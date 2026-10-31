<?php
/**
 * Conversoes offline do Google Ads (CRM -> Google Ads).
 *
 * POR QUE EXISTE
 * O site marca a conversao "Contato" quando a pessoa clica no botao do WhatsApp.
 * Isso conta CLIQUE, nao cliente: em 30 dias o Google reportou centenas de
 * "Contato" enquanto o CRM tinha poucos agendamentos reais. Para o Google
 * otimizar por resultado de verdade, o CRM precisa devolver as conversoes
 * REAIS. Como o fechamento acontece no WhatsApp/CRM (e nao numa pagina), o
 * caminho e a "conversao offline": o CRM guarda o gclid do clique e, quando o
 * cliente avanca no funil, envia esse gclid de volta para o Google Ads.
 *
 * DE ONDE VEM O gclid
 * projetocrm/ir.php grava o clique em ads_src_hits (com um codigo curto); a
 * ponte devolve o codigo no WhatsApp; api/origin_bridge.php troca o codigo pelo
 * gclid e grava em ads_origin_hits.click_id.
 *
 * SEGURANCA
 * Nada e enviado sem pedido explicito de envio (o runner so manda com --apply).
 * A tabela ads_offline_conv guarda cada envio, deduplicado por (gclid, acao).
 */
declare(strict_types=1);

require_once __DIR__ . '/ads_roi.php';

/**
 * Milestones do CRM que viram conversao no Google e o nome da acao la.
 * Os nomes sao comparados sem acento e em minusculo, para nao quebrar se
 * alguem renomear a acao no Google Ads.
 */
function ads_conv_offline_mapa(): array
{
    return [
        // Lead que avancou no funil (a IA casou a conversa com um agendamento).
        'orcamento' => [
            'nomes' => ['orcamento (crm)', 'solicitar orcamento', 'orcamento'],
            'titulo' => 'Orcamento (CRM)',
            'categoria' => 'REQUEST_QUOTE',
            'valor' => false,
        ],
        // Lead que fechou de fato (status final na pipeline do CRM).
        'compra' => [
            'nomes' => ['compra (crm)', 'compra', 'venda'],
            'titulo' => 'Compra (CRM)',
            'categoria' => 'PURCHASE',
            'valor' => true,
        ],
    ];
}

/** Minusculo, sem acento e sem espacos duplicados. */
function ads_conv_offline_slug(string $texto): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
    if ($t === false) {
        $t = $texto;
    }
    return strtolower(trim((string)preg_replace('/\s+/', ' ', (string)$t)));
}

function ads_conv_offline_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS ads_offline_conv (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(32) NOT NULL,
        gclid VARCHAR(255) NOT NULL,
        acao VARCHAR(40) NOT NULL,
        conversion_action VARCHAR(255) NULL,
        valor DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        clique_em DATETIME NULL,
        ocorrido_em DATETIME NOT NULL,
        origem_milestone VARCHAR(40) NULL,
        status VARCHAR(16) NOT NULL DEFAULT "pendente",
        tentativas INT UNSIGNED NOT NULL DEFAULT 0,
        ultimo_erro VARCHAR(500) NULL,
        enviado_em DATETIME NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_conv (gclid(191), acao),
        KEY idx_status (status, ocorrido_em),
        KEY idx_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Contexto OAuth/API do Google Ads do estudio. Devolve ok=false com o motivo. */
function ads_conv_offline_contexto(array $studio): array
{
    $settings = studio_settings($studio);
    $oauth = ads_roi_google_oauth_config($studio);
    $cfg = [
        'customer_id' => preg_replace('/\D/', '', (string)($settings['google_ads_customer_id'] ?? '')),
        'client_id' => (string)($oauth['client_id'] ?? ''),
        'client_secret' => (string)($oauth['client_secret'] ?? ''),
        'refresh_token' => trim((string)($settings['google_ads_refresh_token'] ?? '')),
        'login_customer_id' => preg_replace('/\D/', '', (string)($settings['google_ads_login_customer_id'] ?? '')),
        'api_version' => trim((string)($settings['google_ads_api_version'] ?? 'v25')) ?: 'v25',
        // Ids das acoes de conversao do tipo "Import from clicks" (UPLOAD_CLICKS),
        // as unicas que aceitam conversao offline. Preenchidas por
        // ads_conv_offline_garantir_acoes().
        'conv_orcamento' => trim((string)($settings['google_ads_conv_action_orcamento'] ?? '')),
        'conv_compra' => trim((string)($settings['google_ads_conv_action_compra'] ?? '')),
    ];
    $faltando = [];
    foreach (['customer_id', 'client_id', 'client_secret', 'refresh_token'] as $campo) {
        if ($cfg[$campo] === '') {
            $faltando[] = $campo;
        }
    }
    if ($faltando) {
        return ['ok' => false, 'error' => 'Google Ads sem credencial: falta ' . implode(', ', $faltando) . '.'];
    }
    $tok = ads_roi_google_access_token($cfg);
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => 'Falha no token do Google: ' . (string)($tok['error'] ?? '')];
    }
    $cfg['access_token'] = (string)$tok['token'];
    $cfg['ok'] = true;
    $cfg['error'] = null;
    return $cfg;
}

/** Chama a Google Ads API (REST). */
function ads_conv_offline_api(array $ctx, string $caminho, array $body, int $timeout = 60): array
{
    $headers = [
        'Authorization: Bearer ' . (string)$ctx['access_token'],
        'Content-Type: application/json',
    ];
    if ((string)$ctx['login_customer_id'] !== '') {
        $headers[] = 'login-customer-id: ' . (string)$ctx['login_customer_id'];
    }
    $url = 'https://googleads.googleapis.com/' . rawurlencode((string)$ctx['api_version'])
        . '/customers/' . rawurlencode((string)$ctx['customer_id']) . $caminho;
    return ads_roi_http_request('POST', $url, $headers, $body, 'json', $timeout);
}

/**
 * Lista as acoes de conversao da conta.
 * Devolve [['id'=>..., 'name'=>..., 'type'=>...], ...]
 */
function ads_conv_offline_listar_acoes(array $ctx): array
{
    $consulta = 'SELECT conversion_action.id, conversion_action.name, conversion_action.type '
        . 'FROM conversion_action';
    $res = ads_conv_offline_api($ctx, '/googleAds:search', ['query' => $consulta]);
    if (empty($res['ok'])) {
        return [];
    }
    $out = [];
    foreach ((array)($res['json']['results'] ?? []) as $linha) {
        $acao = (array)($linha['conversionAction'] ?? []);
        if ((string)($acao['id'] ?? '') === '') {
            continue;
        }
        $out[] = [
            'id' => (string)$acao['id'],
            'name' => (string)($acao['name'] ?? ''),
            'type' => (string)($acao['type'] ?? ''),
        ];
    }
    return $out;
}

/** Garante as colunas que guardam os ids das acoes de conversao do CRM. */
function ads_conv_offline_settings_colunas(PDO $pdo): void
{
    foreach (['google_ads_conv_action_orcamento', 'google_ads_conv_action_compra'] as $coluna) {
        $existe = $pdo->query("SHOW COLUMNS FROM studio_settings LIKE '" . $coluna . "'")->fetch(PDO::FETCH_ASSOC);
        if (!$existe) {
            $pdo->exec('ALTER TABLE studio_settings ADD COLUMN ' . $coluna . ' VARCHAR(40) NULL');
        }
    }
}

/**
 * Descobre (ou cria) as acoes de conversao do tipo "Import from clicks"
 * (UPLOAD_CLICKS) — as unicas que aceitam conversao offline pela Data Manager API.
 * Grava os ids nas configuracoes e devolve ['orcamento' => id, 'compra' => id].
 */
function ads_conv_offline_garantir_acoes(PDO $pdo, array $ctx, bool $criar = true): array
{
    ads_conv_offline_settings_colunas($pdo);
    $mapa = ads_conv_offline_mapa();

    $upload = [];
    foreach (ads_conv_offline_listar_acoes($ctx) as $acao) {
        if (strtoupper((string)$acao['type']) === 'UPLOAD_CLICKS') {
            $upload[ads_conv_offline_slug((string)$acao['name'])] = (string)$acao['id'];
        }
    }

    $resultado = ['orcamento' => '', 'compra' => ''];
    foreach ($mapa as $chave => $def) {
        $atual = trim((string)($ctx['conv_' . $chave] ?? ''));
        if ($atual === '') {
            foreach ((array)$def['nomes'] as $nome) {
                if (isset($upload[$nome])) {
                    $atual = $upload[$nome];
                    break;
                }
            }
        }
        if ($atual === '' && $criar) {
            $res = ads_conv_offline_api($ctx, '/conversionActions:mutate', ['operations' => [[
                'create' => [
                    'name' => (string)$def['titulo'],
                    'type' => 'UPLOAD_CLICKS',
                    'category' => (string)$def['categoria'],
                    'status' => 'ENABLED',
                    'countingType' => 'ONE_PER_CLICK',
                ],
            ]]]);
            $novo = (string)($res['json']['results'][0]['resourceName'] ?? '');
            if ($novo !== '') {
                $atual = (string)substr((string)(strrchr($novo, '/') ?: '/'), 1);
            }
        }
        $resultado[$chave] = $atual;
    }

    $pdo->prepare('INSERT INTO studio_settings (id, google_ads_conv_action_orcamento, google_ads_conv_action_compra)
                   VALUES (1, ?, ?)
                   ON DUPLICATE KEY UPDATE
                     google_ads_conv_action_orcamento = VALUES(google_ads_conv_action_orcamento),
                     google_ads_conv_action_compra = VALUES(google_ads_conv_action_compra)')
        ->execute([
            $resultado['orcamento'] !== '' ? $resultado['orcamento'] : null,
            $resultado['compra'] !== '' ? $resultado['compra'] : null,
        ]);

    return $resultado;
}

/** Data no formato RFC 3339 que a Data Manager API exige ("2026-09-28T10:00:00-03:00"). */
function ads_conv_offline_data_dm(string $quando): string
{
    $tz = new DateTimeZone('America/Sao_Paulo');
    try {
        $dt = new DateTime($quando, $tz);
    } catch (Throwable $e) {
        $dt = new DateTime('now', $tz);
    }
    return $dt->format('Y-m-d\TH:i:sP');
}

/**
 * Envia eventos para a Data Manager API (events:ingest).
 * $destino e o ID da acao de conversao (productDestinationId).
 */
function ads_conv_offline_ingest(array $ctx, string $destino, array $eventos, bool $validateOnly = false): array
{
    $operando = ['accountType' => 'GOOGLE_ADS', 'accountId' => (string)$ctx['customer_id']];
    $dest = ['operatingAccount' => $operando, 'productDestinationId' => $destino];
    $login = (string)$ctx['login_customer_id'];
    if ($login !== '' && $login !== (string)$ctx['customer_id']) {
        $dest['loginAccount'] = ['accountType' => 'GOOGLE_ADS', 'accountId' => $login];
    }
    $payload = ['destinations' => [$dest], 'events' => array_values($eventos)];
    if ($validateOnly) {
        $payload['validateOnly'] = true;
    }
    $headers = [
        'Authorization: Bearer ' . (string)$ctx['access_token'],
        'Content-Type: application/json',
    ];
    return ads_roi_http_request('POST', 'https://datamanager.googleapis.com/v1/events:ingest', $headers, $payload, 'json', 90);
}

/**
 * Telefone so com digitos, sem o DDI 55 e limitado aos 11 finais.
 */
function ads_conv_offline_fone_chave(string $fone): string
{
    $d = (string)preg_replace('/\D/', '', $fone);
    if ($d === '') {
        return '';
    }
    if (strlen($d) > 11 && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }
    return strlen($d) > 11 ? substr($d, -11) : $d;
}

/**
 * Chaves de casamento do telefone. O mesmo cliente aparece como
 * 5511999998888, 11999998888 ou 1199998888 (com e sem o 9o digito), entao os
 * 8 digitos finais entram como chave tolerante alem dos 11 finais.
 */
function ads_conv_offline_fone_chaves(string $fone): array
{
    $longa = ads_conv_offline_fone_chave($fone);
    if ($longa === '') {
        return [];
    }
    $chaves = [$longa];
    if (strlen($longa) > 8) {
        $chaves[] = substr($longa, -8);
    }
    return $chaves;
}

/**
 * Varre o CRM atras de milestones reais de leads que TEM click id.
 *
 * Casamento de telefone: ads_origin_hits.phone guarda o numero com DDI e as
 * tabelas do CRM guardam o mesmo numero em formatos variados (com/sem DDI e
 * com/sem o 9o digito) e com collations diferentes. Em vez de JOIN com COLLATE,
 * o casamento e feito por chave normalizada em PHP (ads_conv_offline_fone_*),
 * que tolera os dois formatos. Sem click id nao existe conversao offline.
 */
function ads_conv_offline_coletar(PDO $pdo, int $dias = 30): array
{
    $dias = max(1, min(365, $dias));
    $limite = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'));
    $itens = [];

    // Cliques pagos (gclid) do periodo, indexados pela chave do telefone.
    // Nenhum clique casando = nada a enviar: o Google so aceita com click id.
    $cliques = [];
    try {
        $st = $pdo->prepare('SELECT phone, click_id, created_at FROM ads_origin_hits
                             WHERE click_id IS NOT NULL AND click_id <> "" AND created_at >= ?
                               AND (LOWER(TRIM(origin)) = "google"
                                    OR LOWER(TRIM(COALESCE(platform, ""))) = "google")
                             ORDER BY created_at ASC');
        $st->execute([$limite]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
            foreach (ads_conv_offline_fone_chaves((string)$h['phone']) as $chave) {
                $atual = $cliques[$chave] ?? null;
                if ($atual === null || strtotime((string)$h['created_at']) < strtotime((string)$atual['clique_em'])) {
                    $cliques[$chave] = [
                        'click_id' => (string)$h['click_id'],
                        'clique_em' => (string)$h['created_at'],
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    if (!$cliques) {
        return [];
    }

    // So guarda o milestone quando o telefone casa com um clique pago conhecido.
    $guardar = static function (string $fone, string $acao, string $ocorrido, float $valor, string $origem) use (&$itens, $cliques): void {
        foreach (ads_conv_offline_fone_chaves($fone) as $chave) {
            if (!isset($cliques[$chave])) {
                continue;
            }
            $itens[] = [
                'acao' => $acao,
                'phone' => $fone,
                'click_id' => (string)$cliques[$chave]['click_id'],
                'clique_em' => (string)$cliques[$chave]['clique_em'],
                'ocorrido_em' => $ocorrido,
                'valor' => $valor,
                'origem_milestone' => $origem,
            ];
            return;
        }
    };

    // Milestone 1: o cliente agendou no estudio (agenda real do CRM). Em 72%
    // dos agendamentos o lead_id vem nulo (import do Google Calendar), por isso
    // o telefone sai do cliente e o lead fica como segunda opcao.
    try {
        $st = $pdo->prepare('SELECT COALESCE(NULLIF(c.phone, ""), NULLIF(l.phone, ""), "") AS phone,
                                    a.status AS status_agenda,
                                    a.value AS valor,
                                    a.appointment_date AS data_servico,
                                    COALESCE(a.google_created_at, a.created_at) AS ocorrido_em
                             FROM appointments a
                             LEFT JOIN customers c ON c.id = a.customer_id
                             LEFT JOIN leads l ON l.id = a.lead_id
                             WHERE a.status <> "cancelado"
                               AND COALESCE(a.google_created_at, a.created_at) >= ?');
        $st->execute([$limite]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fone = (string)($r['phone'] ?? '');
            if ($fone === '') {
                continue;
            }
            $guardar($fone, 'orcamento', (string)$r['ocorrido_em'], 0.0, 'agenda_criada');
            // Agendamento ja atendido: e a venda de fato.
            $servico = (string)($r['data_servico'] ?? '');
            $statusServico = strtolower(trim((string)($r['status_agenda'] ?? '')));
            if (in_array($statusServico, ['finalizado', 'finalizada', 'concluido', 'concluida', 'atendido'], true)
                && $servico !== '' && $servico <= date('Y-m-d')) {
                $guardar($fone, 'compra', $servico . ' 12:00:00', (float)($r['valor'] ?? 0), 'agenda_finalizada');
            }
        }
    } catch (Throwable $e) {
        // segue para o proximo milestone
    }

    // Milestone 2: a IA casou a conversa do anuncio com um agendamento real.
    try {
        foreach ($pdo->query('SELECT telefone, data_fechamento, data_ultima_msg FROM funil_match WHERE agendou = 1') as $r) {
            $quando = (string)(($r['data_fechamento'] ?? '') ?: ($r['data_ultima_msg'] ?? ''));
            $guardar((string)$r['telefone'], 'orcamento', $quando !== '' ? $quando : date('Y-m-d H:i:s'), 0.0, 'funil_agendou');
        }
    } catch (Throwable $e) {
        // segue
    }

    // Milestone 3: o lead fechou na pipeline do CRM.
    try {
        foreach ($pdo->query('SELECT phone, updated_at, estimated_value FROM leads WHERE status IN ("fechado", "finalizado")') as $r) {
            $guardar((string)$r['phone'], 'compra', (string)$r['updated_at'], (float)($r['estimated_value'] ?? 0), 'lead_fechado');
        }
    } catch (Throwable $e) {
        // segue
    }

    // A IA pode confundir intencao ("vou agendar") com venda. Compra so sai da
    // agenda finalizada ou do lead realmente fechado nos milestones acima.

    return $itens;
}

/**
 * Grava os milestones na fila. Devolve quantos entraram (dedup por gclid+acao).
 */
function ads_conv_offline_enfileirar(PDO $pdo, array $itens): int
{
    ads_conv_offline_ensure_schema($pdo);
    $stmt = $pdo->prepare('INSERT IGNORE INTO ads_offline_conv
        (phone, gclid, acao, valor, clique_em, ocorrido_em, origem_milestone, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, "pendente")');
    $novos = 0;
    foreach ($itens as $item) {
        $clique = (string)($item['clique_em'] ?? '');
        $ocorrido = (string)($item['ocorrido_em'] ?? '');
        // A conversao nunca pode ser antes do clique: sem isso o Google recusa.
        if ($ocorrido === '' || strtotime($ocorrido) < strtotime($clique)) {
            $ocorrido = $clique;
        }
        $stmt->execute([
            (string)$item['phone'],
            (string)$item['click_id'],
            (string)$item['acao'],
            round((float)($item['valor'] ?? 0), 2),
            $clique !== '' ? $clique : null,
            $ocorrido,
            (string)($item['origem_milestone'] ?? ''),
        ]);
        $novos += $stmt->rowCount();
    }
    return $novos;
}

/**
 * Envia as conversoes pendentes para a Data Manager API, agrupadas por acao.
 *
 * Historico: ate 09/2026 o caminho era o ConversionUploadService do Google Ads
 * API. Ele foi fechado para novas integracoes (CUSTOMER_NOT_ALLOWLISTED_FOR_
 * THIS_FEATURE) e o Google passou a exigir a Data Manager API. O formato mudou:
 * o destino (acao de conversao) vai em "destinations" e os eventos em "events".
 *
 * $aplicar=false apenas simula. $validateOnly=true valida no Google sem gravar.
 */
function ads_conv_offline_enviar(PDO $pdo, array $ctx, bool $aplicar, int $limite = 50, bool $validateOnly = false): array
{
    ads_conv_offline_ensure_schema($pdo);
    $limite = max(1, min(500, $limite));

    $pend = $pdo->query('SELECT * FROM ads_offline_conv
                         WHERE status IN ("pendente", "erro")
                         ORDER BY ocorrido_em ASC, id ASC
                         LIMIT ' . $limite)->fetchAll(PDO::FETCH_ASSOC);

    $resumo = ['ok' => true, 'simulado' => !$aplicar, 'candidatas' => count($pend), 'enviadas' => 0, 'ignoradas' => [], 'aguardando' => [], 'erros' => [], 'exemplos' => []];
    if (!$pend) {
        return $resumo;
    }

    // As acoes de conversao do tipo UPLOAD_CLICKS ("Import from clicks") sao o
    // destino dos eventos. Se ainda nao estiverem configuradas, descobre/cria.
    if ((string)($ctx['conv_orcamento'] ?? '') === '' && (string)($ctx['conv_compra'] ?? '') === '' && $aplicar) {
        $ids = ads_conv_offline_garantir_acoes($pdo, $ctx);
        $ctx['conv_orcamento'] = $ids['orcamento'];
        $ctx['conv_compra'] = $ids['compra'];
    }
    $destinoPorAcao = [
        'orcamento' => trim((string)($ctx['conv_orcamento'] ?? '')),
        'compra' => trim((string)($ctx['conv_compra'] ?? '')),
    ];

    $lotes = [];
    foreach ($pend as $linha) {
        $acaoInterna = (string)$linha['acao'];
        $destino = $destinoPorAcao[$acaoInterna] ?? '';
        if ($destino === '' && $aplicar) {
            $resumo['ignoradas'][$acaoInterna] = ($resumo['ignoradas'][$acaoInterna] ?? 0) + 1;
            continue;
        }
        // A Data Manager API recusa evento no futuro ou recente demais
        // (EVENT_TIME_INVALID: "did not occur within the acceptable time window").
        // Medido em 28/09/2026: "agora" e +5min falham, -10min passa. Aqui o item
        // fica na fila para a proxima rodada, em vez de virar erro.
        if ($aplicar && strtotime((string)$linha['ocorrido_em']) > (time() - 15 * 60)) {
            $resumo['aguardando'][] = (int)$linha['id'];
            continue;
        }
        $valor = round((float)$linha['valor'], 2);
        $evento = [
            'eventTimestamp' => ads_conv_offline_data_dm((string)$linha['ocorrido_em']),
            'eventSource' => 'WEB',
            // transactionId deduplica: reenviar o mesmo evento nao conta duas vezes.
            'transactionId' => 'crm-' . $acaoInterna . '-' . (string)$linha['id'],
            'adIdentifiers' => ['gclid' => (string)$linha['gclid']],
        ];
        if ($valor > 0) {
            $evento['conversionValue'] = $valor;
            $evento['currency'] = 'BRL';
        }
        $chave = $destino !== '' ? $destino : ('<' . $acaoInterna . '>');
        $lotes[$chave]['eventos'][] = $evento;
        $lotes[$chave]['ids'][] = (int)$linha['id'];
    }

    if (!$aplicar) {
        foreach ($lotes as $chave => $lote) {
            $resumo['exemplos'][] = ['destino' => $chave, 'evento' => $lote['eventos'][0]];
        }
        return $resumo;
    }

    $up = $pdo->prepare('UPDATE ads_offline_conv SET status = ?, enviado_em = ?, tentativas = tentativas + 1, ultimo_erro = ? WHERE id = ?');
    foreach ($lotes as $destino => $lote) {
        $res = ads_conv_offline_ingest($ctx, (string)$destino, $lote['eventos'], $validateOnly);
        $erro = '';
        if (empty($res['ok'])) {
            $erro = substr((string)($res['error'] ?? 'erro'), 0, 480);
        } elseif (!empty($res['json']['fieldWarnings'])) {
            // A Data Manager API devolve warnings por linha em vez de falhar tudo.
            $erro = substr((string)json_encode($res['json']['fieldWarnings']), 0, 480);
        }
        foreach ($lote['ids'] as $id) {
            if ($erro !== '') {
                $up->execute(['erro', null, $erro, $id]);
            } elseif ($validateOnly) {
                $up->execute(['pendente', null, 'validado (validateOnly)', $id]);
            } else {
                $up->execute(['enviado', date('Y-m-d H:i:s'), null, $id]);
                $resumo['enviadas']++;
            }
        }
        if ($erro !== '') {
            $resumo['ok'] = false;
            $resumo['erros'][] = $erro;
        }
    }
    return $resumo;
}

/**
 * Relatorio de conferencia: o que o Google reporta x o que o CRM tem.
 * Responde "as conversoes estao sendo rastreadas direitinho?".
 */
function ads_conv_offline_relatorio(PDO $pdo, array $ctx, string $de, string $ate): array
{
    $out = ['de' => $de, 'ate' => $ate, 'google' => [], 'crm' => [], 'fila' => []];

    try {
        $consulta = 'SELECT segments.date, metrics.conversions, metrics.cost_micros FROM customer '
            . 'WHERE segments.date BETWEEN "' . $de . '" AND "' . $ate . '"';
        $res = ads_conv_offline_api($ctx, '/googleAds:search', ['query' => $consulta]);
        if (!empty($res['ok'])) {
            $tot = 0.0;
            $custo = 0.0;
            foreach ((array)($res['json']['results'] ?? []) as $linha) {
                $d = (string)($linha['segments']['date'] ?? '');
                $c = (float)($linha['metrics']['conversions'] ?? 0);
                $m = (float)($linha['metrics']['costMicros'] ?? 0);
                if ($d === '') {
                    continue;
                }
                $tot += $c;
                $custo += $m / 1000000;
                $out['google'][$d] = ['conversoes' => round($c, 2), 'custo' => round($m / 1000000, 2)];
            }
            $out['google_total'] = round($tot, 2);
            $out['google_custo'] = round($custo, 2);
        } else {
            $out['google_erro'] = (string)($res['error'] ?? 'erro');
        }
    } catch (Throwable $e) {
        $out['google_erro'] = $e->getMessage();
    }

    try {
        $sql = 'SELECT LOWER(COALESCE(NULLIF(TRIM(platform), ""), NULLIF(TRIM(origin), ""))) AS origem,
                       COUNT(DISTINCT phone) AS contatos,
                       SUM(CASE WHEN click_id IS NOT NULL AND click_id <> "" THEN 1 ELSE 0 END) AS com_click_id
                FROM ads_origin_hits
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY origem';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$de, $ate]);
        $out['crm']['ponte_por_origem'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sql2 = 'SELECT COUNT(DISTINCT h.phone) AS telefones_ads,
                        SUM(CASE WHEN fm.agendou = 1 THEN 1 ELSE 0 END) AS com_agendamento
                 FROM (SELECT DISTINCT phone FROM ads_origin_hits
                       WHERE DATE(created_at) BETWEEN ? AND ?) h
                 LEFT JOIN (SELECT telefone, MAX(agendou) AS agendou FROM funil_match GROUP BY telefone) fm
                        ON fm.telefone COLLATE utf8mb4_unicode_ci = h.phone COLLATE utf8mb4_unicode_ci';
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute([$de, $ate]);
        $out['crm']['funil'] = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $out['crm_erro'] = $e->getMessage();
    }

    try {
        ads_conv_offline_ensure_schema($pdo);
        $out['fila'] = $pdo->query('SELECT status, COUNT(*) AS qtd FROM ads_offline_conv GROUP BY status')
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $out['fila'] = [];
    }

    return $out;
}
