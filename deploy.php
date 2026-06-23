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

$ffmpegOutput = projetocrm_ensure_ffmpeg($deployConfig['ffmpeg'] ?? []);
if ($ffmpegOutput !== '') {
    file_put_contents($logPath, date('Y-m-d H:i:s') . "\n" . $ffmpegOutput . "\n", FILE_APPEND);
    $output .= "\n" . $ffmpegOutput;
}

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
    $install = false;
    $restart = array_key_exists('restart', $config) ? (bool)$config['restart'] : true;

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
        $lines[] = trim(projetocrm_stop_windows_port($port));
        $launcher = projetocrm_write_windows_whatsapp_launcher($servicePath, $logFile, $port, false);
        $startCommand = 'cmd /D /C start "" /MIN ' . projetocrm_windows_cmd_arg($launcher);
        $lines[] = '$ ' . $startCommand;
        $lines[] = trim((string)shell_exec($startCommand));
    } else {
        $env = 'WHATSAPP_PORT=' . escapeshellarg($port);
        if (!empty($config['webhook_url'])) {
            $env .= ' WHATSAPP_WEBHOOK_URL=' . escapeshellarg((string)$config['webhook_url']);
        }
        $startCommand = 'cd ' . escapeshellarg($servicePath)
            . ' && ' . $env
            . ' nohup node server.js > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        $lines[] = '$ ' . $startCommand;
        $pid = trim((string)shell_exec($startCommand));
        if ($pid !== '') {
            file_put_contents($pidFile, $pid);
            $lines[] = 'PID: ' . $pid;
        }
    }

    return implode("\n", array_filter($lines, static fn($line) => $line !== '')) . "\n";
}

function projetocrm_command_exists(string $command): bool
{
    $probe = PHP_OS_FAMILY === 'Windows'
        ? 'where ' . escapeshellarg($command) . ' 2>NUL'
        : 'command -v ' . escapeshellarg($command) . ' 2>/dev/null';

    return trim((string)shell_exec($probe)) !== '';
}

function projetocrm_ensure_ffmpeg(mixed $config): string
{
    $config = is_array($config) ? $config : [];
    $enabled = array_key_exists('enabled', $config) ? (bool)$config['enabled'] : true;
    if (!$enabled) {
        return '';
    }

    $lines = ['FFmpeg:'];
    $binary = trim((string)(getenv('FFMPEG_BINARY') ?: ($config['binary'] ?? 'ffmpeg')));
    if ($binary !== '' && (is_file($binary) || projetocrm_command_exists($binary))) {
        $lines[] = 'Disponivel: ' . $binary;
        return implode("\n", $lines) . "\n";
    }

    $installCommand = trim((string)($config['install_command'] ?? ''));
    if ($installCommand === '' && PHP_OS_FAMILY !== 'Windows' && projetocrm_command_exists('apt-get')) {
        $installCommand = 'sudo -n apt-get update && sudo -n apt-get install -y ffmpeg';
    }

    if ($installCommand === '') {
        $lines[] = 'Nao encontrado. Instale ffmpeg no servidor ou configure ffmpeg.install_command em deploy.local.php.';
        return implode("\n", $lines) . "\n";
    }

    $lines[] = '$ ' . $installCommand;
    $lines[] = trim((string)shell_exec($installCommand . ' 2>&1'));
    $lines[] = projetocrm_command_exists('ffmpeg') ? 'Instalado.' : 'Ainda nao encontrei ffmpeg apos a tentativa de instalacao.';
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

function projetocrm_stop_windows_port(string $port): string
{
    $port = preg_replace('/\D+/', '', $port) ?: '3010';
    $command = 'powershell -NoProfile -ExecutionPolicy Bypass -Command '
        . escapeshellarg('$pids = @(Get-NetTCPConnection -LocalPort ' . $port . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique); foreach ($procId in $pids) { Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue; Write-Output "Stopped WhatsApp service PID $procId"; }');

    return (string)shell_exec($command);
}

function projetocrm_write_windows_whatsapp_launcher(string $servicePath, string $logFile, string $port, bool $install): string
{
    $launcher = sys_get_temp_dir() . '/projetocrm_whatsapp_start_' . uniqid() . '.cmd';
    $lines = [
        '@echo off',
        'cd /d "' . str_replace('"', '', $servicePath) . '"',
        'set "WHATSAPP_PORT=' . projetocrm_windows_env_value($port) . '"',
    ];

    $lines[] = 'node server.js >> "' . str_replace('"', '', $logFile) . '" 2>&1';
    $lines[] = 'del "%~f0"';
    file_put_contents($launcher, implode("\r\n", $lines) . "\r\n");

    return $launcher;
}
