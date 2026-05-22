<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_whatsapp_transcribe_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_whatsapp_transcribe_exec(string $command): array
{
    $output = [];
    $exitCode = null;
    exec($command, $output, $exitCode);

    return [
        'exitCode' => $exitCode,
        'output' => implode(PHP_EOL, $output),
    ];
}

function api_whatsapp_transcribe_find_audio_path(string ...$mediaLocations): array
{
    $candidates = [];
    foreach ($mediaLocations as $mediaUrl) {
        $mediaUrl = trim($mediaUrl);
        if ($mediaUrl === '') {
            continue;
        }

        $normalized = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $mediaUrl), DIRECTORY_SEPARATOR);
        if (str_starts_with($mediaUrl, 'storage/') || str_starts_with($normalized, 'storage' . DIRECTORY_SEPARATOR)) {
            $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . $normalized;
        }
        $candidates[] = $mediaUrl;
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real && is_file($real)) {
            return ['path' => $real, 'temporary' => false];
        }
    }

    foreach ($mediaLocations as $mediaUrl) {
        $mediaUrl = trim($mediaUrl);
        if ($mediaUrl === '' || !str_starts_with($mediaUrl, 'storage/')) {
            continue;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            continue;
        }

        $scriptDir = trim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $basePath = $scriptDir !== '' ? preg_replace('#/api$#', '', '/' . $scriptDir) : '';
        $downloadUrl = $scheme . '://' . $host . rtrim((string)$basePath, '/') . '/' . ltrim($mediaUrl, '/');
        $binary = @file_get_contents($downloadUrl);
        if ($binary === false || $binary === '') {
            continue;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'wa_audio_');
        if ($tempPath === false) {
            continue;
        }
        if (@file_put_contents($tempPath, $binary) === false) {
            @unlink($tempPath);
            continue;
        }

        return ['path' => $tempPath, 'temporary' => true];
    }

    return ['path' => null, 'temporary' => false];
}

$studio = require_studio();
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$conversationId = (int)($payload['conversation_id'] ?? 0);
$messageId = trim((string)($payload['message_id'] ?? ''));
$mediaUrl = trim((string)($payload['media_url'] ?? ''));

if ($conversationId <= 0 || ($messageId === '' && $mediaUrl === '')) {
    api_whatsapp_transcribe_json(['ok' => false, 'error' => 'Audio nao informado.'], 422);
}

$pdo = studio_db($studio);
$stmt = $pdo->prepare('SELECT media_url, media_file_path, media_mime, message_id FROM whatsapp_messages WHERE conversation_id = ? AND (message_id = ? OR media_url = ? OR media_file_path = ?) LIMIT 1');
$stmt->execute([$conversationId, $messageId, $mediaUrl, $mediaUrl]);
$message = $stmt->fetch();
if (!$message) {
    api_whatsapp_transcribe_json(['ok' => false, 'error' => 'Audio nao encontrado nesta conversa.'], 404);
}

$audioFile = api_whatsapp_transcribe_find_audio_path(
    (string)($message['media_url'] ?? ''),
    (string)($message['media_file_path'] ?? ''),
    $mediaUrl
);
$audioPath = (string)($audioFile['path'] ?? '');
if ($audioPath === '') {
    api_whatsapp_transcribe_json(['ok' => false, 'error' => 'Arquivo de audio nao encontrado.'], 404);
}

$script = realpath(__DIR__ . '/../tools/transcription/transcribe_audio.py');
if (!$script) {
    api_whatsapp_transcribe_json(['ok' => false, 'error' => 'Script de transcricao nao encontrado.'], 500);
}

$stdout = tempnam(sys_get_temp_dir(), 'wa_transcribe_out_');
$stderr = tempnam(sys_get_temp_dir(), 'wa_transcribe_err_');
$command = 'py -3 ' . escapeshellarg($script) . ' ' . escapeshellarg($audioPath) . ' small auto > ' . escapeshellarg($stdout) . ' 2> ' . escapeshellarg($stderr);
$run = api_whatsapp_transcribe_exec($command);
$output = is_file($stdout) ? trim((string)file_get_contents($stdout)) : '';
$error = is_file($stderr) ? trim((string)file_get_contents($stderr)) : '';
if (is_file($stdout)) {
    unlink($stdout);
}
if (is_file($stderr)) {
    unlink($stderr);
}

$decoded = json_decode($output, true);
if (!empty($audioFile['temporary']) && is_file($audioPath)) {
    @unlink($audioPath);
}
if (!is_array($decoded) || empty($decoded['ok']) || empty($decoded['text'])) {
    try {
        studio_update_whatsapp_message_transcription($studio, [
            'messageId' => $messageId,
            'mediaUrl' => $mediaUrl,
            'error' => (string)($decoded['error'] ?? $error ?: 'Nao foi possivel reconhecer fala nesse audio'),
        ]);
    } catch (Throwable $ignore) {
    }
    api_whatsapp_transcribe_json([
        'ok' => false,
        'error' => (string)($decoded['error'] ?? $error ?: 'Nao foi possivel reconhecer fala nesse audio'),
    ], 500);
}

try {
    studio_update_whatsapp_message_transcription($studio, [
        'messageId' => $messageId,
        'mediaUrl' => $mediaUrl,
        'text' => (string)$decoded['text'],
    ]);
} catch (Throwable $ignore) {
}

api_whatsapp_transcribe_json([
    'ok' => true,
    'text' => (string)$decoded['text'],
    'engine' => (string)($decoded['engine'] ?? 'auto'),
]);
