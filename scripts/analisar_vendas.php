<?php
/**
 * Job agendado: analisa VENDA nas conversas do WhatsApp usando a IA local.
 *
 * Roda em segundo plano (tarefa agendada do Windows), nunca na navegacao do usuario,
 * porque cada conversa leva de 5 a 30 segundos na CPU.
 *
 * Uso:
 *   php scripts/analisar_vendas.php [limite]
 *
 * Exemplo (analisa ate 10 conversas pendentes):
 *   php scripts/analisar_vendas.php 10
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once APP_BASE_PATH . '/app/venda_ia.php';

$limite = isset($argv[1]) ? max(1, min(100, (int)$argv[1])) : 10;

function log_venda(string $msg): void
{
    $dir = APP_BASE_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . '/analise_vendas.log',
        '[' . date('c') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
    echo $msg . PHP_EOL;
}

try {
    $studio = get_studio(1);
} catch (Throwable $e) {
    log_venda('ERRO: nao consegui carregar o estudio: ' . $e->getMessage());
    exit(1);
}

// Sem IA local no ar, nao ha o que fazer: sai quieto.
if (!venda_ia_online($studio)) {
    log_venda('IA local (Ollama) indisponivel - analise ignorada nesta rodada.');
    exit(0);
}

$cfg = venda_config($studio);
$pendentes = venda_conversas_pendentes($studio, $limite);

if (!$pendentes) {
    log_venda('Nada pendente.');
    exit(0);
}

log_venda('Analisando ' . count($pendentes) . ' conversa(s) com ' . $cfg['modelo'] . '.');

$ok = 0;
$erro = 0;
$vendas = 0;
$inicio = microtime(true);

foreach ($pendentes as $c) {
    $t0 = microtime(true);
    $r = venda_analisar_conversa($studio, (int)$c['id']);
    $seg = round(microtime(true) - $t0, 1);

    if (!empty($r['ok'])) {
        $ok++;
        if (!empty($r['fechou'])) {
            $vendas++;
        }
        log_venda(sprintf(
            'conv#%d %s | %s | confianca %d | valor %.2f | %ss | %s',
            (int)$c['id'],
            (string)$c['phone'],
            !empty($r['fechou']) ? 'FECHOU' : 'nao fechou',
            (int)$r['confianca'],
            (float)$r['valor'],
            $seg,
            mb_substr((string)$r['motivo'], 0, 90)
        ));
    } else {
        $erro++;
        log_venda(sprintf('conv#%d FALHOU (%ss): %s', (int)$c['id'], $seg, (string)($r['erro'] ?? '')));
    }
}

log_venda(sprintf(
    'Fim: %d analisadas, %d com venda, %d erros, %.1fs no total.',
    $ok,
    $vendas,
    $erro,
    round(microtime(true) - $inicio, 1)
));

exit(0);
