<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function status_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function status_log_path(): string
{
    return APP_BASE_PATH . '/storage/whatsapp_webhook_events.log';
}

function status_read_events(array $filters = [], int $limit = 120): array
{
    $path = status_log_path();
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $lines = array_slice($lines, -500);
    $events = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            $decoded = ['type' => 'invalid_line', 'raw' => $line];
        }
        $type = (string)($decoded['type'] ?? 'unknown');
        $group = match ($type) {
            'raw_post' => 'raw_post',
            'incoming_message', 'incoming_message_seen', 'incoming_message_without_studio' => 'incoming_message',
            'crm_record_message_ok' => 'record_ok',
            'studio_lookup_result' => 'lookup',
            'crm_record_message_error', 'invalid_json' => 'errors',
            default => 'all',
        };
        if (!empty($filters['type']) && $filters['type'] !== 'all' && $filters['type'] !== $group && $filters['type'] !== $type) {
            continue;
        }
        $events[] = $decoded;
        if (count($events) >= $limit) {
            break;
        }
    }
    return $events;
}

if (($_POST['action'] ?? '') === 'clear_log') {
    if (is_file(status_log_path())) {
        file_put_contents(status_log_path(), '', LOCK_EX);
    }
    header('Location: status.php?key=' . urlencode((string)($_GET['key'] ?? '')));
    exit;
}

if (($_POST['action'] ?? '') === 'clear_all_logs') {
    foreach ([status_log_path(), APP_BASE_PATH . '/services/whatsapp/whatsapp_service.log'] as $logPath) {
        if (is_file($logPath)) {
            file_put_contents($logPath, '', LOCK_EX);
        }
    }
    header('Location: status.php?key=' . urlencode((string)($_GET['key'] ?? '')));
    exit;
}

$filters = ['type' => (string)($_GET['type'] ?? 'all')];
$events = status_read_events($filters);
$known = function_exists('studio_whatsapp_zap_local_config') ? studio_whatsapp_zap_local_config() : [];
$knownPhone = trim((string)($known['phone_number_id'] ?? '1186818641175044'));
$knownVerify = trim((string)($known['verify_token'] ?? 'zap_crm_daniel_2026'));
$allEvents = status_read_events(['type' => 'all'], 500);
$rawPosts = array_values(array_filter($allEvents, static fn(array $event): bool => (string)($event['type'] ?? '') === 'raw_post'));
$localRawCount = 0;
$externalRawCount = 0;
$lastRemoteAddr = '';
$lastUserAgent = '';
$lastMessagesEvent = null;
foreach ($rawPosts as $event) {
    $remoteAddr = trim((string)($event['remote_addr'] ?? ''));
    if ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1') {
        $localRawCount++;
    } elseif ($remoteAddr !== '') {
        $externalRawCount++;
    }
    if ($remoteAddr !== '') {
        $lastRemoteAddr = $remoteAddr;
    }
    if (trim((string)($event['user_agent'] ?? '')) !== '') {
        $lastUserAgent = (string)$event['user_agent'];
    }
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $hasMessages = false;
    if (is_array($payload['entry'] ?? null)) {
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ((array)($entry['changes'] ?? []) as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                if (!empty($value['messages']) && is_array($value['messages'])) {
                    $hasMessages = true;
                    break 3;
                }
            }
        }
    }
    if ($hasMessages) {
        $lastMessagesEvent = $event;
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Status WhatsApp API</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:#f8fafc;color:#0f172a}.shell{max-width:1180px}.card-soft{border:1px solid #e2e8f0;border-radius:22px;box-shadow:0 14px 40px rgba(15,23,42,.06)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.9em}.pill{border-radius:999px;padding:.25rem .65rem;font-size:.78rem;font-weight:700}pre{white-space:pre-wrap;max-height:360px;overflow:auto;margin:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:12px}
    </style>
</head>
<body>
<main class="container shell py-4 py-md-5">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div>
            <a href="index.php" class="text-decoration-none">← voltar ao teste</a>
            <h1 class="h3 mt-2 mb-1">Status do webhook WhatsApp</h1>
            <p class="text-secondary mb-0">Diagnóstico do recebimento, gravação e lookup do estúdio.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-primary rounded-pill" href="replay_incoming.php?key=zap_crm_daniel_2026">replay</a>
            <form method="post" class="d-inline d-flex gap-2 flex-wrap">
                <button class="btn btn-outline-danger rounded-pill" name="action" value="clear_log">limpar webhook</button>
                <button class="btn btn-outline-warning rounded-pill" name="action" value="clear_all_logs">limpar tudo</button>
            </form>
        </div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-lg-3"><div class="card card-soft h-100"><div class="card-body p-4"><strong>Total raw_post local</strong><div class="h3 mt-2 mb-0"><?= status_h((string)$localRawCount) ?></div></div></div></div>
        <div class="col-lg-3"><div class="card card-soft h-100"><div class="card-body p-4"><strong>Total raw_post externo</strong><div class="h3 mt-2 mb-0"><?= status_h((string)$externalRawCount) ?></div></div></div></div>
        <div class="col-lg-3"><div class="card card-soft h-100"><div class="card-body p-4"><strong>Último remote_addr</strong><div class="mono small mt-2"><?= status_h($lastRemoteAddr !== '' ? $lastRemoteAddr : '-') ?></div><div class="small mt-2"><?= status_h(($lastRemoteAddr === '127.0.0.1' || $lastRemoteAddr === '::1') ? 'Evento local/replay' : ($lastRemoteAddr !== '' ? 'Evento externo/Meta provável' : 'Sem evento')) ?></div></div></div></div>
        <div class="col-lg-3"><div class="card card-soft h-100"><div class="card-body p-4"><strong>Último user_agent</strong><div class="mono small mt-2"><?= status_h($lastUserAgent !== '' ? $lastUserAgent : '-') ?></div></div></div></div>
    </div>

    <div class="card card-soft mb-4"><div class="card-body p-4"><strong>Último evento com messages</strong><div class="small mt-2"><?= $lastMessagesEvent ? status_h((string)($lastMessagesEvent['logged_at'] ?? '')) : 'Nenhum ainda' ?></div><?php if ($lastMessagesEvent): ?><pre class="mono mt-3"><?= status_h(json_encode($lastMessagesEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre><?php endif; ?></div></div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card card-soft h-100"><div class="card-body p-4"><strong>Webhook</strong><div class="mono small mt-2"><?= status_h(status_log_path()) ?></div></div></div>
        </div>
        <div class="col-lg-4">
            <div class="card card-soft h-100"><div class="card-body p-4"><strong>Config conhecida</strong><div class="small mt-2">Phone Number ID: <span class="mono"><?= status_h($knownPhone) ?></span></div><div class="small">Verify Token: <span class="mono"><?= status_h($knownVerify) ?></span></div></div></div>
        </div>
        <div class="col-lg-4">
            <div class="card card-soft h-100"><div class="card-body p-4"><strong>Filtro</strong><div class="mt-2 d-flex gap-2 flex-wrap"><?php foreach (['all' => 'todos', 'raw_post' => 'raw_post', 'incoming_message' => 'incoming_message', 'errors' => 'errors', 'record_ok' => 'record_ok', 'lookup' => 'lookup'] as $key => $label): ?><a class="btn btn-sm <?= $filters['type'] === $key ? 'btn-primary' : 'btn-outline-secondary' ?> rounded-pill" href="?type=<?= status_h($key) ?>"><?= status_h($label) ?></a><?php endforeach; ?></div></div></div>
        </div>
    </div>

    <div class="card card-soft mb-4">
        <div class="card-body p-4">
            <h2 class="h5 mb-3">Diagnóstico externo</h2>
            <ul class="mb-0">
                <li>Se não aparecer <strong>raw_post</strong> ao mandar mensagem para o número oficial: a Meta não está chamando o webhook. Conferir Callback URL, Verify Token e campo <code>messages</code>.</li>
                <li>Se aparecer <strong>raw_post</strong> mas não aparecer <strong>incoming_message_seen</strong>: a Meta está chamando, mas o payload pode não conter <code>value.messages</code>.</li>
                <li>Se aparecer <strong>incoming_message_seen</strong> e <strong>crm_record_message_ok</strong>: o recebimento está funcionando e o problema passa a ser listagem/workspace.</li>
                <li>Se aparecer <strong>crm_record_message_error</strong>: corrigir banco/função de gravação.</li>
            </ul>
        </div>
    </div>

    <div class="card card-soft mb-4">
        <div class="card-body p-4">
            <h2 class="h5 mb-3">Teste real de recebimento</h2>
            <ol class="mb-0">
                <li>Clique em limpar log.</li>
                <li>Envie uma mensagem real do seu WhatsApp para +55 11 95786-7798.</li>
                <li>Aguarde 10 segundos.</li>
                <li>Atualize esta página.</li>
            </ol>
            <p class="mt-3 mb-0"><strong>Interpretação:</strong> sem <code>raw_post</code> externo, a Meta não chamou este webhook; com <code>raw_post</code> externo sem <code>messages</code>, chegou status/outro evento; com <code>incoming_message_seen</code> e <code>crm_record_message_ok</code>, o recebimento está funcionando; se gravou e não aparece no workspace, o problema está na listagem/filtro.</p>
        </div>
    </div>

    <?php if (!$events): ?>
        <div class="card card-soft"><div class="card-body p-4">Nenhum evento registrado ainda.</div></div>
    <?php endif; ?>

    <div class="vstack gap-3">
        <?php foreach ($events as $event): ?>
            <?php
                $type = (string)($event['type'] ?? 'unknown');
                $badge = match ($type) {
                    'raw_post' => 'text-bg-primary',
                    'change_received' => 'text-bg-info',
                    'incoming_message_seen', 'incoming_message', 'crm_record_message_ok' => 'text-bg-success',
                    'studio_lookup_result' => 'text-bg-secondary',
                    'crm_record_message_error', 'invalid_json', 'incoming_message_without_studio' => 'text-bg-danger',
                    default => 'text-bg-light text-dark',
                };
            ?>
            <div class="card card-soft">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="pill <?= $badge ?>"><?= status_h($type) ?></span>
                            <?php if (!empty($event['message_id'])): ?><span class="mono text-secondary"><?= status_h((string)$event['message_id']) ?></span><?php endif; ?>
                        </div>
                        <span class="text-secondary small mono"><?= status_h((string)($event['logged_at'] ?? '')) ?></span>
                    </div>

                    <pre class="mono"><?= status_h(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>
</body>
</html>
