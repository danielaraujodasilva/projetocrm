<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

function meta_oauth_log(string $message, array $context = []): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('c') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($dir . '/meta_ads_oauth.log', $line . PHP_EOL, FILE_APPEND);
}

function meta_oauth_fail(string $message, array $context = []): void
{
    meta_oauth_log($message, $context);
    flash_set('error', $message);
    redirect_to('studio_meta_ads');
}

function meta_oauth_get_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno || $raw === false) {
        return ['ok' => false, 'status' => $status, 'error' => $error ?: 'Falha na chamada OAuth da Meta.', 'raw' => $raw];
    }
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Resposta invalida da Meta.', 'raw' => $raw];
    }
    if ($status >= 400 || !empty($json['error'])) {
        return ['ok' => false, 'status' => $status, 'error' => (string)($json['error']['message'] ?? ('Erro HTTP ' . $status)), 'json' => $json, 'raw' => $raw];
    }
    return ['ok' => true, 'status' => $status, 'json' => $json, 'raw' => $raw];
}

function meta_oauth_post_form(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno || $raw === false) {
        return ['ok' => false, 'status' => $status, 'error' => $error ?: 'Falha na chamada OAuth da Meta.', 'raw' => $raw];
    }
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Resposta invalida da Meta.', 'raw' => $raw];
    }
    if ($status >= 400 || !empty($json['error'])) {
        return ['ok' => false, 'status' => $status, 'error' => (string)($json['error']['message'] ?? ('Erro HTTP ' . $status)), 'json' => $json, 'raw' => $raw];
    }
    return ['ok' => true, 'status' => $status, 'json' => $json, 'raw' => $raw];
}

$studio = require_studio();
$settings = studio_settings($studio);
$incomingState = (string)($_GET['state'] ?? '');
$expectedState = (string)($_SESSION['meta_ads_oauth_state'] ?? '');

if ($incomingState === '' || $expectedState === '' || !hash_equals($expectedState, $incomingState)) {
    meta_oauth_fail('Falha na validacao do OAuth da Meta.', ['reason' => 'invalid_state']);
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    meta_oauth_fail('A Meta nao retornou o code de autorizacao.', ['reason' => 'missing_code', 'query' => $_GET]);
}

$appId = trim((string)($settings['meta_ads_app_id'] ?? ''));
$appSecret = trim((string)($settings['meta_ads_app_secret'] ?? ''));
$redirectUri = 'https://danieltatuador.com/projetocrm/meta_oauth_callback.php';
$apiVersion = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0'));
if ($appId === '' || $appSecret === '') {
    meta_oauth_fail('Configure o App ID e o App Secret antes de conectar.', ['reason' => 'missing_app_credentials']);
}

$tokenUrl = 'https://graph.facebook.com/oauth/access_token';
$tokenResponse = meta_oauth_post_form($tokenUrl, [
    'client_id' => $appId,
    'redirect_uri' => $redirectUri,
    'client_secret' => $appSecret,
    'code' => $code,
]);
if (!$tokenResponse['ok']) {
    meta_oauth_fail('Erro ao trocar o code por token curto.', ['reason' => 'short_token_exchange', 'error' => $tokenResponse['error'] ?? null, 'raw' => $tokenResponse['raw'] ?? null, 'json' => $tokenResponse['json'] ?? null]);
}

$shortToken = trim((string)($tokenResponse['json']['access_token'] ?? ''));
if ($shortToken === '') {
    meta_oauth_fail('A Meta nao devolveu um token curto valido.', ['reason' => 'missing_short_token', 'response' => $tokenResponse['json'] ?? null]);
}

$longTokenUrl = 'https://graph.facebook.com/oauth/access_token';
$longTokenResponse = meta_oauth_post_form($longTokenUrl, [
    'grant_type' => 'fb_exchange_token',
    'client_id' => $appId,
    'client_secret' => $appSecret,
    'fb_exchange_token' => $shortToken,
]);
if (!$longTokenResponse['ok']) {
    meta_oauth_fail('Erro ao trocar o token curto por token longo.', ['reason' => 'long_token_exchange', 'error' => $longTokenResponse['error'] ?? null, 'raw' => $longTokenResponse['raw'] ?? null, 'json' => $longTokenResponse['json'] ?? null]);
}

$accessToken = trim((string)($longTokenResponse['json']['access_token'] ?? $shortToken));
if ($accessToken === '') {
    meta_oauth_fail('Nao foi possivel obter o access_token final.', ['reason' => 'missing_final_token']);
}

$accountsResponse = studio_meta_ads_request($apiVersion, '/me/adaccounts', $accessToken, [
    'fields' => 'id,name,account_status,business',
    'limit' => 100,
], 'GET', null, 30);
if (!$accountsResponse['ok']) {
    meta_oauth_fail('Consegui o token, mas falhei ao listar as contas de anuncio.', ['reason' => 'list_accounts', 'error' => $accountsResponse['error'] ?? null, 'raw' => $accountsResponse['raw'] ?? null, 'json' => $accountsResponse['json'] ?? null]);
}

$accounts = is_array($accountsResponse['json']['data'] ?? null) ? $accountsResponse['json']['data'] : [];
$_SESSION['meta_ads_oauth_accounts'] = $accounts;
$_SESSION['meta_ads_oauth_result'] = [
    'ok' => true,
    'access_token_tail' => studio_meta_ads_mask_secret($accessToken),
    'accounts_count' => count($accounts),
];
unset($_SESSION['meta_ads_oauth_state']);

$pdo = studio_db($studio);
$stmt = $pdo->prepare('UPDATE studio_settings SET meta_ads_access_token = ?, meta_ads_api_version = ?, meta_ads_redirect_uri = ?, updated_at = NOW() WHERE id = 1');
$stmt->execute([
    $accessToken,
    $apiVersion !== '' ? $apiVersion : 'v22.0',
    $redirectUri,
]);

$targetAccountId = 'act_875946594343063';
$existingAccount = null;
foreach ($accounts as $account) {
    $accountId = (string)($account['id'] ?? '');
    if ($accountId === $targetAccountId) {
        $existingAccount = $accountId;
        break;
    }
}
if ($existingAccount === null && count($accounts) === 1) {
    $existingAccount = (string)($accounts[0]['id'] ?? '');
}
if ($existingAccount !== null && $existingAccount !== '') {
    $stmt = $pdo->prepare('UPDATE studio_settings SET meta_ads_ad_account_id = ?, updated_at = NOW() WHERE id = 1');
    $stmt->execute([preg_replace('/^act_/', '', $existingAccount)]);
    $_SESSION['meta_ads_oauth_result']['selected_account_id'] = $existingAccount;
}

meta_oauth_log('OAuth Meta Ads concluido', [
    'studio_id' => (int)$studio['id'],
    'token_tail' => studio_meta_ads_mask_secret($accessToken),
    'accounts_count' => count($accounts),
    'selected_account' => $existingAccount,
]);

flash_set('success', 'Meta Ads conectada com sucesso.');
redirect_to('studio_meta_ads');
