<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$flowLabel = 'Google Agenda';

try {
    $oauthError = trim((string)($_GET['error'] ?? ''));
    if ($oauthError !== '') {
        throw new RuntimeException('Autorização do Google cancelada ou recusada.');
    }
    $state = trim((string)($_GET['state'] ?? ''));
    $code = trim((string)($_GET['code'] ?? ''));
    // Fluxo separado: leitura da caixa de entrada do Gmail (mesmo redirect, state proprio).
    $gmailPending = $_SESSION['google_gmail_oauth'][$state] ?? null;
    unset($_SESSION['google_gmail_oauth'][$state]);
    if (is_array($gmailPending)) {
        $flowLabel = 'Gmail';
        if ($state === '' || $code === '' || (int)($gmailPending['expires_at'] ?? 0) < time()) {
            throw new RuntimeException('A autorizacao do Gmail expirou. Inicie a conexao novamente.');
        }
        $studio = require_studio();
        if ((int)($gmailPending['studio_id'] ?? 0) !== (int)$studio['id']) {
            throw new RuntimeException('A autorizacao do Gmail nao pertence a este estudio.');
        }
        $tokens = google_gmail_exchange_code($code);
        $account = google_gmail_store_connection($studio, $tokens);
        flash_set('success', 'Gmail conectado: ' . $account);
        redirect_to('studio_agenda');
    }
    $pending = $_SESSION['google_calendar_oauth'][$state] ?? null;
    unset($_SESSION['google_calendar_oauth'][$state]);
    if (
        $state === ''
        || $code === ''
        || !is_array($pending)
        || (int)($pending['expires_at'] ?? 0) < time()
    ) {
        throw new RuntimeException('A autorização do Google expirou. Inicie a conexão novamente.');
    }
    $studio = require_studio();
    if ((int)($pending['studio_id'] ?? 0) !== (int)$studio['id']) {
        throw new RuntimeException('A autorização não pertence a este estúdio.');
    }

    $tokens = google_calendar_exchange_code($code);
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    if ($accessToken === '') {
        throw new RuntimeException('O Google não retornou um token de acesso.');
    }
    $calendars = google_calendar_fetch_calendars($accessToken);
    google_calendar_store_connection($studio, $tokens, $calendars);
    $result = google_calendar_sync_studio($studio, true);
    flash_set('success', 'Google Agenda conectado. Primeira sincronização: ' . $result['message']);
} catch (Throwable $e) {
    flash_set('error', $flowLabel . ': ' . $e->getMessage());
}

redirect_to('studio_agenda');
