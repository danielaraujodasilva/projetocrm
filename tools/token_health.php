<?php

declare(strict_types=1);

/**
 * Token health — checa e renova os tokens que o CRM usa.
 *
 * Uso: php tools/token_health.php [--no-alert] [--verbose]
 *
 * O que faz:
 *  - Meta Ads: confere o System User token (nao expira) via /debug_token.
 *  - Instagram: valida o token e, se estiver valido, renova por mais 60 dias
 *    (refresh_access_token) e grava de volta no instagram/config.local.php.
 *  - Google Ads / Calendar / Gmail: troca o refresh token por um access token
 *    (quem renova de verdade e o Google; aqui so conferimos se o refresh ainda vale).
 *  - WhatsApp oficial: se houver token, confere; se nao houver, so ignora.
 *  - Grava storage/logs/token_health.json + linha no token_health.log.
 *  - Se algum token essencial falhar, avisa o Daniel pelo WhatsApp da ponte
 *    (uma vez por mudanca de estado; --no-alert so desliga o envio).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('uso restrito a CLI');
}

require __DIR__ . '/../app/bootstrap.php';
require_once APP_BASE_PATH . '/app/google_calendar.php';
require_once APP_BASE_PATH . '/app/google_gmail.php';
require_once APP_BASE_PATH . '/app/ads_roi.php';

$noAlert = in_array('--no-alert', $argv, true);
$verbose = in_array('--verbose', $argv, true);

$logDir = APP_BASE_PATH . '/storage/logs';
@mkdir($logDir, 0775, true);
$statePath = $logDir . '/token_health.json';
$logPath = $logDir . '/token_health.log';
$igStatePath = $logDir . '/instagram_token_state.json';

function th_curl(string $url, ?array $post = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw, 'error' => $err];
}

function th_mask(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '(vazio)';
    }
    return strlen($value) <= 10 ? '(curto)' : substr($value, 0, 6) . '...' . substr($value, -4);
}

function th_write_atomic(string $path, string $content): bool
{
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $path);
}

function th_instagram_config_path(): string
{
    return dirname(APP_BASE_PATH) . '/instagram/config.local.php';
}

function th_instagram_update_token(string $newToken): bool
{
    $path = th_instagram_config_path();
    if (!is_file($path)) {
        return false;
    }
    $src = (string)@file_get_contents($path);
    if ($src === '') {
        return false;
    }
    $novo = preg_replace_callback(
        "/(['\"]access_token['\"]\s*=>\s*)['\"][^'\"]*['\"]/",
        static fn(array $m): string => $m[1] . var_export($newToken, true),
        $src,
        1
    );
    if (!is_string($novo) || $novo === $src) {
        return false;
    }
    $bak = $path . '.bak-' . date('Ymd-His') . '-antes-refresh';
    @copy($path, $bak);
    return th_write_atomic($path, $novo);
}

$studio = get_studio(1);
$settings = $studio ? studio_settings($studio) : [];
$tokens = [];

// ---------------------------------------------------------------- Meta Ads
$metaToken = trim((string)($settings['meta_ads_access_token'] ?? ''));
$tokens['meta_ads'] = [
    'label' => 'Meta Ads (System User)',
    'required' => true,
    'ok' => false,
    'detail' => 'sem token',
    'expires_at' => null,
];
if ($metaToken !== '') {
    $r = th_curl('https://graph.facebook.com/v22.0/debug_token?' . http_build_query([
        'input_token' => $metaToken,
        'access_token' => $metaToken,
    ]));
    $d = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : null;
    if ($r['code'] === 200 && is_array($d) && !empty($d['is_valid'])) {
        $expires = (int)($d['expires_at'] ?? 0);
        $tokens['meta_ads']['ok'] = true;
        $tokens['meta_ads']['expires_at'] = $expires > 0 ? date('c', $expires) : null;
        $tokens['meta_ads']['detail'] = 'tipo=' . (string)($d['type'] ?? '?')
            . ($expires > 0 ? ' | expira=' . date('d/m/Y H:i', $expires) : ' | nao expira')
            . ' | escopos=' . count((array)($d['scopes'] ?? []));
    } else {
        $tokens['meta_ads']['detail'] = 'falha: ' . substr((string)($r['json']['error']['message'] ?? $r['raw']), 0, 180);
    }
}

// ---------------------------------------------------------------- Instagram
$igConfigPath = th_instagram_config_path();
$igToken = '';
if (is_file($igConfigPath)) {
    $igCfg = require $igConfigPath;
    $igToken = trim((string)($igCfg['access_token'] ?? ''));
}
$tokens['instagram'] = [
    'label' => 'Instagram (Basic Display)',
    'required' => true,
    'ok' => false,
    'detail' => 'sem token',
    'expires_at' => null,
];
if ($igToken !== '') {
    $me = th_curl('https://graph.instagram.com/me?' . http_build_query([
        'fields' => 'id,username',
        'access_token' => $igToken,
    ]));
    if ($me['code'] === 200 && !empty($me['json']['id'])) {
        $username = (string)($me['json']['username'] ?? '?');
        $refresh = th_curl('https://graph.instagram.com/refresh_access_token?' . http_build_query([
            'grant_type' => 'ig_refresh_token',
            'access_token' => $igToken,
        ]));
        $novo = trim((string)($refresh['json']['access_token'] ?? ''));
        $expiresIn = (int)($refresh['json']['expires_in'] ?? 0);
        if ($refresh['code'] === 200 && $novo !== '') {
            $salvou = th_instagram_update_token($novo);
            $tokens['instagram']['ok'] = true;
            $tokens['instagram']['expires_at'] = $expiresIn > 0 ? date('c', time() + $expiresIn) : null;
            $tokens['instagram']['detail'] = 'valido @' . $username . ' | renovado por '
                . ($expiresIn > 0 ? round($expiresIn / 86400) . ' dias' : '?')
                . ($salvou ? ' | gravado' : ' | FALHA ao gravar config');
            th_write_atomic($igStatePath, json_encode([
                'ok' => true,
                'username' => $username,
                'expires_at' => time() + $expiresIn,
                'refreshed_at' => date('c'),
                'token_tail' => th_mask($novo),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $tokens['instagram']['detail'] = 'valido @' . $username . ', mas o refresh falhou: '
                . substr((string)($refresh['json']['error']['message'] ?? $refresh['raw']), 0, 160);
            $tokens['instagram']['ok'] = true;
        }
    } else {
        $tokens['instagram']['detail'] = 'INVALIDO: ' . substr((string)($me['json']['error']['message'] ?? $me['raw']), 0, 180)
            . ' | gere um token novo no app da Meta';
        th_write_atomic($igStatePath, json_encode([
            'ok' => false,
            'checked_at' => date('c'),
            'error' => $tokens['instagram']['detail'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// ---------------------------------------------------------------- Google Ads
$tokens['google_ads'] = [
    'label' => 'Google Ads (refresh token)',
    'required' => true,
    'ok' => false,
    'detail' => 'sem credenciais',
    'expires_at' => null,
];
try {
    $cfg = ads_roi_google_config($studio);
    $res = ads_roi_google_access_token($cfg);
    if (!empty($res['ok'])) {
        $tokens['google_ads']['ok'] = true;
        $tokens['google_ads']['detail'] = 'refresh valido | access token gerado';
    } else {
        $tokens['google_ads']['detail'] = 'falha: ' . substr((string)($res['error'] ?? '?'), 0, 200);
    }
} catch (Throwable $e) {
    $tokens['google_ads']['detail'] = 'excecao: ' . $e->getMessage();
}

// ---------------------------------------------------------------- Google Calendar
$tokens['google_calendar'] = [
    'label' => 'Google Agenda',
    'required' => true,
    'ok' => false,
    'detail' => 'sem integracao',
    'expires_at' => null,
];
try {
    $access = google_calendar_access_token($studio);
    if ($access !== '') {
        $row = $pdo ?? null;
        $tokens['google_calendar']['ok'] = true;
        $tokens['google_calendar']['detail'] = 'refresh valido | access token gerado';
    }
} catch (Throwable $e) {
    $tokens['google_calendar']['detail'] = 'falha: ' . $e->getMessage();
}

// ---------------------------------------------------------------- Google Gmail
$tokens['google_gmail'] = [
    'label' => 'Gmail',
    'required' => true,
    'ok' => false,
    'detail' => 'sem integracao',
    'expires_at' => null,
];
try {
    $access = google_gmail_access_token($studio);
    if ($access !== '') {
        $tokens['google_gmail']['ok'] = true;
        $tokens['google_gmail']['detail'] = 'refresh valido | access token gerado';
    }
} catch (Throwable $e) {
    $tokens['google_gmail']['detail'] = 'falha: ' . $e->getMessage();
}

// ---------------------------------------------------------------- WhatsApp oficial
$waToken = trim((string)($settings['whatsapp_official_access_token'] ?? ''));
$tokens['whatsapp'] = [
    'label' => 'WhatsApp oficial',
    'required' => false,
    'ok' => true,
    'detail' => 'nao configurado (a ponte Baileys e a linha principal)',
    'expires_at' => null,
];
if ($waToken !== '') {
    $r = th_curl('https://graph.facebook.com/v22.0/debug_token?' . http_build_query([
        'input_token' => $waToken,
        'access_token' => $waToken,
    ]));
    $d = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : null;
    if ($r['code'] === 200 && is_array($d) && !empty($d['is_valid'])) {
        $expires = (int)($d['expires_at'] ?? 0);
        $tokens['whatsapp']['ok'] = true;
        $tokens['whatsapp']['expires_at'] = $expires > 0 ? date('c', $expires) : null;
        $tokens['whatsapp']['detail'] = 'tipo=' . (string)($d['type'] ?? '?')
            . ($expires > 0 ? ' | expira=' . date('d/m/Y H:i', $expires) : ' | nao expira');
    } else {
        $tokens['whatsapp']['ok'] = false;
        $tokens['whatsapp']['detail'] = 'falha: ' . substr((string)($r['json']['error']['message'] ?? $r['raw']), 0, 160);
    }
}

// ---------------------------------------------------------------- Consolidado
$falhas = [];
foreach ($tokens as $key => $token) {
    if (!empty($token['required']) && empty($token['ok'])) {
        $falhas[] = $key;
    }
}
$overall = $falhas === [];
$anterior = [];
if (is_file($statePath)) {
    $anterior = json_decode((string)@file_get_contents($statePath), true) ?: [];
}
$assinatura = implode(',', $falhas);
$assinaturaAnterior = (string)($anterior['failure_signature'] ?? '');

$estado = [
    'checked_at' => date('c'),
    'overall_ok' => $overall,
    'failure_signature' => $assinatura,
    'tokens' => $tokens,
];
th_write_atomic($statePath, json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$linha = '[' . date('Y-m-d H:i:s') . '] overall=' . ($overall ? 'OK' : 'FALHA')
    . ' falhas=' . ($assinatura !== '' ? $assinatura : '-')
    . ' | meta=' . ($tokens['meta_ads']['ok'] ? 'ok' : 'fail')
    . ' ig=' . ($tokens['instagram']['ok'] ? 'ok' : 'fail')
    . ' gads=' . ($tokens['google_ads']['ok'] ? 'ok' : 'fail')
    . ' gcal=' . ($tokens['google_calendar']['ok'] ? 'ok' : 'fail')
    . ' gmail=' . ($tokens['google_gmail']['ok'] ? 'ok' : 'fail')
    . PHP_EOL;
@file_put_contents($logPath, $linha, FILE_APPEND | LOCK_EX);

if ($verbose) {
    echo $linha;
    foreach ($tokens as $key => $token) {
        echo str_pad($key, 18) . ($token['ok'] ? ' OK ' : ' FAIL') . ' ' . $token['detail'] . PHP_EOL;
    }
}

// Aviso no WhatsApp so quando o conjunto de falhas muda (evita spam diario).
if (!$noAlert && $assinatura !== $assinaturaAnterior) {
    $partes = [];
    foreach ($falhas as $key) {
        $partes[] = $tokens[$key]['label'] . ': ' . $tokens[$key]['detail'];
    }
    $texto = $overall
        ? '✅ Tokens do CRM: tudo certo de novo (' . date('d/m H:i') . ').'
        : '⚠️ Token com problema no CRM (' . date('d/m/Y H:i') . "):\n- " . implode("\n- ", $partes);
    $ch = curl_init('http://127.0.0.1:3210/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode(['to' => '5511947573311', 'message' => $texto], JSON_UNESCAPED_UNICODE),
    ]);
    $ok = curl_exec($ch);
    curl_close($ch);
    if ($verbose) {
        echo 'alerta=' . ($ok === false ? 'falhou' : 'enviado') . PHP_EOL;
    }
}

exit($overall ? 0 : 1);
