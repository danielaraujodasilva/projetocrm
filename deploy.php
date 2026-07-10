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

header('Content-Type: text/plain; charset=utf-8');
echo $output;

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
