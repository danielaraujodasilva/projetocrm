<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

putenv('STUDIO_WHATSAPP_AI_DRY_RUN=1');
$_ENV['STUDIO_WHATSAPP_AI_DRY_RUN'] = '1';

$studio = db()->query('SELECT * FROM studios ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$studio) {
    fwrite(STDERR, "Nenhum estudio encontrado.\n");
    exit(1);
}

$pdo = studio_db($studio);
$runId = 'codexstress' . date('YmdHis');
$createdConversationIds = [];
$createdLeadIds = [];
$failures = [];
$reports = [];
$repeat = 1;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--repeat=(\d+)$/', (string)$arg, $match)) {
        $repeat = max(1, min(20, (int)$match[1]));
    }
}

function stress_insert_lead(PDO $pdo, string $name, string $phone, string $interest = 'Teste IA Codex'): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO leads (name, phone, interest, status, pipeline_stage, lead_score, source, created_at, updated_at)
         VALUES (?, ?, ?, "novo", "entrada", 0, "codex_stress", NOW(), NOW())'
    );
    $stmt->execute([$name, $phone, $interest]);
    return (int)$pdo->lastInsertId();
}

function stress_insert_conversation(PDO $pdo, int $leadId, string $phone, string $name, string $memory = ''): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO whatsapp_conversations
            (lead_id, phone, name, attendance_mode, needs_human, lead_score, ai_memory, ai_memory_updated_at, ai_last_status, created_at, updated_at)
         VALUES (?, ?, ?, "bot", 0, 0, ?, IF(? = "", NULL, NOW()), "Teste Codex em preparo", NOW(), NOW())'
    );
    $stmt->execute([$leadId, $phone, $name, $memory, $memory]);
    return (int)$pdo->lastInsertId();
}

function stress_insert_message(PDO $pdo, int $conversationId, string $direction, string $senderType, string $body, string $type, string $messageId, int $offsetSeconds = 0, string $contextPreview = ''): array
{
    $sentAt = date('Y-m-d H:i:s', time() + $offsetSeconds);
    $stmt = $pdo->prepare(
        'INSERT INTO whatsapp_messages
            (conversation_id, direction, sender_type, body, message_type, message_id, context_preview, from_me, status, sent_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $conversationId,
        $direction,
        $senderType,
        $body,
        $type,
        $messageId,
        $contextPreview !== '' ? $contextPreview : null,
        $direction === 'out' ? 1 : 0,
        $direction === 'out' ? 'sent' : null,
        $sentAt,
    ]);
    $messageDbId = (int)$pdo->lastInsertId();
    $preview = $body !== '' ? mb_substr($body, 0, 250) : '[' . $type . ']';
    $pdo->prepare(
        'UPDATE whatsapp_conversations
         SET last_message_preview = ?, last_message_direction = ?, last_message_at = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([$preview, $direction, $sentAt, $conversationId]);
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_messages WHERE id = ?');
    $stmt->execute([$messageDbId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function stress_last_bot_reply(PDO $pdo, int $conversationId): string
{
    $stmt = $pdo->prepare(
        'SELECT body FROM whatsapp_messages
         WHERE conversation_id = ? AND direction = "out"
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$conversationId]);
    return trim((string)($stmt->fetchColumn() ?: ''));
}

function stress_run_case(array $studio, PDO $pdo, string $runId, array $case, array &$createdConversationIds, array &$createdLeadIds): array
{
    $phone = '55999' . substr(preg_replace('/\D+/', '', md5($runId . $case['name'])), 0, 8);
    $name = (string)($case['customer'] ?? 'Cliente Teste');
    $leadId = stress_insert_lead($pdo, $name, $phone, (string)($case['interest'] ?? 'Teste IA Codex'));
    $conversationId = stress_insert_conversation($pdo, $leadId, $phone, $name, (string)($case['memory'] ?? ''));
    $createdLeadIds[] = $leadId;
    $createdConversationIds[] = $conversationId;

    $message = [];
    $offset = 0;
    foreach (($case['history'] ?? []) as $historyItem) {
        $offset++;
        stress_insert_message(
            $pdo,
            $conversationId,
            (string)($historyItem['direction'] ?? 'in'),
            (string)($historyItem['sender_type'] ?? (($historyItem['direction'] ?? 'in') === 'out' ? 'bot' : 'customer')),
            (string)($historyItem['body'] ?? ''),
            (string)($historyItem['message_type'] ?? 'texto'),
            'hist-' . $runId . '-' . $conversationId . '-' . $offset,
            $offset,
            (string)($historyItem['context_preview'] ?? '')
        );
    }

    foreach (($case['messages'] ?? []) as $item) {
        $offset++;
        $message = stress_insert_message(
            $pdo,
            $conversationId,
            'in',
            'customer',
            (string)($item['body'] ?? ''),
            (string)($item['message_type'] ?? 'texto'),
            'in-' . $runId . '-' . $conversationId . '-' . $offset,
            $offset,
            (string)($item['context_preview'] ?? '')
        );
    }

    $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
    $result = studio_whatsapp_ai_reply($studio, $conversation ?: [], $message);
    $reply = stress_last_bot_reply($pdo, $conversationId);
    $checks = $case['checks'] ?? [];
    $errors = [];
    $replyPlain = studio_calendar_remove_accents(mb_strtolower($reply, 'UTF-8'));

    foreach (['inteligencia artificial', 'intelig^encia artificial', 'assistente virtual', 'estou aqui para ajudar', 'como posso ajudar'] as $forbiddenGlobal) {
        if (str_contains($replyPlain, $forbiddenGlobal)) {
            $errors[] = 'mencionou frase global proibida: ' . $forbiddenGlobal;
        }
    }

    foreach (($checks['must_contain_any'] ?? []) as $group) {
        $hit = false;
        foreach ((array)$group as $needle) {
            if (str_contains($replyPlain, studio_calendar_remove_accents(mb_strtolower((string)$needle, 'UTF-8')))) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $errors[] = 'nao encontrou nenhum termo esperado: ' . implode(' | ', (array)$group);
        }
    }
    foreach (($checks['must_not_contain'] ?? []) as $needle) {
        if (str_contains($replyPlain, studio_calendar_remove_accents(mb_strtolower((string)$needle, 'UTF-8')))) {
            $errors[] = 'mencionou termo proibido: ' . (string)$needle;
        }
    }
    foreach (($checks['must_not_match'] ?? []) as $pattern) {
        if (@preg_match($pattern, $reply) === 1) {
            $errors[] = 'caiu no padrao proibido: ' . $pattern;
        }
    }
    if (($checks['needs_human'] ?? null) !== null && (bool)($result['needs_human'] ?? false) !== (bool)$checks['needs_human']) {
        $errors[] = 'needs_human esperado=' . ((bool)$checks['needs_human'] ? 'true' : 'false') . ' recebido=' . (!empty($result['needs_human']) ? 'true' : 'false');
    }
    if (($checks['max_len'] ?? 0) > 0 && mb_strlen($reply, 'UTF-8') > (int)$checks['max_len']) {
        $errors[] = 'resposta longa demais: ' . mb_strlen($reply, 'UTF-8');
    }
    if ($reply === '') {
        $errors[] = 'sem resposta gravada';
    }
    if (empty($result['ok'])) {
        $errors[] = 'resultado nao ok: ' . (string)($result['error'] ?? 'erro desconhecido');
    }

    return [
        'name' => (string)$case['name'],
        'ok' => $errors === [],
        'errors' => $errors,
        'intent' => (string)($result['intent'] ?? ''),
        'needs_human' => !empty($result['needs_human']),
        'reply' => $reply,
    ];
}

$cases = [
    [
        'name' => 'mensagens_fragmentadas_orcamento',
        'customer' => 'Amanda Fragmentada',
        'messages' => [
            ['body' => 'Oi'],
            ['body' => 'boa noite'],
            ['body' => 'quero fazer'],
            ['body' => 'um orçamento'],
            ['body' => 'de tatuagem'],
        ],
        'checks' => [
            'must_contain_any' => [['referencia', 'ideia', 'tatuar', 'tamanho', 'local']],
            'must_not_contain' => ['como posso ajudar', 'pix', 'comprovante'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'mudanca_de_polvo_para_borboleta',
        'customer' => 'Denise Fake',
        'memory' => "Cliente queria tatuar um polvo grande na perna inteira.\nPendência: escolher vaga e enviar sinal via Pix.",
        'history' => [
            ['direction' => 'in', 'body' => 'Quero um polvo grandao na perna inteira'],
            ['direction' => 'out', 'body' => 'Para reservar, o sinal é R$ 50 via Pix.'],
        ],
        'messages' => [
            ['body' => 'Não quero agora uma borboletinha na frente do ombro uns 8cm'],
            ['body' => 'vou te mandar uma foto peraí'],
            ['body' => '', 'message_type' => 'image'],
        ],
        'checks' => [
            'must_contain_any' => [['borboleta', 'borboletinha', 'ombro', 'referencia']],
            'must_not_contain' => ['polvo', 'comprovante', 'pix', 'sinal'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'agenda_sem_orcamento_nao_pix',
        'customer' => 'Cliente Agenda',
        'messages' => [
            ['body' => 'Quero tatuar uma frase pequena'],
            ['body' => 'tem horário amanhã as 15?'],
        ],
        'checks' => [
            'must_contain_any' => [['horario', 'vaga', 'agenda', 'tamanho', 'referencia', 'local']],
            'must_not_contain' => ['pix', 'comprovante', 'envia o pix'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'quer_todas_datas_disponiveis',
        'customer' => 'Cliente Datas',
        'messages' => [
            ['body' => 'quero um leão no braço, uns 20cm, preto e cinza'],
            ['body' => 'quanto fica?'],
            ['body' => 'quais as próximas datas disponíveis pra agendar?'],
        ],
        'checks' => [
            'must_contain_any' => [['vaga', 'horario', 'data', 'agenda', 'disponivel']],
            'must_not_contain' => ['pix', 'parcelar em', 'maquininha'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'pedido_humano_mantem_ia_ativa',
        'customer' => 'Cliente Humano',
        'messages' => [
            ['body' => 'quero falar com atendente humano'],
        ],
        'checks' => [
            'must_contain_any' => [['equipe', 'atendente', 'sinalizada', 'avisei']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'cliente_irritado',
        'customer' => 'Cliente Bravo',
        'history' => [
            ['direction' => 'out', 'body' => 'Qual tamanho aproximado?'],
        ],
        'messages' => [
            ['body' => 'Já falei mulher, é no braço inteiro, você é burra?'],
        ],
        'checks' => [
            'must_contain_any' => [['equipe', 'atendente', 'desculpa', 'entendi']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'endereco_objetivo',
        'customer' => 'Cliente Endereco',
        'messages' => [
            ['body' => 'qual o endereço do estúdio?'],
        ],
        'checks' => [
            'must_contain_any' => [['rua', 'endereco', 'jardim', 'catende']],
            'must_not_contain' => ['referencia', 'tamanho aproximado'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'horario_funcionamento_nao_agenda',
        'customer' => 'Cliente Funcionamento',
        'messages' => [
            ['body' => 'qual horário de funcionamento?'],
        ],
        'checks' => [
            'must_contain_any' => [['funcionamento', 'atendimento', 'horario']],
            'must_not_contain' => ['referencia', 'pix', 'comprovante'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'link_referencia_pede_print_se_falhar',
        'customer' => 'Cliente Link',
        'messages' => [
            ['body' => 'quero tatuar igual esse link https://www.instagram.com/reel/DagRqiONhiZ/'],
        ],
        'checks' => [
            'must_contain_any' => [['print', 'imagem salva', 'referencia', 'link']],
            'must_not_contain' => ['pix', 'comprovante'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'confirmacao_curta_com_contexto',
        'customer' => 'Cliente Ok',
        'history' => [
            ['direction' => 'in', 'body' => 'quero fazer uma rosa no antebraço uns 12cm'],
            ['direction' => 'out', 'body' => 'Me manda uma referência para eu encaminhar o orçamento certinho?'],
        ],
        'messages' => [
            ['body' => 'ok'],
        ],
        'checks' => [
            'must_contain_any' => [['referencia', 'manda', 'foto', 'print']],
            'must_not_contain' => ['como posso ajudar', 'olá'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'pergunta_parcelamento',
        'customer' => 'Cliente Parcela',
        'history' => [
            ['direction' => 'in', 'body' => 'quero fechar a perna completa'],
            ['direction' => 'out', 'body' => 'A promoção de fechamento está em R$ 899 por sessão.'],
        ],
        'messages' => [
            ['body' => 'parcela em 10x?'],
        ],
        'checks' => [
            'must_contain_any' => [['parcela', 'vezes', 'maquininha', 'atendente']],
            'must_not_contain' => ['referencia ou ideia'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'resposta_a_mensagem_especifica',
        'customer' => 'Cliente Reply',
        'history' => [
            ['direction' => 'out', 'body' => 'Tenho terça às 10h ou quinta às 15h.'],
        ],
        'messages' => [
            ['body' => 'quero essa de quinta', 'context_preview' => 'Tenho terça às 10h ou quinta às 15h.'],
        ],
        'checks' => [
            'must_contain_any' => [['quinta', '15', 'horario', 'vaga']],
            'must_not_contain' => ['terça às 10'],
            'max_len' => 520,
        ],
    ],
];

try {
    for ($iteration = 1; $iteration <= $repeat; $iteration++) {
    foreach ($cases as $case) {
        $case['name'] = ($repeat > 1 ? 'rodada_' . $iteration . '_' : '') . (string)$case['name'];
        $report = stress_run_case($studio, $pdo, $runId, $case, $createdConversationIds, $createdLeadIds);
        $reports[] = $report;
        if (!$report['ok']) {
            $failures[] = $report;
        }
        echo '[' . ($report['ok'] ? 'OK' : 'FAIL') . '] ' . $report['name'] . ' intent=' . $report['intent'] . ' human=' . ($report['needs_human'] ? 'yes' : 'no') . "\n";
        echo '  ' . preg_replace('/\s+/', ' ', mb_substr($report['reply'], 0, 280, 'UTF-8')) . "\n";
        if (!$report['ok']) {
            foreach ($report['errors'] as $error) {
                echo '  - ' . $error . "\n";
            }
        }
    }
    }
} finally {
    if ($createdConversationIds) {
        $placeholders = implode(',', array_fill(0, count($createdConversationIds), '?'));
        $pdo->prepare('DELETE FROM whatsapp_conversations WHERE id IN (' . $placeholders . ')')->execute($createdConversationIds);
    }
    if ($createdLeadIds) {
        $placeholders = implode(',', array_fill(0, count($createdLeadIds), '?'));
        $pdo->prepare('DELETE FROM leads WHERE id IN (' . $placeholders . ')')->execute($createdLeadIds);
    }
}

echo "\nResumo: " . (count($reports) - count($failures)) . '/' . count($reports) . " passaram.\n";
if ($failures) {
    echo "Falhas:\n";
    foreach ($failures as $failure) {
        echo '- ' . $failure['name'] . ': ' . implode('; ', $failure['errors']) . "\n";
    }
    exit(1);
}

exit(0);
