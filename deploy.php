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

$whatsappConfig = $deployConfig['whatsapp_service'] ?? [];
if (!is_array($whatsappConfig)) {
    $whatsappConfig = [];
}

$whatsappEnabled = array_key_exists('enabled', $whatsappConfig)
    ? (bool)$whatsappConfig['enabled']
    : true;

if ($whatsappEnabled) {
    $servicePath = (string)($whatsappConfig['path'] ?? ($realDeployPath . '/services/whatsapp'));
    $realServicePath = realpath($servicePath);

    $serviceInsideDeploy = $realServicePath !== false
        && ($realServicePath === $realDeployPath || str_starts_with($realServicePath, $realDeployPath . DIRECTORY_SEPARATOR));

    if (!$serviceInsideDeploy) {
        $whatsappOutput = "Servico WhatsApp: caminho invalido ou nao encontrado.\n";
    } else {
        $whatsappOutput = projetocrm_deploy_whatsapp_service($realServicePath, $whatsappConfig);
    }

    file_put_contents($logPath, date('Y-m-d H:i:s') . "\n" . $whatsappOutput . "\n", FILE_APPEND);
    $output .= "\n" . $whatsappOutput;
}

header('Content-Type: text/plain; charset=utf-8');
echo $output;

function projetocrm_deploy_whatsapp_service(string $servicePath, array $config): string
{
    $lines = ["Servico WhatsApp:"];
    $pidFile = $servicePath . '/whatsapp_service.pid';
    $logFile = $servicePath . '/whatsapp_service.log';
    $port = (string)($config['port'] ?? getenv('WHATSAPP_PORT') ?: '3010');
    $install = array_key_exists('install', $config) ? (bool)$config['install'] : true;
    $restart = array_key_exists('restart', $config) ? (bool)$config['restart'] : true;

    if ($install) {
        $installCommand = 'cd ' . escapeshellarg($servicePath) . ' && npm install --omit=dev 2>&1';
        $lines[] = '$ ' . $installCommand;
        $lines[] = trim((string)shell_exec($installCommand));
    }

    if (!$restart) {
        $lines[] = 'Reinicio automatico desativado.';
        return implode("\n", array_filter($lines, static fn($line) => $line !== '')) . "\n";
    }

    if (is_file($pidFile)) {
        $pid = preg_replace('/\D+/', '', (string)file_get_contents($pidFile));
        if ($pid !== '') {
            if (PHP_OS_FAMILY === 'Windows') {
                shell_exec('taskkill /PID ' . escapeshellarg($pid) . ' /F 2>&1');
            } else {
                shell_exec('kill ' . escapeshellarg($pid) . ' 2>&1');
            }
        }
        @unlink($pidFile);
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $launcher = projetocrm_write_windows_whatsapp_launcher($servicePath, $logFile, $port, false);
        $startCommand = 'start "" ' . projetocrm_windows_cmd_arg($launcher);
        $lines[] = '$ ' . $startCommand;
        $lines[] = trim((string)shell_exec($startCommand));
    } else {
        $env = 'WHATSAPP_PORT=' . escapeshellarg($port);
        if (!empty($config['webhook_url'])) {
            $env .= ' WHATSAPP_WEBHOOK_URL=' . escapeshellarg((string)$config['webhook_url']);
        }
        $startCommand = 'cd ' . escapeshellarg($servicePath)
            . ' && ' . $env
            . ' nohup npm start > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        $lines[] = '$ ' . $startCommand;
        $pid = trim((string)shell_exec($startCommand));
        if ($pid !== '') {
            file_put_contents($pidFile, $pid);
            $lines[] = 'PID: ' . $pid;
        }
    }

    return implode("\n", array_filter($lines, static fn($line) => $line !== '')) . "\n";
}

function projetocrm_windows_env_value(string $value): string
{
    return str_replace(['"', "\r", "\n"], '', $value);
}

function projetocrm_windows_cmd_arg(string $value): string
{
    return '"' . str_replace('"', '', $value) . '"';
}

function projetocrm_write_windows_whatsapp_launcher(string $servicePath, string $logFile, string $port, bool $install): string
{
    $launcher = $servicePath . '/whatsapp_service_start.cmd';
    $lines = [
        '@echo off',
        'cd /d "' . str_replace('"', '', $servicePath) . '"',
        'set "WHATSAPP_PORT=' . projetocrm_windows_env_value($port) . '"',
    ];

    if ($install) {
        $lines[] = 'npm.cmd install --omit=dev >> "' . str_replace('"', '', $logFile) . '" 2>&1';
    }

    $lines[] = 'npm.cmd start >> "' . str_replace('"', '', $logFile) . '" 2>&1';
    file_put_contents($launcher, implode("\r\n", $lines) . "\r\n");

    return $launcher;
}
