<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function zap_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function zap_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function zap_api_url(string $version, string $phoneNumberId): string
{
    return 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneNumberId) . '/messages';
}

$defaults = [
    'api_version' => 'v20.0',
    'phone_number_id' => '',
    'access_token' => '',
    'recipient_number' => '',
    'verify_token' => '',
    'message_text' => 'Teste da API oficial do WhatsApp',
    'webhook_url' => '',
];

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = zap_post('action');
    $apiVersion = zap_post('api_version', $defaults['api_version']);
    $phoneNumberId = zap_post('phone_number_id');
    $accessToken = zap_post('access_token');
    $recipientNumber = preg_replace('/\D+/', '', zap_post('recipient_number'));
    $messageText = zap_post('message_text', $defaults['message_text']);

    if ($action === 'send_test') {
        if ($phoneNumberId === '' || $accessToken === '' || $recipientNumber === '') {
            $result = [
                'ok' => false,
                'title' => 'Campos obrigatorios faltando',
                'detail' => 'Preencha o Phone Number ID, o Access Token e o numero de destino.',
            ];
        } else {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $recipientNumber,
                'type' => 'text',
                'text' => ['body' => $messageText],
            ];

            $ch = curl_init(zap_api_url($apiVersion, $phoneNumberId));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_TIMEOUT => 30,
            ]);

            $responseBody = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($responseBody === false || $curlError !== '') {
                $result = [
                    'ok' => false,
                    'title' => 'Falha ao chamar a API',
                    'detail' => $curlError !== '' ? $curlError : 'Nao foi possivel ler a resposta.',
                ];
            } else {
                $decoded = json_decode($responseBody, true);
                $result = [
                    'ok' => $httpCode >= 200 && $httpCode < 300,
                    'title' => $httpCode >= 200 && $httpCode < 300 ? 'Mensagem enviada' : 'Erro na API',
                    'detail' => is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : $responseBody,
                    'http_code' => $httpCode,
                ];
            }
        }
    } elseif ($action === 'preview_webhook') {
        $verifyToken = zap_post('verify_token');
        $webhookUrl = zap_post('webhook_url');
        $challenge = 'zap-test-' . date('YmdHis');
        $result = [
            'ok' => true,
            'title' => 'Teste de webhook',
            'detail' => "Use a URL abaixo no painel da Meta.\n\nGET {$webhookUrl}?hub.mode=subscribe&hub.verify_token=" . rawurlencode($verifyToken) . '&hub.challenge=' . rawurlencode($challenge) . "\n\nResposta esperada: {$challenge}",
        ];
    }

    $defaults['api_version'] = $apiVersion;
    $defaults['phone_number_id'] = $phoneNumberId;
    $defaults['access_token'] = $accessToken;
    $defaults['recipient_number'] = $recipientNumber;
    $defaults['verify_token'] = zap_post('verify_token');
    $defaults['message_text'] = $messageText;
    $defaults['webhook_url'] = zap_post('webhook_url', $defaults['webhook_url']);
}

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/zap')), '/');
$rootDir = $scriptDir === '' || $scriptDir === '/' ? '' : dirname($scriptDir);
$rootDir = $rootDir === '/' ? '' : $rootDir;
$webhookUrl = $defaults['webhook_url'] !== '' ? $defaults['webhook_url'] : ((($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $rootDir) . '/api/whatsapp_webhook.php');
$webhookUrl = rtrim($webhookUrl, '/');

?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zap Teste - WhatsApp Oficial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:linear-gradient(180deg,#f8fafc 0%,#eef2ff 100%);min-height:100vh}
        .hero{background:linear-gradient(135deg,#0f172a,#155e75);color:#fff;border-radius:1.5rem}
        .card-soft{border:0;box-shadow:0 12px 32px rgba(15,23,42,.08)}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
        .small-help{font-size:.92rem;color:#64748b}
    </style>
</head>
<body>
<main class="container py-4 py-md-5">
    <section class="hero p-4 p-md-5 mb-4">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-light text-dark mb-3">WhatsApp Official API</span>
                <h1 class="display-6 mb-3">Zap</h1>
                <p class="mb-0 text-white-50">Página mínima para validar webhook e enviar uma mensagem de teste pela API oficial do WhatsApp.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="small-help text-white-50">Webhook local</div>
                <div class="mono"><?= zap_h($webhookUrl !== '' ? $webhookUrl : '') ?></div>
            </div>
        </div>
    </section>

    <?php if (is_array($result)): ?>
        <div class="alert <?= !empty($result['ok']) ? 'alert-success' : 'alert-danger' ?> card-soft">
            <strong><?= zap_h((string)($result['title'] ?? 'Resultado')) ?></strong>
            <?php if (!empty($result['http_code'])): ?>
                <span class="badge text-bg-secondary ms-2">HTTP <?= (int)$result['http_code'] ?></span>
            <?php endif; ?>
            <div class="mt-2"><pre class="mb-0 mono" style="white-space:pre-wrap"><?= zap_h((string)($result['detail'] ?? '')) ?></pre></div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card card-soft">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Configuração mínima</h2>
                    <form method="post" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">API version</label>
                            <input class="form-control" name="api_version" value="<?= zap_h($defaults['api_version']) ?>" placeholder="v20.0">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Webhook URL</label>
                            <input class="form-control" name="webhook_url" value="<?= zap_h($webhookUrl) ?>" placeholder="<?= zap_h($webhookUrl) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number ID</label>
                            <input class="form-control" name="phone_number_id" value="<?= zap_h($defaults['phone_number_id']) ?>" placeholder="123456789012345">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Verify Token</label>
                            <input class="form-control" name="verify_token" value="<?= zap_h($defaults['verify_token']) ?>" placeholder="token-de-verificacao">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Access Token</label>
                            <input class="form-control mono" name="access_token" value="<?= zap_h($defaults['access_token']) ?>" placeholder="EAAB...">
                            <div class="small-help mt-1">Use o token permanente do app/usuário com permissão para enviar mensagens.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Numero de destino</label>
                            <input class="form-control" name="recipient_number" value="<?= zap_h($defaults['recipient_number']) ?>" placeholder="5511999999999">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mensagem de teste</label>
                            <textarea class="form-control" name="message_text" rows="4"><?= zap_h($defaults['message_text']) ?></textarea>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button class="btn btn-primary" type="submit" name="action" value="send_test">Enviar teste</button>
                            <button class="btn btn-outline-secondary" type="submit" name="action" value="preview_webhook">Ver webhook</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card card-soft mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Checklist rapido</h2>
                    <ul class="mb-0">
                        <li>Webhook apontando para <span class="mono">/api/whatsapp_webhook.php</span></li>
                        <li><span class="mono">GET</span> de verificacao aceito com <span class="mono">hub.mode</span>, <span class="mono">hub.verify_token</span> e <span class="mono">hub.challenge</span></li>
                        <li><span class="mono">POST</span> da Meta chegando no endpoint</li>
                        <li>Envio de mensagem de teste com <span class="mono">phone_number_id</span> e <span class="mono">access_token</span></li>
                    </ul>
                </div>
            </div>
            <div class="card card-soft">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Exemplo de teste</h2>
                    <p class="mb-2 small-help">Webhook:</p>
                    <code class="d-block p-3 bg-light rounded mono"><?= zap_h($webhookUrl) ?></code>
                    <p class="mt-3 mb-2 small-help">Mensagem:</p>
                    <code class="d-block p-3 bg-light rounded mono">POST /messages com messaging_product=whatsapp</code>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
