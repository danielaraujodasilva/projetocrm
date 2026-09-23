<?php
/**
 * Job de retaguarda: religa conversas de WhatsApp orfas ao lead correspondente.
 *
 * POR QUE EXISTE (rede de seguranca)
 * A correcao principal (Opcao A) faz a conversa nascer vinculada e a ponte de
 * anuncio fechar o ciclo. Este script e a TERCEIRA linha de defesa: pega
 * qualquer conversa que tenha escapado - importacao de historico, mensagem que
 * chegou antes do lead, backfill manual - e liga pelo telefone.
 *
 * O QUE FAZ
 *   - Varre whatsapp_conversations com lead_id NULL/0.
 *   - Casa pelo telefone (mesma tolerancia do CRM: DDI, 9o digito, formatacao).
 *   - Grava o lead_id na conversa. NUNCA cria lead (regra da Opcao A).
 *   - Reprocessa a analise de venda das conversas recem-vinculadas que tenham
 *     origem de anuncio, para o card de ROI refletir a origem.
 *
 * O QUE NAO FAZ
 *   - Nao altera telefone, nome nem origem de lead nenhum.
 *   - Nao cria, nao apaga e nao move conversa.
 *   - Sem IA no ar, nao quebra: so pula a parte de analise.
 *
 * Uso:  php scripts/vincular_conversas_orfaos.php [--dry-run]
 * Saida: uma linha de resumo (para o job agendado conferir) + codigo de saida 0.
 */
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'CLI';

require dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_BASE_PATH . '/app/venda_ia.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$studio = null;
try {
    $studio = get_studio(1);
} catch (Throwable $e) {
    echo "VINCULAR|ERRO|estudio_indisponivel|" . $e->getMessage() . PHP_EOL;
    exit(1);
}
if (!$studio) {
    echo "VINCULAR|ERRO|nenhum_estudio" . PHP_EOL;
    exit(1);
}

$pdo = studio_db($studio);

try {
    $orfaos = $pdo->query(
        'SELECT id, phone FROM whatsapp_conversations
         WHERE (lead_id IS NULL OR lead_id = 0)
         ORDER BY id DESC LIMIT 800'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $vinculadas = 0;
    $semPar = 0;

    foreach ($orfaos as $conv) {
        $phone = (string)($conv['phone'] ?? '');
        // Telefone que nao e telefone (LID do WhatsApp) nunca vai casar. Conta
        // separado para o resumo nao parecer falha: e limitacao conhecida.
        $digitos = preg_replace('/\D+/', '', $phone);
        $pareceTelefone = strlen($digitos) >= 10 && strlen($digitos) <= 13;

        if (!$pareceTelefone) {
            $semPar++;
            continue;
        }

        if ($dryRun) {
            // Em dry-run so reporta o que faria.
            $achou = false;
            $lista = $pdo->query('SELECT id, phone FROM leads WHERE phone IS NOT NULL AND phone <> "" ORDER BY updated_at DESC, id DESC LIMIT 500');
            foreach ($lista->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lead) {
                if (phones_match((string)$lead['phone'], $digitos)) {
                    $achou = true;
                    break;
                }
            }
            if ($achou) {
                $vinculadas++;
            } else {
                $semPar++;
            }
            continue;
        }

        if (ads_vincular_conversa_ao_lead($studio, (int)$conv['id'], $digitos)) {
            $vinculadas++;
        } else {
            $semPar++;
        }
    }

    // Analise: so das conversas vinculadas que ainda nao tem veredito.
    $analisadas = 0;
    $iaOnline = function_exists('venda_ia_online') && venda_ia_online($studio);
    if (!$dryRun && $vinculadas > 0 && $iaOnline) {
        try {
            $r = venda_analisar_lote($studio, 240, 30);
            $analisadas = (int)($r['analisadas'] ?? 0);
        } catch (Throwable $e) {
            // Analise falhou nao invalida o vinculo, que ja foi gravado.
        }
    }

    $modo = $dryRun ? 'DRY_RUN' : 'OK';
    $ia = $iaOnline ? 'online' : 'offline';
    echo "VINCULAR|{$modo}|vinculadas={$vinculadas}|sem_par={$semPar}|analisadas={$analisadas}|ia={$ia}" . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo "VINCULAR|ERRO|" . $e->getMessage() . PHP_EOL;
    exit(1);
}
