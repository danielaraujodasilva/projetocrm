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

function zap_only_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function zap_api_url(string $version, string $phoneNumberId): string
{
    return 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneNumberId) . '/messages';
}

function zap_config_path(): string
{
    return APP_BASE_PATH . '/storage/zap_api_config.local.json';
}

function zap_load_saved_config(): array
{
    $path = zap_config_path();
    if (!is_file($path)) {
        return [];
    }

    $content = file_get_contents($path);
    if (!is_string($content) || trim($content) === '') {
        return [];
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function zap_save_config(array $config): bool
{
    $dir = dirname(zap_config_path());
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $safeConfig = [
        'api_version' => trim((string)($config['api_version'] ?? '')),
        'app_id' => trim((string)($config['app_id'] ?? '')),
        'waba_id' => trim((string)($config['waba_id'] ?? '')),
        'phone_number_id' => trim((string)($config['phone_number_id'] ?? '')),
        'access_token' => trim((string)($config['access_token'] ?? '')),
        'recipient_number' => zap_only_digits((string)($config['recipient_number'] ?? '')),
        'verify_token' => trim((string)($config['verify_token'] ?? '')),
        'webhook_url' => trim((string)($config['webhook_url'] ?? '')),
        'updated_at' => date('c'),
    ];

    $saved = file_put_contents(
        zap_config_path(),
        json_encode($safeConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    if ($saved === false) {
        return false;
    }

    @chmod(zap_config_path(), 0600);
    return true;
}

function zap_token_preview(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return 'vazio';
    }

    $length = strlen($token);
    $start = substr($token, 0, 8);
    $end = $length > 14 ? substr($token, -6) : '';

    return $end !== '' ? "{$start}...{$end} ({$length} caracteres)" : "{$start}... ({$length} caracteres)";
}

function zap_required_report(array $fields): string
{
    $missing = [];
    $lines = [];

    foreach ($fields as $field) {
        $ok = trim((string)$field['value']) !== '';
        if (!$ok) {
            $missing[] = $field['label'];
        }
        $lines[] = sprintf(
            '%s %s: %s',
            $ok ? '✅' : '❌',
            $field['label'],
            $field['preview'] !== '' ? $field['preview'] : 'vazio'
        );
    }

    return "Campos faltando exatamente:\n- " . implode("\n- ", $missing) . "\n\nRaio-x do que chegou no servidor:\n" . implode("\n", $lines) . "\n\nSe o campo parecia preenchido na tela mas chegou vazio aqui, o navegador/autocomplete ou o reload do formulário te pregou uma peça. Tecnologia: a arte de perder a paciência com eletricidade organizada.";
}

function zap_test_webhook(string $webhookUrl, string $verifyToken): array
{
    $challenge = 'zap-test-' . date('YmdHis');
    $separator = str_contains($webhookUrl, '?') ? '&' : '?';
    $url = $webhookUrl . $separator . http_build_query([
        'hub.mode' => 'subscribe',
        'hub.verify_token' => $verifyToken,
        'hub.challenge' => $challenge,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $bodyText = is_string($body) ? trim($body) : '';
    $ok = $curlError === '' && $httpCode >= 200 && $httpCode < 300 && $bodyText === $challenge;

    return [
        'ok' => $ok,
        'title' => $ok ? 'Webhook validado' : 'Webhook ainda não validou',
        'http_code' => $httpCode,
        'detail' => "URL testada:\n{$url}\n\nResposta esperada:\n{$challenge}\n\nResposta recebida:\n" . ($bodyText !== '' ? $bodyText : ($curlError !== '' ? $curlError : 'Sem corpo de resposta')),
    ];
}

function zap_api_error_hint(array $decoded, string $recipientNumber, string $officialNumber): string
{
    $hint = [];
    $hint[] = 'Diagnóstico provável:';

    if ($recipientNumber === zap_only_digits($officialNumber)) {
        $hint[] = '- O número de destino era o próprio número oficial da API. Isso costuma dar ruim. Troquei o padrão para seu particular: 5511947573311.';
    }

    $hint[] = '- Confira se o número de destino está com DDI + DDD + número, só dígitos. Agora ficou 5511947573311.';
    $hint[] = '- Se for primeiro contato fora da janela de 24h, a Meta pode exigir template aprovado em vez de texto livre.';
    $hint[] = '- Se o token estiver certo e o Phone Number ID estiver certo, esse #100 geralmente aponta para algum parâmetro do payload, quase sempre o campo to ou tipo de mensagem permitido.';

    if (isset($decoded['error'])) {
        $hint[] = '';
        $hint[] = 'Resposta bruta da Meta:';
        $hint[] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    return implode("\n", $hint);
}

$known = [
    'app_name' => 'mkt_danieltatuador',
    'app_id' => '2034781270582768',
    'waba_id' => '1908414750031996',
    'phone_number_id' => '1186818641175044',
    'display_number' => '+55 11 95786-7798',
    'api_version' => 'v23.0',
    'verify_token' => 'zap_crm_daniel_2026',
    'webhook_url' => 'https://danieltatuador.com/projetocrm/api/whatsapp_webhook.php',
];

$legacy = [
    'waba_id' => '120771777788528',
    'phone_number_id' => '126382657222367',
    'display_number' => '+1 555-101-5039',
];

$savedConfig = zap_load_saved_config();
$defaults = array_merge([
    'api_version' => $known['api_version'],
    'app_id' => $known['app_id'],
    'waba_id' => $known['waba_id'],
    'phone_number_id' => $known['phone_number_id'],
    'access_token' => '',
    'recipient_number' => '5511947573311',
    'verify_token' => $known['verify_token'],
    'message_text' => 'Teste da API oficial do WhatsApp pelo painel Zap do Projetocrm.',
    'webhook_url' => $known['webhook_url'],
], array_intersect_key($savedConfig, array_flip([
    'api_version',
    'app_id',
    'waba_id',
    'phone_number_id',
    'access_token',
    'recipient_number',
    'verify_token',
    'message_text',
    'webhook_url',
])));

if (($defaults['recipient_number'] ?? '') === zap_only_digits($known['display_number'])) {
    $defaults['recipient_number'] = '5511947573311';
}

$result = null;
$configSaved = is_file(zap_config_path());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = zap_post('action');
    $apiVersion = zap_post('api_version', $defaults['api_version']);
    $phoneNumberId = zap_post('phone_number_id', $defaults['phone_number_id']);
    $postedAccessToken = zap_post('access_token');
    $accessToken = $postedAccessToken !== '' ? $postedAccessToken : (string)($defaults['access_token'] ?? '');
    $recipientRaw = zap_post('recipient_number', $defaults['recipient_number']);
    $recipientNumber = zap_only_digits($recipientRaw);
    $messageText = zap_post('message_text', $defaults['message_text']);
    $verifyToken = zap_post('verify_token', $defaults['verify_token']);
    $webhookUrl = zap_post('webhook_url', $defaults['webhook_url']);
    $wabaId = zap_post('waba_id', $defaults['waba_id']);
    $appId = zap_post('app_id', $defaults['app_id']);

    if (in_array($action, ['send_test', 'test_webhook', 'preview_payload', 'save_config'], true)) {
        $configSaved = zap_save_config([
            'api_version' => $apiVersion,
            'app_id' => $appId,
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'access_token' => $accessToken,
            'recipient_number' => $recipientNumber,
            'verify_token' => $verifyToken,
            'webhook_url' => $webhookUrl,
        ]);
    }

    if ($action === 'save_config') {
        $result = [
            'ok' => $configSaved,
            'title' => $configSaved ? 'Configuração salva' : 'Não consegui salvar a configuração',
            'detail' => $configSaved
                ? 'Token e dados salvos em storage/zap_api_config.local.json no servidor. Esse arquivo é local e não entra no GitHub.'
                : 'Falha ao gravar em storage/zap_api_config.local.json. Confira permissão da pasta storage.',
        ];
    } elseif ($action === 'send_test') {
        if ($phoneNumberId === '' || $accessToken === '' || $recipientNumber === '') {
            $result = [
                'ok' => false,
                'title' => 'Campos obrigatórios faltando',
                'detail' => zap_required_report([
                    [
                        'label' => 'Phone Number ID',
                        'value' => $phoneNumberId,
                        'preview' => $phoneNumberId,
                    ],
                    [
                        'label' => 'Access Token',
                        'value' => $accessToken,
                        'preview' => zap_token_preview($accessToken),
                    ],
                    [
                        'label' => 'Número de destino',
                        'value' => $recipientNumber,
                        'preview' => $recipientRaw !== $recipientNumber ? "{$recipientRaw} → {$recipientNumber}" : $recipientNumber,
                    ],
                ]),
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
                    'detail' => $curlError !== '' ? $curlError : 'Não foi possível ler a resposta da API.',
                ];
            } else {
                $decoded = json_decode($responseBody, true);
                $ok = $httpCode >= 200 && $httpCode < 300;
                $result = [
                    'ok' => $ok,
                    'title' => $ok ? 'Mensagem enviada' : 'Erro na API',
                    'detail' => is_array($decoded)
                        ? ($ok ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : zap_api_error_hint($decoded, $recipientNumber, $known['display_number']))
                        : $responseBody,
                    'http_code' => $httpCode,
                ];
            }
        }
    } elseif ($action === 'test_webhook') {
        if ($webhookUrl === '' || $verifyToken === '') {
            $missing = [];
            if ($webhookUrl === '') {
                $missing[] = 'Webhook URL';
            }
            if ($verifyToken === '') {
                $missing[] = 'Verify Token';
            }
            $result = [
                'ok' => false,
                'title' => 'Webhook incompleto',
                'detail' => "Campo faltando exatamente:\n- " . implode("\n- ", $missing),
            ];
        } else {
            $result = zap_test_webhook($webhookUrl, $verifyToken);
        }
    } elseif ($action === 'preview_payload') {
        $result = [
            'ok' => true,
            'title' => 'Dados prontos para cadastrar/testar',
            'detail' => json_encode([
                'meta_app' => [
                    'app_name' => $known['app_name'],
                    'app_id' => $appId,
                    'api_version' => $apiVersion,
                ],
                'whatsapp_business_account' => [
                    'waba_id' => $wabaId,
                    'phone_number_id' => $phoneNumberId,
                    'display_number' => $known['display_number'],
                ],
                'webhook' => [
                    'callback_url' => $webhookUrl,
                    'verify_token' => $verifyToken,
                    'subscribe_field' => 'messages',
                ],
                'send_message_endpoint' => zap_api_url($apiVersion, $phoneNumberId),
                'recipient_number' => $recipientNumber,
                'diagnostico_envio' => [
                    'phone_number_id' => $phoneNumberId !== '' ? 'OK' : 'FALTANDO',
                    'access_token' => $accessToken !== '' ? zap_token_preview($accessToken) : 'FALTANDO',
                    'recipient_number' => $recipientNumber !== '' ? 'OK: ' . $recipientNumber : 'FALTANDO',
                    'config_saved' => $configSaved ? 'SIM' : 'NAO',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }

    $defaults['api_version'] = $apiVersion;
    $defaults['app_id'] = $appId;
    $defaults['waba_id'] = $wabaId;
    $defaults['phone_number_id'] = $phoneNumberId;
    $defaults['access_token'] = $accessToken;
    $defaults['recipient_number'] = $recipientNumber;
    $defaults['verify_token'] = $verifyToken;
    $defaults['message_text'] = $messageText;
    $defaults['webhook_url'] = $webhookUrl;
}

$webhookUrl = $defaults['webhook_url'];
$sendEndpoint = zap_api_url($defaults['api_version'], $defaults['phone_number_id']);

?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zap API - WhatsApp Oficial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root{--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--zap:#22c55e;--zap-dark:#14532d;--card:#ffffff}
        body{background:radial-gradient(circle at top left,#dcfce7 0,#f8fafc 34%,#eef2ff 100%);min-height:100vh;color:var(--ink)}
        .shell{max-width:1180px}
        .hero{background:linear-gradient(135deg,#052e16,#0f766e 55%,#0f172a);color:#fff;border-radius:28px;box-shadow:0 24px 70px rgba(15,23,42,.18);overflow:hidden;position:relative}
        .hero:after{content:"";position:absolute;inset:auto -80px -120px auto;width:320px;height:320px;border-radius:999px;background:rgba(255,255,255,.09)}
        .badge-soft{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.24);color:#fff}
        .card-soft{border:1px solid rgba(226,232,240,.9);box-shadow:0 16px 45px rgba(15,23,42,.08);border-radius:24px;background:rgba(255,255,255,.92);backdrop-filter:blur(12px)}
        .mini-card{border:1px solid var(--line);border-radius:18px;background:#fff;padding:16px;height:100%}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.92em}
        .small-help{font-size:.9rem;color:var(--muted)}
        .form-control,.form-select{border-radius:14px;border-color:#dbe3ef;padding:.72rem .9rem}
        textarea.form-control{min-height:115px}
        .btn{border-radius:999px;padding:.72rem 1rem;font-weight:700}
        .btn-zap{background:var(--zap);border-color:var(--zap);color:#052e16}
        .btn-zap:hover{background:#16a34a;border-color:#16a34a;color:#fff}
        .copy-btn{border:0;background:#f1f5f9;border-radius:999px;padding:.32rem .65rem;font-size:.78rem;color:#334155}
        .copy-btn:hover{background:#e2e8f0}
        .step{display:flex;gap:12px;align-items:flex-start;padding:14px 0;border-bottom:1px solid #eef2f7}
        .step:last-child{border-bottom:0}
        .step-num{width:30px;height:30px;display:grid;place-items:center;border-radius:999px;background:#dcfce7;color:#14532d;font-weight:800;flex:0 0 auto}
        .status-dot{width:10px;height:10px;border-radius:999px;background:#22c55e;display:inline-block;margin-right:6px}
        pre.result{white-space:pre-wrap;margin:0;max-height:420px;overflow:auto}
        code.block{display:block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:12px;word-break:break-all;color:#0f172a}
        .danger-note{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:16px;padding:12px}
        .token-warning{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:14px;padding:10px 12px;font-size:.9rem}
        .token-ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#14532d;border-radius:14px;padding:10px 12px;font-size:.9rem}
    </style>
</head>
<body>
<main class="container shell py-4 py-md-5">
    <section class="hero p-4 p-md-5 mb-4">
        <div class="row align-items-center g-4 position-relative" style="z-index:1">
            <div class="col-lg-7">
                <span class="badge badge-soft rounded-pill mb-3">WhatsApp Cloud API • laboratório de guerra</span>
                <h1 class="display-6 fw-bold mb-3">Zap API Oficial</h1>
                <p class="lead mb-0 text-white-50">Uma tela limpa para achar os IDs certos, validar webhook e mandar mensagem de teste sem o CRM inteiro gritando junto.</p>
            </div>
            <div class="col-lg-5">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="mini-card bg-transparent border-light text-white">
                            <div class="small text-white-50">App</div>
                            <strong><?= zap_h($known['app_name']) ?></strong>
                            <div class="mono text-white-50 mt-1"><?= zap_h($defaults['app_id']) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="mini-card bg-transparent border-light text-white">
                            <div class="small text-white-50">Destino teste</div>
                            <strong>+55 11 94757-3311</strong>
                            <div class="mono text-white-50 mt-1"><?= zap_h($defaults['recipient_number']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if (is_array($result)): ?>
        <div class="alert <?= !empty($result['ok']) ? 'alert-success' : 'alert-danger' ?> card-soft mb-4">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <strong><?= zap_h((string)($result['title'] ?? 'Resultado')) ?></strong>
                <?php if (!empty($result['http_code'])): ?>
                    <span class="badge text-bg-secondary">HTTP <?= (int)$result['http_code'] ?></span>
                <?php endif; ?>
            </div>
            <div class="mt-3"><pre class="result mono"><?= zap_h((string)($result['detail'] ?? '')) ?></pre></div>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" class="row g-4">
        <div class="col-lg-8">
            <div class="card card-soft mb-4">
                <div class="card-body p-4">
                    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                        <div>
                            <h2 class="h4 mb-1">Dados principais</h2>
                            <p class="small-help mb-0">Já deixei preenchido com o conjunto mais provável. Todos os campos continuam editáveis porque a Meta adora fazer a gente duvidar da realidade.</p>
                        </div>
                        <span class="badge rounded-pill <?= $configSaved ? 'text-bg-success' : 'text-bg-warning' ?>"><span class="status-dot"></span><?= $configSaved ? 'Config salva' : 'Ainda não salvo' ?></span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">API version</label>
                            <input class="form-control mono" name="api_version" value="<?= zap_h($defaults['api_version']) ?>" placeholder="v23.0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">App ID</label>
                            <input class="form-control mono" name="app_id" value="<?= zap_h($defaults['app_id']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">WABA ID</label>
                            <input class="form-control mono" name="waba_id" value="<?= zap_h($defaults['waba_id']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone Number ID</label>
                            <input class="form-control mono" name="phone_number_id" value="<?= zap_h($defaults['phone_number_id']) ?>" placeholder="123456789012345">
                            <div class="small-help mt-1">Esse ID entra no endpoint de envio.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Verify Token</label>
                            <input class="form-control mono" name="verify_token" value="<?= zap_h($defaults['verify_token']) ?>" placeholder="token-de-verificacao">
                            <div class="small-help mt-1">Esse texto precisa bater com o webhook.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Webhook URL</label>
                            <div class="input-group">
                                <input class="form-control mono" id="webhookUrl" name="webhook_url" value="<?= zap_h($webhookUrl) ?>">
                                <button class="copy-btn" type="button" data-copy-target="webhookUrl">copiar</button>
                            </div>
                            <div class="small-help mt-1">Cadastre essa URL no painel da Meta como Callback URL.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Access Token</label>
                            <div class="input-group">
                                <input class="form-control mono" id="accessToken" name="access_token" type="password" value="<?= zap_h($defaults['access_token']) ?>" placeholder="Cole aqui o token novo da Meta" autocomplete="off" spellcheck="false">
                                <button class="copy-btn" type="button" id="toggleToken">mostrar</button>
                            </div>
                            <?php if ($defaults['access_token'] === ''): ?>
                                <div class="token-warning mt-2">Access Token está vazio nesta tela. Cole o token novo uma vez e clique em salvar ou enviar. Depois ele fica salvo localmente no servidor.</div>
                            <?php else: ?>
                                <div class="token-ok mt-2">Token salvo/carregado: <?= zap_h(zap_token_preview($defaults['access_token'])) ?></div>
                            <?php endif; ?>
                            <div class="small-help mt-1">O token é salvo em <span class="mono">storage/zap_api_config.local.json</span>, fora do GitHub.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-soft">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">Teste de envio</h2>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Número de destino</label>
                            <input class="form-control mono" name="recipient_number" value="<?= zap_h($defaults['recipient_number']) ?>" placeholder="5511999999999">
                            <div class="small-help mt-1">Use DDI + DDD + número. Seu particular ficou: <span class="mono">5511947573311</span>.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Endpoint gerado</label>
                            <div class="d-flex gap-2 align-items-start">
                                <code class="block mono flex-grow-1" id="sendEndpoint"><?= zap_h($sendEndpoint) ?></code>
                                <button class="copy-btn" type="button" data-copy-target="sendEndpoint">copiar</button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Mensagem</label>
                            <textarea class="form-control" name="message_text" rows="4"><?= zap_h($defaults['message_text']) ?></textarea>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button class="btn btn-zap" type="submit" name="action" value="send_test">Salvar e enviar mensagem de teste</button>
                            <button class="btn btn-outline-success" type="submit" name="action" value="test_webhook">Salvar e testar webhook</button>
                            <button class="btn btn-outline-secondary" type="submit" name="action" value="preview_payload">Salvar e ver dados prontos</button>
                            <button class="btn btn-outline-dark" type="submit" name="action" value="save_config">Só salvar dados</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-soft mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Ordem certa da bagunça</h2>
                    <div class="step">
                        <div class="step-num">1</div>
                        <div><strong>Gerar token novo</strong><div class="small-help">Cole aqui uma vez. A página salva localmente no servidor.</div></div>
                    </div>
                    <div class="step">
                        <div class="step-num">2</div>
                        <div><strong>Cadastrar webhook</strong><div class="small-help">Callback URL + Verify Token. Campo inscrito: <span class="mono">messages</span>.</div></div>
                    </div>
                    <div class="step">
                        <div class="step-num">3</div>
                        <div><strong>Testar webhook</strong><div class="small-help">Tem que responder exatamente o challenge. Essa parte já funcionou.</div></div>
                    </div>
                    <div class="step">
                        <div class="step-num">4</div>
                        <div><strong>Enviar mensagem</strong><div class="small-help">Agora usando seu particular como destino padrão.</div></div>
                    </div>
                </div>
            </div>

            <div class="card card-soft mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Dados conhecidos</h2>
                    <div class="small-help">App</div>
                    <code class="block mono mb-3"><?= zap_h($known['app_name']) ?> / <?= zap_h($known['app_id']) ?></code>
                    <div class="small-help">WABA provável</div>
                    <code class="block mono mb-3"><?= zap_h($known['waba_id']) ?></code>
                    <div class="small-help">Phone Number ID provável</div>
                    <code class="block mono mb-3"><?= zap_h($known['phone_number_id']) ?></code>
                    <div class="small-help">Número oficial provável</div>
                    <code class="block mono"><?= zap_h($known['display_number']) ?></code>
                </div>
            </div>

            <div class="danger-note mb-4">
                <strong>Erro #100 que apareceu</strong>
                <div class="mt-2 small">O dado mais suspeito era o destino: estava usando o próprio número oficial. Agora o padrão é seu particular. Se ainda der #100, provavelmente será janela de 24h/template ou Phone Number ID/token incompatível.</div>
            </div>

            <div class="danger-note mb-4">
                <strong>Plano B de teste</strong>
                <div class="mt-2 small">Se o número oficial não responder, teste o conjunto antigo/fake da Meta:</div>
                <div class="mono mt-2">WABA: <?= zap_h($legacy['waba_id']) ?><br>Phone ID: <?= zap_h($legacy['phone_number_id']) ?><br>Número: <?= zap_h($legacy['display_number']) ?></div>
            </div>

            <div class="card card-soft">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Erros mais prováveis</h2>
                    <ul class="small-help mb-0 ps-3">
                        <li>Token expirado ou sem permissão.</li>
                        <li>Phone Number ID de outro WABA.</li>
                        <li>Webhook em HTTP em vez de HTTPS.</li>
                        <li>Verify Token diferente no painel e no PHP.</li>
                        <li>Número de destino fora da janela de conversa ou sem template aprovado.</li>
                    </ul>
                </div>
            </div>
        </div>
    </form>
</main>
<script>
document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget);
        const text = target?.value || target?.textContent || '';
        if (!text.trim()) return;
        await navigator.clipboard.writeText(text.trim());
        const original = button.textContent;
        button.textContent = 'copiado';
        setTimeout(() => button.textContent = original, 1200);
    });
});

document.getElementById('toggleToken')?.addEventListener('click', () => {
    const input = document.getElementById('accessToken');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    document.getElementById('toggleToken').textContent = input.type === 'password' ? 'mostrar' : 'ocultar';
});
</script>
</body>
</html>
