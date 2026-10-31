<?php

declare(strict_types=1);

// Acesso a caixa de entrada do Gmail (leitura + mover para a lixeira).
// Reaproveita o mesmo aplicativo OAuth do Google Cloud usado pelo Google Agenda,
// mas com fluxo, escopo e token separados — conectar o Gmail aqui nao altera a agenda.

$calendar = require __DIR__ . '/google_calendar.php';

return [
    'client_id' => trim((string)($calendar['client_id'] ?? '')),
    'client_secret' => trim((string)($calendar['client_secret'] ?? '')),
    'auth_uri' => trim((string)($calendar['auth_uri'] ?? 'https://accounts.google.com/o/oauth2/v2/auth')),
    'token_uri' => trim((string)($calendar['token_uri'] ?? 'https://oauth2.googleapis.com/token')),
    'redirect_uri' => trim((string)($calendar['redirect_uri'] ?? '')),
    // gmail.modify inclui a leitura e permite mover mensagens para a lixeira.
    'scopes' => [
        'https://www.googleapis.com/auth/gmail.modify',
    ],
];