<?php
/**
 * VARREdura: extrai nome completo das conversas da ponte via IA local
 * e grava no CRM (customers.name / leads.name) SOMENTE quando o nome atual
 * esta vazio ou repete o titulo do agendamento.
 *
 * Modo de uso:
 *   php scripts/extrair_nomes_ia.php --dry            (nao grava, so relatorio)
 *   php scripts/extrair_nomes_ia.php                  (grava)
 *   php scripts/extrair_nomes_ia.php --limite=50      (processa so 50)
 *
 * Progresso fica em dados/nomes_ia_checkpoint.json: pode reexecutar sem
 * repetir o que ja foi processado.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "So roda em CLI.\n");
    exit(1);
}

require __DIR__ . '/../app/bootstrap.php';

$dry = in_array('--dry', $argv, true);
$limite = 0;
foreach ($argv as $a) {
    if (preg_match('/^--limite=(\d+)$/', $a, $m)) {
        $limite = (int)$m[1];
    }
}

$ckptPath = APP_BASE_PATH . '/dados/nomes_ia_checkpoint.json';
$ckpt = ['processadas' => [], 'achados' => [], 'falhas' => []];
if (is_file($ckptPath)) {
    $j = json_decode((string)file_get_contents($ckptPath), true);
    if (is_array($j)) {
        $ckpt = array_merge($ckpt, $j);
    }
}

$cfg = nome_ia_config();
echo "Motor      : {$cfg['modelo']} @ {$cfg['url']}\n";
echo "sem_thinking: " . ($cfg['sem_thinking'] ? 'SIM' : 'nao') . "\n";
echo "max_chars  : {$cfg['max_chars']} | timeout: {$cfg['timeout']}s\n";
echo "Modo       : " . ($dry ? 'DRY-RUN (nao grava)' : 'GRAVANDO') . "\n";
echo str_repeat('=', 78) . "\n";

// --- Carrega conversas da ponte ---
$path = historico_conversas_path();
if (!is_file($path)) {
    fwrite(STDERR, "Arquivo da ponte nao existe: $path\n");
    exit(1);
}
$linhas = [];
$fh = fopen($path, 'r');
while (($l = fgets($fh)) !== false) {
    $m = json_decode(trim($l), true);
    if (is_array($m)) { $linhas[] = $m; }
}
fclose($fh);

$porFone = [];
foreach ($linhas as $m) {
    $f = historico_telefone_canonico($m);
    if ($f === '') { continue; }
    // Chave sempre como string: telefone so de digitos vira int no PHP e
    // quebra a assinatura tipada de crm_por_telefone().
    $f = (string)$f;
    $porFone[$f][] = $m;
}
echo "Conversas na ponte: " . count($porFone) . "\n";

$pdo = studio_db(get_studio(1));

// Mapa telefone -> registros do CRM (customers e leads).
function crm_por_telefone(PDO $pdo, string $fone): array
{
    $digits = preg_replace('/\D+/', '', $fone);
    $saida = ['customers' => [], 'leads' => []];
    if ($digits === '') { return $saida; }
    // Casa pelo numero COMPLETO (com ou sem DDI) ou pelos ultimos 8 digitos.
    // Sufixo curto pode casar com mais de um cadastro: quem chama decide se
    // grava (so grava quando o total de candidatos for exatamente 1).
    $sufixo = substr($digits, -8);
    $semDdi = str_starts_with($digits, '55') ? substr($digits, 2) : $digits;
    foreach (['customers', 'leads'] as $tabela) {
        try {
            $st = $pdo->prepare(
                "SELECT id, name, phone FROM $tabela
                  WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+',''),'.','') IN (?, ?)
                     OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+',''),'.','') LIKE ?
                  LIMIT 10"
            );
            $st->execute([$digits, $semDdi, '%' . $sufixo]);
            $saida[$tabela] = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // tabela/coluna ausente: segue com o outro
        }
    }
    return $saida;
}

$total = count($porFone);
$processadas = 0; $achados = 0; $gravados = 0; $falhas = 0; $puladas = 0;
$t0 = microtime(true);

foreach ($porFone as $fone => $msgs) {
    $fone = (string)$fone;
    if (isset($ckpt['processadas'][$fone])) { $puladas++; continue; }
    if ($limite > 0 && $processadas >= $limite) { break; }

    $tr = nome_ia_transcricao($msgs, $cfg['max_chars'], $cfg['chars_por_msg']);
    if (mb_strlen(trim($tr)) < 30) {
        $ckpt['processadas'][$fone] = ['nome' => '', 'motivo' => 'conversa curta demais'];
        $processadas++;
        continue;
    }

    $r = nome_ia_extrair($tr, $cfg);
    $processadas++;

    if (!$r['ok']) {
        $falhas++;
        $ckpt['falhas'][$fone] = $r['erro'];
        printf("[%-16s] FALHA: %s\n", $fone, $r['erro']);
        continue;
    }

    $nome = trim($r['nome']);
    if ($nome === '') {
        $ckpt['processadas'][$fone] = ['nome' => '', 'conf' => $r['confianca']];
        continue;
    }

    // Descarta resposta invalida (lugar/endereco ou primeiro nome sozinho).
    // Filtro no codigo: a IA as vezes devolve esses casos com confianca alta.
    if (nome_ia_invalido($nome)) {
        printf("[%-16s] DESCARTADO: %s\n", $fone, $nome);
        $ckpt['processadas'][$fone] = ['nome' => '', 'conf' => 0, 'motivo' => 'invalido'];
        continue;
    }

    $achados++;
    $ckpt['achados'][$fone] = ['nome' => $nome, 'conf' => $r['confianca']];

    // --- Gravar no CRM (regras do dono) ---
    //   1) so onde JA existe cadastro (nunca cria cliente/lead);
    //   2) so quando o telefone casa em UM UNICO cadastro (ambiguidade = nao grava);
    //   3) so se o name estiver vazio ou igual ao titulo/valor atual.
    $alvos = crm_por_telefone($pdo, $fone);
    $totalCadastros = count($alvos['customers']) + count($alvos['leads']);
    $linhasLog = [];

    if ($totalCadastros === 0) {
        $linhasLog[] = 'sem cadastro no CRM (so ponte)';
    } elseif ($totalCadastros > 1) {
        $linhasLog[] = "AMBIGUO ($totalCadastros cadastros) - nao gravado";
    } else {
        foreach (['customers', 'leads'] as $tabela) {
            foreach ($alvos[$tabela] as $reg) {
                $atual = trim((string)($reg['name'] ?? ''));
                $vazio = $atual === '';
                if (!$vazio && mb_strtolower($atual, 'UTF-8') === mb_strtolower($nome, 'UTF-8')) {
                    $linhasLog[] = "$tabela#{$reg['id']} ja igual";
                    continue;
                }
                if (!$vazio) {
                    $linhasLog[] = "$tabela#{$reg['id']} PRESERVADO ('" . mb_substr($atual, 0, 24) . "')";
                    continue;
                }
                if (!$dry) {
                    try {
                        $st = $pdo->prepare("UPDATE $tabela SET name = ? WHERE id = ? AND (name IS NULL OR name = '')");
                        $st->execute([$nome, (int)$reg['id']]);
                        if ($st->rowCount() > 0) { $gravados++; }
                    } catch (Throwable $e) {
                        $linhasLog[] = "$tabela#{$reg['id']} ERRO " . $e->getMessage();
                        continue;
                    }
                }
                $linhasLog[] = "$tabela#{$reg['id']} " . ($dry ? 'GRAVARIA' : 'GRAVADO');
            }
        }
    }

    printf("[%-16s] conf=%3d %-34s | %s\n", $fone, $r['confianca'], mb_substr($nome, 0, 34), implode('; ', $linhasLog));
    // Salva checkpoint a cada 10 conversas (nao perde progresso em queda).
    if ($processadas % 10 === 0) {
        file_put_contents($ckptPath, json_encode($ckpt, JSON_UNESCAPED_UNICODE));
    }
}

file_put_contents($ckptPath, json_encode($ckpt, JSON_UNESCAPED_UNICODE));

$dt = round(microtime(true) - $t0, 1);
echo str_repeat('=', 78) . "\n";
echo "Processadas nesta rodada : $processadas\n";
echo "Puladas (ja no checkpoint): $puladas\n";
echo "Nomes achados            : $achados\n";
echo "Gravados no CRM          : $gravados\n";
echo "Falhas                   : $falhas\n";
echo "Tempo                    : {$dt}s\n";
echo "Checkpoint               : $ckptPath\n";
