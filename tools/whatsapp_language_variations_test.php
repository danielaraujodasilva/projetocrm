<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$failures = [];

$timeCases = [
    'meio dia' => '12:00',
    'meio-dia' => '12:00',
    'meia noite' => '00:00',
    'meia-noite' => '00:00',
    '13h' => '13:00',
    '13hs' => '13:00',
    '13:00' => '13:00',
    '13.00' => '13:00',
    'as 13' => '13:00',
    'às 13' => '13:00',
    '1 da tarde' => '13:00',
    'uma da tarde' => '13:00',
    '1:30 da tarde' => '13:30',
    '1 e meia da tarde' => '13:30',
    'uma e meia da tarde' => '13:30',
    '7 da noite' => '19:00',
    '3pm' => '15:00',
    '3 PM' => '15:00',
    '3 p.m.' => '15:00',
    '15PM' => '15:00',
    '1:30pm' => '13:30',
    '10 e meia' => '10:30',
];

foreach ($timeCases as $input => $expected) {
    $actual = studio_whatsapp_ai_extract_time_choice((string)$input);
    if ($actual !== $expected) {
        $failures[] = 'Horario "' . $input . '" esperado ' . $expected . ', veio ' . ($actual === '' ? 'vazio' : $actual);
    }
}

$yesCases = ['ok', 'blz', 'beleza', 'bora', 'manda ver', 'confirmado', 'combinado', 'fechou', 'show', 'ta bom'];
$noCases = ['não rola', 'nao da', 'não dá', 'prefiro outro', 'melhor outro', 'esse não', 'negativo'];

foreach ($yesCases as $input) {
    $state = ['script_answers' => []];
    $accepted = studio_whatsapp_service_flow_store_current_answer($state, [
        'field_key' => 'accept_test',
        'answer_type' => 'yes_no',
    ], $input, 'text', false);
    if (!$accepted || (string)($state['accept_test'] ?? '') !== 'Sim') {
        $failures[] = 'Confirmacao "' . $input . '" nao foi entendida como Sim.';
    }
}

foreach ($noCases as $input) {
    $state = ['script_answers' => []];
    $accepted = studio_whatsapp_service_flow_store_current_answer($state, [
        'field_key' => 'accept_test',
        'answer_type' => 'yes_no',
    ], $input, 'text', false);
    if (!$accepted || (string)($state['accept_test'] ?? '') !== 'Não') {
        $failures[] = 'Recusa "' . $input . '" nao foi entendida como Nao.';
    }
}

$referenceDeclines = [
    'não tenho foto',
    'nao tenho imagem',
    'sem imagem',
    'vou descrever',
    'pode ser sem referência',
    'não precisa de foto',
];
foreach ($referenceDeclines as $input) {
    $state = ['script_answers' => []];
    $accepted = studio_whatsapp_service_flow_store_current_answer($state, [
        'field_key' => 'reference_received',
        'answer_type' => 'image_or_skip',
    ], $input, 'text', false);
    if (!$accepted || empty($state['reference_declined'])) {
        $failures[] = 'Referencia ausente "' . $input . '" nao foi entendida.';
    }
}

$uploadActions = [
    'Enviar foto',
    'vou mandar foto',
    'vou enviar imagem',
    'mando a referência',
    'envio o desenho',
    'foto eu mando',
];
foreach ($uploadActions as $input) {
    $state = ['script_answers' => []];
    $accepted = studio_whatsapp_service_flow_store_current_answer($state, [
        'field_key' => 'reference_received',
        'answer_type' => 'image_or_skip',
    ], $input, 'text', false);
    if ($accepted || empty($state['script_waiting_for_reference_upload'])) {
        $failures[] = 'Acao de upload "' . $input . '" nao deixou o fluxo aguardando arquivo.';
    }
}

$relaxedScheduleCases = [
    'Pode ser outro dia! Qual tem?',
    'qual data tem disponível?',
    'que dia e horário tem disponível?',
    'me passa as próximas vagas',
    'não precisa ser esse dia',
];
foreach ($relaxedScheduleCases as $input) {
    if (!studio_whatsapp_ai_relaxes_schedule_preference($input)) {
        $failures[] = 'Flexibilizacao de agenda "' . $input . '" nao foi entendida.';
    }
}

$profileNameState = ['customer_name' => 'Nome do Perfil'];
if (studio_whatsapp_service_flow_field_complete('customer_name', $profileNameState)) {
    $failures[] = 'Nome vindo apenas do perfil nao deveria concluir o primeiro bloco do roteiro.';
}
$confirmedNameState = ['customer_name' => 'Nome Confirmado', 'customer_name_confirmed' => true];
if (!studio_whatsapp_service_flow_field_complete('customer_name', $confirmedNameState)) {
    $failures[] = 'Nome confirmado pelo fluxo deveria concluir o primeiro bloco do roteiro.';
}

if ($failures) {
    echo "Falhas:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo 'Resumo: variacoes de linguagem aprovadas em '
    . count($timeCases) . ' horarios, '
    . count($yesCases) . ' confirmacoes, '
    . count($noCases) . ' recusas, '
    . count($referenceDeclines) . ' referencias ausentes e '
    . count($uploadActions) . ' acoes de upload, '
    . count($relaxedScheduleCases) . " flexibilizacoes de agenda.\n";
