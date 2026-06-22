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

function status_read_events(int $limit = 80): array
{
    $path = status_log_path();
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $lines = array_slice($lines, -$limit);
    $events = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        $events[] = is_array($decoded) ? $decoded : ['type' => 'invalid_line', 'raw' => $line];
    }

    return $events;
}

if (($_POST['action'] ?? '') === 'clear_log') {
    if (is_file(status_log_path())) {
        file_put_contents(status_log_path(), '', LOCK_EX);
    }
    header('Location: status.php');
    exit;
}

$events = status_read_events();

?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Status WhatsApp API</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:#f8fafc;color:#0f172a}
        .shell{max-width:1100px}
        .card-soft{border:1px solid #e2e8f0;border-radius:22px;box-shadow:0 14px 40px rgba(15,23,42,.06)}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.9em}
        .pill{border-radius:999px;padding:.25rem .65rem;font-size:.78rem;font-weight:700}
        pre{white-space:pre-wrap;max-height:360px;overflow:auto;margin:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:12px}
    </style>
</head>
<body>
<main class="container shell py-4 py-md-5">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div>
            <a href="index.php" class="text-decoration-none">← voltar ao teste</a>
            <h1 class="h3 mt-2 mb-1">Status do webhook WhatsApp</h1>
            <p class="text-secondary mb-0">Aqui aparecem os retornos da Meta: sent, delivered, read, failed e mensagens recebidas.</p>
        </div>
        <form method="post">
            <button class="btn btn-outline-danger rounded-pill" name="action" value="clear_log">limpar log</button>
        </form>
    </div>

    <div class="alert alert-info card-soft">
        <strong>Arquivo do log:</strong>
        <span class="mono"><?= status_h(status_log_path()) ?></span>
    </div>

    <?php if (!$events): ?>
        <div class="card card-soft"><div class="card-body p-4">Nenhum evento registrado ainda. Envie outra mensagem de teste e aguarde a Meta devolver algum status. Sim, ela trabalha no próprio tempo místico dela.</div></div>
    <?php endif; ?>

    <div class="vstack gap-3">
        <?php foreach ($events as $event): ?>
            <?php
                $type = (string)($event['type'] ?? 'unknown');
                $status = (string)($event['status'] ?? '');
                $badge = match ($status) {
                    'sent' => 'text-bg-primary',
                    'delivered' => 'text-bg-success',
                    'read' => 'text-bg-success',
                    'failed' => 'text-bg-danger',
                    default => match ($type) {
                        'message_status' => 'text-bg-secondary',
                        'incoming_message' => 'text-bg-success',
                        'verification' => 'text-bg-info',
                        default => 'text-bg-light text-dark',
                    },
                };
            ?>
            <div class="card card-soft">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="pill <?= $badge ?>"><?= status_h($status !== '' ? $status : $type) ?></span>
                            <?php if (!empty($event['message_id'])): ?>
                                <span class="mono text-secondary"><?= status_h((string)$event['message_id']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="text-secondary small mono"><?= status_h((string)($event['logged_at'] ?? '')) ?></span>
                    </div>

                    <?php if ($type === 'message_status'): ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><strong>Destino:</strong><br><span class="mono"><?= status_h((string)($event['recipient_id'] ?? '')) ?></span></div>
                            <div class="col-md-4"><strong>Phone ID:</strong><br><span class="mono"><?= status_h((string)($event['phone_number_id'] ?? '')) ?></span></div>
                            <div class="col-md-4"><strong>Timestamp Meta:</strong><br><span class="mono"><?= status_h((string)($event['timestamp'] ?? '')) ?></span></div>
                        </div>
                        <?php if (!empty($event['errors'])): ?>
                            <div class="alert alert-danger">
                                <strong>Erro de entrega:</strong>
                                <pre class="mono mt-2"><?= status_h(json_encode($event['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($type === 'incoming_message'): ?>
                        <div><strong>De:</strong> <span class="mono"><?= status_h((string)($event['from'] ?? '')) ?></span></div>
                        <div><strong>Tipo:</strong> <span class="mono"><?= status_h((string)($event['message_type'] ?? '')) ?></span></div>
                        <?php if (!empty($event['text'])): ?><div class="mt-2"><strong>Texto:</strong> <?= status_h((string)$event['text']) ?></div><?php endif; ?>
                    <?php endif; ?>

                    <details class="mt-3">
                        <summary>ver JSON bruto</summary>
                        <pre class="mono mt-2"><?= status_h(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
                    </details>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>
</body>
</html>
