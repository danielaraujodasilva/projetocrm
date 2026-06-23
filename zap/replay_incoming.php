<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function replay_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function replay_require_access(): void
{
    $key = trim((string)($_GET['key'] ?? ''));
    $expected = 'zap_crm_daniel_2026';
    if (function_exists('current_admin') && current_admin() !== null) {
        return;
    }
    if ($key === $expected) {
        return;
    }
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

function replay_log_path(): string
{
    return APP_BASE_PATH . '/storage/whatsapp_webhook_events.log';
}

function replay_last_events(array $types = [], int $limit = 20): array
{
    $path = replay_log_path();
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $lines = array_slice($lines, -200);
    $events = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        if ($types && !in_array((string)($decoded['type'] ?? ''), $types, true)) {
            continue;
        }
        $events[] = $decoded;
        if (count($events) >= $limit) {
            break;
        }
    }
    return $events;
}

replay_require_access();

$host = (string)($_SERVER['HTTP_HOST'] ?? 'danieltatuador.com');
$projectBase = rtrim(dirname(app_base_path()), '/');
$serviceWebhookUrl = 'https://' . $host . $projectBase . '/api/whatsapp_webhook.php';
$studio = null;
$payload = [
    'entry' => [[
        'changes' => [[
            'value' => [
                'metadata' => [
                    'phone_number_id' => '1186818641175044',
                ],
                'contacts' => [[
                    'wa_id' => '5511947573311',
                    'profile' => ['name' => 'Teste Replay'],
                ]],
                'messages' => [[
                    'from' => '5511947573311',
                    'id' => 'replay-' . date('YmdHis'),
                    'timestamp' => (string)time(),
                    'type' => 'text',
                    'text' => ['body' => 'teste replay webhook'],
                ]],
                'statuses' => [],
            ],
        ]],
    ]],
];

$result = null;
$responseCode = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ch = curl_init($serviceWebhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $responseCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $result = [
        'http_code' => $responseCode,
        'curl_error' => $curlError,
        'body' => $body,
    ];

    $studio = null;
    try {
        $allStudios = list_studios();
        if (count($allStudios) === 1 && is_array($allStudios[0] ?? null)) {
            $studio = $allStudios[0];
        }
    } catch (Throwable) {
        $studio = null;
    }
}

$events = replay_last_events(['raw_post', 'change_received', 'incoming_message_seen', 'studio_lookup_result', 'crm_record_message_attempt', 'crm_record_message_ok', 'crm_record_message_error', 'incoming_message_without_studio', 'invalid_json', 'baileys_message'], 25);
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Replay WhatsApp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:#f8fafc;color:#0f172a}.shell{max-width:1100px}.card-soft{border:1px solid #e2e8f0;border-radius:22px;box-shadow:0 14px 40px rgba(15,23,42,.06)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.9em}pre{white-space:pre-wrap;max-height:360px;overflow:auto;margin:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:12px}
    </style>
</head>
<body>
<main class="container shell py-4 py-md-5">
    <div class="mb-4">
        <a href="/projetocrm/zap/status.php?key=zap_crm_daniel_2026" class="text-decoration-none">← voltar ao status</a>
        <h1 class="h3 mt-2 mb-1">Replay de webhook WhatsApp</h1>
        <p class="text-secondary mb-0">Envia um POST de teste para o mesmo endpoint da Meta.</p>
    </div>

    <div class="card card-soft mb-4">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-4"><strong>Webhook:</strong><br><span class="mono"><?= replay_h($serviceWebhookUrl) ?></span></div>
                <div class="col-md-4"><strong>Phone Number ID:</strong><br><span class="mono">1186818641175044</span></div>
                <div class="col-md-4"><strong>Texto:</strong><br><span class="mono">teste replay webhook</span></div>
            </div>
            <form method="post" class="mt-4">
                <button class="btn btn-primary rounded-pill">Executar replay</button>
            </form>
        </div>
    </div>

    <?php if ($result !== null): ?>
        <div class="card card-soft mb-4">
            <div class="card-body p-4">
                <h2 class="h5">Resultado</h2>
                <?php if ($studio): ?><p><strong>Studio local:</strong> <?= replay_h((string)$studio['id']) ?> / <?= replay_h((string)$studio['slug']) ?></p><?php endif; ?>
                <p><strong>HTTP:</strong> <?= replay_h((string)($result['http_code'] ?? '')) ?></p>
                <?php if (!empty($result['curl_error'])): ?><p class="text-danger"><strong>CURL:</strong> <?= replay_h((string)$result['curl_error']) ?></p><?php endif; ?>
                <details open>
                    <summary>Resposta</summary>
                    <pre class="mono mt-2"><?= replay_h((string)($result['body'] ?? '')) ?></pre>
                </details>
            </div>
        </div>
    <?php endif; ?>

    <div class="card card-soft">
        <div class="card-body p-4">
            <h2 class="h5 mb-3">Últimos logs relacionados</h2>
            <?php if (!$events): ?>
                <p class="text-secondary mb-0">Nenhum log ainda.</p>
            <?php endif; ?>
            <div class="vstack gap-3">
                <?php foreach ($events as $event): ?>
                    <div class="border rounded-4 p-3 bg-white">
                        <div class="d-flex justify-content-between gap-2 flex-wrap mb-2">
                            <strong><?= replay_h((string)($event['type'] ?? 'unknown')) ?></strong>
                            <span class="text-secondary small mono"><?= replay_h((string)($event['logged_at'] ?? '')) ?></span>
                        </div>
                        <pre class="mono"><?= replay_h(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>
</body>
</html>
