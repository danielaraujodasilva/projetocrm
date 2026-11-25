<?php

declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'CLI';
require dirname(__DIR__) . '/app/bootstrap.php';

if (!google_calendar_configured()) {
    fwrite(STDERR, "Google Calendar OAuth não configurado.\n");
    exit(1);
}

$results = google_calendar_sync_due_studios();
$failed = false;
foreach ($results as $result) {
    $failed = $failed || empty($result['ok']);
    echo sprintf(
        "[%s] estúdio %d: %s\n",
        !empty($result['ok']) ? 'ok' : 'erro',
        (int)$result['studio_id'],
        (string)$result['message']
    );
}
if (!$results) {
    echo "Nenhuma sincronização pendente.\n";
}

// Agendamentos criados pela Irene (fluxo Pix) entram direto no banco e nao
// passam pelo formulario do CRM, entao nao eram enviados ao Google. Empurra
// os que ainda nao tem evento, com a cor propria da Irene (colorId=3).
$pushFailed = false;
foreach (list_studios() as $studio) {
    try {
        if (!studio_schema_ready($studio)) {
            continue;
        }
        $integration = google_calendar_integration($studio);
        if (!google_calendar_is_connected($integration)) {
            continue;
        }
        $pdo = studio_db($studio);
        $rows = $pdo->query(
            "SELECT id, title
             FROM appointments
             WHERE import_source = 'autochat_pix'
               AND google_calendar_event_id IS NULL
               AND appointment_date >= CURDATE()
               AND LOWER(COALESCE(status, '')) NOT IN ('cancelado','cancelada','cancelled','canceled','falta','faltou','no_show','nao_compareceu','perdido')
             ORDER BY appointment_date ASC, start_time ASC, id ASC
             LIMIT 20"
        )->fetchAll() ?: [];
        foreach ($rows as $row) {
            $ok = google_calendar_try_push_appointment($studio, (int)$row['id'], false, true);
            $pushFailed = $pushFailed || !$ok;
            echo sprintf(
                "[irene] agendamento %d (%s): %s\n",
                (int)$row['id'],
                (string)($row['title'] ?? ''),
                $ok ? 'enviado ao Google' : 'falhou'
            );
        }
    } catch (Throwable $e) {
        $pushFailed = true;
        echo sprintf("[irene] estúdio %d: erro %s\n", (int)($studio['id'] ?? 0), $e->getMessage());
    }
}

exit(($failed || $pushFailed) ? 1 : 0);
