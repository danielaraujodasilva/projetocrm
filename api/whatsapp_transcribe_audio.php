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

function api_whatsapp_transcribe_find_audio_path(string $mediaUrl): ?string
{
    $mediaUrl = trim($mediaUrl);
    if ($mediaUrl === '') {
        return null;
    }

    $candidates = [];
    if (str_starts_with($mediaUrl, 'storage/')) {
        $candidates[] = __DIR__ . '/../' . $mediaUrl;
    }
    $candidates[] = $mediaUrl;

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real && is_file($real)) {
            return $real;
        }
    }

    return null;
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
$stmt = $pdo->prepare('SELECT media_url, media_mime, message_id FROM whatsapp_messages WHERE conversation_id = ? AND (message_id = ? OR media_url = ?) LIMIT 1');
$stmt->execute([$conversationId, $messageId, $mediaUrl]);
$message = $stmt->fetch();
if (!$message) {
    api_whatsapp_transcribe_json(['ok' => false, 'error' => 'Audio nao encontrado nesta conversa.'], 404);
}

$audioPath = api_whatsapp_transcribe_find_audio_path((string)($message['media_url'] ?? ''));
if (!$audioPath) {
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
