<?php
/**
 * Job agendado: analisa VENDA nas conversas do WhatsApp usando a IA local.
 *
 * Roda em segundo plano, nunca na navegacao do usuario.
 *
 * ORCAMENTO DE TEMPO, NAO NUMERO FIXO
 * A rodada continua analisando enquanto houver fila e tempo houver. Se ainda
 * sobrar fila ao acabar o tempo, o proprio script faz outra passada imediata
 * (ate um limite de passadas), em vez de esperar a proxima rodada agendada.
 * Assim a analise alcanca o volume em vez de acumular atraso.
 *
 * Uso:
 *   php scripts/analisar_vendas.php [segundos] [max_passadas]
 *
 * Exemplo (10 min de trabalho, ate 3 passadas):
 *   php scripts/analisar_vendas.php 600 3
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once APP_BASE_PATH . '/app/venda_ia.php';

$orcamento = isset($argv[1]) ? max(60, min(3600, (int)$argv[1])) : 600;
$maxPassadas = isset($argv[2]) ? max(1, min(10, (int)$argv[2])) : 3;

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

// Sem IA local no ar, nao ha o que fazer: sai quieto (o painel avisa o dono).
if (!venda_ia_online($studio)) {
    log_venda('IA local (Ollama) indisponivel - analise ignorada nesta rodada.');
    exit(0);
}

$cfg = venda_config($studio);
$filaInicial = venda_fila_tamanho($studio);

if ($filaInicial === 0) {
    log_venda('Nada pendente.');
    exit(0);
}

log_venda(sprintf(
    'Fila inicial: %d conversa(s). Modelo %s. Orcamento %ds, ate %d passada(s).',
    $filaInicial,
    $cfg['modelo'],
    $orcamento,
    $maxPassadas
));

$totalAnalisadas = 0;
$totalErros = 0;
$totalIgnoradas = 0;
$passada = 0;

while ($passada < $maxPassadas) {
    $passada++;
    $restante = 0;

    $r = venda_analisar_lote($studio, $orcamento, (int)$cfg['max_por_rodada']);

    $totalAnalisadas += (int)$r['analisadas'];
    $totalErros += (int)$r['erros'];
    $totalIgnoradas += (int)$r['ignoradas'];
    $restante = (int)$r['restantes'];

    log_venda(sprintf(
        'Passada %d: %d analisadas, %d ignoradas, %d erros, %ss | fila restante: %d',
        $passada,
        (int)$r['analisadas'],
        (int)$r['ignoradas'],
        (int)$r['erros'],
        $r['segundos'],
        $restante
    ));

    // Nada mais a fazer.
    if ($restante === 0 || (int)$r['analisadas'] === 0) {
        break;
    }
    // Ainda tem fila e ainda temos passadas: continua imediatamente.
    log_venda('Ainda ha fila: repetindo agora (passada ' . ($passada + 1) . ').');
}

$filaFinal = venda_fila_tamanho($studio);

log_venda(sprintf(
    'Fim: %d analisadas, %d ignoradas, %d erros. Fila: %d -> %d.',
    $totalAnalisadas,
    $totalIgnoradas,
    $totalErros,
    $filaInicial,
    $filaFinal
));

// Deixou fila para tras? Registra de forma clara: o painel mostra esse aviso.
if ($filaFinal > (int)$cfg['alerta_fila']) {
    log_venda(sprintf(
        'ATENCAO: fila de %d conversas acima do limite (%d). Verifique o driver da GPU ou reduza o intervalo das rodadas.',
        $filaFinal,
        (int)$cfg['alerta_fila']
    ));
}

exit(0);
