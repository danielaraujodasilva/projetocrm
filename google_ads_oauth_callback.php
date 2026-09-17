<?php

declare(strict_types=1);

/**
 * Recebe o retorno do Google (code) e guarda o refresh token do Google Ads
 * nas configuracoes do estudio. Espelha o fluxo do Google Calendar.
 */
require __DIR__ . '/app/bootstrap.php';

try {
    $oauthError = trim((string)($_GET['error'] ?? ''));
    if ($oauthError !== '') {
        throw new RuntimeException('Autorizacao do Google cancelada ou recusada.');
    }
    $state = trim((string)($_GET['state'] ?? ''));
    $code = trim((string)($_GET['code'] ?? ''));
    $pending = $_SESSION['google_ads_oauth'][$state] ?? null;
    unset($_SESSION['google_ads_oauth'][$state]);
    if ($state === '' || $code === '' || !is_array($pending) || (int)($pending['expires_at'] ?? 0) < time()) {
        throw new RuntimeException('A autorizacao do Google expirou. Inicie a conexao novamente.');
    }
    $studio = require_studio();
    if ((int)($pending['studio_id'] ?? 0) !== (int)$studio['id']) {
        throw new RuntimeException('A autorizacao nao pertence a este estudio.');
    }

    $tokens = ads_roi_google_exchange_code($code);
    $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        throw new RuntimeException('O Google nao devolveu o refresh token. Remova o acesso do app na sua Conta Google e tente de novo.');
    }
    ads_roi_google_store_refresh_token($studio, $refreshToken);
    flash_set('success', 'Google Ads conectado. O gasto passa a ser importado na sincronizacao diaria.');
} catch (Throwable $e) {
    flash_set('error', 'Google Ads: ' . $e->getMessage());
}

redirect_to('studio_ads_roi');
