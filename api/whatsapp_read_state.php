<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function whatsapp_read_state_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $studio = require_studio();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        whatsapp_read_state_json([
            'ok' => true,
            'read' => studio_whatsapp_read_state($studio),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        whatsapp_read_state_json(['ok' => false, 'error' => 'Metodo nao permitido.'], 405);
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $conversationId = (int)($payload['id'] ?? $payload['conversation_id'] ?? 0);
    if ($conversationId <= 0) {
        whatsapp_read_state_json(['ok' => false, 'error' => 'Conversa invalida.'], 422);
    }

    $mode = (string)($payload['mode'] ?? 'read');
    if ($mode === 'unread') {
        studio_whatsapp_mark_unread($studio, $conversationId);
        whatsapp_read_state_json(['ok' => true, 'id' => $conversationId, 'read_at' => null]);
    }

    $readAt = studio_whatsapp_mark_read($studio, $conversationId);
    whatsapp_read_state_json(['ok' => true, 'id' => $conversationId, 'read_at' => $readAt]);
} catch (Throwable $e) {
    whatsapp_read_state_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
