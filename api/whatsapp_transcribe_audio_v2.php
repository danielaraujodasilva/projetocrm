<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/historico_conversas.php';

header('Content-Type: application/json; charset=utf-8');

function api_whatsapp_transcribe_v2_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_whatsapp_transcribe_v2_bridge_file(string $mediaUrl): string
{
    $query = (string)(parse_url($mediaUrl, PHP_URL_QUERY) ?: '');
    parse_str($query, $params);
    $file = basename(trim((string)($params['file'] ?? '')));
    if ($file === '' || preg_match('/^[A-Za-z0-9._-]+\.(ogg|oga|opus|mp3|wav|m4a|aac|webm)$/i', $file) !== 1) {
        return '';
    }
    $baseDir = 'C:\\Users\\server_spd\\Documents\\whatsapp-origin-bridge\\dados\\audios';
    $realPath = realpath($baseDir . DIRECTORY_SEPARATOR . $file);
    $realBase = realpath($baseDir);
    if ($realPath === false || $realBase === false || !str_starts_with(strtolower($realPath), strtolower($realBase . DIRECTORY_SEPARATOR)) || !is_file($realPath)) {
        return '';
    }
    return $realPath;
}

function api_whatsapp_transcribe_v2_run_python(string $audioPath): array
{
    $script = realpath(__DIR__ . '/../tools/transcription/transcribe_audio.py');
    if (!$script) {
        return ['ok' => false, 'error' => 'Script de transcricao nao encontrado.'];
    }
    $python = studio_whisper_python_binary();
    if ($python === '') {
        return ['ok' => false, 'error' => 'Nenhum interpretador Python com whisper encontrado para transcricao.'];
    }
    $stdout = tempnam(sys_get_temp_dir(), 'wa_transcribe_out_');
    $stderr = tempnam(sys_get_temp_dir(), 'wa_transcribe_err_');
    if ($stdout === false || $stderr === false) {
        return ['ok' => false, 'error' => 'Nao foi possivel preparar a transcricao.'];
    }
    $command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($audioPath) . ' base auto > ' . escapeshellarg($stdout) . ' 2> ' . escapeshellarg($stderr);
    exec($command, $output, $exitCode);
    $json = is_file($stdout) ? trim((string)file_get_contents($stdout)) : '';
    $error = is_file($stderr) ? trim((string)file_get_contents($stderr)) : '';
    @unlink($stdout);
    @unlink($stderr);
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || empty($decoded['ok']) || trim((string)($decoded['text'] ?? '')) === '') {
        return ['ok' => false, 'error' => (string)($decoded['error'] ?? $error ?: 'Nao foi possivel reconhecer fala nesse audio')];
    }
    return ['ok' => true, 'text' => trim((string)$decoded['text']), 'engine' => (string)($decoded['engine'] ?? 'auto')];
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
    $bridgePath = api_whatsapp_transcribe_v2_bridge_file($mediaUrl);
    if ($bridgePath === '') {
        api_whatsapp_transcribe_v2_json(['ok' => false, 'error' => 'Audio nao encontrado nesta conversa.'], 404);
    }
    $bridgeFile = basename($bridgePath);
    $cachedBridge = historico_audio_transcricao_ponte($bridgeFile);
    if ($cachedBridge !== '') {
        api_whatsapp_transcribe_v2_json(['ok' => true, 'text' => $cachedBridge, 'engine' => 'cached']);
    }
    $bridgeResult = api_whatsapp_transcribe_v2_run_python($bridgePath);
    if (empty($bridgeResult['ok'])) {
        api_whatsapp_transcribe_v2_json(['ok' => false, 'error' => (string)($bridgeResult['error'] ?? 'Nao foi possivel transcrever.')], 500);
    }
    historico_salvar_transcricao_audio_ponte($bridgeFile, (string)$bridgeResult['text']);
    api_whatsapp_transcribe_v2_json([
        'ok' => true,
        'text' => (string)$bridgeResult['text'],
        'engine' => (string)($bridgeResult['engine'] ?? 'auto'),
    ]);
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
