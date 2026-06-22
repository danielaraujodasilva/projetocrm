<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function webhook_text(string $text, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

function webhook_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function whatsapp_webhook_log_path(): string
{
    return APP_BASE_PATH . '/storage/whatsapp_webhook_events.log';
}

function whatsapp_webhook_log(array $event): void
{
    $dir = dirname(whatsapp_webhook_log_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $event['logged_at'] = date('c');
    $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents(whatsapp_webhook_log_path(), $line, FILE_APPEND | LOCK_EX);
}

function find_official_whatsapp_studio_by_verify_token(string $verifyToken): ?array
{
    foreach (list_studios() as $studio) {
        if (!is_array($studio)) {
            continue;
        }
        try {
            $settings = studio_settings($studio);
        } catch (Throwable) {
            continue;
        }
        if (trim((string)($settings['whatsapp_official_verify_token'] ?? '')) === $verifyToken) {
            return $studio;
        }
    }

    $acceptedTestTokens = [
        'zap_crm_daniel_2026',
        'Luna*123',
    ];

    if (in_array($verifyToken, $acceptedTestTokens, true)) {
        $studios = list_studios();
        if (count($studios) === 1 && is_array($studios[0] ?? null)) {
            return $studios[0];
        }
        foreach ($studios as $studio) {
            if (!is_array($studio)) {
                continue;
            }
            try {
                $settings = studio_settings($studio);
            } catch (Throwable) {
                continue;
            }
            if ((string)($settings['whatsapp_provider'] ?? 'baileys') === 'official') {
                return $studio;
            }
        }
    }

    return null;
}

function find_official_whatsapp_studio_by_phone_id(string $phoneNumberId): ?array
{
    if ($phoneNumberId === '') {
        return null;
    }
    foreach (list_studios() as $studio) {
        if (!is_array($studio)) {
            continue;
        }
        try {
            if (function_exists('crm_whatsapp_official_apply_defaults')) {
                crm_whatsapp_official_apply_defaults($studio);
            }
            $settings = studio_settings($studio);
        } catch (Throwable) {
            continue;
        }
        $configuredPhoneId = trim((string)($settings['whatsapp_official_phone_number_id'] ?? ''));
        $configuredTestPhoneId = trim((string)($settings['whatsapp_official_test_phone_number_id'] ?? ''));
        if ($phoneNumberId === $configuredPhoneId || $phoneNumberId === $configuredTestPhoneId) {
            return $studio;
        }
    }
    return null;
}

function whatsapp_official_record_status(array $studio, array $status): void
{
    $messageId = (string)($status['id'] ?? '');
    $recipientId = (string)($status['recipient_id'] ?? '');
    $state = (string)($status['status'] ?? '');
    if ($messageId === '' || $state === '') {
        return;
    }
    try {
        studio_record_whatsapp_message($studio, [
            'statusUpdate' => true,
            'messageId' => $messageId,
            'remoteJid' => $recipientId,
            'status' => $state,
        ]);
    } catch (Throwable $e) {
        whatsapp_webhook_log(['type' => 'crm_record_status_error', 'error' => $e->getMessage(), 'message_id' => $messageId]);
    }
}

function whatsapp_official_record_message(array $studio, array $message, array $contacts = []): void
{
    $from = preg_replace('/\D+/', '', (string)($message['from'] ?? '')) ?: '';
    if ($from === '') {
        return;
    }

    $name = '';
    foreach ($contacts as $contact) {
        if (!is_array($contact)) {
            continue;
        }
        if ((string)($contact['wa_id'] ?? '') === $from) {
            $profile = is_array($contact['profile'] ?? null) ? $contact['profile'] : [];
            $name = trim((string)($profile['name'] ?? ''));
            break;
        }
    }

    $type = (string)($message['type'] ?? 'text');
    $body = '';
    if ($type === 'text' && is_array($message['text'] ?? null)) {
        $body = (string)($message['text']['body'] ?? '');
    } elseif (is_array($message[$type] ?? null)) {
        $body = trim((string)($message[$type]['caption'] ?? ''));
    }

    try {
        studio_record_whatsapp_message($studio, [
            'numero' => $from,
            'name' => $name,
            'mensagem' => $body !== '' ? $body : '[' . $type . ']',
            'fromMe' => false,
            'senderType' => 'customer',
            'messageId' => (string)($message['id'] ?? ''),
            'remoteJid' => $from,
            'timestamp' => (int)($message['timestamp'] ?? time()),
            'tipoMensagem' => $type !== '' ? $type : 'texto',
        ]);
    } catch (Throwable $e) {
        whatsapp_webhook_log(['type' => 'crm_record_message_error', 'error' => $e->getMessage(), 'from' => $from]);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $mode = trim((string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? ''));
    $challenge = trim((string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? ''));
    $verifyToken = trim((string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''));

    if ($mode === '' || $challenge === '' || $verifyToken === '') {
        webhook_text('Missing verification parameters', 400);
    }

    if (!in_array($mode, ['subscribe', 'webhook'], true)) {
        webhook_text('Unsupported mode', 400);
    }

    $studio = find_official_whatsapp_studio_by_verify_token($verifyToken);
    if (!$studio) {
        webhook_text('Verification token mismatch', 403);
    }

    studio_event((int)$studio['id'], 'whatsapp_official_webhook_verified', 'Webhook oficial validado com sucesso pela Meta.');
    whatsapp_webhook_log([
        'type' => 'verification',
        'mode' => $mode,
        'verify_token' => $verifyToken,
        'challenge' => $challenge,
        'studio_id' => (int)$studio['id'],
    ]);
    webhook_text($challenge, 200);
}

if ($method !== 'POST') {
    webhook_text('Method not allowed', 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = [];
}

whatsapp_webhook_log([
    'type' => 'raw_post',
    'payload' => $payload,
]);

$entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
foreach ($entries as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
    foreach ($changes as $change) {
        if (!is_array($change)) {
            continue;
        }
        $value = is_array($change['value'] ?? null) ? $change['value'] : [];
        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
        $phoneNumberId = trim((string)($metadata['phone_number_id'] ?? ''));
        $verifyToken = trim((string)($value['verify_token'] ?? ''));

        $studio = find_official_whatsapp_studio_by_phone_id($phoneNumberId);
        if (!$studio && $verifyToken !== '') {
            $studio = find_official_whatsapp_studio_by_verify_token($verifyToken);
        }

        $statuses = is_array($value['statuses'] ?? null) ? $value['statuses'] : [];
        foreach ($statuses as $status) {
            if (!is_array($status)) {
                continue;
            }
            whatsapp_webhook_log([
                'type' => 'message_status',
                'phone_number_id' => $phoneNumberId,
                'message_id' => (string)($status['id'] ?? ''),
                'recipient_id' => (string)($status['recipient_id'] ?? ''),
                'status' => (string)($status['status'] ?? ''),
                'timestamp' => (string)($status['timestamp'] ?? ''),
                'errors' => $status['errors'] ?? [],
                'pricing' => $status['pricing'] ?? [],
                'studio_id' => is_array($studio) ? (int)$studio['id'] : null,
            ]);
            if ($studio) {
                whatsapp_official_record_status($studio, $status);
            }
        }

        $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
        $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            whatsapp_webhook_log([
                'type' => 'incoming_message',
                'phone_number_id' => $phoneNumberId,
                'from' => (string)($message['from'] ?? ''),
                'message_id' => (string)($message['id'] ?? ''),
                'message_type' => (string)($message['type'] ?? ''),
                'text' => is_array($message['text'] ?? null) ? (string)($message['text']['body'] ?? '') : '',
                'studio_id' => is_array($studio) ? (int)$studio['id'] : null,
            ]);
            if ($studio) {
                whatsapp_official_record_message($studio, $message, $contacts);
            }
        }

        if (!$studio) {
            continue;
        }

        studio_event((int)$studio['id'], 'whatsapp_official_webhook_event', 'Evento recebido da API oficial do WhatsApp.');
    }
}

webhook_json(['ok' => true]);
