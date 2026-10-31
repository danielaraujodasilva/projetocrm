<?php
/**
 * Limpeza da caixa do Gmail do estudio.
 * Move para a lixeira (recuperavel por 30 dias) apenas o que e lixo:
 *   - mensagens na pasta SPAM
 *   - categoria PROMOCOES, SOCIAL e FORUNS
 * Apenas mensagens com mais de 15 dias, para nao mexer no que chegou agora.
 *
 * Uso:
 *   php scripts/limpar_gmail.php            -> so mostra o que faria (dry-run)
 *   php scripts/limpar_gmail.php --apagar   -> move de verdade para a lixeira
 *   php scripts/limpar_gmail.php --apagar --limite=800
 *
 * Código de saída: 0 quando conclui, 1 quando falha.
 */
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'CLI';
require dirname(__DIR__) . '/app/bootstrap.php';

$apagar = in_array('--apagar', array_slice($argv ?? [], 1), true);
$limite = 500;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--limite=(\d+)$/', (string)$arg, $m)) {
        $limite = max(1, min(2000, (int)$m[1]));
    }
}

// CRM é multi-tenant; a caixa conectada é o estúdio do Daniel (cereja, id 1).
$studio = get_studio(1);
if (!$studio) {
    fwrite(STDERR, "LIMPEZA_ERRO|estudio 1 nao encontrado\n");
    exit(1);
}

// De proposito NAO entram "social" e "foruns": ali costumam cair avisos e faturas
// (Meta, GitHub etc). Tambem fica de fora tudo que tem anexo, porque boleto vem em PDF.
$alvos = [
    'spam' => 'in:spam older_than:15d -has:attachment',
    'promocoes' => 'category:promotions older_than:15d -has:attachment',
];

$total = 0;
$apagadas = 0;
$erros = 0;
$resumo = [];

try {
    foreach ($alvos as $nome => $consulta) {
        $ids = google_gmail_ids($studio, $consulta, $limite);
        $quantidade = count($ids);
        $total += $quantidade;
        $resumo[$nome] = $quantidade;

        if ($quantidade > 0 && !$apagar) {
            // Mostra uma amostra dos assuntos para conferencia.
            foreach (array_slice($ids, 0, 5) as $id) {
                $mensagem = google_gmail_message(google_gmail_access_token($studio), $id);
                fwrite(STDOUT, '  amostra ' . $nome . ' | ' . substr($mensagem['date'], 0, 10) . ' | ' . mb_substr((string)$mensagem['from'], 0, 45) . ' | ' . mb_substr((string)$mensagem['subject'], 0, 70) . PHP_EOL);
            }
        }

        if (!$apagar) {
            continue;
        }
        foreach ($ids as $id) {
            if (google_gmail_trash($studio, $id)) {
                $apagadas++;
            } else {
                $erros++;
            }
        }
        fwrite(STDOUT, 'LIMPEZA_ALVO|' . $nome . '|encontradas=' . $quantidade . '|lixeira=' . $apagadas . '|erros=' . $erros . PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'LIMPEZA_ERRO|' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$modo = $apagar ? 'aplicado' : 'dry-run';
$linha = 'LIMPEZA_' . strtoupper($modo) . '|total=' . $total
    . '|lixeira=' . $apagadas
    . '|erros=' . $erros
    . '|' . http_build_query($resumo, '', ' ');
fwrite(STDOUT, $linha . PHP_EOL);
@file_put_contents(__DIR__ . '/limpar_gmail.log', date('c') . ' ' . $linha . PHP_EOL, FILE_APPEND);
exit(0);