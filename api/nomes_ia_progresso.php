<?php
/**
 * Acompanhamento da varredura de nomes via IA.
 * Le o checkpoint e devolve o estado em JSON para a pagina.
 *
 * Uso: GET /projetocrm/nomes_ia_progresso.php
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ckptPath = APP_BASE_PATH . '/dados/nomes_ia_checkpoint.json';
$logPath = APP_BASE_PATH . '/dados/varredura.log';

$ckpt = ['processadas' => [], 'achados' => [], 'falhas' => []];
if (is_file($ckptPath)) {
    $j = json_decode((string)file_get_contents($ckptPath), true);
    if (is_array($j)) { $ckpt = array_merge($ckpt, $j); }
}

// Total de conversas na ponte (denominador do progresso).
$total = 0;
$path = historico_conversas_path();
if (is_file($path)) {
    $fones = [];
    $fh = fopen($path, 'r');
    while (($l = fgets($fh)) !== false) {
        $m = json_decode(trim($l), true);
        if (!is_array($m)) { continue; }
        $f = (string)historico_telefone_canonico($m);
        if ($f !== '') { $fones[$f] = true; }
    }
    fclose($fh);
    $total = count($fones);
}

$processadas = count($ckpt['processadas'] ?? []);
$achados = count($ckpt['achados'] ?? []);
$falhas = count($ckpt['falhas'] ?? []);

// Lista dos nomes achados (mais recentes primeiro, se houver ordem).
$lista = [];
foreach (($ckpt['achados'] ?? []) as $fone => $info) {
    $lista[] = [
        'fone' => (string)$fone,
        'nome' => (string)($info['nome'] ?? ''),
        'conf' => (int)($info['conf'] ?? 0),
    ];
}

// Falhas.
$listaFalhas = [];
foreach (($ckpt['falhas'] ?? []) as $fone => $erro) {
    $listaFalhas[] = ['fone' => (string)$fone, 'erro' => (string)$erro];
}

// Processo vivo? (procura php.exe rodando o script)
$rodando = false;
$saida = [];
exec('tasklist /FI "IMAGENAME eq php.exe" /FO CSV /NH 2>&1', $saida);
foreach ($saida as $linha) {
    if (stripos($linha, 'php.exe') !== false) { $rodando = true; break; }
}

// Ultimas linhas do log.
$ultimasLinhas = [];
if (is_file($logPath)) {
    $log = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $ultimasLinhas = array_slice($log, -25);
}

echo json_encode([
    'ok' => true,
    'total' => $total,
    'processadas' => $processadas,
    'achados' => $achados,
    'falhas' => $falhas,
    'restantes' => max(0, $total - $processadas),
    'percentual' => $total > 0 ? round(100 * $processadas / $total, 1) : 0.0,
    'rodando' => $rodando,
    'checkpoint_mtime' => is_file($ckptPath) ? date('d/m/Y H:i:s', (int)filemtime($ckptPath)) : null,
    'nomes' => $lista,
    'erros' => $listaFalhas,
    'log' => $ultimasLinhas,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
