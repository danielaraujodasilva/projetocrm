<?php
/**
 * Funções de sincronização do painel de ROI (ads_daily).
 * - ads_roi_sync_meta($studio, $days): puxa gasto diário real do Meta Ads e grava em ads_daily.
 * - ads_roi_sync_google($studio, $days): idem para Google Ads.
 *
 * Retornos: ['ok'=>bool, 'imported'=>int, 'days'=>int, 'error'=>string]
 * Para o Google sem credencial completa: ['ok'=>false, 'needs_google_credentials'=>true, 'error'=>...]
 */
declare(strict_types=1);

require_once __DIR__ . '/ads_daily.php';
require_once __DIR__ . '/ads_meta_sync.php';

/** Grava/atualiza o gasto de um dia/canal em ads_daily (preserva leads_direct/notes manuais). */
function ads_roi_write_spend(PDO $pdo, string $date, string $channel, float $spend, string $note = ''): void
{
    if ($spend <= 0) {
        return;
    }
    studio_ads_daily_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO ads_daily (campaign_date, channel, campaign_name, spend, leads_direct, notes)
         VALUES (?, ?, ?, ?, 0, ?)
         ON DUPLICATE KEY UPDATE spend = VALUES(spend), notes = VALUES(notes)'
    );
    $stmt->execute([$date, $channel, '[SYNC ' . strtoupper($channel) . ']', $spend, $note !== '' ? $note : null]);
}

/** Meta Ads: gasto diário real. */
function ads_roi_sync_meta(array $studio, int $days = 7): array
{
    $res = ads_meta_sync_daily_spend($studio, $days);
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => (string)($res['error'] ?? 'erro meta')];
    }
    return [
        'ok' => true,
        'imported' => (int)($res['dias_com_gasto'] ?? 0),
        'days' => (int)($res['dias_sincronizados'] ?? $days),
    ];
}

/** Renova o access token do Google Ads a partir do refresh token salvo. */
function ads_roi_google_access_token(array $studio): array
{
    $st = studio_settings($studio);
    $cid = trim((string)($st['google_ads_client_id'] ?? ''));
    $sec = trim((string)($st['google_ads_client_secret'] ?? ''));
    $rt  = trim((string)($st['google_ads_refresh_token'] ?? ''));

    if ($cid === '' || $sec === '' || $rt === '') {
        // Fallback: arquivo local de configuracao.
        $cfgPath = APP_BASE_PATH . '/config/google_ads.local.json';
        if (is_file($cfgPath)) {
            $cfg = json_decode((string)@file_get_contents($cfgPath), true);
            $cid = $cid !== '' ? $cid : (string)($cfg['web']['client_id'] ?? '');
            $sec = $sec !== '' ? $sec : (string)($cfg['web']['client_secret'] ?? '');
        }
        if ($cid === '' || $sec === '' || $rt === '') {
            return ['ok' => false, 'needs_google_credentials' => true, 'error' => 'Credenciais Google Ads incompletas (client_id/client_secret/refresh_token).'];
        }
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $cid,
            'client_secret' => $sec,
            'refresh_token' => $rt,
            'grant_type' => 'refresh_token',
        ]),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    if ($code >= 400 || empty($json['access_token'])) {
        return ['ok' => false, 'error' => 'Falha ao renovar token Google (HTTP ' . $code . ').'];
    }
    return ['ok' => true, 'access_token' => (string)$json['access_token']];
}

/**
 * Google Ads: gasto diario. Requer, alem do OAuth, o developer-token da API.
 * Sem developer token a API responde 404/403 - devolvemos needs_google_credentials.
 */
function ads_roi_sync_google(array $studio, int $days = 7): array
{
    $st = studio_settings($studio);
    $customerId = preg_replace('/\D+/', '', (string)($st['google_ads_customer_id'] ?? ''));
    $devToken = trim((string)($st['google_ads_developer_token'] ?? ''));

    if ($customerId === '') {
        return ['ok' => false, 'needs_google_credentials' => true, 'error' => 'customer_id do Google Ads nao configurado.'];
    }
    if ($devToken === '') {
        return ['ok' => false, 'needs_google_credentials' => true, 'error' => 'developer-token do Google Ads ausente (obrigatorio para a API).'];
    }

    $tok = ads_roi_google_access_token($studio);
    if (empty($tok['ok'])) {
        return ['ok' => false, 'needs_google_credentials' => !empty($tok['needs_google_credentials']), 'error' => (string)($tok['error'] ?? 'erro token')];
    }
    $access = (string)$tok['access_token'];
    $version = 'v19';
    $loginCustomer = preg_replace('/\D+/', '', (string)($st['google_ads_login_customer_id'] ?? ''));

    $since = date('Y-m-d', strtotime('-' . max(1, $days) . ' days'));
    $until = date('Y-m-d');
    $query = 'SELECT segments.date, metrics.cost_micros FROM customer WHERE segments.date BETWEEN "' . $since . '" AND "' . $until . '"';

    $headers = [
        'Authorization: Bearer ' . $access,
        'developer-token: ' . $devToken,
        'Content-Type: application/json',
    ];
    if ($loginCustomer !== '') {
        $headers[] = 'login-customer-id: ' . $loginCustomer;
    }

    $ch = curl_init('https://googleads.googleapis.com/' . $version . '/customers/' . $customerId . '/googleAds:searchStream');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code >= 400) {
        return ['ok' => false, 'error' => 'Google Ads API HTTP ' . $code . ': ' . substr((string)$raw, 0, 200)];
    }

    $pdo = studio_db($studio);
    $imported = 0;
    // searchStream devolve um array de chunks com "results".
    $chunks = json_decode((string)$raw, true);
    if (is_array($chunks)) {
        foreach ($chunks as $chunk) {
            foreach (($chunk['results'] ?? []) as $row) {
                $date = (string)($row['segments']['date'] ?? '');
                $micros = (int)($row['metrics']['costMicros'] ?? 0);
                if ($date !== '' && $micros > 0) {
                    ads_roi_write_spend($pdo, $date, 'google', $micros / 1000000, 'SYNC GOOGLE');
                    $imported++;
                }
            }
        }
    }
    return ['ok' => true, 'imported' => $imported, 'days' => max(1, $days)];
}
