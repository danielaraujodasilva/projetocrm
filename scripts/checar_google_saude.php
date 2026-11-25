<?php
/**
 * Checagem periodica da saude do Google Ads (entrega travada / recurso reprovado)
 * + alerta de limite de gastos da conta acabando.
 *
 * Roda via CLI numa tarefa agendada (a cada 30 min). SOMENTE CONSULTA a conta.
 * O resultado da saude vai para ads_google_saude (painel de ROI). O alerta de
 * limite vai por WhatsApp para o Daniel, no maximo 1x a cada 12 h por faixa.
 *
 * Uso: php scripts/checar_google_saude.php
 * Saida: GOOGLE_SAUDE|ok|<motivos>
 *        GOOGLE_SAUDE|pendente|<motivos>
 */
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'CLI';
require dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_BASE_PATH . '/app/ads_roi.php';

$studio = get_studio(1);
if (!$studio) {
    echo "NENHUM_ESTUDIO\n";
    exit(1);
}

function google_saude_log(string $msg): void
{
    $dir = APP_BASE_PATH . '/storage/logs';
    @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/google_saude.log', '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function google_saude_alerta(string $texto): bool
{
    $payload = json_encode([
        'to' => '5511947573311',
        'kind' => 'text',
        'message' => $texto,
        'by' => 'google-saude',
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('http://127.0.0.1:3210/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($http >= 200 && $http < 300) {
        return true;
    }
    google_saude_log('falha ao enviar alerta (http=' . $http . ' erro=' . $erro . ')');
    return false;
}

// 1) Saude da entrega (alimenta o painel).
$saude = ads_roi_google_saude_check($studio);
$motivos = trim((string)($saude['motivos'] ?? ''));
if ($motivos === '' && !empty($saude['erro'])) {
    $motivos = trim((string)$saude['erro']);
}
echo 'GOOGLE_SAUDE|' . (!empty($saude['ok']) ? 'ok' : 'pendente') . '|' . $motivos . "\n";

// 2) Limite de gastos da conta (account budget). Nao da para alterar por API
// com o token atual (explorer); o alerta evita a campanha parar de surpresa.
$saldo = ads_roi_google_balance_status($studio);
if (empty($saldo['ok']) || !isset($saldo['balance']) || $saldo['balance'] === null) {
    google_saude_log('saldo indisponivel: ' . json_encode($saldo['error'] ?? $saldo['observacao'] ?? 'sem dados', JSON_UNESCAPED_UNICODE));
    exit(0);
}
$restante = (float)$saldo['balance'];
$limite = (float)($saldo['limite'] ?? 0);
$servido = (float)($saldo['servido'] ?? 0);

$estadoPath = APP_BASE_PATH . '/storage/logs/google_budget_alerta.json';
$estado = [];
if (is_file($estadoPath)) {
    $estado = json_decode((string)@file_get_contents($estadoPath), true) ?: [];
}

$faixa = $restante <= 50 ? 50 : ($restante <= 150 ? 150 : 0);
$ultimo = (int)($estado['ultimo_envio'] ?? 0);
$ultimaFaixa = (int)($estado['faixa'] ?? 0);
$agora = time();

// Alerta quando entra numa faixa nova (150 ou 50) ou quando o saldo ficou
// negativo. Cooldown de 12 h para nao spammar a cada 30 min.
$precisa = ($faixa > 0 && ($faixa !== $ultimaFaixa || $agora - $ultimo > 43200)) || $restante < 0;
if ($precisa) {
    $texto = "⚠️ Google Ads: o limite de gastos da conta está em R$ " . number_format($limite, 2, ',', '.')
        . " e já foram gastos R$ " . number_format($servido, 2, ',', '.') . ".\n"
        . "Restam R$ " . number_format($restante, 2, ',', '.') . " — quando zerar, a campanha para sozinha.\n"
        . "Sobe o limite em Google Ads → Faturamento → Configurações (limite de gastos).";
    if (google_saude_alerta($texto)) {
        @file_put_contents($estadoPath, json_encode(['ultimo_envio' => $agora, 'faixa' => $faixa, 'restante' => $restante], JSON_UNESCAPED_UNICODE));
        google_saude_log('alerta enviado: restante=' . number_format($restante, 2, '.', ''));
    }
}

exit(0);
