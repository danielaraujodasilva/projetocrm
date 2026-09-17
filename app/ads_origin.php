<?php
/**
 * Rastreio de origem do cliente (Opcao 1 - tag no link/primeiro contato).
 *
 * Fluxo:
 *  - Anuncio aponta para /projetocrm/ir.php?src=meta (ou google/instagram/indicacao/...)
 *  - ir.php redireciona para o WhatsApp com a mensagem "Vim pelo META ..."
 *  - Quando a mensagem chega, ads_detect_origin() reconhece a marca na 1a mensagem
 *    e ads_apply_origin_to_conversation() grava em leads.source (sem sobrescrever manual).
 */
declare(strict_types=1);

/**
 * Detecta a origem a partir do texto da mensagem.
 * Retorna 'meta' | 'google' | 'instagram' | 'indicacao' | 'porta' | 'reincidente' | '' se nao reconhecer.
 */
function ads_detect_origin(string $text): string
{
    $t = mb_strtolower(trim($text));
    if ($t === '') {
        return '';
    }
    $map = [
        'meta'        => ['vim pelo meta', 'pelo meta', 'vi no meta', 'anuncio do meta', 'anúncio do meta', 'facebook', 'instagram do meta'],
        'google'      => ['vim pelo google', 'pelo google', 'vi no google', 'anuncio do google', 'anúncio do google', 'pesquisei no google'],
        'instagram'   => ['vim pelo instagram', 'pelo instagram', 'vi no instagram', 'instagram', 'insta'],
        'indicacao'   => ['indicacao', 'indicação', 'indicado', 'indicada', 'me indicaram', 'boca a boca'],
        'porta'       => ['passei na frente', 'passei por ai', 'passei por aí', 'vizinho', 'do lado do estudio', 'do lado do estúdio'],
        'reincidente' => ['ja sou cliente', 'já sou cliente', 'ja tatuei', 'já tatuei', 'sou cliente', 'voltei'],
    ];
    foreach ($map as $src => $phrases) {
        foreach ($phrases as $p) {
            if (mb_strpos($t, $p) !== false) {
                return $src;
            }
        }
    }
    return '';
}

/**
 * Se a conversa ainda nao tem lead com origem definida, cria/atualiza com a origem detectada.
 * Nunca sobrescreve uma origem ja preenchida manualmente.
 */
function ads_apply_origin_to_conversation(array $studio, int $conversationId, string $text): bool
{
    $src = ads_detect_origin($text);
    if ($src === '' || $conversationId <= 0) {
        return false;
    }
    try {
        $pdo = studio_db($studio);
        // Descobre o telefone/lead da conversa
        $stmt = $pdo->prepare('SELECT id, phone FROM whatsapp_conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            return false;
        }
        $phone = preg_replace('/\D+/', '', (string)($conv['phone'] ?? ''));

        // Procura lead existente pelo telefone
        $leadId = 0;
        if ($phone !== '') {
            $ls = $pdo->prepare('SELECT id, source FROM leads WHERE phone = ? OR phone = ? ORDER BY id DESC LIMIT 1');
            $ls->execute([$phone, '+' . $phone]);
            $row = $ls->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $leadId = (int)$row['id'];
                $cur = strtolower(trim((string)($row['source'] ?? '')));
                // Nao sobrescreve origem ja definida (manual ou anterior)
                if ($cur !== '' && !in_array($cur, ['manual', 'google_calendar', 'whatsapp'], true)) {
                    return false;
                }
                $upd = $pdo->prepare('UPDATE leads SET source = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$src, $leadId]);
                return true;
            }
        }
        // Sem lead ainda: nao cria (o fluxo normal cria). Marca a conversa para o proximo lead herdar.
        $pdo->prepare('UPDATE whatsapp_conversations SET ai_last_status = ? WHERE id = ?')
            ->execute(['origem:' . $src, $conversationId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Conta LEADS por origem no periodo (data de criacao do lead).
 *
 * Diferente de ads_roi_by_origin(): aqui conta o lead em si, nao o agendamento.
 * Serve para responder "quantos contatos cada canal trouxe", que e a base do
 * custo por lead. Retorna [origem => n] e o total no indice '__total'.
 */
function ads_leads_by_origin(PDO $pdo, string $start, string $end): array
{
    try {
        $sql = 'SELECT COALESCE(NULLIF(TRIM(source), ""), "sem_origem") AS origem,
                       COUNT(*) AS total
                FROM leads
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY origem ORDER BY total DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start, $end]);
        $out = ['__total' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $origem = strtolower(trim((string)$row['origem']));
            $n = (int)$row['total'];
            $out[$origem] = ($out[$origem] ?? 0) + $n;
            $out['__total'] += $n;
        }
        return $out;
    } catch (Throwable $e) {
        return ['__total' => 0];
    }
}

/**
 * Conta os cliques de rastreio (ads_src_hits) por origem. Indica quantas
 * pessoas clicaram no link antes de virar lead - mostra a perda no caminho.
 */
function ads_hits_by_origin(PDO $pdo, string $start, string $end): array
{
    try {
        $sql = 'SELECT src, COUNT(*) AS total FROM ads_src_hits
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY src ORDER BY total DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start, $end]);
        $out = ['__total' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $src = strtolower(trim((string)$row['src']));
            $n = (int)$row['total'];
            $out[$src] = ($out[$src] ?? 0) + $n;
            $out['__total'] += $n;
        }
        return $out;
    } catch (Throwable $e) {
        return ['__total' => 0];
    }
}

/**
 * Conta agendamentos com valor por origem (para a pagina de ROI quando houver origem).
 * Retorna [origem => ['agendamentos'=>n, 'valor'=>v]]
 */
function ads_roi_by_origin(PDO $pdo, string $start, string $end): array
{
    try {
        $sql = 'SELECT COALESCE(NULLIF(l.source, ""), "sem_origem") AS origem,
                       COUNT(*) AS agendamentos,
                       SUM(CASE WHEN a.value > 0 THEN a.value ELSE 0 END) AS valor
                FROM appointments a
                LEFT JOIN leads l ON l.id = a.lead_id
                WHERE DATE(a.created_at) BETWEEN ? AND ?
                GROUP BY origem ORDER BY valor DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start, $end]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['origem']] = [
                'agendamentos' => (int)$row['agendamentos'],
                'valor' => (float)$row['valor'],
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
