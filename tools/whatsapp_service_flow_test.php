<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$studio = db()->query('SELECT * FROM studios ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$studio) {
    fwrite(STDERR, "Nenhum estúdio encontrado.\n");
    exit(1);
}

$state = studio_whatsapp_booking_state([]);
$conversation = ['id' => 0, 'attendance_mode' => 'bot'];
$failures = [];
$step = static function (
    string $label,
    string $message,
    string $intent,
    ?array $quote = null,
    ?array $slot = null,
    bool $attachment = false,
    array $proof = []
) use ($studio, $conversation, &$state): array {
    $result = studio_whatsapp_service_flow_decide(
        $studio,
        $conversation,
        $state,
        $message,
        $attachment ? 'document' : 'text',
        $intent,
        $attachment,
        $quote,
        $slot,
        $proof
    );
    $state = is_array($result['state'] ?? null) ? $result['state'] : $state;
    $reply = trim((string)($result['direct_reply'] ?? $result['resume_question'] ?? 'sem resposta'));
    echo '[' . $label . '] ' . $reply . "\n";
    return $result;
};
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $text, string $needle): bool {
    return str_contains(studio_calendar_remove_accents(mb_strtolower($text, 'UTF-8')), studio_calendar_remove_accents(mb_strtolower($needle, 'UTF-8')));
};

$expect(
    studio_whatsapp_service_flow_unverified_policy_topic('Se eu atrasar vinte minutos, perco a vaga?', '') === 'atrasos e tolerância de horário',
    'A proteção não detectou uma política de atraso ausente.'
);
$expect(
    studio_whatsapp_service_flow_unverified_policy_topic('Se eu atrasar vinte minutos, perco a vaga?', 'Tolerância de atraso: 15 minutos.') === '',
    'A proteção bloqueou uma política que estava cadastrada.'
);

$result = $step('abertura', 'Oi', 'general');
$expect($contains((string)($result['direct_reply'] ?? ''), 'nome completo'), 'A abertura não pediu o nome.');

$result = $step('nome', 'Daniel Araújo da Silva', 'general');
$expect(($state['customer_name'] ?? '') === 'Daniel Araújo da Silva', 'O nome não foi armazenado.');
$expect($contains((string)($result['direct_reply'] ?? ''), 'quer tatuar'), 'O roteiro não avançou para a ideia.');

$result = $step('ideia', 'Quero um dragão realista com flores', 'tattoo_idea');
$expect($contains((string)($result['direct_reply'] ?? ''), 'parte do corpo'), 'O roteiro não avançou para área do corpo.');

$result = $step('dúvida lateral', 'Onde fica o estúdio?', 'address');
$expect((string)($result['direct_reply'] ?? '') === '', 'A dúvida lateral deveria ser entregue à IA/regra especializada.');
$expect($contains((string)($result['resume_question'] ?? ''), 'parte do corpo'), 'A dúvida lateral não preservou a pergunta pendente.');

$result = $step('área', 'antebraço esquerdo', 'tattoo_idea');
$expect($contains((string)($result['direct_reply'] ?? ''), 'cobrir'), 'A integração não avançou para cobertura.');

$result = $step('cobertura não', 'não', 'general');
$expect($contains((string)($result['direct_reply'] ?? ''), 'colorido') || $contains((string)($result['direct_reply'] ?? ''), 'preto'), 'A integração não avançou para estilo/cor.');

$quote = ['amount' => 799.0, 'price' => 'R$ 799,00', 'label' => 'antebraço externo', 'key' => 'antebraco_externo', 'type' => 'area', 'source' => 'teste'];
$result = $step('estilo', 'preto e branco', 'general', $quote);
$expect($contains((string)($result['direct_reply'] ?? ''), 'dia') && $contains((string)($result['direct_reply'] ?? ''), 'horário'), 'O roteiro não avançou para a agenda.');

$result = $step('preferência amanhã cedo', 'Amanhã cedo, pode ser?', 'schedule', $quote);
$expect(($state['schedule_preference'] ?? '') === 'Amanhã cedo, pode ser?', 'O roteiro não armazenou a preferência "amanhã cedo".');
$expect(trim((string)($result['direct_reply'] ?? '')) === '', 'O roteiro repetiu a pergunta de agenda em vez de deixar a disponibilidade responder.');

$slot = ['date' => '2026-07-31', 'time' => '10:00', 'source' => 'teste'];
$result = $step('vaga', 'sexta às dez', 'schedule', $quote, $slot);
$reply = (string)($result['direct_reply'] ?? '');
$expect(str_contains($reply, 'R$ 799,00'), 'O preço oficial não foi apresentado antes da confirmação.');
$expect($contains($reply, 'funcionam para você'), 'A vaga não avançou para confirmação.');

$result = $step('recusa', 'Escolher outro horário', 'schedule', $quote);
$expect(!is_array($state['selected_slot'] ?? null), 'A vaga recusada não foi descartada.');
$expect($contains((string)($result['direct_reply'] ?? ''), 'dia') && $contains((string)($result['direct_reply'] ?? ''), 'horário'), 'A recusa não retornou à agenda.');

$slot = ['date' => '2026-08-01', 'time' => '15:00', 'source' => 'teste'];
$step('nova vaga', 'sábado às três da tarde', 'schedule', $quote, $slot);
$result = $step('confirmação', 'Sim, funciona', 'reservation', $quote, $slot);
$expect(!empty($state['slot_confirmed']), 'A confirmação da vaga não foi armazenada.');
$expect(!empty($state['deposit_requested']), 'O Pix não foi liberado depois da confirmação.');
$expect($contains((string)($result['direct_reply'] ?? ''), 'comprovante'), 'A etapa de Pix não pediu comprovante.');

$result = $step('comprovante PDF sem agenda', 'Segue o comprovante em PDF', 'payment_proof', $quote, $slot, true, ['present' => true, 'confirmed' => true]);
$expect(!empty($state['proof_received']), 'O comprovante confirmado não foi armazenado.');
$expect(!empty($result['needs_human']), 'Sem criação da agenda, a finalização deveria sinalizar humano.');
$expect(!$contains((string)($result['direct_reply'] ?? ''), 'agendamento concluído'), 'O roteiro confirmou agendamento sem appointment_id.');

$state['appointment_id'] = 999;
$result = $step('confirmação final', '', 'booking_status', $quote, $slot);
$finalReply = (string)($result['direct_reply'] ?? '');
$expect(!empty($result['completed']) || $contains($finalReply, 'agendamento') || str_contains($finalReply, '#999'), 'O roteiro não entregou conclusão após criar a agenda.');

if ($failures) {
    echo "\nFalhas:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}
echo "\nResumo: fluxo rígido completo aprovado, incluindo desvio, retomada, recusa de vaga, Pix, PDF e confirmação final segura.\n";
