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

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) {
        $headers = [];
    }
    $normalizedHeaders = [];
    foreach ($headers as $name => $value) {
        $key = strtolower((string)$name);
        if ($key === 'authorization') {
            $normalizedHeaders[$key] = 'redacted';
            continue;
        }
        if ($key === 'x-hub-signature-256' && is_string($value)) {
            $normalizedHeaders[$key] = strlen($value) > 12 ? substr($value, 0, 12) . '…' : $value;
            continue;
        }
        $normalizedHeaders[$key] = is_array($value) ? $value : (string)$value;
    }

    whatsapp_webhook_log([
        'type' => 'raw_post',
        'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'headers' => $normalizedHeaders,
        'payload' => function_exists('studio_whatsapp_redact_for_log') ? studio_whatsapp_redact_for_log($payload) : $payload,
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
                if (studio_whatsapp_provider($studio) === 'official') {
                    return $studio;
                }
            } catch (Throwable) {
                continue;
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
        $result = studio_record_whatsapp_message($studio, studio_normalize_whatsapp_message_payload([
            'statusUpdate' => true,
            'messageId' => $messageId,
            'remoteJid' => $recipientId,
            'status' => $state,
        ]));
        studio_whatsapp_event_log($studio, [
            'provider' => 'official',
            'event_type' => 'official_webhook_status',
            'direction' => 'system',
            'phone' => $recipientId,
            'message_id' => $messageId,
            'status' => $state,
            'payload' => ['status' => $status, 'updated' => (int)($result['updated'] ?? 0)],
        ]);
    } catch (Throwable $e) {
        whatsapp_webhook_log(['type' => 'crm_record_status_error', 'error' => $e->getMessage(), 'message_id' => $messageId]);
        studio_whatsapp_event_log($studio, [
            'provider' => 'official',
            'event_type' => 'official_webhook_status_error',
            'direction' => 'system',
            'phone' => $recipientId,
            'message_id' => $messageId,
            'status' => $state,
            'error' => $e->getMessage(),
            'payload' => ['status' => $status],
        ]);
    }
}

function whatsapp_official_media_config(array $studio): array
{
    $settings = studio_settings($studio);
    $zapConfig = studio_whatsapp_zap_local_config();
    $useZapLocalConfig = !empty($zapConfig)
        && trim((string)($zapConfig['access_token'] ?? '')) !== ''
        && trim((string)($zapConfig['api_version'] ?? '')) !== '';

    return [
        'access_token' => $useZapLocalConfig
            ? trim((string)$zapConfig['access_token'])
            : trim((string)($settings['whatsapp_official_access_token'] ?? '')),
        'api_version' => $useZapLocalConfig
            ? trim((string)$zapConfig['api_version'])
            : trim((string)($settings['whatsapp_official_api_version'] ?? 'v23.0')),
        'source' => $useZapLocalConfig ? 'zap_local_config' : 'crm_settings',
    ];
}

function whatsapp_official_extension_for_mime(string $mime, string $fallback = ''): string
{
    $fallbackExt = strtolower((string)pathinfo($fallback, PATHINFO_EXTENSION));
    if ($fallbackExt !== '') {
        return $fallbackExt;
    }

    return match (strtolower(trim(strtok($mime, ';') ?: $mime))) {
        'audio/ogg', 'audio/opus' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4', 'audio/aac', 'audio/m4a' => 'm4a',
        'audio/amr' => 'amr',
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
        default => 'bin',
    };
}

function whatsapp_official_download_media(array $studio, array $message, string $messageType): array
{
    $media = is_array($message[$messageType] ?? null) ? $message[$messageType] : [];
    $mediaId = trim((string)($media['id'] ?? ''));
    if ($mediaId === '') {
        return [];
    }

    $config = whatsapp_official_media_config($studio);
    $token = (string)$config['access_token'];
    $version = (string)$config['api_version'];
    if ($token === '') {
        whatsapp_webhook_log(['type' => 'official_media_download_skip', 'reason' => 'missing_access_token', 'media_id' => $mediaId, 'message_type' => $messageType]);
        studio_whatsapp_event_log($studio, [
            'provider' => 'official',
            'event_type' => 'official_media_download_error',
            'direction' => 'in',
            'message_id' => (string)($message['id'] ?? ''),
            'status' => 'missing_access_token',
            'error' => 'Access token ausente para baixar midia oficial.',
            'payload' => ['media_id' => $mediaId, 'message_type' => $messageType],
        ]);
        return [];
    }

    $metaUrl = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($mediaId);
    $ch = curl_init($metaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 25,
    ]);
    $rawMeta = curl_exec($ch);
    $metaErrno = curl_errno($ch);
    $metaError = curl_error($ch);
    $metaStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $meta = json_decode((string)$rawMeta, true);
    if ($metaErrno || !is_array($meta) || $metaStatus >= 400 || empty($meta['url'])) {
        whatsapp_webhook_log([
            'type' => 'official_media_metadata_error',
            'media_id' => $mediaId,
            'message_type' => $messageType,
            'status' => $metaStatus,
            'error' => $metaError ?: (string)($meta['error']['message'] ?? 'metadata_url_missing'),
        ]);
        studio_whatsapp_event_log($studio, [
            'provider' => 'official',
            'event_type' => 'official_media_download_error',
            'direction' => 'in',
            'message_id' => (string)($message['id'] ?? ''),
            'status' => (string)$metaStatus,
            'error' => $metaError ?: (string)($meta['error']['message'] ?? 'metadata_url_missing'),
            'payload' => ['media_id' => $mediaId, 'message_type' => $messageType, 'step' => 'metadata'],
        ]);
        return [];
    }

    $downloadUrl = (string)$meta['url'];
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 45,
    ]);
    $binary = curl_exec($ch);
    $downloadErrno = curl_errno($ch);
    $downloadError = curl_error($ch);
    $downloadStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($downloadErrno || $binary === false || $binary === '' || $downloadStatus >= 400) {
        whatsapp_webhook_log([
            'type' => 'official_media_download_error',
            'media_id' => $mediaId,
            'message_type' => $messageType,
            'status' => $downloadStatus,
            'error' => $downloadError ?: 'empty_media_download',
        ]);
        studio_whatsapp_event_log($studio, [
            'provider' => 'official',
            'event_type' => 'official_media_download_error',
            'direction' => 'in',
            'message_id' => (string)($message['id'] ?? ''),
            'status' => (string)$downloadStatus,
            'error' => $downloadError ?: 'empty_media_download',
            'payload' => ['media_id' => $mediaId, 'message_type' => $messageType, 'step' => 'binary'],
        ]);
        return [];
    }

    $mime = trim((string)($meta['mime_type'] ?? $media['mime_type'] ?? $contentType));
    if (str_contains($mime, ';')) {
        $mime = trim(strtok($mime, ';'));
    }
    $mime = $mime !== '' ? $mime : 'application/octet-stream';
    $fileName = trim((string)($media['filename'] ?? $media['file_name'] ?? ''));
    if ($fileName === '') {
        $fileName = $messageType . '_' . $mediaId . '.' . whatsapp_official_extension_for_mime($mime);
    }

    whatsapp_webhook_log([
        'type' => 'official_media_download_ok',
        'media_id' => $mediaId,
        'message_type' => $messageType,
        'mime' => $mime,
        'bytes' => strlen((string)$binary),
        'source' => (string)$config['source'],
    ]);
    studio_whatsapp_event_log($studio, [
        'provider' => 'official',
        'event_type' => 'official_media_download_ok',
        'direction' => 'in',
        'message_id' => (string)($message['id'] ?? ''),
        'status' => 'ok',
        'payload' => ['media_id' => $mediaId, 'message_type' => $messageType, 'mime' => $mime, 'bytes' => strlen((string)$binary)],
    ]);

    return [
        'mediaBase64' => base64_encode((string)$binary),
        'mediaMime' => $mime,
        'mediaFileName' => $fileName,
        'mediaId' => $mediaId,
    ];
}

function whatsapp_official_update_duplicate_media(array $studio, string $messageId, array $mediaPayload, string $messageType): void
{
    if ($messageId === '' || empty($mediaPayload['mediaBase64'])) {
        return;
    }

    try {
        $stored = studio_store_whatsapp_media(
            $studio,
            0,
            (string)$mediaPayload['mediaBase64'],
            (string)($mediaPayload['mediaMime'] ?? ''),
            $messageType,
            (string)($mediaPayload['mediaFileName'] ?? '')
        );
        $pdo = studio_db($studio);
        $stmt = $pdo->prepare(
            'UPDATE whatsapp_messages
             SET media_url = ?, media_mime = ?, media_file_name = ?, media_file_path = ?, message_type = ?
             WHERE message_id = ? AND (media_url IS NULL OR media_url = "")'
        );
        $stmt->execute([
            (string)($stored['relativePath'] ?? ''),
            (string)($stored['mime'] ?? ($mediaPayload['mediaMime'] ?? '')),
            (string)($stored['fileName'] ?? ($mediaPayload['mediaFileName'] ?? '')),
            (string)($stored['relativePath'] ?? ''),
            $messageType,
            $messageId,
        ]);
        whatsapp_webhook_log(['type' => 'official_duplicate_media_update', 'message_id' => $messageId, 'updated' => $stmt->rowCount()]);
    } catch (Throwable $e) {
        whatsapp_webhook_log(['type' => 'official_duplicate_media_update_error', 'message_id' => $messageId, 'error' => $e->getMessage()]);
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
    $messageType = strtolower($type !== '' ? $type : 'texto');
    $mediaPayload = in_array($messageType, ['audio', 'image', 'video', 'document', 'sticker'], true)
        ? whatsapp_official_download_media($studio, $message, $messageType)
        : [];
    whatsapp_webhook_log([
        'type' => 'crm_record_message_attempt',
        'from' => $from,
        'message_id' => $messageId,
        'message_type' => $messageType,
        'media_downloaded' => !empty($mediaPayload['mediaBase64']),
        'text_body' => $body !== '' ? $body : '[' . $messageType . ']',
        'studio_id' => (int)$studio['id'],
    ]);
    try {
        $recordPayload = studio_normalize_whatsapp_message_payload([
            'phone_number_id' => (string)($message['phone_number_id'] ?? ''),
            'wa_id' => $from,
            'from' => $from,
            'numero' => $from,
            'name' => $name,
            'body' => $body,
            'message_id' => $messageId,
            'remote_jid' => $from,
            'from_me' => false,
            'sender_type' => 'customer',
            'timestamp' => (int)($message['timestamp'] ?? time()),
            'message_type' => $messageType,
            'media_base64' => (string)($mediaPayload['mediaBase64'] ?? ''),
            'media_mime' => (string)($mediaPayload['mediaMime'] ?? ''),
            'media_file_name' => (string)($mediaPayload['mediaFileName'] ?? ''),
            'media_file_path' => (string)($mediaPayload['mediaFilePath'] ?? ''),
            'media_url' => (string)($mediaPayload['mediaUrl'] ?? ''),
        ]);
        if (!empty($mediaPayload)) {
            $recordPayload += $mediaPayload;
        }
        $result = studio_record_whatsapp_message($studio, $recordPayload);
        if (!empty($result['duplicate']) && !empty($mediaPayload['mediaBase64'])) {
            whatsapp_official_update_duplicate_media($studio, $messageId, $mediaPayload, $messageType);
        }
        studio_whatsapp_event_log($studio, [
            'provider' => 'official',
            'event_type' => 'official_webhook_message',
            'direction' => 'in',
            'phone' => $from,
            'message_id' => $messageId,
            'conversation_id' => (int)($result['conversation_id'] ?? 0),
            'status' => !empty($result['duplicate']) ? 'duplicate' : 'received',
            'payload' => [
                'message_type' => $messageType,
                'media_downloaded' => !empty($mediaPayload['mediaBase64']),
                'has_text' => $body !== '',
                'contact_name' => $name,
            ],
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
        studio_whatsapp_event_log($studio, [
            'provider' => 'official',
            'event_type' => 'official_webhook_message_error',
            'direction' => 'in',
            'phone' => $from,
            'message_id' => $messageId,
            'status' => 'error',
            'error' => $e->getMessage(),
            'payload' => [
                'message_type' => $messageType,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ],
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
    studio_whatsapp_event_log($studio, [
        'provider' => 'official',
        'event_type' => 'official_webhook_verified',
        'direction' => 'system',
        'status' => 'ok',
        'payload' => ['mode' => $mode, 'challenge_length' => strlen($challenge)],
    ]);
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

    // TODO(migration): this branch still accepts the legacy Baileys-shaped payload for older collectors.
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

        $changeField = (string)($change['field'] ?? '');
        $deliveryKind = $messages ? 'messages' : ($statuses ? 'statuses' : ($contacts ? 'contacts' : 'other'));
        whatsapp_webhook_log([
            'type' => 'change_received',
            'phone_number_id' => $phoneNumberId,
            'field' => $changeField,
            'delivery_kind' => $deliveryKind,
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
