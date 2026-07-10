<?php

declare(strict_types=1);

function google_calendar_configured(): bool
{
    $config = app_config('google_calendar');
    return trim((string)($config['client_id'] ?? '')) !== ''
        && trim((string)($config['client_secret'] ?? '')) !== ''
        && trim((string)($config['redirect_uri'] ?? '')) !== '';
}

function google_calendar_ensure_schema(array $studio): void
{
    static $checked = [];
    $key = (int)($studio['id'] ?? 0) . '|' . (string)($studio['database_name'] ?? '');
    if (isset($checked[$key])) {
        return;
    }
    $checked[$key] = true;

    $pdo = studio_db($studio);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `google_calendar_integration` (
            `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `calendar_id` VARCHAR(255) NULL,
            `calendar_name` VARCHAR(255) NULL,
            `account_email` VARCHAR(255) NULL,
            `access_token_encrypted` MEDIUMTEXT NULL,
            `refresh_token_encrypted` MEDIUMTEXT NULL,
            `access_token_expires_at` DATETIME NULL,
            `sync_token` MEDIUMTEXT NULL,
            `calendars_json` MEDIUMTEXT NULL,
            `last_sync_at` DATETIME NULL,
            `last_sync_status` VARCHAR(30) NULL,
            `last_sync_message` TEXT NULL,
            `last_sync_created` INT NOT NULL DEFAULT 0,
            `last_sync_updated` INT NOT NULL DEFAULT 0,
            `last_sync_unchanged` INT NOT NULL DEFAULT 0,
            `last_sync_cancelled` INT NOT NULL DEFAULT 0,
            `outbound_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `outbound_last_status` VARCHAR(30) NULL,
            `outbound_last_message` TEXT NULL,
            `outbound_last_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    foreach ([
        'ALTER TABLE `google_calendar_integration` ADD COLUMN IF NOT EXISTS `outbound_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_sync_cancelled`',
        'ALTER TABLE `google_calendar_integration` ADD COLUMN IF NOT EXISTS `outbound_last_status` VARCHAR(30) NULL AFTER `outbound_enabled`',
        'ALTER TABLE `google_calendar_integration` ADD COLUMN IF NOT EXISTS `outbound_last_message` TEXT NULL AFTER `outbound_last_status`',
        'ALTER TABLE `google_calendar_integration` ADD COLUMN IF NOT EXISTS `outbound_last_at` DATETIME NULL AFTER `outbound_last_message`',
        'ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `google_calendar_event_id` VARCHAR(255) NULL AFTER `import_uid`',
        'ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `google_calendar_id` VARCHAR(255) NULL AFTER `google_calendar_event_id`',
        'ALTER TABLE `appointments` ADD INDEX IF NOT EXISTS `idx_appointments_google_event` (`google_calendar_id`, `google_calendar_event_id`)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable) {
        }
    }
}

function google_calendar_integration(array $studio): array
{
    google_calendar_ensure_schema($studio);
    $row = studio_db($studio)->query('SELECT * FROM google_calendar_integration WHERE id = 1 LIMIT 1')->fetch();
    return is_array($row) ? $row : [];
}

function google_calendar_is_connected(array $integration): bool
{
    return trim((string)($integration['refresh_token_encrypted'] ?? '')) !== '';
}

function google_calendar_crypto_key(): string
{
    $secret = trim((string)(app_config('google_calendar')['client_secret'] ?? ''));
    if ($secret === '') {
        throw new RuntimeException('Credencial do Google Calendar não configurada.');
    }
    return hash('sha256', 'projetocrm-google-calendar|' . $secret, true);
}

function google_calendar_encrypt(string $value): string
{
    if ($value === '') {
        return '';
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $value,
        'aes-256-gcm',
        google_calendar_crypto_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if ($ciphertext === false) {
        throw new RuntimeException('Não foi possível proteger o token do Google.');
    }
    return 'gcm:' . base64_encode($nonce . $tag . $ciphertext);
}

function google_calendar_decrypt(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (!str_starts_with($value, 'gcm:')) {
        return $value;
    }
    $payload = base64_decode(substr($value, 4), true);
    if ($payload === false || strlen($payload) < 29) {
        throw new RuntimeException('Token armazenado do Google está inválido.');
    }
    $nonce = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $plain = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        google_calendar_crypto_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if ($plain === false) {
        throw new RuntimeException('Não foi possível abrir o token do Google.');
    }
    return $plain;
}

function google_calendar_http(string $method, string $url, array $headers = [], array|string|null $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Não foi possível iniciar a conexão com o Google.');
    }
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Falha ao acessar o Google Calendar: ' . $error);
    }
    $decoded = json_decode((string)$raw, true);
    $data = is_array($decoded) ? $decoded : [];
    if ($status < 200 || $status >= 300) {
        $message = trim((string)($data['error']['message'] ?? $data['error_description'] ?? ''));
        throw new RuntimeException(
            $message !== '' ? $message : 'Google Calendar respondeu com HTTP ' . $status . '.',
            $status
        );
    }
    return ['status' => $status, 'data' => $data];
}

function google_calendar_authorization_url(array $studio): string
{
    if (!google_calendar_configured()) {
        throw new RuntimeException('As credenciais OAuth do Google Calendar ainda não estão configuradas.');
    }
    $config = app_config('google_calendar');
    $state = bin2hex(random_bytes(24));
    $_SESSION['google_calendar_oauth'] ??= [];
    $_SESSION['google_calendar_oauth'][$state] = [
        'studio_id' => (int)$studio['id'],
        'expires_at' => time() + 900,
    ];

    return (string)$config['auth_uri'] . '?' . http_build_query([
        'client_id' => (string)$config['client_id'],
        'redirect_uri' => (string)$config['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', array_map('strval', $config['scopes'] ?? [])),
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'false',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

function google_calendar_exchange_code(string $code): array
{
    $config = app_config('google_calendar');
    $response = google_calendar_http(
        'POST',
        (string)$config['token_uri'],
        ['Content-Type: application/x-www-form-urlencoded'],
        [
            'code' => $code,
            'client_id' => (string)$config['client_id'],
            'client_secret' => (string)$config['client_secret'],
            'redirect_uri' => (string)$config['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]
    );
    return $response['data'];
}

function google_calendar_fetch_calendars(string $accessToken): array
{
    $calendars = [];
    $pageToken = '';
    do {
        $params = ['maxResults' => 250];
        if ($pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }
        $response = google_calendar_http(
            'GET',
            'https://www.googleapis.com/calendar/v3/users/me/calendarList?' . http_build_query($params),
            ['Authorization: Bearer ' . $accessToken]
        );
        foreach ($response['data']['items'] ?? [] as $calendar) {
            if (!is_array($calendar) || empty($calendar['id'])) {
                continue;
            }
            $calendars[] = [
                'id' => (string)$calendar['id'],
                'name' => (string)($calendar['summaryOverride'] ?? $calendar['summary'] ?? $calendar['id']),
                'primary' => !empty($calendar['primary']),
                'time_zone' => (string)($calendar['timeZone'] ?? 'America/Sao_Paulo'),
                'access_role' => (string)($calendar['accessRole'] ?? ''),
            ];
        }
        $pageToken = trim((string)($response['data']['nextPageToken'] ?? ''));
    } while ($pageToken !== '');

    return $calendars;
}

function google_calendar_store_connection(array $studio, array $tokens, array $calendars): void
{
    google_calendar_ensure_schema($studio);
    $current = google_calendar_integration($studio);
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        $refreshToken = google_calendar_decrypt((string)($current['refresh_token_encrypted'] ?? ''));
    }
    if ($accessToken === '' || $refreshToken === '') {
        throw new RuntimeException('O Google não retornou autorização offline. Remova o acesso anterior na conta Google e conecte novamente.');
    }
    $selected = null;
    $currentCalendarId = trim((string)($current['calendar_id'] ?? ''));
    if ($currentCalendarId !== '') {
        foreach ($calendars as $calendar) {
            if (hash_equals((string)($calendar['id'] ?? ''), $currentCalendarId)) {
                $selected = $calendar;
                break;
            }
        }
    }
    foreach ($calendars as $calendar) {
        if ($selected !== null) {
            break;
        }
        if (!empty($calendar['primary'])) {
            $selected = $calendar;
            break;
        }
    }
    $selected ??= $calendars[0] ?? ['id' => 'primary', 'name' => 'Agenda principal'];
    $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int)($tokens['expires_in'] ?? 3600) - 60));
    $accountEmail = str_contains((string)$selected['id'], '@') ? (string)$selected['id'] : '';

    $stmt = studio_db($studio)->prepare(
        'INSERT INTO google_calendar_integration
            (id, enabled, calendar_id, calendar_name, account_email, access_token_encrypted,
             refresh_token_encrypted, access_token_expires_at, sync_token, calendars_json,
             last_sync_status, last_sync_message, created_at, updated_at)
         VALUES
            (1, 1, ?, ?, ?, ?, ?, ?, NULL, ?, "connected", "Conta conectada. Aguardando primeira sincronização.", NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            enabled = 1, calendar_id = VALUES(calendar_id), calendar_name = VALUES(calendar_name),
            account_email = VALUES(account_email), access_token_encrypted = VALUES(access_token_encrypted),
            refresh_token_encrypted = VALUES(refresh_token_encrypted),
            access_token_expires_at = VALUES(access_token_expires_at), sync_token = NULL,
            calendars_json = VALUES(calendars_json), last_sync_status = "connected",
            last_sync_message = "Conta conectada. Aguardando primeira sincronização.", updated_at = NOW()'
    );
    $stmt->execute([
        (string)$selected['id'],
        (string)$selected['name'],
        $accountEmail,
        google_calendar_encrypt($accessToken),
        google_calendar_encrypt($refreshToken),
        $expiresAt,
        json_encode($calendars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function google_calendar_access_token(array $studio): string
{
    $integration = google_calendar_integration($studio);
    if (!google_calendar_is_connected($integration)) {
        throw new RuntimeException('Google Agenda ainda não está conectado.');
    }
    $expiresAt = strtotime((string)($integration['access_token_expires_at'] ?? '')) ?: 0;
    $accessToken = google_calendar_decrypt((string)($integration['access_token_encrypted'] ?? ''));
    if ($accessToken !== '' && $expiresAt > time() + 90) {
        return $accessToken;
    }

    $config = app_config('google_calendar');
    $refreshToken = google_calendar_decrypt((string)$integration['refresh_token_encrypted']);
    $response = google_calendar_http(
        'POST',
        (string)$config['token_uri'],
        ['Content-Type: application/x-www-form-urlencoded'],
        [
            'client_id' => (string)$config['client_id'],
            'client_secret' => (string)$config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]
    );
    $accessToken = trim((string)($response['data']['access_token'] ?? ''));
    if ($accessToken === '') {
        throw new RuntimeException('O Google não renovou o token de acesso.');
    }
    $newExpiresAt = date('Y-m-d H:i:s', time() + max(60, (int)($response['data']['expires_in'] ?? 3600) - 60));
    studio_db($studio)->prepare(
        'UPDATE google_calendar_integration
         SET access_token_encrypted = ?, access_token_expires_at = ?, updated_at = NOW()
         WHERE id = 1'
    )->execute([google_calendar_encrypt($accessToken), $newExpiresAt]);
    return $accessToken;
}

function google_calendar_select(array $studio, string $calendarId): void
{
    $integration = google_calendar_integration($studio);
    $calendars = json_decode((string)($integration['calendars_json'] ?? '[]'), true);
    $selected = null;
    foreach (is_array($calendars) ? $calendars : [] as $calendar) {
        if (is_array($calendar) && hash_equals((string)($calendar['id'] ?? ''), $calendarId)) {
            $selected = $calendar;
            break;
        }
    }
    if (!$selected) {
        throw new RuntimeException('Calendário selecionado não está disponível nessa conta.');
    }
    studio_db($studio)->prepare(
        'UPDATE google_calendar_integration
         SET calendar_id = ?, calendar_name = ?, sync_token = NULL,
             last_sync_status = "pending", last_sync_message = "Calendário alterado. Sincronização completa pendente.",
             updated_at = NOW()
         WHERE id = 1'
    )->execute([(string)$selected['id'], (string)$selected['name']]);
}

function google_calendar_disconnect(array $studio): void
{
    google_calendar_ensure_schema($studio);
    studio_db($studio)->prepare('DELETE FROM google_calendar_integration WHERE id = 1')->execute();
}

function google_calendar_set_enabled(array $studio, bool $enabled): void
{
    google_calendar_ensure_schema($studio);
    studio_db($studio)->prepare(
        'UPDATE google_calendar_integration SET enabled = ?, updated_at = NOW() WHERE id = 1'
    )->execute([$enabled ? 1 : 0]);
}

function google_calendar_set_outbound_enabled(array $studio, bool $enabled): void
{
    google_calendar_ensure_schema($studio);
    studio_db($studio)->prepare(
        'UPDATE google_calendar_integration
         SET outbound_enabled = ?, outbound_last_status = NULL, outbound_last_message = NULL,
             outbound_last_at = NULL, updated_at = NOW()
         WHERE id = 1'
    )->execute([$enabled ? 1 : 0]);
}

function google_calendar_outbound_enabled(array $studio): bool
{
    $integration = google_calendar_integration($studio);
    return google_calendar_is_connected($integration) && !empty($integration['outbound_enabled']);
}

function google_calendar_set_outbound_status(array $studio, string $status, string $message): void
{
    google_calendar_ensure_schema($studio);
    studio_db($studio)->prepare(
        'UPDATE google_calendar_integration
         SET outbound_last_status = ?, outbound_last_message = ?, outbound_last_at = NOW(), updated_at = NOW()
         WHERE id = 1'
    )->execute([$status, mb_substr($message, 0, 1000)]);
}

function google_calendar_api_datetime_to_ics(array $dateData): string
{
    $dateTime = trim((string)($dateData['dateTime'] ?? ''));
    if ($dateTime !== '') {
        try {
            return (new DateTimeImmutable($dateTime))
                ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
                ->format('Ymd\THis');
        } catch (Throwable) {
            return '';
        }
    }
    $date = trim((string)($dateData['date'] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? str_replace('-', '', $date) : '';
}

function google_calendar_api_event_item(array $event, string $calendarId): array
{
    $eventId = trim((string)($event['id'] ?? ''));
    $startData = is_array($event['start'] ?? null) ? $event['start'] : [];
    $endData = is_array($event['end'] ?? null) ? $event['end'] : [];
    $startValue = google_calendar_api_datetime_to_ics($startData);
    $endValue = google_calendar_api_datetime_to_ics($endData);
    $source = [
        'UID' => trim((string)($event['iCalUID'] ?? $eventId)),
        'SUMMARY' => (string)($event['summary'] ?? ''),
        'DESCRIPTION' => (string)($event['description'] ?? ''),
        'DTSTART' => $startValue,
        'DTEND' => $endValue,
        'STATUS' => strtoupper((string)($event['status'] ?? 'confirmed')),
        'ALL_DAY' => isset($startData['date']) && !isset($startData['dateTime']),
    ];
    $parsed = studio_parse_calendar_event_for_crm($source);
    $parsed['uid'] = import_uid('google-api|' . $calendarId . '|' . $eventId);
    $parsed['google_event_id'] = $eventId;
    $parsed['google_calendar_id'] = $calendarId;
    return $parsed;
}

function google_calendar_mark_cancelled(array $studio, string $calendarId, string $eventId): int
{
    if ($eventId === '') {
        return 0;
    }
    $stmt = studio_db($studio)->prepare(
        'UPDATE appointments
         SET status = "cancelado", updated_at = NOW()
         WHERE google_calendar_id = ? AND google_calendar_event_id = ?
           AND status NOT IN ("cancelado", "perdido")'
    );
    $stmt->execute([$calendarId, $eventId]);
    return $stmt->rowCount();
}

function google_calendar_appointment_datetime(array $appointment, string $field): string
{
    $date = trim((string)($appointment['appointment_date'] ?? ''));
    $time = trim((string)($appointment[$field] ?? ''));
    if ($date === '') {
        throw new RuntimeException('Agendamento sem data para enviar ao Google.');
    }
    if ($time === '') {
        $time = $field === 'end_time' ? trim((string)($appointment['start_time'] ?? '')) : '09:00:00';
    }
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        $time .= ':00';
    }
    $dateTime = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('America/Sao_Paulo'));
    if ($field === 'end_time' && trim((string)($appointment['end_time'] ?? '')) === '') {
        $dateTime = $dateTime->modify('+1 hour');
    }
    return $dateTime->format(DateTimeInterface::RFC3339);
}

function google_calendar_appointment_payload(array $appointment, bool $deleted = false): array
{
    $name = trim((string)($appointment['customer_name'] ?: ($appointment['lead_name'] ?: ($appointment['title'] ?? 'Atendimento'))));
    $title = trim((string)($appointment['title'] ?? $name));
    $status = trim((string)($appointment['status'] ?? ''));
    $summary = $name !== '' ? $name : ($title !== '' ? $title : 'Atendimento');
    if ($deleted || in_array($status, ['cancelado', 'perdido'], true)) {
        $summary = '[CANCELADO] ' . preg_replace('/^\[CANCELADO\]\s*/u', '', $summary);
    }
    $descriptionParts = [];
    $description = trim((string)($appointment['description'] ?? ''));
    if ($description !== '') {
        $descriptionParts[] = $description;
    }
    $descriptionParts[] = 'Sincronizado pelo projetocrm.';
    $descriptionParts[] = 'Status no CRM: ' . ($status !== '' ? $status : 'sem status');
    if (!empty($appointment['artist_name'])) {
        $descriptionParts[] = 'Tatuador: ' . (string)$appointment['artist_name'];
    }
    if ($deleted) {
        $descriptionParts[] = 'Este agendamento foi excluído no CRM.';
    }

    return [
        'summary' => $summary,
        'description' => implode("\n", $descriptionParts),
        'start' => [
            'dateTime' => google_calendar_appointment_datetime($appointment, 'start_time'),
            'timeZone' => 'America/Sao_Paulo',
        ],
        'end' => [
            'dateTime' => google_calendar_appointment_datetime($appointment, 'end_time'),
            'timeZone' => 'America/Sao_Paulo',
        ],
        'extendedProperties' => [
            'private' => [
                'projetocrm_appointment_id' => (string)($appointment['id'] ?? ''),
                'projetocrm_status' => $deleted ? 'deleted' : $status,
            ],
        ],
    ];
}

function google_calendar_push_appointment(array $studio, int $appointmentId, bool $deleted = false): bool
{
    if ($appointmentId <= 0 || !google_calendar_outbound_enabled($studio)) {
        return false;
    }
    $appointment = studio_find_appointment($studio, $appointmentId);
    if (!$appointment) {
        return false;
    }
    $integration = google_calendar_integration($studio);
    $calendarId = trim((string)($integration['calendar_id'] ?? ''));
    if ($calendarId === '') {
        throw new RuntimeException('Selecione um calendário Google antes de enviar agendamentos do CRM.');
    }
    $eventId = trim((string)($appointment['google_calendar_event_id'] ?? ''));
    if ($eventId === '' && ($deleted || in_array((string)($appointment['status'] ?? ''), ['cancelado', 'perdido'], true))) {
        return false;
    }

    $accessToken = google_calendar_access_token($studio);
    $payload = google_calendar_appointment_payload($appointment, $deleted);
    if ($eventId !== '') {
        google_calendar_http(
            'PATCH',
            'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        google_calendar_set_outbound_status($studio, 'success', 'Evento atualizado no Google Agenda.');
        return true;
    }

    $response = google_calendar_http(
        'POST',
        'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events',
        ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $newEventId = trim((string)($response['data']['id'] ?? ''));
    if ($newEventId === '') {
        throw new RuntimeException('O Google não retornou o ID do evento criado.');
    }
    studio_db($studio)->prepare(
        'UPDATE appointments
         SET google_calendar_id = ?, google_calendar_event_id = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([$calendarId, $newEventId, $appointmentId]);
    google_calendar_set_outbound_status($studio, 'success', 'Evento criado no Google Agenda.');
    return true;
}

function google_calendar_try_push_appointment(array $studio, int $appointmentId, bool $deleted = false): bool
{
    try {
        return google_calendar_push_appointment($studio, $appointmentId, $deleted);
    } catch (Throwable $e) {
        google_calendar_set_outbound_status($studio, 'error', $e->getMessage());
        return false;
    }
}

function google_calendar_sync_studio(array $studio, bool $forceFull = false): array
{
    google_calendar_ensure_schema($studio);
    $integration = google_calendar_integration($studio);
    if (!google_calendar_is_connected($integration)) {
        throw new RuntimeException('Google Agenda ainda não está conectado.');
    }
    if (empty($integration['enabled'])) {
        throw new RuntimeException('A sincronização automática está pausada.');
    }

    $pdo = studio_db($studio);
    $calendarId = trim((string)($integration['calendar_id'] ?? 'primary')) ?: 'primary';
    $syncToken = $forceFull ? '' : trim((string)($integration['sync_token'] ?? ''));
    $accessToken = google_calendar_access_token($studio);
    $items = [];
    $cancelled = 0;
    $nextSyncToken = '';
    $pageToken = '';

    try {
        do {
            $params = [
                'singleEvents' => 'true',
                'showDeleted' => 'true',
                'maxResults' => 2500,
            ];
            if ($syncToken !== '') {
                $params['syncToken'] = $syncToken;
            }
            if ($pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }
            try {
                $response = google_calendar_http(
                    'GET',
                    'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events?' . http_build_query($params),
                    ['Authorization: Bearer ' . $accessToken]
                );
            } catch (RuntimeException $e) {
                if ($syncToken !== '' && ($e->getCode() === 410 || str_contains(studio_calendar_lower_text($e->getMessage()), 'sync token'))) {
                    $pdo->prepare('UPDATE google_calendar_integration SET sync_token = NULL, updated_at = NOW() WHERE id = 1')->execute();
                    return google_calendar_sync_studio($studio, true);
                }
                throw $e;
            }
            foreach ($response['data']['items'] ?? [] as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $eventId = trim((string)($event['id'] ?? ''));
                if ((string)($event['status'] ?? '') === 'cancelled') {
                    $cancelled += google_calendar_mark_cancelled($studio, $calendarId, $eventId);
                    continue;
                }
                $parsed = google_calendar_api_event_item($event, $calendarId);
                if (!empty($parsed['include'])) {
                    $items[] = $parsed;
                }
            }
            $pageToken = trim((string)($response['data']['nextPageToken'] ?? ''));
            if ($pageToken === '') {
                $nextSyncToken = trim((string)($response['data']['nextSyncToken'] ?? ''));
            }
        } while ($pageToken !== '');

        $result = $items ? studio_import_calendar_events($studio, $items) : [
            'appointments_created' => 0,
            'appointments_updated' => 0,
            'duplicates_skipped' => 0,
        ];
        if ($nextSyncToken === '') {
            $nextSyncToken = $syncToken;
        }
        $message = sprintf(
            '%d criados, %d atualizados, %d sem alteração e %d cancelados.',
            (int)($result['appointments_created'] ?? 0),
            (int)($result['appointments_updated'] ?? 0),
            (int)($result['duplicates_skipped'] ?? 0),
            $cancelled
        );
        $pdo->prepare(
            'UPDATE google_calendar_integration
             SET sync_token = ?, last_sync_at = NOW(), last_sync_status = "success", last_sync_message = ?,
                 last_sync_created = ?, last_sync_updated = ?, last_sync_unchanged = ?,
                 last_sync_cancelled = ?, updated_at = NOW()
             WHERE id = 1'
        )->execute([
            $nextSyncToken !== '' ? $nextSyncToken : null,
            $message,
            (int)($result['appointments_created'] ?? 0),
            (int)($result['appointments_updated'] ?? 0),
            (int)($result['duplicates_skipped'] ?? 0),
            $cancelled,
        ]);
        return array_merge($result, ['cancelled' => $cancelled, 'message' => $message]);
    } catch (Throwable $e) {
        $pdo->prepare(
            'UPDATE google_calendar_integration
             SET last_sync_at = NOW(), last_sync_status = "error", last_sync_message = ?, updated_at = NOW()
             WHERE id = 1'
        )->execute([mb_substr($e->getMessage(), 0, 1000)]);
        throw $e;
    }
}

function google_calendar_sync_due_studios(): array
{
    $results = [];
    $interval = max(1, (int)(app_config('google_calendar')['sync_interval_minutes'] ?? 5));
    foreach (list_studios() as $studio) {
        try {
            if (!studio_schema_ready($studio)) {
                continue;
            }
            $integration = google_calendar_integration($studio);
            if (!google_calendar_is_connected($integration) || empty($integration['enabled'])) {
                continue;
            }
            $lastSync = strtotime((string)($integration['last_sync_at'] ?? '')) ?: 0;
            if ($lastSync > time() - ($interval * 60)) {
                continue;
            }
            $sync = google_calendar_sync_studio($studio);
            $results[] = ['studio_id' => (int)$studio['id'], 'ok' => true, 'message' => $sync['message']];
        } catch (Throwable $e) {
            $results[] = ['studio_id' => (int)($studio['id'] ?? 0), 'ok' => false, 'message' => $e->getMessage()];
        }
    }
    return $results;
}
