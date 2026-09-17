<?php

declare(strict_types=1);

/**
 * Inicia a autorizacao OAuth do Google Ads.
 *
 * Se o usuario ainda nao estiver logado no CRM, guarda o destino e manda para o
 * login; apos entrar, o login devolve para ca (via studio_return_to) e a autorizacao
 * segue para o Google. Sem isso, o fluxo morria no login e o Google nunca recebia o
 * pedido (sintoma: "parece que so redirecionou para o sistema").
 */
require __DIR__ . '/app/bootstrap.php';

// Sem sessao valida no CRM: guarda o retorno e manda para o login, para voltar aqui depois.
if (!current_studio_user()) {
    $_SESSION['studio_return_to'] = app_asset_url('google_ads_oauth_start.php');
    redirect_to('studio_login');
}

try {
    $studio = require_studio();
    header('Location: ' . ads_roi_google_authorization_url($studio));
    exit;
} catch (Throwable $e) {
    flash_set('error', 'Google Ads: ' . $e->getMessage());
    redirect_to('studio_ads_roi');
}
