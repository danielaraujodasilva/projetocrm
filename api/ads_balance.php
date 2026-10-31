<?php
declare(strict_types=1);

/**
 * Saldos de anuncios para a rotina local da ponte.
 *
 * SOMENTE LEITURA: usa as mesmas credenciais e funcoes do painel de Ads,
 * sem alterar configuracoes, campanhas ou agendamentos.
 *
 * SEGURANCA: exige o token compartilhado da ponte no header X-Bridge-Token.
 */

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

function ads_balance_respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ads_balance_token(): string
{
    $localFile = APP_BASE_PATH . '/storage/origin_bridge.local.php';
    if (is_file($localFile)) {
        $cfg = require $localFile;
        if (is_array($cfg) && !empty($cfg['token'])) {
            return trim((string)$cfg['token']);
        }
    }
    return trim((string)(getenv('BRIDGE_ORIGIN_TOKEN') ?: ''));
}

function ads_balance_last_notified(array $studio, string $key): ?string
{
    try {
        $pdo = studio_db($studio);
        $stmt = $pdo->prepare('SELECT last_notified_at FROM crm_alert_state WHERE alert_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['last_notified_at'])) {
            return (string)$row['last_notified_at'];
        }
    } catch (Throwable $e) {
        // Estado antigo ausente nao impede a leitura do saldo.
    }
    return null;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    ads_balance_respond(405, ['ok' => false, 'error' => 'Use POST.']);
}

$expected = ads_balance_token();
$provided = trim((string)($_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ''));
if ($expected === '') {
    ads_balance_respond(500, ['ok' => false, 'error' => 'Ponte sem token configurado no servidor.']);
}
if ($provided === '' || !hash_equals($expected, $provided)) {
    ads_balance_respond(403, ['ok' => false, 'error' => 'Token invalido.']);
}

try {
    $studio = get_studio(1);
    $settings = studio_settings($studio);
} catch (Throwable $e) {
    ads_balance_respond(500, ['ok' => false, 'error' => 'Estudio indisponivel.']);
}

$metaThreshold = (float)money_to_float((string)($settings['meta_balance_alert_threshold'] ?? '20'));
if ($metaThreshold <= 0) {
    $metaThreshold = 20.0;
}

$meta = studio_meta_ads_balance_status($studio, $metaThreshold);
$google = ads_roi_google_balance_status($studio);

ads_balance_respond(200, [
    'ok' => true,
    'checked_at' => date('c'),
    'meta' => $meta,
    'google' => $google,
    'meta_threshold' => $metaThreshold,
    'meta_last_notified_at' => ads_balance_last_notified($studio, 'meta_ads_low_balance'),
]);
