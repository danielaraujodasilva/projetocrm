<?php
/**
 * Checagem periodica da saude do Google Ads (entrega travada / recurso reprovado).
 *
 * Roda via CLI numa tarefa agendada. SOMENTE CONSULTA a conta: confere se a
 * campanha e o grupo de recursos estao elegiveis e se existe recurso reprovado.
 * O resultado vai para ads_google_saude — e a ultima linha dessa tabela que o
 * painel de ROI usa para mostrar (ou esconder) o aviso vermelho.
 *
 * Uso: php scripts/checar_google_saude.php
 * Saida: GOOGLE_SAUDE|ok|<motivos>
 *        GOOGLE_SAUDE|pendente|<motivos>
 *
 */
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'CLI';
require dirname(__DIR__) . '/app/bootstrap.php';

$studio = get_studio(1);
if (!$studio) {
    echo "NENHUM_ESTUDIO\n";
    exit(1);
}

// So alimenta o painel: grava o resultado em ads_google_saude para o aviso do
// ROI refletir a realidade. Nao manda mensagem para ninguem.
$saude = ads_roi_google_saude_check($studio);
$motivos = trim((string)($saude['motivos'] ?? ''));
if ($motivos === '' && !empty($saude['erro'])) {
    $motivos = trim((string)$saude['erro']);
}
echo 'GOOGLE_SAUDE|' . (!empty($saude['ok']) ? 'ok' : 'pendente') . '|' . $motivos . "\n";
exit(0);
