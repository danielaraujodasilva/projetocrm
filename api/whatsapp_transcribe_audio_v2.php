<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_whatsapp_transcribe_v2_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
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
    api_whatsapp_transcribe_v2_json(['ok' => false, 'error' => 'Audio nao informado.'], 422);
}

$pdo = studio_db($studio);
$stmt = $pdo->prepare('SELECT id, message_id, media_url, media_file_path, transcricao, transcript, transcricao_erro, transcript_error FROM whatsapp_messages WHERE conversation_id = ? AND (message_id = ? OR media_url = ? OR media_file_path = ?) LIMIT 1');
$stmt->execute([$conversationId, $messageId, $mediaUrl, $mediaUrl]);
$message = $stmt->fetch();
if (!$message) {
    api_whatsapp_transcribe_v2_json(['ok' => false, 'error' => 'Audio nao encontrado nesta conversa.'], 404);
}

$existingText = trim((string)($message['transcricao'] ?? $message['transcript'] ?? ''));
if ($existingText !== '') {
    api_whatsapp_transcribe_v2_json([
        'ok' => true,
        'text' => $existingText,
        'engine' => 'cached',
    ]);
}

$resolvedMediaPath = trim((string)($message['media_file_path'] ?? ''));
if ($resolvedMediaPath === '') {
    $resolvedMediaPath = trim((string)($message['media_url'] ?? ''));
}
if ($resolvedMediaPath === '') {
    api_whatsapp_transcribe_v2_json(['ok' => false, 'error' => 'Arquivo de audio nao encontrado.'], 404);
}

studio_attempt_whatsapp_audio_transcription(
    $studio,
    trim((string)($message['message_id'] ?? '')),
    $resolvedMediaPath
);

$stmt = $pdo->prepare('SELECT transcricao, transcript, transcricao_erro, transcript_error FROM whatsapp_messages WHERE id = ? LIMIT 1');
$stmt->execute([(int)$message['id']]);
$updated = $stmt->fetch() ?: [];
$text = trim((string)($updated['transcricao'] ?? $updated['transcript'] ?? ''));
$error = trim((string)($updated['transcricao_erro'] ?? $updated['transcript_error'] ?? ''));

if ($text === '') {
    api_whatsapp_transcribe_v2_json([
        'ok' => false,
        'error' => $error !== '' ? $error : 'Nao foi possivel reconhecer fala nesse audio',
    ], 500);
}

api_whatsapp_transcribe_v2_json([
    'ok' => true,
    'text' => $text,
    'engine' => 'auto',
]);
