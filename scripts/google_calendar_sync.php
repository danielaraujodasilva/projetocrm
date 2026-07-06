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
exit($failed ? 1 : 0);
