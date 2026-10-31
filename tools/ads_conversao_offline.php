<?php
/**
 * Conversoes offline do Google Ads - runner de linha de comando.
 *
 * O QUE FAZ
 *   --relatorio : mostra o Google x o CRM (conferencia do rastreio)
 *   --preparar  : descobre/cria as acoes "Import from clicks" e grava os ids
 *   --simular   : mostra o que SERIA enfileirado/enviado, sem tocar na API
 *   --validar   : valida no Google (validateOnly) sem gravar nada
 *   --apply     : enfileira os milestones reais e envia ao Google Ads
 *
 * Uso:
 *   php tools/ads_conversao_offline.php --relatorio --dias=30
 *   php tools/ads_conversao_offline.php --simular   --dias=30
 *   php tools/ads_conversao_offline.php --apply     --dias=30 --limite=50
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$opcoes = ['relatorio' => false, 'preparar' => false, 'simular' => false, 'validar' => false, 'apply' => false, 'dias' => 30, 'limite' => 50, 'help' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(relatorio|preparar|simular|validar|apply|help)$/', $arg, $m)) {
        $opcoes[$m[1]] = true;
    } elseif (preg_match('/^--(dias|limite)=(\d+)$/', $arg, $m)) {
        $opcoes[$m[1]] = (int)$m[2];
    } elseif ($arg === '-h') {
        $opcoes['help'] = true;
    }
}

if ($opcoes['help'] || (!$opcoes['relatorio'] && !$opcoes['preparar'] && !$opcoes['simular'] && !$opcoes['validar'] && !$opcoes['apply'])) {
    echo "Uso: php tools/ads_conversao_offline.php [--relatorio] [--preparar] [--simular] [--validar] [--apply] [--dias=30] [--limite=50]\n";
    exit(0);
}

$studio = get_studio(1);
if (!$studio) {
    fwrite(STDERR, "Estudio 1 nao encontrado.\n");
    exit(1);
}
$pdo = studio_db($studio);
$ctx = ads_conv_offline_contexto($studio);
if (empty($ctx['ok'])) {
    fwrite(STDERR, 'Google Ads indisponivel: ' . (string)($ctx['error'] ?? '') . "\n");
    exit(1);
}

$dias = max(1, min(365, (int)$opcoes['dias']));
$ate = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
$de = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->modify('-' . ($dias - 1) . ' days')->format('Y-m-d');

if ($opcoes['preparar']) {
    $ids = ads_conv_offline_garantir_acoes($pdo, $ctx);
    echo 'Acao "Import from clicks" orcamento: ' . ($ids['orcamento'] !== '' ? $ids['orcamento'] : 'NAO ENCONTRADA') . "\n";
    echo 'Acao "Import from clicks" compra: ' . ($ids['compra'] !== '' ? $ids['compra'] : 'NAO ENCONTRADA') . "\n";
    exit(0);
}

if ($opcoes['relatorio']) {
    $rel = ads_conv_offline_relatorio($pdo, $ctx, $de, $ate);
    echo "== Conferencia de rastreio ($de a $ate) ==\n";
    if (!empty($rel['google_erro'])) {
        echo "Google Ads: erro - {$rel['google_erro']}\n";
    } else {
        echo "Google Ads (o que a conta reporta): {$rel['google_total']} conversoes | custo R$ {$rel['google_custo']}\n";
    }
    foreach ((array)($rel['crm']['ponte_por_origem'] ?? []) as $linha) {
        printf("  ponte %-12s contatos=%s com_click_id=%s\n", (string)$linha['origem'], (string)$linha['contatos'], (string)$linha['com_click_id']);
    }
    $funil = (array)($rel['crm']['funil'] ?? []);
    if ($funil) {
        printf("  CRM: telefones de anuncio=%s | com agendamento=%s\n", (string)($funil['telefones_ads'] ?? '0'), (string)($funil['com_agendamento'] ?? '0'));
    }
    if (!empty($rel['crm_erro'])) {
        echo "  CRM: erro - {$rel['crm_erro']}\n";
    }
    foreach ((array)$rel['fila'] as $linha) {
        printf("  fila de conversoes: %s=%s\n", (string)$linha['status'], (string)$linha['qtd']);
    }
    exit(0);
}

// Base do periodo: sem clique pago (gclid) nao existe conversao offline, entao
// este numero explica um "Milestones: 0" sem precisar investigar o banco.
$cliquesBase = 0;
try {
    $stBase = $pdo->prepare('SELECT COUNT(DISTINCT phone) FROM ads_origin_hits
                             WHERE click_id IS NOT NULL AND click_id <> "" AND created_at >= ?');
    $stBase->execute([date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'))]);
    $cliquesBase = (int)$stBase->fetchColumn();
} catch (Throwable $eBase) {
    $cliquesBase = 0;
}
echo 'Contatos com clique pago (Google) no periodo: ' . $cliquesBase . "\n";

$itens = ads_conv_offline_coletar($pdo, $dias);
echo 'Milestones reais com click id no periodo: ' . count($itens) . "\n";

if ($opcoes['simular']) {
    $novos = ads_conv_offline_enfileirar($pdo, $itens);
    echo "Enfileirados agora (dedup): {$novos}\n";
    $resumo = ads_conv_offline_enviar($pdo, $ctx, false, (int)$opcoes['limite']);
    echo "Simulacao: candidatas={$resumo['candidatas']} (nada foi enviado)\n";
    foreach ((array)($resumo['exemplos'] ?? []) as $ex) {
        echo '  ' . json_encode($ex, JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit(0);
}

$novos = ads_conv_offline_enfileirar($pdo, $itens);
echo "Enfileirados agora (dedup): {$novos}\n";
if ($opcoes['validar']) {
    $resumo = ads_conv_offline_enviar($pdo, $ctx, true, (int)$opcoes['limite'], true);
    echo 'Validacao no Google (nada gravado): candidatas=' . (int)$resumo['candidatas'] . "\n";
    foreach ((array)($resumo['ignoradas'] ?? []) as $acao => $qtd) {
        echo '  ignoradas sem acao configurada: ' . $acao . '=' . $qtd . "\n";
    }
    foreach ((array)($resumo['erros'] ?? []) as $erro) {
        echo '  erro: ' . $erro . "\n";
    }
    exit(empty($resumo['ok']) ? 1 : 0);
}
$resumo = ads_conv_offline_enviar($pdo, $ctx, true, (int)$opcoes['limite']);
echo 'Enviadas ao Google Ads: ' . (int)$resumo['enviadas'] . ' de ' . (int)$resumo['candidatas'] . "\n";
if (!empty($resumo['ignoradas'])) {
    echo 'Ignoradas (sem acao correspondente): ' . json_encode($resumo['ignoradas']) . "\n";
}
if (!empty($resumo['aguardando'])) {
    echo 'Aguardando janela da API (evento recente demais): ' . count((array)$resumo['aguardando']) . "\n";
}
if (!empty($resumo['erros'])) {
    foreach ($resumo['erros'] as $erro) {
        echo 'Erro: ' . $erro . "\n";
    }
    exit(1);
}
exit(0);
