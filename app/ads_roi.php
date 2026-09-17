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
        $raw[$d][$ch]['sync_spend'] = ($raw[$d][$ch]['sync_spend'] ?? 0.0) + ($isSync ? (float)$row['spend'] : 0.0);
        $raw[$d][$ch]['manual_spend'] = ($raw[$d][$ch]['manual_spend'] ?? 0.0) + ($isSync ? 0.0 : (float)$row['spend']);
        $raw[$d][$ch]['leads'] = ($raw[$d][$ch]['leads'] ?? 0) + (int)$row['leads_direct'];
    }

    $out = [];
    foreach ($raw as $d => $channels) {
        foreach ($channels as $ch => $agg) {
            $hasSync = ($agg['sync_spend'] ?? 0.0) > 0;
            $out[$d][$ch] = [
                // SYNC ganha quando existe; senão cai no manual.
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
 * Gasto por dia x agendamento real (por data). Fonte de agenda: appointments.appointment_date.
 * Considera só tatuagem com valor (>0) e ignora cobranças/tarefas.
 */
function ads_roi_daily_series(PDO $pdo, string $start, string $end): array
{
    studio_ads_daily_ensure_schema($pdo);

    // Usa a matriz com precedência SYNC > manual (evita somar o mesmo gasto 2x/3x).
    $spendByDay = ads_roi_spend_matrix($pdo, $start, $end);

    // Só conta como AGENDAMENTO a linha que tem valor cadastrado (> 0). Compromissos da
    // agenda sem valor (limpeza, reunião, bloqueio de horário) e agendamentos cancelados
    // sem valor não entram na conta - senão o CPA e o ROAS saem artificialmente bons.
    // Cancelados COM valor continuam contando (o serviço foi vendido) e aparecem no subtítulo.
    $apptSql = 'SELECT appointment_date,
                       SUM(CASE WHEN value > 0 THEN 1 ELSE 0 END) AS agendamentos,
                       SUM(CASE WHEN value > 0 AND LOWER(status) IN ("cancelado","canceled","cancelada") THEN 1 ELSE 0 END) AS cancelados,
                       SUM(CASE WHEN LOWER(status) IN ("cancelado","canceled","cancelada") THEN 1 ELSE 0 END) AS cancelados_total,
                       SUM(CASE WHEN value > 0 THEN value ELSE 0 END) AS valor_total
                FROM appointments
                WHERE appointment_date BETWEEN ? AND ?
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
        $appt = $apptByDay[$d] ?? ['agendamentos' => 0, 'cancelados' => 0, 'cancelados_total' => 0, 'valor_total' => 0];
        $totalSpend = $metaSpend + $googleSpend;
        $ag = (int)$appt['agendamentos'];
        $days[] = [
            'date' => $d,
            'meta_spend' => $metaSpend,
            'google_spend' => $googleSpend,
            'spend_total' => $totalSpend,
            'agendamentos' => $ag,
            'cancelados' => (int)$appt['cancelados'],
            'cancelados_total' => (int)($appt['cancelados_total'] ?? 0),
            'valor_agendado' => (float)$appt['valor_total'],
            'custo_por_agendamento' => $ag > 0 ? round($totalSpend / $ag, 2) : null,
            'roas' => $totalSpend > 0 ? round(((float)$appt['valor_total']) / $totalSpend, 2) : null,
        ];
        $cursor->modify('+1 day');
    }
    return $days;
}

/**
 * Resumo consolidado do período + comparação Meta x Google.
 */
function ads_roi_summary(PDO $pdo, string $start, string $end): array
{
    $series = ads_roi_daily_series($pdo, $start, $end);
    $spend = ads_roi_spend_by_channel($pdo, $start, $end);

    $totMeta = (float)($spend['meta']['spend'] ?? 0);
    $totGoogle = (float)($spend['google']['spend'] ?? 0);
    $totSpend = $totMeta + $totGoogle;

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
    foreach (($response['json']['data'] ?? []) as $row) {
        $date = (string)($row['date_start'] ?? '');
        $spend = (float)($row['spend'] ?? 0);
        if ($date === '' || $spend <= 0) { continue; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { continue; }
        $stmt->execute([$date, $spend]);
        $imported++;
    }
    return ['ok' => true, 'imported' => $imported, 'days' => $days];
}

/**
 * Sincroniza o gasto diário REAL do Google Ads na tabela ads_daily.
 * Requer credencial Google Ads configurada (OAuth/API key). Enquanto não houver
 * credencial, retorna erro claro. Preencher o fetch real quando a conta for conectada.
 * Grava com campaign_name = '[SYNC GOOGLE]'. Contrato igual ao sync Meta.
 */
function ads_roi_sync_google(array $studio, int $days = 30): array
{
    $settings = studio_settings($studio);
    $googleCfg = [
        'developer_token' => trim((string)($settings['google_ads_developer_token'] ?? '')),
        'customer_id' => trim((string)($settings['google_ads_customer_id'] ?? '')),
        'client_id' => trim((string)($settings['google_ads_client_id'] ?? '')),
        'client_secret' => trim((string)($settings['google_ads_client_secret'] ?? '')),
        'refresh_token' => trim((string)($settings['google_ads_refresh_token'] ?? '')),
    ];
    if (in_array('', $googleCfg, true)) {
        return ['ok' => false, 'error' => 'Google Ads ainda não configurado: conecte a conta (developer token, customer id e OAuth) para importar o gasto automaticamente.', 'imported' => 0, 'days' => $days, 'needs_google_credentials' => true];
    }

    // TODO(google-ads): implementar o fetch real via Google Ads API quando a conta for conectada.
    // O contrato: para cada dia do período, doar [datetime => spend] e gravar como abaixo.
    $days = max(1, min(365, $days));
    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO ads_daily (campaign_date, channel, campaign_name, spend, notes)
         VALUES (?, "google", "[SYNC GOOGLE]", ?, NULL)
         ON DUPLICATE KEY UPDATE spend = VALUES(spend)'
    );
    // Exemplo: $stmt->execute([$date, $spend]);
    return ['ok' => true, 'imported' => 0, 'days' => $days, 'stub' => true];
}
