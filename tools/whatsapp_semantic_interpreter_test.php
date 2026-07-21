<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$studio = db()->query('SELECT * FROM studios ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$studio) {
    fwrite(STDERR, "Nenhum estúdio encontrado.\n");
    exit(1);
}

$config = studio_openai_config($studio);
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with((string)$argument, '--model=')) {
        $config['model'] = trim(substr((string)$argument, 8));
    }
}
$cases = [
    [
        'name' => 'pergunta lateral sem abandonar a reserva',
        'state' => [
            'active' => true,
            'reference_received' => true,
            'tattoo_idea' => 'dragão realista',
            'body_area' => 'antebraço',
            'customer_name' => 'Carlos',
            'quote' => ['amount' => 799, 'label' => 'R$ 799,00'],
            'selected_slot' => ['date' => '2026-07-30', 'time' => '10:00'],
            'slot_confirmed' => false,
        ],
        'history' => [
            'Cliente: quero um dragão realista no antebraço',
            'Atendente: O valor fica R$ 799,00. Quinta, dia 30, às 10 funciona?',
        ],
        'message' => 'antes disso, parcela? se der pode deixar esse horário mesmo',
        'assert' => static fn(array $result): bool => !empty($result['wants_booking'])
            && !empty($result['should_resume_booking'])
            && (string)($result['intent'] ?? '') === 'payment_terms',
    ],
    [
        'name' => 'correção explícita de projeto e agenda',
        'state' => [
            'active' => true,
            'reference_received' => true,
            'tattoo_idea' => 'dragão',
            'body_area' => 'braço externo',
            'schedule_preference' => 'quinta de manhã',
            'quote' => ['amount' => 799, 'label' => 'R$ 799,00'],
        ],
        'history' => [
            'Cliente: pensei num dragão no braço por fora',
            'Atendente: Certo. Quinta de manhã funciona para você?',
        ],
        'message' => 'pensando melhor esquece o braço, quero a mesma arte na perna por dentro do lado esquerdo e só consigo sexta à tarde',
        'assert' => static fn(array $result): bool => !empty($result['correction_detected'])
            && !empty($result['wants_booking'])
            && !empty($result['should_resume_booking']),
    ],
    [
        'name' => 'mensagens fragmentadas com referência pronominal',
        'state' => ['active' => true],
        'history' => [
            'Cliente: oi boa noite',
            'Cliente: quero fazer um orçamento',
            'Cliente: minha ideia é uma homenagem para minha mãe com flores e a data de nascimento dela',
            'Atendente: Em qual parte do corpo?',
            'Cliente: pensei nas costas',
            'Atendente: Você quer fechamento completo?',
        ],
        'message' => 'isso, ela inteira, e queria ver se tem como marcar já',
        'assert' => static fn(array $result): bool => !empty($result['wants_booking'])
            && !empty($result['should_resume_booking'])
            && (string)($result['intent'] ?? '') === 'schedule',
    ],
    [
        'name' => 'português informal preserva desenho área e restrição',
        'state' => ['active' => true],
        'history' => [
            'Atendente: Me explica sua ideia e quando consegue vir.',
        ],
        'message' => 'sabado d tarde so qro, n da outro dia. e o desenho eh um dragao, costas inteira',
        'assert' => static fn(array $result): bool => !empty($result['wants_booking'])
            && str_contains(mb_strtolower((string)($result['confirmed']['tattoo_idea'] ?? ''), 'UTF-8'), 'drag')
            && str_contains(mb_strtolower((string)($result['confirmed']['body_area'] ?? ''), 'UTF-8'), 'cost')
            && str_contains(mb_strtolower((string)($result['confirmed']['schedule_preference'] ?? ''), 'UTF-8'), 'sáb'),
    ],
    [
        'name' => 'área anatômica rara não vira tema',
        'state' => [
            'active' => true,
            'tattoo_idea' => 'uma rosa em traço fino',
        ],
        'history' => [
            'Cliente: quero uma rosa em traço fino',
            'Atendente: Em qual local do corpo seria?',
        ],
        'message' => 'na escápula esquerda',
        'assert' => static fn(array $result): bool => str_contains(mb_strtolower((string)($result['confirmed']['body_area'] ?? ''), 'UTF-8'), 'escáp')
            && str_contains(mb_strtolower((string)($result['confirmed']['tattoo_idea'] ?? ''), 'UTF-8'), 'rosa'),
    ],
];

$failed = 0;
foreach ($cases as $case) {
    $startedAt = microtime(true);
    $result = studio_whatsapp_ai_interpret_conversation(
        $studio,
        $config,
        $case['state'],
        $case['history'],
        $case['message']
    );
    $providerUnavailable = empty($result['ok'])
        && preg_match('/timed out|timeout|temporar|rate limit|indispon/i', (string)($result['error'] ?? ''));
    $passed = !empty($result['ok']) && $case['assert']($result);
    if (!$passed && !$providerUnavailable) {
        $failed++;
    }
    echo ($passed ? '[OK] ' : ($providerUnavailable ? '[API INDISPONÍVEL] ' : '[FALHOU] '))
        . $case['name'] . ' (' . number_format(microtime(true) - $startedAt, 1) . "s)\n";
    echo json_encode([
        'intent' => $result['intent'] ?? null,
        'confidence' => $result['confidence'] ?? null,
        'goal' => $result['customer_goal'] ?? null,
        'wants_booking' => $result['wants_booking'] ?? null,
        'resume' => $result['should_resume_booking'] ?? null,
        'slot_confirmation' => $result['slot_confirmation'] ?? null,
        'correction' => $result['correction_detected'] ?? null,
        'confirmed' => $result['confirmed'] ?? null,
        'questions' => $result['unanswered_customer_questions'] ?? null,
        'error' => $result['error'] ?? null,
        'model' => $result['model'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
}

exit($failed > 0 ? 1 : 0);
