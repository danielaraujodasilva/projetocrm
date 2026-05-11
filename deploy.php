<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('America/Sao_Paulo');

$logPath = __DIR__ . '/deploy.log';
file_put_contents($logPath, date('Y-m-d H:i:s') . " deploy solicitado\n", FILE_APPEND);

$localConfig = __DIR__ . '/deploy.local.php';
$deployConfig = is_file($localConfig) ? require $localConfig : [];
if (!is_array($deployConfig)) {
    $deployConfig = [];
}

$secret = getenv('PROJETOCRM_DEPLOY_WEBHOOK_SECRET') ?: (string)($deployConfig['secret'] ?? '');
if ($secret === '') {
    http_response_code(500);
    echo 'Deploy nao configurado.';
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$signature = (string)($headers['X-Hub-Signature-256'] ?? $headers['x-hub-signature-256'] ?? '');
$payload = (string)file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo 'Assinatura invalida.';
    exit;
}

$deployPath = getenv('PROJETOCRM_DEPLOY_PATH') ?: (string)($deployConfig['path'] ?? __DIR__);
$branch = getenv('PROJETOCRM_DEPLOY_BRANCH') ?: (string)($deployConfig['branch'] ?? 'main');
if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
    http_response_code(500);
    echo 'Branch invalida.';
    exit;
}

$realDeployPath = realpath($deployPath);
if ($realDeployPath === false || $realDeployPath !== realpath(__DIR__)) {
    http_response_code(500);
    echo 'Caminho de deploy invalido.';
    exit;
}

$command = 'cd ' . escapeshellarg($realDeployPath)
    . ' && git fetch origin ' . escapeshellarg($branch)
    . ' && git reset --hard ' . escapeshellarg('origin/' . $branch)
    . ' 2>&1';

$output = shell_exec($command) ?: '';
file_put_contents($logPath, date('Y-m-d H:i:s') . "\n" . $output . "\n", FILE_APPEND);

header('Content-Type: text/plain; charset=utf-8');
echo $output;
