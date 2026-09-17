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
 * Credenciais OAuth do Google Ads, lidas das configuracoes do estudio.
 * O CRM guarda client id/secret em studio_settings (mesma origem do Calendar quando vazio).
 */
function ads_roi_google_oauth_config(array $studio): array
{
    $settings = studio_settings($studio);
    $clientId = trim((string)($settings['google_ads_client_id'] ?? ''));
    $clientSecret = trim((string)($settings['google_ads_client_secret'] ?? ''));
    // Reaproveita as credenciais do Google Calendar quando o Ads nao tiver as proprias.
    if ($clientId === '' || $clientSecret === '') {
        $calendarCfg = (array)(app_config('google_calendar') ?? []);
        if ($clientId === '') {
            $clientId = trim((string)($calendarCfg['client_id'] ?? ''));
        }
        if ($clientSecret === '') {
            $clientSecret = trim((string)($calendarCfg['client_secret'] ?? ''));
        }
    }
    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => 'https://danieltatuador.com/projetocrm/google_ads_oauth_callback.php',
        'auth_uri' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        // Escopo do Google Ads. Ver docs: o escopo adwords cobre leitura de relatorios.
        'scopes' => ['https://www.googleapis.com/auth/adwords'],
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
    $imported = 0;
    foreach ($porDia as $data => $valor) {
        $stmt->execute([$data, round((float)$valor, 2)]);
        $imported++;
    }
    return ['ok' => true, 'imported' => $imported, 'days' => $days, 'from' => $de, 'to' => $ate];
}
