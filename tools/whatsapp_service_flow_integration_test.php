<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

putenv('STUDIO_WHATSAPP_AI_DRY_RUN=1');
putenv('STUDIO_WHATSAPP_SEMANTIC_DISABLED=1');
putenv('STUDIO_WHATSAPP_SERVICE_FLOW_DISABLED=0');

$studio = db()->query('SELECT * FROM studios ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$studio) {
    fwrite(STDERR, "Nenhum estúdio encontrado.\n");
    exit(1);
}
$pdo = studio_db($studio);
$runId = 'flowtest' . date('YmdHis');
$phone = '55998' . substr(md5($runId), 0, 8);
$leadId = 0;
$conversationId = 0;
$createdAppointmentIds = [];
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $text, string $needle): bool {
    return str_contains(studio_calendar_remove_accents(mb_strtolower($text, 'UTF-8')), studio_calendar_remove_accents(mb_strtolower($needle, 'UTF-8')));
};

try {
    $pdo->prepare(
        'INSERT INTO leads (name, phone, interest, status, pipeline_stage, lead_score, estimated_value, source, created_at, updated_at)
         VALUES ("Cliente WhatsApp", ?, "Teste roteiro rígido", "novo", "entrada", 0, 0, "codex_flow_test", NOW(), NOW())'
    )->execute([$phone]);
    $leadId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO whatsapp_conversations
            (lead_id, phone, name, attendance_mode, needs_human, lead_score, created_at, updated_at)
         VALUES (?, ?, "Cliente WhatsApp", "bot", 0, 0, NOW(), NOW())'
    )->execute([$leadId, $phone]);
    $conversationId = (int)$pdo->lastInsertId();

    $send = static function (
        string $body,
        string $label = '',
        string $messageType = 'text',
        string $mediaMime = '',
        string $mediaFileName = '',
        string $mediaFilePath = ''
    ) use ($studio, $pdo, $conversationId, $runId): array {
        $messageId = 'flow-' . $runId . '-' . bin2hex(random_bytes(3));
        $pdo->prepare(
            'INSERT INTO whatsapp_messages
                (conversation_id, direction, sender_type, body, media_mime, media_file_name, media_file_path, message_type, message_id, from_me, status, sent_at, created_at)
             VALUES (?, "in", "customer", ?, ?, ?, ?, ?, ?, 0, NULL, NOW(), NOW())'
        )->execute([$conversationId, $body, $mediaMime, $mediaFileName, $mediaFilePath, $messageType, $messageId]);
        $messageDbId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE whatsapp_conversations
             SET last_message_preview = ?, last_message_direction = "in", last_message_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        )->execute([mb_substr($body !== '' ? $body : '[' . $messageType . ']', 0, 250, 'UTF-8'), $conversationId]);
        $messageStmt = $pdo->prepare('SELECT * FROM whatsapp_messages WHERE id = ?');
        $messageStmt->execute([$messageDbId]);
        $conversation = studio_find_whatsapp_conversation($studio, $conversationId) ?: [];
        $result = studio_whatsapp_ai_reply($studio, $conversation, $messageStmt->fetch() ?: []);
        $replyStmt = $pdo->prepare('SELECT body FROM whatsapp_messages WHERE conversation_id = ? AND direction = "out" ORDER BY id DESC LIMIT 1');
        $replyStmt->execute([$conversationId]);
        $reply = trim((string)($replyStmt->fetchColumn() ?: ''));
        echo '[' . ($label !== '' ? $label : $body) . '] ' . $reply . "\n";
        return ['result' => $result, 'reply' => $reply, 'conversation' => studio_find_whatsapp_conversation($studio, $conversationId) ?: []];
    };

    $slotDate = '';
    $slotTime = '';
    foreach (studio_schedule_available_slots($studio, 60) as $day) {
        $freeSlots = array_values(array_map('strval', $day['free_slots'] ?? []));
        if (empty($day['allowed']) || !$freeSlots) {
            continue;
        }
        $slotDate = (string)($day['date'] ?? '');
        $slotTime = substr((string)$freeSlots[0], 0, 5);
        break;
    }
    $expect($slotDate !== '' && $slotTime !== '', 'Não há vaga livre na agenda para executar o teste de ponta a ponta.');
    $round = $send('Oi', 'abertura');
    $expect($contains($round['reply'], 'nome'), 'A abertura não pediu o nome do cliente.');

    $fullRequest = 'Meu nome é João Pereira. Quero um dragão realista com flores no antebraço esquerdo externo, cerca de 15 cm, preto e branco. Não tenho referência. Quero agendar dia '
        . date('d/m/Y', strtotime($slotDate)) . ' às ' . $slotTime . '.';
    $round = $send($fullRequest, 'ficha completa');
    $reply = $round['reply'];
    $expect($contains($reply, 'agendamento criado') || $contains($reply, 'agendamento'), 'A confirmação final não mencionou o agendamento.');
    $expect($contains($reply, 'joão pereira'), 'A confirmação final não trouxe o nome do cliente.');
    $expect($contains($reply, 'orçamento') && $contains($reply, 'r$'), 'O orçamento não foi calculado automaticamente pela tabela oficial.');
    $slotTimeLabel = studio_whatsapp_schedule_time_label($slotTime);
    $expect($contains($reply, $slotTime) || $contains($reply, $slotTimeLabel), 'A confirmação final não trouxe o horário escolhido.');

    $appointmentStmt = $pdo->prepare(
        'SELECT id, appointment_date, start_time, status, value, deposit_value
         FROM appointments
        WHERE lead_id = ? AND import_source = "whatsapp_ai_simple_booking"
         ORDER BY id DESC LIMIT 1'
    );
    $appointmentStmt->execute([$leadId]);
    $appointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($appointment) {
        $createdAppointmentIds[] = (int)$appointment['id'];
    }
    $expect($appointment !== [], 'Nenhum agendamento foi criado pela IA após comprovante confirmado.');
    $expect((string)($appointment['appointment_date'] ?? '') === $slotDate, 'O agendamento foi criado em data diferente da confirmada.');
    $expect(substr((string)($appointment['start_time'] ?? ''), 0, 5) === $slotTime, 'O agendamento foi criado em horário diferente do confirmado.');
    $expect((float)($appointment['value'] ?? 0) > 0, 'O agendamento não registrou o valor calculado pela tabela.');
    $expect((float)($appointment['deposit_value'] ?? 0) === 0.0, 'O pré-agendamento não deveria registrar sinal sem comprovante.');
} finally {
    foreach (array_unique($createdAppointmentIds) as $appointmentId) {
        if ($appointmentId > 0) {
            $pdo->prepare('DELETE FROM appointments WHERE id = ?')->execute([$appointmentId]);
        }
    }
    if ($conversationId > 0) {
        $pdo->prepare('DELETE FROM whatsapp_messages WHERE conversation_id = ?')->execute([$conversationId]);
        $pdo->prepare('DELETE FROM whatsapp_conversations WHERE id = ?')->execute([$conversationId]);
    }
    if ($leadId > 0) {
        $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$leadId]);
    }
}

if ($failures) {
    echo "\nFalhas:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}
echo "\nResumo: integração real do roteiro aprovada até criar agenda e enviar confirmação no chat.\n";
