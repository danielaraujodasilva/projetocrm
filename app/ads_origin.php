<?php
/**
 * Rastreio de origem do cliente (Opcao 1 - tag no link/primeiro contato).
 *
 * Fluxo:
 *  - Anuncio aponta para /projetocrm/ir.php?src=meta (ou google/instagram/indicacao/...)
 *  - ir.php redireciona para o WhatsApp com a mensagem "Vim pelo META ..."
 *  - Quando a mensagem chega, ads_detect_origin() reconhece a marca na 1a mensagem
 *    e ads_apply_origin_to_conversation() grava em leads.source (sem sobrescrever manual).
 */
declare(strict_types=1);

/**
 * Detecta a origem a partir do texto da mensagem.
 * Retorna 'meta' | 'google' | 'instagram' | 'indicacao' | 'porta' | 'reincidente' | '' se nao reconhecer.
 */
function ads_detect_origin(string $text): string
{
    $t = mb_strtolower(trim($text));
    if ($t === '') {
        return '';
    }
    $map = [
        'meta'        => ['vim pelo meta', 'pelo meta', 'vi no meta', 'anuncio do meta', 'anúncio do meta', 'facebook', 'instagram do meta'],
        'google'      => ['vim pelo google', 'pelo google', 'vi no google', 'anuncio do google', 'anúncio do google', 'pesquisei no google', 'vim pelo youtube', 'pelo youtube', 'vi no youtube', 'vi o anuncio no youtube', 'vi o anúncio no youtube', 'anuncio do youtube', 'anúncio do youtube', 'youtube', 'you tube'],
        'instagram'   => ['vim pelo instagram', 'pelo instagram', 'vi no instagram', 'instagram', 'insta'],
        'indicacao'   => ['indicacao', 'indicação', 'indicado', 'indicada', 'me indicaram', 'boca a boca'],
        'porta'       => ['passei na frente', 'passei por ai', 'passei por aí', 'vizinho', 'do lado do estudio', 'do lado do estúdio'],
        'reincidente' => ['ja sou cliente', 'já sou cliente', 'ja tatuei', 'já tatuei', 'sou cliente', 'voltei'],
    ];
    foreach ($map as $src => $phrases) {
        foreach ($phrases as $p) {
            if (mb_strpos($t, $p) !== false) {
                return $src;
            }
        }
    }
    return '';
}

/**
 * Se a conversa ainda nao tem lead com origem definida, cria/atualiza com a origem detectada.
 * Nunca sobrescreve uma origem ja preenchida manualmente.
 */
function ads_apply_origin_to_conversation(array $studio, int $conversationId, string $text): bool
{
    $src = ads_detect_origin($text);
    if ($src === '' || $conversationId <= 0) {
        return false;
    }
    try {
        $pdo = studio_db($studio);
        // Descobre o telefone/lead da conversa
        $stmt = $pdo->prepare('SELECT id, phone FROM whatsapp_conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            return false;
        }
        $phone = preg_replace('/\D+/', '', (string)($conv['phone'] ?? ''));

        // Procura lead existente pelo telefone
        $leadId = 0;
        if ($phone !== '') {
            $ls = $pdo->prepare('SELECT id, source FROM leads WHERE phone = ? OR phone = ? ORDER BY id DESC LIMIT 1');
            $ls->execute([$phone, '+' . $phone]);
            $row = $ls->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $leadId = (int)$row['id'];
                $cur = strtolower(trim((string)($row['source'] ?? '')));
                // Nao sobrescreve origem ja definida (manual ou anterior)
                if ($cur !== '' && !in_array($cur, ['manual', 'google_calendar', 'whatsapp'], true)) {
                    return false;
                }
                $upd = $pdo->prepare('UPDATE leads SET source = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$src, $leadId]);
                return true;
            }
        }
        // Sem lead ainda: nao cria (o fluxo normal cria). Marca a conversa para o proximo lead herdar.
        $pdo->prepare('UPDATE whatsapp_conversations SET ai_last_status = ? WHERE id = ?')
            ->execute(['origem:' . $src, $conversationId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Religa uma conversa orfa ao lead correspondente e, de quebra, liga a origem.
 *
 * POR QUE EXISTE: nem sempre a origem chega antes da conversa. Quando o historico
 * do WhatsApp e arquivado de uma vez (syncFullHistory) ou quando o cliente escreve
 * antes de a ponte detectar o anuncio, a conversa nasce sem lead_id. Sem lead_id a
 * analise de venda nao sabe a origem e o card "Conversa inicial" aparece zerado,
 * mesmo com leads de anuncio no periodo (bug visto em 18/09/2026: 15 conversas de
 * anuncio contadas como sem_origem).
 *
 * Regra de posse (Opcao A): quem cria o lead e o fluxo da conversa (studio_crm.php).
 * Esta funcao nunca cria lead - so LIGA o que ja existe. Assim nao ha duplicacao.
 *
 * SOMENTE LE LE ADICIONA o vinculo: nao altera telefone, nome nem origem do lead.
 */
function ads_vincular_conversa_ao_lead(array $studio, int $conversationId, string $phone = ''): bool
{
    if ($conversationId <= 0) {
        return false;
    }
    try {
        $pdo = studio_db($studio);

        $stmt = $pdo->prepare('SELECT id, phone, lead_id FROM whatsapp_conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            return false;
        }
        // Ja vinculada: nada a fazer (idempotente).
        if ((int)($conv['lead_id'] ?? 0) > 0) {
            return false;
        }

        $alvo = $phone !== '' ? $phone : (string)($conv['phone'] ?? '');
        $alvo = preg_replace('/\D+/', '', $alvo);
        if ($alvo === '') {
            return false;
        }

        // Mesma tolerancia de telefone do resto do CRM (DDI/9o digito/formatacao).
        $leadId = 0;
        $candidatos = $pdo->query('SELECT id, phone FROM leads WHERE phone IS NOT NULL AND phone <> "" ORDER BY updated_at DESC, id DESC LIMIT 500');
        foreach ($candidatos->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lead) {
            if (phones_match((string)$lead['phone'], $alvo)) {
                $leadId = (int)$lead['id'];
                break;
            }
        }
        if ($leadId <= 0) {
            // Sem lead ainda: nao cria (Opcao A). A ponte guarda a pendencia.
            return false;
        }

        $pdo->prepare('UPDATE whatsapp_conversations SET lead_id = ?, updated_at = NOW() WHERE id = ? AND (lead_id IS NULL OR lead_id = 0)')
            ->execute([$leadId, $conversationId]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Le a origem de anuncio ja registrada pela ponte para um telefone.
 *
 * POR QUE EXISTE (Opcao A): a ponte de rastreio detecta o anuncio e grava a
 * origem em ads_origin_hits ANTES de a conversa existir. Como o fluxo da
 * conversa e o dono da criacao do lead, ele precisa desta consulta para nascer
 * ja com a origem certa - em vez de nascer como "WhatsApp" e nunca mais saber
 * de onde veio. Fonte de verdade: ads_origin_hits (auditoria da ponte).
 *
 * Devolve o codigo canonico ('meta', 'instagram', ...) ou '' quando nao houver.
 */
function ads_origem_do_telefone(array $studio, string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);
    if ($phone === '') {
        return '';
    }
    try {
        $pdo = studio_db($studio);

        // Tolerancia de telefone igual ao resto do CRM: tenta o numero como veio
        // e a variante sem o DDI 55 (a ponte pode ter gravado qualquer uma).
        $variantes = [$phone];
        if (strlen($phone) > 11 && str_starts_with($phone, '55')) {
            $variantes[] = substr($phone, 2);
        }
        $ph = implode(',', array_fill(0, count($variantes), '?'));

        $stmt = $pdo->prepare("SELECT origin FROM ads_origin_hits WHERE phone IN ($ph) ORDER BY id DESC LIMIT 1");
        $stmt->execute($variantes);
        $origem = trim((string)($stmt->fetchColumn() ?: ''));
        if ($origem === '') {
            return '';
        }

        $codigo = ads_origem_normalizar($origem);
        // So origem de anuncio vale a pena gravar como source do lead; organico
        // continua caindo no fluxo normal ("WhatsApp").
        return ads_origem_e_anuncio($codigo) ? $codigo : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Diagnostico da ponte de origem.
 *
 * POR QUE EXISTE: a pagina de Historico le o ARQUIVO da ponte, e o painel de ROI le o
 * BANCO do CRM. Se a ponte deteta origem mas o envio falha (token, URL, servidor), os
 * dois divergem sem nenhum erro visivel - foi o que aconteceu em 17/09/2026 (HTTP 405
 * por redirect de localhost->https). Esta funcao faz essa divergencia aparecer.
 *
 * SOMENTE LEITURA: nao escreve nada.
 */
function ads_bridge_health(): array
{
    $base = 'C:\\Users\\server_spd\\Documents\\whatsapp-origin-bridge';
    // ATENCAO: os dois arquivos registram eventos DIFERENTES da mesma ponte.
    //  - eventos.jsonl: eventos do servico (recordEvent) -> origem_detectada, audio, conexao.
    //  - bridge.log:    log do push (log)              -> origin_pushed, push_error.
    // Ler so o eventos.jsonl fazia pushed_ok ficar sempre 0 e o painel alertar
    // um falso positivo de "detectou mas nao gravou" (bug visto em 20/09/2026).
    $eventosPath = $base . '\\dados\\eventos.jsonl';
    $logPath = $base . '\\bridge.log';

    $out = [
        'ok' => true,
        'alerta' => '',
        'ponte_online' => false,
        'detectadas_hoje' => 0,
        'pushed_ok' => 0,
        'push_erros' => 0,
        'ultimo_erro' => '',
    ];

    // Ponte no ar? (porta do painel local)
    $fp = @fsockopen('127.0.0.1', 3210, $e1, $e2, 0.6);
    if (is_resource($fp)) {
        fclose($fp);
        $out['ponte_online'] = true;
    }

    $hoje = date('Y-m-d');

    // Varre um arquivo JSONL contando so os eventos de hoje.
    $varrer = static function (string $path) use (&$out, $hoje): void {
        if (!is_file($path)) {
            return;
        }
        $fh = @fopen($path, 'r');
        if (!$fh) {
            return;
        }
        while (($linha = fgets($fh)) !== false) {
            $ev = json_decode(trim($linha), true);
            if (!is_array($ev)) {
                continue;
            }
            if (substr((string)($ev['at'] ?? ''), 0, 10) !== $hoje) {
                continue;
            }
            $tipo = (string)($ev['event'] ?? '');
            if ($tipo === 'origem_detectada') {
                $out['detectadas_hoje']++;
            } elseif ($tipo === 'origin_pushed') {
                $out['pushed_ok']++;
            } elseif ($tipo === 'push_error') {
                $out['push_erros']++;
                $out['ultimo_erro'] = trim((string)($ev['response'] ?? $ev['error'] ?? ''));
            }
        }
        fclose($fh);
    };

    $varrer($eventosPath); // origem_detectada vive aqui
    $varrer($logPath);     // origin_pushed / push_error vivem aqui

    // Detectou origem mas nada foi gravado? Sinal de falha silenciosa.
    if ($out['push_erros'] > 0) {
        $out['ok'] = false;
        $out['alerta'] = 'A ponte detectou origem de anúncio mas houve ' . $out['push_erros']
            . ' falha(s) ao gravar no CRM hoje. Os leads abaixo podem estar incompletos.';
    } elseif ($out['detectadas_hoje'] > 0 && $out['pushed_ok'] === 0) {
        // So alerta aqui quando realmente detectou e nao gravou nada.
        $out['ok'] = false;
        $out['alerta'] = 'A ponte detectou ' . $out['detectadas_hoje']
            . ' origem(ns) de anúncio hoje, mas nenhuma foi gravada no CRM. Confira o token/URL do push.';
    } elseif (!$out['ponte_online']) {
        $out['ok'] = false;
        $out['alerta'] = 'A ponte do WhatsApp está parada: nenhuma origem nova está sendo rastreada agora.';
    }

    return $out;
}

/**
 * Contagem por origem no periodo, a partir de uma tabela+coluna de data.
 * Helper interno de ads_leads_by_origin()/ads_leads_by_origin_ponte().
 */
function ads_contar_origens(PDO $pdo, string $sql, array $params): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = ['__total' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $origem = strtolower(trim((string)$row['origem']));
            $n = (int)$row['total'];
            if ($origem === '') {
                $origem = 'sem_origem';
            }
            $out[$origem] = ($out[$origem] ?? 0) + $n;
            $out['__total'] += $n;
        }
        return $out;
    } catch (Throwable $e) {
        return ['__total' => 0];
    }
}

/**
 * Conta LEADS por origem no periodo (data de criacao do lead) — tabela `leads`.
 *
 * Diferente de ads_roi_by_origin(): aqui conta o lead em si, nao o agendamento.
 * Serve para responder "quantos contatos cada canal trouxe", que e a base do
 * custo por lead. Retorna [origem => n] e o total no indice '__total'.
 */
function ads_leads_by_origin(PDO $pdo, string $start, string $end): array
{
    return ads_contar_origens($pdo,
        'SELECT COALESCE(NULLIF(TRIM(source), ""), "sem_origem") AS origem,
                COUNT(*) AS total
         FROM leads
         WHERE DATE(created_at) BETWEEN ? AND ?
         GROUP BY origem ORDER BY total DESC', [$start, $end]);
}

/**
 * Conta LEADS por origem usando a PONTE como fonte (ads_origin_hits).
 *
 * POR QUE EXISTE (migracao oficial -> ponte, 22/09/2026)
 * Quando o estudio migrou do WhatsApp oficial (Cloud API) para a ponte Baileys, o
 * CRM deixou de receber mensagem nova e, com ela, o fluxo que criava lead por
 * conversa. A ponte continuou detectando a origem de cada anuncio e gravando em
 * ads_origin_hits (com telefone e ctwa_clid), mas nada criava mais a linha em
 * `leads` — entao os cards de lead do ROI zeravam mesmo com anuncio trazendo
 * contato todos os dias.
 *
 * Aqui a contagem sai do proprio registro da ponte: 1 hit = 1 contato de anuncio.
 * Deduplica por telefone, porque a mesma pessoa pode gerar mais de um hit.
 *
 * Retorna o mesmo formato de ads_leads_by_origin(): [origem => n] + '__total'.
 */
function ads_leads_by_origin_ponte(PDO $pdo, string $start, string $end): array
{
    // platform e a coluna mais fiel ao canal real (facebook/instagram); origin e
    // o codigo canonico. Preferimos platform e caímos para origin quando vazia.
    $sql = 'SELECT LOWER(COALESCE(NULLIF(TRIM(platform), ""), NULLIF(TRIM(origin), ""))) AS origem,
                   COUNT(*) AS total
            FROM (
                SELECT phone,
                       LOWER(COALESCE(NULLIF(TRIM(platform), ""), NULLIF(TRIM(origin), ""))) AS platform,
                       LOWER(TRIM(origin)) AS origin
                FROM ads_origin_hits
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY phone, platform, origin
            ) t
            GROUP BY origem ORDER BY total DESC';
    return ads_contar_origens($pdo, $sql, [$start, $end]);
}

/**
 * Contagem por origem que o painel de ROI deve USAR.
 *
 * Precedencia: ponte (ads_origin_hits) quando ela tiver dado no periodo.
 * Se a ponte estiver vazia (ex.: periodo antigo, antes da ponte, ou ponte fora),
 * cai para a tabela `leads` — assim periodo historico continua mostrando o que
 * foi gravado na epoca, em vez de zerar.
 */
function ads_leads_by_origin_painel(PDO $pdo, string $start, string $end): array
{
    $ponte = ads_leads_by_origin_ponte($pdo, $start, $end);
    if ((int)($ponte['__total'] ?? 0) > 0) {
        return $ponte;
    }
    return ads_leads_by_origin($pdo, $start, $end);
}

/**
 * Conta os cliques de rastreio (ads_src_hits) por origem. Indica quantas
 * pessoas clicaram no link antes de virar lead - mostra a perda no caminho.
 */
function ads_hits_by_origin(PDO $pdo, string $start, string $end): array
{
    try {
        $sql = 'SELECT src, COUNT(*) AS total FROM ads_src_hits
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY src ORDER BY total DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start, $end]);
        $out = ['__total' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $src = strtolower(trim((string)$row['src']));
            $n = (int)$row['total'];
            $out[$src] = ($out[$src] ?? 0) + $n;
            $out['__total'] += $n;
        }
        return $out;
    } catch (Throwable $e) {
        return ['__total' => 0];
    }
}

/**
 * Resultado REAL por canal: agendamentos atendidos e valor, quebrados pelo canal
 * que trouxe o cliente.
 *
 * ANTES: agrupava por `leads.source` (texto livre) e todo agendamento
 * importado do Google Calendar, que nao tem canal, aparecia como "Google Agenda"
 * - como se Google Agenda fosse canal de aquisicao. Qualquer leitura por canal
 * ficava falsa.
 *
 * O canal sai, em ordem de confianca:
 *   1) clique pago rastreado (ads_origin_hits), casado pelo telefone;
 *   2) origem detectada pela IA (funil_match.origem_detectada);
 *   3) leads.source, mas so quando e origem de verdade (agenda/manual sao
 *      importacao e digitacao, nao canal -> viram sem_origem).
 * Sem nenhum dos tres o agendamento entra em "sem_origem", em vez de inventar canal.
 *
 * Base identica ao resumo do painel (ads_roi_daily_series): data do ATENDIMENTO,
 * status finalizado e sem os compromissos operacionais - assim a soma dos canais
 * fecha com o total mostrado no topo.
 *
 * Retorna [canal => ['agendamentos'=>n, 'valor'=>v]] + '__total'.
 */
function ads_roi_by_origin(PDO $pdo, string $start, string $end): array
{
    $out = ['__total' => ['agendamentos' => 0, 'valor' => 0.0]];
    try {
        // 1) Clique pago rastreado pela ponte: telefone -> canal.
        $porFone = [];
        try {
            foreach ($pdo->query('SELECT phone, origin, platform FROM ads_origin_hits') as $row) {
                $canal = ads_roi_canal_da_ponte((string)($row['platform'] ?? ''), (string)($row['origin'] ?? ''));
                if ($canal === '') {
                    continue;
                }
                foreach (ads_roi_fone_variacoes((string)($row['phone'] ?? '')) as $chave) {
                    if (!isset($porFone[$chave]) || !ads_origem_e_anuncio($porFone[$chave])) {
                        $porFone[$chave] = $canal;
                    }
                }
            }
        } catch (Throwable $e) {
            $porFone = [];
        }
        // 2) Origem detectada pela IA. Nunca sobrescreve clique pago.
        try {
            foreach ($pdo->query('SELECT telefone, origem_detectada FROM funil_match WHERE origem_detectada IS NOT NULL AND origem_detectada <> ""') as $row) {
                $canal = ads_roi_canal_do_source((string)($row['origem_detectada'] ?? ''));
                if ($canal === '') {
                    continue;
                }
                foreach (ads_roi_fone_variacoes((string)($row['telefone'] ?? '')) as $chave) {
                    if (!isset($porFone[$chave])) {
                        $porFone[$chave] = $canal;
                    }
                }
            }
        } catch (Throwable $e) {
            // funil_match pode nao existir: segue sem a IA.
        }

        // 3) Vinculo que a IA amarrou (funil_match.appointment_id), o mesmo que a
        // tela de Funil usa: a agenda importada quase nunca guarda telefone, entao
        // so vale quando UM telefone reivindica o agendamento - se dois apontam
        // para o mesmo, e ambiguidade e fica de fora.
        $porAppt = [];
        try {
            $claim = [];
            foreach ($pdo->query('SELECT appointment_id, telefone, origem_detectada FROM funil_match WHERE appointment_id IS NOT NULL') as $row) {
                $id = (int)($row['appointment_id'] ?? 0);
                if ($id > 0) {
                    $claim[$id][] = $row;
                }
            }
            foreach ($claim as $id => $linhas) {
                if (count($linhas) !== 1) {
                    continue;
                }
                $linha = $linhas[0];
                $canal = '';
                foreach (ads_roi_fone_variacoes((string)($linha['telefone'] ?? '')) as $chave) {
                    if (isset($porFone[$chave])) {
                        $canal = $porFone[$chave];
                        break;
                    }
                }
                if ($canal === '') {
                    $canal = ads_roi_canal_do_source((string)($linha['origem_detectada'] ?? ''));
                }
                if ($canal !== '') {
                    $porAppt[$id] = $canal;
                }
            }
        } catch (Throwable $e) {
            $porAppt = [];
        }


        $sql = 'SELECT a.id AS appt_id, COALESCE(NULLIF(c.phone, ""), NULLIF(l.phone, ""), "") AS phone, l.source AS lead_source, a.value AS valor
                  FROM appointments a
                  LEFT JOIN customers c ON c.id = a.customer_id
                  LEFT JOIN leads l ON l.id = a.lead_id
                 WHERE a.appointment_date BETWEEN ? AND ?
                   AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100
                   AND LOWER(TRIM(a.status)) = "finalizado"
                   AND NOT (LOWER(a.title) REGEXP "limpeza|luna|niver|aniversario|casamento|inss|pericia|caf\u00e9 da manh\u00e3|cafe da manha|estorno|escola|tv vizinho|lembrar|s\u00e1bado da|sabado da|spa day|manuten\u00e7\u00e3o|manutencao|vistoria|carro")';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $canal = '';
            foreach (ads_roi_fone_variacoes((string)($row['phone'] ?? '')) as $chave) {
                if (isset($porFone[$chave])) {
                    $canal = $porFone[$chave];
                    break;
                }
            }
            if ($canal === '' && isset($porAppt[(int)($row['appt_id'] ?? 0)])) {
                $canal = $porAppt[(int)($row['appt_id'] ?? 0)];
            }
            if ($canal === '') {
                $canal = ads_roi_canal_do_source((string)($row['lead_source'] ?? ''));
            }
            if ($canal === '') {
                $canal = 'sem_origem';
            }
            if (!isset($out[$canal])) {
                $out[$canal] = ['agendamentos' => 0, 'valor' => 0.0];
            }
            $out[$canal]['agendamentos']++;
            $out[$canal]['valor'] += (float)($row['valor'] ?? 0);
            $out['__total']['agendamentos']++;
            $out['__total']['valor'] += (float)($row['valor'] ?? 0);
        }
    } catch (Throwable $e) {
        return $out;
    }

    $total = $out['__total'];
    unset($out['__total']);
    uasort($out, static function (array $a, array $b): int {
        return ($b['valor'] <=> $a['valor']) ?: ($b['agendamentos'] <=> $a['agendamentos']);
    });
    return ['__total' => $total] + $out;
}

/**
 * VENDAS NOVAS x ATENDIDOS num dia, quebrados pelo canal que trouxe o cliente.
 *
 * VENDAS = agendamentos CRIADOS no dia (o estudio so agenda depois do sinal,
 * entao agendamento novo = venda fechada). Usa COALESCE(google_created_at,
 * created_at): nos importados, created_at e a data da importacao e jogaria
 * venda antiga para o dia errado. Ignora compromisso operacional e cancelado.
 *
 * ATENDIDOS = agendamentos cuja DATA DO ATENDIMENTO e o dia e que aconteceram:
 * 'finalizado' conta sempre; qualquer outro status conta quando a hora ja
 * passou; cancelado/falta nunca conta (mesma regra do "Tatuou" do funil).
 *
 * Canal, em ordem de confianca (a mesma do ads_roi_by_origin):
 *   1) clique pago rastreado (ads_origin_hits), casado pelo telefone;
 *   2) origem que a IA amarrou no funil_match (telefone ou appointment_id);
 *   3) leads.source, so quando e canal de aquisicao de verdade.
 * Meta e Instagram entram juntos em 'meta'; Google Agenda/manual viram
 * 'sem_origem' (importacao nao e canal pago).
 *
 * Devolve ['vendas' => [...], 'atendidos' => [...]] com
 * meta/google/outros/sem_origem/total/valor em cada bloco.
 */
function ads_roi_dia_vendas_atendidos(PDO $pdo, string $data): array
{
    $vazio = ['meta' => 0, 'google' => 0, 'outros' => 0, 'sem_origem' => 0, 'total' => 0, 'valor' => 0.0];
    $out = ['vendas' => $vazio, 'atendidos' => $vazio];

    // 1) Clique pago: telefone -> canal. Ordem crescente + sobrescrita deixa o
    // clique MAIS RECENTE valendo, que e o que normalmente fechou a venda.
    $porFone = [];
    try {
        $st = $pdo->query('SELECT phone, origin, platform, created_at
                             FROM ads_origin_hits
                            ORDER BY created_at ASC');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $canal = ads_roi_canal_da_ponte((string)($h['platform'] ?? ''), (string)($h['origin'] ?? ''));
            if ($canal === '') {
                continue;
            }
            foreach (ads_roi_fone_variacoes((string)($h['phone'] ?? '')) as $k) {
                $porFone[$k] = $canal;
            }
        }
    } catch (Throwable $e) {
        $porFone = [];
    }

    // 2) Origem detectada pela IA. Nunca sobrescreve clique pago.
    $porAppt = [];
    try {
        $claims = [];
        foreach ($pdo->query('SELECT telefone, origem_detectada, appointment_id FROM funil_match') as $row) {
            $canal = ads_roi_canal_do_source((string)($row['origem_detectada'] ?? ''));
            if ($canal === '') {
                continue;
            }
            foreach (ads_roi_fone_variacoes((string)($row['telefone'] ?? '')) as $k) {
                if (!isset($porFone[$k])) {
                    $porFone[$k] = $canal;
                }
            }
            $id = (int)($row['appointment_id'] ?? 0);
            if ($id > 0) {
                $claims[$id][] = $canal;
            }
        }
        foreach ($claims as $id => $canais) {
            $unicos = array_values(array_unique($canais));
            if (count($unicos) === 1) {
                $porAppt[$id] = $unicos[0];
            }
        }
    } catch (Throwable $e) {
        // funil_match pode nao existir: segue so com clique e leads.source.
    }

    // Compromisso pessoal/operacional nao e venda nem atendimento de cliente.
    $operacional = static function (string $titulo): bool {
        return (bool)preg_match(
            '/limpeza|luna|niver|aniversario|aniversário|casamento|inss|pericia|perícia|café da manhã|cafe da manha|estorno|escola|tv vizinho|lembrar|sábado da|sabado da|spa day|manutenção|manutencao|vistoria|carro|não contabilizar|nao contabilizar/iu',
            $titulo
        );
    };

    $canalDe = static function (array $row) use ($porFone, $porAppt): string {
        $canal = '';
        foreach (ads_roi_fone_variacoes((string)($row['phone'] ?? '')) as $k) {
            if (isset($porFone[$k])) {
                $canal = $porFone[$k];
                break;
            }
        }
        $id = (int)($row['id'] ?? 0);
        if ($canal === '' && isset($porAppt[$id])) {
            $canal = $porAppt[$id];
        }
        if ($canal === '') {
            $canal = ads_roi_canal_do_source((string)($row['lead_source'] ?? ''));
        }
        return $canal;
    };

    $bucket = static function (string $canal): string {
        if ($canal === 'meta' || $canal === 'instagram') {
            return 'meta';
        }
        if ($canal === 'google') {
            return 'google';
        }
        return $canal === '' ? 'sem_origem' : 'outros';
    };

    $select = 'SELECT a.id, a.title, a.status, a.appointment_date, a.start_time, a.value,
                      a.import_source, COALESCE(NULLIF(c.phone, ""), NULLIF(l.phone, ""), "") AS phone,
                      l.source AS lead_source
                 FROM appointments a
                 LEFT JOIN customers c ON c.id = a.customer_id
                 LEFT JOIN leads l ON l.id = a.lead_id';

    // ---- VENDAS: agendamentos criados no dia ----
    try {
        $st = $pdo->prepare($select . '
                WHERE DATE(COALESCE(a.google_created_at, a.created_at)) = ?
                  AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100
                  AND (a.import_source IS NULL OR a.import_source = "" OR a.google_created_at IS NOT NULL)
                  AND LOWER(TRIM(a.status)) NOT IN ("cancelado", "cancelada", "cancel", "perdido", "perdida", "falta", "faltou", "no_show", "nao_compareceu")');
        $st->execute([$data]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($operacional((string)($row['title'] ?? ''))) {
                continue;
            }
            $b = $bucket($canalDe($row));
            $out['vendas'][$b]++;
            $out['vendas']['total']++;
            $out['vendas']['valor'] += (float)($row['value'] ?? 0);
        }
    } catch (Throwable $e) {
        // Mantem o bloco zerado se a agenda falhar.
    }

    // ---- ATENDIDOS: agenda do dia que realmente aconteceu ----
    try {
        $st = $pdo->prepare($select . '
                WHERE a.appointment_date = ?
                  AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100');
        $st->execute([$data]);
        $agora = time();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if (in_array($status, ['cancelado', 'cancelada', 'cancel', 'perdido', 'perdida', 'falta', 'faltou', 'no_show', 'nao_compareceu'], true)) {
                continue;
            }
            if ($operacional((string)($row['title'] ?? ''))) {
                continue;
            }
            if ($status !== 'finalizado') {
                $hora = preg_match('/^\d{1,2}:\d{2}/', (string)($row['start_time'] ?? ''), $m) ? $m[0] : '23:59';
                if (strlen($hora) === 4) {
                    $hora = '0' . $hora;
                }
                $ts = strtotime((string)$row['appointment_date'] . ' ' . $hora . ':00');
                if ($ts === false || $ts > $agora) {
                    continue;
                }
            }
            $b = $bucket($canalDe($row));
            $out['atendidos'][$b]++;
            $out['atendidos']['total']++;
            $out['atendidos']['valor'] += (float)($row['value'] ?? 0);
        }
    } catch (Throwable $e) {
        // Mantem o bloco zerado se a agenda falhar.
    }

    return $out;
}

/**
 * Variacoes do telefone para casar entre as tabelas, com a mesma tolerancia do
 * resto do CRM (com/sem DDI e com/sem o 9). Reusa historico_telefone_variacoes
 * quando ela estiver carregada.
 */
function ads_roi_fone_variacoes(string $fone): array
{
    if (function_exists('historico_telefone_variacoes')) {
        return historico_telefone_variacoes($fone);
    }
    $d = preg_replace('/\D+/', '', $fone);
    if ($d === '') {
        return [];
    }
    $out = [$d];
    if (strlen($d) > 11 && str_starts_with($d, '55')) {
        $sem = substr($d, 2);
        $out[] = $sem;
        if (strlen($sem) === 11) {
            $out[] = substr($sem, 0, 2) . substr($sem, 3);
        } elseif (strlen($sem) === 10) {
            $out[] = substr($sem, 0, 2) . '9' . substr($sem, 2);
        }
    } elseif (strlen($d) === 11) {
        $out[] = substr($d, 0, 2) . substr($d, 3);
        $out[] = '55' . $d;
    } elseif (strlen($d) === 10) {
        $out[] = substr($d, 0, 2) . '9' . substr($d, 2);
        $out[] = '55' . $d;
    }
    return array_values(array_unique(array_filter($out)));
}

/**
 * Canal canonico do que a ponte gravou: platform manda (facebook/instagram),
 * origin completa quando a platform vem vazia.
 */
function ads_roi_canal_da_ponte(string $platform, string $origin): string
{
    $p = ads_origem_normalizar($platform);
    if ($p !== '' && $p !== 'outro') {
        return $p;
    }
    $o = ads_origem_normalizar($origin);
    return $o === 'outro' ? '' : $o;
}

/**
 * Canal a partir de um codigo de origem solto. Importacao ("agenda") e digitacao
 * ("manual") NAO sao canal de aquisicao, entao devolvem vazio.
 */
function ads_roi_canal_do_source(string $source): string
{
    $cod = ads_origem_normalizar($source);
    if ($cod === '' || $cod === 'outro' || $cod === 'agenda' || $cod === 'manual') {
        return '';
    }
    return $cod;
}
