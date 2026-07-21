<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$tz = new DateTimeZone('America/Sao_Paulo');
$base = new DateTimeImmutable('2026-07-20 00:00:00', $tz); // segunda-feira
$failures = [];

$cases = [
    'hoje' => '2026-07-20',
    'amanhã' => '2026-07-21',
    'depois de amanhã' => '2026-07-22',
    'dia 14' => '2026-08-14',
    '14/08' => '2026-08-14',
    '14-08-26' => '2026-08-14',
    '14 de agosto' => '2026-08-14',
    '14 de agosto de 2026' => '2026-08-14',
    'daqui a 3 dias' => '2026-07-23',
    'daqui a duas semanas' => '2026-08-03',
    'em 1 mes' => '2026-08-20',
    'segunda' => '2026-07-20',
    'próxima segunda' => '2026-07-27',
    'terça agora' => '2026-07-21',
    'terça que vem' => '2026-07-21',
    'terça da semana que vem' => '2026-07-28',
    'quinta-feira' => '2026-07-23',
    'quinta da próxima semana' => '2026-07-30',
    'sexta de manhã' => '2026-07-24',
    'sábado agora' => '2026-07-25',
    'próximo sábado' => '2026-07-25',
    'domingo' => '2026-07-26',
    'fim de semana' => '2026-07-25',
];

foreach ($cases as $input => $expected) {
    $date = studio_whatsapp_ai_parse_natural_date_pt((string)$input, $base);
    $actual = $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : 'null';
    if ($actual !== $expected) {
        $failures[] = $input . ' => esperado ' . $expected . ', veio ' . $actual;
    }
}

$preferences = [
    'tem para quinta que vem às 15h?' => ['date' => '2026-07-23', 'weekday' => 4, 'time' => '15:00'],
    'quero terça da semana que vem de manhã' => ['date' => '2026-07-28', 'weekday' => 2, 'period' => 'morning'],
    'daqui a duas semanas às 10' => ['date' => '2026-08-03', 'time' => '10:00'],
    'posso todas as quintas de manhã' => ['date' => '', 'weekday' => 4, 'period' => 'morning'],
    'amanhã cedo, pode ser?' => ['date' => '2026-07-21', 'period' => 'morning'],
    'amanhã no primeiro horário' => ['date' => '2026-07-21', 'period' => 'morning'],
    'amanhã cedinho' => ['date' => '2026-07-21', 'period' => 'morning'],
];

foreach ($preferences as $input => $expected) {
    $pref = studio_whatsapp_ai_schedule_preference((string)$input, $base);
    foreach ($expected as $key => $value) {
        if ((string)($pref[$key] ?? '') !== (string)$value) {
            $failures[] = $input . ' preferência ' . $key . ' esperado ' . $value . ', veio ' . (string)($pref[$key] ?? '');
        }
    }
}

if ($failures) {
    echo "Falhas:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "Resumo: parser de datas PT-BR aprovado em " . count($cases) . " formas e " . count($preferences) . " preferências.\n";
