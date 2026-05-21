<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_chat_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $studio = require_studio();
    $conversationId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($conversationId <= 0) {
        api_chat_json(['ok' => false, 'error' => 'Conversa invalida.'], 422);
    }

    $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
    if (!$conversation) {
        api_chat_json(['ok' => false, 'error' => 'Conversa nao encontrada.'], 404);
    }

    $messages = studio_whatsapp_messages($studio, $conversationId);
    api_chat_json([
        'ok' => true,
        'conversation' => $conversation,
        'mensagens' => $messages,
    ]);
} catch (Throwable $e) {
    api_chat_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
