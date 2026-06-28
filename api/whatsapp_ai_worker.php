<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function worker_log(string $message, array $context = []): void
{
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/whatsapp_ai_worker.log';
    $line = '[' . date('c') . '] [ai-worker] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

$argv = $_SERVER['argv'] ?? [];
$sessionKey = (string)($argv[1] ?? '');
$conversationId = (int)($argv[2] ?? 0);
$messageId = (string)($argv[3] ?? '');

if ($sessionKey === '' || $conversationId <= 0 || $messageId === '') {
    worker_log('Parametros invalidos', ['sessionKey' => $sessionKey, 'conversationId' => $conversationId, 'messageId' => $messageId]);
    exit(1);
}

try {
    $studio = get_studio_by_session_key($sessionKey);
    if (!$studio) {
        worker_log('Studio nao encontrado', ['sessionKey' => $sessionKey]);
        exit(1);
    }

    $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
    if (!$conversation) {
        worker_log('Conversa nao encontrada', ['conversationId' => $conversationId]);
        exit(1);
    }

    $pdo = studio_db($studio);
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_messages WHERE conversation_id = ? AND message_id = ? LIMIT 1');
    $stmt->execute([$conversationId, $messageId]);
    $message = $stmt->fetch();
    if (!is_array($message)) {
        worker_log('Mensagem nao encontrada', ['conversationId' => $conversationId, 'messageId' => $messageId]);
        exit(1);
    }

    $messageBody = trim((string)($message['body'] ?? ''));
    if ($messageBody === '') {
        $messageBody = trim((string)($message['transcricao'] ?? $message['transcript'] ?? ''));
    }

    worker_log('Iniciando IA', ['conversationId' => $conversationId, 'messageId' => $messageId]);
    $result = studio_whatsapp_ai_reply($studio, $conversation, [
        'body' => $messageBody,
        'mensagem' => $messageBody,
        'from_me' => false,
        'direction' => 'in',
        'message_type' => (string)($message['message_type'] ?? 'texto'),
        'media_mime' => (string)($message['media_mime'] ?? ''),
        'media_url' => (string)($message['media_url'] ?? ''),
        'media_file_path' => (string)($message['media_file_path'] ?? ''),
        'media_file_name' => (string)($message['media_file_name'] ?? ''),
        'message_id' => $messageId,
    ]);
    if (!empty($result['ok'])) {
        worker_log('IA concluida', [
            'conversationId' => $conversationId,
            'messageId' => $messageId,
            'status' => $result['ai_last_status'] ?? '',
            'intent' => $result['intent'] ?? '',
            'visualType' => $result['image_analysis']['visual_type'] ?? '',
        ]);
    } else {
        studio_update_whatsapp_conversation($studio, [
            'conversation_id' => (int)$conversation['id'],
            'ai_last_status' => 'IA sem resposta: ' . mb_substr((string)($result['error'] ?? 'erro'), 0, 120),
            'ai_last_message_id' => $messageId,
            'ai_last_at' => date('Y-m-d H:i:s'),
        ]);
        worker_log('IA falhou', ['conversationId' => $conversationId, 'messageId' => $messageId, 'error' => $result['error'] ?? 'erro']);
    }
} catch (Throwable $e) {
    try {
        if (!empty($studio) && is_array($studio) && !empty($conversation['id'])) {
            studio_update_whatsapp_conversation($studio, [
                'conversation_id' => (int)$conversation['id'],
                'ai_last_status' => 'IA sem resposta: ' . mb_substr($e->getMessage(), 0, 120),
            ]);
        }
    } catch (Throwable) {
    }
    worker_log('Erro fatal', ['error' => $e->getMessage()]);
    exit(1);
}
