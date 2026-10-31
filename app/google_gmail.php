<?php

declare(strict_types=1);

// Caixa de entrada do Gmail (escopo somente leitura) para o estudio.
// Fluxo OAuth proprio: nao compartilha token com o Google Agenda.
// Reaproveita as credenciais do app Google Cloud e a criptografia ja usada pelo Agenda.

function google_gmail_configured(): bool
{
    $config = app_config('google_gmail');
    return trim((string)($config['client_id'] ?? '')) !== ''
        && trim((string)($config['client_secret'] ?? '')) !== ''
        && trim((string)($config['redirect_uri'] ?? '')) !== '';
}

function google_gmail_ensure_schema(array $studio): void
{
    static $checked = [];
    $key = (int)($studio['id'] ?? 0) . '|' . (string)($studio['database_name'] ?? '');
    if (isset($checked[$key])) {
        return;
    }
    $checked[$key] = true;

    studio_db($studio)->exec(
        'CREATE TABLE IF NOT EXISTS `google_gmail_integration` (
            `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `account_email` VARCHAR(255) NULL,
            `scopes` VARCHAR(255) NULL,
            `access_token_encrypted` MEDIUMTEXT NULL,
            `refresh_token_encrypted` MEDIUMTEXT NULL,
            `access_token_expires_at` DATETIME NULL,
            `last_check_at` DATETIME NULL,
            `last_check_status` VARCHAR(30) NULL,
            `last_check_message` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function google_gmail_integration(array $studio): array
{
    google_gmail_ensure_schema($studio);
    $row = studio_db($studio)->query('SELECT * FROM google_gmail_integration WHERE id = 1 LIMIT 1')->fetch();
    return is_array($row) ? $row : [];
}

function google_gmail_is_connected(array $integration): bool
{
    return trim((string)($integration['refresh_token_encrypted'] ?? '')) !== '';
}

function google_gmail_http(string $method, string $url, array $headers = [], array|string|null $body = null): array
{
    try {
        return google_calendar_http($method, $url, $headers, $body);
    } catch (Throwable $e) {
        throw new RuntimeException(str_replace('Google Calendar', 'Gmail', $e->getMessage()), (int)$e->getCode());
    }
}

function google_gmail_authorization_url(array $studio): string
{
    if (!google_gmail_configured()) {
        throw new RuntimeException('As credenciais OAuth do Google ainda nao estao configuradas.');
    }
    $config = app_config('google_gmail');
    $state = bin2hex(random_bytes(24));
    $_SESSION['google_gmail_oauth'] ??= [];
    $_SESSION['google_gmail_oauth'][$state] = [
        'studio_id' => (int)$studio['id'],
        'expires_at' => time() + 900,
    ];

    return (string)$config['auth_uri'] . '?' . http_build_query([
        'client_id' => (string)$config['client_id'],
        'redirect_uri' => (string)$config['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', array_map('strval', $config['scopes'] ?? [])),
        'access_type' => 'offline',
        'prompt' => 'consent select_account',
        'include_granted_scopes' => 'false',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

function google_gmail_exchange_code(string $code): array
{
    $config = app_config('google_gmail');
    $response = google_gmail_http(
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

function google_gmail_profile_with_token(string $accessToken): array
{
    $response = google_gmail_http(
        'GET',
        'https://gmail.googleapis.com/gmail/v1/users/me/profile',
        ['Authorization: Bearer ' . $accessToken]
    );
    return is_array($response['data']) ? $response['data'] : [];
}

function google_gmail_store_connection(array $studio, array $tokens): string
{
    google_gmail_ensure_schema($studio);
    $current = google_gmail_integration($studio);
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        $refreshToken = google_calendar_decrypt((string)($current['refresh_token_encrypted'] ?? ''));
    }
    if ($accessToken === '' || $refreshToken === '') {
        throw new RuntimeException('O Google nao retornou autorizacao offline. Remova o acesso anterior na conta Google e conecte novamente.');
    }

    // A consulta ao perfil e apenas informativa: se a API do Gmail ainda nao
    // estiver ativa no projeto Google, guardamos o token mesmo assim.
    $email = '';
    try {
        $profile = google_gmail_profile_with_token($accessToken);
        $email = trim((string)($profile['emailAddress'] ?? ''));
    } catch (Throwable $e) {
        $email = '';
    }
    $note = $email !== ''
        ? 'Conta conectada.'
        : 'Conta conectada. Ative a Gmail API no projeto Google para poder ler as mensagens.';
    $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int)($tokens['expires_in'] ?? 3600) - 60));

    $stmt = studio_db($studio)->prepare(
        'INSERT INTO google_gmail_integration
            (id, enabled, account_email, scopes, access_token_encrypted, refresh_token_encrypted,
             access_token_expires_at, last_check_at, last_check_status, last_check_message, created_at, updated_at)
         VALUES
            (1, 1, ?, ?, ?, ?, ?, NOW(), "connected", ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            enabled = 1, account_email = VALUES(account_email), scopes = VALUES(scopes),
            access_token_encrypted = VALUES(access_token_encrypted),
            refresh_token_encrypted = VALUES(refresh_token_encrypted),
            access_token_expires_at = VALUES(access_token_expires_at),
            last_check_at = NOW(), last_check_status = "connected",
            last_check_message = VALUES(last_check_message), updated_at = NOW()'
    );
    $stmt->execute([
        $email,
        trim((string)($tokens['scope'] ?? '')),
        google_calendar_encrypt($accessToken),
        google_calendar_encrypt($refreshToken),
        $expiresAt,
        $note,
    ]);
    return $email !== '' ? $email : 'conta Google';
}

function google_gmail_access_token(array $studio): string
{
    $integration = google_gmail_integration($studio);
    if (!google_gmail_is_connected($integration)) {
        throw new RuntimeException('O Gmail ainda nao esta conectado.');
    }
    $expiresAt = strtotime((string)($integration['access_token_expires_at'] ?? '')) ?: 0;
    $accessToken = google_calendar_decrypt((string)($integration['access_token_encrypted'] ?? ''));
    if ($accessToken !== '' && $expiresAt > time() + 90) {
        return $accessToken;
    }

    $config = app_config('google_gmail');
    $refreshToken = google_calendar_decrypt((string)$integration['refresh_token_encrypted']);
    $response = google_gmail_http(
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
    $newToken = trim((string)($response['data']['access_token'] ?? ''));
    if ($newToken === '') {
        throw new RuntimeException('O Google nao renovou o acesso ao Gmail.');
    }
    $newExpiresAt = date('Y-m-d H:i:s', time() + max(60, (int)($response['data']['expires_in'] ?? 3600) - 60));
    studio_db($studio)->prepare(
        'UPDATE google_gmail_integration
            SET access_token_encrypted = ?, access_token_expires_at = ?, updated_at = NOW()
          WHERE id = 1'
    )->execute([google_calendar_encrypt($newToken), $newExpiresAt]);

    return $newToken;
}

function google_gmail_profile(array $studio): array
{
    return google_gmail_profile_with_token(google_gmail_access_token($studio));
}

function google_gmail_message(string $accessToken, string $id): array
{
    $response = google_gmail_http(
        'GET',
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($id) . '?' . http_build_query([
            'format' => 'metadata',
            'metadataHeaders' => ['From', 'To', 'Subject', 'Date'],
        ]),
        ['Authorization: Bearer ' . $accessToken]
    );
    $data = is_array($response['data']) ? $response['data'] : [];
    $headers = [];
    foreach ($data['payload']['headers'] ?? [] as $header) {
        $name = strtolower(trim((string)($header['name'] ?? '')));
        if ($name !== '') {
            $headers[$name] = trim((string)($header['value'] ?? ''));
        }
    }
    $attachments = 0;
    foreach ($data['payload']['parts'] ?? [] as $part) {
        if (trim((string)($part['filename'] ?? '')) !== '') {
            $attachments++;
        }
    }
    $timestamp = (int)floor(((int)($data['internalDate'] ?? 0)) / 1000);

    return [
        'id' => (string)($data['id'] ?? $id),
        'thread_id' => (string)($data['threadId'] ?? ''),
        'date' => $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '',
        'from' => (string)($headers['from'] ?? ''),
        'to' => (string)($headers['to'] ?? ''),
        'subject' => (string)($headers['subject'] ?? ''),
        'snippet' => (string)($data['snippet'] ?? ''),
        'attachments' => $attachments,
    ];
}

function google_gmail_search(array $studio, string $query, int $limit = 25): array
{
    $accessToken = google_gmail_access_token($studio);
    $limit = max(1, min(200, $limit));
    $response = google_gmail_http(
        'GET',
        'https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query([
            'q' => $query,
            'maxResults' => $limit,
        ]),
        ['Authorization: Bearer ' . $accessToken]
    );

    $messages = [];
    foreach ($response['data']['messages'] ?? [] as $item) {
        $id = trim((string)($item['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $messages[] = google_gmail_message($accessToken, $id);
    }

    return $messages;
}
// Move uma mensagem para a lixeira (recuperavel por 30 dias no Gmail).
// Exige o escopo gmail.modify na autorizacao conectada.
function google_gmail_trash(array $studio, string $id): bool
{
    $accessToken = google_gmail_access_token($studio);
    $response = google_gmail_http(
        'POST',
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($id) . '/trash',
        ['Authorization: Bearer ' . $accessToken, 'Content-Length: 0']
    );
    return ($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300;
}
// Lista apenas os IDs das mensagens que casam com a consulta (uma chamada por pagina).
function google_gmail_ids(array $studio, string $query, int $limit = 500): array
{
    $accessToken = google_gmail_access_token($studio);
    $limit = max(1, min(2000, $limit));
    $ids = [];
    $pageToken = '';
    do {
        $params = ['q' => $query, 'maxResults' => max(1, min(500, $limit - count($ids)))];
        if ($pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }
        $response = google_gmail_http(
            'GET',
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query($params),
            ['Authorization: Bearer ' . $accessToken]
        );
        foreach ($response['data']['messages'] ?? [] as $item) {
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $pageToken = trim((string)($response['data']['nextPageToken'] ?? ''));
    } while ($pageToken !== '' && count($ids) < $limit);

    return array_slice($ids, 0, $limit);
}
