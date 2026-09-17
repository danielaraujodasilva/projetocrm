<?php

declare(strict_types=1);

/**
 * Recebe o retorno do Google (code) e guarda o refresh token do Google Ads
 * nas configuracoes do estudio. Espelha o fluxo do Google Calendar.
 *
 * Observacao importante: a autorizacao pode ser feita em OUTRO dispositivo
 * (ex.: celular, quando o computador nao tem Bluetooth para o passkey). Por isso
 * o code e trocado pelo token ANTES de exigir a sessao do CRM; se nao houver
 * sessao valida, o token fica guardado numa autorizacao pendente (arquivo local)
 * e pode ser recolhido depois, dentro do painel, sem repetir o consentimento.
 */
require __DIR__ . '/app/bootstrap.php';

$state = trim((string)($_GET['state'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
$oauthError = trim((string)($_GET['error'] ?? ''));

// Diagnostico: registra QUAIS parametros voltaram (sem os valores sensiveis).
$diagLog = __DIR__ . '/storage/logs/google_ads_oauth.log';
@mkdir(dirname($diagLog), 0775, true);
@file_put_contents($diagLog, date('Y-m-d H:i:s') . ' retorno: params=[' . implode(',', array_keys($_GET))
    . '] error=' . ($oauthError !== '' ? $oauthError : '-')
    . ' tem_code=' . ($code !== '' ? 'sim' : 'nao')
    . ' tem_state=' . ($state !== '' ? 'sim' : 'nao') . PHP_EOL, FILE_APPEND);

try {
    if ($oauthError !== '') {
        throw new RuntimeException('Autorizacao do Google cancelada ou recusada pelo usuario (' . $oauthError . ').');
    }
    if ($state === '' || $code === '') {
        throw new RuntimeException('O Google nao devolveu o codigo de autorizacao.');
    }
    $pending = $_SESSION['google_ads_oauth'][$state] ?? null;
    unset($_SESSION['google_ads_oauth'][$state]);

    // Troca o code pelo token SEM depender da sessao do CRM (pode vir de outro aparelho).
    $tokens = ads_roi_google_exchange_code($code, $state);
    $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        throw new RuntimeException('O Google nao devolveu o refresh token. Revogue o acesso do app na sua Conta Google (Seguranca > Apps com acesso) e tente de novo.');
    }

    // Guarda numa pendencia em disco (curta), para recolher com sessao ou sem ela.
    $pendPath = __DIR__ . '/storage/google_ads_oauth_pending.json';
    @mkdir(dirname($pendPath), 0775, true);
    file_put_contents($pendPath, json_encode([
        'refresh_token' => $refreshToken,
        'created_at' => time(),
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);

    // Se houver sessao de estudio valida, grava na hora e da o recado de sucesso.
    $studio = current_studio();
    if (is_array($studio) && (int)$studio['id'] > 0) {
        ads_roi_google_store_refresh_token($studio, $refreshToken);
        @unlink($pendPath);
        flash_set('success', 'Google Ads conectado. O gasto passa a ser importado na sincronizacao diaria.');
        redirect_to('studio_ads_roi');
    }

    // Sem sessao: avisa numa tela simples (nao redireciona para o painel, que so
    // mostraria o login e engoliria o aviso).
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Google Ads autorizado</title>';
    echo '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f6f7f9;margin:0;padding:24px;color:#101828}'
        . '.card{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e6e8ee;border-radius:16px;padding:24px}'
        . 'h1{font-size:20px;margin:0 0 10px}.ok{color:#079455;font-weight:800}'
        . 'p{line-height:1.55;color:#475467;font-size:14px}'
        . 'a.btn{display:inline-block;margin-top:14px;background:#101828;color:#fff;text-decoration:none;padding:10px 16px;border-radius:10px;font-weight:700;font-size:14px}'
        . '</style></head><body><div class="card">';
    echo '<h1><span class="ok">Autorizado com sucesso.</span></h1>';
    echo '<p>O Google liberou o acesso ao Google Ads. <b>Nao feche este aviso ainda:</b> '
        . 'a autorizacao foi feita em outro aparelho, entao falta o CRM recolher o token.</p>';
    echo '<p>Entre no CRM <b>neste aparelho</b> e a conexao e concluida automaticamente '
        . 'na primeira visita ao painel Retorno dos Anuncios.</p>';
    echo '<a class="btn" href="' . h(app_url('studio_ads_roi')) . '">Abrir o painel</a>';
    echo '</div></body></html>';
    exit;
} catch (Throwable $e) {
    // Registra o motivo no storage para diagnostico e mostra uma tela clara.
    $logPath = __DIR__ . '/storage/logs/google_ads_oauth.log';
    @mkdir(dirname($logPath), 0775, true);
    file_put_contents($logPath, date('Y-m-d H:i:s') . ' ERRO: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    error_log('google_ads_oauth_callback: ' . $e->getMessage());
    flash_set('error', 'Google Ads: ' . $e->getMessage());
    redirect_to('studio_ads_roi');
}
