<?php
/**
 * Baixador resiliente de modelos do LM Studio.
 *
 * POR QUE EXISTE
 * O CLI `lms get` morre se a sessao que o chamou for encerrada (o download
 * fica em 0 MB). Este script roda como TAREFA AGENDADA, entao sobrevive:
 *   - se a sessao do agente cair
 *   - se o download falhar (retoma de onde parou)
 *
 * Grava o progresso num arquivo de status que o painel pode ler.
 *
 * Uso:
 *   php scripts/baixar_modelo.php "qwen/qwen3-30b-a3b"
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$modelo = trim((string)($argv[1] ?? 'qwen/qwen3-30b-a3b'));
$statusPath = APP_BASE_PATH . '/storage/logs/download_modelo.json';
$logPath = APP_BASE_PATH . '/storage/logs/download_modelo.log';

function dl_log(string $path, string $msg): void
{
    @file_put_contents($path, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function dl_status(string $path, array $dados): void
{
    @file_put_contents($path, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

$lms = 'C:\Users\server_spd\AppData\Local\Programs\LM Studio\resources\app\.webpack\lms.exe';
if (!is_file($lms)) {
    dl_status($statusPath, ['ok' => false, 'erro' => 'lms.exe nao encontrado', 'em' => date('c')]);
    exit(1);
}

dl_status($statusPath, [
    'ok' => true,
    'estado' => 'baixando',
    'modelo' => $modelo,
    'iniciado_em' => date('c'),
    'percentual' => 0,
]);
dl_log($logPath, 'Iniciando download de ' . $modelo);

// Roda o lms get com saida em tempo real, lendo o progresso.
$cmd = '"' . $lms . '" get ' . escapeshellarg($modelo) . ' --gguf --yes';
$descritores = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($cmd, $descritores, $pipes, APP_BASE_PATH);
if (!is_resource($proc)) {
    dl_status($statusPath, ['ok' => false, 'erro' => 'nao consegui iniciar o lms', 'em' => date('c')]);
    exit(1);
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$buffer = '';
$ultimoPercentual = -1;
$inicio = time();
$ultimoMovimento = time();

while (true) {
    $saida = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    if ($saida !== '') {
        $buffer .= $saida;
        $ultimoMovimento = time();

        // O lms imprime linhas tipo "61.95% | 3.11 GB / 5.03 GB".
        if (preg_match_all('/([\d]{1,3}(?:[.,]\d{1,2})?)%\s*\|\s*([\d.,]+ \w+)\s*\/\s*([\d.,]+ \w+)/u', $buffer, $m)) {
            $ultimo = count($m[1]) - 1;
            $pct = (float)str_replace(',', '.', $m[1][$ultimo]);
            if ((int)$pct !== $ultimoPercentual) {
                $ultimoPercentual = (int)$pct;
                dl_status($statusPath, [
                    'ok' => true,
                    'estado' => 'baixando',
                    'modelo' => $modelo,
                    'percentual' => round($pct, 1),
                    'baixado' => trim($m[2][$ultimo]),
                    'total' => trim($m[3][$ultimo]),
                    'atualizado_em' => date('c'),
                    'segundos' => time() - $inicio,
                ]);
            }
        }
        if (str_contains($buffer, 'Download completed')) {
            dl_log($logPath, 'Download concluido.');
            dl_status($statusPath, [
                'ok' => true,
                'estado' => 'concluido',
                'modelo' => $modelo,
                'percentual' => 100,
                'concluido_em' => date('c'),
                'segundos' => time() - $inicio,
            ]);
            break;
        }
    }

    $info = proc_get_status($proc);
    if (!$info['running']) {
        break;
    }

    // Sem movimento por 10 minutos: considera travado e sai para a tarefa tentar de novo.
    if ((time() - $ultimoMovimento) > 600) {
        dl_log($logPath, 'Sem progresso por 10 min; encerrando para retomar depois.');
        dl_status($statusPath, [
            'ok' => false,
            'estado' => 'travado',
            'modelo' => $modelo,
            'percentual' => $ultimoPercentual >= 0 ? $ultimoPercentual : 0,
            'erro' => 'sem progresso por 10 minutos',
            'em' => date('c'),
        ]);
        proc_terminate($proc);
        break;
    }

    usleep(1500000); // 1,5s
}

fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

dl_log($logPath, 'Processo do download finalizado.');
exit(0);
