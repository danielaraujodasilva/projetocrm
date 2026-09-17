<?php
/**
 * Receptor da ponte de rastreio de origem (WhatsApp Web nao-oficial -> CRM).
 *
 * POR QUE ESTE ARQUIVO EXISTE
 * O numero que recebe os anuncios (+55 11 99997-9785) NAO esta na API oficial.
 * A API oficial so entrega o pacote `referral` (origem do anuncio) quando o
 * numero que recebe e o numero conectado a ela. Como esse numero fica fora da
 * API por decisao do dono, a origem e lida no WhatsApp Web por uma ponte local
 * (C:\Users\server_spd\Documents\whatsapp-origin-bridge) e enviada para ca.
 *
 * O QUE ESTE ENDPOINT FAZ
 *   - Recebe {phone, origin, platform, greeting, ...} da ponte.
 *   - Grava a origem no lead da conversa quando existir, ou guarda como
 *     "pendencia de origem" para a conversa herdar quando chegar.
 *
 * O QUE ELE NAO FAZ
 *   - Nao envia mensagem. Nao responde cliente. Nao altera provider/numeros.
 *   - Nao toca na API oficial da Meta.
 *
 * SEGURANCA
 *   - Exige um token compartilhado (BRIDGE_ORIGIN_TOKEN) no header
 *     X-Bridge-Token ou no corpo. Sem token valido, responde 403.
 *   - O token fica em storage/origin_bridge.local.php (fora do versionamento).
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function origin_bridge_respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function origin_bridge_token(): string
{
    $localFile = APP_BASE_PATH . '/storage/origin_bridge.local.php';
    if (is_file($localFile)) {
        $cfg = require $localFile;
        if (is_array($cfg) && !empty($cfg['token'])) {
            return trim((string)$cfg['token']);
        }
    }
    return trim((string)(getenv('BRIDGE_ORIGIN_TOKEN') ?: ''));
}

function origin_bridge_log(array $event): void
{
    $dir = APP_BASE_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $event['logged_at'] = date('c');
    @file_put_contents(
        $dir . '/origin_bridge.log',
        json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// --- Somente POST ---------------------------------------------------------
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    origin_bridge_respond(405, ['ok' => false, 'error' => 'Use POST.']);
}

$rawBody = (string)file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    origin_bridge_log(['type' => 'invalid_json', 'body' => substr($rawBody, 0, 300)]);
    origin_bridge_respond(400, ['ok' => false, 'error' => 'JSON invalido.']);
}

// --- Autenticacao por token compartilhado --------------------------------
$expected = origin_bridge_token();
$provided = trim((string)(
    $_SERVER['HTTP_X_BRIDGE_TOKEN']
    ?? $payload['token']
    ?? ''
));
if ($expected === '') {
    origin_bridge_log(['type' => 'misconfigured', 'reason' => 'token ausente no servidor']);
    origin_bridge_respond(500, ['ok' => false, 'error' => 'Ponte sem token configurado no servidor.']);
}
if ($provided === '' || !hash_equals($expected, $provided)) {
    origin_bridge_log(['type' => 'unauthorized', 'provided_len' => strlen($provided)]);
    origin_bridge_respond(403, ['ok' => false, 'error' => 'Token invalido.']);
}

// --- Campos ---------------------------------------------------------------
$phone = preg_replace('/\D+/', '', (string)($payload['phone'] ?? $payload['numero'] ?? ''));
$origin = strtolower(trim((string)($payload['origin'] ?? '')));
$platform = strtolower(trim((string)($payload['platform'] ?? '')));
$greeting = trim((string)($payload['greeting'] ?? $payload['origin_greeting'] ?? ''));
$chatName = trim((string)($payload['chatName'] ?? $payload['origin_chat_name'] ?? ''));
$ctwaClid = trim((string)($payload['ctwaClid'] ?? ''));
$sourceUrl = trim((string)($payload['sourceUrl'] ?? ''));
$headline = trim((string)($payload['headline'] ?? ''));
$messageId = trim((string)($payload['messageId'] ?? ''));

if ($phone === '' || $origin === '') {
    origin_bridge_log(['type' => 'missing_fields', 'phone' => $phone, 'origin' => $origin]);
    origin_bridge_respond(422, ['ok' => false, 'error' => 'Informe phone e origin.']);
}

$allowed = ['meta', 'instagram', 'google', 'indicacao', 'porta', 'reincidente', 'outro'];
if (!in_array($origin, $allowed, true)) {
    $origin = 'outro';
}

$studio = null;
try {
    $studio = get_studio(1);
} catch (Throwable $e) {
    origin_bridge_respond(500, ['ok' => false, 'error' => 'Estudio indisponivel.']);
}

$result = [
    'ok' => true,
    'phone' => $phone,
    'origin' => $origin,
    'platform' => $platform,
    'action' => '',
];

try {
    $pdo = studio_db($studio);

    // 1) Procura lead pelo telefone (mesma tolerancia do ads_apply_origin_to_conversation).
    $variants = [$phone, '+' . $phone];
    if (strlen($phone) > 11 && str_starts_with($phone, '55')) {
        $variants[] = substr($phone, 2);
        $variants[] = '+55' . substr($phone, 2);
    }
    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $stmt = $pdo->prepare("SELECT id, source FROM leads WHERE phone IN ($placeholders) ORDER BY id DESC LIMIT 1");
    $stmt->execute($variants);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);

    $originNote = trim(implode(' · ', array_filter([
        'Origem: ' . strtoupper($origin),
        $platform !== '' ? 'plataforma: ' . $platform : '',
        $headline !== '' ? 'criativo: ' . $headline : '',
        $sourceUrl !== '' ? 'url: ' . $sourceUrl : '',
        $greeting !== '' ? 'saudacao: ' . $greeting : '',
    ])));

    if ($lead) {
        $current = strtolower(trim((string)($lead['source'] ?? '')));
        // Nao sobrescreve origem definida manualmente ou por outro caminho confiavel.
        $protected = ['manual', 'google_calendar'];
        if ($current !== '' && in_array($current, $protected, true)) {
            $result['action'] = 'preservado';
            $result['current_source'] = $current;
        } else {
            $pdo->prepare('UPDATE leads SET source = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$origin, (int)$lead['id']]);
            $result['action'] = 'lead_atualizado';
            $result['lead_id'] = (int)$lead['id'];
        }
    } else {
        // 2) Sem lead ainda: guarda a origem para a conversa herdar quando existir.
        $stmtConv = $pdo->prepare('SELECT id FROM whatsapp_conversations WHERE phone = ? ORDER BY id DESC LIMIT 1');
        $stmtConv->execute([$phone]);
        $convId = (int)$stmtConv->fetchColumn();

        if ($convId > 0) {
            $pdo->prepare('UPDATE whatsapp_conversations SET ai_last_status = ? WHERE id = ?')
                ->execute(['origem:' . $origin, $convId]);
            $result['action'] = 'conversa_marcada';
            $result['conversation_id'] = $convId;
        } else {
            $result['action'] = 'sem_lead_ainda';
        }

        // Cria o lead com a origem, para o dado nao se perder.
        try {
            $leadId = studio_save_lead($studio, [
                'name' => $chatName !== '' ? $chatName : 'Lead anúncio ' . $origin,
                'phone' => $phone,
                'interest' => $originNote !== '' ? $originNote : 'Contato via anúncio',
                'status' => 'novo',
                'pipeline_stage' => 'entrada',
                'lead_score' => 5,
                'estimated_value' => '0',
                'source' => $origin,
            ]);
            $result['action'] = 'lead_criado';
            $result['lead_id'] = $leadId;
        } catch (Throwable $eLead) {
            origin_bridge_log(['type' => 'create_lead_failed', 'phone' => $phone, 'error' => $eLead->getMessage()]);
        }
    }

    // 3) Registro em tabela propria, para auditoria e para a pagina de ROI.
    $pdo->exec('CREATE TABLE IF NOT EXISTS ads_origin_hits (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(32) NOT NULL,
        origin VARCHAR(32) NOT NULL,
        platform VARCHAR(32) NULL,
        greeting VARCHAR(255) NULL,
        chat_name VARCHAR(160) NULL,
        ctwa_clid VARCHAR(255) NULL,
        source_url VARCHAR(500) NULL,
        headline VARCHAR(255) NULL,
        message_id VARCHAR(160) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_phone (phone),
        KEY idx_origin_date (origin, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->prepare('INSERT INTO ads_origin_hits
        (phone, origin, platform, greeting, chat_name, ctwa_clid, source_url, headline, message_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $phone,
            $origin,
            $platform !== '' ? $platform : null,
            $greeting !== '' ? mb_substr($greeting, 0, 255) : null,
            $chatName !== '' ? mb_substr($chatName, 0, 160) : null,
            $ctwaClid !== '' ? mb_substr($ctwaClid, 0, 255) : null,
            $sourceUrl !== '' ? mb_substr($sourceUrl, 0, 500) : null,
            $headline !== '' ? mb_substr($headline, 0, 255) : null,
            $messageId !== '' ? mb_substr($messageId, 0, 160) : null,
        ]);
    $result['hit_id'] = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    origin_bridge_log(['type' => 'processing_error', 'phone' => $phone, 'error' => $e->getMessage()]);
    origin_bridge_respond(500, ['ok' => false, 'error' => 'Falha ao gravar origem.']);
}

origin_bridge_log(['type' => 'origin_received'] + $result);

origin_bridge_respond(200, $result);
