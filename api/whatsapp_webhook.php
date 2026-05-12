<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$sessionKey = trim((string)($payload['studioSessionKey'] ?? $payload['sessionKey'] ?? ''));
$studio = null;
if ($sessionKey !== '') {
    $studio = get_studio_by_session_key($sessionKey);
}

if (!$studio && !empty($payload['studioId'])) {
    $studio = get_studio((int)$payload['studioId']);
}

if (!$studio) {
    api_json(['ok' => false, 'error' => 'Estudio nao encontrado para esta sessao WhatsApp.'], 404);
}

$expectedToken = studio_whatsapp_webhook_token($studio);
$receivedToken = trim((string)($payload['webhookToken'] ?? $payload['token'] ?? ''));
if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    api_json(['ok' => false, 'error' => 'Token do webhook invalido.'], 403);
}

if (!empty($payload['statusEvent'])) {
    $status = (string)($payload['status'] ?? 'error');
    $platformStatus = match ($status) {
        'connected' => 'connected',
        'waiting_qr' => 'waiting_qr',
        'starting', 'disconnected' => 'disconnected',
        default => 'error',
    };
    studio_update_whatsapp_platform_status($studio, $platformStatus);
    studio_event((int)$studio['id'], 'whatsapp_status_' . $platformStatus, (string)($payload['message'] ?? 'Status WhatsApp atualizado.'));
    api_json(['ok' => true, 'status' => $platformStatus]);
}

try {
    $result = studio_record_whatsapp_message($studio, $payload);
    api_json($result + ['ok' => true]);
} catch (Throwable $e) {
    studio_event((int)$studio['id'], 'whatsapp_webhook_error', $e->getMessage());
    api_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
