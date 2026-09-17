<?php

declare(strict_types=1);

/**
 * Inicia a autorizacao OAuth do Google Ads.
 * Redireciona para a tela de consentimento do Google pedindo o escopo do AdWords.
 * Apos autorizar, o Google volta em google_ads_oauth_callback.php, que guarda o
 * refresh token nas configuracoes do estudio.
 */
require __DIR__ . '/app/bootstrap.php';

try {
    $studio = require_studio();
    header('Location: ' . ads_roi_google_authorization_url($studio));
    exit;
} catch (Throwable $e) {
    flash_set('error', 'Google Ads: ' . $e->getMessage());
    redirect_to('studio_ads_roi');
}
