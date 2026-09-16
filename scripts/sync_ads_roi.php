<?php
/**
 * Sync autônomo do painel de ROI (Studio Ads).
 * Roda via CLI para popular ads_daily com gasto real do Meta Ads.
 * Uso: php scripts/sync_ads_roi.php [dias]
 * Exemplo de agendamento (Windows): rodar diariamente às 08:00.
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
$res = ads_roi_sync_meta($studio, $days);
if (!empty($res['ok'])) {
    echo "SYNC_META|" . $studio['slug'] . "|imported=" . (int)$res['imported'] . "|days=" . (int)$res['days'] . "\n";
    exit(0);
}
echo "SYNC_META_ERRO|" . $studio['slug'] . "|" . (string)($res['error'] ?? '?') . "\n";
exit(2);