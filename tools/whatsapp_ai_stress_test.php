<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

putenv('STUDIO_WHATSAPP_AI_DRY_RUN=1');
$_ENV['STUDIO_WHATSAPP_AI_DRY_RUN'] = '1';
putenv('STUDIO_WHATSAPP_SEMANTIC_DISABLED=1');
$_ENV['STUDIO_WHATSAPP_SEMANTIC_DISABLED'] = '1';
putenv('STUDIO_WHATSAPP_SERVICE_FLOW_DISABLED=1');
$_ENV['STUDIO_WHATSAPP_SERVICE_FLOW_DISABLED'] = '1';
ob_implicit_flush(true);

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
$caseFilter = '';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--repeat=(\d+)$/', (string)$arg, $match)) {
        $repeat = max(1, min(20, (int)$match[1]));
    } elseif (preg_match('/^--case=(.+)$/', (string)$arg, $match)) {
        $caseFilter = trim((string)$match[1]);
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

    $result = [];
    $roundReplies = [];
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
        if (!empty($case['sequential'])) {
            $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
            $result = studio_whatsapp_ai_reply($studio, $conversation ?: [], $message);
            $roundReplies[] = stress_last_bot_reply($pdo, $conversationId);
        }
    }

    if (empty($case['sequential'])) {
        $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
        $result = studio_whatsapp_ai_reply($studio, $conversation ?: [], $message);
    }
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
    $allRoundRepliesPlain = studio_calendar_remove_accents(mb_strtolower(implode("\n", $roundReplies), 'UTF-8'));
    foreach (($checks['round_must_contain_any'] ?? []) as $group) {
        $hit = false;
        foreach ((array)$group as $needle) {
            if (str_contains($allRoundRepliesPlain, studio_calendar_remove_accents(mb_strtolower((string)$needle, 'UTF-8')))) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $errors[] = 'nenhuma etapa encontrou: ' . implode(' | ', (array)$group);
        }
    }
    $finalConversation = studio_find_whatsapp_conversation($studio, $conversationId) ?: [];
    $bookingState = studio_whatsapp_booking_state($finalConversation);
    if (isset($checks['booking_body_area']) && (string)($bookingState['body_area'] ?? '') !== (string)$checks['booking_body_area']) {
        $errors[] = 'area persistida incorreta: ' . (string)($bookingState['body_area'] ?? '');
    }
    if (isset($checks['booking_quote_amount']) && abs((float)($bookingState['quote']['amount'] ?? 0) - (float)$checks['booking_quote_amount']) > 0.01) {
        $errors[] = 'orcamento persistido incorreto: ' . (string)($bookingState['quote']['amount'] ?? 0);
    }
    if (isset($checks['booking_slot_time']) && (string)($bookingState['selected_slot']['time'] ?? '') !== (string)$checks['booking_slot_time']) {
        $errors[] = 'horario persistido incorreto: ' . (string)($bookingState['selected_slot']['time'] ?? '');
    }
    if (array_key_exists('booking_reference', $checks) && !empty($bookingState['reference_received']) !== (bool)$checks['booking_reference']) {
        $errors[] = 'estado persistido da referencia incorreto';
    }
    if (array_key_exists('booking_deposit_requested', $checks) && !empty($bookingState['deposit_requested']) !== (bool)$checks['booking_deposit_requested']) {
        $errors[] = 'estado persistido do sinal incorreto';
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
    [
        'name' => 'primeiro_oi_nao_generico',
        'customer' => 'Cliente Novo',
        'messages' => [
            ['body' => 'Oi'],
        ],
        'checks' => [
            'must_contain_any' => [['nome', 'tatuar', 'tattoo', 'ideia']],
            'must_not_contain' => ['pix', 'comprovante', 'sinal'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'audio_transcrito_agenda_sem_pix',
        'customer' => 'Cliente Audio',
        'messages' => [
            ['body' => 'Quero agendar uma rosa no braço amanhã às 10 horas', 'message_type' => 'audio'],
        ],
        'checks' => [
            'must_contain_any' => [['reservar', 'livre', 'vaga', 'agenda']],
            'must_not_contain' => ['pix', 'comprovante', 'sinal'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'duvida_saude_pos_tattoo',
        'customer' => 'Cliente Saude',
        'messages' => [
            ['body' => 'Minha tatuagem inflamou e está saindo sangue, o que faço?'],
        ],
        'checks' => [
            'must_contain_any' => [['profissional', 'saude', 'atendimento', 'equipe']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'pedido_desconto_humano',
        'customer' => 'Cliente Desconto',
        'messages' => [
            ['body' => 'Tem desconto se eu fechar hoje?'],
        ],
        'checks' => [
            'must_contain_any' => [['equipe', 'atendente', 'conferir', 'condicao']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'comprovante_sem_anexo',
        'customer' => 'Cliente Comprovante Texto',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero uma rosa no braço, 15cm, preto e cinza'],
            ['direction' => 'out', 'body' => 'Para reservar terça às 10h, o sinal é R$ 50 via Pix. Me envia o comprovante por aqui.'],
        ],
        'messages' => [
            ['body' => 'Já paguei o pix'],
        ],
        'checks' => [
            'must_contain_any' => [['comprovante', 'envia', 'equipe', 'conferir']],
            'must_not_contain' => ['agendado', 'confirmado e horário'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'reserva_pronta_pode_pix',
        'customer' => 'Cliente Reserva Pronta',
        'history' => [
            ['direction' => 'in', 'body' => 'Sou Carlos, quero uma rosa no antebraço, 15cm, preto e cinza'],
            ['direction' => 'out', 'body' => 'Pela tabela oficial do orçamento, antebraço fica em R$ 500. Tenho quinta-feira, dia 30/07/2026 às 15h livre.'],
        ],
        'messages' => [
            ['body' => 'Pode reservar quinta dia 30 às 15 então'],
        ],
        'checks' => [
            'must_contain_any' => [['pix', 'sinal', 'comprovante', 'reservar']],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'cliente_corrige_data_depois_de_oferta',
        'customer' => 'Cliente Corrige Data',
        'history' => [
            ['direction' => 'out', 'body' => 'Tenho terça-feira às 10h livre.'],
        ],
        'messages' => [
            ['body' => 'Na verdade quero quinta às 15h'],
        ],
        'checks' => [
            'must_contain_any' => [['quinta', '15', 'vaga', 'horario']],
            'must_not_contain' => ['terça às 10 para reservar'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'cliente_manda_multiplas_perguntas',
        'customer' => 'Cliente Multi',
        'messages' => [
            ['body' => 'Onde fica?'],
            ['body' => 'E parcela?'],
            ['body' => 'Queria uma caveira no braço'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['rua', 'endereco', 'catende'],
                ['parcela', 'cartao', 'vezes'],
            ],
            'must_not_contain' => ['pix', 'comprovante'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'coverup_com_imagem_precisa_humano',
        'customer' => 'Cliente Cover',
        'history' => [
            ['direction' => 'in', 'body' => '', 'message_type' => 'image'],
        ],
        'messages' => [
            ['body' => 'Dá pra cobrir essa tatuagem?'],
        ],
        'checks' => [
            'must_contain_any' => [['cobertura', 'daniel', 'avaliacao', 'equipe']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'fora_escopo_pessoal',
        'customer' => 'Cliente Pessoal',
        'messages' => [
            ['body' => 'Briguei com meu ex-marido e preciso desabafar'],
        ],
        'checks' => [
            'must_contain_any' => [['estudio', 'atendente', 'equipe', 'canal']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'remarcacao_existente_chama_humano',
        'customer' => 'Cliente Remarca',
        'messages' => [
            ['body' => 'Preciso remarcar meu horário de terça para outro dia'],
        ],
        'checks' => [
            'must_contain_any' => [['equipe', 'atendente', 'alterar', 'mudanca']],
            'must_not_contain' => ['pix', 'comprovante'],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'cancelamento_existente_chama_humano',
        'customer' => 'Cliente Cancela',
        'messages' => [
            ['body' => 'Não vou conseguir ir, preciso cancelar meu agendamento'],
        ],
        'checks' => [
            'must_contain_any' => [['equipe', 'atendente', 'agendamento', 'horario']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'menor_de_idade_chama_humano',
        'customer' => 'Cliente Menor',
        'messages' => [
            ['body' => 'Tenho 16 anos, posso fazer uma tatuagem com autorização da minha mãe?'],
        ],
        'checks' => [
            'must_contain_any' => [['menor', 'autorizacao', 'equipe', 'atendente']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'gravidez_chama_humano',
        'customer' => 'Cliente Gravida',
        'messages' => [
            ['body' => 'Estou grávida de três meses, posso tatuar agora?'],
        ],
        'checks' => [
            'must_contain_any' => [['saude', 'cuidado', 'equipe', 'profissional']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'pergunta_tatuador_chama_humano',
        'customer' => 'Cliente Artista',
        'messages' => [
            ['body' => 'Quem vai ser o tatuador do meu horário?'],
        ],
        'checks' => [
            'must_contain_any' => [['tatuador', 'equipe', 'confirmar']],
            'needs_human' => true,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'nome_explicito_e_reserva_pronta',
        'customer' => 'Cliente WhatsApp',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero um fechamento completo das costas com uma fênix, preto e cinza'],
            ['direction' => 'out', 'body' => 'Pela promoção, o fechamento fica em R$ 899. Tenho QUI - 30/07/2026 às 15:00 livre.'],
        ],
        'messages' => [
            ['body' => 'Meu nome é Ana Silva, pode reservar esse horário'],
        ],
        'checks' => [
            'must_contain_any' => [['pix', 'sinal', 'comprovante']],
            'must_not_contain' => ['nome completo'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'quinta_tres_da_tarde',
        'customer' => 'Cliente Hora Falada',
        'messages' => [
            ['body' => 'Quero uma rosa no antebraço, 12cm, preto e cinza'],
            ['body' => 'Tem vaga quinta-feira às três da tarde?'],
        ],
        'checks' => [
            'must_contain_any' => [['quinta', '15h', '15:00', 'vaga']],
            'must_not_contain' => ['pix', 'comprovante'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'sabado_valido_conforme_configuracao',
        'customer' => 'Cliente Sabado',
        'messages' => [
            ['body' => 'Quero tatuar uma caveira no braço, uns 18cm'],
            ['body' => 'Tem sábado às 15 horas?'],
        ],
        'checks' => [
            'must_contain_any' => [['bado'], ['15h', '15:00', 'vaga', 'livre']],
            'must_not_contain' => ['agenda regular', 'encaixe', 'pix', 'comprovante'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'domingo_manha_valido_conforme_configuracao',
        'customer' => 'Cliente Domingo',
        'messages' => [
            ['body' => 'Quero tatuar uma caveira no braço, uns 18cm'],
            ['body' => 'Tem domingo de manhã?'],
        ],
        'checks' => [
            'must_contain_any' => [['domingo'], ['nao tenho', 'não tenho'], ['manha', 'manhã'], ['15h', '15:00']],
            'must_not_contain' => ['agenda regular', 'encaixe', 'pix', 'comprovante'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'apos_pedido_de_opcoes_lista_vagas_validas',
        'customer' => 'Cliente Alternativas',
        'sequential' => true,
        'messages' => [
            ['body' => 'Quero tatuar uma caveira no braço, uns 18cm'],
            ['body' => 'Tem sábado às 15 horas?'],
            ['body' => 'Que dia e horário tem disponível?'],
        ],
        'checks' => [
            'must_contain_any' => [['proximas vagas', 'vagas livres', 'horario livre', 'tenho estas']],
            'must_not_contain' => ['agenda regular nao abre sabado', 'encaixe excepcional'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'data_passada_nao_e_oferecida',
        'customer' => 'Cliente Passado',
        'messages' => [
            ['body' => 'Quero uma borboleta no ombro, 10cm'],
            ['body' => 'Tem vaga dia 14/07/2026 às 15h?'],
        ],
        'checks' => [
            'must_contain_any' => [['nao tenho', 'proximo', 'passou', 'outra data']],
            'must_not_match' => ['/Tenho\s+15h\s+livre\s+.*14\/07/iu'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'recusa_horario_e_pede_quinta',
        'customer' => 'Cliente Recusa',
        'history' => [
            ['direction' => 'out', 'body' => 'Tenho TER - 21/07/2026 às 15:00 livre.'],
        ],
        'messages' => [
            ['body' => 'Não, esse não. Tem quinta às 15h?'],
        ],
        'checks' => [
            'must_contain_any' => [['quinta', '15h', '15:00', 'vaga']],
            'must_not_contain' => ['pix', 'comprovante', 'terça para reservar'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'perguntas_multiplas_na_mesma_mensagem',
        'customer' => 'Cliente Tudo Junto',
        'messages' => [
            ['body' => 'Onde fica o estúdio, parcela em 10x e tem vaga quinta às 15h?'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['rua', 'endereco', 'catende'],
                ['parcela', 'cartao', 'vezes'],
                ['quinta', 'vaga', 'horario'],
            ],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'nao_repete_pergunta_uma_sessao',
        'customer' => 'Cliente Fechamento',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero um fechamento das costas com um leão, preto e cinza'],
            ['direction' => 'out', 'body' => 'Você gostaria de fazer em uma única sessão conforme a promoção de fechamento?'],
        ],
        'messages' => [
            ['body' => 'Sim, de uma vez, conforme a promoção de fechamento'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['3.100'],
                ['agenda', 'data', 'horario', 'reserva'],
            ],
            'must_not_match' => ['/voc[eê]\s+gostaria\s+de\s+fazer\s+em\s+uma\s+[uú]nica\s+sess[aã]o/iu'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'orcamento_de_coxa_usa_tabela_sem_chamar_humano',
        'customer' => 'Cliente Orcamento Completo',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero uma fênix autoral no lado de fora da coxa, 27cm, colorida'],
            ['direction' => 'out', 'body' => 'Entendi todos os detalhes da fênix na coxa.'],
        ],
        'messages' => [
            ['body' => 'Quanto fica esse trabalho?'],
        ],
        'checks' => [
            'must_contain_any' => [['600', 'tabela oficial', 'agenda', 'dia', 'horario']],
            'must_not_contain' => ['me manda a ideia', 'qual local do corpo', 'qual tamanho'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'resposta_curta_de_tamanho_avanca_para_nome',
        'customer' => 'Cliente WhatsApp',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero tatuar um dragão realista no braço'],
            ['direction' => 'out', 'body' => 'Qual tamanho aproximado você imagina?'],
        ],
        'messages' => [
            ['body' => 'Grande'],
        ],
        'checks' => [
            'must_contain_any' => [['nome', 'chama']],
            'must_not_contain' => ['qual tamanho', 'tamanho aproximado'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'nome_curto_depois_da_pergunta_nao_repete_nome',
        'customer' => 'Cliente WhatsApp',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero um leão realista no braço com 30cm'],
            ['direction' => 'out', 'body' => 'Me confirma seu nome completo para deixar o cadastro certinho?'],
        ],
        'messages' => [
            ['body' => 'Daniel'],
        ],
        'checks' => [
            'must_contain_any' => [['500', 'agenda', 'dia', 'horario']],
            'must_not_contain' => ['confirma seu nome', 'nome completo'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'briefing_completo_nao_inventa_nivel_de_detalhe',
        'customer' => 'Cliente Briefing Completo',
        'memory' => 'Imagem de referência recebida. Local: antebraço. Tamanho aproximado: 25cm. Cliente identificado.',
        'history' => [
            ['direction' => 'in', 'body' => '', 'message_type' => 'image'],
            ['direction' => 'out', 'body' => 'Qual seria o tamanho aproximado em centímetros?'],
        ],
        'messages' => [
            ['body' => '25cm'],
        ],
        'checks' => [
            'must_contain_any' => [['500', 'tabela oficial', 'agenda', 'dia', 'horario']],
            'must_not_contain' => ['nivel de detalhe', 'nível de detalhe', 'qual tamanho', 'tamanho aproximado'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'botao_enviar_foto_confirma_espera_sem_gerar',
        'customer' => 'Cliente Referencia',
        'history' => [
            ['direction' => 'in', 'body' => 'Oi'],
            [
                'direction' => 'out',
                'body' => 'Oi! Me conta o que você quer tatuar e eu te ajudo por aqui.',
                'message_type' => 'interactive_button',
            ],
        ],
        'messages' => [
            [
                'body' => 'Enviar foto',
                'message_type' => 'interactive',
                'context_preview' => 'Oi! Me conta o que você quer tatuar e eu te ajudo por aqui.',
            ],
        ],
        'checks' => [
            'must_contain_any' => [
                ['pode mandar', 'mande a foto', 'envie a foto'],
                ['assim que', 'aguardando', 'quando chegar'],
            ],
            'must_not_contain' => ['gerando imagem', 'gerei uma imagem', 'aqui vai a imagem'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'orcamento_por_area_nao_exige_centimetros_ou_detalhe',
        'customer' => 'Marina Área',
        'messages' => [
            ['body' => 'Quero tatuar uma borboleta no pulso, quanto fica?'],
        ],
        'checks' => [
            'must_contain_any' => [['100', 'tabela oficial']],
            'must_not_contain' => ['centimetro', 'centímetro', 'nivel de detalhe', 'nível de detalhe', 'daniel precisa avaliar'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'fechamento_generico_pede_posicao_em_vez_de_chutar_preco',
        'customer' => 'Roberto Fechamento',
        'messages' => [
            ['body' => 'Quero fechar o braço com um dragão, quanto custa?'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['interno', 'externo'],
                ['direito', 'esquerdo'],
            ],
            'must_not_contain' => ['899', 'nivel de detalhe', 'nível de detalhe'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'costas_em_contexto_de_tattoo_significa_fechamento_completo',
        'customer' => 'Paula Costas',
        'messages' => [
            ['body' => 'Quero fazer um dragão nas costas. Quanto fica?'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['3.100'],
                ['fechamento completo de costas'],
            ],
            'must_not_contain' => ['850', '899'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'fluxo_persistente_chega_ao_pix_sem_repetir_briefing',
        'customer' => 'Cliente WhatsApp',
        'sequential' => true,
        'messages' => [
            ['body' => '', 'message_type' => 'image'],
            ['body' => 'Costas', 'message_type' => 'interactive'],
            ['body' => 'Eu posso todas as quintas de manhã'],
            ['body' => 'Meu nome é Daniel Teste'],
            ['body' => 'Antes, vocês parcelam?'],
            ['body' => 'Sim, pode reservar'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['pix'],
                ['comprovante'],
                ['50'],
            ],
            'must_not_contain' => ['qual é o seu estilo', 'qual area do corpo', 'qual área do corpo', 'manda a referência', 'manda a referencia'],
            'round_must_contain_any' => [
                ['3.100'],
                ['quinta-feira', '30/07'],
                ['parcelar', 'cartão'],
            ],
            'booking_reference' => true,
            'booking_body_area' => 'costas',
            'booking_quote_amount' => 3100,
            'booking_slot_time' => '10:00',
            'booking_deposit_requested' => true,
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'conversa_antiga_recupera_foto_area_preco_nome_e_preferencia',
        'customer' => 'Daniel',
        'history' => [
            ['direction' => 'in', 'body' => 'Boa noite'],
            ['direction' => 'out', 'body' => 'Me conta o que você quer tatuar.'],
            ['direction' => 'in', 'body' => '', 'message_type' => 'image'],
            ['direction' => 'out', 'body' => 'Recebi a imagem. Em qual local do corpo seria?'],
            ['direction' => 'in', 'body' => 'Costas'],
            ['direction' => 'out', 'body' => 'Fechamento completo de costas fica em R$ 3.100. Qual dia e horário você prefere?'],
            ['direction' => 'in', 'body' => 'Eu posso todas as quintas de manhã'],
            ['direction' => 'out', 'body' => 'Qual é o seu nome?'],
            ['direction' => 'in', 'body' => 'Daniel'],
            ['direction' => 'out', 'body' => 'Qual é o estilo da tatuagem?'],
        ],
        'messages' => [
            ['body' => 'Eu já te expliquei e mandei a foto'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['10h', '10:00'],
                ['reservar esse horário', 'reservar esse horario'],
            ],
            'must_not_contain' => ['qual é o estilo', 'qual area do corpo', 'qual área do corpo', 'manda a referência', 'manda a referencia'],
            'booking_reference' => true,
            'booking_body_area' => 'costas',
            'booking_quote_amount' => 3100,
            'booking_slot_time' => '10:00',
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'fechamento_de_perna_pergunta_posicao_e_lado',
        'customer' => 'Bruno Perna',
        'messages' => [
            ['body' => 'Quero fechar a perna com um projeto oriental. Quanto custa?'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['externa', 'frontal'],
                ['interna', 'posterior'],
                ['direito', 'esquerdo'],
            ],
            'must_not_contain' => ['899', 'daniel precisa avaliar'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'resposta_externa_esquerda_ativa_fechamento_de_perna',
        'customer' => 'Bruna Perna',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero fechar a perna com um dragão oriental'],
            ['direction' => 'out', 'body' => 'Você quer a parte externa/frontal ou interna/posterior, e qual lado?'],
        ],
        'messages' => [
            ['body' => 'Externa, do lado esquerdo'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['1.450'],
                ['fechamento de perna externa/frontal esquerda'],
            ],
            'must_not_contain' => ['899', 'qual parte da perna'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'resposta_interno_direito_ativa_fechamento_de_braco',
        'customer' => 'Guilherme Braço',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero fechar o braço com um samurai'],
            ['direction' => 'out', 'body' => 'É interno ou externo e do lado direito ou esquerdo?'],
        ],
        'messages' => [
            ['body' => 'Interno, braço direito'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['900'],
                ['fechamento de braço interno direito'],
            ],
            'must_not_contain' => ['899', 'qual lado'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'fechamento_exato_usa_promocao_do_json',
        'customer' => 'Rafael Fechamento',
        'messages' => [
            ['body' => 'Quero fechar o braço externo esquerdo com um samurai. Quanto fica?'],
        ],
        'checks' => [
            'must_contain_any' => [['1.000', 'tabela oficial']],
            'must_not_contain' => ['899', 'daniel precisa avaliar'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'conjunto_de_areas_ativa_promocao_do_json',
        'customer' => 'Bianca Componentes',
        'messages' => [
            ['body' => 'Quero um projeto japonês no antebraço externo, braço externo e ombro esquerdo. Quanto fica?'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['1.000'],
                ['fechamento de braço externo esquerdo'],
            ],
            'must_not_contain' => ['500', '899', 'daniel precisa avaliar'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'mesmo_conjunto_respeita_desconto_do_lado_direito',
        'customer' => 'Caio Componentes',
        'messages' => [
            ['body' => 'Quero antebraço externo, braço externo e ombro direito com tema de dragão. Qual o valor?'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['1.200'],
                ['fechamento de braço externo direito'],
            ],
            'must_not_contain' => ['500', '899'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'areas_fragmentadas_tambem_formam_promocao',
        'customer' => 'Nina Componentes',
        'messages' => [
            ['body' => 'Quero tatuar o antebraço externo esquerdo'],
            ['body' => 'também o braço externo'],
            ['body' => 'e o ombro esquerdo'],
            ['body' => 'quanto fica tudo?'],
        ],
        'checks' => [
            'must_contain_any' => [
                ['1.000'],
                ['fechamento de braço externo esquerdo'],
            ],
            'must_not_contain' => ['500', '899'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'conjunto_incompleto_nao_recebe_desconto_de_fechamento',
        'customer' => 'Lia Componentes',
        'messages' => [
            ['body' => 'Quero tatuar o antebraço externo e o ombro esquerdo. Quanto fica?'],
        ],
        'checks' => [
            'must_contain_any' => [['500', 'tabela oficial']],
            'must_not_contain' => ['1.000', 'fechamento de braço externo esquerdo'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'botao_quero_orcamento_nao_repete_tamanho',
        'customer' => 'Cliente Orçamento Interativo',
        'memory' => 'Imagem de referência recebida. Local: antebraço. Cobertura: área inteira. Briefing completo, exceto valor.',
        'history' => [
            ['direction' => 'in', 'body' => '', 'message_type' => 'image'],
            ['direction' => 'out', 'body' => 'Em qual local do corpo seria?'],
            ['direction' => 'in', 'body' => 'Antebraço', 'message_type' => 'interactive'],
            ['direction' => 'out', 'body' => 'Qual tamanho aproximado ou cobertura você imagina?'],
            ['direction' => 'in', 'body' => 'Área inteira', 'message_type' => 'interactive'],
            ['direction' => 'out', 'body' => 'Agora o Daniel precisa apenas confirmar o valor.'],
        ],
        'messages' => [
            [
                'body' => 'Quero orçamento',
                'message_type' => 'interactive',
                'context_preview' => 'Agora o Daniel precisa apenas confirmar o valor.',
            ],
        ],
        'checks' => [
            'must_contain_any' => [['500', 'tabela oficial', 'agenda', 'dia', 'horario']],
            'must_not_contain' => ['qual tamanho', 'tamanho aproximado', 'qual local do corpo'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'botao_interativo_nao_contamina_area_do_corpo',
        'customer' => 'Cliente Braço',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero adaptar a tattoo da referência para o braço'],
            ['direction' => 'out', 'body' => 'O tamanho varia: costas, braço e perna são exemplos. Você quer apenas parte ou área inteira?'],
        ],
        'messages' => [
            [
                'body' => 'Área inteira',
                'message_type' => 'interactive_button_reply',
                'context_preview' => 'O tamanho varia: costas, braço e perna são exemplos. Você quer apenas parte ou área inteira?',
            ],
        ],
        'checks' => [
            'must_contain_any' => [['braco', 'valor', 'daniel', 'equipe']],
            'must_not_contain' => ['costas completas', 'fechamento completo das costas'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'pedido_direto_para_deixar_agendado',
        'customer' => 'Daniel',
        'memory' => 'Tattoo: leão realista no braço, 30cm. Valor/orçamento combinado: R$ 500,00.',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero um leão realista no braço com 30cm'],
            ['direction' => 'out', 'body' => 'O valor combinado ficou em R$ 500,00.'],
        ],
        'messages' => [
            ['body' => 'Quero já deixar agendado'],
        ],
        'checks' => [
            'must_contain_any' => [['data', 'dia', 'horario']],
            'must_not_contain' => ['qual tamanho', 'qual estilo', 'nome completo'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'cliente_reclama_de_repeticao_e_fluxo_recupera',
        'customer' => 'Daniel',
        'memory' => 'Tattoo: leão realista no braço, 30cm. Valor/orçamento combinado: R$ 500,00.',
        'history' => [
            ['direction' => 'in', 'body' => 'Quero um leão realista no braço com 30cm'],
            ['direction' => 'out', 'body' => 'Qual tamanho e estilo você quer?'],
        ],
        'messages' => [
            ['body' => 'Já expliquei isso, você não lembra?'],
        ],
        'checks' => [
            'must_contain_any' => [['razao', 'anotei', 'reservar', 'agenda']],
            'must_not_contain' => ['qual tamanho', 'qual estilo'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'area_sozinha_nao_vira_ideia_da_tattoo',
        'customer' => 'Cliente Área',
        'messages' => [
            ['body' => 'Costas'],
        ],
        'checks' => [
            'must_contain_any' => [['ideia', 'referencia', 'o que voce quer tatuar']],
            'must_not_contain' => ['qual dia', 'pix', 'comprovante'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'area_rara_sozinha_nao_vira_desenho',
        'customer' => 'Cliente Escápula',
        'history' => [
            ['direction' => 'out', 'body' => 'Legal. Em qual local do corpo você quer fazer?'],
        ],
        'messages' => [
            ['body' => 'Na escápula esquerda'],
        ],
        'checks' => [
            'must_contain_any' => [['ideia', 'referencia', 'o que voce quer tatuar']],
            'must_not_contain' => ['qual dia', 'pix', 'comprovante'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'multiplas_areas_nao_viram_desenho',
        'customer' => 'Cliente Áreas',
        'history' => [
            ['direction' => 'out', 'body' => 'Em qual local do corpo você quer fazer?'],
        ],
        'messages' => [
            ['body' => 'Do ombro até o antebraço e a mão'],
        ],
        'checks' => [
            'must_contain_any' => [['ideia', 'referencia', 'o que voce quer tatuar']],
            'must_not_contain' => ['qual dia', 'pix', 'comprovante'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'desenho_e_area_rara_permanecem_separados',
        'customer' => 'Cliente Clavícula',
        'messages' => [
            ['body' => 'qro uma rosa pequena na clavícula'],
        ],
        'checks' => [
            'must_contain_any' => [['clavicula', 'valor', 'orcamento', 'tabela', 'daniel']],
            'must_not_contain' => ['o que voce quer tatuar', 'qual local do corpo'],
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'correcao_para_sabado_valido_nao_reaproveita_segunda',
        'customer' => 'Cliente Restrição',
        'memory' => 'Tattoo: leão nas costas. Valor combinado: R$ 3.100,00. Preferência atual: somente sábado à tarde.',
        'history' => [
            ['direction' => 'out', 'body' => 'Tenho segunda-feira, dia 20/07, às 15h. Quer reservar?'],
            ['direction' => 'in', 'body' => 'Eu só consigo sábado à tarde'],
        ],
        'messages' => [
            ['body' => 'Tem que ser sábado mesmo'],
        ],
        'checks' => [
            'must_contain_any' => [['bado'], ['15h', '15:00', 'vaga', 'livre']],
            'must_not_contain' => ['segunda-feira', '20/07', 'agenda regular', 'encaixe', 'pix', 'comprovante'],
            'needs_human' => false,
            'max_len' => 520,
        ],
    ],
    [
        'name' => 'status_sem_registro_nao_confirma_agendamento',
        'customer' => 'Cliente Status',
        'memory' => 'Tattoo: leão nas costas. Foi discutida uma possibilidade de sábado às 15h, sem comprovante validado.',
        'history' => [
            ['direction' => 'out', 'body' => 'Vou verificar o horário e o comprovante antes de confirmar.'],
        ],
        'messages' => [
            ['body' => 'Tudo certo? Já estou agendado?'],
        ],
        'checks' => [
            'must_contain_any' => [['agendamento registrado', 'agenda']],
            'must_not_match' => ['/\b(?:sim|tudo certo).{0,35}\bagendad[oa]\b/iu'],
            'max_len' => 520,
        ],
    ],
];

if ($caseFilter !== '') {
    $cases = array_values(array_filter(
        $cases,
        static fn(array $case): bool => str_contains((string)($case['name'] ?? ''), $caseFilter)
    ));
    if (!$cases) {
        fwrite(STDERR, 'Nenhum caso corresponde a --case=' . $caseFilter . "\n");
        exit(2);
    }
}

try {
    for ($iteration = 1; $iteration <= $repeat; $iteration++) {
    foreach ($cases as $case) {
        $case['name'] = ($repeat > 1 ? 'rodada_' . $iteration . '_' : '') . (string)$case['name'];
        echo '[RUN] ' . $case['name'] . "\n";
        fflush(STDOUT);
        $caseStartedAt = microtime(true);
        $report = stress_run_case($studio, $pdo, $runId, $case, $createdConversationIds, $createdLeadIds);
        $report['elapsed'] = round(microtime(true) - $caseStartedAt, 2);
        $reports[] = $report;
        if (!$report['ok']) {
            $failures[] = $report;
        }
        echo '[' . ($report['ok'] ? 'OK' : 'FAIL') . '] ' . $report['name'] . ' intent=' . $report['intent'] . ' human=' . ($report['needs_human'] ? 'yes' : 'no') . ' time=' . $report['elapsed'] . "s\n";
        echo '  ' . preg_replace('/\s+/', ' ', mb_substr($report['reply'], 0, 280, 'UTF-8')) . "\n";
        if (!$report['ok']) {
            foreach ($report['errors'] as $error) {
                echo '  - ' . $error . "\n";
            }
        }
        fflush(STDOUT);
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
