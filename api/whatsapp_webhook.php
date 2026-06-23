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
    $path = APP_BASE_PATH . '/storage/zap_api_config.local.json';
    $config = [];
    if (is_file($path)) {
        $raw = file_get_contents($path);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
    }

    return [
        'phone_number_id' => trim((string)($config['phone_number_id'] ?? '1186818641175044')),
        'verify_token' => trim((string)($config['verify_token'] ?? 'zap_crm_daniel_2026')),
        'waba_id' => trim((string)($config['waba_id'] ?? '1908414750031996')),
        'raw' => $config,
    ];
}

function whatsapp_webhook_capture_request(): array
{
    $rawBody = (string)file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        $payload = [];
        whatsapp_webhook_log([
            'type' => 'invalid_json',
            'raw_body' => $rawBody,
            'json_last_error_msg' => json_last_error_msg(),
        ]);
    }

    whatsapp_webhook_log([
        'type' => 'raw_post',
        'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'payload' => $payload,
    ]);

    return $payload;
}

function whatsapp_webhook_respond_ok(): never
{
    webhook_json(['ok' => true], 200);
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

function find_whatsapp_studio_by_session_key(string $sessionKey): ?array
{
    $sessionKey = trim($sessionKey);
    if ($sessionKey === '') {
        return null;
    }
    $safeSessionKey = function_exists('studio_whatsapp_safe_session_key') ? studio_whatsapp_safe_session_key($sessionKey) : $sessionKey;
    foreach (list_studios() as $studio) {
        if (!is_array($studio)) {
            continue;
        }
        try {
            $studioSessionKey = studio_session_key($studio);
            if ($studioSessionKey === $sessionKey || $studioSessionKey === $safeSessionKey) {
                return $studio;
            }
        } catch (Throwable) {
            continue;
        }
    }
    return null;
}

function find_official_whatsapp_studio_by_phone_id(string $phoneNumberId): ?array
{
    $known = whatsapp_official_known_config();
    $knownPhoneId = trim((string)($known['phone_number_id'] ?? ''));
    $matches = [];
    $studios = list_studios();

    foreach ($studios as $studio) {
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
        if ($phoneNumberId !== '' && ($phoneNumberId === $configuredPhoneId || $phoneNumberId === $configuredTestPhoneId)) {
            $matches[] = ['studio' => $studio, 'reason' => 'crm_settings'];
        }
    }

    if (!$matches && $knownPhoneId !== '' && $phoneNumberId !== '' && $phoneNumberId === $knownPhoneId) {
        foreach ($studios as $studio) {
            if (is_array($studio)) {
                $matches[] = ['studio' => $studio, 'reason' => 'zap_local_config'];
                break;
            }
        }
    }

    if (!$matches && count($studios) === 1 && is_array($studios[0] ?? null)) {
        $matches[] = ['studio' => $studios[0], 'reason' => 'single_studio_fallback'];
    }

    $resultStudio = $matches[0]['studio'] ?? null;
    whatsapp_webhook_log([
        'type' => 'studio_lookup_result',
        'phone_number_id_received' => $phoneNumberId,
        'known_phone_number_id' => $knownPhoneId,
        'studio_id' => is_array($resultStudio) ? (int)$resultStudio['id'] : null,
        'studio_slug' => is_array($resultStudio) ? (string)$resultStudio['slug'] : null,
        'match_reason' => $matches[0]['reason'] ?? 'failed',
    ]);

    return is_array($resultStudio) ? $resultStudio : null;
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

    $messageId = (string)($message['id'] ?? '');
    $messageType = $type !== '' ? $type : 'texto';
    whatsapp_webhook_log([
        'type' => 'crm_record_message_attempt',
        'from' => $from,
        'message_id' => $messageId,
        'message_type' => $messageType,
        'text_body' => $body !== '' ? $body : '[' . $messageType . ']',
        'studio_id' => (int)$studio['id'],
    ]);
    try {
        $result = studio_record_whatsapp_message($studio, [
            'numero' => $from,
            'name' => $name,
            'mensagem' => $body !== '' ? $body : '[' . $messageType . ']',
            'fromMe' => false,
            'senderType' => 'customer',
            'messageId' => $messageId,
            'remoteJid' => $from,
            'timestamp' => (int)($message['timestamp'] ?? time()),
            'tipoMensagem' => $messageType,
        ]);
        whatsapp_webhook_log([
            'type' => 'crm_record_message_ok',
            'conversation_id' => (int)($result['conversation_id'] ?? 0),
            'duplicate' => !empty($result['duplicate']) ? 'SIM' : 'NAO',
            'message_id' => $messageId,
            'from' => $from,
            'studio_id' => (int)$studio['id'],
        ]);
    } catch (Throwable $e) {
        whatsapp_webhook_log([
            'type' => 'crm_record_message_error',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'from' => $from,
            'message_id' => $messageId,
            'message_type' => $messageType,
            'studio_id' => (int)$studio['id'],
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

$payload = whatsapp_webhook_capture_request();

$studioSessionKey = trim((string)($payload['studioSessionKey'] ?? $payload['sessionKey'] ?? $payload['studio_session_key'] ?? ''));
$webhookToken = trim((string)($payload['webhookToken'] ?? $payload['webhook_token'] ?? ''));
$statusEvent = !empty($payload['statusEvent']);
if ($studioSessionKey !== '' || $webhookToken !== '') {
    $studio = null;
    if ($studioSessionKey !== '') {
        $studio = find_whatsapp_studio_by_session_key($studioSessionKey);
    }
    if (!$studio && $webhookToken !== '') {
        foreach (list_studios() as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            try {
                if (studio_whatsapp_webhook_token($candidate) === $webhookToken) {
                    $studio = $candidate;
                    break;
                }
            } catch (Throwable) {
                continue;
            }
        }
    }

    if (!$studio) {
        whatsapp_webhook_log(['type' => 'incoming_message_without_studio', 'session_key' => $studioSessionKey, 'webhook_token_present' => $webhookToken !== '']);
        whatsapp_webhook_respond_ok();
    }

    if ($statusEvent) {
        whatsapp_webhook_log([
            'type' => 'status_event',
            'studio_id' => (int)$studio['id'],
            'studio_session_key' => $studioSessionKey,
            'status' => (string)($payload['status'] ?? ''),
            'message' => (string)($payload['message'] ?? ''),
        ]);
        whatsapp_webhook_respond_ok();
    }

    $numero = trim((string)($payload['numero'] ?? $payload['phone'] ?? ''));
    $mensagem = trim((string)($payload['mensagem'] ?? $payload['message'] ?? $payload['body'] ?? ''));
    $remoteJid = trim((string)($payload['remoteJid'] ?? $payload['jidCompleto'] ?? ''));
    $messageId = trim((string)($payload['messageId'] ?? $payload['message_id'] ?? ''));
    $timestamp = (int)($payload['timestamp'] ?? time());
    $tipoMensagem = trim((string)($payload['tipoMensagem'] ?? $payload['messageType'] ?? 'texto')) ?: 'texto';
    $fromMe = !empty($payload['fromMe']);

    whatsapp_webhook_log([
        'type' => 'baileys_message',
        'studio_id' => (int)$studio['id'],
        'studio_session_key' => $studioSessionKey,
        'numero' => $numero,
        'remote_jid' => $remoteJid,
        'message_id' => $messageId,
        'from_me' => $fromMe,
        'message_type' => $tipoMensagem,
    ]);

    if ($numero !== '' || $remoteJid !== '' || $mensagem !== '' || $messageId !== '') {
        try {
            studio_record_whatsapp_message($studio, [
                'numero' => $numero !== '' ? $numero : $remoteJid,
                'mensagem' => $mensagem,
                'fromMe' => $fromMe,
                'senderType' => $fromMe ? 'human' : 'customer',
                'messageId' => $messageId,
                'remoteJid' => $remoteJid !== '' ? $remoteJid : $numero,
                'timestamp' => $timestamp,
                'tipoMensagem' => $tipoMensagem,
                'mediaUrl' => trim((string)($payload['mediaUrl'] ?? '')),
                'mediaMime' => trim((string)($payload['mediaMime'] ?? '')),
                'mediaFileName' => trim((string)($payload['mediaFileName'] ?? '')),
            ]);
        } catch (Throwable $e) {
            whatsapp_webhook_log([
                'type' => 'crm_record_message_error',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'from' => $numero,
                'message_id' => $messageId,
                'message_type' => $tipoMensagem,
                'studio_id' => (int)$studio['id'],
            ]);
        }
    }

    whatsapp_webhook_respond_ok();
}

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
        $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
        $statuses = is_array($value['statuses'] ?? null) ? $value['statuses'] : [];
        $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];

        whatsapp_webhook_log([
            'type' => 'change_received',
            'phone_number_id' => $phoneNumberId,
            'has_messages' => $messages ? 'SIM' : 'NAO',
            'messages_count' => count($messages),
            'has_statuses' => $statuses ? 'SIM' : 'NAO',
            'statuses_count' => count($statuses),
            'contacts_count' => count($contacts),
        ]);

        $studio = find_official_whatsapp_studio_by_phone_id($phoneNumberId);
        if (!$studio && $verifyToken !== '') {
            $studio = find_official_whatsapp_studio_by_verify_token($verifyToken);
        }

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

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $messageType = (string)($message['type'] ?? '');
            $textBody = '';
            if ($messageType === 'text' && is_array($message['text'] ?? null)) {
                $textBody = (string)($message['text']['body'] ?? '');
            }
            whatsapp_webhook_log([
                'type' => 'incoming_message_seen',
                'phone_number_id' => $phoneNumberId,
                'from' => (string)($message['from'] ?? ''),
                'message_id' => (string)($message['id'] ?? ''),
                'message_type' => $messageType,
                'text_body' => $textBody,
                'contacts' => $contacts,
                'studio_found' => $studio ? 'SIM' : 'NAO',
            ]);
            if ($studio) {
                try {
                    whatsapp_official_record_message($studio, $message, $contacts);
                } catch (Throwable $e) {
                    whatsapp_webhook_log([
                        'type' => 'crm_record_message_error',
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'from' => (string)($message['from'] ?? ''),
                        'message_id' => (string)($message['id'] ?? ''),
                        'message_type' => $messageType,
                        'studio_id' => (int)$studio['id'],
                    ]);
                }
            }
        }

        if (!$studio) {
            continue;
        }

        studio_event((int)$studio['id'], 'whatsapp_official_webhook_event', 'Evento recebido da API oficial do WhatsApp.');
    }
}

whatsapp_webhook_respond_ok();
