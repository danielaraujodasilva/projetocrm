<?php
/**
 * Sync autônomo do painel de ROI (Studio Ads).
 * Roda via CLI para popular ads_daily com gasto real do Meta Ads e do Google Ads.
 * Uso: php scripts/sync_ads_roi.php [dias]
 * Exemplo de agendamento (Windows): rodar diariamente às 08:00.
 *
 * Saída (uma linha por canal, para o job agendado poder conferir):
 *   SYNC_META|slug|imported=N|days=N
 *   SYNC_GOOGLE|slug|imported=N|days=N
 *   SYNC_GOOGLE_PENDENTE|slug|<motivo>   (enquanto a conta do Google não estiver conectada)
 *   SYNC_META_ERRO|slug|<motivo>
 *
 * Código de saída: 0 se o Meta sincronizou (Google pendente não falha o job).
 */
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'CLI';
require dirname(__DIR__) . '/app/bootstrap.php';

$days = isset($argv[1]) ? (int)$argv[1] : 7;

// CRM é multi-tenant; aqui sincronizamos o estúdio do Daniel (cereja, id 1).
// Se houver mais estúdios, listá-los via plataforma e iterar.
$studio = get_studio(1);
if (!$studio) {
    echo "NENHUM_ESTUDIO\n";
    exit(1);
}
$slug = (string)($studio['slug'] ?? '?');

// --- Meta Ads ---
$okMeta = false;
$res = ads_roi_sync_meta($studio, $days);
if (!empty($res['ok'])) {
    echo "SYNC_META|" . $slug . "|imported=" . (int)$res['imported'] . "|days=" . (int)$res['days'] . "\n";
    $okMeta = true;
} else {
    echo "SYNC_META_ERRO|" . $slug . "|" . (string)($res['error'] ?? '?') . "\n";
}

// --- Google Ads ---
// Enquanto a conta não estiver conectada, o sync devolve needs_google_credentials;
// isso é esperado e NÃO deve marcar o job como falho.
$resGoogle = ads_roi_sync_google($studio, $days);
if (!empty($resGoogle['ok'])) {
    echo "SYNC_GOOGLE|" . $slug . "|imported=" . (int)$resGoogle['imported'] . "|days=" . (int)$resGoogle['days'] . "\n";
} elseif (!empty($resGoogle['needs_google_credentials'])) {
    echo "SYNC_GOOGLE_PENDENTE|" . $slug . "|" . (string)($resGoogle['error'] ?? 'nao configurado') . "\n";
} else {
    echo "SYNC_GOOGLE_ERRO|" . $slug . "|" . (string)($resGoogle['error'] ?? '?') . "\n";
}

exit($okMeta ? 0 : 2);
