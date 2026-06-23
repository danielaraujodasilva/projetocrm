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

function whatsapp_official_known_config(): array
{
    $config = [
        'phone_number_id' => '1186818641175044',
        'verify_token' => 'zap_crm_daniel_2026',
        'waba_id' => '1908414750031996',
    ];

    $path = APP_BASE_PATH . '/storage/zap_api_config.local.json';
    if (is_file($path)) {
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            foreach (['phone_number_id', 'verify_token', 'waba_id'] as $key) {
                $value = trim((string)($decoded[$key] ?? ''));
                if ($value !== '') {
                    $config[$key] = $value;
                }
            }
        }
    }

    return $config;
}

function whatsapp_first_studio(): ?array
{
    $studios = list_studios();
    foreach ($studios as $studio) {
        if (is_array($studio)) {
            return $studio;
        }
    }
    return null;
}

function find_official_whatsapp_studio_by_verify_token(string $verifyToken): ?array
{
    $known = whatsapp_official_known_config();
    if ($verifyToken !== '' && hash_equals((string)$known['verify_token'], $verifyToken)) {
        $studio = whatsapp_first_studio();
        if ($studio) {
            return $studio;
        }
    }

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
        $studio = whatsapp_first_studio();
        if ($studio) {
            return $studio;
        }
    }

    return null;
}

function find_official_whatsapp_studio_by_phone_id(string $phoneNumberId): ?array
{
    if ($phoneNumberId === '') {
        return null;
    }

    $known = whatsapp_official_known_config();
    if ($phoneNumberId === (string)$known['phone_number_id']) {
        $studio = whatsapp_first_studio();
        if ($studio) {
            whatsapp_webhook_log([
                'type' => 'studio_lookup_fallback_known_phone_id',
                'phone_number_id' => $phoneNumberId,
                'studio_id' => (int)($studio['id'] ?? 0),
                'studio_slug' => (string)($studio['slug'] ?? ''),
            ]);
            return $studio;
        }
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
        } catch (Throwable $e) {
            whatsapp_webhook_log([
                'type' => 'studio_lookup_settings_error',
                'phone_number_id' => $phoneNumberId,
                'studio_id' => (int)($studio['id'] ?? 0),
                'error' => $e->getMessage(),
            ]);
            continue;
        }
        $configuredPhoneId = trim((string)($settings['whatsapp_official_phone_number_id'] ?? ''));
        $configuredTestPhoneId = trim((string)($settings['whatsapp_official_test_phone_number_id'] ?? ''));
        if ($phoneNumberId === $configuredPhoneId || $phoneNumberId === $configuredTestPhoneId) {
            return $studio;
        }
    }

    whatsapp_webhook_log([
        'type' => 'studio_lookup_failed',
        'phone_number_id' => $phoneNumberId,
        'known_phone_number_id' => (string)$known['phone_number_id'],
        'studios_count' => count(list_studios()),
    ]);

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
        $result = studio_record_whatsapp_message($studio, [
            'statusUpdate' => true,
            'messageId' => $messageId,
            'remoteJid' => $recipientId,
            'status' => $state,
        ]);
        whatsapp_webhook_log([
            'type' => 'crm_record_status_ok',
            'message_id' => $messageId,
            'status' => $state,
            'result' => $result,
            'studio_id' => (int)($studio['id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        whatsapp_webhook_log(['type' => 'crm_record_status_error', 'error' => $e->getMessage(), 'message_id' => $messageId]);
    }
}

function whatsapp_official_record_message(array $studio, array $message, array $contacts = []): void
{
    $from = preg_replace('/\D+/', '', (string)($message['from'] ?? '')) ?: '';
    if ($from === '') {
        whatsapp_webhook_log(['type' => 'incoming_message_without_from', 'message' => $message]);
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
        $result = studio_record_whatsapp_message($studio, [
            'numero' => $from,
            'phone' => $from,
            'name' => $name,
            'mensagem' => $body !== '' ? $body : '[' . $type . ']',
            'body' => $body !== '' ? $body : '[' . $type . ']',
            'fromMe' => false,
            'senderType' => 'customer',
            'messageId' => (string)($message['id'] ?? ''),
            'remoteJid' => $from,
            'timestamp' => (int)($message['timestamp'] ?? time()),
            'tipoMensagem' => $type !== '' ? $type : 'texto',
            'messageType' => $type !== '' ? $type : 'texto',
        ]);
        whatsapp_webhook_log([
            'type' => 'crm_record_message_ok',
            'from' => $from,
            'message_id' => (string)($message['id'] ?? ''),
            'conversation_id' => (int)($result['conversation_id'] ?? 0),
            'duplicate' => !empty($result['duplicate']),
            'studio_id' => (int)($studio['id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        whatsapp_webhook_log([
            'type' => 'crm_record_message_error',
            'error' => $e->getMessage(),
            'from' => $from,
            'message_id' => (string)($message['id'] ?? ''),
            'message_type' => $type,
            'studio_id' => (int)($studio['id'] ?? 0),
        ]);
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
            } else {
                whatsapp_webhook_log([
                    'type' => 'incoming_message_without_studio',
                    'phone_number_id' => $phoneNumberId,
                    'from' => (string)($message['from'] ?? ''),
                    'message_id' => (string)($message['id'] ?? ''),
                ]);
            }
        }

        if (!$studio) {
            continue;
        }

        studio_event((int)$studio['id'], 'whatsapp_official_webhook_event', 'Evento recebido da API oficial do WhatsApp.');
    }
}

webhook_json(['ok' => true]);
