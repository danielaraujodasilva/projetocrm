<?php

declare(strict_types=1);

$credentialsPath = __DIR__ . '/google_calendar.local.json';
$credentials = [];
if (is_file($credentialsPath)) {
    $decoded = json_decode((string)file_get_contents($credentialsPath), true);
    if (is_array($decoded)) {
        $credentials = is_array($decoded['web'] ?? null) ? $decoded['web'] : ($decoded['installed'] ?? []);
    }
}

return [
    'client_id' => trim((string)($credentials['client_id'] ?? getenv('GOOGLE_CALENDAR_CLIENT_ID') ?: '')),
    'client_secret' => trim((string)($credentials['client_secret'] ?? getenv('GOOGLE_CALENDAR_CLIENT_SECRET') ?: '')),
    'auth_uri' => trim((string)($credentials['auth_uri'] ?? 'https://accounts.google.com/o/oauth2/v2/auth')),
    'token_uri' => trim((string)($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token')),
    'redirect_uri' => trim((string)(($credentials['redirect_uris'][0] ?? null) ?: 'https://danieltatuador.com/projetocrm/google_calendar_oauth_callback.php')),
    'scopes' => [
        'https://www.googleapis.com/auth/calendar.events.readonly',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
    ],
    'sync_interval_minutes' => 5,
];
