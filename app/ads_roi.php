<?php
/**
 * Painel de Retorno dos Anúncios (ads_roi)
 * Cruza gasto por canal (Meta / Google) com leads e AGENDAMENTOS REAIS da agenda.
 * Responde: "o que gastei em ads está voltando?"
 */
declare(strict_types=1);

require_once __DIR__ . '/ads_daily.php';

/**
 * Gasto por dia x canal, resolvendo a precedência entre lançamento manual e a
 * importação real da API ([SYNC META] / [SYNC GOOGLE]).
 *
 * Regra: para cada campaign_date+channel, se existir linha de SYNC, o gasto real da
 * API é a verdade e as linhas manuais do mesmo dia/canal são IGNORADAS (senão o mesmo
 * dinheiro seria somado 2x/3x). Se não houver SYNC, usa a soma dos lançamentos manuais.
 * leads_direct continua sendo somado sempre (dado manual que a API não traz).
 *
 * Retorna [date => [channel => ['spend'=>float,'leads'=>int]]].
 */
function ads_roi_spend_matrix(PDO $pdo, string $start, string $end): array
{
    studio_ads_daily_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT campaign_date, channel, campaign_name, spend, leads_direct
         FROM ads_daily WHERE campaign_date BETWEEN ? AND ?'
    );
    $stmt->execute([$start, $end]);

    // Agrupa em [dia][canal] separando o que veio de SYNC do que é manual.
    $raw = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = (string)$row['campaign_date'];
        $ch = strtolower((string)$row['channel']);
        $name = strtoupper(trim((string)($row['campaign_name'] ?? '')));
        $isSync = str_starts_with($name, '[SYNC');
        // "sync_seen" marca que a API já cobriu aquele dia, mesmo quando o gasto é 0,00.
        // Sem isso, um dia com gasto 0 na API cai no lançamento manual antigo e infla o total.
        $raw[$d][$ch]['sync_seen'] = ($raw[$d][$ch]['sync_seen'] ?? false) || $isSync;
        $raw[$d][$ch]['sync_spend'] = ($raw[$d][$ch]['sync_spend'] ?? 0.0) + ($isSync ? (float)$row['spend'] : 0.0);
        $raw[$d][$ch]['manual_spend'] = ($raw[$d][$ch]['manual_spend'] ?? 0.0) + ($isSync ? 0.0 : (float)$row['spend']);
        $raw[$d][$ch]['leads'] = ($raw[$d][$ch]['leads'] ?? 0) + (int)$row['leads_direct'];
    }

    $out = [];
    foreach ($raw as $d => $channels) {
        foreach ($channels as $ch => $agg) {
            // Basta EXISTIR linha de SYNC para o dia/canal: a API é a verdade, mesmo
            // quando ela devolve 0,00. Só cai no manual quando não houve sync nenhum.
            $hasSync = (bool)($agg['sync_seen'] ?? false);
            $out[$d][$ch] = [
                'spend' => $hasSync ? (float)$agg['sync_spend'] : (float)$agg['manual_spend'],
                'leads' => (int)($agg['leads'] ?? 0),
                'source' => $hasSync ? 'sync' : 'manual',
            ];
        }
    }
    return $out;
}

/**
 * Gasto lançado por canal num período. Retorna [channel => total_spend].
 */
function ads_roi_spend_by_channel(PDO $pdo, string $start, string $end): array
{
    $matrix = ads_roi_spend_matrix($pdo, $start, $end);
    $out = [];
    foreach ($matrix as $channels) {
        foreach ($channels as $ch => $agg) {
            if (!isset($out[$ch])) {
                $out[$ch] = ['spend' => 0.0, 'leads_direct' => 0];
            }
            $out[$ch]['spend'] += (float)$agg['spend'];
            $out[$ch]['leads_direct'] += (int)$agg['leads'];
        }
    }
    return $out;
}

/**
 * Gasto por campanha no periodo, com o que da para atribuir de verdade.
 *
 * Leads por campanha: so o GOOGLE permite atribuicao real, porque o gclid do
 * clique liga o contato da ponte (ads_origin_hits.click_id) a campanha
 * (ads_click_info.campaign_name). No Meta a ponte guarda ctwa_clid, que nao
 * revela a campanha, entao a coluna de leads fica vazia e o CPL sai so por canal.
 *
 * Nao soma dinheiro novo: ads_campaign_daily guarda a quebra do MESMO gasto do
 * dia que ads_daily, entao o total por canal continua vindo de la.
 *
 * Devolve [ ['channel'=>, 'campaign'=>, 'spend'=>, 'dias'=>, 'leads'=>?int,
 * 'cpl'=>?float], ... ] do maior gasto para o menor.
 */
function ads_roi_campaigns(PDO $pdo, string $start, string $end): array
{
    studio_ads_daily_ensure_schema($pdo);
    $out = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT channel, campaign_name, COUNT(*) AS dias, SUM(spend) AS spend
               FROM ads_campaign_daily
              WHERE campaign_date BETWEEN ? AND ?
              GROUP BY channel, campaign_name
              ORDER BY spend DESC'
        );
        $stmt->execute([$start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'channel' => strtolower((string)$row['channel']),
                'campaign' => (string)$row['campaign_name'],
                'spend' => round((float)$row['spend'], 2),
                'dias' => (int)$row['dias'],
                'leads' => null,
                'cpl' => null,
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    if (!$out) {
        return [];
    }

    // Leads por campanha, deduplicados por telefone como no resto do painel.
    // Cada canal tem a sua ligacao entre o contato e a campanha:
    //   Google -> ads_origin_hits.click_id (gclid)      -> ads_click_info
    //   Meta   -> ads_origin_hits.ctwa_clid (id anuncio) -> ads_meta_ad
    // Sem esse vinculo nao existe campanha: o contato fica de fora, nunca chutado.
    $contarPorCampanha = static function (string $sql) use ($pdo, $start, $end): array {
        $mapa = [];
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$start, $end]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mapa[(string)$row['campaign_name']] = (int)$row['leads'];
            }
        } catch (Throwable $e) {
            $mapa = [];
        }
        return $mapa;
    };
    $leadsPorCanal = [
        'google' => $contarPorCampanha(
            'SELECT c.campaign_name, COUNT(DISTINCT h.phone) AS leads
               FROM ads_origin_hits h
               JOIN ads_click_info c ON c.gclid = h.click_id
              WHERE h.click_id IS NOT NULL AND h.click_id <> ""
                AND LOWER(TRIM(h.origin)) = "google"
                AND DATE(h.created_at) BETWEEN ? AND ?
              GROUP BY c.campaign_name'
        ),
        'meta' => $contarPorCampanha(
            'SELECT m.campaign_name, COUNT(DISTINCT h.phone) AS leads
               FROM ads_origin_hits h
               JOIN ads_meta_ad m ON m.ad_id = h.ctwa_clid
              WHERE h.ctwa_clid IS NOT NULL AND h.ctwa_clid <> ""
                AND DATE(h.created_at) BETWEEN ? AND ?
              GROUP BY m.campaign_name'
        ),
    ];
    foreach ($out as $i => $linha) {
        $mapaCampanha = $leadsPorCanal[$linha['channel']] ?? [];
        if (!isset($mapaCampanha[$linha['campaign']])) {
            continue;
        }
        $leads = (int)$mapaCampanha[$linha['campaign']];
        $out[$i]['leads'] = $leads;
        $out[$i]['cpl'] = ($leads > 0 && $linha['spend'] > 0) ? round($linha['spend'] / $leads, 2) : null;
    }
    return $out;
}

/**
 * Gasto por dia x agendamento real (por data). Fonte de agenda: appointments.appointment_date.
 * Considera só tatuagem com valor (>0) e ignora cobranças/tarefas.
 */
function ads_roi_daily_series(PDO $pdo, string $start, string $end): array
{
    studio_ads_daily_ensure_schema($pdo);

    // Usa a matriz com precedência SYNC > manual (evita somar o mesmo gasto 2x/3x).
    $spendByDay = ads_roi_spend_matrix($pdo, $start, $end);

    // Só conta como REALIZADO a linha que tem valor cadastrado (> 0). Compromissos da
    // agenda sem valor (limpeza, reunião, bloqueio de horário) ficam fora - senão o CPA
    // e o ROAS saem artificialmente bons. Agendamento FUTURO (confirmado) e contado
    // separado, pela data do evento, no bloco "agendados_futuros" do dia.
    $apptSql = 'SELECT appointment_date,
                       SUM(CASE WHEN LOWER(status) = "finalizado"
                                 AND NOT (LOWER(title) REGEXP "limpeza|luna|niver|aniversario|casamento|inss|pericia|caf\u00e9 da manh\u00e3|cafe da manha|estorno|escola|tv vizinho|lembrar|s\u00e1bado da|sabado da|spa day|manuten\u00e7\u00e3o|manutencao|vistoria|carro")
                                THEN 1 ELSE 0 END) AS realizados,
                       SUM(CASE WHEN LOWER(status) = "finalizado" THEN value ELSE 0 END) AS valor_realizado,
                       SUM(CASE WHEN LOWER(status) IN ("confirmado","pre_agendado","agendado") THEN 1 ELSE 0 END) AS agendados_futuros,
                       SUM(CASE WHEN value > 0 AND LOWER(status) IN ("cancelado","canceled","cancelada") THEN 1 ELSE 0 END) AS cancelados,
                       SUM(CASE WHEN LOWER(status) IN ("cancelado","canceled","cancelada") THEN 1 ELSE 0 END) AS cancelados_total
                FROM appointments
                WHERE appointment_date BETWEEN ? AND ?
                  AND YEAR(appointment_date) BETWEEN 2000 AND 2100
                GROUP BY appointment_date';
    $apptStmt = $pdo->prepare($apptSql);
    $apptStmt->execute([$start, $end]);
    $apptByDay = [];
    foreach ($apptStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $apptByDay[(string)$row['appointment_date']] = $row;
    }

    $days = [];
    $cursor = new DateTime($start);
    $limit = new DateTime($end);
    while ($cursor <= $limit) {
        $d = $cursor->format('Y-m-d');
        $metaSpend = (float)($spendByDay[$d]['meta']['spend'] ?? 0);
        $googleSpend = (float)($spendByDay[$d]['google']['spend'] ?? 0);
        $tiktokSpend = (float)($spendByDay[$d]['tiktok']['spend'] ?? 0);
        $appt = $apptByDay[$d] ?? ['realizados' => 0, 'valor_realizado' => 0, 'agendados_futuros' => 0, 'cancelados' => 0, 'cancelados_total' => 0];
        $totalSpend = $metaSpend + $googleSpend + $tiktokSpend;
        $real = (int)$appt['realizados'];
        $valReal = (float)$appt['valor_realizado'];
        $days[] = [
            'date' => $d,
            'meta_spend' => $metaSpend,
            'google_spend' => $googleSpend,
            'tiktok_spend' => $tiktokSpend,
            'spend_total' => $totalSpend,
            // "agendamentos" mantido por compatibilidade: aqui significa REALIZADOS.
            'agendamentos' => $real,
            'realizados' => $real,
            'valor_realizado' => $valReal,
            'agendados_futuros' => (int)$appt['agendados_futuros'],
            'cancelados' => (int)$appt['cancelados'],
            'cancelados_total' => (int)($appt['cancelados_total'] ?? 0),
            'valor_agendado' => $valReal,
            'custo_por_agendamento' => $real > 0 ? round($totalSpend / $real, 2) : null,
            'roas' => ($totalSpend > 0 && $valReal > 0) ? round($valReal / $totalSpend, 2) : null,
        ];
        $cursor->modify('+1 day');
    }
    return $days;
}

/**
 * Resumo consolidado do período + comparação Meta x Google x TikTok.
 */
function ads_roi_summary(PDO $pdo, string $start, string $end): array
{
    $series = ads_roi_daily_series($pdo, $start, $end);
    $spend = ads_roi_spend_by_channel($pdo, $start, $end);

    $totMeta = (float)($spend['meta']['spend'] ?? 0);
    $totGoogle = (float)($spend['google']['spend'] ?? 0);
    $totTiktok = (float)($spend['tiktok']['spend'] ?? 0);
    $totSpend = $totMeta + $totGoogle + $totTiktok;

    $totAg = 0;
    $totCancel = 0;
    $totCancelTotal = 0;
    $totValor = 0.0;
    foreach ($series as $d) {
        $totAg += (int)$d['agendamentos'];
        $totCancel += (int)$d['cancelados'];
        $totCancelTotal += (int)($d['cancelados_total'] ?? $d['cancelados']);
        $totValor += (float)$d['valor_agendado'];
    }

    return [
        'spend_meta' => $totMeta,
        'spend_google' => $totGoogle,
        'spend_tiktok' => $totTiktok,
        'spend_total' => $totSpend,
        'agendamentos' => $totAg,
        'cancelados' => $totCancel,
        'cancelados_total' => $totCancelTotal,
        'valor_agendado' => $totValor,
        'custo_por_agendamento' => $totAg > 0 ? round($totSpend / $totAg, 2) : null,
        'roas' => $totSpend > 0 ? round($totValor / $totSpend, 2) : null,
        'series' => $series,
    ];
}

/**
 * AGENDADO x REALIZADO - duas coisas DIFERENTES (regra do dono, 22/09/2026).
 *
 *   AGENDADO  = o cliente marcou e reservou a data. Conta na data em que o
 *               agendamento foi CRIADO (created_at), inclusive os que depois
 *               foram cancelados (marcaram, mas nao veio).
 *   REALIZADO = a tatuagem ACONTECEU de fato. So status 'finalizado'.
 *               Cancelado NUNCA conta como realizado.
 *
 * Antes o painel somava os dois num campo so ("agendamentos"), filtrando por
 * appointment_date - o que somava promessa futura e venda cancelada junto da
 * venda feita, e contava no dia errado.
 *
 * Devolve os totais do periodo + serie diaria para os dois blocos.
 */
function ads_roi_agendado_realizado(PDO $pdo, string $start, string $end): array
{
    $agendadosSerie = [];
    $realizadosSerie = [];
    $futurosSerie = [];
    // AGENDADOS FUTUROS: eventos com data >= hoje e status confirmado/pre-agendado.
    // Esta e a unica contagem de "agendado" confiavel, porque usa a DATA DO EVENTO
    // (a agenda do Google) e nao o created_at - que nos importados e a data da
    // importacao, nao a data em que o cliente marcou. Responde "quantos clientes
    // tenho na frente", que e a pergunta util.
    // EXCLUI compromisso pessoal/operacional (limpeza, Luna, niver, casamento,
    // pericia INSS, etc): nao sao venda. Filtro por titulo, como o parser do sync.
    $stFut = $pdo->prepare(
        'SELECT appointment_date AS dia, COUNT(*) AS n, SUM(value) AS valor
           FROM appointments
          WHERE appointment_date BETWEEN ? AND ?
            AND YEAR(appointment_date) BETWEEN 2000 AND 2100
            AND LOWER(TRIM(status)) IN ("confirmado", "pre_agendado", "agendado")
            AND NOT (
                 LOWER(title) REGEXP "limpeza|luna|niver|aniversario|aniversário|casamento|inss|pericia|perícia|café da manhã|cafe da manha|estorno|escola|tv vizinho|lembrar|sábado da|sabado da|spa day|manutenção|manutencao"
            )
          GROUP BY appointment_date'
    );
    $stFut->execute([$start, $end]);
    foreach ($stFut->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $futurosSerie[(string)$r['dia']] = ['n' => (int)$r['n'], 'valor' => (float)$r['valor']];
    }

    // REALIZADO: tatuagem feita. So 'finalizado', pela data do atendimento.
    $st2 = $pdo->prepare(
        'SELECT appointment_date AS dia, COUNT(*) AS n, SUM(value) AS valor
           FROM appointments
          WHERE appointment_date BETWEEN ? AND ?
            AND YEAR(appointment_date) BETWEEN 2000 AND 2100
            AND LOWER(TRIM(status)) = "finalizado"
            -- "Tatuou" = cliente ATENDIDO (decisao do dono): nao exige valor.
            -- Mas exclui compromisso operacional (limpeza, vistoria, carro...).
            AND NOT (LOWER(title) REGEXP "limpeza|luna|niver|aniversario|casamento|inss|pericia|caf\u00e9 da manh\u00e3|cafe da manha|estorno|escola|tv vizinho|lembrar|s\u00e1bado da|sabado da|spa day|manuten\u00e7\u00e3o|manutencao|vistoria|carro")
          GROUP BY appointment_date'
    );
    $st2->execute([$start, $end]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $realizadosSerie[(string)$r['dia']] = [
            'n' => (int)$r['n'],
            'valor' => (float)$r['valor'],
        ];
    }

    // CPA: realizados COM valor (> 0). "Realizados" conta atendidos sem exigir
    // valor, mas o custo por tatuagem precisa medir so o que gerou dinheiro.
    $comValor = 0;
    try {
        $stCV = $pdo->prepare(
            'SELECT COUNT(*) FROM appointments
              WHERE appointment_date BETWEEN ? AND ?
                AND YEAR(appointment_date) BETWEEN 2000 AND 2100
                AND LOWER(TRIM(status)) = "finalizado"
                AND value > 0'
        );
        $stCV->execute([$start, $end]);
        $comValor = (int)$stCV->fetchColumn();
    } catch (Throwable $e) {
        $comValor = 0;
    }

    $somaFut = 0; $valFut = 0.0; $somaReal = 0; $valReal = 0.0;
    foreach ($futurosSerie as $x) { $somaFut += $x['n']; $valFut += $x['valor']; }
    foreach ($realizadosSerie as $x) { $somaReal += $x['n']; $valReal += $x['valor']; }

    return [
        'futuros' => $somaFut,
        'futuros_valor' => $valFut,
        'realizados' => $somaReal,
        'realizados_com_valor' => $comValor,
        'realizados_valor' => $valReal,
        'serie_futuros' => $futurosSerie,
        'serie_realizados' => $realizadosSerie,
        // Compatibilidade: "agendados" agora significa os futuros.
        'agendados' => $somaFut,
        'agendados_valor' => $valFut,
        'serie_agendados' => $futurosSerie,
    ];
}

/**
 * Projeção mensal baseada na média diária de gasto configurada.
 */
function ads_roi_monthly_projection(PDO $pdo): array
{
    studio_ads_daily_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT channel, daily_budget, active FROM ads_channel_config');
    $daily = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int)$row['active'] === 1) {
            $daily += (float)$row['daily_budget'];
        }
    }
    return [
        'daily' => round($daily, 2),
        'monthly' => round($daily * 30, 2),
    ];
}

/**
 * Canal de gasto do ads_daily ('meta' / 'google' / 'tiktok') a partir do canal da ponte.
 * Instagram e Facebook gastam na MESMA conta do Meta Ads, entao viram 'meta';
 * o que nao e anuncio (agenda, indicacao, porta, whatsapp...) devolve ''.
 */
function ads_roi_canal_de_gasto(string $canalPonte): string
{
    if ($canalPonte === 'google') {
        return 'google';
    }
    if ($canalPonte === 'tiktok') {
        return 'tiktok';
    }
    if (in_array($canalPonte, ['meta', 'facebook', 'instagram'], true)) {
        return 'meta';
    }
    return '';
}

/**
 * Conta os contatos de anuncio por dia e canal, do mesmo jeito que a ponte
 * (ads_origin_hits): 1 hit = 1 contato, deduplicado por telefone no dia.
 * Devolve [dia => [canal => n]] com o canal de gasto ('meta' / 'google' / 'tiktok').
 */
function ads_roi_leads_por_dia(PDO $pdo, string $start, string $end): array
{
    $out = [];
    $sql = 'SELECT dia, plataforma, origem, COUNT(*) AS total FROM (
                SELECT DATE(created_at) AS dia,
                       phone,
                       LOWER(COALESCE(NULLIF(TRIM(platform), ""), NULLIF(TRIM(origin), ""))) AS plataforma,
                       LOWER(TRIM(origin)) AS origem
                  FROM ads_origin_hits
                 WHERE DATE(created_at) BETWEEN ? AND ?
                 GROUP BY dia, phone, plataforma, origem
            ) t GROUP BY dia, plataforma, origem';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $canal = ads_roi_canal_de_gasto(ads_roi_canal_da_ponte((string)$row['plataforma'], (string)$row['origem']));
            if ($canal === '') {
                continue;
            }
            $dia = (string)$row['dia'];
            $out[$dia][$canal] = ($out[$dia][$canal] ?? 0) + (int)$row['total'];
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

/**
 * Grava em ads_daily.leads_direct o total de contatos do dia para UM canal.
 *
 * Por que existe: o sync so escrevia o gasto, entao o painel mostrava leads
 * sempre em 0 e o CPL por canal saia vazio. A contagem sai da ponte
 * (ads_origin_hits), a mesma fonte usada no resto do painel, e reescreve o
 * intervalo inteiro: em cada dia a ponte e a verdade, inclusive quando e 0.
 *
 * Best effort: devolve quantos dias foram processados.
 */
function ads_roi_leads_direct_sync(PDO $pdo, string $canal, string $start, string $end): int
{
    $rotulos = ['meta' => '[SYNC META]', 'google' => '[SYNC GOOGLE]', 'tiktok' => '[SYNC TIKTOK]'];
    $rotulo = $rotulos[$canal] ?? '';
    if ($rotulo === '') {
        return 0;
    }
    $porDia = ads_roi_leads_por_dia($pdo, $start, $end);
    $stmt = $pdo->prepare(
        'UPDATE ads_daily SET leads_direct = ?
          WHERE campaign_date = ? AND channel = ? AND campaign_name = ?'
    );
    $dias = 0;
    $cursor = new DateTime($start);
    $limite = new DateTime($end);
    while ($cursor <= $limite) {
        $dia = $cursor->format('Y-m-d');
        $stmt->execute([(int)($porDia[$dia][$canal] ?? 0), $dia, $canal, $rotulo]);
        $dias++;
        $cursor->modify('+1 day');
    }
    return $dias;
}

/**
 * Quebra o gasto do Meta por campanha em ads_campaign_daily (uma linha por
 * campanha/dia). Best effort: nao altera o gasto do dia em ads_daily e nunca
 * derruba o sync quando a API falha.
 *
 * Devolve ['ok'=>bool,'imported'=>int,'error'=>?string].
 */
function ads_roi_meta_campaigns_sync(array $studio, int $days = 30): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $accountId = (string)preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? '')));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0')) ?: 'v22.0';
    if ($token === '' || $accountId === '') {
        return ['ok' => false, 'imported' => 0, 'error' => 'Meta Ads sem token ou conta configurados.'];
    }
    $days = max(1, min(365, $days));
    $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $until = date('Y-m-d');
    $response = studio_meta_ads_request($version, '/act_' . $accountId . '/insights', $token, [
        'fields' => 'spend,date_start,campaign_id,campaign_name',
        'time_increment' => 1,
        'time_range' => json_encode(['since' => $since, 'until' => $until], JSON_UNESCAPED_SLASHES),
        'level' => 'campaign',
        'limit' => 500,
    ], 'GET', null, 60);
    if (empty($response['ok'])) {
        return ['ok' => false, 'imported' => 0, 'error' => (string)($response['error'] ?? 'Falha ao consultar Meta API.')];
    }
    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO ads_campaign_daily (campaign_date, channel, campaign_id, campaign_name, spend)
         VALUES (?, "meta", ?, ?, ?)
         ON DUPLICATE KEY UPDATE campaign_name = VALUES(campaign_name), spend = VALUES(spend)'
    );
    $imported = 0;
    $vistos = [];
    foreach (($response['json']['data'] ?? []) as $row) {
        $date = (string)($row['date_start'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            continue;
        }
        $cid = trim((string)($row['campaign_id'] ?? ''));
        $cname = trim((string)($row['campaign_name'] ?? ''));
        if ($cid === '' && $cname === '') {
            continue;
        }
        $chave = $date . '|' . $cid;
        if (isset($vistos[$chave])) {
            continue;
        }
        $vistos[$chave] = true;
        $stmt->execute([$date, $cid, $cname !== '' ? $cname : ('campanha ' . $cid), round((float)($row['spend'] ?? 0), 2)]);
        $imported++;
    }
    return ['ok' => true, 'imported' => $imported, 'error' => null];
}

/**
 * Descobre a campanha de cada anuncio do Meta que trouxe contato.
 *
 * POR QUE EXISTE
 * O pacote que a Meta anexa na mensagem (ctwaContext) traz o source_id, que e o
 * ID DO ANUNCIO, e a ponte ja grava esse id em ads_origin_hits.ctwa_clid. O
 * rastreio por WhatsApp Web nao conhece a campanha, mas a API do Meta conhece:
 * dado o id do anuncio, ela devolve a campanha. Sem isso o Meta aparece sem
 * lead/CPL por campanha no painel, porque so o Instagram/Facebook sabem.
 *
 * Guarda em ads_meta_ad e nao pergunta o mesmo anuncio de novo. Quem falhou por
 * rede/API nao e marcado perdido: volta na proxima rodada. So marca
 * "nao_encontrado" quando a API respondeu e nao veio campanha (anuncio de outra
 * conta, por exemplo), e mesmo assim retenta no maximo 1x por dia.
 *
 * Best effort: devolve ['ok'=>bool,'resolvidos'=>int,'pendentes'=>int,'erro'=>?string].
 */
function ads_roi_meta_resolver_anuncios(array $studio, int $max = 50): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0')) ?: 'v22.0';
    if ($token === '') {
        return ['ok' => false, 'resolvidos' => 0, 'pendentes' => 0, 'erro' => 'Meta Ads sem token configurado.'];
    }
    try {
        $pdo = studio_db($studio);
        studio_ads_daily_ensure_schema($pdo);
        $stmt = $pdo->query(
            'SELECT h.ctwa_clid AS ad_id
               FROM ads_origin_hits h
               LEFT JOIN ads_meta_ad m ON m.ad_id = h.ctwa_clid
              WHERE h.ctwa_clid IS NOT NULL AND h.ctwa_clid <> ""
                AND (m.ad_id IS NULL
                     OR (m.estado <> "ok" AND m.tentado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)))
              GROUP BY h.ctwa_clid
              LIMIT ' . max(1, min(200, $max))
        );
        $anuncios = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        return ['ok' => false, 'resolvidos' => 0, 'pendentes' => 0, 'erro' => 'Falha ao listar os anuncios do Meta.'];
    }
    if (!$anuncios) {
        return ['ok' => true, 'resolvidos' => 0, 'pendentes' => 0, 'erro' => null];
    }

    $salvarOk = $pdo->prepare('INSERT INTO ads_meta_ad (ad_id, campaign_id, campaign_name, estado, tentado_em)
        VALUES (?, ?, ?, "ok", NOW())
        ON DUPLICATE KEY UPDATE campaign_id = VALUES(campaign_id), campaign_name = VALUES(campaign_name),
            estado = "ok", tentado_em = NOW()');
    $salvarErro = $pdo->prepare('INSERT INTO ads_meta_ad (ad_id, campaign_id, campaign_name, estado, tentado_em)
        VALUES (?, "", "", "nao_encontrado", NOW())
        ON DUPLICATE KEY UPDATE estado = IF(estado = "ok", estado, "nao_encontrado"), tentado_em = NOW()');

    $resolvidos = 0;
    foreach ($anuncios as $adId) {
        $adId = trim((string)$adId);
        if ($adId === '') {
            continue;
        }
        $res = studio_meta_ads_request($version, '/' . rawurlencode($adId), $token, [
            'fields' => 'id,name,campaign_id,campaign{id,name}',
        ], 'GET', null, 30);
        if (empty($res['ok'])) {
            continue; // rede/API: volta na proxima rodada, nao marca como perdido
        }
        $campanhaNome = trim((string)($res['json']['campaign']['name'] ?? ''));
        if ($campanhaNome === '') {
            $salvarErro->execute([$adId]);
            continue;
        }
        $salvarOk->execute([$adId, (string)($res['json']['campaign']['id'] ?? ''), $campanhaNome]);
        $resolvidos++;
    }
    return ['ok' => true, 'resolvidos' => $resolvidos, 'pendentes' => count($anuncios) - $resolvidos, 'erro' => null];
}

/**
 * Quebra o gasto do Google por campanha/dia em ads_campaign_daily.
 *
 * Roda como consulta SEPARADA da que fecha o gasto do dia: a consulta de gasto
 * sai de "FROM customer" (nao aceita campo de campanha) e esta sai de
 * "FROM campaign". Se esta falhar, o total do dia em ads_daily fica intacto.
 *
 * Best effort: devolve ['ok'=>bool,'imported'=>int,'error'=>?string].
 */
function ads_roi_google_campaigns_sync(array $studio, int $days, array $headers, string $url): array
{
    $days = max(1, min(365, $days));
    $ate = date('Y-m-d');
    $de = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $consulta = 'SELECT segments.date, metrics.cost_micros, campaign.id, campaign.name FROM campaign '
        . 'WHERE segments.date BETWEEN "' . $de . '" AND "' . $ate . '" '
        . 'AND metrics.cost_micros > 0';
    $res = ads_roi_http_request('POST', $url, $headers, ['query' => $consulta]);
    if (empty($res['ok'])) {
        return ['ok' => false, 'imported' => 0, 'error' => 'Google Ads API (campanha): ' . (string)($res['error'] ?? 'erro desconhecido')];
    }
    $porCampanha = [];
    foreach ((array)($res['json']['results'] ?? []) as $linha) {
        $data = (string)($linha['segments']['date'] ?? '');
        $micros = (float)($linha['metrics']['costMicros'] ?? 0);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) !== 1 || $micros <= 0) {
            continue;
        }
        $cid = (string)($linha['campaign']['id'] ?? '');
        $cname = (string)($linha['campaign']['name'] ?? '');
        if ($cid === '' && $cname === '') {
            continue;
        }
        $chave = $data . '|' . $cid;
        if (!isset($porCampanha[$chave])) {
            $porCampanha[$chave] = ['dia' => $data, 'id' => $cid, 'nome' => $cname !== '' ? $cname : ('campanha ' . $cid), 'spend' => 0.0];
        }
        $porCampanha[$chave]['spend'] += $micros / 1000000;
    }
    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO ads_campaign_daily (campaign_date, channel, campaign_id, campaign_name, spend)
         VALUES (?, "google", ?, ?, ?)
         ON DUPLICATE KEY UPDATE campaign_name = VALUES(campaign_name), spend = VALUES(spend)'
    );
    $imported = 0;
    foreach ($porCampanha as $linhaCamp) {
        $stmt->execute([$linhaCamp['dia'], $linhaCamp['id'], $linhaCamp['nome'], round((float)$linhaCamp['spend'], 2)]);
        $imported++;
    }
    return ['ok' => true, 'imported' => $imported, 'error' => null];
}

/**
 * Sincroniza o gasto diário REAL do Meta Ads (via API de insights) na tabela ads_daily.
 * Grava com campaign_name = '[SYNC META]' para não colidir com lançamentos manuais
 * (chave única é campaign_date+channel+campaign_name). Upsert preserva leads manuais.
 * Retorna ['ok'=>bool,'imported'=>int,'days'=>n,'error'=>?string].
 */
function ads_roi_sync_meta(array $studio, int $days = 30): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $accountId = (string)preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? '')));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0')) ?: 'v22.0';
    if ($token === '' || $accountId === '') {
        return ['ok' => false, 'error' => 'Meta Ads sem token ou conta configurados.', 'imported' => 0, 'days' => 0];
    }
    $days = max(1, min(365, $days));

    $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $until = date('Y-m-d');
    $response = studio_meta_ads_request($version, '/act_' . $accountId . '/insights', $token, [
        'fields' => 'spend,date_start',
        'time_increment' => 1,
        'time_range' => json_encode(['since' => $since, 'until' => $until], JSON_UNESCAPED_SLASHES),
        'level' => 'account',
        'limit' => 500,
    ], 'GET', null, 60);
    if (!$response['ok']) {
        return ['ok' => false, 'error' => (string)($response['error'] ?? 'Falha ao consultar Meta API.'), 'imported' => 0, 'days' => $days];
    }

    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO ads_daily (campaign_date, channel, campaign_name, spend, notes)
         VALUES (?, "meta", "[SYNC META]", ?, NULL)
         ON DUPLICATE KEY UPDATE spend = VALUES(spend)'
    );
    $imported = 0;
    $spendPorDia = [];
    foreach (($response['json']['data'] ?? []) as $row) {
        $date = (string)($row['date_start'] ?? '');
        $spend = (float)($row['spend'] ?? 0);
        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { continue; }
        $spendPorDia[$date] = $spend;
    }
    // Grava TODO o intervalo, inclusive dias sem gasto (0,00), para a API ser a
    // fonte da verdade do período e o lançamento manual antigo não voltar a contar.
    $cursor = new DateTime($since);
    $limite = new DateTime($until);
    while ($cursor <= $limite) {
        $date = $cursor->format('Y-m-d');
        $stmt->execute([$date, round((float)($spendPorDia[$date] ?? 0.0), 2)]);
        $imported++;
        $cursor->modify('+1 day');
    }
    // De qual campanha veio cada anuncio (id do anuncio -> campanha). Sem isso o
    // painel nao consegue atribuir lead as campanhas do Meta: a ponte grava o id
    // do anuncio, mas so a API do Meta sabe a campanha dele.
    $anuncios = null;
    try {
        $anuncios = ads_roi_meta_resolver_anuncios($studio, 50);
    } catch (Throwable $e) {
        $anuncios = null;
    }
    // Quebra por campanha e leads do canal. Best effort: se a Meta API ou a
    // contagem da ponte falhar, o gasto do dia (o que o painel usa) segue igual.
    $campanhas = null;
    try {
        $campanhas = ads_roi_meta_campaigns_sync($studio, $days);
    } catch (Throwable $e) {
        $campanhas = null;
    }
    try {
        ads_roi_leads_direct_sync($pdo, 'meta', $since, $until);
    } catch (Throwable $e) {
        // segue
    }
    // Orcamento real dos anuncios ativos da conta: o painel para de depender do
    // valor fixo. Best effort: nunca derruba o sync de gasto.
    $orcamento = null;
    try {
        $orcamento = ads_roi_meta_budget_sync($studio);
    } catch (Throwable $e) {
        $orcamento = null;
    }

    return ['ok' => true, 'imported' => $imported, 'days' => $days, 'orcamento' => $orcamento, 'campanhas' => $campanhas];
}

/**
 * Orcamento diario REAL dos anuncios ativos na conta do Meta Ads.
 *
 * Soma o orcamento diario das campanhas ATIVAS que tem orcamento na propria
 * campanha (CBO) e, para as campanhas ativas SEM orcamento proprio, soma o
 * orcamento diario dos conjuntos de anuncios ATIVOS delas. Orcamento de
 * periodo (lifetime_budget) fica fora: nao e "por dia". Cada orcamento entra
 * UMA vez. Existe para o painel de ROI nao depender do valor fixo gravado em
 * ads_channel_config.
 *
 * Retorna sempre array (nunca lanca):
 *   ['ok' => bool, 'daily' => float, 'campanhas' => int, 'conjuntos' => int, 'error' => ?string]
 */
function ads_roi_meta_daily_budget(array $studio): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $contaId = (string)preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? '')));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0')) ?: 'v22.0';
    if ($token === '' || $contaId === '') {
        return ['ok' => false, 'daily' => 0.0, 'campanhas' => 0, 'conjuntos' => 0,
            'error' => 'Meta Ads sem token ou conta configurados.'];
    }

    $res = studio_meta_ads_request($version, '/act_' . $contaId . '/campaigns', $token, [
        'fields' => 'id,name,effective_status,daily_budget,lifetime_budget',
        'limit' => 200,
    ], 'GET', null, 45);
    if (empty($res['ok'])) {
        return ['ok' => false, 'daily' => 0.0, 'campanhas' => 0, 'conjuntos' => 0,
            'error' => 'Meta Ads API: ' . (string)($res['error'] ?? 'erro desconhecido')];
    }

    $total = 0.0;
    $campanhasComOrcamento = 0;
    $semOrcamento = [];
    foreach ((array)($res['json']['data'] ?? []) as $campanha) {
        if ((string)($campanha['effective_status'] ?? '') !== 'ACTIVE') {
            continue;
        }
        $diario = ((float)($campanha['daily_budget'] ?? 0)) / 100;
        if ($diario > 0) {
            $total += $diario;
            $campanhasComOrcamento++;
            continue;
        }
        // Sem orcamento na campanha: o valor esta nos conjuntos de anuncios dela.
        $id = (string)($campanha['id'] ?? '');
        if ($id !== '') {
            $semOrcamento[$id] = true;
        }
    }

    $conjuntos = 0;
    if ($semOrcamento) {
        $resConj = studio_meta_ads_request($version, '/act_' . $contaId . '/adsets', $token, [
            'fields' => 'id,campaign_id,effective_status,daily_budget,lifetime_budget',
            'limit' => 500,
        ], 'GET', null, 45);
        if (empty($resConj['ok'])) {
            return ['ok' => false, 'daily' => round($total, 2), 'campanhas' => $campanhasComOrcamento, 'conjuntos' => 0,
                'error' => 'Meta Ads API (conjuntos): ' . (string)($resConj['error'] ?? 'erro desconhecido')];
        }
        foreach ((array)($resConj['json']['data'] ?? []) as $conjunto) {
            if (empty($semOrcamento[(string)($conjunto['campaign_id'] ?? '')])) {
                continue;
            }
            if ((string)($conjunto['effective_status'] ?? '') !== 'ACTIVE') {
                continue;
            }
            $diario = ((float)($conjunto['daily_budget'] ?? 0)) / 100;
            if ($diario <= 0) {
                continue;
            }
            $total += $diario;
            $conjuntos++;
        }
    }

    return ['ok' => true, 'daily' => round($total, 2), 'campanhas' => $campanhasComOrcamento,
        'conjuntos' => $conjuntos, 'error' => null];
}

/**
 * Le o orcamento real da conta do Meta e grava em ads_channel_config (canal
 * meta), de onde o painel tira a projecao mensal. Se a API falhar ou vier
 * zero, mantem o valor que ja estava gravado.
 *
 * Retorna o mesmo array de ads_roi_meta_daily_budget, com 'salvo' => bool.
 */
function ads_roi_meta_budget_sync(array $studio): array
{
    $orc = ads_roi_meta_daily_budget($studio);
    $orc['salvo'] = false;
    if (empty($orc['ok']) || (float)$orc['daily'] <= 0) {
        return $orc;
    }

    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);
    $pdo->prepare('INSERT INTO ads_channel_config (channel, daily_budget) VALUES ("meta", ?) '
        . 'ON DUPLICATE KEY UPDATE daily_budget = VALUES(daily_budget)')
        ->execute([(float)$orc['daily']]);
    $orc['salvo'] = true;
    return $orc;
}

/**
 * Credenciais OAuth do Google Ads, lidas das configuracoes do estudio.
 * O CRM guarda client id/secret em studio_settings (mesma origem do Calendar quando vazio).
 */
function ads_roi_google_oauth_config(array $studio): array
{
    $settings = studio_settings($studio);
    // Credenciais locais (fora do versionamento), no mesmo padrao do Google Calendar.
    $caminho = dirname(__DIR__) . '/config/google_ads.local.json';
    $cred = [];
    if (is_file($caminho)) {
        $dec = json_decode((string)file_get_contents($caminho), true);
        if (is_array($dec)) {
            $cred = is_array($dec['web'] ?? null) ? $dec['web'] : ($dec['installed'] ?? []);
        }
    }
    $clientId = trim((string)($cred['client_id'] ?? $settings['google_ads_client_id'] ?? ''));
    // O segredo tambem pode vir do cofre, injetado como variavel de ambiente.
    $clientSecret = trim((string)($cred['client_secret'] ?? ''));
    if ($clientSecret === '') {
        $clientSecret = trim((string)(getenv('GOOGLE_ADS_CLIENT_SECRET') ?: ''));
    }
    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => trim((string)(($cred['redirect_uris'][0] ?? null) ?: 'https://danieltatuador.com/projetocrm/google_ads_oauth_callback.php')),
        'auth_uri' => trim((string)($cred['auth_uri'] ?? 'https://accounts.google.com/o/oauth2/v2/auth')),
        'token_uri' => trim((string)($cred['token_uri'] ?? 'https://oauth2.googleapis.com/token')),
        // Escopo do Google Ads. Ver docs: o escopo adwords cobre leitura de relatorios.
        // Inclui o datamanager: e o escopo exigido pela Data Manager API, usada
        // desde 09/2026 para DEVOLVER as conversoes reais do CRM ao Google Ads
        // (as antigas uploadClickConversions da API do Google Ads foram fechadas
        // para novas integracoes). Reconectar o Google Ads no painel renova o
        // consentimento com este escopo.
        'scopes' => [
            'https://www.googleapis.com/auth/adwords',
            'https://www.googleapis.com/auth/datamanager',
        ],
    ];
}

/**
 * URL da tela de consentimento do Google para conectar o Google Ads.
 * Guarda o state na sessao (mesmo padrao do Calendar) e forca prompt=consent
 * para o Google sempre devolver o refresh_token.
 */
function ads_roi_google_authorization_url(array $studio): string
{
    $config = ads_roi_google_oauth_config($studio);
    if ($config['client_id'] === '' || $config['client_secret'] === '') {
        throw new RuntimeException('As credenciais OAuth do Google ainda nao estao configuradas (client id e secret).');
    }
    $state = bin2hex(random_bytes(24));
    $_SESSION['google_ads_oauth'] ??= [];
    $_SESSION['google_ads_oauth'][$state] = [
        'studio_id' => (int)$studio['id'],
        'expires_at' => time() + 900,
    ];
    return $config['auth_uri'] . '?' . http_build_query([
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', array_map('strval', $config['scopes'])),
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'false',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Troca o code por tokens (authorization_code) no OAuth do Google.
 * Aceita o state para funcionar tambem quando a autorizacao veio de outro aparelho
 * (sem sessao ativa no navegador que abriu o callback).
 */
function ads_roi_google_exchange_code(string $code, string $state = ''): array
{
    // Sem sessao (autorizacao feita em outro dispositivo), usa o estudio 1 apenas
    // como contexto para resolver client id/secret - o token e gravado depois.
    $studio = current_studio();
    if (!is_array($studio) || !$studio) {
        $studio = get_studio(1) ?: [];
    }
    $config = ads_roi_google_oauth_config(is_array($studio) ? $studio : []);
    $res = ads_roi_http_request('POST', $config['token_uri'], [], [
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code',
    ], 'form');
    if (!$res['ok']) {
        throw new RuntimeException('Falha ao trocar o codigo pelo token: ' . (string)($res['error'] ?? 'erro desconhecido'));
    }
    return (array)$res['json'];
}

/**
 * Guarda o refresh token do Google Ads nas configuracoes do estudio.
 * Garante a coluna ANTES de escrever (nao confia em capturar o erro do INSERT:
 * dependendo do driver, a coluna inexistente nao cai no catch de forma confiavel).
 */
function ads_roi_google_store_refresh_token(array $studio, string $refreshToken): void
{
    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);

    // 1) Garante a coluna.
    $col = $pdo->query("SHOW COLUMNS FROM studio_settings LIKE 'google_ads_refresh_token'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec('ALTER TABLE studio_settings ADD COLUMN google_ads_refresh_token TEXT NULL');
    }

    // 2) Grava.
    $stmt = $pdo->prepare('INSERT INTO studio_settings (id, google_ads_refresh_token) VALUES (1, ?) ON DUPLICATE KEY UPDATE google_ads_refresh_token = VALUES(google_ads_refresh_token)');
    $stmt->execute([$refreshToken]);
}

/**
 * Troca o refresh token por um access token do Google (OAuth 2.0).
 * Retorna ['ok'=>bool,'token'=>string,'error'=>?string].
 */
/**
 * Saldo / credito da conta do Google Ads.
 *
 * A API nao tem um campo unico de "saldo disponivel" como a Meta tem `balance`.
 * O que da para ler de forma confiavel no account_budget:
 *   - adjusted_spending_limit_micros: limite EFETIVO do periodo. Ja considera os
 *     creditos/ajustes lancados pelo Google (total_adjustments_micros). E o valor
 *     correto para calcular o que ainda cabe gastar.
 *   - approved_spending_limit_micros: limite apenas aprovado (sem ajustes). Quando
 *     o credito/adjuste aumenta o limite, este fica MENOR que o ajustado, e usar
 *     so ele faz o saldo aparecer zerado ou negativo sem motivo.
 *   - total_adjustments_micros: ajustes (creditos), positivos ou negativos.
 *   - amount_served_micros: quanto ja foi gasto ate agora.
 *
 * Saldo = adjusted + adjustments - served (com fallback para approved quando o
 * ajustado nao vier). NUNCA aplicar max(0, ...) no calculo: saldo negativo e uma
 * informacao real (limite estourado) e o painel precisa mostrar isso, nao esconder
 * atras de um "0".
 *
 * Forma de faturamento (billing_setup) tambem e lida: conta pos-paga nao tem
 * "saldo" no sentido de credito, e o painel precisa dizer isso em vez de mostrar 0.
 *
 * Retorna sempre um array (nunca lanca), com 'ok' => bool.
 */
function ads_roi_google_balance_status(array $studio): array
{
    $settings = studio_settings($studio);
    $oauth = ads_roi_google_oauth_config($studio);

    $cfg = [
        'customer_id' => preg_replace('/\D/', '', (string)($settings['google_ads_customer_id'] ?? '')),
        'client_id' => $oauth['client_id'],
        'client_secret' => $oauth['client_secret'],
        'refresh_token' => trim((string)($settings['google_ads_refresh_token'] ?? '')),
        'login_customer_id' => preg_replace('/\D/', '', (string)($settings['google_ads_login_customer_id'] ?? '')),
        'api_version' => trim((string)($settings['google_ads_api_version'] ?? 'v25')) ?: 'v25',
    ];

    $faltando = [];
    foreach (['customer_id', 'client_id', 'client_secret', 'refresh_token'] as $campo) {
        if (($cfg[$campo] ?? '') === '') {
            $faltando[] = $campo;
        }
    }
    if ($faltando) {
        return ['ok' => false, 'configured' => false, 'error' => 'Google Ads nao conectado.'];
    }

    $tokenRes = ads_roi_google_access_token($cfg);
    if (!$tokenRes['ok']) {
        return ['ok' => false, 'configured' => true, 'error' => (string)($tokenRes['error'] ?? 'Falha ao renovar o token do Google.')];
    }

    $headers = ['Authorization: Bearer ' . (string)$tokenRes['token']];
    if ($cfg['login_customer_id'] !== '') {
        $headers[] = 'login-customer-id: ' . $cfg['login_customer_id'];
    }

    $url = 'https://googleads.googleapis.com/' . rawurlencode($cfg['api_version'])
        . '/customers/' . rawurlencode($cfg['customer_id']) . '/googleAds:search';

    // Orcamento da conta: limite ajustado (efetivo) x ja servido.
    $consultaBudget = 'SELECT customer.id, customer.descriptive_name, customer.currency_code, '
        . 'customer.status, account_budget.approved_spending_limit_micros, '
        . 'account_budget.adjusted_spending_limit_micros, account_budget.total_adjustments_micros, '
        . 'account_budget.amount_served_micros, account_budget.status, '
        . 'account_budget.approved_start_date_time, account_budget.approved_end_date_time '
        . 'FROM account_budget LIMIT 1';

    $res = ads_roi_http_request('POST', $url, $headers, ['query' => $consultaBudget], 'json', 45);
    if (empty($res['ok'])) {
        // Pode ser conta sem account_budget (pos-paga). Tenta so a moeda/status.
        $consultaConta = 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.status '
            . 'FROM customer LIMIT 1';
        $resConta = ads_roi_http_request('POST', $url, $headers, ['query' => $consultaConta], 'json', 45);
        if (empty($resConta['ok'])) {
            return ['ok' => false, 'configured' => true, 'error' => (string)($res['error'] ?? 'Falha ao consultar o Google Ads.')];
        }
        $linha = $resConta['json']['results'][0]['customer'] ?? [];
        return [
            'ok' => true,
            'configured' => true,
            'tem_creidto' => false,
            'balance' => null,
            'currency' => (string)($linha['currencyCode'] ?? 'BRL'),
            'account_name' => (string)($linha['descriptiveName'] ?? 'Conta Google Ads'),
            'account_status' => (string)($linha['status'] ?? ''),
            'observacao' => 'Conta sem orçamento de período (pós-paga): não há saldo pré-pago para exibir.',
        ];
    }

    $linhas = $res['json']['results'] ?? [];
    $linha = $linhas[0] ?? [];
    $conta = $linha['customer'] ?? [];
    $budget = $linha['accountBudget'] ?? [];

    $microAprovado = isset($budget['approvedSpendingLimitMicros']) ? (float)$budget['approvedSpendingLimitMicros'] : null;
    $microAjustado = isset($budget['adjustedSpendingLimitMicros']) ? (float)$budget['adjustedSpendingLimitMicros'] : null;
    $microAjustes = isset($budget['totalAdjustmentsMicros']) ? (float)$budget['totalAdjustmentsMicros'] : null;
    $microServido = isset($budget['amountServedMicros']) ? (float)$budget['amountServedMicros'] : null;

    // Limite efetivo: o ajustado ja embute os ajustes; quando ausente, usa o aprovado.
    $limiteMicro = $microAjustado ?? $microAprovado;

    $limite = $limiteMicro !== null ? $limiteMicro / 1000000 : null;
    $aprovado = $microAprovado !== null ? $microAprovado / 1000000 : null;
    $ajustes = $microAjustes !== null ? $microAjustes / 1000000 : null;
    $servido = $microServido !== null ? $microServido / 1000000 : null;

    // Sem max(0, ...): saldo negativo = limite estourado, e o dono precisa ver isso.
    $restante = ($limite !== null && $servido !== null) ? ($limite - $servido) : null;

    return [
        'ok' => true,
        'configured' => true,
        'tem_creidto' => $restante !== null,
        'balance' => $restante,
        'limite' => $limite,
        'aprovado' => $aprovado,
        'ajustes' => $ajustes,
        'servido' => $servido,
        'currency' => (string)($conta['currencyCode'] ?? 'BRL'),
        'account_name' => (string)($conta['descriptiveName'] ?? 'Conta Google Ads'),
        'account_status' => (string)($conta['status'] ?? ''),
        'budget_status' => (string)($budget['status'] ?? ''),
        'budget_fim' => (string)($budget['approvedEndDateTime'] ?? ''),
        'observacao' => $restante !== null
            ? ('Limite ajustado (' . number_format((float)$limite, 2, ',', '.')
                . ($ajustes !== null && abs((float)$ajustes) >= 0.005 ? ', com ajustes de ' . number_format((float)$ajustes, 2, ',', '.') : '')
                . ') menos o já gasto (' . number_format((float)$servido, 2, ',', '.') . ').')
            : 'A conta nao informou limite de periodo; confira o saldo no painel do Google Ads.',
    ];
}

function ads_roi_google_access_token(array $cfg): array
{
    if (($cfg['client_id'] ?? '') === '' || ($cfg['client_secret'] ?? '') === '' || ($cfg['refresh_token'] ?? '') === '') {
        return ['ok' => false, 'token' => '', 'error' => 'OAuth do Google incompleto (client id, secret ou refresh token).'];
    }
    $res = ads_roi_http_request('POST', 'https://oauth2.googleapis.com/token', [], [
        'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'refresh_token' => $cfg['refresh_token'],
        'grant_type' => 'refresh_token',
    ], 'form');
    if (!$res['ok']) {
        return ['ok' => false, 'token' => '', 'error' => 'Falha ao renovar o token do Google: ' . (string)($res['error'] ?? 'erro desconhecido')];
    }
    $token = (string)($res['json']['access_token'] ?? '');
    if ($token === '') {
        return ['ok' => false, 'token' => '', 'error' => 'O Google nao devolveu access_token.'];
    }
    return ['ok' => true, 'token' => $token, 'error' => null];
}

/**
 * Cliente HTTP simples (curl) usado pelo sync do Google.
 * Suporta corpo JSON e application/x-www-form-urlencoded.
 */
function ads_roi_http_request(string $metodo, string $url, array $headers, array $dados, string $formato = 'json', int $timeout = 60): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Extensao curl do PHP indisponivel.'];
    }
    $ch = curl_init($url);
    $headers[] = 'Accept: application/json';
    $corpo = '';
    if ($metodo !== 'GET' && $dados) {
        if ($formato === 'form') {
            $corpo = http_build_query($dados);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $corpo = json_encode($dados, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($corpo !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
    $resposta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'error' => 'Falha de conexao: ' . $erroCurl, 'status' => $codigo];
    }
    $json = json_decode((string)$resposta, true);
    if ($codigo < 200 || $codigo >= 300) {
        $msg = (string)($json['error']['message'] ?? $json['error_description'] ?? substr((string)$resposta, 0, 300));
        return ['ok' => false, 'error' => 'HTTP ' . $codigo . ': ' . $msg, 'status' => $codigo, 'json' => $json];
    }
    return ['ok' => true, 'json' => is_array($json) ? $json : [], 'status' => $codigo, 'error' => null];
}

/**
 * Orcamento diario REAL das campanhas ativas na conta do Google Ads.
 *
 * Le campaign_budget.amount_micros das campanhas ENABLED. Cada pacote de
 * orcamento entra UMA vez (orcamento compartilhado entre campanhas nao pode
 * contar duas vezes). Existe para o painel de ROI parar de depender do valor
 * fixo que o instalador gravava em ads_channel_config.
 *
 * Retorna sempre array (nunca lanca):
 *   ['ok' => bool, 'daily' => float, 'campanhas' => int, 'error' => ?string]
 */
function ads_roi_google_daily_budget(array $studio): array
{
    $cfg = ads_roi_google_config($studio);

    $faltando = [];
    foreach (['customer_id', 'client_id', 'client_secret', 'refresh_token'] as $campo) {
        if ((string)($cfg[$campo] ?? '') === '') {
            $faltando[] = $campo;
        }
    }
    if ($faltando) {
        return ['ok' => false, 'daily' => 0.0, 'campanhas' => 0,
            'error' => 'Google Ads nao conectado: falta ' . implode(', ', $faltando) . '.'];
    }

    $tokenRes = ads_roi_google_access_token($cfg);
    if (empty($tokenRes['ok'])) {
        return ['ok' => false, 'daily' => 0.0, 'campanhas' => 0,
            'error' => (string)($tokenRes['error'] ?? 'Falha ao renovar o token do Google.')];
    }

    $headers = ['Authorization: Bearer ' . (string)$tokenRes['token']];
    if ($cfg['login_customer_id'] !== '') {
        $headers[] = 'login-customer-id: ' . $cfg['login_customer_id'];
    }
    $url = 'https://googleads.googleapis.com/' . rawurlencode($cfg['api_version'])
        . '/customers/' . rawurlencode($cfg['customer_id']) . '/googleAds:search';

    $consulta = 'SELECT campaign.id, campaign.status, campaign_budget.id, '
        . 'campaign_budget.amount_micros, campaign_budget.period '
        . 'FROM campaign WHERE campaign.status = "ENABLED"';

    $res = ads_roi_http_request('POST', $url, $headers, ['query' => $consulta], 'json', 45);
    if (empty($res['ok'])) {
        return ['ok' => false, 'daily' => 0.0, 'campanhas' => 0,
            'error' => 'Google Ads API: ' . (string)($res['error'] ?? 'erro desconhecido')];
    }

    $vistos = [];
    $total = 0.0;
    foreach ((array)($res['json']['results'] ?? []) as $linha) {
        $orc = $linha['campaignBudget'] ?? [];
        $periodo = (string)($orc['period'] ?? '');
        // Orcamento de periodo personalizado nao e "por dia": fica fora da soma.
        if ($periodo !== '' && $periodo !== 'DAILY') {
            continue;
        }
        $id = (string)($orc['id'] ?? '');
        if ($id === '' || isset($vistos[$id])) {
            continue;
        }
        $vistos[$id] = true;
        $total += ((float)($orc['amountMicros'] ?? 0)) / 1000000;
    }

    return ['ok' => true, 'daily' => round($total, 2), 'campanhas' => count($vistos), 'error' => null];
}

/**
 * Le o orcamento real da conta e grava em ads_channel_config (canal google),
 * que e de onde o painel tira a projecao mensal. Se a API falhar ou vier zero,
 * mantem o valor que ja estava gravado — o sync de gasto nunca depende disso.
 *
 * Retorna o mesmo array de ads_roi_google_daily_budget, com 'salvo' => bool.
 */
function ads_roi_google_budget_sync(array $studio): array
{
    $orc = ads_roi_google_daily_budget($studio);
    $orc['salvo'] = false;
    if (empty($orc['ok']) || (float)$orc['daily'] <= 0) {
        return $orc;
    }

    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);
    $pdo->prepare('INSERT INTO ads_channel_config (channel, daily_budget) VALUES ("google", ?) '
        . 'ON DUPLICATE KEY UPDATE daily_budget = VALUES(daily_budget)')
        ->execute([(float)$orc['daily']]);
    $orc['salvo'] = true;
    return $orc;
}

/**
 * Sincroniza o gasto diario REAL do Google Ads na tabela ads_daily.
 * Usa a Google Ads API (REST) com OAuth 2.0: renova o access token pelo refresh token
 * e consulta a metrica metrics.cost_micros por dia (segmented by segments.date).
 * Grava com campaign_name = '[SYNC GOOGLE]'. Contrato igual ao sync Meta.
 *
 * Observacao: enquanto a chave de desenvolvedor estiver em nivel de teste, a API
 * aceita consultas apenas em contas de teste; com Explorer/Basic Access funciona
 * na conta de producao.
 */
function ads_roi_sync_google(array $studio, int $days = 30): array
{
    $settings = studio_settings($studio);
    $oauth = ads_roi_google_oauth_config($studio);
    $googleCfg = [
        // O developer token foi descontinuado em 09/09/2026 e passou a ser ignorado
        // pela API; o acesso agora vem do projeto do Google Cloud dono do OAuth.
        'customer_id' => preg_replace('/\D/', '', (string)($settings['google_ads_customer_id'] ?? '')),
        'client_id' => $oauth['client_id'],
        'client_secret' => $oauth['client_secret'],
        'refresh_token' => trim((string)($settings['google_ads_refresh_token'] ?? '')),
        'login_customer_id' => preg_replace('/\D/', '', (string)($settings['google_ads_login_customer_id'] ?? '')),
        'api_version' => trim((string)($settings['google_ads_api_version'] ?? 'v25')) ?: 'v25',
    ];
    $faltando = [];
    foreach (['customer_id', 'client_id', 'client_secret', 'refresh_token'] as $campo) {
        if ((string)$googleCfg[$campo] === '') {
            $faltando[] = $campo;
        }
    }
    if ($faltando) {
        return ['ok' => false, 'error' => 'Google Ads ainda nao configurado: falta ' . implode(', ', $faltando) . '. Use "Conectar Google Ads" no painel.', 'imported' => 0, 'days' => $days, 'needs_google_credentials' => true];
    }

    $tokenRes = ads_roi_google_access_token($googleCfg);
    if (!$tokenRes['ok']) {
        return ['ok' => false, 'error' => (string)$tokenRes['error'], 'imported' => 0, 'days' => $days];
    }

    $days = max(1, min(365, $days));
    $ate = date('Y-m-d');
    $de = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    $consulta = 'SELECT segments.date, metrics.cost_micros FROM customer '
        . 'WHERE segments.date BETWEEN "' . $de . '" AND "' . $ate . '" '
        . 'AND metrics.cost_micros > 0';

    $headers = [
        'Authorization: Bearer ' . (string)$tokenRes['token'],
    ];
    if ($googleCfg['login_customer_id'] !== '') {
        $headers[] = 'login-customer-id: ' . $googleCfg['login_customer_id'];
    }

    $url = 'https://googleads.googleapis.com/' . rawurlencode($googleCfg['api_version'])
        . '/customers/' . rawurlencode($googleCfg['customer_id']) . '/googleAds:search';

    $res = ads_roi_http_request('POST', $url, $headers, ['query' => $consulta]);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => 'Google Ads API: ' . (string)($res['error'] ?? 'erro desconhecido'), 'imported' => 0, 'days' => $days];
    }

    // Soma cost_micros (1 unidade = 1/1.000.000 da moeda) por dia.
    $porDia = [];
    foreach ((array)($res['json']['results'] ?? []) as $linha) {
        $data = (string)($linha['segments']['date'] ?? '');
        $micros = (float)($linha['metrics']['costMicros'] ?? 0);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) !== 1 || $micros <= 0) {
            continue;
        }
        $porDia[$data] = ($porDia[$data] ?? 0.0) + ($micros / 1000000);
    }

    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO ads_daily (campaign_date, channel, campaign_name, spend, notes)
         VALUES (?, "google", "[SYNC GOOGLE]", ?, NULL)
         ON DUPLICATE KEY UPDATE spend = VALUES(spend)'
    );
    // Grava TODO o intervalo sincronizado, inclusive os dias sem gasto (0,00).
    // Assim a API vira a fonte da verdade para esses dias e o lançamento manual
    // antigo não volta a inflar o painel quando o gasto real do dia é zero.
    $imported = 0;
    $cursor = new DateTime($de);
    $limite = new DateTime($ate);
    while ($cursor <= $limite) {
        $data = $cursor->format('Y-m-d');
        $stmt->execute([$data, round((float)($porDia[$data] ?? 0.0), 2)]);
        $imported++;
        $cursor->modify('+1 day');
    }
    // Quebra por campanha (tabela separada: o painel soma so a linha [SYNC ...] de
    // ads_daily) e leads do canal. Best effort: nunca derruba o sync de gasto.
    $campanhas = null;
    try {
        $campanhas = ads_roi_google_campaigns_sync($studio, $days, $headers, $url);
    } catch (Throwable $e) {
        $campanhas = null;
    }
    try {
        ads_roi_leads_direct_sync($pdo, 'google', $de, $ate);
    } catch (Throwable $e) {
        // segue
    }
    // Aproveita o mesmo access token para descobrir de onde DENTRO do Google
    // veio cada clique (YouTube, Pesquisa, Display...). Nunca derruba o sync.
    $cliquesResolvidos = 0;
    try {
        $cliques = ads_roi_google_resolver_cliques($studio, 200, 10, $headers, $url);
        $cliquesResolvidos = (int)($cliques['resolvidos'] ?? 0);
    } catch (Throwable $e) {
        $cliquesResolvidos = 0;
    }

    // Saude do rastreio: confere o link final da campanha e se algum criativo
    // ficou reprovado. Best effort: nunca derruba o sync de gasto.
    $saude = null;
    try {
        $saude = ads_roi_google_saude_check($studio, $headers, $url);
    } catch (Throwable $e) {
        $saude = null;
    }

    // Orcamento real das campanhas ativas da conta: o painel de ROI para de
    // depender do valor fixo gravado na instalacao. Best effort: nunca derruba
    // o sync de gasto se a leitura do orcamento falhar.
    $orcamento = null;
    try {
        $orcamento = ads_roi_google_budget_sync($studio);
    } catch (Throwable $e) {
        $orcamento = null;
    }

    return ['ok' => true, 'imported' => $imported, 'days' => $days, 'from' => $de, 'to' => $ate,
        'cliques_resolvidos' => $cliquesResolvidos, 'saude' => $saude, 'orcamento' => $orcamento,
        'campanhas' => $campanhas];
}


/**
 * Configuracao do Google Ads (OAuth + customer) montada a partir do estudio.
 * Usada pelos recursos que falam com a API fora do sync de gasto.
 */
function ads_roi_google_config(array $studio): array
{
    $settings = studio_settings($studio);
    $oauth = ads_roi_google_oauth_config($studio);
    return [
        'customer_id' => preg_replace('/\D/', '', (string)($settings['google_ads_customer_id'] ?? '')),
        'client_id' => (string)($oauth['client_id'] ?? ''),
        'client_secret' => (string)($oauth['client_secret'] ?? ''),
        'refresh_token' => trim((string)($settings['google_ads_refresh_token'] ?? '')),
        'login_customer_id' => preg_replace('/\D/', '', (string)($settings['google_ads_login_customer_id'] ?? '')),
        'api_version' => trim((string)($settings['google_ads_api_version'] ?? 'v25')) ?: 'v25',
    ];
}

/**
 * Cache da origem do clique pago do Google. Uma linha por gclid.
 * estado = ok (a API achou) | nao_encontrado (a API respondeu e nao achou).
 * Criada apenas se nao existir: nunca altera estrutura ja existente.
 */
function ads_roi_click_info_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS ads_click_info (
        gclid VARCHAR(255) NOT NULL PRIMARY KEY,
        click_date DATE NULL,
        ad_network_type VARCHAR(32) NULL,
        campaign_id VARCHAR(32) NULL,
        campaign_name VARCHAR(180) NULL,
        estado VARCHAR(16) NOT NULL DEFAULT "ok",
        tentado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rede (ad_network_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Descobre de onde DENTRO do Google veio cada clique pago (YouTube, Pesquisa,
 * Display, Discover...) consultando o recurso click_view pelo gclid.
 *
 * A API so aceita UM dia por consulta, entao os gclids pendentes (o gclid nasce
 * no ir.php, em ads_src_hits) sao agrupados pelo dia do hit e resolvidos em
 * lote. O resultado fica em ads_click_info e nao e consultado de novo.
 *
 * Best effort: falha de rede/API nao lanca; devolve o motivo em 'erro'.
 * $headers/$url permitem reaproveitar o token do sync (evita renovar 2x).
 *
 * Devolve ['ok'=>bool,'resolvidos'=>int,'dias'=>int,'erro'=>?string].
 */
function ads_roi_google_resolver_cliques(
    array $studio,
    int $maxGclids = 60,
    int $maxDias = 5,
    ?array $headers = null,
    ?string $url = null,
    int $timeout = 45
): array {
    try {
        $pdo = studio_db($studio);
    } catch (Throwable $e) {
        return ['ok' => false, 'resolvidos' => 0, 'dias' => 0, 'erro' => 'Estudio indisponivel.'];
    }

    try {
        ads_roi_click_info_ensure_schema($pdo);
        $limite = max(1, min(500, $maxGclids));
        $stmt = $pdo->query(
            'SELECT h.gclid, DATE(MIN(h.created_at)) AS dia
               FROM ads_src_hits h
               LEFT JOIN ads_click_info c ON c.gclid = h.gclid
              WHERE h.gclid IS NOT NULL AND h.gclid <> ""
                AND h.src = "google"
                AND (c.gclid IS NULL
                     OR (c.estado <> "ok" AND c.tentado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)))
              GROUP BY h.gclid
              ORDER BY dia ASC, h.gclid ASC
              LIMIT ' . $limite
        );
        $pendentes = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return ['ok' => false, 'resolvidos' => 0, 'dias' => 0, 'erro' => 'Falha ao listar os cliques pendentes.'];
    }

    if (!$pendentes) {
        return ['ok' => true, 'resolvidos' => 0, 'dias' => 0, 'erro' => null];
    }

    // Agrupa por dia: o click_view exige filtro de um dia so.
    $porDia = [];
    foreach ($pendentes as $p) {
        $dia = (string)($p['dia'] ?? '');
        $g = trim((string)($p['gclid'] ?? ''));
        if ($g === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) !== 1) {
            continue;
        }
        $porDia[$dia][] = $g;
    }
    if (!$porDia) {
        return ['ok' => true, 'resolvidos' => 0, 'dias' => 0, 'erro' => null];
    }

    if ($headers === null || $url === null) {
        $cfg = ads_roi_google_config($studio);
        $faltando = [];
        foreach (['customer_id', 'client_id', 'client_secret', 'refresh_token'] as $campo) {
            if ((string)($cfg[$campo] ?? '') === '') {
                $faltando[] = $campo;
            }
        }
        if ($faltando) {
            return ['ok' => false, 'resolvidos' => 0, 'dias' => 0, 'erro' => 'Google Ads ainda nao configurado.'];
        }
        $tokenRes = ads_roi_google_access_token($cfg);
        if (empty($tokenRes['ok'])) {
            return ['ok' => false, 'resolvidos' => 0, 'dias' => 0, 'erro' => (string)($tokenRes['error'] ?? 'Falha ao renovar o token do Google.')];
        }
        $headers = ['Authorization: Bearer ' . (string)$tokenRes['token']];
        if ($cfg['login_customer_id'] !== '') {
            $headers[] = 'login-customer-id: ' . $cfg['login_customer_id'];
        }
        $url = 'https://googleads.googleapis.com/' . rawurlencode($cfg['api_version'])
            . '/customers/' . rawurlencode($cfg['customer_id']) . '/googleAds:search';
    }

    $achados = [];     // gclid => ['rede'=>, 'campaign_id'=>, 'campaign_name'=>, 'dia'=>]
    $respondidos = []; // dia => true: so esses podem virar "nao_encontrado"
    $diasFeitos = 0;
    foreach ($porDia as $dia => $gclids) {
        if ($diasFeitos >= max(1, $maxDias)) {
            break;
        }
        $diasFeitos++;
        $lista = [];
        foreach ($gclids as $g) {
            $lista[] = "'" . str_replace("'", '', $g) . "'";
        }
        // O click_view so aceita um dia por consulta, e o clique pode ter
        // acontecido no dia anterior (virada da meia-noite): tenta os dois.
        $respondido = false;
        foreach ([$dia, date('Y-m-d', strtotime($dia . ' -1 day'))] as $diaConsulta) {
            $consulta = 'SELECT click_view.gclid, segments.ad_network_type, campaign.id, campaign.name FROM click_view '
                . 'WHERE segments.date = "' . $diaConsulta . '" AND click_view.gclid IN (' . implode(',', $lista) . ')';
            $res = ads_roi_http_request('POST', (string)$url, (array)$headers, ['query' => $consulta], 'json', max(5, $timeout));
            if (empty($res['ok'])) {
                continue; // erro de API: tenta de novo na proxima rodada, nao marca como perdido
            }
            $respondido = true;
            foreach ((array)($res['json']['results'] ?? []) as $linha) {
                $g = (string)($linha['clickView']['gclid'] ?? '');
                if ($g === '') {
                    continue;
                }
                $achados[$g] = [
                    'rede' => (string)($linha['segments']['adNetworkType'] ?? ''),
                    'campaign_id' => (string)($linha['campaign']['id'] ?? ''),
                    'campaign_name' => (string)($linha['campaign']['name'] ?? ''),
                    'dia' => $dia,
                ];
            }
            // Achou todos os cliques deste dia: nao precisa olhar o dia anterior.
            $faltando = false;
            foreach ($gclids as $g) {
                if (!isset($achados[$g])) {
                    $faltando = true;
                    break;
                }
            }
            if (!$faltando) {
                break;
            }
        }
        if ($respondido) {
            $respondidos[$dia] = true;
        }
    }

    $resolvidos = 0;
    try {
        $insOk = $pdo->prepare('INSERT INTO ads_click_info (gclid, click_date, ad_network_type, campaign_id, campaign_name, estado, tentado_em)
            VALUES (?, ?, ?, ?, ?, "ok", NOW())
            ON DUPLICATE KEY UPDATE click_date = VALUES(click_date), ad_network_type = VALUES(ad_network_type),
                campaign_id = VALUES(campaign_id), campaign_name = VALUES(campaign_name), estado = "ok", tentado_em = NOW()');
        $insNao = $pdo->prepare('INSERT INTO ads_click_info (gclid, click_date, ad_network_type, campaign_id, campaign_name, estado, tentado_em)
            VALUES (?, ?, NULL, NULL, NULL, "nao_encontrado", NOW())
            ON DUPLICATE KEY UPDATE estado = IF(estado = "ok", estado, "nao_encontrado"), tentado_em = NOW()');
        foreach ($porDia as $dia => $gclids) {
            if (!isset($respondidos[$dia])) {
                continue;
            }
            foreach ($gclids as $g) {
                if (isset($achados[$g])) {
                    $a = $achados[$g];
                    $insOk->execute([$g, $a['dia'], $a['rede'], $a['campaign_id'], $a['campaign_name']]);
                    $resolvidos++;
                } else {
                    $insNao->execute([$g, $dia]);
                }
            }
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'resolvidos' => $resolvidos, 'dias' => $diasFeitos, 'erro' => 'Falha ao gravar a origem dos cliques.'];
    }

    return ['ok' => true, 'resolvidos' => $resolvidos, 'dias' => $diasFeitos, 'erro' => null];
}

/**
 * SAUDE DO RASTREIO DO GOOGLE (checagem diaria)
 *
 * Confere na propria API o que faz o dinheiro voltar ou se perder:
 *   1) a campanha ativa manda o clique para o link de rastreio (ir.php)?
 *   2) algum criativo (asset) do grupo esta REPROVADO pelo Google?
 *   3) o grupo de recursos esta elegivel (NOT_ELIGIBLE derruba a entrega)?
 *
 * O resultado vira uma linha em ads_google_saude e o painel mostra o aviso
 * quando a ultima checagem achou problema. Nao altera nada na conta.
 */
function ads_roi_google_saude_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS ads_google_saude (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        checado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ok TINYINT(1) NOT NULL DEFAULT 0,
        campanha VARCHAR(160) NULL,
        campanha_status VARCHAR(32) NULL,
        grupo VARCHAR(160) NULL,
        grupo_primary VARCHAR(32) NULL,
        url_final VARCHAR(500) NULL,
        reprovados INT NOT NULL DEFAULT 0,
        motivos VARCHAR(255) NULL,
        erro VARCHAR(255) NULL,
        KEY idx_data (checado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function ads_roi_google_saude_check(array $studio, array $headers = [], string $url = ''): array
{
    $cfg = ads_roi_google_config($studio);
    if (!$headers) {
        $tok = ads_roi_google_access_token($cfg);
        if (empty($tok['ok'])) {
            return ['ok' => false, 'erro' => (string)($tok['error'] ?? 'token indisponivel')];
        }
        $headers = ['Authorization: Bearer ' . (string)$tok['token']];
        if ($cfg['login_customer_id'] !== '') {
            $headers[] = 'login-customer-id: ' . $cfg['login_customer_id'];
        }
    }
    if ($url === '') {
        $url = 'https://googleads.googleapis.com/' . rawurlencode($cfg['api_version'])
            . '/customers/' . rawurlencode($cfg['customer_id']) . '/googleAds:search';
    }

    $saude = [
        'ok' => false, 'campanha' => '', 'campanha_status' => '', 'grupo' => '',
        'grupo_primary' => '', 'url_final' => '', 'reprovados' => 0, 'motivos' => '', 'erro' => null,
    ];
    $problemas = [];

    $res = ads_roi_http_request('POST', $url, $headers, ['query' =>
        'SELECT campaign.name, campaign.status, campaign.primary_status FROM campaign WHERE campaign.status = "ENABLED"']);
    if (empty($res['ok'])) {
        $saude['erro'] = 'consulta de campanha: ' . (string)($res['error'] ?? '?');
    } else {
        $linhas = (array)($res['json']['results'] ?? []);
        if (!$linhas) {
            $problemas[] = 'nenhuma campanha ativa';
        }
        foreach ($linhas as $l) {
            $saude['campanha'] = (string)($l['campaign']['name'] ?? '');
            $saude['campanha_status'] = (string)($l['campaign']['status'] ?? '');
            if (($l['campaign']['primaryStatus'] ?? '') === 'NOT_ELIGIBLE') {
                $problemas[] = 'campanha nao elegivel';
            }
            break;
        }
    }

    $res = ads_roi_http_request('POST', $url, $headers, ['query' =>
        'SELECT asset_group.name, asset_group.primary_status, asset_group.primary_status_reasons, asset_group.final_urls FROM asset_group WHERE asset_group.status = "ENABLED" AND campaign.status = "ENABLED"']);
    if (empty($res['ok'])) {
        $saude['erro'] = trim((string)$saude['erro'] . ' | grupo: ' . (string)($res['error'] ?? '?'), ' |');
    } else {
        $linhas = (array)($res['json']['results'] ?? []);
        foreach ($linhas as $l) {
            $nome = (string)($l['assetGroup']['name'] ?? '');
            $primary = (string)($l['assetGroup']['primaryStatus'] ?? '');
            $urls = (array)($l['assetGroup']['finalUrls'] ?? []);
            if ($saude['grupo'] === '') {
                $saude['grupo'] = $nome;
                $saude['grupo_primary'] = $primary;
                $saude['url_final'] = (string)($urls[0] ?? '');
            }
            // O destino tem que ser uma pagina real do site: o ir.php (que
            // redireciona para o wa.me, outro dominio) foi reprovado pelo
            // Google como "Destino nao correspondente" e derrubou a entrega.
            // O rastreio continua porque a home le o gclid e manda os botoes
            // de WhatsApp pelo ir.php com a marca de origem.
            $destinoOk = false;
            foreach ($urls as $u) {
                $u = (string)$u;
                if (preg_match('#^https?://(www\.)?danieltatuador\.com(/|$)#i', $u) === 1
                    && stripos($u, 'ir.php') === false) {
                    $destinoOk = true;
                    break;
                }
            }
            if (!$destinoOk) {
                $problemas[] = 'grupo "' . $nome . '" com destino fora do site (o Google reprova como destino nao correspondente)';
            }
            if ($primary === 'NOT_ELIGIBLE') {
                $problemas[] = 'grupo "' . $nome . '" nao elegivel';
            }
        }
    }

    $res = ads_roi_http_request('POST', $url, $headers, ['query' =>
        'SELECT asset_group_asset.asset FROM asset_group_asset WHERE asset_group_asset.status = "ENABLED" AND campaign.status = "ENABLED" AND asset_group_asset.policy_summary.approval_status = "DISAPPROVED"']);
    if (empty($res['ok'])) {
        $saude['erro'] = trim((string)$saude['erro'] . ' | criativos: ' . (string)($res['error'] ?? '?'), ' |');
    } else {
        $reprovados = count((array)($res['json']['results'] ?? []));
        $saude['reprovados'] = $reprovados;
        if ($reprovados > 0) {
            $problemas[] = $reprovados . ' criativo(s) reprovado(s)';
        }
    }

    // Anuncio de Pesquisa reprovado tambem derruba entrega. A checagem antiga
    // olhava so asset_group (PMax) e acusava PMax pausada como problema.
    $res = ads_roi_http_request('POST', $url, $headers, ['query' =>
        'SELECT ad_group_ad.ad.id FROM ad_group_ad
         WHERE campaign.status = "ENABLED" AND ad_group_ad.status = "ENABLED"
           AND ad_group_ad.policy_summary.approval_status = "DISAPPROVED"']);
    if (empty($res['ok'])) {
        $saude['erro'] = trim((string)$saude['erro'] . ' | anuncios: ' . (string)($res['error'] ?? '?'), ' |');
    } else {
        $reprovadosAds = count((array)($res['json']['results'] ?? []));
        if ($reprovadosAds > 0) {
            $saude['reprovados'] += $reprovadosAds;
            $problemas[] = $reprovadosAds . ' anuncio(s) de pesquisa reprovado(s)';
        }
    }

    // Recurso reprovado na biblioteca E ainda vinculado a anuncio/grupo.
    // Foi assim que um sitelink com link final para uma busca do Google
    // derrubou a campanha sem aparecer na checagem do grupo de recursos.
    $res = ads_roi_http_request('POST', $url, $headers, ['query' =>
        'SELECT asset.id, asset.type FROM asset WHERE asset.policy_summary.approval_status = "DISAPPROVED"']);
    if (empty($res['ok'])) {
        $saude['erro'] = trim((string)$saude['erro'] . ' | biblioteca: ' . (string)($res['error'] ?? '?'), ' |');
    } else {
        $reprovadosBiblioteca = [];
        foreach ((array)($res['json']['results'] ?? []) as $l) {
            $idAsset = (string)($l['asset']['id'] ?? '');
            if ($idAsset !== '') {
                $reprovadosBiblioteca[$idAsset] = (string)($l['asset']['type'] ?? '?');
            }
        }
        if ($reprovadosBiblioteca) {
            $emUso = [];
            $resUso = ads_roi_http_request('POST', $url, $headers, ['query' =>
                'SELECT campaign_asset.asset FROM campaign_asset WHERE campaign_asset.status = "ENABLED"']);
            foreach ((array)($resUso['json']['results'] ?? []) as $l) {
                $nomeAsset = (string)($l['campaignAsset']['asset'] ?? '');
                if (preg_match('#/assets/(\d+)$#', $nomeAsset, $m) === 1) {
                    $emUso[$m[1]] = true;
                }
            }
            foreach (array_keys($reprovadosBiblioteca) as $idAsset) {
                if (!isset($emUso[$idAsset])) {
                    unset($reprovadosBiblioteca[$idAsset]);
                }
            }
        }
        if ($reprovadosBiblioteca) {
            $saude['reprovados'] = (int)$saude['reprovados'] + count($reprovadosBiblioteca);
            $problemas[] = count($reprovadosBiblioteca) . ' recurso(s) reprovado(s) em uso ('
                . implode(', ', array_unique(array_values($reprovadosBiblioteca))) . ')';
        }
    }

    $saude['motivos'] = implode('; ', $problemas);
    $saude['ok'] = $problemas === [] && $saude['erro'] === null;

    try {
        $pdo = studio_db($studio);
        ads_roi_google_saude_ensure_schema($pdo);
        $pdo->prepare('INSERT INTO ads_google_saude
            (ok, campanha, campanha_status, grupo, grupo_primary, url_final, reprovados, motivos, erro)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $saude['ok'] ? 1 : 0,
                $saude['campanha'] !== '' ? mb_substr($saude['campanha'], 0, 160) : null,
                $saude['campanha_status'] !== '' ? mb_substr($saude['campanha_status'], 0, 32) : null,
                $saude['grupo'] !== '' ? mb_substr($saude['grupo'], 0, 160) : null,
                $saude['grupo_primary'] !== '' ? mb_substr($saude['grupo_primary'], 0, 32) : null,
                $saude['url_final'] !== '' ? mb_substr($saude['url_final'], 0, 500) : null,
                (int)$saude['reprovados'],
                $saude['motivos'] !== '' ? mb_substr($saude['motivos'], 0, 255) : null,
                $saude['erro'] !== null ? mb_substr((string)$saude['erro'], 0, 255) : null,
            ]);
    } catch (Throwable $e) {
        $saude['erro'] = trim((string)$saude['erro'] . ' | gravar: ' . $e->getMessage(), ' |');
    }

    return $saude;
}

/** Ultima checagem de saude do Google (para o aviso no painel). */
function ads_roi_google_saude_ultima(PDO $pdo): ?array
{
    try {
        ads_roi_google_saude_ensure_schema($pdo);
        $linha = $pdo->query('SELECT * FROM ads_google_saude ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $linha ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Credenciais do TikTok Ads. Ficam em config/tiktok_ads.local.json
 * (arquivo ignorado pelo Git) para nao misturar token com o codigo.
 */
function ads_roi_tiktok_config(): array
{
    $arquivo = APP_BASE_PATH . '/config/tiktok_ads.local.json';
    if (!is_file($arquivo)) {
        return [];
    }
    $cfg = json_decode((string)file_get_contents($arquivo), true);
    return is_array($cfg) ? $cfg : [];
}

/** Chamada autenticada a Marketing API do TikTok. */
function ads_roi_tiktok_request(array $cfg, string $path, array $params): array
{
    $token = trim((string)($cfg['access_token'] ?? ''));
    $version = trim((string)($cfg['api_version'] ?? 'v1.3')) ?: 'v1.3';
    if ($token === '') {
        return ['ok' => false, 'error' => 'TikTok Ads sem access_token.'];
    }
    $url = 'https://business-api.tiktok.com/open_api/' . rawurlencode($version) . '/' . ltrim($path, '/')
        . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Access-Token: ' . $token, 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'TikTok Ads API indisponivel.'];
    }
    if ($http >= 400) {
        return ['ok' => false, 'error' => 'TikTok Ads API HTTP ' . $http . ': ' . substr((string)$raw, 0, 200)];
    }
    $json = json_decode((string)$raw, true);
    if (!is_array($json) || (int)($json['code'] ?? -1) !== 0) {
        return ['ok' => false, 'error' => 'TikTok Ads API: ' . (string)($json['message'] ?? 'resposta invalida')];
    }
    return ['ok' => true, 'json' => $json];
}

/** Quebra o gasto do TikTok por campanha/dia em ads_campaign_daily. */
function ads_roi_tiktok_campaigns_sync(array $studio, array $cfg, int $days = 30): array
{
    $advertiserId = preg_replace('/\D+/', '', (string)($cfg['advertiser_id'] ?? ''));
    if ($advertiserId === '') {
        return ['ok' => false, 'imported' => 0, 'error' => 'TikTok Ads sem advertiser_id.'];
    }
    $days = max(1, min(365, $days));
    $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $end = date('Y-m-d');
    $res = ads_roi_tiktok_request($cfg, 'report/integrated/get/', [
        'advertiser_id' => $advertiserId,
        'report_type' => 'BASIC',
        'data_level' => 'AUCTION_CAMPAIGN',
        'dimensions' => json_encode(['campaign_id', 'stat_time_day']),
        'metrics' => json_encode(['campaign_name', 'spend']),
        'start_date' => $start,
        'end_date' => $end,
        'page_size' => 100,
    ]);
    if (empty($res['ok'])) {
        return ['ok' => false, 'imported' => 0, 'error' => (string)($res['error'] ?? 'Falha no relatorio por campanha.')];
    }

    try {
        $pdo = studio_db($studio);
        studio_ads_daily_ensure_schema($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO ads_campaign_daily (campaign_date, channel, campaign_id, campaign_name, spend)
             VALUES (?, "tiktok", ?, ?, ?)
             ON DUPLICATE KEY UPDATE campaign_name = VALUES(campaign_name), spend = VALUES(spend)'
        );
        $imported = 0;
        foreach (($res['json']['data']['list'] ?? []) as $row) {
            $date = substr((string)($row['dimensions']['stat_time_day'] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            $cid = trim((string)($row['dimensions']['campaign_id'] ?? ''));
            $cname = trim((string)($row['metrics']['campaign_name'] ?? ''));
            if ($cid === '' && $cname === '') {
                continue;
            }
            $stmt->execute([
                $date,
                $cid,
                $cname !== '' ? $cname : ('campanha ' . $cid),
                round((float)($row['metrics']['spend'] ?? 0), 2),
            ]);
            $imported++;
        }
        return ['ok' => true, 'imported' => $imported, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'imported' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * TikTok Ads: gasto diario real por anunciante.
 *
 * Usa o endpoint oficial de relatorios da Marketing API. Enquanto o Ads Manager
 * nao estiver criado e o token/advertiser_id nao forem preenchidos, devolve
 * needs_tiktok_credentials e o restante do sync continua normalmente.
 */
function ads_roi_sync_tiktok(array $studio, int $days = 7): array
{
    $cfg = ads_roi_tiktok_config();
    $token = trim((string)($cfg['access_token'] ?? ''));
    $advertiserId = preg_replace('/\D+/', '', (string)($cfg['advertiser_id'] ?? ''));
    if ($token === '' || $advertiserId === '') {
        return [
            'ok' => false,
            'needs_tiktok_credentials' => true,
            'error' => 'TikTok Ads ainda nao configurado: preencha access_token e advertiser_id em config/tiktok_ads.local.json.',
        ];
    }

    $dias = max(1, min(365, $days));
    $start = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
    $end = date('Y-m-d');
    $params = [
        'advertiser_id' => $advertiserId,
        'report_type' => 'BASIC',
        'data_level' => 'AUCTION_ADVERTISER',
        'dimensions' => json_encode(['stat_time_day']),
        'metrics' => json_encode(['spend']),
        'start_date' => $start,
        'end_date' => $end,
        'page_size' => 100,
    ];
    $res = ads_roi_tiktok_request($cfg, 'report/integrated/get/', $params);
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => (string)($res['error'] ?? 'Falha ao consultar TikTok Ads.')];
    }

    $pdo = studio_db($studio);
    $imported = 0;
    $spendPorDia = [];
    foreach (($res['json']['data']['list'] ?? []) as $row) {
        $date = substr((string)($row['dimensions']['stat_time_day'] ?? ''), 0, 10);
        $spend = (float)($row['metrics']['spend'] ?? 0);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            continue;
        }
        $spendPorDia[$date] = $spend;
    }
    // Grava o intervalo inteiro (inclusive zero) para a API ser a fonte da verdade.
    $cursor = new DateTime($start);
    $limite = new DateTime($end);
    while ($cursor <= $limite) {
        $date = $cursor->format('Y-m-d');
        ads_roi_write_spend($pdo, $date, 'tiktok', (float)($spendPorDia[$date] ?? 0), 'SYNC TIKTOK');
        $imported++;
        $cursor->modify('+1 day');
    }
    $campanhas = null;
    try {
        $campanhas = ads_roi_tiktok_campaigns_sync($studio, $cfg, $dias);
    } catch (Throwable $e) {
        $campanhas = null;
    }
    try {
        ads_roi_leads_direct_sync($pdo, 'tiktok', $start, $end);
    } catch (Throwable $e) {
        // segue
    }
    return ['ok' => true, 'imported' => $imported, 'days' => $dias, 'campanhas' => $campanhas];
}
