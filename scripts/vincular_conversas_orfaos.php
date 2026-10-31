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
 *   - Se o agendamento veio do Google Agenda sem telefone, tenta o NOME COMPLETO
 *     exato nas mensagens da conversa (janela de 14 dias e vinculo unico).
 *   - Grava o lead_id na conversa. NUNCA cria lead (regra da Opcao A).
 *   - Reprocessa a analise de venda das conversas recem-vinculadas que tenham
 *     origem de anuncio, para o card de ROI refletir a origem.
 *
 * O QUE NAO FAZ
 *   - Nao altera nome nem origem de lead nenhum. No casamento por nome, grava o
 *     telefone da conversa no lead que esta sem telefone.
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

/** Nome do cliente na primeira parte do titulo da agenda. */
function vincular_orfaos_nome_do_titulo(string $titulo, string $rawTitulo): string
{
    $base = trim((string)(explode(',', trim($rawTitulo) !== '' ? $rawTitulo : $titulo)[0] ?? ''));
    if ($base === '' || preg_match('/\d/u', $base)) {
        return '';
    }
    $nome = function_exists('crm_telefone_normalizar')
        ? crm_telefone_normalizar($base)
        : trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]/', ' ', mb_strtolower($base, 'UTF-8'))) ?? '');
    if ($nome === '') {
        return '';
    }
    $ruido = ['cliente', 'tatuagem', 'tattoo', 'fechamento', 'costas', 'peito', 'braco', 'perna', 'coxa', 'pomada', 'pomadas', 'sinal', 'pago', 'pix', 'agenda', 'google', 'whatsapp'];
    $tokens = array_values(array_filter(
        explode(' ', $nome),
        static fn(string $token): bool => mb_strlen($token) >= 2 && !in_array($token, $ruido, true)
    ));
    $significativos = array_filter($tokens, static fn(string $token): bool => mb_strlen($token) >= 3);
    if (count($significativos) < 2 || count($tokens) > 8) {
        return '';
    }
    return implode(' ', $tokens);
}

/** Mensagens da conversa dentro da janela do agendamento. */
function vincular_orfaos_mensagens(PDO $pdo, int $conversationId, string $de, string $ate): array
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(sent_at, created_at) AS em,
                COALESCE(body, "") AS body,
                COALESCE(transcricao, "") AS transcricao,
                COALESCE(transcript, "") AS transcript
           FROM whatsapp_messages
          WHERE conversation_id = ?
            AND COALESCE(sent_at, created_at) BETWEEN ? AND ?
          ORDER BY COALESCE(sent_at, created_at) ASC, id ASC'
    );
    $stmt->execute([$conversationId, $de, $ate]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $texto = (string)$row['body'] . ' ' . (string)$row['transcricao'] . ' ' . (string)$row['transcript'];
        $texto = function_exists('crm_telefone_normalizar')
            ? crm_telefone_normalizar($texto)
            : trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]/', ' ', mb_strtolower($texto, 'UTF-8'))) ?? '');
        if ($texto !== '') {
            $out[] = ['em' => (string)$row['em'], 'texto' => $texto];
        }
    }
    return $out;
}

/**
 * Terceira rede de seguranca: agenda importada sem telefone x conversa orfa.
 *
 * So liga quando o nome COMPLETO do titulo aparece como frase inteira em uma
 * mensagem dentro de +/-14 dias do atendimento. Se o mesmo nome apontar para
 * mais de uma conversa/lead, nao adivinha. Os nomes curtos e genericos ficam de
 * fora porque exigimos ao menos dois tokens significativos.
 */
function vincular_orfaos_por_nome(array $studio, PDO $pdo, array $orfaos, bool $dryRun): array
{
    $deAgenda = date('Y-m-d', strtotime('-180 days'));
    $ateAgenda = date('Y-m-d', strtotime('+30 days'));
    $stmt = $pdo->prepare(
        'SELECT a.id AS appointment_id, a.lead_id, a.title, a.raw_title, a.appointment_date
           FROM appointments a
           INNER JOIN leads l ON l.id = a.lead_id
          WHERE a.import_source = "google_calendar"
            AND a.appointment_date BETWEEN ? AND ?
            AND COALESCE(l.phone, "") = ""
          ORDER BY a.appointment_date ASC, a.id ASC'
    );
    $stmt->execute([$deAgenda, $ateAgenda]);
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$apps) {
        return [];
    }

    $porNome = [];
    foreach ($apps as $app) {
        $nome = vincular_orfaos_nome_do_titulo((string)($app['title'] ?? ''), (string)($app['raw_title'] ?? ''));
        if ($nome !== '') {
            $porNome[$nome][] = $app;
        }
    }
    if (!$porNome) {
        return [];
    }

    $msgDe = date('Y-m-d 00:00:00', strtotime('-194 days'));
    $msgAte = date('Y-m-d 23:59:59', strtotime('+44 days'));
    $candidatos = [];
    foreach ($orfaos as $conv) {
        $digitos = preg_replace('/\D+/', '', (string)($conv['phone'] ?? ''));
        if (strlen($digitos) < 10 || strlen($digitos) > 13) {
            continue;
        }
        $mensagens = vincular_orfaos_mensagens($pdo, (int)$conv['id'], $msgDe, $msgAte);
        if (!$mensagens) {
            continue;
        }
        foreach ($porNome as $nome => $agendas) {
            $melhor = null;
            foreach ($agendas as $app) {
                $data = (string)($app['appointment_date'] ?? '');
                if ($data === '') {
                    continue;
                }
                foreach ($mensagens as $msg) {
                    if (!str_contains(' ' . $msg['texto'] . ' ', ' ' . $nome . ' ')) {
                        continue;
                    }
                    $dias = (int)floor(abs(strtotime(substr($msg['em'], 0, 10)) - strtotime($data)) / 86400);
                    if ($dias > 14) {
                        continue;
                    }
                    if ($melhor === null || $dias < $melhor['dias']) {
                        $melhor = [
                            'appointment_id' => (int)$app['appointment_id'],
                            'lead_id' => (int)$app['lead_id'],
                            'nome' => $nome,
                            'dias' => $dias,
                        ];
                    }
                }
            }
            if ($melhor !== null) {
                $candidatos[(int)$conv['id']][] = $melhor;
            }
        }
    }

    // Um lead so pode receber UMA conversa e uma conversa so pode apontar para
    // UM lead. Qualquer ambiguidade fica para revisao humana.
    $porLead = [];
    foreach ($candidatos as $convId => $lista) {
        $leads = array_values(array_unique(array_map(static fn(array $r): int => $r['lead_id'], $lista)));
        if (count($leads) !== 1) {
            continue;
        }
        $porLead[$leads[0]][] = $convId;
    }

    $finais = [];
    foreach ($porLead as $leadId => $convIds) {
        $convIds = array_values(array_unique($convIds));
        if (count($convIds) !== 1) {
            continue;
        }
        $convId = (int)$convIds[0];
        $escolhido = null;
        foreach ($candidatos[$convId] as $cand) {
            if ((int)$cand['lead_id'] !== (int)$leadId) {
                continue;
            }
            if ($escolhido === null || $cand['dias'] < $escolhido['dias']) {
                $escolhido = $cand;
            }
        }
        if ($escolhido !== null) {
            $finais[] = ['conversation_id' => $convId] + $escolhido;
        }
    }

    $salvos = [];
    $pdo->beginTransaction();
    try {
        foreach ($finais as $match) {
            $convStmt = $pdo->prepare('SELECT phone, lead_id FROM whatsapp_conversations WHERE id = ? LIMIT 1');
            $convStmt->execute([(int)$match['conversation_id']]);
            $conv = $convStmt->fetch(PDO::FETCH_ASSOC);
            if (!$conv || (int)($conv['lead_id'] ?? 0) > 0) {
                continue;
            }
            $phone = preg_replace('/\D+/', '', (string)($conv['phone'] ?? ''));
            $leadStmt = $pdo->prepare('SELECT phone FROM leads WHERE id = ? LIMIT 1');
            $leadStmt->execute([(int)$match['lead_id']]);
            $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) {
                continue;
            }
            $leadPhone = preg_replace('/\D+/', '', (string)($lead['phone'] ?? ''));
            if ($leadPhone !== '' && !phones_match($leadPhone, $phone)) {
                continue;
            }
            if ($leadPhone === '') {
                $pdo->prepare('UPDATE leads SET phone = ?, updated_at = NOW() WHERE id = ? AND COALESCE(phone, "") = ""')
                    ->execute([$phone, (int)$match['lead_id']]);
            }
            $upd = $pdo->prepare('UPDATE whatsapp_conversations SET lead_id = ?, updated_at = NOW() WHERE id = ? AND (lead_id IS NULL OR lead_id = 0)');
            $upd->execute([(int)$match['lead_id'], (int)$match['conversation_id']]);
            if ($upd->rowCount() === 1) {
                $salvos[] = $match;
            }
        }
        if ($dryRun) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $salvos;
}

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

    // Agendamentos importados do Google Agenda chegam sem telefone. Quando a
    // conversa ficou orfa, o nome completo escrito na conversa e a unica ponte
    // segura entre o atendimento e o WhatsApp.
    $porNome = vincular_orfaos_por_nome($studio, $pdo, $orfaos, $dryRun);
    foreach ($porNome as $match) {
        echo "VINCULAR_NOME|" . ($dryRun ? 'DRY_RUN' : 'OK') . '|conv=' . (int)$match['conversation_id']
            . '|lead=' . (int)$match['lead_id']
            . '|agenda=' . (int)$match['appointment_id']
            . '|nome=' . (string)$match['nome'] . PHP_EOL;
        if (!$dryRun && function_exists('studio_refresh_lead_status_from_appointments')) {
            studio_refresh_lead_status_from_appointments($studio, (int)$match['lead_id']);
        }
    }
    $vinculadas += count($porNome);
    $semPar = max(0, $semPar - count($porNome));

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
    echo "VINCULAR|{$modo}|vinculadas={$vinculadas}|por_nome=" . count($porNome) . "|sem_par={$semPar}|analisadas={$analisadas}|ia={$ia}" . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo "VINCULAR|ERRO|" . $e->getMessage() . PHP_EOL;
    exit(1);
}
