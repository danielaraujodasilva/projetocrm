<?php

declare(strict_types=1);

function studio_db_config(array $studio): array
{
    $platform = app_config('database');

    return [
        'host' => $studio['database_host'] ?: ($platform['host'] ?? 'localhost'),
        'port' => (int)($platform['port'] ?? 3306),
        'database' => (string)$studio['database_name'],
        'username' => $studio['database_user'] ?: ($platform['username'] ?? 'root'),
        'password' => (string)($studio['database_password'] ?? ($platform['password'] ?? '')),
        'charset' => (string)($platform['charset'] ?? 'utf8mb4'),
    ];
}

function studio_db(array $studio): PDO
{
    static $connections = [];
    $config = studio_db_config($studio);
    $key = implode('|', [$config['host'], $config['port'], $config['database'], $config['username']]);
    if (isset($connections[$key]) && $connections[$key] instanceof PDO) {
        return $connections[$key];
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );
    $connections[$key] = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    studio_ensure_whatsapp_schema($connections[$key]);

    return $connections[$key];
}

function studio_ensure_whatsapp_schema(PDO $pdo): void
{
    static $checked = [];
    $key = spl_object_id($pdo);
    if (isset($checked[$key])) {
        return;
    }
    $checked[$key] = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `whatsapp_conversations` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `lead_id` BIGINT UNSIGNED NULL,
            `customer_id` BIGINT UNSIGNED NULL,
            `phone` VARCHAR(40) NOT NULL,
            `name` VARCHAR(160) NULL,
            `remote_jid` VARCHAR(180) NULL,
            `attendance_mode` ENUM("human", "bot") NOT NULL DEFAULT "human",
            `needs_human` TINYINT(1) NOT NULL DEFAULT 0,
            `lead_score` TINYINT UNSIGNED NULL,
            `ai_last_status` VARCHAR(80) NULL,
            `ai_last_message` TEXT NULL,
            `ai_last_at` DATETIME NULL,
            `last_message_preview` VARCHAR(260) NULL,
            `last_message_direction` ENUM("in", "out") NULL,
            `last_message_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_whatsapp_conversations_phone` (`phone`),
            KEY `idx_whatsapp_conversations_last` (`last_message_at`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    foreach ([
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `remote_jid` VARCHAR(180) NULL AFTER `name`',
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `needs_human` TINYINT(1) NOT NULL DEFAULT 0 AFTER `attendance_mode`',
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `lead_score` TINYINT UNSIGNED NULL AFTER `needs_human`',
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `ai_last_status` VARCHAR(80) NULL AFTER `lead_score`',
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `ai_last_message` TEXT NULL AFTER `ai_last_status`',
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `ai_last_at` DATETIME NULL AFTER `ai_last_message`',
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `last_message_preview` VARCHAR(260) NULL AFTER `ai_last_at`',
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `last_message_direction` ENUM("in", "out") NULL AFTER `last_message_preview`',
        'ALTER TABLE `whatsapp_conversations` ADD COLUMN IF NOT EXISTS `last_message_at` DATETIME NULL AFTER `last_message_direction`',
        'ALTER TABLE `whatsapp_conversations` ADD INDEX IF NOT EXISTS `idx_whatsapp_conversations_last` (`last_message_at`, `updated_at`)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable) {
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` BIGINT UNSIGNED NOT NULL,
            `direction` ENUM("in", "out") NOT NULL,
            `sender_type` ENUM("customer", "human", "bot", "system") NOT NULL DEFAULT "customer",
            `body` MEDIUMTEXT NULL,
            `media_url` VARCHAR(500) NULL,
            `media_mime` VARCHAR(120) NULL,
            `media_file_name` VARCHAR(255) NULL,
            `media_file_path` VARCHAR(500) NULL,
            `message_type` VARCHAR(40) NOT NULL DEFAULT "texto",
            `message_id` VARCHAR(180) NULL,
            `remote_jid` VARCHAR(180) NULL,
            `from_me` TINYINT(1) NOT NULL DEFAULT 0,
            `status` VARCHAR(40) NULL,
            `sent_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            `transcricao` MEDIUMTEXT NULL,
            `transcript` MEDIUMTEXT NULL,
            `transcricao_erro` TEXT NULL,
            `transcript_error` TEXT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_whatsapp_messages_conversation` (`conversation_id`, `sent_at`),
            KEY `idx_whatsapp_messages_message_id` (`message_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    foreach ([
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `media_file_name` VARCHAR(255) NULL AFTER `media_mime`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `media_file_path` VARCHAR(500) NULL AFTER `media_file_name`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `message_type` VARCHAR(40) NOT NULL DEFAULT "texto" AFTER `media_file_path`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `remote_jid` VARCHAR(180) NULL AFTER `message_id`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `from_me` TINYINT(1) NOT NULL DEFAULT 0 AFTER `remote_jid`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `status` VARCHAR(40) NULL AFTER `from_me`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `transcricao` MEDIUMTEXT NULL AFTER `created_at`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `transcript` MEDIUMTEXT NULL AFTER `transcricao`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `transcricao_erro` TEXT NULL AFTER `transcript`',
        'ALTER TABLE `whatsapp_messages` ADD COLUMN IF NOT EXISTS `transcript_error` TEXT NULL AFTER `transcricao_erro`',
        'ALTER TABLE `whatsapp_messages` ADD INDEX IF NOT EXISTS `idx_whatsapp_messages_message_id` (`message_id`)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable) {
        }
    }
}

function studio_db_status_for(array $studio): array
{
    try {
        studio_db($studio)->query('SELECT 1');
        if (!studio_schema_ready($studio)) {
            return ['ok' => false, 'error' => 'Banco encontrado, mas as tabelas do CRM ainda nao foram instaladas.'];
        }
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function studio_schema_ready(array $studio): bool
{
    try {
        $config = studio_db_config($studio);
        $stmt = studio_db($studio)->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME IN ("studio_settings", "customers", "leads", "tattoo_artists", "pipeline_stages", "appointments", "expenses", "quick_replies", "whatsapp_conversations", "whatsapp_messages")'
        );
        $stmt->execute([$config['database']]);

        return (int)$stmt->fetchColumn() === 10;
    } catch (Throwable) {
        return false;
    }
}

function studio_install_database(array $studio): void
{
    $config = studio_db_config($studio);
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $config['host'],
        $config['port'],
        $config['charset']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    run_sql_script($pdo, studio_sql($studio));
    studio_event((int)$studio['id'], 'studio_database_installed', 'Banco isolado instalado ou atualizado.');
}

function run_sql_script(PDO $pdo, string $sql): void
{
    foreach (split_sql_statements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

function split_sql_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($char === "'" && !$inDouble) {
            if ($inSingle && $next === "'") {
                $current .= "''";
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
            $current .= $char;
            continue;
        }

        if ($char === '"' && !$inSingle) {
            $inDouble = !$inDouble;
            $current .= $char;
            continue;
        }

        if ($char === '\\' && ($inSingle || $inDouble) && $next !== '') {
            $current .= $char . $next;
            $i++;
            continue;
        }

        if ($char === ';' && !$inSingle && !$inDouble) {
            $statements[] = $current;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = $current;
    }

    return $statements;
}

function current_studio(): ?array
{
    $user = current_studio_user();
    if (!$user) {
        return null;
    }

    return get_studio((int)$user['studio_id']);
}

function require_studio(): array
{
    $studio = current_studio();
    if (!$studio) {
        redirect_to('studio_login');
    }

    return $studio;
}

function money_to_float(string $value): float
{
    $value = trim($value);
    if ($value === '') {
        return 0.0;
    }
    $value = preg_replace('/[^\d,.\-]/', '', str_replace(['R$', ' '], '', $value)) ?? '';
    if ($value === '') {
        return 0.0;
    }

    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/', $value)) {
        $value = str_replace('.', '', $value);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function phones_match(string $left, string $right): bool
{
    $left = normalize_phone($left);
    $right = normalize_phone($right);
    if ($left === '' || $right === '') {
        return false;
    }
    if ($left === $right) {
        return true;
    }

    $min = min(strlen($left), strlen($right));
    return $min >= 10 && substr($left, -$min) === substr($right, -$min);
}

function studio_session_key(array $studio): string
{
    $sessionKey = trim((string)($studio['whatsapp_session_key'] ?? ''));
    if ($sessionKey !== '') {
        return $sessionKey;
    }

    $sessionKey = studio_session_key_from_parts((int)$studio['id'], (string)$studio['slug']);
    db()->prepare('UPDATE studios SET whatsapp_session_key = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$sessionKey, (int)$studio['id']]);

    return $sessionKey;
}

function studio_whatsapp_service_url(array $studio): string
{
    $settings = studio_settings($studio);
    $url = trim((string)($settings['whatsapp_service_url'] ?? ''));
    return rtrim($url !== '' ? $url : 'http://localhost:3010', '/');
}

function studio_whatsapp_provider(array $studio): string
{
    $settings = studio_settings($studio);
    $provider = strtolower(trim((string)($settings['whatsapp_provider'] ?? 'baileys')));
    return in_array($provider, ['baileys', 'official'], true) ? $provider : 'baileys';
}

function studio_whatsapp_official_configured(array $studio): bool
{
    $settings = studio_settings($studio);
    $mode = strtolower(trim((string)($settings['whatsapp_official_mode'] ?? 'production')));
    if (!in_array($mode, ['production', 'sandbox'], true)) {
        $mode = 'production';
    }
    return trim((string)($settings['whatsapp_official_app_id'] ?? '')) !== ''
        && trim((string)($mode === 'sandbox' ? ($settings['whatsapp_official_test_phone_number_id'] ?? '') : ($settings['whatsapp_official_phone_number_id'] ?? ''))) !== ''
        && trim((string)($settings['whatsapp_official_access_token'] ?? '')) !== ''
        && trim((string)($settings['whatsapp_official_callback_url'] ?? '')) !== '';
}

function studio_mask_token_preview(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return 'vazio';
    }

    $length = strlen($token);
    $start = substr($token, 0, 8);
    $end = $length > 14 ? substr($token, -6) : '';

    return $end !== '' ? $start . '...' . $end . ' (' . $length . ' caracteres)' : $start . '... (' . $length . ' caracteres)';
}

function studio_whatsapp_zap_local_config(): array
{
    $path = APP_BASE_PATH . '/storage/zap_api_config.local.json';
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function crm_whatsapp_official_apply_defaults(array $studio): void
{
    $settings = studio_settings($studio);
    $updates = [];

    foreach ([
        'whatsapp_official_mode' => 'production',
        'whatsapp_official_api_version' => 'v23.0',
        'whatsapp_official_phone_number_id' => '1186818641175044',
    ] as $key => $default) {
        if (trim((string)($settings[$key] ?? '')) === '' && $default !== '') {
            $updates[$key] = $default;
        }
    }

    if (!$updates) {
        return;
    }

    $assignments = [];
    $params = [];
    foreach ($updates as $key => $value) {
        $assignments[] = $key . ' = ?';
        $params[] = $value;
    }
    $params[] = (int)$studio['id'];

    studio_db($studio)->prepare('UPDATE studio_settings SET ' . implode(', ', $assignments) . ', updated_at = NOW() WHERE id = ?')->execute($params);
}

function studio_whatsapp_official_status(array $studio): array
{
    $settings = studio_settings($studio);
    $provider = studio_whatsapp_provider($studio);
    $mode = strtolower(trim((string)($settings['whatsapp_official_mode'] ?? 'production')));
    if (!in_array($mode, ['production', 'sandbox'], true)) {
        $mode = 'production';
    }
    $checks = [
        'provider' => [
            'label' => 'Provedor selecionado',
            'ok' => $provider === 'official',
            'value' => $provider === 'official' ? 'API oficial' : 'Baileys',
        ],
        'mode' => [
            'label' => 'Ambiente',
            'ok' => true,
            'value' => $mode === 'sandbox' ? 'Sandbox / teste' : 'Produção',
        ],
        'app_id' => [
            'label' => 'App ID',
            'ok' => trim((string)($settings['whatsapp_official_app_id'] ?? '')) !== '',
            'value' => studio_meta_ads_mask_secret((string)($settings['whatsapp_official_app_id'] ?? '')),
        ],
        'phone_number_id' => [
            'label' => 'Phone Number ID',
            'ok' => trim((string)($mode === 'sandbox' ? ($settings['whatsapp_official_test_phone_number_id'] ?? '') : ($settings['whatsapp_official_phone_number_id'] ?? ''))) !== '',
            'value' => studio_meta_ads_mask_secret((string)($mode === 'sandbox' ? ($settings['whatsapp_official_test_phone_number_id'] ?? '') : ($settings['whatsapp_official_phone_number_id'] ?? ''))),
        ],
        'access_token' => [
            'label' => 'Access Token',
            'ok' => trim((string)($settings['whatsapp_official_access_token'] ?? '')) !== '',
            'value' => trim((string)($settings['whatsapp_official_access_token'] ?? '')) !== '' ? '••••••' . mb_substr(trim((string)($settings['whatsapp_official_access_token'] ?? '')), -6) : '',
        ],
        'verify_token' => [
            'label' => 'Webhook Verify Token',
            'ok' => trim((string)($settings['whatsapp_official_verify_token'] ?? '')) !== '',
            'value' => trim((string)($settings['whatsapp_official_verify_token'] ?? '')),
        ],
        'callback_url' => [
            'label' => 'Callback URL',
            'ok' => trim((string)($settings['whatsapp_official_callback_url'] ?? '')) !== '',
            'value' => trim((string)($settings['whatsapp_official_callback_url'] ?? '')),
        ],
        'waba_id' => [
            'label' => 'WABA ID',
            'ok' => trim((string)($mode === 'sandbox' ? ($settings['whatsapp_official_test_business_account_id'] ?? '') : ($settings['whatsapp_official_business_account_id'] ?? ''))) !== '',
            'value' => trim((string)($mode === 'sandbox' ? ($settings['whatsapp_official_test_business_account_id'] ?? '') : ($settings['whatsapp_official_business_account_id'] ?? ''))),
        ],
    ];

    $score = 0;
    foreach ($checks as $check) {
        if (!empty($check['ok'])) {
            $score++;
        }
    }

    return [
        'provider' => $provider,
        'ready' => $score >= count($checks),
        'score' => $score,
        'total' => count($checks),
        'checks' => $checks,
    ];
}

function studio_whatsapp_webhook_token(array $studio): string
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['whatsapp_webhook_token'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $token = bin2hex(random_bytes(32));
    studio_db($studio)->prepare('UPDATE studio_settings SET whatsapp_webhook_token = ?, updated_at = NOW() WHERE id = 1')
        ->execute([$token]);

    return $token;
}

function studio_whatsapp_webhook_url(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return 'http://localhost/projetocrm/api/whatsapp_webhook.php';
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/projetocrm/index.php'))), '/');
    if ($basePath === '' || $basePath === '.') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath . '/api/whatsapp_webhook.php';
}

function studio_whatsapp_request(array $studio, string $method, string $path, array $payload = [], int $timeout = 8): array
{
    $url = studio_whatsapp_service_url($studio) . $path;
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    $method = strtoupper($method);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return [
            'ok' => false,
            'error' => 'Servico WhatsApp indisponivel: ' . $error,
            'http_code' => $httpCode,
            'url' => $url,
        ];
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        return [
            'ok' => false,
            'error' => 'Servico WhatsApp retornou resposta invalida.',
            'http_code' => $httpCode,
            'raw' => mb_substr((string)$response, 0, 500),
            'url' => $url,
        ];
    }

    $json['http_code'] = $httpCode;
    return $json;
}

function studio_whatsapp_service_is_local(array $studio): bool
{
    $host = strtolower((string)(parse_url(studio_whatsapp_service_url($studio), PHP_URL_HOST) ?: ''));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function studio_expected_whatsapp_service_version(): string
{
    return '2026-05-15-force-restart';
}

function studio_shell_exec_available(): bool
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    return (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true))
        || (function_exists('popen') && !in_array('popen', $disabled, true));
}

function studio_whatsapp_log_paths(): array
{
    return [
        APP_BASE_PATH . '/services/whatsapp/whatsapp_service.log',
        APP_BASE_PATH . '/storage/logs/whatsapp_service.log',
    ];
}

function studio_append_whatsapp_service_log(string $message): bool
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    $written = false;

    foreach (studio_whatsapp_log_paths() as $path) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_dir($dir) && @file_put_contents($path, $line, FILE_APPEND) !== false) {
            $written = true;
        }
    }

    return $written;
}

function studio_whatsapp_start_local_service(array $studio): array
{
    if (!studio_whatsapp_service_is_local($studio)) {
        return ['ok' => false, 'error' => 'A URL do servico WhatsApp nao e local; inicie o servico manualmente neste endereco.'];
    }

    if (!studio_shell_exec_available()) {
        return ['ok' => false, 'error' => 'O PHP do servidor nao permite executar comandos para iniciar o servico WhatsApp.'];
    }

    $servicePath = APP_BASE_PATH . '/services/whatsapp';
    if (!is_dir($servicePath) || !is_file($servicePath . '/package.json')) {
        return ['ok' => false, 'error' => 'Pasta services/whatsapp nao encontrada no servidor.'];
    }

    $port = (string)(parse_url(studio_whatsapp_service_url($studio), PHP_URL_PORT) ?: 3010);
    $logDir = APP_BASE_PATH . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $logFile = $logDir . '/whatsapp_service.log';
    $pidFile = $servicePath . '/whatsapp_service.pid';
    $startOutput = '';
    $needsInstall = false;
    $health = studio_whatsapp_request($studio, 'GET', '/health', [], 1);

    if (studio_whatsapp_health_is_current($health)) {
        return [
            'ok' => true,
            'message' => 'Servico WhatsApp ja estava em execucao.',
            'health' => $health,
            'log_file' => $logFile,
        ];
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $startLines = [
            'cd /d "' . str_replace('"', '', $servicePath) . '"',
            'set "WHATSAPP_PORT=' . studio_windows_env_value($port) . '"',
        ];
        $startLines[] = studio_windows_port_guard_line(
            $port,
            studio_windows_whatsapp_start_cmd($servicePath, $logFile),
            $logFile
        );
        studio_windows_start_process(implode(' && ', $startLines));
        $startOutput = 'Inicio disparado em background.';
    } else {
        $startCommand = 'cd ' . escapeshellarg($servicePath)
            . ' && WHATSAPP_PORT=' . escapeshellarg($port)
            . ' nohup node server.js > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        $startOutput = trim((string)shell_exec($startCommand));
        if ($startOutput !== '') {
            file_put_contents($pidFile, $startOutput);
        }
    }

    $health = [];
    for ($attempt = 0; $attempt < 6; $attempt++) {
        usleep(700000);
        $health = studio_whatsapp_request($studio, 'GET', '/health', [], 2);
        if (!empty($health['ok'])) {
            return [
                'ok' => true,
                'message' => 'Servico WhatsApp iniciado automaticamente.',
                'start_output' => mb_substr($startOutput, 0, 500),
                'health' => $health,
                'log_file' => $logFile,
            ];
        }
    }

    $logTail = '';
    if (is_file($logFile)) {
        $logTail = mb_substr((string)file_get_contents($logFile), -1500);
    }

    return [
        'ok' => false,
        'pending' => false,
        'error' => 'Tentei iniciar o servico WhatsApp automaticamente, mas ele nao respondeu em ' . studio_whatsapp_service_url($studio) . '/health.',
        'start_output' => mb_substr($startOutput, 0, 500),
        'health_error' => (string)($health['error'] ?? ''),
        'log_tail' => $logTail,
        'log_file' => $logFile,
    ];
}

function studio_whatsapp_stop_local_service(array $studio): array
{
    if (!studio_whatsapp_service_is_local($studio)) {
        return ['ok' => false, 'error' => 'A URL do servico WhatsApp nao e local.'];
    }

    if (!studio_shell_exec_available()) {
        return ['ok' => false, 'error' => 'O PHP do servidor nao permite executar comandos para parar o servico WhatsApp.'];
    }

    $port = preg_replace('/\D+/', '', (string)(parse_url(studio_whatsapp_service_url($studio), PHP_URL_PORT) ?: 3010)) ?: '3010';
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'powershell -NoProfile -ExecutionPolicy Bypass -Command '
            . escapeshellarg('$pids = @(Get-NetTCPConnection -LocalPort ' . $port . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique); foreach ($procId in $pids) { Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue; Write-Output "Stopped PID $procId"; }');
        $output = trim((string)shell_exec($command));
    } else {
        $command = 'lsof -ti tcp:' . escapeshellarg($port) . ' | xargs -r kill 2>&1';
        $output = trim((string)shell_exec($command));
    }

    return ['ok' => true, 'output' => mb_substr($output, 0, 1000)];
}

function studio_delete_directory(string $target, string $allowedRoot): bool
{
    $root = realpath($allowedRoot);
    if ($root === false) {
        return false;
    }

    if (!is_dir($target)) {
        return true;
    }

    $realTarget = realpath($target);
    if ($realTarget === false || !str_starts_with($realTarget, $root . DIRECTORY_SEPARATOR)) {
        return false;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realTarget, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    return rmdir($realTarget);
}

function studio_reset_whatsapp_session_locally(array $studio): array
{
    if (!studio_whatsapp_service_is_local($studio)) {
        return ['ok' => false, 'error' => 'A URL do servico WhatsApp nao e local; nao posso limpar arquivos de sessao remotos.'];
    }

    if (!studio_shell_exec_available()) {
        return ['ok' => false, 'error' => 'O PHP do servidor nao permite executar comandos para limpar a sessao WhatsApp.'];
    }

    $servicePath = APP_BASE_PATH . '/services/whatsapp';
    if (!is_dir($servicePath) || !is_file($servicePath . '/package.json')) {
        return ['ok' => false, 'error' => 'Pasta services/whatsapp nao encontrada no servidor.'];
    }

    $sessionsRoot = $servicePath . '/sessions';
    if (!is_dir($sessionsRoot)) {
        mkdir($sessionsRoot, 0775, true);
    }

    $sessionKey = studio_whatsapp_safe_session_key(studio_session_key($studio));
    $sessionPath = $sessionsRoot . '/' . $sessionKey;
    $port = preg_replace('/\D+/', '', (string)(parse_url(studio_whatsapp_service_url($studio), PHP_URL_PORT) ?: 3010)) ?: '3010';
    $logDir = APP_BASE_PATH . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/whatsapp_service.log';
    $needsInstall = false;

    if (PHP_OS_FAMILY === 'Windows') {
        $resetLines = [
            "powershell -NoProfile -ExecutionPolicy Bypass -Command \"\$pids = @(Get-NetTCPConnection -LocalPort " . preg_replace('/\D+/', '', $port) . " -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique); foreach (\$procId in \$pids) { Stop-Process -Id \$procId -Force -ErrorAction SilentlyContinue; Write-Output ('Stopped PID ' + \$procId) }\" >> \"" . str_replace('"', '', $logFile) . "\" 2>&1",
            'rmdir /S /Q "' . str_replace('"', '', $sessionPath) . '" >> "' . str_replace('"', '', $logFile) . '" 2>&1',
            'cd /d "' . str_replace('"', '', $servicePath) . '"',
            'set "WHATSAPP_PORT=' . studio_windows_env_value($port) . '"',
        ];
        $resetLines[] = studio_windows_port_guard_line(
            $port,
            studio_windows_whatsapp_start_cmd($servicePath, $logFile),
            $logFile
        );
        studio_windows_start_process(implode(' && ', $resetLines));
    } else {
        $command = '(lsof -ti tcp:' . escapeshellarg($port) . ' | xargs -r kill; rm -rf ' . escapeshellarg($sessionPath)
            . '; cd ' . escapeshellarg($servicePath)
            . ' WHATSAPP_PORT=' . escapeshellarg($port)
            . ' nohup node server.js >> ' . escapeshellarg($logFile) . ' 2>&1 &) 2>&1';
        shell_exec($command);
    }

    return [
        'ok' => true,
        'pending' => true,
        'message' => 'Limpeza da sessao WhatsApp disparada em background.',
        'deleted_path' => $sessionPath,
        'log_file' => $logFile,
    ];
}

function studio_whatsapp_safe_session_key(string $value): string
{
    $key = strtolower((string)preg_replace('/[^a-z0-9_-]+/i', '-', $value));
    $key = trim($key, '-');
    return $key !== '' ? mb_substr($key, 0, 120) : 'studio-session';
}

function studio_windows_env_value(string $value): string
{
    return str_replace(['"', "\r", "\n"], '', $value);
}

function studio_windows_cmd_arg(string $value): string
{
    return '"' . str_replace('"', '', $value) . '"';
}

function studio_pomada_unit_price(array $studio): float
{
    $settings = studio_settings($studio);
    $value = $settings['pomada_unit_price'] ?? null;
    if ($value !== null && $value !== '') {
        return max(0.0, (float)$value);
    }

    $appValue = app_config('app')['pomada_unit_price'] ?? 100;
    return max(0.0, (float)$appValue);
}

function studio_windows_whatsapp_start_cmd(string $servicePath, string $logFile): string
{
    $nodeExe = trim((string)(getenv('NODE_EXE') ?: 'node'));

    return '"' 
        . str_replace('"', '', $nodeExe)
        . '" server.js >> "'
        . str_replace('"', '', $logFile)
        . '" 2>&1';
}

function studio_windows_port_guard_line(string $port, string $startCommand, string $logFile): string
{
    $portDigits = preg_replace('/\D+/', '', $port) ?: '3010';
    $escapedStart = str_replace("'", "''", $startCommand);
    $escapedLog = str_replace('"', '', $logFile);

    return "powershell -NoProfile -ExecutionPolicy Bypass -Command \"if (-not (Get-NetTCPConnection -LocalPort {$portDigits} -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1)) { cmd /c '{$escapedStart}' } else { Write-Output 'WhatsApp service already listening on port {$portDigits}' }\" >> \"{$escapedLog}\" 2>&1";
}

function studio_windows_json_string(array $payload): string
{
    return str_replace("'", "''", json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
}

function studio_whatsapp_ai_timeout(array $studio): int
{
    $settings = studio_settings($studio);
    $provider = (string)($settings['ai_provider'] ?? 'ollama');
    return $provider === 'ollama' ? 90 : 60;
}

function studio_queue_whatsapp_ai_reply(array $studio, array $conversation, array $newMessage): array
{
    if (!plan_allows('ai')) {
        try {
            studio_update_whatsapp_conversation($studio, [
                'conversation_id' => (int)$conversation['id'],
                'ai_last_status' => 'IA indisponivel no plano atual',
            ]);
        } catch (Throwable) {
        }
        return ['ok' => false, 'error' => 'Os recursos de IA estão disponíveis no plano Avançado.'];
    }

    $sessionKey = studio_session_key($studio);
    if ($sessionKey === '') {
        return ['ok' => false, 'error' => 'Sessao WhatsApp invalida.'];
    }

    try {
        studio_update_whatsapp_conversation($studio, [
            'conversation_id' => (int)$conversation['id'],
            'ai_last_status' => 'Analisando com IA...',
        ]);
    } catch (Throwable) {
    }

    $php = 'C:\\xampp\\php\\php.exe';
    $worker = APP_BASE_PATH . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'whatsapp_ai_worker.php';
    if (!is_file($php) || !is_file($worker)) {
        return ['ok' => false, 'error' => 'Worker de IA nao encontrado.'];
    }

    $logFile = APP_BASE_PATH . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'whatsapp' . DIRECTORY_SEPARATOR . 'whatsapp_service.log';
    $launcher = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'projetocrm_ai_worker_' . uniqid() . '.cmd';
    $lines = [
        '@echo off',
        'cd /d "' . str_replace('"', '', dirname($worker)) . '"',
        'echo [%date% %time%] [ai-worker-launcher] start >> "' . str_replace('"', '', $logFile) . '"',
        '"' . str_replace('"', '', $php) . '" "' . str_replace('"', '', $worker) . '" "' . str_replace('"', '', $sessionKey) . '" "' . (string)((int)$conversation['id']) . '" "' . str_replace('"', '', trim((string)($newMessage['message_id'] ?? $newMessage['messageId'] ?? ''))) . '" >> "' . str_replace('"', '', $logFile) . '" 2>&1',
    ];
    if (file_put_contents($launcher, implode("\r\n", $lines) . "\r\n") === false) {
        return ['ok' => false, 'error' => 'Nao consegui preparar o worker de IA.'];
    }

    $command = 'cmd /c start "" /B ' . studio_windows_cmd_arg($launcher) . ' > NUL 2>&1';
    if (function_exists('shell_exec')) {
        @shell_exec($command);
    } elseif (function_exists('popen')) {
        $handle = @popen($command, 'r');
        if (is_resource($handle)) {
            @pclose($handle);
        }
    } else {
        return ['ok' => false, 'error' => 'PHP nao consegue disparar o worker da IA neste servidor.'];
    }

    return ['ok' => true, 'queued' => true];
}

function studio_windows_start_process(string $command): void
{
    $escaped = escapeshellarg('Start-Process -WindowStyle Hidden -WorkingDirectory ' . studio_windows_cmd_arg(getcwd() ?: '.') . ' -FilePath powershell.exe -ArgumentList ' . studio_windows_cmd_arg('-NoProfile -ExecutionPolicy Bypass -Command ' . $command));
    $shell = 'powershell -NoProfile -ExecutionPolicy Bypass -Command ' . $escaped;

    if (function_exists('shell_exec')) {
        @shell_exec($shell . ' 2>&1');
        return;
    }

    if (function_exists('popen')) {
        $handle = @popen($shell, 'r');
        if (is_resource($handle)) {
            @pclose($handle);
        }
    }
}

function studio_write_windows_whatsapp_action_launcher(
    string $servicePath,
    string $logFile,
    string $port,
    string $action,
    string $sessionKey,
    array $payload = [],
    bool $install = false
): string {
    $safeAction = preg_replace('/[^a-z0-9_-]+/i', '-', $action) ?: 'action';
    $launcher = sys_get_temp_dir() . '/projetocrm_whatsapp_' . $safeAction . '_' . uniqid() . '.cmd';
    $baseUrl = 'http://localhost:' . studio_windows_env_value($port) . '/studios/' . rawurlencode($sessionKey);
    $payloadJson = studio_windows_json_string($payload);
    $log = str_replace('"', '', $logFile);
    $service = str_replace('"', '', $servicePath);

    $lines = [
        '@echo off',
        'echo. >> "' . $log . '"',
        'echo [%date% %time%] WhatsApp action: ' . $safeAction . ' >> "' . $log . '"',
        'cd /d "' . $service . '"',
        'set "WHATSAPP_PORT=' . studio_windows_env_value($port) . '"',
    ];

    if (in_array($action, ['start', 'restart', 'pairing_code'], true)) {
        if ($action === 'restart') {
            $lines[] = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "$pids = @(Get-NetTCPConnection -LocalPort ' . preg_replace('/\D+/', '', $port) . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique); foreach ($procId in $pids) { Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue; Write-Output \"Stopped PID $procId\" }" >> "' . $log . '" 2>&1';
        }
        $lines[] = studio_windows_port_guard_line($port, studio_windows_whatsapp_start_cmd($servicePath, $logFile), $logFile);
        $lines[] = 'timeout /T 8 /NOBREAK > nul';
    }

    if ($action === 'start') {
        $lines[] = "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Invoke-RestMethod -Method Post -Uri '" . $baseUrl . "/start' -ContentType 'application/json' -Body '" . $payloadJson . "' -TimeoutSec 25 | ConvertTo-Json -Compress\" >> \"" . $log . "\" 2>&1";
    } elseif ($action === 'pairing_code') {
        $lines[] = "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Invoke-RestMethod -Method Post -Uri '" . $baseUrl . "/pairing-code' -ContentType 'application/json' -Body '" . $payloadJson . "' -TimeoutSec 25 | ConvertTo-Json -Compress\" >> \"" . $log . "\" 2>&1";
    } elseif ($action === 'disconnect') {
        $lines[] = "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Invoke-RestMethod -Method Post -Uri '" . $baseUrl . "/logout' -ContentType 'application/json' -Body '{}' -TimeoutSec 8 | ConvertTo-Json -Compress\" >> \"" . $log . "\" 2>&1";
    } elseif ($action === 'restart') {
        $lines[] = 'echo [%date% %time%] Restart requested; service start command dispatched. >> "' . $log . '"';
    }

    if (file_put_contents($launcher, implode("\r\n", $lines) . "\r\n") === false) {
        throw new RuntimeException('Nao consegui gravar o iniciador da acao WhatsApp.');
    }

    return $launcher;
}

function studio_write_windows_whatsapp_reset_launcher(string $servicePath, string $sessionPath, string $logFile, string $port, bool $install): string
{
    $launcher = sys_get_temp_dir() . '/projetocrm_whatsapp_reset_' . uniqid() . '.cmd';
    $lines = [
        '@echo off',
        'echo [%date% %time%] Reset WhatsApp session started >> "' . str_replace('"', '', $logFile) . '"',
        'powershell -NoProfile -ExecutionPolicy Bypass -Command "$pids = @(Get-NetTCPConnection -LocalPort ' . preg_replace('/\D+/', '', $port) . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique); foreach ($procId in $pids) { Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue; Write-Output \"Stopped PID $procId\" }" >> "' . str_replace('"', '', $logFile) . '" 2>&1',
        'rmdir /S /Q "' . str_replace('"', '', $sessionPath) . '" >> "' . str_replace('"', '', $logFile) . '" 2>&1',
        'cd /d "' . str_replace('"', '', $servicePath) . '"',
        'set "WHATSAPP_PORT=' . studio_windows_env_value($port) . '"',
    ];

    $lines[] = studio_windows_port_guard_line($port, studio_windows_whatsapp_start_cmd($servicePath, $logFile), $logFile);
    if (file_put_contents($launcher, implode("\r\n", $lines) . "\r\n") === false) {
        throw new RuntimeException('Nao consegui gravar o iniciador de limpeza do WhatsApp.');
    }

    return $launcher;
}

function studio_whatsapp_background_context(array $studio): array
{
    $servicePath = APP_BASE_PATH . '/services/whatsapp';
    $logDir = APP_BASE_PATH . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    if (!is_dir($servicePath . '/sessions')) {
        mkdir($servicePath . '/sessions', 0775, true);
    }

    $sessionKey = studio_whatsapp_safe_session_key(studio_session_key($studio));

    return [
        'servicePath' => $servicePath,
        'logFile' => $servicePath . '/whatsapp_service.log',
        'port' => (string)(parse_url(studio_whatsapp_service_url($studio), PHP_URL_PORT) ?: 3010),
        'sessionKey' => $sessionKey,
        'sessionPath' => $servicePath . '/sessions/' . $sessionKey,
        'needsInstall' => false,
        'payload' => [
            'studioId' => (int)$studio['id'],
            'studioSlug' => (string)$studio['slug'],
            'studioName' => (string)$studio['name'],
            'webhookUrl' => studio_whatsapp_webhook_url(),
            'webhookToken' => studio_whatsapp_webhook_token($studio),
        ],
    ];
}

function studio_queue_whatsapp_action(array $studio, string $action, ?array $payload = null): array
{
    if (!in_array($action, ['start', 'disconnect', 'reset', 'restart', 'pairing_code'], true)) {
        return ['ok' => false, 'error' => 'Acao WhatsApp invalida.'];
    }

    if (!studio_whatsapp_service_is_local($studio)) {
        return ['ok' => false, 'error' => 'A URL do servico WhatsApp nao e local; nao posso disparar acao em background.'];
    }

    if (!studio_shell_exec_available()) {
        return ['ok' => false, 'error' => 'O PHP do servidor nao permite disparar comandos em background.'];
    }

    $ctx = studio_whatsapp_background_context($studio);
    if (!is_dir($ctx['servicePath']) || !is_file($ctx['servicePath'] . '/package.json')) {
        return ['ok' => false, 'error' => 'Pasta services/whatsapp nao encontrada no servidor.'];
    }

    studio_append_whatsapp_service_log('CRM queued WhatsApp action: ' . $action);

    if (PHP_OS_FAMILY === 'Windows') {
        $launcher = $action === 'reset'
            ? studio_write_windows_whatsapp_reset_launcher($ctx['servicePath'], $ctx['sessionPath'], $ctx['logFile'], $ctx['port'], $ctx['needsInstall'])
            : studio_write_windows_whatsapp_action_launcher(
                $ctx['servicePath'],
                $ctx['logFile'],
                $ctx['port'],
                $action,
                $ctx['sessionKey'],
                $payload ?? $ctx['payload'],
                $ctx['needsInstall']
            );

        $command = 'cmd /c start "" /B ' . studio_windows_cmd_arg($launcher) . ' > NUL 2>&1';
        if (function_exists('shell_exec')) {
            @shell_exec($command);
        } elseif (function_exists('popen')) {
            $handle = @popen($command, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
            }
        }

        return ['ok' => true, 'pending' => true, 'message' => 'Acao WhatsApp disparada em background.', 'sessionKey' => $ctx['sessionKey']];
    }

    return ['ok' => false, 'error' => 'Disparo em background ainda nao configurado para este sistema operacional.'];
}

function studio_wait_whatsapp_health(array $studio, int $seconds = 10, int $requestTimeout = 2): array
{
    $last = [];
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $last = studio_whatsapp_request($studio, 'GET', '/health', [], $requestTimeout);
        if (!empty($last['ok'])) {
            return $last;
        }
        usleep(750000);
    }

    return $last ?: ['ok' => false, 'error' => 'Servico WhatsApp nao respondeu dentro do tempo esperado.'];
}

function studio_whatsapp_health_is_current(array $health): bool
{
    return !empty($health['ok']) && (string)($health['version'] ?? '') === studio_expected_whatsapp_service_version();
}

function studio_launch_whatsapp_service(array $studio, array $ctx): array
{
    if (!studio_whatsapp_service_is_local($studio)) {
        return ['ok' => false, 'error' => 'A URL do servico WhatsApp nao e local; nao posso iniciar automaticamente.'];
    }

    if (!studio_shell_exec_available()) {
        return ['ok' => false, 'error' => 'O PHP do servidor nao permite executar comandos para iniciar o servico WhatsApp.'];
    }

    if (!is_dir($ctx['servicePath']) || !is_file($ctx['servicePath'] . '/package.json')) {
        return ['ok' => false, 'error' => 'Pasta services/whatsapp nao encontrada no servidor.'];
    }

    studio_append_whatsapp_service_log('CRM trying to launch WhatsApp service on port ' . $ctx['port']);

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'cd /d "' . str_replace('"', '', $ctx['servicePath']) . '" && set "WHATSAPP_PORT=' . studio_windows_env_value($ctx['port']) . '"';
        $command .= ' && ' . studio_windows_port_guard_line(
            $ctx['port'],
            studio_windows_whatsapp_start_cmd($ctx['servicePath'], $ctx['logFile']),
            $ctx['logFile']
        );
        studio_windows_start_process($command);
        return ['ok' => true, 'log_file' => $ctx['logFile']];
    }

    $command = 'cd ' . escapeshellarg($ctx['servicePath'])
        . ' && '
        . ' WHATSAPP_PORT=' . escapeshellarg($ctx['port'])
        . ' nohup node server.js >> ' . escapeshellarg($ctx['logFile']) . ' 2>&1 &';
    @shell_exec($command);

    return ['ok' => true, 'command' => $command, 'log_file' => $ctx['logFile']];
}

function studio_ensure_whatsapp_service(array $studio, array $ctx): array
{
    $health = studio_whatsapp_request($studio, 'GET', '/health', [], 1);
    if (studio_whatsapp_health_is_current($health)) {
        return ['ok' => true, 'health' => $health, 'restarted' => false];
    }

    if (!empty($health['ok'])) {
        studio_append_whatsapp_service_log(
            'CRM restarting stale WhatsApp service. Current version: '
            . (string)($health['version'] ?? 'sem-versao')
            . ' expected: '
            . studio_expected_whatsapp_service_version()
        );
        studio_whatsapp_stop_local_service($studio);
        usleep(1200000);
    }

    $launch = studio_launch_whatsapp_service($studio, $ctx);
    if (empty($launch['ok'])) {
        return $launch;
    }

    $health = studio_wait_whatsapp_health($studio, 30, 3);
    if (!studio_whatsapp_health_is_current($health)) {
        return [
            'ok' => false,
            'error' => 'O servico WhatsApp iniciou, mas esta em versao antiga ou nao respondeu corretamente.',
            'health_error' => (string)($health['error'] ?? ''),
            'current_version' => (string)($health['version'] ?? 'sem-versao'),
            'expected_version' => studio_expected_whatsapp_service_version(),
            'log_tail' => studio_whatsapp_service_log_tail(2500),
        ];
    }

    return ['ok' => true, 'health' => $health, 'restarted' => true];
}

function studio_restart_whatsapp_service(array $studio): array
{
    $ctx = studio_whatsapp_background_context($studio);
    studio_append_whatsapp_service_log('CRM forced WhatsApp service restart requested.');
    $result = studio_queue_whatsapp_action($studio, 'restart');
    return $result + ['message' => 'Reinicio do servico WhatsApp disparado em background.'];
}

function studio_whatsapp_service_status(array $studio, int $timeout = 2): array
{
    $sessionKey = studio_session_key($studio);
    $status = studio_whatsapp_request($studio, 'GET', '/studios/' . rawurlencode($sessionKey) . '/status', [], $timeout);
    $health = studio_whatsapp_request($studio, 'GET', '/health', [], 1);
    $status['service_health'] = $health;
    $status['service_version'] = (string)($health['version'] ?? '');
    $status['expected_service_version'] = studio_expected_whatsapp_service_version();
    $status['service_stale'] = !studio_whatsapp_health_is_current($health);

    if (!empty($status['ok']) && !empty($status['status'])) {
        $platformStatus = match ((string)$status['status']) {
            'connected' => 'connected',
            'waiting_qr' => 'waiting_qr',
            'starting', 'disconnected' => 'disconnected',
            default => 'error',
        };
        studio_update_whatsapp_platform_status($studio, $platformStatus);
    }

    return $status;
}

function studio_update_whatsapp_platform_status(array $studio, string $status): void
{
    if (!in_array($status, ['not_configured', 'waiting_qr', 'connected', 'disconnected', 'error'], true)) {
        $status = 'error';
    }
    db()->prepare('UPDATE studios SET whatsapp_status = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$status, (int)$studio['id']]);
}

function studio_start_whatsapp_session(array $studio): array
{
    if (!plan_allows('whatsapp')) {
        return ['ok' => false, 'error' => 'A integração com WhatsApp está disponível a partir do plano Profissional.'];
    }

    $limit = plan_limit('max_whatsapp_sessions');
    if ($limit > 0 && studio_whatsapp_session_count($studio) >= $limit && trim((string)($studio['whatsapp_session_key'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Seu plano atual permite até ' . $limit . ' sessões WhatsApp. Para conectar mais sessões, altere para um plano superior.'];
    }

    $ctx = studio_whatsapp_background_context($studio);
    studio_append_whatsapp_service_log('CRM start requested for session ' . $ctx['sessionKey']);

    $service = studio_ensure_whatsapp_service($studio, $ctx);
    if (empty($service['ok'])) {
        studio_update_whatsapp_platform_status($studio, 'error');
        return $service;
    }

    $payload = $ctx['payload'];
    $result = studio_whatsapp_request($studio, 'POST', '/studios/' . rawurlencode($ctx['sessionKey']) . '/start', $payload, 20);
    if (empty($result['ok'])) {
        $result = studio_queue_whatsapp_action($studio, 'start');
    }

    studio_update_whatsapp_platform_status($studio, empty($result['ok']) ? 'error' : 'waiting_qr');
    studio_event((int)$studio['id'], 'whatsapp_session_started', 'Sessao WhatsApp solicitada no servico multi-estudio.');
    return $result;
}

function studio_request_whatsapp_pairing_code(array $studio, string $phone): array
{
    if (!plan_allows('whatsapp')) {
        return ['ok' => false, 'error' => 'A integração com WhatsApp está disponível a partir do plano Profissional.'];
    }

    $limit = plan_limit('max_whatsapp_sessions');
    if ($limit > 0 && studio_whatsapp_session_count($studio) >= $limit && trim((string)($studio['whatsapp_session_key'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Seu plano atual permite até ' . $limit . ' sessões WhatsApp. Para conectar mais sessões, altere para um plano superior.'];
    }

    $phone = preg_replace('/\D+/', '', $phone) ?: '';
    if (strlen($phone) < 10) {
        return ['ok' => false, 'error' => 'Informe o telefone com DDI e DDD, somente numeros. Exemplo: 5521999999999.'];
    }

    $ctx = studio_whatsapp_background_context($studio);
    studio_append_whatsapp_service_log('CRM pairing code requested for session ' . $ctx['sessionKey'] . ' phone ' . $phone);

    $service = studio_ensure_whatsapp_service($studio, $ctx);
    if (empty($service['ok'])) {
        studio_update_whatsapp_platform_status($studio, 'error');
        return $service;
    }

    $payload = $ctx['payload'];
    $payload['numero'] = $phone;
    $result = studio_whatsapp_request($studio, 'POST', '/studios/' . rawurlencode($ctx['sessionKey']) . '/pairing-code', $payload, 20);
    if (empty($result['ok'])) {
        $result = studio_queue_whatsapp_action($studio, 'pairing_code', $payload);
    }

    studio_update_whatsapp_platform_status($studio, empty($result['ok']) ? 'error' : 'waiting_qr');
    studio_event((int)$studio['id'], 'whatsapp_pairing_code_requested', 'Codigo de pareamento WhatsApp solicitado pelo painel.');

    return $result;
}

function studio_disconnect_whatsapp_session(array $studio): array
{
    $ctx = studio_whatsapp_background_context($studio);
    studio_append_whatsapp_service_log('CRM disconnect requested for session ' . $ctx['sessionKey']);
    $result = studio_queue_whatsapp_action($studio, 'disconnect');
    studio_update_whatsapp_platform_status($studio, empty($result['ok']) ? 'error' : 'disconnected');
    studio_event((int)$studio['id'], 'whatsapp_session_disconnected', 'Sessao WhatsApp desconectada pelo painel.');

    return $result;
}

function studio_reset_whatsapp_session(array $studio): array
{
    $ctx = studio_whatsapp_background_context($studio);
    studio_append_whatsapp_service_log('CRM reset requested for session ' . $ctx['sessionKey']);

    $result = studio_queue_whatsapp_action($studio, 'reset');
    studio_update_whatsapp_platform_status($studio, empty($result['ok']) ? 'error' : 'disconnected');
    studio_event((int)$studio['id'], 'whatsapp_session_reset', 'Sessao WhatsApp limpa para gerar novo QR Code.');

    return $result;
}

function studio_whatsapp_service_log_tail(int $maxBytes = 5000): string
{
    $paths = studio_whatsapp_log_paths();

    $chunks = [];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $content = (string)file_get_contents($path);
        $chunks[] = basename(dirname($path)) . '/' . basename($path) . "\n" . mb_substr($content, -$maxBytes);
    }

    return implode("\n\n", $chunks);
}

function studio_stats(array $studio): array
{
    $pdo = studio_db($studio);
    $pomadaUnit = studio_pomada_unit_price($studio);
    $stats = [
        'leads' => 0,
        'open_leads' => 0,
        'customers' => 0,
        'appointments' => 0,
        'appointments_month' => 0,
        'month_signals' => 0.0,
        'human_conversations' => 0,
        'open_value' => 0.0,
        'month_revenue' => 0.0,
        'month_expenses' => 0.0,
        'whatsapp_conversations' => 0,
    ];
    $stats['leads'] = (int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    $stats['open_leads'] = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE status NOT IN ('perdido', 'fechado')")->fetchColumn();
    $stats['customers'] = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    $stats['appointments'] = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status NOT IN ('cancelado')")->fetchColumn();
    $stats['appointments_month'] = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE_FORMAT(appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND status NOT IN ('cancelado')")->fetchColumn();
    $stats['month_signals'] = (float)$pdo->query("SELECT COALESCE(SUM(deposit_value), 0) FROM appointments WHERE DATE_FORMAT(appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND status NOT IN ('cancelado')")->fetchColumn();
    $stats['human_conversations'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_conversations WHERE needs_human = 1")->fetchColumn();
    $stats['open_value'] = (float)$pdo->query("SELECT COALESCE(SUM(estimated_value), 0) FROM leads WHERE status NOT IN ('perdido', 'fechado')")->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0, COALESCE(value, 0) + (COALESCE(pomadas_quantity, 0) * ?) - COALESCE(deposit_value, 0))), 0) FROM appointments WHERE DATE_FORMAT(appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND status NOT IN ('cancelado')");
    $stmt->execute([$pomadaUnit]);
    $stats['month_revenue'] = (float)$stmt->fetchColumn();
    $stats['month_expenses'] = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
    $stats['whatsapp_conversations'] = (int)$pdo->query('SELECT COUNT(*) FROM whatsapp_conversations')->fetchColumn();

    return $stats;
}

function studio_list_pipeline_stages(array $studio): array
{
    return studio_db($studio)->query('SELECT * FROM pipeline_stages WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll() ?: [];
}

function studio_recent_leads(array $studio, int $limit = 8): array
{
    $stmt = studio_db($studio)->prepare(
        "SELECT l.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email, c.instagram AS customer_instagram
         FROM leads l
         LEFT JOIN customers c ON c.id = l.customer_id
         ORDER BY l.updated_at DESC, l.id DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function studio_upcoming_appointments(array $studio, int $limit = 8): array
{
    $stmt = studio_db($studio)->prepare(
        "SELECT a.*, COALESCE(c.name, a.title) AS customer_name, ta.name AS artist_name, ta.color AS artist_color,
                c.allergies AS customer_allergies, c.medications AS customer_medications, c.health_conditions AS customer_health_conditions, c.skin_conditions AS customer_skin_conditions,
                c.keloid_history AS customer_keloid_history, c.anticoagulants AS customer_anticoagulants, c.diabetes AS customer_diabetes, c.healing_issues AS customer_healing_issues, c.pregnant_or_breastfeeding AS customer_pregnant_or_breastfeeding
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN leads l ON l.id = a.lead_id
         LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
         WHERE a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelado')
         ORDER BY a.appointment_date ASC, a.start_time ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function studio_find_appointment(array $studio, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = studio_db($studio)->prepare(
        "SELECT a.*, COALESCE(c.name, a.title) AS customer_name, l.name AS lead_name, ta.name AS artist_name, ta.color AS artist_color,
                c.allergies AS customer_allergies, c.medications AS customer_medications, c.health_conditions AS customer_health_conditions, c.skin_conditions AS customer_skin_conditions,
                c.keloid_history AS customer_keloid_history, c.anticoagulants AS customer_anticoagulants, c.diabetes AS customer_diabetes, c.healing_issues AS customer_healing_issues, c.pregnant_or_breastfeeding AS customer_pregnant_or_breastfeeding
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN leads l ON l.id = a.lead_id
         LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
         WHERE a.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $appointment = $stmt->fetch();

    return is_array($appointment) ? $appointment : null;
}

function studio_delete_appointment(array $studio, int $id): void
{
    if ($id <= 0) {
        return;
    }

    $pdo = studio_db($studio);
    $stmt = $pdo->prepare('DELETE FROM appointments WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
}

function studio_list_customers(array $studio, int $limit = 180): array
{
    $stmt = studio_db($studio)->prepare(
        "SELECT c.*, COUNT(DISTINCT a.id) AS appointment_count, MAX(a.appointment_date) AS last_appointment_date, MAX(wc.last_message_at) AS last_message_at
         FROM customers c
         LEFT JOIN appointments a ON a.customer_id = c.id AND a.status NOT IN ('cancelado')
         LEFT JOIN whatsapp_conversations wc ON wc.customer_id = c.id
         GROUP BY c.id
         ORDER BY c.updated_at DESC, c.id DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function studio_find_customer(array $studio, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = studio_db($studio)->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $customer = $stmt->fetch();

    return is_array($customer) ? $customer : null;
}

function studio_customer_health_alerts(array $customer): array
{
    $fields = [
        'allergies' => 'Alergia',
        'medications' => 'Medicamento importante',
        'health_conditions' => 'Condição de saúde',
        'skin_conditions' => 'Condição de pele',
        'keloid_history' => 'Histórico de queloide',
        'anticoagulants' => 'Uso de anticoagulante',
        'diabetes' => 'Diabetes',
        'healing_issues' => 'Cicatrização',
        'pregnant_or_breastfeeding' => 'Gestação/amamentação',
    ];

    $alerts = [];
    foreach ($fields as $field => $label) {
        $value = trim((string)($customer[$field] ?? ''));
        if ($value === '') {
            continue;
        }
        $normalized = mb_strtolower($value, 'UTF-8');
        if (in_array($normalized, ['não', 'nao', 'não sei', 'nao sei', 'no', 'n/a', 'na'], true)) {
            continue;
        }
        $alerts[] = [
            'field' => $field,
            'label' => $label,
            'value' => $value,
        ];
    }

    return $alerts;
}

function studio_appointment_health_alerts_from_row(array $appointment): array
{
    $fields = [
        'allergies' => 'Alergia',
        'medications' => 'Medicamento importante',
        'health_conditions' => 'Condição de saúde',
        'skin_conditions' => 'Condição de pele',
        'keloid_history' => 'Histórico de queloide',
        'anticoagulants' => 'Uso de anticoagulante',
        'diabetes' => 'Diabetes',
        'healing_issues' => 'Cicatrização',
        'pregnant_or_breastfeeding' => 'Gestação/amamentação',
    ];

    $alerts = [];
    foreach ($fields as $field => $label) {
        $value = '';
        foreach (['customer_', 'lead_'] as $prefix) {
            $candidate = trim((string)($appointment[$prefix . $field] ?? ''));
            if ($candidate !== '') {
                $value = $candidate;
                break;
            }
        }
        if ($value === '') {
            continue;
        }
        $normalized = mb_strtolower($value, 'UTF-8');
        if (in_array($normalized, ['não', 'nao', 'não sei', 'nao sei', 'no', 'n/a', 'na'], true)) {
            continue;
        }
        $alerts[] = [
            'field' => $field,
            'label' => $label,
            'detail' => $value === 'Sim' ? 'Confirmado no cadastro' : $value,
        ];
    }

    return $alerts;
}

function studio_customer_activity(array $studio, int $customerId): array
{
    $pdo = studio_db($studio);
    $leadStmt = $pdo->prepare('SELECT * FROM leads WHERE customer_id = ? ORDER BY updated_at DESC, id DESC LIMIT 40');
    $leadStmt->execute([$customerId]);

    $appointmentStmt = $pdo->prepare(
        "SELECT a.*, c.name AS customer_name, l.name AS lead_name, ta.name AS artist_name, ta.color AS artist_color,
                c.allergies AS customer_allergies, c.medications AS customer_medications, c.health_conditions AS customer_health_conditions, c.skin_conditions AS customer_skin_conditions,
                c.keloid_history AS customer_keloid_history, c.anticoagulants AS customer_anticoagulants, c.diabetes AS customer_diabetes, c.healing_issues AS customer_healing_issues, c.pregnant_or_breastfeeding AS customer_pregnant_or_breastfeeding
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN leads l ON l.id = a.lead_id
         LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
         WHERE a.customer_id = ?
         ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
         LIMIT 40"
    );
    $appointmentStmt->execute([$customerId]);

    $conversationStmt = $pdo->prepare(
        "SELECT wc.*, COUNT(wm.id) AS message_count, COALESCE(wc.last_message_at, MAX(wm.sent_at)) AS message_last_at
         FROM whatsapp_conversations wc
         LEFT JOIN whatsapp_messages wm ON wm.conversation_id = wc.id
         WHERE wc.customer_id = ?
         GROUP BY wc.id
         ORDER BY COALESCE(wc.last_message_at, MAX(wm.sent_at), wc.updated_at) DESC
         LIMIT 20"
    );
    $conversationStmt->execute([$customerId]);

    return [
        'leads' => $leadStmt->fetchAll() ?: [],
        'appointments' => $appointmentStmt->fetchAll() ?: [],
        'conversations' => $conversationStmt->fetchAll() ?: [],
    ];
}

function studio_list_artists(array $studio, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM tattoo_artists';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, name ASC, id ASC';

    return studio_db($studio)->query($sql)->fetchAll() ?: [];
}

function studio_save_artist(array $studio, array $data): int
{
    $pdo = studio_db($studio);
    $id = (int)($data['id'] ?? 0);
    $values = [
        trim((string)($data['name'] ?? '')),
        trim((string)($data['specialty'] ?? '')),
        trim((string)($data['color'] ?? '#1f6f78')) ?: '#1f6f78',
        !empty($data['is_active']) ? 1 : 0,
    ];

    if ($values[0] === '') {
        throw new RuntimeException('Informe o nome do tatuador.');
    }

    if ($id <= 0) {
        $limit = plan_limit('max_tattooers');
        if ($limit > 0 && studio_artist_count($studio) >= $limit) {
            throw new RuntimeException('Seu plano atual permite até ' . $limit . ' tatuadores. Para adicionar mais tatuadores, altere para um plano superior.');
        }
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE tattoo_artists SET name = ?, specialty = ?, color = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([...$values, $id]);
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO tattoo_artists (name, specialty, color, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function studio_list_leads(array $studio, array $filters = [], int $limit = 180): array
{
    $where = [];
    $params = [];
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR interest LIKE ? OR source LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $source = trim((string)($filters['source'] ?? ''));
    if ($source !== '') {
        $where[] = 'source = ?';
        $params[] = $source;
    }
    $minScore = (int)($filters['min_score'] ?? 0);
    if ($minScore > 0) {
        $where[] = 'COALESCE(lead_score, 0) >= ?';
        $params[] = min(10, $minScore);
    }

    $sql = 'SELECT * FROM leads';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT ?';

    $stmt = studio_db($studio)->prepare($sql);
    foreach ($params as $index => $param) {
        $stmt->bindValue($index + 1, $param);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function studio_find_lead(array $studio, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = studio_db($studio)->prepare(
        'SELECT l.*, c.name AS customer_name, c.email AS customer_email, c.instagram AS customer_instagram, c.notes AS customer_notes
         FROM leads l
         LEFT JOIN customers c ON c.id = l.customer_id
         WHERE l.id = ?
         LIMIT 1'
    );
    $stmt->execute([$id]);
    $lead = $stmt->fetch();

    return is_array($lead) ? $lead : null;
}

function studio_pipeline_board(array $studio, array $filters = []): array
{
    $stages = studio_list_pipeline_stages($studio);
    $board = [];
    foreach ($stages as $stage) {
        $board[(string)$stage['name']] = [
            'stage' => $stage,
            'leads' => [],
            'total_value' => 0.0,
        ];
    }

    $leads = studio_list_leads($studio, $filters);
    foreach ($leads as $lead) {
        $stageName = (string)($lead['pipeline_stage'] ?: 'entrada');
        if (!isset($board[$stageName])) {
            $board[$stageName] = [
                'stage' => ['name' => $stageName, 'color' => '#667085', 'sort_order' => 999],
                'leads' => [],
                'total_value' => 0.0,
            ];
        }
        $board[$stageName]['leads'][] = $lead;
        $board[$stageName]['total_value'] += (float)($lead['estimated_value'] ?? 0);
    }

    foreach ($board as &$column) {
        usort($column['leads'], static function (array $left, array $right): int {
            $today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
            $scoreLeft = (int)($left['lead_score'] ?? 0);
            $scoreRight = (int)($right['lead_score'] ?? 0);
            $leftUpdated = (string)($left['updated_at'] ?? $left['created_at'] ?? '');
            $rightUpdated = (string)($right['updated_at'] ?? $right['created_at'] ?? '');
            $leftStale = false;
            $rightStale = false;
            if ($leftUpdated !== '') {
                try {
                    $leftStale = new DateTimeImmutable($leftUpdated, new DateTimeZone('America/Sao_Paulo')) < $today->modify('-24 hours');
                } catch (Throwable) {
                    $leftStale = false;
                }
            }
            if ($rightUpdated !== '') {
                try {
                    $rightStale = new DateTimeImmutable($rightUpdated, new DateTimeZone('America/Sao_Paulo')) < $today->modify('-24 hours');
                } catch (Throwable) {
                    $rightStale = false;
                }
            }
            $leftPriority = in_array((string)($left['status'] ?? ''), ['pre_agendado', 'agendado'], true) ? 1 : 0;
            $rightPriority = in_array((string)($right['status'] ?? ''), ['pre_agendado', 'agendado'], true) ? 1 : 0;
            if ($leftPriority !== $rightPriority) {
                return $rightPriority <=> $leftPriority;
            }
            if ($leftStale !== $rightStale) {
                return ($rightStale <=> $leftStale);
            }
            if ($scoreLeft !== $scoreRight) {
                return $scoreRight <=> $scoreLeft;
            }
            if ($leftUpdated !== $rightUpdated) {
                return strcmp($rightUpdated, $leftUpdated);
            }
            return (float)($right['estimated_value'] ?? 0) <=> (float)($left['estimated_value'] ?? 0);
        });
        $column['total_count'] = count($column['leads']);
    }
    unset($column);

    uasort($board, static fn(array $a, array $b): int => ((int)($a['stage']['sort_order'] ?? 999)) <=> ((int)($b['stage']['sort_order'] ?? 999)));

    return $board;
}

function studio_update_lead_stage(array $studio, int $leadId, string $stage, ?string $status = null): void
{
    $stage = trim($stage);
    if ($leadId <= 0 || $stage === '') {
        throw new RuntimeException('Lead ou etapa invalida.');
    }

    $allowedStages = array_map(static fn(array $row): string => (string)$row['name'], studio_list_pipeline_stages($studio));
    if (!in_array($stage, $allowedStages, true)) {
        throw new RuntimeException('Etapa de funil invalida.');
    }

    $allowedStatus = array_keys(lead_status_options());
    $status = $status !== null ? trim($status) : '';
    if ($status !== '' && !in_array($status, $allowedStatus, true)) {
        throw new RuntimeException('Status de lead invalido.');
    }

    if ($status !== '') {
        $stmt = studio_db($studio)->prepare('UPDATE leads SET pipeline_stage = ?, status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$stage, $status, $leadId]);
        return;
    }

    $stmt = studio_db($studio)->prepare('UPDATE leads SET pipeline_stage = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$stage, $leadId]);
}

function studio_lead_activity(array $studio, int $leadId): array
{
    $pdo = studio_db($studio);

    $appointmentsStmt = $pdo->prepare(
        "SELECT a.*, c.name AS customer_name, ta.name AS artist_name, ta.color AS artist_color
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
         WHERE a.lead_id = ?
         ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
         LIMIT 20"
    );
    $appointmentsStmt->execute([$leadId]);

    $conversationStmt = $pdo->prepare(
        "SELECT wc.*, COUNT(wm.id) AS message_count, COALESCE(wc.last_message_at, MAX(wm.sent_at)) AS message_last_at
         FROM whatsapp_conversations wc
         LEFT JOIN whatsapp_messages wm ON wm.conversation_id = wc.id
         WHERE wc.lead_id = ?
         GROUP BY wc.id
         ORDER BY COALESCE(wc.last_message_at, MAX(wm.sent_at), wc.updated_at) DESC
         LIMIT 10"
    );
    $conversationStmt->execute([$leadId]);

    return [
        'appointments' => $appointmentsStmt->fetchAll() ?: [],
        'conversations' => $conversationStmt->fetchAll() ?: [],
    ];
}

function studio_list_appointments(array $studio): array
{
    $stmt = studio_db($studio)->query(
        "SELECT a.*, c.name AS customer_name, l.name AS lead_name, ta.name AS artist_name, ta.color AS artist_color,
                c.allergies AS customer_allergies, c.medications AS customer_medications, c.health_conditions AS customer_health_conditions, c.skin_conditions AS customer_skin_conditions,
                c.keloid_history AS customer_keloid_history, c.anticoagulants AS customer_anticoagulants, c.diabetes AS customer_diabetes, c.healing_issues AS customer_healing_issues, c.pregnant_or_breastfeeding AS customer_pregnant_or_breastfeeding,
                c.allergies AS customer_allergies, c.medications AS customer_medications, c.health_conditions AS customer_health_conditions, c.skin_conditions AS customer_skin_conditions,
                c.keloid_history AS customer_keloid_history, c.anticoagulants AS customer_anticoagulants, c.diabetes AS customer_diabetes, c.healing_issues AS customer_healing_issues, c.pregnant_or_breastfeeding AS customer_pregnant_or_breastfeeding
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN leads l ON l.id = a.lead_id
         LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
         ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
         LIMIT 120"
    );

    return $stmt->fetchAll() ?: [];
}

function studio_calendar_appointments(array $studio, string $startDate, string $endDate): array
{
    $stmt = studio_db($studio)->prepare(
        "SELECT a.*, c.name AS customer_name, l.name AS lead_name, ta.name AS artist_name, ta.color AS artist_color,
                c.allergies AS customer_allergies, c.medications AS customer_medications, c.health_conditions AS customer_health_conditions, c.skin_conditions AS customer_skin_conditions,
                c.keloid_history AS customer_keloid_history, c.anticoagulants AS customer_anticoagulants, c.diabetes AS customer_diabetes, c.healing_issues AS customer_healing_issues, c.pregnant_or_breastfeeding AS customer_pregnant_or_breastfeeding
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN leads l ON l.id = a.lead_id
         LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
         WHERE a.appointment_date BETWEEN ? AND ?
         ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC"
    );
    $stmt->execute([$startDate, $endDate]);

    return $stmt->fetchAll() ?: [];
}

function studio_finance_summary(array $studio): array
{
    $pdo = studio_db($studio);
    $pomadaUnit = studio_pomada_unit_price($studio);
    $summary = [
        'appointments_month' => 0.0,
        'expenses_month' => 0.0,
        'expenses_total' => 0.0,
        'balance_month' => 0.0,
        'by_category' => [],
    ];
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(GREATEST(0, COALESCE(value, 0) + (COALESCE(pomadas_quantity, 0) * ?) - COALESCE(deposit_value, 0))), 0)
         FROM appointments
         WHERE DATE_FORMAT(appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
           AND status NOT IN ('cancelado')"
    );
    $stmt->execute([$pomadaUnit]);
    $summary['appointments_month'] = (float)$stmt->fetchColumn();
    $summary['expenses_month'] = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
    $summary['expenses_total'] = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses')->fetchColumn();
    $summary['balance_month'] = $summary['appointments_month'] - $summary['expenses_month'];
    $summary['by_category'] = $pdo->query(
        "SELECT category, COUNT(*) AS qtd, COALESCE(SUM(amount), 0) AS total
         FROM expenses
         GROUP BY category
         ORDER BY total DESC, category ASC
         LIMIT 12"
    )->fetchAll() ?: [];

    return $summary;
}

function studio_list_expenses(array $studio): array
{
    return studio_db($studio)->query('SELECT * FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 120')->fetchAll() ?: [];
}

function studio_save_expense(array $studio, array $data): int
{
    $pdo = studio_db($studio);
    $id = (int)($data['id'] ?? 0);
    $values = [
        trim((string)($data['category'] ?? 'Geral')) ?: 'Geral',
        trim((string)($data['description'] ?? '')),
        money_to_float((string)($data['amount'] ?? '0')),
        trim((string)($data['expense_date'] ?? date('Y-m-d'))),
        trim((string)($data['payment_method'] ?? '')),
        trim((string)($data['notes'] ?? '')),
    ];

    if ($values[1] === '') {
        throw new RuntimeException('Informe a descricao da despesa.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE expenses SET category = ?, description = ?, amount = ?, expense_date = ?, payment_method = ?, notes = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([...$values, $id]);
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO expenses (category, description, amount, expense_date, payment_method, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function studio_list_quick_replies(array $studio): array
{
    return studio_db($studio)->query('SELECT * FROM quick_replies ORDER BY is_active DESC, category ASC, title ASC LIMIT 120')->fetchAll() ?: [];
}

function studio_save_quick_reply(array $studio, array $data): int
{
    $pdo = studio_db($studio);
    $id = (int)($data['id'] ?? 0);
    $shortcut = trim((string)($data['shortcut'] ?? ''));
    $values = [
        trim((string)($data['title'] ?? '')),
        $shortcut !== '' ? $shortcut : null,
        trim((string)($data['category'] ?? 'Geral')) ?: 'Geral',
        trim((string)($data['body'] ?? '')),
        !empty($data['is_active']) ? 1 : 0,
    ];

    if ($values[0] === '' || $values[3] === '') {
        throw new RuntimeException('Informe titulo e texto da resposta rapida.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE quick_replies SET title = ?, shortcut = ?, category = ?, body = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([...$values, $id]);
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO quick_replies (title, shortcut, category, body, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function studio_whatsapp_summary(array $studio): array
{
    $pdo = studio_db($studio);
    $row = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN attendance_mode = 'bot' THEN 1 ELSE 0 END) AS bot,
            SUM(CASE WHEN attendance_mode = 'human' THEN 1 ELSE 0 END) AS human,
            SUM(CASE WHEN ai_last_status IS NOT NULL AND ai_last_status <> '' THEN 1 ELSE 0 END) AS analyzed,
            SUM(CASE WHEN needs_human = 1 THEN 1 ELSE 0 END) AS needs_human,
            ROUND(AVG(NULLIF(lead_score, 0)), 1) AS avg_score
         FROM whatsapp_conversations"
    )->fetch() ?: [];

    return [
        'total' => (int)($row['total'] ?? 0),
        'bot' => (int)($row['bot'] ?? 0),
        'human' => (int)($row['human'] ?? 0),
        'analyzed' => (int)($row['analyzed'] ?? 0),
        'needs_human' => (int)($row['needs_human'] ?? 0),
        'avg_score' => (float)($row['avg_score'] ?? 0),
    ];
}

function studio_list_whatsapp_conversations(array $studio, array $filters = [], int $limit = 160): array
{
    $where = [];
    $params = [];
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(wc.phone LIKE ? OR wc.name LIKE ? OR wc.last_message_preview LIKE ? OR c.name LIKE ? OR l.name LIKE ? OR l.interest LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $mode = trim((string)($filters['mode'] ?? ''));
    if (in_array($mode, ['human', 'bot'], true)) {
        $where[] = 'wc.attendance_mode = ?';
        $params[] = $mode;
    }
    if (!empty($filters['needs_human'])) {
        $where[] = 'wc.needs_human = 1';
    }
    $minScore = (int)($filters['min_score'] ?? 0);
    if ($minScore > 0) {
        $where[] = 'COALESCE(wc.lead_score, 0) >= ?';
        $params[] = min(10, $minScore);
    }

    $sql =
        "SELECT wc.*, c.name AS customer_name, l.name AS lead_name, COUNT(wm.id) AS message_count,
                COALESCE(wc.last_message_at, wm_last.sent_at, MAX(wm.sent_at)) AS message_last_at,
                COALESCE(wc.last_message_preview, wm_last.body) AS latest_message_preview,
                COALESCE(wc.last_message_preview, wm_last.body) AS last_message_preview,
                COALESCE(wc.last_message_direction, wm_last.direction) AS latest_message_direction,
                COALESCE(wc.last_message_direction, wm_last.direction) AS last_message_direction,
                MAX(CASE WHEN wm.direction IN ('in', 'customer') THEN wm.sent_at END) AS last_incoming_at,
                MAX(CASE WHEN wm.direction IN ('out', 'human', 'bot') THEN wm.sent_at END) AS last_outgoing_at
         FROM whatsapp_conversations wc
         LEFT JOIN customers c ON c.id = wc.customer_id
         LEFT JOIN leads l ON l.id = wc.lead_id
         LEFT JOIN whatsapp_messages wm ON wm.conversation_id = wc.id
         LEFT JOIN whatsapp_messages wm_last ON wm_last.id = (
             SELECT wm2.id
             FROM whatsapp_messages wm2
             WHERE wm2.conversation_id = wc.id
             ORDER BY wm2.sent_at DESC, wm2.id DESC
             LIMIT 1
         )";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $offset = max(0, (int)($filters['offset'] ?? 0));
    $fetchLimit = $limit > 0 ? ($limit + $offset) : 0;
    if ($fetchLimit <= 0) {
        $fetchLimit = 160;
    }

    $sql .= " GROUP BY wc.id
         ORDER BY COALESCE(wc.last_message_at, wm_last.sent_at, MAX(wm.sent_at), wc.updated_at) DESC, wc.id DESC
         LIMIT ?";

    $stmt = studio_db($studio)->prepare($sql);
    foreach ($params as $index => $param) {
        $stmt->bindValue($index + 1, $param);
    }
    $stmt->bindValue(count($params) + 1, $fetchLimit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    $filter = trim((string)($filters['filter'] ?? 'all'));
    if (!in_array($filter, ['all', 'unreplied', 'needs_human', 'bot', 'human', 'no_link'], true)) {
        $filter = 'all';
    }

    $rows = array_values(array_filter($rows, static function (array $conversation) use ($filter): bool {
        $hasLink = !empty($conversation['customer_id']) || !empty($conversation['lead_id']);
        $lastIncoming = trim((string)($conversation['last_incoming_at'] ?? ''));
        $lastOutgoing = trim((string)($conversation['last_outgoing_at'] ?? ''));
        $needsReply = $lastIncoming !== '' && ($lastOutgoing === '' || strtotime($lastIncoming) > strtotime($lastOutgoing));

        return match ($filter) {
            'unreplied' => $needsReply,
            'needs_human' => !empty($conversation['needs_human']),
            'bot' => (string)($conversation['attendance_mode'] ?? '') === 'bot',
            'human' => (string)($conversation['attendance_mode'] ?? '') === 'human',
            'no_link' => !$hasLink,
            default => true,
        };
    }));

    if ($offset > 0 || $limit > 0) {
        $rows = array_slice($rows, $offset, $limit > 0 ? $limit : null);
    }

    return $rows;
}

function studio_find_whatsapp_conversation(array $studio, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = studio_db($studio)->prepare(
        "SELECT wc.*, c.name AS customer_name, c.email AS customer_email, c.instagram AS customer_instagram, c.notes AS customer_notes,
                l.name AS lead_name, l.interest AS lead_interest, l.status AS lead_status, l.pipeline_stage AS lead_pipeline_stage, l.estimated_value AS lead_estimated_value,
                COALESCE(wc.last_message_preview, wm_last.body) AS latest_message_preview,
                COALESCE(wc.last_message_preview, wm_last.body) AS last_message_preview,
                COALESCE(wc.last_message_direction, wm_last.direction) AS latest_message_direction,
                COALESCE(wc.last_message_direction, wm_last.direction) AS last_message_direction,
                COALESCE(wc.last_message_at, wm_last.sent_at) AS message_last_at
         FROM whatsapp_conversations wc
         LEFT JOIN customers c ON c.id = wc.customer_id
         LEFT JOIN leads l ON l.id = wc.lead_id
         LEFT JOIN whatsapp_messages wm_last ON wm_last.id = (
             SELECT wm2.id
             FROM whatsapp_messages wm2
             WHERE wm2.conversation_id = wc.id
             ORDER BY wm2.sent_at DESC, wm2.id DESC
             LIMIT 1
         )
         WHERE wc.id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $conversation = $stmt->fetch();

    return is_array($conversation) ? $conversation : null;
}

function studio_whatsapp_messages(array $studio, int $conversationId, int $limit = 80, array $conversation = []): array
{
    $stmt = studio_db($studio)->prepare(
        'SELECT *
         FROM whatsapp_messages
         WHERE conversation_id = ?
         ORDER BY sent_at DESC, id DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $conversationId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll() ?: [];

    $fallbackBody = trim((string)($conversation['last_message_preview'] ?? ''));
    if ($fallbackBody === '') {
        $fallbackBody = trim((string)($conversation['latest_message_preview'] ?? ''));
    }

    if (!$messages && $fallbackBody !== '') {
        $messages = [[
            'id' => 0,
            'conversation_id' => $conversationId,
            'direction' => (string)($conversation['last_message_direction'] ?? 'in'),
            'sender_type' => (string)($conversation['last_message_direction'] ?? 'in') === 'out' ? 'human' : 'customer',
            'body' => $fallbackBody,
            'media_url' => '',
            'media_mime' => '',
            'media_file_name' => '',
            'media_file_path' => '',
            'message_type' => 'texto',
            'message_id' => '',
            'remote_jid' => (string)($conversation['remote_jid'] ?? ''),
            'from_me' => (string)($conversation['last_message_direction'] ?? 'in') === 'out' ? 1 : 0,
            'status' => '',
            'sent_at' => (string)($conversation['last_message_at'] ?? $conversation['updated_at'] ?? ''),
            'created_at' => (string)($conversation['last_message_at'] ?? $conversation['updated_at'] ?? ''),
            'transcricao' => '',
            'transcript' => '',
        ]];
    }

    return array_reverse($messages);
}

function studio_whatsapp_read_state_path(array $studio): string
{
    $studioId = max(0, (int)($studio['id'] ?? 0));
    $dir = APP_BASE_PATH . '/storage/whatsapp';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir . '/read_state_' . $studioId . '.json';
}

function studio_whatsapp_read_state(array $studio): array
{
    $path = studio_whatsapp_read_state_path($studio);
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function studio_whatsapp_save_read_state(array $studio, array $state): void
{
    $path = studio_whatsapp_read_state_path($studio);
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

function studio_whatsapp_get_read_at(array $studio, int $conversationId): string
{
    $state = studio_whatsapp_read_state($studio);
    return trim((string)($state[(string)$conversationId] ?? ''));
}

function studio_whatsapp_mark_read(array $studio, int $conversationId): string
{
    if ($conversationId <= 0) {
        throw new RuntimeException('Conversa invalida.');
    }

    $state = studio_whatsapp_read_state($studio);
    $readAt = date('Y-m-d H:i:s');
    $state[(string)$conversationId] = $readAt;
    studio_whatsapp_save_read_state($studio, $state);

    return $readAt;
}

function studio_whatsapp_mark_unread(array $studio, int $conversationId): void
{
    if ($conversationId <= 0) {
        throw new RuntimeException('Conversa invalida.');
    }

    $state = studio_whatsapp_read_state($studio);
    unset($state[(string)$conversationId]);
    studio_whatsapp_save_read_state($studio, $state);
}

function studio_whatsapp_unread_count(array $conversation, array $studio): int
{
    $readAt = studio_whatsapp_get_read_at($studio, (int)($conversation['id'] ?? 0));
    $lastRead = $readAt !== '' ? strtotime($readAt) : 0;
    $lastIncoming = trim((string)($conversation['last_incoming_at'] ?? ''));
    if ($lastIncoming === '') {
        return 0;
    }

    $incomingTs = strtotime($lastIncoming);
    if ($incomingTs === false || $incomingTs <= $lastRead) {
        return 0;
    }

    $totalMessages = max(1, (int)($conversation['message_count'] ?? 1));
    return min(99, $totalMessages);
}

function studio_update_whatsapp_conversation(array $studio, array $data): void
{
    $id = (int)($data['conversation_id'] ?? $data['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Conversa invalida.');
    }

    $mode = (string)($data['attendance_mode'] ?? 'human');
    if (!in_array($mode, ['human', 'bot'], true)) {
        $mode = 'human';
    }
    $needsHuman = !empty($data['needs_human']) ? 1 : 0;
    $score = max(0, min(10, (int)($data['lead_score'] ?? 0)));
    $aiStatus = trim((string)($data['ai_last_status'] ?? ''));
    if ($aiStatus === '' && $mode === 'human') {
        $aiStatus = 'IA inativa';
    } elseif ($aiStatus === '' && $mode === 'bot') {
        $aiStatus = 'IA pronta';
    }

    $stmt = studio_db($studio)->prepare(
        'UPDATE whatsapp_conversations
         SET attendance_mode = ?, needs_human = ?, lead_score = ?, ai_last_status = NULLIF(?, ""), updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$mode, $needsHuman, $score, $aiStatus, $id]);
}

function studio_update_whatsapp_profile(array $studio, array $data): array
{
    $pdo = studio_db($studio);
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
    if (!$conversation) {
        throw new RuntimeException('Conversa invalida.');
    }

    $phone = normalize_phone((string)($data['phone'] ?? $conversation['phone'] ?? ''));
    if ($phone === '') {
        $phone = (string)($conversation['phone'] ?? '');
    }
    $entityPhone = str_starts_with($phone, 'web_') ? '' : $phone;

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $name = (string)($conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: 'Cliente WhatsApp')));
    }

    $customerId = (int)($data['customer_id'] ?? $conversation['customer_id'] ?? 0);
    $customerPayload = [
        'id' => $customerId,
        'name' => $name,
        'phone' => $entityPhone,
        'email' => (string)($data['email'] ?? $conversation['customer_email'] ?? ''),
        'instagram' => (string)($data['instagram'] ?? $conversation['customer_instagram'] ?? ''),
        'notes' => (string)($data['notes'] ?? $conversation['customer_notes'] ?? ''),
    ];
    if ($customerId > 0 || !empty($data['create_customer'])) {
        $customerId = studio_save_customer($studio, $customerPayload);
    }

    $leadId = (int)($data['lead_id'] ?? $conversation['lead_id'] ?? 0);
    $leadPayload = [
        'id' => $leadId,
        'customer_id' => $customerId,
        'name' => $name,
        'phone' => $entityPhone,
        'interest' => trim((string)($data['interest'] ?? $conversation['lead_interest'] ?? 'Conversa WhatsApp')),
        'status' => trim((string)($data['status'] ?? $conversation['lead_status'] ?? 'em_conversa')),
        'pipeline_stage' => trim((string)($data['pipeline_stage'] ?? $conversation['lead_pipeline_stage'] ?? 'em_conversa')),
        'lead_score' => (int)($data['lead_score'] ?? $conversation['lead_score'] ?? 0),
        'estimated_value' => (string)($data['estimated_value'] ?? $conversation['lead_estimated_value'] ?? '0'),
        'source' => trim((string)($data['source'] ?? 'WhatsApp')),
    ];
    if ($leadId > 0 || !empty($data['create_lead'])) {
        $leadId = studio_save_lead($studio, $leadPayload);
    }

    $mode = (string)($data['attendance_mode'] ?? $conversation['attendance_mode'] ?? 'human');
    if (!in_array($mode, ['human', 'bot'], true)) {
        $mode = 'human';
    }
    $needsHuman = !empty($data['needs_human']) ? 1 : 0;
    $score = max(0, min(10, (int)($data['lead_score'] ?? $conversation['lead_score'] ?? 0)));
    $aiStatus = trim((string)($data['ai_last_status'] ?? $conversation['ai_last_status'] ?? ''));
    if ($aiStatus === '' && $mode === 'human') {
        $aiStatus = 'IA inativa';
    } elseif ($aiStatus === '' && $mode === 'bot') {
        $aiStatus = 'IA pronta';
    }

    $stmt = $pdo->prepare(
        'UPDATE whatsapp_conversations
         SET customer_id = ?, lead_id = ?, phone = ?, name = ?, attendance_mode = ?, needs_human = ?, lead_score = ?, ai_last_status = NULLIF(?, ""), updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$customerId ?: null, $leadId ?: null, $phone, $name, $mode, $needsHuman, $score, $aiStatus, $conversationId]);

    return ['customer_id' => $customerId, 'lead_id' => $leadId, 'conversation_id' => $conversationId];
}

function studio_find_customer_by_phone(array $studio, string $phone): ?array
{
    $stmt = studio_db($studio)->query('SELECT * FROM customers WHERE phone IS NOT NULL AND phone <> "" ORDER BY updated_at DESC, id DESC LIMIT 500');
    foreach ($stmt->fetchAll() ?: [] as $customer) {
        if (phones_match((string)$customer['phone'], $phone)) {
            return $customer;
        }
    }

    return null;
}

function studio_find_lead_by_phone(array $studio, string $phone): ?array
{
    $stmt = studio_db($studio)->query('SELECT * FROM leads WHERE phone IS NOT NULL AND phone <> "" ORDER BY updated_at DESC, id DESC LIMIT 500');
    foreach ($stmt->fetchAll() ?: [] as $lead) {
        if (phones_match((string)$lead['phone'], $phone)) {
            return $lead;
        }
    }

    return null;
}

function studio_find_whatsapp_conversation_by_phone(array $studio, string $phone): ?array
{
    $stmt = studio_db($studio)->query('SELECT * FROM whatsapp_conversations ORDER BY updated_at DESC, id DESC LIMIT 800');
    foreach ($stmt->fetchAll() ?: [] as $conversation) {
        if (phones_match((string)$conversation['phone'], $phone)) {
            return $conversation;
        }
    }

    return null;
}

function studio_whatsapp_default_mode(array $studio): string
{
    $settings = studio_settings($studio);
    return (string)($settings['whatsapp_default_mode'] ?? 'human') === 'bot' ? 'bot' : 'human';
}

function studio_schedule_days(array $studio): array
{
    $settings = studio_settings($studio);
    $raw = trim((string)($settings['appointment_work_days'] ?? ''));
    if ($raw === '') {
        return ['1', '2', '3', '4', '5'];
    }
    $days = array_values(array_filter(array_map(static fn(string $value): string => trim($value), preg_split('/[,\s|;]+/', $raw) ?: []), static fn(string $value): bool => $value !== ''));
    $days = array_values(array_unique(array_map(static fn(string $value): string => (string)max(1, min(7, (int)$value)), $days)));
    return $days ?: ['1', '2', '3', '4', '5'];
}

function studio_schedule_slots(array $studio): array
{
    $settings = studio_settings($studio);
    $raw = trim((string)($settings['appointment_time_slots'] ?? ''));
    if ($raw === '') {
        return ['10:00', '15:00'];
    }
    $slots = array_values(array_filter(array_map(static fn(string $value): string => preg_replace('/[^0-9:]/', '', trim($value)), preg_split('/[,\n\r|;]+/', $raw) ?: []), static fn(string $value): bool => $value !== ''));
    $slots = array_values(array_unique(array_filter($slots, static function (string $value): bool {
        return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
    })));
    return $slots ?: ['10:00', '15:00'];
}

function studio_schedule_duration_minutes(array $studio): int
{
    $settings = studio_settings($studio);
    $raw = trim((string)($settings['appointment_duration_minutes'] ?? '300'));
    $minutes = (int)$raw;
    if ($minutes <= 0) {
        $minutes = 300;
    }

    return max(15, min(24 * 60, $minutes));
}

function studio_schedule_add_minutes(string $date, string $time, int $minutes): string
{
    $start = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        trim($date) . ' ' . substr(trim($time), 0, 5),
        new DateTimeZone('America/Sao_Paulo')
    );
    if (!$start) {
        return '';
    }

    return $start->modify('+' . max(0, $minutes) . ' minutes')->format('H:i');
}

function studio_weekday_label_pt(DateTimeImmutable $date): string
{
    return [
        '1' => 'Seg',
        '2' => 'Ter',
        '3' => 'Qua',
        '4' => 'Qui',
        '5' => 'Sex',
        '6' => 'Sáb',
        '7' => 'Dom',
    ][$date->format('N')] ?? $date->format('d/m');
}

function studio_schedule_normalize_end_time(string $date, string $startTime, ?string $endTime, int $durationMinutes): string
{
    $startTime = substr(trim($startTime), 0, 5);
    $provided = substr(trim((string)$endTime), 0, 5);
    $calculated = studio_schedule_add_minutes($date, $startTime, $durationMinutes);
    if ($calculated === '') {
        return $provided;
    }

    if ($provided === '') {
        return $calculated;
    }

    return $calculated;
}

function studio_appointment_blocking_statuses(): array
{
    return ['pre_agendado', 'agendado', 'confirmado', 'em_atendimento', 'pendente'];
}

function studio_ensure_public_lead_links_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo = db();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `public_lead_links` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `studio_id` INT UNSIGNED NOT NULL,
                `lead_id` BIGINT UNSIGNED NOT NULL,
                `token` VARCHAR(64) NOT NULL,
                `lead_customer_id` BIGINT UNSIGNED NULL,
                `draft_payload` LONGTEXT NULL,
                `last_step` VARCHAR(40) NULL,
                `finished_at` DATETIME NULL,
                `last_accessed_at` DATETIME NULL,
                `last_ip_hash` VARCHAR(120) NULL,
                `last_user_agent` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_public_lead_links_token` (`token`),
                UNIQUE KEY `uk_public_lead_links_studio_lead` (`studio_id`, `lead_id`),
                KEY `idx_public_lead_links_studio` (`studio_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable) {
    }
    $done = true;
}

function studio_ensure_public_lead_events_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo = db();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `public_lead_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `studio_id` INT UNSIGNED NOT NULL,
                `lead_id` BIGINT UNSIGNED NOT NULL,
                `token` VARCHAR(64) NOT NULL,
                `event_name` VARCHAR(60) NOT NULL,
                `event_payload` LONGTEXT NULL,
                `ip_hash` VARCHAR(120) NULL,
                `user_agent` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_public_lead_events_lookup` (`studio_id`, `lead_id`, `event_name`, `created_at`),
                KEY `idx_public_lead_events_token` (`token`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable) {
    }
    $done = true;
}

function studio_upsert_public_lead_link(array $studio, int $leadId, string $token, ?int $customerId = null): void
{
    studio_ensure_public_lead_links_column();
    if ($leadId <= 0 || $token === '') {
        return;
    }
    try {
        $stmt = db()->prepare(
            'INSERT INTO public_lead_links (studio_id, lead_id, token, lead_customer_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE token = VALUES(token), lead_customer_id = VALUES(lead_customer_id), updated_at = NOW()'
        );
        $stmt->execute([(int)$studio['id'], $leadId, $token, $customerId ?: null]);
    } catch (Throwable) {
    }
}

function studio_save_public_lead_progress(array $studio, int $leadId, string $token, array $payload, ?string $step = null, bool $finished = false): void
{
    studio_ensure_public_lead_links_column();
    if ($leadId <= 0 || $token === '') {
        return;
    }
    try {
        $existing = studio_find_public_lead_link($leadId, $token);
        $previousDraft = [];
        if (is_array($existing) && !empty($existing['draft_payload'])) {
            $decoded = json_decode((string)$existing['draft_payload'], true);
            if (is_array($decoded)) {
                $previousDraft = $decoded;
            }
        }
        $merged = array_merge($previousDraft, array_filter($payload, static fn(mixed $value): bool => !($value === null || $value === '')));
        $stmt = db()->prepare(
            'INSERT INTO public_lead_links (studio_id, lead_id, token, draft_payload, last_step, finished_at, last_accessed_at, last_ip_hash, last_user_agent, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE draft_payload = VALUES(draft_payload), last_step = VALUES(last_step), finished_at = VALUES(finished_at), last_accessed_at = NOW(), last_ip_hash = VALUES(last_ip_hash), last_user_agent = VALUES(last_user_agent), updated_at = NOW()'
        );
        $stmt->execute([
            (int)$studio['id'],
            $leadId,
            $token,
            json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $step,
            $finished ? date('Y-m-d H:i:s') : null,
            studio_public_ip_hash(),
            studio_public_user_agent(),
        ]);
    } catch (Throwable) {
    }
}

function studio_log_public_lead_event(array $studio, int $leadId, string $token, string $eventName, array $payload = []): void
{
    studio_ensure_public_lead_events_column();
    if ($leadId <= 0 || $token === '' || $eventName === '') {
        return;
    }
    try {
        $stmt = db()->prepare(
            'INSERT INTO public_lead_events (studio_id, lead_id, token, event_name, event_payload, ip_hash, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            (int)$studio['id'],
            $leadId,
            $token,
            $eventName,
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            studio_public_ip_hash(),
            studio_public_user_agent(),
        ]);
    } catch (Throwable) {
    }
}

function studio_public_ip_hash(): string
{
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim(explode(',', $ip)[0] ?? '');
    if ($ip === '') {
        return '';
    }
    return hash('sha256', $ip . '|' . (__FILE__ ?? 'projetocrm'));
}

function studio_public_user_agent(): string
{
    return mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

function studio_find_public_lead_link(int $leadId, string $token): ?array
{
    studio_ensure_public_lead_links_column();
    if ($leadId <= 0 || $token === '') {
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT * FROM public_lead_links WHERE lead_id = ? AND token = ? LIMIT 1');
        $stmt->execute([$leadId, $token]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

function studio_appointment_blocking_statuses_for_status(string $status): array
{
    $blockingStatuses = studio_appointment_blocking_statuses();
    if ($status === 'pre_agendado') {
        return array_values(array_filter(
            $blockingStatuses,
            static fn(string $blockingStatus): bool => $blockingStatus !== 'pre_agendado'
        ));
    }

    return $blockingStatuses;
}

function studio_appointment_non_blocking_statuses(): array
{
    return ['cancelado', 'perdido', 'concluido', 'atendido', 'finalizado'];
}

function studio_appointment_allowed_statuses(): array
{
    return [
        'pre_agendado',
        'agendado',
        'confirmado',
        'atendido',
        'finalizado',
        'falta',
        'cancelado',
    ];
}

function studio_validate_appointment_payload(array $studio, array $data, int $excludeId = 0): array
{
    $pdo = studio_db($studio);
    $appointmentDate = trim((string)($data['appointment_date'] ?? ''));
    $startTime = substr(trim((string)($data['start_time'] ?? '')), 0, 5);
    $endTime = substr(trim((string)($data['end_time'] ?? '')), 0, 5);
    $importSource = trim((string)($data['import_source'] ?? 'manual'));
    $artistId = (int)($data['artist_id'] ?? 0);
    $leadId = (int)($data['lead_id'] ?? 0);
    $customerId = (int)($data['customer_id'] ?? 0);
    $value = money_to_float((string)($data['value'] ?? '0'));
    $depositValue = money_to_float((string)($data['deposit_value'] ?? '0'));

    if ($appointmentDate === '') {
        throw new RuntimeException('Informe a data do agendamento.');
    }
    if ($startTime === '') {
        throw new RuntimeException('Informe o horário de início.');
    }
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $appointmentDate, new DateTimeZone('America/Sao_Paulo'));
    if (!$dateObject) {
        throw new RuntimeException('Informe uma data válida para o agendamento.');
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
        throw new RuntimeException('Informe horários válidos para início e fim.');
    }
    if ($endTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
        throw new RuntimeException('Informe horários válidos para início e fim.');
    }
    if ($endTime !== '' && $endTime <= $startTime) {
        throw new RuntimeException('O horário final precisa ser maior que o horário inicial.');
    }
    if ($artistId <= 0) {
        $artistId = default_artist_id($studio) ?? 0;
    }
    if ($artistId <= 0) {
        throw new RuntimeException('Selecione um tatuador para o agendamento.');
    }
    if ($leadId <= 0 && $customerId <= 0 && $importSource === 'manual') {
        throw new RuntimeException('Vincule o agendamento a um cliente ou lead.');
    }
    if ($value < 0) {
        throw new RuntimeException('O valor não pode ser negativo.');
    }
    if ($depositValue < 0) {
        throw new RuntimeException('O sinal não pode ser negativo.');
    }
    if ($depositValue > $value && $value > 0) {
        throw new RuntimeException('O sinal não pode ser maior que o valor total.');
    }
    if ($leadId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM leads WHERE id = ? LIMIT 1');
        $stmt->execute([$leadId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('O lead informado não pertence a este estúdio.');
        }
    }
    if ($customerId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('O cliente informado não pertence a este estúdio.');
        }
    }
    $artistStmt = $pdo->prepare('SELECT id, name FROM tattoo_artists WHERE id = ? LIMIT 1');
    $artistStmt->execute([$artistId]);
    $artist = $artistStmt->fetch();
    if (!$artist) {
        throw new RuntimeException('Selecione um tatuador válido deste estúdio.');
    }

    return [
        'appointment_date' => $appointmentDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'artist_id' => $artistId,
        'lead_id' => $leadId,
        'customer_id' => $customerId,
        'import_source' => $importSource !== '' ? $importSource : 'manual',
        'value' => $value,
        'deposit_value' => $depositValue,
        'artist_name' => (string)($artist['name'] ?? ''),
        'exclude_id' => $excludeId,
    ];
}

function studio_schedule_is_allowed_day(array $studio, DateTimeImmutable $date): bool
{
    return in_array((string)$date->format('N'), studio_schedule_days($studio), true);
}

function studio_schedule_next_slots(array $studio, int $daysAhead = 7): array
{
    $slots = studio_schedule_slots($studio);
    $allowedDays = studio_schedule_days($studio);
    $today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
    $result = [];
    for ($offset = 0; $offset < max(1, $daysAhead); $offset++) {
        $day = $today->modify('+' . $offset . ' days');
        if (!in_array((string)$day->format('N'), $allowedDays, true)) {
            continue;
        }
        foreach ($slots as $slot) {
            $result[] = [
                'date' => $day->format('Y-m-d'),
                'weekday' => $day->format('D'),
                'time' => $slot,
            ];
        }
    }
    return $result;
}

function studio_schedule_available_slots(array $studio, int $daysAhead = 14, ?DateTimeImmutable $startDate = null): array
{
    $today = $startDate ?: new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
    $endDate = $today->modify('+' . max(1, $daysAhead - 1) . ' days');
    $allowedSlots = studio_schedule_slots($studio);
    $allowedDays = studio_schedule_days($studio);
    $appointments = studio_calendar_appointments($studio, $today->format('Y-m-d'), $endDate->format('Y-m-d'));
    $byDate = [];
    foreach ($appointments as $appointment) {
        $byDate[(string)$appointment['appointment_date']][] = $appointment;
    }

    $result = [];
    for ($offset = 0; $offset < max(1, $daysAhead); $offset++) {
        $day = $today->modify('+' . $offset . ' days');
        $date = $day->format('Y-m-d');
        $allowed = in_array((string)$day->format('N'), $allowedDays, true);
        $freeSlots = [];
        $booked = [];
        foreach ($allowedSlots as $slot) {
            $occupied = false;
            foreach ($byDate[$date] ?? [] as $appointment) {
                if (substr((string)$appointment['start_time'], 0, 5) === $slot) {
                    $occupied = true;
                    $booked[] = [
                        'id' => (int)($appointment['id'] ?? 0),
                        'time' => $slot,
                        'customer_name' => (string)($appointment['customer_name'] ?? $appointment['title'] ?? ''),
                        'status' => (string)($appointment['status'] ?? ''),
                    ];
                    break;
                }
            }
            if (!$occupied) {
                $freeSlots[] = $slot;
            }
        }
        $result[] = [
            'date' => $date,
            'label' => studio_weekday_label_pt($day) . ' ' . $day->format('d/m'),
            'allowed' => $allowed,
            'free_slots' => $freeSlots,
            'booked' => $booked,
            'free' => count($freeSlots),
        ];
    }

    return $result;
}

function studio_whatsapp_lead_score(string $text, bool $hasMedia): int
{
    $text = strtolower($text);
    $score = 4;
    foreach (['agenda', 'agendar', 'horario', 'sinal', 'pix', 'fechar', 'disponivel'] as $word) {
        if (str_contains($text, $word)) {
            $score += 1;
        }
    }
    foreach (['preco', 'valor', 'quanto', 'orcamento'] as $word) {
        if (str_contains($text, $word)) {
            $score += 1;
        }
    }
    if ($hasMedia) {
        $score += 1;
    }

    return max(1, min(10, $score));
}

function studio_whatsapp_needs_human(string $text): bool
{
    $text = strtolower($text);
    foreach (['humano', 'atendente', 'pessoa', 'alguem', 'responsavel', 'falar com voce'] as $needle) {
        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

function studio_whatsapp_schedule_suggestion(array $conversation, array $messages, array $artists): array
{
    $text = strtolower(trim((string)($conversation['last_message_preview'] ?? '')));
    foreach (array_slice(array_reverse($messages), 0, 6) as $message) {
        $text .= ' ' . strtolower(trim((string)($message['body'] ?? '')));
    }

    $reason = 'Conversa com sinais de agendamento.';
    $title = 'Atendimento';
    if (str_contains($text, 'fech') || str_contains($text, 'agenda') || str_contains($text, 'horario') || str_contains($text, 'sinal')) {
        $reason = 'A conversa cita agenda, sinal ou fechamento.';
        $title = 'Agendamento de tatuagem';
    } elseif (str_contains($text, 'cobertura')) {
        $reason = 'A conversa cita cobertura, bom para uma reserva dedicada.';
        $title = 'Cobertura';
    } elseif (str_contains($text, 'feminina') || str_contains($text, 'masculina')) {
        $reason = 'A conversa sugere estilo/pedido especifico.';
        $title = 'Orcamento / atendimento';
    }

    $artistId = '';
    foreach ($artists as $artist) {
        $specialty = strtolower((string)($artist['specialty'] ?? ''));
        $name = strtolower((string)($artist['name'] ?? ''));
        if ($specialty !== '' && (str_contains($text, $specialty) || str_contains($title, $specialty))) {
            $artistId = (string)$artist['id'];
            break;
        }
        if ($artistId === '' && $name !== '' && str_contains($text, $name)) {
            $artistId = (string)$artist['id'];
        }
    }
    if ($artistId === '' && $artists) {
        $artistId = (string)($artists[0]['id'] ?? '');
    }

    $studio = current_studio() ?: [];
    $durationMinutes = studio_schedule_duration_minutes($studio);
    $date = date('Y-m-d');
    $time = studio_schedule_slots($studio)[0] ?? '10:00';
    $nextSlots = studio_schedule_next_slots($studio, 14);
    if ($nextSlots) {
        $date = $nextSlots[0]['date'];
        $time = $nextSlots[0]['time'];
    }
    if (str_contains($text, 'amanha')) {
        $date = date('Y-m-d', strtotime('+1 day'));
    } elseif (str_contains($text, 'sabado')) {
        $date = date('Y-m-d', strtotime('next saturday'));
    } elseif (str_contains($text, 'segunda')) {
        $date = date('Y-m-d', strtotime('next monday'));
    }
    if (preg_match('/\b(1[0-9]|2[0-1]):[0-5][0-9]\b/', $text, $match)) {
        $time = $match[0];
    }
    $allowedSlots = studio_schedule_slots($studio);
    if (!in_array($time, $allowedSlots, true)) {
        $time = $allowedSlots[0] ?? '10:00';
    }
    $endTime = studio_schedule_add_minutes($date, $time, $durationMinutes);

    return [
        'title' => $title,
        'reason' => $reason,
        'date' => $date,
        'time' => $time,
        'end_time' => $endTime,
        'duration_minutes' => $durationMinutes,
        'description' => (string)($conversation['last_message_preview'] ?? ''),
        'artist_id' => $artistId,
    ];
}

function studio_whatsapp_assistant_insights(array $studio, array $conversation, array $messages = []): array
{
    $settings = studio_settings($studio);
    $enabled = !empty($settings['ai_enabled']) && !empty($settings['assistant_autofill_enabled']);
    $history = array_slice(array_reverse($messages), 0, 8);
    $currentName = trim((string)($conversation['name'] ?? $conversation['customer_name'] ?? $conversation['lead_name'] ?? ''));
    $currentInterest = trim((string)($conversation['lead_interest'] ?? ''));
    $currentNotes = trim((string)($conversation['customer_notes'] ?? ''));
    $currentPhone = trim((string)($conversation['phone'] ?? ''));
    $genericName = $currentName === '' || in_array(function_exists('mb_strtolower') ? mb_strtolower($currentName, 'UTF-8') : strtolower($currentName), ['cliente whatsapp', 'contato whatsapp', 'sem nome'], true);

    $result = [
        'source' => 'heuristic',
        'confidence' => 0,
        'suggested_name' => '',
        'suggested_interest' => '',
        'suggested_notes' => '',
        'suggested_date' => '',
        'suggested_time' => '',
        'schedule_reason' => '',
        'summary' => '',
    ];

    $parseNameFromText = static function (string $text): string {
        $patterns = [
            '/\bmeu nome e[?a]\s+([A-Z???????????][\p{L}\'?\-]+(?:\s+[A-Z???????????][\p{L}\'?\-]+){0,3})/iu',
            '/\bsou\s+([A-Z???????????][\p{L}\'?\-]+(?:\s+[A-Z???????????][\p{L}\'?\-]+){0,3})/iu',
            '/\bme chamo\s+([A-Z???????????][\p{L}\'?\-]+(?:\s+[A-Z???????????][\p{L}\'?\-]+){0,3})/iu',
            '/\b(aqui e|aqui ?)\s+([A-Z???????????][\p{L}\'?\-]+(?:\s+[A-Z???????????][\p{L}\'?\-]+){0,3})/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim((string)($matches[2] ?? $matches[1] ?? ''));
            }
        }
        return '';
    };
    $parseInterestFromText = static function (string $text): string {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach (['quero tatuar', 'quero fazer', 'quero agendar', 'tenho interesse', 'valor', 'orcamento', 'referencia', 'tattoo'] as $needle) {
            if (str_contains($lower, $needle)) {
                return mb_substr(trim($text), 0, 180);
            }
        }
        return '';
    };

    foreach ($history as $message) {
        $body = trim((string)($message['body'] ?? ''));
        if ($body === '') {
            continue;
        }
        if ($result['suggested_name'] === '') {
            $candidate = $parseNameFromText($body);
            if ($candidate !== '') {
                $result['suggested_name'] = $candidate;
                $result['confidence'] = max($result['confidence'], 7);
            }
        }
        if ($result['suggested_interest'] === '') {
            $candidate = $parseInterestFromText($body);
            if ($candidate !== '') {
                $result['suggested_interest'] = $candidate;
                $result['confidence'] = max($result['confidence'], 5);
            }
        }
        if ($result['suggested_notes'] === '' && str_contains(function_exists('mb_strtolower') ? mb_strtolower($body, 'UTF-8') : strtolower($body), 'pomada')) {
            $result['suggested_notes'] = mb_substr($body, 0, 180);
        }
    }

    $schedule = studio_whatsapp_schedule_suggestion($conversation, $messages, []);
    if (!empty($schedule['date']) && !empty($schedule['time'])) {
        $result['suggested_date'] = (string)$schedule['date'];
        $result['suggested_time'] = (string)$schedule['time'];
        $result['schedule_reason'] = (string)($schedule['reason'] ?? '');
        $result['confidence'] = max($result['confidence'], 5);
    }

    if ($enabled) {
        $historyText = [];
        foreach ($history as $message) {
            $direction = (string)($message['direction'] ?? 'in') === 'out' ? 'Atendente' : 'Cliente';
            $body = trim((string)($message['body'] ?? ''));
            if ($body === '') {
                $body = '[' . (string)($message['message_type'] ?? 'texto') . ']';
            }
            $historyText[] = $direction . ': ' . $body;
        }

        $systemPrompt = "Você extrai dados úteis de conversas de WhatsApp de um estúdio de tatuagem.\n"
            . "Responda somente com JSON válido e curto contendo: suggested_name, suggested_interest, suggested_notes, suggested_date, suggested_time, schedule_reason, confidence.\n"
            . "Use apenas o que estiver claramente explícito na conversa. Se não houver dado, deixe vazio.\n"
            . "Não invente nome, data, horário, interesse ou observação.";
        $userPrompt = json_encode([
            'nome_atual' => $currentName,
            'telefone' => $currentPhone,
            'interesse_atual' => $currentInterest,
            'observacoes_atual' => $currentNotes,
            'mensagens_recentes' => $historyText,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $userPrompt = is_string($userPrompt) ? $userPrompt : '{}';

        try {
            $config = studio_openai_config($studio);
            if ($config['api_key'] !== '') {
                $aiResult = studio_openai_text($config['api_key'], $config['model'], $systemPrompt, $userPrompt, $config['base_url'], 40);
                if (!empty($aiResult['ok']) && !empty($aiResult['reply_text'])) {
                    $decoded = json_decode((string)$aiResult['reply_text'], true);
                    if (!is_array($decoded) && preg_match('/\{.*\}/s', (string)$aiResult['reply_text'], $matches)) {
                        $decoded = json_decode($matches[0], true);
                    }
                    if (is_array($decoded)) {
                        foreach (['suggested_name', 'suggested_interest', 'suggested_notes', 'suggested_date', 'suggested_time', 'schedule_reason'] as $key) {
                            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                                $result[$key] = trim($decoded[$key]);
                            }
                        }
                        if (isset($decoded['confidence'])) {
                            $result['confidence'] = max($result['confidence'], min(10, max(0, (int)$decoded['confidence'])));
                        }
                        $result['source'] = 'ai';
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    if ($result['suggested_name'] === '' && $genericName && $currentPhone !== '') {
        $result['suggested_name'] = 'Cliente ' . $currentPhone;
    }

    return $result;
}

function studio_apply_whatsapp_assistant_enrichment(array $studio, array $conversation, array $insights): void
{
    $conversationId = (int)($conversation['id'] ?? 0);
    if ($conversationId <= 0 || !$insights) {
        return;
    }

    $pdo = studio_db($studio);
    $suggestedName = trim((string)($insights['suggested_name'] ?? ''));
    $suggestedInterest = trim((string)($insights['suggested_interest'] ?? ''));
    $suggestedNotes = trim((string)($insights['suggested_notes'] ?? ''));
    $conversationName = trim((string)($conversation['name'] ?? ''));
    $conversationNameLower = function_exists('mb_strtolower') ? mb_strtolower($conversationName, 'UTF-8') : strtolower($conversationName);
    $isGenericConversationName = $conversationName === '' || in_array($conversationNameLower, ['cliente whatsapp', 'contato whatsapp', 'sem nome'], true);

    $conversationUpdates = [];
    $conversationParams = [];
    if ($suggestedName !== '' && $isGenericConversationName) {
        $conversationUpdates[] = 'name = ?';
        $conversationParams[] = mb_substr($suggestedName, 0, 120);
    }
    if ($suggestedInterest !== '' && trim((string)($conversation['lead_interest'] ?? '')) === '') {
        $conversationUpdates[] = 'lead_interest = ?';
        $conversationParams[] = mb_substr($suggestedInterest, 0, 220);
    }
    if ($suggestedNotes !== '' && trim((string)($conversation['customer_notes'] ?? '')) === '') {
        $conversationUpdates[] = 'customer_notes = ?';
        $conversationParams[] = mb_substr($suggestedNotes, 0, 500);
    }

    if ($conversationUpdates) {
        $conversationUpdates[] = 'updated_at = NOW()';
        $conversationParams[] = $conversationId;
        $stmt = $pdo->prepare('UPDATE whatsapp_conversations SET ' . implode(', ', $conversationUpdates) . ' WHERE id = ?');
        $stmt->execute($conversationParams);
    }

    if (!empty($conversation['lead_id']) && ($suggestedName !== '' || $suggestedInterest !== '')) {
        $leadUpdates = [];
        $leadParams = [];
        if ($suggestedName !== '' && $isGenericConversationName) {
            $leadUpdates[] = 'name = COALESCE(NULLIF(name, ""), ?)';
            $leadParams[] = mb_substr($suggestedName, 0, 120);
        }
        if ($suggestedInterest !== '' && trim((string)($conversation['lead_interest'] ?? '')) === '') {
            $leadUpdates[] = 'interest = COALESCE(NULLIF(interest, ""), ?)';
            $leadParams[] = mb_substr($suggestedInterest, 0, 220);
        }
        if ($leadUpdates) {
            $leadParams[] = (int)$conversation['lead_id'];
            $stmt = $pdo->prepare('UPDATE leads SET ' . implode(', ', $leadUpdates) . ', updated_at = NOW() WHERE id = ?');
            $stmt->execute($leadParams);
        }
    }

    if (!empty($conversation['customer_id']) && $suggestedName !== '' && $isGenericConversationName) {
        $stmt = $pdo->prepare('UPDATE customers SET name = COALESCE(NULLIF(name, ""), ?) WHERE id = ?');
        $stmt->execute([mb_substr($suggestedName, 0, 120), (int)$conversation['customer_id']]);
    }
}

function studio_whatsapp_extract_date_context(string $text, array $studio): ?array
{
    $text = strtolower(trim($text));
    $tz = new DateTimeZone('America/Sao_Paulo');
    $today = new DateTimeImmutable('today', $tz);
    $candidate = null;

    if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/', $text, $match)) {
        $day = str_pad((string)(int)$match[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad((string)(int)$match[2], 2, '0', STR_PAD_LEFT);
        $year = !empty($match[3]) ? (int)$match[3] : (int)$today->format('Y');
        if ($year < 100) {
            $year += 2000;
        }
        $candidate = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, (int)$month, (int)$day), $tz) ?: null;
    } else {
        $relativeMap = [
            'hoje' => 'today',
            'amanha' => '+1 day',
            'amanhã' => '+1 day',
            'depois de amanha' => '+2 day',
            'depois de amanhã' => '+2 day',
            'semana que vem' => '+1 week',
            'proxima semana' => '+1 week',
            'próxima semana' => '+1 week',
        ];
        foreach ($relativeMap as $needle => $modifier) {
            if (str_contains($text, $needle)) {
                $candidate = new DateTimeImmutable($modifier, $tz);
                break;
            }
        }
        if (!$candidate) {
            $weekdayMap = [
                'segunda' => 'monday',
                'terca' => 'tuesday',
                'terça' => 'tuesday',
                'quarta' => 'wednesday',
                'quinta' => 'thursday',
                'sexta' => 'friday',
                'sabado' => 'saturday',
                'sábado' => 'saturday',
            ];
            foreach ($weekdayMap as $needle => $modifier) {
                if (str_contains($text, $needle)) {
                    $candidate = new DateTimeImmutable('next ' . $modifier, $tz);
                    break;
                }
            }
        }
    }

    if (!$candidate) {
        return null;
    }

    $date = $candidate->format('Y-m-d');
    $daysAhead = max(1, (int)$today->diff($candidate)->days + 1);
    $availability = studio_schedule_available_slots($studio, max(1, min(60, $daysAhead)));
    foreach ($availability as $day) {
        if ((string)($day['date'] ?? '') !== $date) {
            continue;
        }
        return [
            'date' => $date,
            'label' => (string)($day['label'] ?? ($candidate->format('d/m'))),
            'allowed' => !empty($day['allowed']),
            'free_slots' => array_values(array_map('strval', $day['free_slots'] ?? [])),
            'booked' => array_values(array_map(static function (array $appt): array {
                return [
                    'time' => (string)($appt['time'] ?? ''),
                    'customer_name' => (string)($appt['customer_name'] ?? ''),
                    'status' => (string)($appt['status'] ?? ''),
                ];
            }, $day['booked'] ?? [])),
        ];
    }

    return [
        'date' => $date,
        'label' => $candidate->format('d/m'),
        'allowed' => studio_schedule_is_allowed_day($studio, $candidate),
        'free_slots' => [],
        'booked' => [],
    ];
}

function studio_upsert_whatsapp_conversation(array $studio, array $payload): array
{
    $pdo = studio_db($studio);
    $phone = normalize_phone((string)($payload['numero'] ?? $payload['phone'] ?? ''));
    if ($phone === '') {
        throw new RuntimeException('Telefone do WhatsApp nao informado.');
    }

    $remoteJid = trim((string)($payload['remoteJid'] ?? $payload['jidCompleto'] ?? ''));
    $fromMe = !empty($payload['fromMe']);
    $text = trim((string)($payload['mensagem'] ?? $payload['body'] ?? ''));
    $messageType = trim((string)($payload['tipoMensagem'] ?? $payload['messageType'] ?? 'texto')) ?: 'texto';
    $hasMedia = !empty($payload['mediaBase64']) || !empty($payload['mediaUrl']);
    $score = studio_whatsapp_lead_score($text, $hasMedia);
    $needsHuman = studio_whatsapp_needs_human($text);

    $conversation = studio_find_whatsapp_conversation_by_phone($studio, $phone);
    if ($conversation) {
        return $conversation;
    }

    $customer = studio_find_customer_by_phone($studio, $phone);
    $lead = studio_find_lead_by_phone($studio, $phone);
    if (!$lead && !$fromMe) {
        $leadId = studio_save_lead($studio, [
            'name' => $customer['name'] ?? 'Cliente WhatsApp',
            'phone' => $phone,
            'interest' => $text !== '' ? mb_substr($text, 0, 180) : 'Contato WhatsApp',
            'status' => 'novo',
            'pipeline_stage' => 'entrada',
            'lead_score' => $score,
            'estimated_value' => '0',
            'source' => 'WhatsApp',
        ]);
        $lead = ['id' => $leadId, 'name' => $customer['name'] ?? 'Cliente WhatsApp'];
    }

    $name = trim((string)($payload['name'] ?? $payload['nome'] ?? ''));
    if ($name === '') {
        $name = (string)($customer['name'] ?? $lead['name'] ?? 'Cliente WhatsApp');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO whatsapp_conversations
            (lead_id, customer_id, phone, name, remote_jid, attendance_mode, needs_human, lead_score, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        (int)($lead['id'] ?? 0) ?: null,
        (int)($customer['id'] ?? 0) ?: null,
        $phone,
        $name,
        $remoteJid,
        studio_whatsapp_default_mode($studio),
        $needsHuman ? 1 : 0,
        $score,
    ]);

    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_conversations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: [];
}

function studio_whatsapp_update_message_status(array $studio, array $payload): array
{
    $pdo = studio_db($studio);
    $messageId = trim((string)($payload['messageId'] ?? ''));
    $remoteJid = trim((string)($payload['remoteJid'] ?? ''));
    $status = trim((string)($payload['status'] ?? ''));
    if ($messageId === '' || $status === '') {
        return ['ok' => false, 'error' => 'Status sem messageId ou estado.'];
    }

    $stmt = $pdo->prepare('UPDATE whatsapp_messages SET status = ? WHERE message_id = ?');
    $stmt->execute([$status, $messageId]);
    if ($stmt->rowCount() === 0 && $remoteJid !== '') {
        $stmt = $pdo->prepare('UPDATE whatsapp_messages SET status = ? WHERE remote_jid = ? AND direction = "out" ORDER BY id DESC LIMIT 1');
        $stmt->execute([$status, $remoteJid]);
    }

    return ['ok' => true, 'updated' => $stmt->rowCount()];
}

function studio_record_whatsapp_message(array $studio, array $payload): array
{
    if (!empty($payload['statusUpdate'])) {
        return studio_whatsapp_update_message_status($studio, $payload);
    }

    $pdo = studio_db($studio);
    $conversation = studio_upsert_whatsapp_conversation($studio, $payload);
    if (!$conversation) {
        throw new RuntimeException('Nao foi possivel abrir conversa WhatsApp.');
    }

    $messageId = trim((string)($payload['messageId'] ?? ''));
    if ($messageId !== '') {
        $stmt = $pdo->prepare('SELECT id FROM whatsapp_messages WHERE message_id = ? LIMIT 1');
        $stmt->execute([$messageId]);
        if ($stmt->fetchColumn()) {
            return ['ok' => true, 'duplicate' => true, 'conversation_id' => (int)$conversation['id']];
        }
    }

    $fromMe = !empty($payload['fromMe']);
    $direction = $fromMe ? 'out' : 'in';
    $senderType = trim((string)($payload['senderType'] ?? ''));
    if (!in_array($senderType, ['customer', 'human', 'bot', 'system'], true)) {
        $senderType = $fromMe ? 'human' : 'customer';
    }
    $body = trim((string)($payload['mensagem'] ?? $payload['body'] ?? ''));
    $messageType = trim((string)($payload['tipoMensagem'] ?? $payload['messageType'] ?? 'texto')) ?: 'texto';
    $mediaMime = trim((string)($payload['mediaMime'] ?? $payload['media_mime'] ?? ''));
    if ($messageType === 'texto' && $mediaMime !== '') {
        if (str_starts_with($mediaMime, 'image/')) {
            $messageType = 'image';
        } elseif (str_starts_with($mediaMime, 'video/')) {
            $messageType = 'video';
        } elseif (str_starts_with($mediaMime, 'audio/')) {
            $messageType = 'audio';
        } else {
            $messageType = 'document';
        }
    }
    $timestamp = (int)($payload['timestamp'] ?? time());
    if ($timestamp > 2000000000) {
        $timestamp = (int)floor($timestamp / 1000);
    }
    $sentAt = date('Y-m-d H:i:s', $timestamp > 0 ? $timestamp : time());
    $remoteJid = trim((string)($payload['remoteJid'] ?? $payload['jidCompleto'] ?? ''));
    $needsHuman = studio_whatsapp_needs_human($body);
    $hasMedia = !empty($payload['mediaBase64']) || !empty($payload['mediaUrl']);
    $score = studio_whatsapp_lead_score($body, $hasMedia);
    $mediaUrl = trim((string)($payload['mediaUrl'] ?? ''));
    $mediaFileName = trim((string)($payload['mediaFileName'] ?? $payload['media_file_name'] ?? ''));
    $mediaFilePath = trim((string)($payload['mediaFilePath'] ?? $payload['media_file_path'] ?? ''));
    if ($mediaUrl === '' && !empty($payload['mediaBase64'])) {
        $storedMedia = studio_store_whatsapp_media(
            $studio,
            (int)$conversation['id'],
            (string)$payload['mediaBase64'],
            $mediaMime,
            $messageType,
            trim((string)($payload['mediaFileName'] ?? ''))
        );
        $mediaUrl = $storedMedia['relativePath'];
        $mediaFilePath = $storedMedia['relativePath'];
        $mediaFileName = (string)$storedMedia['fileName'];
        if ($mediaMime === '') {
            $mediaMime = (string)$storedMedia['mime'];
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO whatsapp_messages
            (conversation_id, direction, sender_type, body, media_url, media_mime, media_file_name, media_file_path, message_type, message_id, remote_jid, from_me, status, sent_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        (int)$conversation['id'],
        $direction,
        $senderType,
        $body,
        $mediaUrl,
        $mediaMime,
        $mediaFileName !== '' ? $mediaFileName : null,
        $mediaFilePath !== '' ? $mediaFilePath : null,
        $messageType,
        $messageId !== '' ? $messageId : null,
        $remoteJid,
        $fromMe ? 1 : 0,
        $fromMe ? 'sent' : null,
        $sentAt,
    ]);
    if ($messageType === 'audio' && $mediaUrl !== '' && $messageId !== '') {
        studio_attempt_whatsapp_audio_transcription($studio, $messageId, $mediaUrl);
    }

    $preview = $body !== '' ? mb_substr($body, 0, 250) : '[' . $messageType . ']';
    $stmt = $pdo->prepare(
        'UPDATE whatsapp_conversations
         SET remote_jid = COALESCE(NULLIF(?, ""), remote_jid),
             needs_human = GREATEST(needs_human, ?),
             lead_score = GREATEST(COALESCE(lead_score, 0), ?),
             last_message_preview = ?,
             last_message_direction = ?,
             last_message_at = ?,
             updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$remoteJid, $needsHuman ? 1 : 0, $score, $preview, $direction, $sentAt, (int)$conversation['id']]);

    if (!empty($conversation['lead_id'])) {
        $pdo->prepare('UPDATE leads SET lead_score = GREATEST(COALESCE(lead_score, 0), ?), last_contact_at = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$score, $sentAt, (int)$conversation['lead_id']]);
    }

    if (!$fromMe) {
        try {
            studio_process_appointment_confirmation_reply($studio, $conversation, $body);
        } catch (Throwable) {
        }
    }

    $assistantSettings = studio_settings($studio);
    if (!$fromMe && !empty($assistantSettings['assistant_autofill_enabled'])) {
        try {
            $assistantInsights = studio_whatsapp_assistant_insights($studio, $conversation, studio_whatsapp_messages($studio, (int)$conversation['id'], 12, $conversation));
            if (!empty($assistantInsights)) {
                studio_apply_whatsapp_assistant_enrichment($studio, $conversation, $assistantInsights);
                $conversation = studio_find_whatsapp_conversation($studio, (int)$conversation['id']) ?: $conversation;
            }
        } catch (Throwable) {
        }
    }

    if (!$fromMe && (string)($conversation['attendance_mode'] ?? 'human') === 'bot') {
        try {
            studio_update_whatsapp_conversation($studio, [
                'conversation_id' => (int)$conversation['id'],
                'ai_last_status' => 'Analisando com IA...',
            ]);
            $aiResult = studio_whatsapp_ai_reply($studio, $conversation, [
                'body' => $body,
                'mensagem' => $body,
                'from_me' => $fromMe,
                'direction' => $direction,
                'message_type' => $messageType,
                'message_id' => $messageId,
            ]);
            if (empty($aiResult['ok'])) {
                studio_update_whatsapp_conversation($studio, [
                    'conversation_id' => (int)$conversation['id'],
                    'ai_last_status' => 'IA sem resposta: ' . mb_substr((string)($aiResult['error'] ?? 'erro'), 0, 120),
                ]);
            }
        } catch (Throwable $e) {
            studio_update_whatsapp_conversation($studio, [
                'conversation_id' => (int)$conversation['id'],
                'ai_last_status' => 'IA sem resposta: ' . mb_substr($e->getMessage(), 0, 120),
            ]);
        }
    }

    return ['ok' => true, 'conversation_id' => (int)$conversation['id']];
}

function studio_update_whatsapp_message_transcription(array $studio, array $payload): array
{
    $pdo = studio_db($studio);
    $messageId = trim((string)($payload['messageId'] ?? $payload['message_id'] ?? ''));
    $mediaUrl = trim((string)($payload['mediaUrl'] ?? $payload['media_url'] ?? ''));
    $text = trim((string)($payload['text'] ?? ''));
    $error = trim((string)($payload['error'] ?? ''));
    if ($text === '' && $error === '') {
        throw new RuntimeException('Transcricao vazia.');
    }

    $sql = 'UPDATE whatsapp_messages SET ';
    $params = [];
    if ($text !== '') {
        $sql .= 'transcricao = ?, transcript = ?, transcricao_erro = NULL, transcript_error = NULL, ';
        $params[] = $text;
        $params[] = $text;
    } else {
        $sql .= 'transcricao_erro = ?, transcript_error = ?, ';
        $params[] = $error;
        $params[] = $error;
    }
    $sql = rtrim($sql, ', ');
    $sql .= ' WHERE ';
    if ($messageId !== '') {
        $sql .= 'message_id = ?';
        $params[] = $messageId;
    } elseif ($mediaUrl !== '') {
        $sql .= 'media_url = ?';
        $params[] = $mediaUrl;
    } else {
        throw new RuntimeException('Mensagem de audio invalida.');
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return ['ok' => true, 'updated' => $stmt->rowCount()];
}

function studio_whatsapp_attachment_dir(array $studio, int $conversationId = 0): array
{
    $root = realpath(__DIR__ . '/../');
    if (!$root) {
        throw new RuntimeException('Nao consegui localizar o diretório do projeto.');
    }

    $safeStudio = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($studio['slug'] ?? 'studio')) ?: 'studio';
    $safeConversation = $conversationId > 0 ? 'conv_' . $conversationId : 'conv';
    $folder = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'whatsapp-attachments' . DIRECTORY_SEPARATOR . $safeStudio . DIRECTORY_SEPARATOR . $safeConversation;
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        throw new RuntimeException('Nao foi possivel criar a pasta de anexos.');
    }

    return [
        'folder' => $folder,
        'relativePrefix' => 'storage/whatsapp-attachments/' . $safeStudio . '/' . $safeConversation . '/',
    ];
}

function studio_whatsapp_extension_for_mime(string $mime, string $fallbackName = ''): string
{
    $fallbackExt = strtolower((string)pathinfo($fallbackName, PATHINFO_EXTENSION));
    if ($fallbackExt !== '') {
        return $fallbackExt;
    }

    return match (strtolower(trim($mime))) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
        'audio/webm', 'audio/webm; codecs=opus' => 'webm',
        'audio/mpeg' => 'mp3',
        'audio/mp4', 'audio/m4a' => 'm4a',
        'application/pdf' => 'pdf',
        default => '',
    };
}

function studio_store_whatsapp_media(array $studio, int $conversationId, string $base64, string $mime, string $messageType, string $fileName = ''): array
{
    $base64 = trim($base64);
    if ($base64 === '') {
        return ['relativePath' => '', 'mime' => $mime, 'fileName' => $fileName];
    }

    $binary = base64_decode($base64, true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('Nao foi possivel decodificar a midia do WhatsApp.');
    }

    $storage = studio_whatsapp_attachment_dir($studio, $conversationId);
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($fileName !== '' ? $fileName : $messageType, PATHINFO_FILENAME)) ?: ($messageType !== '' ? $messageType : 'arquivo');
    $ext = studio_whatsapp_extension_for_mime($mime, $fileName);
    $stamp = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $storedName = $stamp . '_' . $base . ($ext !== '' ? '.' . $ext : '');
    $dest = $storage['folder'] . DIRECTORY_SEPARATOR . $storedName;

    if (file_put_contents($dest, $binary) === false) {
        throw new RuntimeException('Nao foi possivel salvar a midia recebida.');
    }

    return [
        'relativePath' => $storage['relativePrefix'] . $storedName,
        'mime' => $mime,
        'fileName' => $fileName !== '' ? $fileName : $storedName,
    ];
}

function studio_whatsapp_media_absolute_path(string $mediaPath): ?string
{
    $mediaPath = trim($mediaPath);
    if ($mediaPath === '') {
        return null;
    }

    if (preg_match('~^[A-Za-z]:[\\\\/]~', $mediaPath) || str_starts_with($mediaPath, DIRECTORY_SEPARATOR)) {
        return is_file($mediaPath) ? $mediaPath : null;
    }

    $candidate = realpath(__DIR__ . '/../' . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $mediaPath), DIRECTORY_SEPARATOR));
    return ($candidate && is_file($candidate)) ? $candidate : null;
}

function studio_attempt_whatsapp_audio_transcription(array $studio, string $messageId, string $mediaPath): void
{
    $messageId = trim($messageId);
    $absolutePath = studio_whatsapp_media_absolute_path($mediaPath);
    if ($messageId === '' || !$absolutePath) {
        return;
    }

    $script = realpath(__DIR__ . '/../tools/transcription/transcribe_audio.py');
    if (!$script) {
        return;
    }

    $stdout = tempnam(sys_get_temp_dir(), 'wa_auto_transcribe_out_');
    $stderr = tempnam(sys_get_temp_dir(), 'wa_auto_transcribe_err_');
    if ($stdout === false || $stderr === false) {
        return;
    }

    $command = 'py -3 ' . escapeshellarg($script) . ' ' . escapeshellarg($absolutePath) . ' small auto > ' . escapeshellarg($stdout) . ' 2> ' . escapeshellarg($stderr);
    $output = [];
    $exitCode = null;
    exec($command, $output, $exitCode);
    $json = is_file($stdout) ? trim((string)file_get_contents($stdout)) : '';
    $error = is_file($stderr) ? trim((string)file_get_contents($stderr)) : '';
    if (is_file($stdout)) {
        @unlink($stdout);
    }
    if (is_file($stderr)) {
        @unlink($stderr);
    }

    $decoded = json_decode($json, true);
    try {
        if (is_array($decoded) && !empty($decoded['ok']) && !empty($decoded['text'])) {
            studio_update_whatsapp_message_transcription($studio, [
                'messageId' => $messageId,
                'mediaUrl' => $mediaPath,
                'text' => (string)$decoded['text'],
            ]);
            return;
        }

        studio_update_whatsapp_message_transcription($studio, [
            'messageId' => $messageId,
            'mediaUrl' => $mediaPath,
            'error' => (string)($decoded['error'] ?? $error ?: 'Nao foi possivel reconhecer fala nesse audio'),
        ]);
    } catch (Throwable $ignore) {
    }
}

function studio_send_whatsapp_message(array $studio, array $data): array
{
    $phone = normalize_phone((string)($data['phone'] ?? $data['numero'] ?? ''));
    $conversationId = (int)($data['conversation_id'] ?? 0);
    if ($phone === '' && $conversationId > 0) {
        $conversationById = studio_find_whatsapp_conversation($studio, $conversationId);
        $phone = normalize_phone((string)($conversationById['phone'] ?? ''));
    }
    $message = trim((string)($data['message'] ?? $data['mensagem'] ?? ''));
    $upload = studio_prepare_whatsapp_attachment($studio, $data, $_FILES ?? [], $conversationId);
    if ($phone === '' || ($message === '' && empty($upload['base64']))) {
        throw new RuntimeException('Informe telefone, mensagem ou anexo.');
    }

    $conversation = studio_find_whatsapp_conversation_by_phone($studio, $phone);
    $jid = trim((string)($conversation['remote_jid'] ?? ''));
    $sessionKey = studio_session_key($studio);
    $payload = [
        'numero' => $phone,
        'jid' => $jid,
        'mensagem' => $message,
    ];
    if (!empty($upload['base64'])) {
        $payload['media'] = [
            'base64' => $upload['base64'],
            'mime' => $upload['mime'],
            'fileName' => $upload['fileName'],
            'kind' => $upload['kind'],
        ];
    }

    $result = studio_whatsapp_request($studio, 'POST', '/studios/' . rawurlencode($sessionKey) . '/send', $payload, studio_whatsapp_ai_timeout($studio));

    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? $result['erro'] ?? 'Nao foi possivel enviar pelo WhatsApp.'));
    }

    studio_record_whatsapp_message($studio, [
        'numero' => $phone,
        'mensagem' => $message,
        'fromMe' => true,
        'senderType' => 'human',
        'messageId' => $result['messageId'] ?? null,
        'remoteJid' => $result['remoteJid'] ?? $jid,
        'timestamp' => time(),
        'tipoMensagem' => !empty($upload['kind']) ? $upload['kind'] : 'texto',
        'mediaUrl' => $upload['relativePath'] ?? '',
        'mediaMime' => $upload['mime'] ?? '',
        'mediaFileName' => $upload['fileName'] ?? '',
    ]);

    return $result;
}

function studio_openai_config(array $studio): array
{
    $settings = studio_settings($studio);
    $provider = trim((string)($settings['ai_provider'] ?? 'ollama'));
    if ($provider !== 'openai' && $provider !== 'ollama') {
        $provider = 'ollama';
    }
    $apiKey = trim((string)($settings['openai_api_key'] ?? getenv('OPENAI_API_KEY') ?: ''));
    $model = trim((string)($settings['openai_model'] ?? $settings['ai_model'] ?? 'llama3.2:3b'));
    $model = $model !== '' ? $model : 'llama3.2:3b';
    $baseUrl = trim((string)($settings['ai_api_base_url'] ?? ''));
    if ($provider === 'ollama') {
        $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : 'http://localhost:11434/v1';
        if ($apiKey === '') {
            $apiKey = 'ollama';
        }
    }
    if ($provider === 'openai') {
        $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://api.openai.com/v1';
    }
    $systemPrompt = trim((string)($settings['ai_whatsapp_prompt'] ?? ''));
    if ($systemPrompt === '') {
        $systemPrompt = <<<TXT
Você é o assistente do WhatsApp de um estúdio de tatuagem no Brasil.
Responda sempre em português do Brasil, com tom humano, caloroso e objetivo.
Você representa o estúdio e fala como uma atendente real de tatuagem, nunca como bot genérico.
Use linguagem natural de estúdio: tatuagem, agenda, sinal, horário, encaixe, referência, retoque.
Não use inglês, não misture termos em inglês e não explique que você é uma IA.
Não repita a mesma saudação, a mesma estrutura ou a mesma frase de abertura.
Se a conversa já estiver em andamento, continue do ponto atual sem recomeçar do zero.
Se a pessoa pedir agendamento, responda pensando em confirmação, sinal, data e horário.
Se a pessoa perguntar por disponibilidade, fale só do que foi informado no contexto da agenda.
Se faltar informação importante, faça no máximo uma pergunta curta e direta.
Se a pessoa pedir humano, sinalize isso com educação e sem excesso de texto.
Não invente preços, horários, artistas, políticas, prazos ou disponibilidade.
Prefira respostas curtas, úteis e específicas para um estúdio de tatuagem.
TXT;
    }

    return [
        'provider' => $provider,
        'api_key' => $apiKey,
        'model' => $model,
        'base_url' => $baseUrl,
        'system_prompt' => $systemPrompt,
    ];
}

function studio_openai_text(string $apiKey, string $model, string $systemPrompt, string $userPrompt, string $baseUrl = 'https://api.openai.com/v1', ?int $timeoutSeconds = null): array
{
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'Chave da OpenAI nao configurada.'];
    }

    $isOllama = (bool)preg_match('#(localhost|127\.0\.0\.1|::1):11434#i', $baseUrl);
    $responseText = '';
    if ($isOllama) {
        $body = [
            'model' => $model,
            'stream' => false,
            'options' => [
                'temperature' => 0.1,
                'top_p' => 0.9,
                'repeat_penalty' => 1.15,
            ],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt . "\n\nResponda somente com JSON valido neste formato: {\"reply_text\":\"...\",\"needs_human\":false,\"lead_score_delta\":0,\"summary\":\"...\"}"],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        $ch = curl_init(rtrim(preg_replace('#/v1/?$#', '', $baseUrl), '/') . '/api/chat');
    } else {
        $body = [
            'model' => $model,
            'temperature' => 0.1,
            'top_p' => 0.9,
            'presence_penalty' => 0.2,
            'frequency_penalty' => 0.35,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt . "\n\nResponda somente com JSON valido neste formato: {\"reply_text\":\"...\",\"needs_human\":false,\"lead_score_delta\":0,\"summary\":\"...\"}"],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        $ch = curl_init(rtrim($baseUrl, '/') . '/chat/completions');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => $timeoutSeconds ?? ($isOllama ? 180 : 60),
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno || $raw === false) {
        return ['ok' => false, 'error' => $error ?: 'Falha na chamada da IA.'];
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Resposta invalida da IA.'];
    }
    if ($status >= 400) {
        return ['ok' => false, 'error' => (string)($json['error']['message'] ?? ('Erro HTTP ' . $status))];
    }

    if ($isOllama) {
        $responseText = trim((string)($json['message']['content'] ?? ''));
    } else {
        $responseText = trim((string)($json['choices'][0]['message']['content'] ?? ''));
    }
    $content = $responseText;
    if ($content === '') {
        return ['ok' => false, 'error' => 'A IA nao retornou texto.'];
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded) && preg_match('/\{.*\}/s', $content, $matches)) {
        $decoded = json_decode($matches[0], true);
    }
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Nao consegui ler o JSON da IA: ' . mb_substr($content, 0, 120)];
    }

    return [
        'ok' => true,
        'reply_text' => trim((string)($decoded['reply_text'] ?? '')),
        'needs_human' => !empty($decoded['needs_human']),
        'lead_score_delta' => (int)($decoded['lead_score_delta'] ?? 0),
        'summary' => trim((string)($decoded['summary'] ?? '')),
    ];
}

function studio_meta_ads_request(string $version, string $path, string $accessToken, array $query = [], string $method = 'GET', ?array $body = null, ?int $timeoutSeconds = null): array
{
    $version = trim($version) !== '' ? trim($version) : 'v22.0';
    $version = preg_replace('/^v?/', 'v', $version) ?: 'v22.0';
    $path = '/' . ltrim($path, '/');
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => $timeoutSeconds ?? 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno || $raw === false) {
        return ['ok' => false, 'error' => $error ?: 'Falha na chamada da Meta API.', 'status' => $status];
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Resposta invalida da Meta API.', 'status' => $status, 'raw' => $raw];
    }
    if ($status >= 400 || !empty($json['error'])) {
        return ['ok' => false, 'error' => (string)($json['error']['message'] ?? ('Erro HTTP ' . $status)), 'status' => $status, 'json' => $json];
    }

    return ['ok' => true, 'status' => $status, 'json' => $json, 'raw' => $raw];
}

function studio_meta_ads_mask_secret(string $value, int $keep = 6): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $tail = mb_substr($value, max(0, mb_strlen($value) - $keep));
    return str_repeat('•', max(0, mb_strlen($value) - mb_strlen($tail))) . $tail;
}

function studio_meta_ads_test_connection(array $studio): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $accountId = preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? '')));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0'));
    if ($token === '') {
        return ['ok' => false, 'error' => 'Access Token da Meta nao configurado.'];
    }
    if ($accountId === '') {
        return ['ok' => false, 'error' => 'ID da conta de anuncio nao configurado.'];
    }

    $me = studio_meta_ads_request($version, '/me', $token, ['fields' => 'id,name']);
    if (!$me['ok']) {
        return $me;
    }

    $account = studio_meta_ads_request($version, '/act_' . $accountId, $token, [
        'fields' => 'id,name,account_status,currency,timezone_name,amount_spent,balance',
    ]);
    if (!$account['ok']) {
        return $account;
    }

    $campaigns = studio_meta_ads_request($version, '/act_' . $accountId . '/campaigns', $token, [
        'fields' => 'id,name,status,objective,created_time,updated_time',
        'limit' => 3,
    ]);

    return [
        'ok' => true,
        'version' => $version,
        'account_id' => 'act_' . $accountId,
        'me' => $me['json'] ?? [],
        'account' => $account['json'] ?? [],
        'campaigns' => $campaigns['json'] ?? [],
        'campaigns_ok' => !empty($campaigns['ok']),
    ];
}

function studio_whatsapp_official_test_connection(array $studio): array
{
    $settings = studio_settings($studio);
    if (studio_whatsapp_provider($studio) !== 'official') {
        return ['ok' => false, 'error' => 'O provedor ativo nao e a API oficial.'];
    }

    $appId = trim((string)($settings['whatsapp_official_app_id'] ?? ''));
    $accessToken = trim((string)($settings['whatsapp_official_access_token'] ?? ''));
    $verifyToken = trim((string)($settings['whatsapp_official_verify_token'] ?? ''));
    $callbackUrl = trim((string)($settings['whatsapp_official_callback_url'] ?? ''));
    $version = trim((string)($settings['whatsapp_official_api_version'] ?? 'v25.0'));
    $mode = strtolower(trim((string)($settings['whatsapp_official_mode'] ?? 'production')));
    if (!in_array($mode, ['production', 'sandbox'], true)) {
        $mode = 'production';
    }
    $phoneNumberId = trim((string)($mode === 'sandbox' ? ($settings['whatsapp_official_test_phone_number_id'] ?? '') : ($settings['whatsapp_official_phone_number_id'] ?? '')));
    $wabaId = trim((string)($mode === 'sandbox' ? ($settings['whatsapp_official_test_business_account_id'] ?? '') : ($settings['whatsapp_official_business_account_id'] ?? '')));

    foreach ([
        'App ID' => $appId,
        'Phone Number ID' => $phoneNumberId,
        'Access Token' => $accessToken,
        'Webhook Verify Token' => $verifyToken,
        'Callback URL' => $callbackUrl,
        'WABA ID' => $wabaId,
    ] as $label => $value) {
        if (trim($value) === '') {
            return ['ok' => false, 'error' => 'Faltou preencher: ' . $label . '.'];
        }
    }

    $app = studio_meta_ads_request($version, '/app', $accessToken, ['fields' => 'id,name']);
    $phone = studio_meta_ads_request($version, '/' . rawurlencode($phoneNumberId), $accessToken, ['fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status']);
    $waba = studio_meta_ads_request($version, '/' . rawurlencode($wabaId), $accessToken, ['fields' => 'id,name,currency,timezone_id']);

    $ok = !empty($app['ok']) && !empty($phone['ok']) && !empty($waba['ok']);
    return [
        'ok' => $ok,
        'version' => $version,
        'mode' => $mode,
        'app' => $app,
        'phone_number' => $phone,
        'waba' => $waba,
        'summary' => $ok ? 'Configuração oficial validada.' : 'Um ou mais checks da API oficial falharam.',
    ];
}

function studio_whatsapp_official_send_text(array $studio, string $toPhone, string $message): array
{
    $settings = studio_settings($studio);
    if (studio_whatsapp_provider($studio) !== 'official') {
        return ['ok' => false, 'error' => 'O provedor ativo nao e a API oficial.'];
    }

    $crmAccessToken = trim((string)($settings['whatsapp_official_access_token'] ?? ''));
    $crmVersion = trim((string)($settings['whatsapp_official_api_version'] ?? 'v23.0'));
    $crmPhoneNumberId = trim((string)($settings['whatsapp_official_phone_number_id'] ?? '1186818641175044'));
    $zapConfig = studio_whatsapp_zap_local_config();

    $useZapLocalConfig = !empty($zapConfig)
        && trim((string)($zapConfig['access_token'] ?? '')) !== ''
        && trim((string)($zapConfig['api_version'] ?? '')) !== ''
        && trim((string)($zapConfig['phone_number_id'] ?? '')) !== '';

    $source = $useZapLocalConfig ? 'zap_local_config' : 'crm_settings';
    $accessToken = $useZapLocalConfig ? trim((string)$zapConfig['access_token']) : $crmAccessToken;
    $version = $useZapLocalConfig ? trim((string)$zapConfig['api_version']) : $crmVersion;
    $phoneNumberId = $useZapLocalConfig ? trim((string)$zapConfig['phone_number_id']) : $crmPhoneNumberId;
    $toPhone = preg_replace('/\D+/', '', $toPhone) ?: '';
    $message = trim($message);
    $diagnostic = [
        'source' => $source,
        'crm' => [
            'api_version' => $crmVersion,
            'phone_number_id' => $crmPhoneNumberId,
            'token_preview' => studio_mask_token_preview($crmAccessToken),
            'token_length' => strlen($crmAccessToken),
        ],
        'zap_local_config' => [
            'exists' => !empty($zapConfig),
            'api_version' => (string)($zapConfig['api_version'] ?? ''),
            'phone_number_id' => (string)($zapConfig['phone_number_id'] ?? ''),
            'token_preview' => studio_mask_token_preview((string)($zapConfig['access_token'] ?? '')),
            'token_length' => strlen((string)($zapConfig['access_token'] ?? '')),
            'same_token_as_crm' => hash_equals($crmAccessToken, (string)($zapConfig['access_token'] ?? '')),
            'same_phone_number_id_as_crm' => $crmPhoneNumberId === (string)($zapConfig['phone_number_id'] ?? ''),
            'same_api_version_as_crm' => $crmVersion === (string)($zapConfig['api_version'] ?? ''),
        ],
        'send' => [
            'to_phone' => $toPhone,
            'message_length' => strlen($message),
        ],
    ];

    if ($phoneNumberId === '' || $accessToken === '' || $toPhone === '' || $message === '') {
        return ['ok' => false, 'error' => 'Faltam dados para enviar a mensagem.', 'diagnostic' => $diagnostic];
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $toPhone,
        'type' => 'text',
        'text' => ['body' => $message],
    ];

    $result = studio_meta_ads_request($version, '/' . rawurlencode($phoneNumberId) . '/messages', $accessToken, [], 'POST', $payload);
    $result['diagnostic'] = $diagnostic;
    if (empty($result['ok'])) {
        return $result;
    }

    return $result;
}

function studio_meta_ads_insights_summary(array $studio, int $days = 30): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $accountId = preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? '')));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0'));
    if ($token === '' || $accountId === '') {
        return ['ok' => false, 'error' => 'Meta Ads sem token ou conta de anuncio configurados.'];
    }
    $days = max(1, min(365, $days));
    $response = studio_meta_ads_request($version, '/act_' . $accountId . '/insights', $token, [
        'fields' => 'spend,impressions,clicks,ctr,cpc,cpm,reach',
        'date_preset' => $days <= 7 ? 'last_7d' : ($days <= 30 ? 'last_30d' : 'last_90d'),
        'level' => 'account',
        'limit' => 1,
    ]);
    if (!$response['ok']) {
        return $response;
    }
    $items = is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
    $row = $items[0] ?? [];
    return [
        'ok' => true,
        'account_id' => 'act_' . $accountId,
        'days' => $days,
        'spend' => (float)($row['spend'] ?? 0),
        'impressions' => (int)($row['impressions'] ?? 0),
        'clicks' => (int)($row['clicks'] ?? 0),
        'ctr' => (float)($row['ctr'] ?? 0),
        'cpc' => (float)($row['cpc'] ?? 0),
        'cpm' => (float)($row['cpm'] ?? 0),
        'reach' => (int)($row['reach'] ?? 0),
    ];
}

function studio_whatsapp_ai_reply(array $studio, array $conversation, array $newMessage): array
{
    $settings = studio_settings($studio);
    if (empty($settings['ai_enabled'])) {
        return ['ok' => false, 'error' => 'IA desativada nas configuracoes.'];
    }
    if ((string)($conversation['attendance_mode'] ?? 'human') !== 'bot') {
        return ['ok' => false, 'error' => 'Conversa nao esta em modo IA.'];
    }
    if (!empty($newMessage['from_me']) || (string)($newMessage['direction'] ?? '') === 'out') {
        return ['ok' => false, 'error' => 'Mensagem de saida nao gera resposta.'];
    }

    $config = studio_openai_config($studio);
    if ($config['api_key'] === '') {
        return ['ok' => false, 'error' => 'Configure a chave da OpenAI nas configuracoes do estudio.'];
    }

    $pdo = studio_db($studio);
    $stmt = $pdo->prepare(
        'SELECT direction, sender_type, body, message_type, media_mime, media_file_name, sent_at
         FROM whatsapp_messages
         WHERE conversation_id = ?
         ORDER BY id DESC
         LIMIT 6'
    );
    $stmt->execute([(int)$conversation['id']]);
    $history = array_reverse($stmt->fetchAll() ?: []);
    $historyLines = [];
    foreach ($history as $item) {
        $role = (string)($item['direction'] ?? 'in') === 'out' ? 'Atendente' : 'Cliente';
        $text = trim((string)($item['body'] ?? ''));
        if ($text === '') {
            $text = '[' . (string)($item['message_type'] ?? 'texto') . ']';
        }
        $sentAt = trim((string)($item['sent_at'] ?? ''));
        $historyLines[] = $role . ($sentAt !== '' ? ' (' . $sentAt . ')' : '') . ': ' . $text;
    }

    $studioRules = trim((string)($settings['business_rules'] ?? ''));
    $scheduleDays = trim((string)($settings['appointment_work_days'] ?? '1,2,3,4,5'));
    $scheduleSlots = trim((string)($settings['appointment_time_slots'] ?? '10:00,15:00'));
    $durationMinutes = (int)($settings['appointment_duration_minutes'] ?? 300);
    $studioName = (string)($studio['name'] ?? 'Estudio');
    $customerName = trim((string)($conversation['name'] ?? $conversation['customer_name'] ?? $conversation['lead_name'] ?? ''));
    $customerId = (int)($conversation['customer_id'] ?? 0);
    $leadId = (int)($conversation['lead_id'] ?? 0);
    $customerActivity = $customerId > 0 ? studio_customer_activity($studio, $customerId) : ['leads' => [], 'appointments' => [], 'conversations' => []];
    $leadData = $leadId > 0 ? studio_find_lead($studio, $leadId) : null;
    $latestMessages = implode("\n- ", array_slice($historyLines, -6));
    $availability = studio_schedule_available_slots($studio, 14);
    $messageText = trim((string)($newMessage['body'] ?? $newMessage['mensagem'] ?? ''));
    $dateContext = studio_whatsapp_extract_date_context($messageText, $studio);
    $availableNotes = [];
    $occupiedNotes = [];
    foreach ($availability as $day) {
        if (!empty($day['allowed']) && !empty($day['free_slots'])) {
            $availableNotes[] = $day['date'] . ' => ' . implode(', ', array_slice($day['free_slots'], 0, 3));
        }
        if (!empty($day['booked'])) {
            $occupiedNotes[] = $day['date'] . ' => ' . implode(', ', array_map(static fn(array $appt): string => $appt['time'] . ($appt['customer_name'] !== '' ? ' (' . $appt['customer_name'] . ')' : ''), array_slice($day['booked'], 0, 3)));
        }
    }
    $availabilityPreview = $availableNotes ? implode("\n- ", array_slice($availableNotes, 0, 6)) : 'Sem vagas livres no recorte rapido.';
    $occupiedPreview = $occupiedNotes ? implode("\n- ", array_slice($occupiedNotes, 0, 6)) : 'Sem ocupacoes no recorte rapido.';
    $exactDateBlock = "Nao foi citada uma data especifica.";
    $nextAvailableHint = 'Sem vaga futura encontrada no recorte rapido.';
    if (is_array($dateContext)) {
        $freeSlots = array_values(array_map('strval', $dateContext['free_slots'] ?? []));
        $bookedSlots = array_values(array_map(static fn(array $appt): string => trim((string)($appt['time'] ?? '')), $dateContext['booked'] ?? []));
        $nextFreeSlot = $freeSlots[0] ?? '';
        $nextAvailableHint = $nextFreeSlot !== '' ? ($dateContext['date'] . ' ' . $nextFreeSlot) : $nextAvailableHint;
        $exactDateBlock = "Data citada pelo cliente: " . $dateContext['date'] . "\n"
            . "Esta data esta " . (!empty($dateContext['allowed']) ? 'dentro' : 'fora') . " dos dias permitidos do estúdio.\n"
            . "Vagas livres exatas nesse dia: " . ($freeSlots ? implode(', ', $freeSlots) : 'nenhuma') . "\n"
            . "Proximo horario livre real no recorte: " . $nextAvailableHint . "\n"
            . "Horarios ocupados nesse dia: " . ($bookedSlots ? implode(', ', array_slice($bookedSlots, 0, 3)) : 'nenhum') . "\n"
            . "Regra: se vagas livres exatas estiverem vazias, responda apenas que esse dia esta lotado e mostre o proximo horario livre real.";
    }
    $customerContextLines = [];
    if ($customerId > 0) {
        $customerContextLines[] = 'Cliente vinculado na base: sim (ID ' . $customerId . ')';
        $customerContextLines[] = 'Resumo do historico deste cliente: ' . count($customerActivity['appointments']) . ' agendamentos, ' . count($customerActivity['leads']) . ' leads, ' . count($customerActivity['conversations']) . ' conversas.';
        if (!empty($customerActivity['appointments'])) {
            $customerContextLines[] = 'Ultimos agendamentos do cliente:';
            foreach (array_slice($customerActivity['appointments'], 0, 3) as $appointment) {
                $customerContextLines[] = '- ' . format_date_pt((string)$appointment['appointment_date']) . ' as ' . substr((string)$appointment['start_time'], 0, 5) . ' · ' . (($appointment['artist_name'] ?? '') ?: 'sem tatuador') . ' · ' . (($appointment['status'] ?? '') ?: 'sem status');
            }
        }
    } else {
        $customerContextLines[] = 'Cliente vinculado na base: nao.';
    }
    if (is_array($leadData) && !empty($leadData)) {
        $customerContextLines[] = 'Lead associado: ' . (($leadData['name'] ?? '') ?: 'sem nome') . ' · status ' . (($leadData['status'] ?? 'novo') ?: 'novo') . ' · etapa ' . (($leadData['pipeline_stage'] ?? 'entrada') ?: 'entrada') . ' · nota ' . ((string)($leadData['lead_score'] ?? '-') ?: '-') . '/10';
        if (!empty($leadData['interest'])) {
            $customerContextLines[] = 'Interesse atual: ' . (string)$leadData['interest'];
        }
        if (!empty($leadData['estimated_value'])) {
            $customerContextLines[] = 'Valor estimado atual: ' . format_money((float)$leadData['estimated_value']);
        }
    }
    $aiModel = $config['model'];
    $prompt = "Contexto do estudio:\n"
        . "Nome do estudio: " . $studioName . "\n"
        . "Regras do estudio: " . ($studioRules !== '' ? $studioRules : 'Sem regras extras.') . "\n"
        . "Agenda do estudio: dias " . $scheduleDays . ' | horarios ' . $scheduleSlots . ' | duracao ' . $durationMinutes . " minutos\n"
        . "Proximas vagas livres reais (data => horarios livres):\n- " . $availabilityPreview . "\n"
        . "Proximos horarios ocupados reais:\n- " . $occupiedPreview . "\n"
        . $exactDateBlock . "\n"
        . "Nome do cliente: " . ($customerName !== '' ? $customerName : 'Nao informado') . "\n"
        . "Telefone/contato: " . trim((string)($conversation['phone'] ?? '')) . "\n"
        . "Modo atual da conversa: " . trim((string)($conversation['attendance_mode'] ?? 'human')) . "\n"
        . "Status comercial: " . trim((string)($conversation['lead_status'] ?? 'em_conversa')) . ' / ' . trim((string)($conversation['lead_pipeline_stage'] ?? 'em_conversa')) . "\n"
        . "Nota do lead: " . trim((string)($conversation['lead_score'] ?? '0')) . "/10\n"
        . "Contexto da ficha e relacionamento com este cliente:\n- " . implode("\n- ", $customerContextLines) . "\n"
        . "Ultima mensagem do cliente: " . trim((string)($newMessage['body'] ?? $newMessage['mensagem'] ?? '')) . "\n"
        . "Historico recente da conversa:\n- " . ($latestMessages !== '' ? $latestMessages : 'Sem historico recente.') . "\n\n"
        . "Regras de resposta:\n"
        . "- Responda como atendente de tatuagem, sem soar robotico.\n"
        . "- Seja direto, util e natural. Use no maximo 2 frases curtas.\n"
        . "- Nao repita a mesma saudacao ou frase de abertura.\n"
        . "- Nao diga 'estou aqui para ajudar' nem variações parecidas.\n"
        . "- Nao use respostas genéricas de assistente, tipo 'como posso ajudar'.\n"
        . "- Se a ultima mensagem for curta demais, responda de forma curta e contextual, sem enrolar.\n"
        . "- Se a conversa ja teve saudacao, nao cumprimente de novo.\n"
        . "- Se o cliente ja fez uma pergunta objetiva, responda objetivamente.\n"
        . "- Se a pessoa perguntou de agendamento, puxe para data, horario e sinal.\n"
        . "- Se perguntou disponibilidade, use somente os dias e horarios informados acima e as vagas livres reais listadas acima.\n"
        . "- Se a ultima mensagem citar uma data especifica, consulte o bloco 'Data citada pelo cliente' e responda apenas com base nele.\n"
        . "- Se a data citada estiver lotada, diga apenas que o dia esta lotado e ofereca o proximo horario livre real.\n"
        . "- Nunca invente horario. Se o horario nao estiver na lista de vagas livres reais, nao o sugira.\n"
        . "- Nunca diga que existe vaga em uma data que tenha vagas livres exatas vazias no contexto.\n"
        . "- Nunca revele dados de outros clientes. Use apenas dados do cliente atual, da conversa atual e das vagas livres reais.\n"
        . "- Quando responder sobre agendamento, se existir um proximo horario livre real, cite só ele.\n"
        . "- Se faltar contexto, faça uma unica pergunta curta.\n"
        . "- Se precisar de humano, marque needs_human=true e explique em uma frase curta.\n"
        . "- Nao invente preco, disponibilidade, artista ou politica.\n"
        . "- Fale como estúdio de tatuagem do Brasil, nao como central de suporte.\n"
        . "- Se o cliente disser que quer tatuar, abra caminho para orçamento, referencia ou agenda.\n"
        . "- Se o cliente perguntar nome, valor ou prazo, responda com o dado disponível ou pergunte de forma curta.\n"
        . "- Use um tom de estúdio: direto, humano, profissional e levemente caloroso.\n"
        . "- Exemplos de estilo:\n"
        . "  * Cliente: 'oi' -> Resposta: 'Oi! Me conta o que você quer tatuar e eu te ajudo por aqui.'\n"
        . "  * Cliente: 'quero agendar' -> Resposta: 'Perfeito. Me manda a referência e a data que você prefere, que eu vejo os próximos passos.'\n"
        . "  * Cliente: 'qual o valor?' -> Resposta: 'Me manda a ideia ou a referência da tattoo que eu te passo o melhor caminho.'\n\n"
        . "- Quando falar de disponibilidade, use este formato mental:\n"
        . "  * se houver vaga: 'Tem vaga sim. O proximo horario livre real é DD/MM às HH:MM.'\n"
        . "  * se nao houver vaga: 'Nao tem vaga nesse dia. O proximo horario livre real é DD/MM às HH:MM.'\n"
        . "- Evite listar varias vagas, varios nomes ou varios detalhes. Entregue só o proximo passo mais util.\n"
        . "Responda somente com JSON valido e curto. Se precisar de humano, diga isso no campo needs_human.";

    $result = studio_openai_text($config['api_key'], $aiModel, $config['system_prompt'], $prompt, (string)($config['base_url'] ?? 'https://api.openai.com/v1'));
    if (empty($result['ok'])) {
        return $result;
    }

    $replyText = trim((string)$result['reply_text']);
    if ($replyText === '') {
        return ['ok' => false, 'error' => 'A IA devolveu resposta vazia.'];
    }
    $replyText = preg_replace('/\s+/', ' ', $replyText) ?? $replyText;
    if (mb_strlen($replyText) > 220) {
        $parts = preg_split('/(?<=[.!?])\s+/u', $replyText) ?: [$replyText];
        $replyText = trim(implode(' ', array_slice($parts, 0, 2)));
        if (mb_strlen($replyText) > 220) {
            $replyText = mb_substr($replyText, 0, 220);
        }
    }

    $reply = studio_send_whatsapp_message($studio, [
        'conversation_id' => (int)$conversation['id'],
        'phone' => (string)($conversation['phone'] ?? ''),
        'message' => $replyText,
    ]);

    $currentScore = (int)($conversation['lead_score'] ?? 0);
    $scoreDelta = max(0, (int)($result['lead_score_delta'] ?? 0));
    $newScore = max(0, min(10, $currentScore + $scoreDelta));
    $aiStatus = $result['needs_human'] ? 'IA sinalizou atendimento humano' : 'IA respondeu automaticamente';

    studio_update_whatsapp_conversation($studio, [
        'conversation_id' => (int)$conversation['id'],
        'attendance_mode' => 'bot',
        'needs_human' => !empty($result['needs_human']) ? 1 : 0,
        'lead_score' => $newScore,
        'ai_last_status' => $aiStatus,
    ]);

    return [
        'ok' => true,
        'reply' => $reply,
        'ai_last_status' => $aiStatus,
        'needs_human' => !empty($result['needs_human']),
    ];
}

function studio_prepare_whatsapp_attachment(array $studio, array $data, array $files, int $conversationId = 0): array
{
    $file = $files['media_file'] ?? null;
    if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['base64' => '', 'mime' => '', 'fileName' => '', 'kind' => '', 'relativePath' => ''];
    }

    if (!empty($file['error'])) {
        throw new RuntimeException('Nao foi possivel ler o anexo enviado.');
    }

    $maxSize = 20 * 1024 * 1024;
    if (!empty($file['size']) && (int)$file['size'] > $maxSize) {
        throw new RuntimeException('Anexo muito grande. Use um arquivo de ate 20MB.');
    }

    $mime = trim((string)($file['type'] ?? ''));
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($file['tmp_name']);
    }
    $mime = $mime !== '' ? $mime : 'application/octet-stream';
    $fileName = trim((string)($file['name'] ?? 'arquivo'));
    $fileName = $fileName !== '' ? $fileName : 'arquivo';
    $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
    $kind = 'document';
    if (str_starts_with($mime, 'image/')) {
        $kind = 'image';
    } elseif (str_starts_with($mime, 'video/')) {
        $kind = 'video';
    } elseif (str_starts_with($mime, 'audio/')) {
        $kind = 'audio';
    }

    $storage = studio_whatsapp_attachment_dir($studio, $conversationId);

    $base = pathinfo($fileName, PATHINFO_FILENAME);
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $base) ?: 'arquivo';
    $stamp = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $storedName = $stamp . '_' . $base . ($ext !== '' ? '.' . $ext : '');
    $dest = $storage['folder'] . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Nao foi possivel salvar o anexo.');
    }

    $relativePath = $storage['relativePrefix'] . $storedName;
    $base64 = base64_encode((string)file_get_contents($dest));

    return [
        'base64' => $base64,
        'mime' => $mime,
        'fileName' => $fileName,
        'kind' => $kind,
        'relativePath' => $relativePath,
    ];
}

function studio_report_data(array $studio): array
{
    $pdo = studio_db($studio);

    return [
        'leads_by_status' => $pdo->query('SELECT status, COUNT(*) AS qtd, COALESCE(SUM(estimated_value), 0) AS total FROM leads GROUP BY status ORDER BY qtd DESC')->fetchAll() ?: [],
        'leads_by_source' => $pdo->query('SELECT source, COUNT(*) AS qtd, COALESCE(SUM(estimated_value), 0) AS total FROM leads GROUP BY source ORDER BY qtd DESC LIMIT 12')->fetchAll() ?: [],
        'appointments_by_status' => $pdo->query('SELECT status, COUNT(*) AS qtd, COALESCE(SUM(value), 0) AS total FROM appointments GROUP BY status ORDER BY qtd DESC')->fetchAll() ?: [],
        'appointments_by_month' => $pdo->query("SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS month, COUNT(*) AS qtd, COALESCE(SUM(value), 0) AS total FROM appointments GROUP BY DATE_FORMAT(appointment_date, '%Y-%m') ORDER BY month DESC LIMIT 12")->fetchAll() ?: [],
        'expenses_by_category' => $pdo->query('SELECT category, COUNT(*) AS qtd, COALESCE(SUM(amount), 0) AS total FROM expenses GROUP BY category ORDER BY total DESC LIMIT 12')->fetchAll() ?: [],
    ];
}

function studio_data_assistant_context(array $studio): array
{
    $pdo = studio_db($studio);
    return [
        'stats' => studio_stats($studio),
        'settings' => studio_settings($studio),
        'artists' => studio_list_artists($studio),
        'leads_by_status' => $pdo->query('SELECT status, COUNT(*) AS qtd, COALESCE(SUM(estimated_value), 0) AS total FROM leads GROUP BY status ORDER BY qtd DESC')->fetchAll() ?: [],
        'leads_by_source' => $pdo->query('SELECT source, COUNT(*) AS qtd, COALESCE(SUM(estimated_value), 0) AS total FROM leads GROUP BY source ORDER BY qtd DESC LIMIT 12')->fetchAll() ?: [],
        'hot_leads' => $pdo->query('SELECT name, phone, interest, status, pipeline_stage, lead_score, estimated_value, source FROM leads ORDER BY COALESCE(lead_score, 0) DESC, updated_at DESC LIMIT 10')->fetchAll() ?: [],
        'upcoming_appointments' => studio_upcoming_appointments($studio, 12),
        'appointments_by_artist' => $pdo->query(
            "SELECT COALESCE(ta.name, 'Sem tatuador') AS artist, COUNT(*) AS qtd, COALESCE(SUM(a.value), 0) AS total
             FROM appointments a
             LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
             WHERE a.appointment_date >= CURDATE()
             GROUP BY COALESCE(ta.name, 'Sem tatuador')
             ORDER BY qtd DESC"
        )->fetchAll() ?: [],
        'finance' => studio_finance_summary($studio),
        'whatsapp' => studio_whatsapp_summary($studio),
        'whatsapp_conversations' => array_slice(studio_list_whatsapp_conversations($studio), 0, 12),
    ];
}

function studio_data_assistant_answer(array $studio, string $question): array
{
    $question = trim($question);
    if ($question === '') {
        throw new RuntimeException('Digite uma pergunta para o assistente.');
    }

    if (!plan_allows('ai_data_assistant')) {
        throw new RuntimeException('Os recursos de IA estão disponíveis no plano Avançado.');
    }

    $context = studio_data_assistant_context($studio);
    $pdo = studio_db($studio);
    $config = studio_openai_config($studio);
    $tz = new DateTimeZone('America/Sao_Paulo');
    $lower = function_exists('mb_strtolower') ? mb_strtolower($question, 'UTF-8') : strtolower($question);
    $isAgendaQuestion = str_contains($lower, 'agenda') || str_contains($lower, 'agendamento') || str_contains($lower, 'horario') || str_contains($lower, 'horários') || str_contains($lower, 'calendario') || str_contains($lower, 'calendário') || str_contains($lower, 'vaga') || str_contains($lower, 'vagas') || str_contains($lower, 'livre') || str_contains($lower, 'disponivel') || str_contains($lower, 'disponível') || str_contains($lower, 'marcar') || str_contains($lower, 'remarcar');
    $isFinanceQuestion = str_contains($lower, 'finance') || str_contains($lower, 'fatur') || str_contains($lower, 'caixa') || str_contains($lower, 'despesa') || str_contains($lower, 'resultado') || str_contains($lower, 'ticket') || str_contains($lower, 'receita') || str_contains($lower, 'custo') || str_contains($lower, 'lucro') || str_contains($lower, 'prejuizo') || str_contains($lower, 'prejuízo');
    $isWhatsappQuestion = str_contains($lower, 'whatsapp') || str_contains($lower, 'conversa') || str_contains($lower, 'atencao') || str_contains($lower, 'atenção') || str_contains($lower, 'humano') || str_contains($lower, 'mensagem') || str_contains($lower, 'chat') || str_contains($lower, 'respond') || str_contains($lower, 'resposta');
    $isLeadQuestion = str_contains($lower, 'lead') || str_contains($lower, 'funil') || str_contains($lower, 'pipeline') || str_contains($lower, 'orçamento') || str_contains($lower, 'orcamento') || str_contains($lower, 'prioridade') || str_contains($lower, 'quente') || str_contains($lower, 'oportunidade') || str_contains($lower, 'prospec') || str_contains($lower, 'venda');
    $isCustomerQuestion = str_contains($lower, 'cliente') || str_contains($lower, 'clientes') || str_contains($lower, 'cadastro') || str_contains($lower, 'contato') || str_contains($lower, 'contatos');
    $isArtistQuestion = str_contains($lower, 'tatuador') || str_contains($lower, 'artista') || str_contains($lower, 'tatuadores') || str_contains($lower, 'equipe');
    $wantsNextAppointmentName = (str_contains($lower, 'próximo') || str_contains($lower, 'proximo') || str_contains($lower, 'seguinte') || str_contains($lower, 'primeiro')) && (str_contains($lower, 'cliente') || str_contains($lower, 'agend') || str_contains($lower, 'atendimento') || str_contains($lower, 'horário') || str_contains($lower, 'horario') || str_contains($lower, 'consulta') || str_contains($lower, 'sessão') || str_contains($lower, 'sessao') || str_contains($lower, 'cita') || str_contains($lower, 'marcado'));
    $isAgendaQuestion = $isAgendaQuestion || $wantsNextAppointmentName;
    $hasRecognizedTopic = $isAgendaQuestion || $isFinanceQuestion || $isWhatsappQuestion || $isLeadQuestion || $isCustomerQuestion || $isArtistQuestion;
    $needsClarification = !$hasRecognizedTopic;

    $summarizeAppointment = static function (array $appointment): array {
        return [
            'data' => (string)($appointment['appointment_date'] ?? ''),
            'hora_inicio' => substr((string)($appointment['start_time'] ?? ''), 0, 5),
            'hora_fim' => substr((string)($appointment['end_time'] ?? ''), 0, 5),
            'cliente' => (string)(($appointment['customer_name'] ?? '') ?: ($appointment['lead_name'] ?? '') ?: ($appointment['title'] ?? '')),
            'tatuador' => (string)(($appointment['artist_name'] ?? '') ?: 'tatuador nao definido'),
            'status' => (string)($appointment['status'] ?? ''),
            'valor' => (float)($appointment['value'] ?? 0),
            'sinal' => (float)($appointment['deposit_value'] ?? 0),
        ];
    };
    $summarizeLead = static function (array $lead): array {
        return [
            'nome' => (string)(($lead['name'] ?? '') ?: ($lead['phone'] ?? 'Sem nome')),
            'telefone' => (string)($lead['phone'] ?? ''),
            'interesse' => (string)($lead['interest'] ?? ''),
            'status' => (string)($lead['status'] ?? ''),
            'etapa' => (string)($lead['pipeline_stage'] ?? ''),
            'nota' => (int)($lead['lead_score'] ?? 0),
            'valor_estimado' => (float)($lead['estimated_value'] ?? 0),
            'origem' => (string)($lead['source'] ?? ''),
        ];
    };
    $summarizeConversation = static function (array $conversation): array {
        return [
            'nome' => (string)(($conversation['customer_name'] ?? '') ?: ($conversation['lead_name'] ?? '') ?: ($conversation['name'] ?? '') ?: ($conversation['phone'] ?? '')),
            'telefone' => (string)($conversation['phone'] ?? ''),
            'modo' => (string)($conversation['attendance_mode'] ?? 'human'),
            'pediu_humano' => !empty($conversation['needs_human']),
            'vinculo' => !empty($conversation['customer_id']) || !empty($conversation['lead_id']),
            'nota' => (int)($conversation['lead_score'] ?? 0),
            'ultima_mensagem' => (string)($conversation['last_message_preview'] ?? ''),
        ];
    };

    $dateContext = studio_whatsapp_extract_date_context($question, $studio);
    $dateFocus = null;
    if (is_array($dateContext) && !empty($dateContext['date'])) {
        $stmt = $pdo->prepare(
            "SELECT a.*, COALESCE(c.name, a.title) AS customer_name, l.name AS lead_name, ta.name AS artist_name
             FROM appointments a
             LEFT JOIN customers c ON c.id = a.customer_id
             LEFT JOIN leads l ON l.id = a.lead_id
             LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
             WHERE a.appointment_date = ?
               AND a.status NOT IN ('cancelado')
             ORDER BY a.start_time ASC, a.id ASC"
        );
        $stmt->execute([(string)$dateContext['date']]);
        $dayAppointments = array_map($summarizeAppointment, $stmt->fetchAll() ?: []);
        $dateFocus = [
            'data' => (string)$dateContext['date'],
            'rotulo' => (string)($dateContext['label'] ?? ''),
            'permitido' => !empty($dateContext['allowed']),
            'vagas_livres_exatas' => array_values(array_map('strval', $dateContext['free_slots'] ?? [])),
            'horarios_ocupados' => array_values(array_map(static fn(array $item): string => trim((string)($item['time'] ?? '')) . ((string)($item['customer_name'] ?? '') !== '' ? ' · ' . (string)$item['customer_name'] : ''), $dateContext['booked'] ?? [])),
            'agendamentos_do_dia' => $dayAppointments,
        ];
    }

    $availability = studio_schedule_available_slots($studio, 14);
    $availabilityPreview = array_slice(array_map(static function (array $day): array {
        return [
            'data' => (string)($day['date'] ?? ''),
            'rotulo' => (string)($day['label'] ?? ''),
            'permitido' => !empty($day['allowed']),
            'vagas_livres' => array_slice(array_values(array_map('strval', $day['free_slots'] ?? [])), 0, 4),
            'horarios_ocupados' => array_slice(array_values(array_map(static fn(array $item): string => trim((string)($item['time'] ?? '')) . ((string)($item['customer_name'] ?? '') !== '' ? ' · ' . (string)$item['customer_name'] : ''), $day['booked'] ?? [])), 0, 4),
        ];
    }, $availability), 0, 7);

    $assistantContext = [
        'pergunta' => $question,
        'intencao' => $wantsNextAppointmentName ? 'agenda_proximo' : ($isAgendaQuestion ? 'agenda' : ($isFinanceQuestion ? 'financeiro' : ($isWhatsappQuestion ? 'whatsapp' : ($isLeadQuestion ? 'leads' : ($isCustomerQuestion ? 'clientes' : ($isArtistQuestion ? 'tatuadores' : 'geral')))))),
        'estudio' => [
            'nome' => (string)($studio['name'] ?? 'Estudio'),
            'regras' => trim((string)($context['settings']['business_rules'] ?? '')),
            'dias_agenda' => trim((string)($context['settings']['appointment_work_days'] ?? '1,2,3,4,5')),
            'horarios_agenda' => trim((string)($context['settings']['appointment_time_slots'] ?? '10:00,15:00')),
            'duracao_atendimento_minutos' => (int)($context['settings']['appointment_duration_minutes'] ?? 300),
        ],
        'resumo' => $context['stats'],
    ];
    if ($isAgendaQuestion || (!$isFinanceQuestion && !$isWhatsappQuestion)) {
        $assistantContext['agenda'] = [
            'proximos_agendamentos' => array_slice(array_map($summarizeAppointment, $context['upcoming_appointments'] ?: []), 0, 5),
            'agenda_por_tatuador' => array_map(static fn(array $row): array => [
                'tatuador' => (string)($row['artist'] ?? ''),
                'quantidade' => (int)($row['qtd'] ?? 0),
                'valor_total' => (float)($row['total'] ?? 0),
            ], array_slice($context['appointments_by_artist'] ?: [], 0, 5)),
            'visao_proximos_dias' => array_slice($availabilityPreview, 0, 5),
        ];
        if ($dateFocus) {
            $assistantContext['agenda']['recorte_data_citada'] = $dateFocus;
        }
    }
    if ($isWhatsappQuestion || (!$isAgendaQuestion && !$isFinanceQuestion)) {
        $assistantContext['whatsapp'] = [
            'resumo' => $context['whatsapp'],
            'conversas' => array_slice(array_map($summarizeConversation, $context['whatsapp_conversations'] ?: []), 0, 5),
        ];
    }
    if ($isFinanceQuestion || (!$isAgendaQuestion && !$isWhatsappQuestion)) {
        $assistantContext['financeiro'] = [
            'agenda_mes' => (float)($context['finance']['appointments_month'] ?? 0),
            'despesas_mes' => (float)($context['finance']['expenses_month'] ?? 0),
            'resultado_mes' => (float)($context['finance']['balance_month'] ?? 0),
            'por_categoria' => array_map(static fn(array $row): array => [
                'categoria' => (string)($row['category'] ?? 'Geral'),
                'quantidade' => (int)($row['qtd'] ?? 0),
                'total' => (float)($row['total'] ?? 0),
            ], array_slice($context['finance']['by_category'] ?: [], 0, 5)),
        ];
    }
    if (!$isAgendaQuestion && !$isFinanceQuestion && !$isWhatsappQuestion) {
        $assistantContext['leads'] = [
            'prioritarios' => array_slice(array_map($summarizeLead, $context['hot_leads'] ?: []), 0, 5),
            'por_status' => array_slice(array_map(static fn(array $row): array => [
                'status' => (string)($row['status'] ?? ''),
                'quantidade' => (int)($row['qtd'] ?? 0),
                'total' => (float)($row['total'] ?? 0),
            ], $context['leads_by_status'] ?: []), 0, 5),
            'por_origem' => array_slice(array_map(static fn(array $row): array => [
                'origem' => (string)($row['source'] ?? ''),
                'quantidade' => (int)($row['qtd'] ?? 0),
                'total' => (float)($row['total'] ?? 0),
            ], $context['leads_by_source'] ?: []), 0, 5),
        ];
    }

    $assistantContextJson = json_encode($assistantContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assistantContextJson = is_string($assistantContextJson) ? $assistantContextJson : '{}';

    $configPrompt = trim((string)($context['settings']['business_rules'] ?? ''));
    $systemPrompt = "Você é o assistente interno de dados do CRM de um estúdio de tatuagem no Brasil.\n"
        . "Responda apenas com base no contexto fornecido no JSON.\n"
        . "Se a pergunta for curta e objetiva, responda de forma curta e objetiva, sem acrescentar tópicos não solicitados.\n"
        . "Se a pergunta for sobre agenda ou disponibilidade, use o recorte de data e os agendamentos listados, sem inventar horário.\n"
        . "Se a data citada estiver lotada, diga isso de forma curta e aponte o próximo horário livre real, se houver.\n"
        . "Se a pergunta não for claramente sobre agenda, finanças, WhatsApp, leads, clientes ou tatuadores, responda que precisa de mais contexto e sugira reformular.\n"
        . "Se faltar dado, diga que não há informação suficiente.\n"
        . "Nunca exponha dados de outros clientes além do recorte fornecido.\n"
        . "Responda em português do Brasil, com tom humano, direto e útil, sem dizer que você é IA.\n"
        . ($configPrompt !== '' ? "\nRegras adicionais do estúdio:\n" . $configPrompt . "\n" : '')
        . "\nFormato esperado: uma resposta curta, clara e prática. Se não houver dados suficientes, responda explicitamente com isso e faça uma única pergunta de clarificação.";

    if ($config['api_key'] !== '') {
        $aiResult = studio_openai_text($config['api_key'], $config['model'], $systemPrompt, $assistantContextJson, $config['base_url'], 60);
        $replyText = trim((string)($aiResult['reply_text'] ?? ''));
        $looksGeneric = $replyText !== '' && (
            str_contains(function_exists('mb_strtolower') ? mb_strtolower($replyText, 'UTF-8') : strtolower($replyText), 'com base nos dados atuais')
            || str_contains(function_exists('mb_strtolower') ? mb_strtolower($replyText, 'UTF-8') : strtolower($replyText), 'sugestao pratica')
            || str_contains(function_exists('mb_strtolower') ? mb_strtolower($replyText, 'UTF-8') : strtolower($replyText), 'priorizar contatos')
        );
        if (!empty($aiResult['ok']) && $replyText !== '' && !$looksGeneric) {
            return [
                'question' => $question,
                'answer' => $replyText,
                'context' => $context,
                'generated_at' => date('Y-m-d H:i:s'),
                'source' => 'ai',
            ];
        }
    }

    if ($needsClarification) {
        $lines = [];
        $lines[] = 'Eu consigo ajudar com agenda, finanças, WhatsApp, leads, clientes e tatuadores.';
        $lines[] = 'A sua pergunta ficou ampla demais para eu responder com precisão.';
        $lines[] = 'Tente reformular com um foco mais claro, por exemplo: agenda do dia, próximos horários livres, leads quentes, conversas sem resposta ou resultado do mês.';
        return [
            'question' => $question,
            'answer' => implode("\n", $lines),
            'context' => $context,
            'generated_at' => date('Y-m-d H:i:s'),
            'source' => 'fallback',
        ];
    }

    if ($isAgendaQuestion) {
        if ($wantsNextAppointmentName) {
            $next = $context['upcoming_appointments'][0] ?? null;
            if (is_array($next) && !empty($next)) {
                $customerName = (string)(($next['customer_name'] ?? '') ?: ($next['lead_name'] ?? '') ?: ($next['title'] ?? ''));
                if ($customerName === '') {
                    $customerName = 'Sem nome';
                }
                $artistName = (string)(($next['artist_name'] ?? '') ?: 'tatuador nao definido');
                $answer = 'O próximo cliente agendado é ' . $customerName . ', em ' . format_date_pt((string)($next['appointment_date'] ?? '')) . ' às ' . substr((string)($next['start_time'] ?? ''), 0, 5) . ', com ' . $artistName . '.';
            } else {
                $answer = 'Não encontrei um próximo agendamento no recorte atual.';
            }
            return [
                'question' => $question,
                'answer' => $answer,
                'context' => $context,
                'generated_at' => date('Y-m-d H:i:s'),
                'source' => 'fallback',
            ];
        }
        if ($dateFocus) {
            $dayAppointments = (array)($dateFocus['agendamentos_do_dia'] ?? []);
            $countAppointments = count($dayAppointments);
            $dayLabel = (string)($dateFocus['rotulo'] ?? format_date_pt((string)($dateFocus['data'] ?? '')));
            $leadNames = [];
            foreach (array_slice($dayAppointments, 0, 5) as $appointment) {
                $leadName = trim((string)($appointment['cliente'] ?? ''));
                if ($leadName !== '') {
                    $leadNames[] = $leadName;
                }
            }
            if ($countAppointments > 0) {
                $answer = 'Sim, ' . $dayLabel . ' tem ' . $countAppointments . ' cliente' . ($countAppointments === 1 ? '' : 's') . ' agendado' . ($countAppointments === 1 ? '' : 's') . '.';
                if ($leadNames) {
                    $answer .= ' Os nomes que encontrei são: ' . implode('; ', array_values(array_unique($leadNames))) . '.';
                }
            } else {
                $answer = 'Não encontrei clientes agendados para ' . $dayLabel . '.';
            }
            return [
                'question' => $question,
                'answer' => $answer,
                'context' => $context,
                'generated_at' => date('Y-m-d H:i:s'),
                'source' => 'fallback',
            ];
        }
        if (str_contains($lower, 'livre') || str_contains($lower, 'vaga')) {
            $nextFreeSlot = '';
            $nextFreeDay = '';
            foreach ($availabilityPreview as $day) {
                $freeSlots = array_values(array_filter(array_map('trim', (array)($day['vagas_livres'] ?? [])), static fn(string $slot): bool => $slot !== ''));
                if ($freeSlots) {
                    $nextFreeDay = (string)($day['rotulo'] ?? $day['data'] ?? '');
                    $nextFreeSlot = $freeSlots[0];
                    break;
                }
            }
            if ($nextFreeSlot !== '') {
                $answer = 'O próximo horário livre que encontrei é ' . $nextFreeDay . ' às ' . $nextFreeSlot . '. Se quiser, eu também posso olhar um dia específico para você.';
            } else {
                $answer = 'Não encontrei um horário livre rápido no recorte atual. Se quiser, eu posso analisar uma data específica.';
            }
            return [
                'question' => $question,
                'answer' => $answer,
                'context' => $context,
                'generated_at' => date('Y-m-d H:i:s'),
                'source' => 'fallback',
            ];
        }
        $appointments = $context['upcoming_appointments'];
        $parts = [];
        $parts[] = 'No recorte rápido, encontrei ' . count($appointments) . ' próximos agendamentos.';
        foreach (array_slice($appointments, 0, 6) as $appointment) {
            $parts[] = format_date_pt((string)$appointment['appointment_date']) . ' às ' . substr((string)$appointment['start_time'], 0, 5) . ': ' . (($appointment['customer_name'] ?? '') ?: $appointment['title']) . ' com ' . (($appointment['artist_name'] ?? '') ?: 'tatuador nao definido') . ' (' . $appointment['status'] . ').';
        }
        if ($context['appointments_by_artist']) {
            $artistParts = [];
            foreach ($context['appointments_by_artist'] as $row) {
                $artistParts[] = $row['artist'] . ': ' . (int)$row['qtd'] . ' agendamentos, ' . format_money($row['total'] ?? 0);
            }
            $parts[] = 'Por tatuador, o recorte atual mostra ' . implode('; ', $artistParts) . '.';
        }
        $answer = implode(' ', $parts);
        return [
            'question' => $question,
            'answer' => $answer,
            'context' => $context,
            'generated_at' => date('Y-m-d H:i:s'),
            'source' => 'fallback',
        ];
    } elseif ($isFinanceQuestion) {
        $finance = $context['finance'];
        $answer = 'No mês, a agenda soma ' . format_money($finance['appointments_month']) . ', as despesas somam ' . format_money($finance['expenses_month']) . ' e o resultado simples fica em ' . format_money($finance['balance_month']) . '.';
        foreach (array_slice($finance['by_category'], 0, 6) as $row) {
            $answer .= ' ' . 'A categoria ' . (($row['category'] ?? '') ?: 'Geral') . ' concentra ' . format_money($row['total'] ?? 0) . '.';
        }
        return [
            'question' => $question,
            'answer' => $answer,
            'context' => $context,
            'generated_at' => date('Y-m-d H:i:s'),
            'source' => 'fallback',
        ];
    } elseif ($isWhatsappQuestion) {
        $wa = $context['whatsapp'];
        $answer = 'No WhatsApp, encontrei ' . $wa['total'] . ' conversas, sendo ' . $wa['bot'] . ' em IA e ' . $wa['human'] . ' em humano. ' . $wa['needs_human'] . ' conversas estão pedindo humano.';
        $names = [];
        foreach (array_slice($context['whatsapp_conversations'], 0, 6) as $conversation) {
            $name = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: $conversation['phone']));
            $names[] = $name . ' (nota ' . (($conversation['lead_score'] ?? '-') ?: '-') . '/10, modo ' . $conversation['attendance_mode'] . ', última mensagem: ' . (($conversation['last_message_preview'] ?? '') ?: '-') . ')';
        }
        if ($names) {
            $answer .= ' As conversas mais visíveis agora são: ' . implode('; ', $names) . '.';
        }
        return [
            'question' => $question,
            'answer' => $answer,
            'context' => $context,
            'generated_at' => date('Y-m-d H:i:s'),
            'source' => 'fallback',
        ];
    } elseif ($isCustomerQuestion) {
        $stats = $context['stats'];
        $answer = '';
        $customerLabel = ((int)$stats['customers'] === 1) ? '1 cliente cadastrado' : ((int)$stats['customers'] . ' clientes cadastrados');
        $leadLabel = ((int)$stats['leads'] === 1) ? '1 lead cadastrado' : ((int)$stats['leads'] . ' leads cadastrados');
        if (str_contains($lower, 'lead')) {
            $answer = 'Hoje você tem ' . $leadLabel . '.';
        } elseif (str_contains($lower, 'cadastro') || str_contains($lower, 'total')) {
            $answer = 'Hoje você tem ' . $customerLabel . ' e ' . $context['whatsapp']['total'] . ' conversas no WhatsApp.';
        } else {
            $answer = 'Hoje você tem ' . $customerLabel . '.';
        }
        return [
            'question' => $question,
            'answer' => $answer,
            'context' => $context,
            'generated_at' => date('Y-m-d H:i:s'),
            'source' => 'fallback',
        ];
    } elseif ($isArtistQuestion) {
        $artists = array_slice($context['artists'] ?: [], 0, 8);
        if (!$artists) {
            $answer = 'Não encontrei tatuadores cadastrados no momento.';
        } else {
            $names = [];
            foreach ($artists as $artist) {
                $names[] = (($artist['name'] ?? '') ?: 'Sem nome') . ' (' . (($artist['specialty'] ?? '') ?: 'sem especialidade') . ', ' . (!empty($artist['is_active']) ? 'ativo' : 'inativo') . ')';
            }
            $answer = 'Os tatuadores cadastrados são: ' . implode('; ', $names) . '.';
        }
        return [
            'question' => $question,
            'answer' => $answer,
            'context' => $context,
            'generated_at' => date('Y-m-d H:i:s'),
            'source' => 'fallback',
        ];
    } elseif ($isLeadQuestion) {
        if (str_contains($lower, 'quente') || str_contains($lower, 'prior')) {
            $hotLeads = array_slice($context['hot_leads'], 0, 5);
            $names = [];
            foreach ($hotLeads as $lead) {
                $names[] = (($lead['name'] ?? '') ?: ($lead['phone'] ?? 'Sem nome')) . ' (' . (($lead['lead_score'] ?? '-') ?: '-') . '/10, ' . (($lead['interest'] ?? '') ?: 'sem interesse descrito') . ', status ' . $lead['status'] . ')';
            }
            if (!$hotLeads) {
                $answer = 'Não encontrei leads quentes no momento.';
            } else {
                $answer = 'Os leads mais promissores agora são: ' . implode('; ', $names) . '.';
            }
        } elseif (str_contains($lower, 'quant') || str_contains($lower, 'tem')) {
            $answer = 'Você tem ' . $context['stats']['leads'] . ' leads no funil e ' . format_money($context['stats']['open_value']) . ' em oportunidades abertas.';
        } else {
            $answer = 'Você tem ' . $context['stats']['leads'] . ' leads no funil e ' . format_money($context['stats']['open_value']) . ' em oportunidades abertas.';
        }
        return [
            'question' => $question,
            'answer' => $answer,
            'context' => $context,
            'generated_at' => date('Y-m-d H:i:s'),
            'source' => 'fallback',
        ];
    }

    $stats = $context['stats'];
    $leadLabel = ((int)$stats['leads'] === 1) ? '1 lead' : ((int)$stats['leads'] . ' leads');
    $customerLabel = ((int)$stats['customers'] === 1) ? '1 cliente' : ((int)$stats['customers'] . ' clientes');
    $appointmentLabel = ((int)$stats['appointments'] === 1) ? '1 próximo agendamento' : ((int)$stats['appointments'] . ' próximos agendamentos');
    $answer = 'Hoje, o estúdio tem ' . $leadLabel . ', ' . $customerLabel . ', ' . $appointmentLabel . ' e ' . format_money($stats['month_revenue'] - $stats['month_expenses']) . ' de resultado simples no mês.';
    return [
        'question' => $question,
        'answer' => $answer,
        'context' => $context,
        'generated_at' => date('Y-m-d H:i:s'),
        'source' => 'fallback',
    ];
}

function studio_save_customer(array $studio, array $data): int
{
    $pdo = studio_db($studio);
    studio_ensure_customer_columns($studio);
    $id = (int)($data['id'] ?? 0);
    $values = studio_customer_payload_values($data);
    $columns = studio_customer_columns();

    if ($id > 0) {
        $assignments = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', $columns));
        $stmt = $pdo->prepare('UPDATE customers SET ' . $assignments . ', updated_at = NOW() WHERE id = ?');
        $stmt->execute([...$values, $id]);
        return $id;
    }

    $limit = plan_limit('max_clients');
    if ($limit > 0 && studio_customer_count($studio) >= $limit) {
        throw new RuntimeException('Seu plano atual permite até ' . $limit . ' clientes/leads cadastrados. Para continuar cadastrando novos contatos, altere para um plano superior.');
    }

    $stmt = $pdo->prepare('INSERT INTO customers (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ', NOW(), NOW())');
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function studio_customer_columns(): array
{
    return [
        'name',
        'phone',
        'email',
        'instagram',
        'birth_date',
        'document_number',
        'gender',
        'occupation',
        'address_zip',
        'address_street',
        'address_number',
        'address_complement',
        'address_neighborhood',
        'address_city',
        'address_state',
        'address_reference',
        'emergency_contact_name',
        'emergency_contact_phone',
        'allergies',
        'medications',
        'health_conditions',
        'skin_conditions',
        'pregnant_or_breastfeeding',
        'keloid_history',
        'anticoagulants',
        'diabetes',
        'healing_issues',
        'body_area',
        'tattoo_size',
        'reference_style',
        'reference_link',
        'previous_tattoos',
        'pain_tolerance',
        'marketing_opt_in',
        'marketing_channels',
        'sms_opt_in',
        'whatsapp_opt_in',
        'email_opt_in',
        'push_opt_in',
        'social_network_opt_in',
        'social_networks',
        'share_before_after_opt_in',
        'data_processing_consent',
        'health_data_consent',
        'truthfulness_confirmed',
        'notes',
    ];
}

function studio_customer_payload_values(array $data): array
{
    $optIn = static function (mixed $value): int {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'on', 'yes', 'sim', 'checked'], true) ? 1 : 0;
    };
    $checkboxes = static function (array $keys) use ($data, $optIn): array {
        $values = [];
        foreach ($keys as $key) {
            $values[] = $optIn($data[$key] ?? 0);
        }
        return $values;
    };
    return [
        trim((string)($data['name'] ?? '')),
        trim((string)($data['phone'] ?? '')),
        strtolower(trim((string)($data['email'] ?? ''))),
        trim((string)($data['instagram'] ?? '')),
        trim((string)($data['birth_date'] ?? '')) ?: null,
        trim((string)($data['document_number'] ?? '')),
        trim((string)($data['gender'] ?? '')),
        trim((string)($data['occupation'] ?? '')),
        trim((string)($data['address_zip'] ?? '')),
        trim((string)($data['address_street'] ?? '')),
        trim((string)($data['address_number'] ?? '')),
        trim((string)($data['address_complement'] ?? '')),
        trim((string)($data['address_neighborhood'] ?? '')),
        trim((string)($data['address_city'] ?? '')),
        trim((string)($data['address_state'] ?? '')),
        trim((string)($data['address_reference'] ?? '')),
        trim((string)($data['emergency_contact_name'] ?? '')),
        trim((string)($data['emergency_contact_phone'] ?? '')),
        trim((string)($data['allergies'] ?? '')),
        trim((string)($data['medications'] ?? '')),
        trim((string)($data['health_conditions'] ?? '')),
        trim((string)($data['skin_conditions'] ?? '')),
        trim((string)($data['pregnant_or_breastfeeding'] ?? '')),
        trim((string)($data['keloid_history'] ?? '')),
        trim((string)($data['anticoagulants'] ?? '')),
        trim((string)($data['diabetes'] ?? '')),
        trim((string)($data['healing_issues'] ?? '')),
        trim((string)($data['body_area'] ?? '')),
        trim((string)($data['tattoo_size'] ?? '')),
        trim((string)($data['reference_style'] ?? '')),
        trim((string)($data['reference_link'] ?? '')),
        trim((string)($data['previous_tattoos'] ?? '')),
        trim((string)($data['pain_tolerance'] ?? '')),
        $optIn($data['marketing_opt_in'] ?? 0),
        trim((string)($data['marketing_channels'] ?? '')),
        $optIn($data['sms_opt_in'] ?? 0),
        $optIn($data['whatsapp_opt_in'] ?? 0),
        $optIn($data['email_opt_in'] ?? 0),
        $optIn($data['push_opt_in'] ?? 0),
        $optIn($data['social_network_opt_in'] ?? 0),
        trim((string)($data['social_networks'] ?? '')),
        $optIn($data['share_before_after_opt_in'] ?? 0),
        $optIn($data['data_processing_consent'] ?? 0),
        $optIn($data['health_data_consent'] ?? 0),
        $optIn($data['truthfulness_confirmed'] ?? 0),
        trim((string)($data['notes'] ?? '')),
    ];
}

function studio_ensure_customer_columns(array $studio): void
{
    static $done = [];
    $studioId = (int)($studio['id'] ?? 0);
    if ($studioId > 0 && isset($done[$studioId])) {
        return;
    }
    try {
        $pdo = studio_db($studio);
        $pdo->exec('ALTER TABLE customers
            ADD COLUMN IF NOT EXISTS birth_date DATE NULL AFTER instagram,
            ADD COLUMN IF NOT EXISTS document_number VARCHAR(60) NULL AFTER birth_date,
            ADD COLUMN IF NOT EXISTS gender VARCHAR(40) NULL AFTER document_number,
            ADD COLUMN IF NOT EXISTS occupation VARCHAR(120) NULL AFTER gender,
            ADD COLUMN IF NOT EXISTS address_zip VARCHAR(20) NULL AFTER occupation,
            ADD COLUMN IF NOT EXISTS address_street VARCHAR(180) NULL AFTER address_zip,
            ADD COLUMN IF NOT EXISTS address_number VARCHAR(30) NULL AFTER address_street,
            ADD COLUMN IF NOT EXISTS address_complement VARCHAR(120) NULL AFTER address_number,
            ADD COLUMN IF NOT EXISTS address_neighborhood VARCHAR(120) NULL AFTER address_complement,
            ADD COLUMN IF NOT EXISTS address_city VARCHAR(120) NULL AFTER address_neighborhood,
            ADD COLUMN IF NOT EXISTS address_state VARCHAR(40) NULL AFTER address_city,
            ADD COLUMN IF NOT EXISTS address_reference VARCHAR(180) NULL AFTER address_state,
            ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(160) NULL AFTER address_reference,
            ADD COLUMN IF NOT EXISTS emergency_contact_phone VARCHAR(40) NULL AFTER emergency_contact_name,
            ADD COLUMN IF NOT EXISTS allergies TEXT NULL AFTER emergency_contact_phone,
            ADD COLUMN IF NOT EXISTS medications TEXT NULL AFTER allergies,
            ADD COLUMN IF NOT EXISTS health_conditions TEXT NULL AFTER medications,
            ADD COLUMN IF NOT EXISTS skin_conditions TEXT NULL AFTER health_conditions,
            ADD COLUMN IF NOT EXISTS pregnant_or_breastfeeding VARCHAR(80) NULL AFTER skin_conditions,
            ADD COLUMN IF NOT EXISTS keloid_history VARCHAR(120) NULL AFTER pregnant_or_breastfeeding,
            ADD COLUMN IF NOT EXISTS anticoagulants VARCHAR(120) NULL AFTER keloid_history,
            ADD COLUMN IF NOT EXISTS diabetes VARCHAR(120) NULL AFTER anticoagulants,
            ADD COLUMN IF NOT EXISTS healing_issues VARCHAR(160) NULL AFTER diabetes,
            ADD COLUMN IF NOT EXISTS body_area VARCHAR(160) NULL AFTER healing_issues,
            ADD COLUMN IF NOT EXISTS tattoo_size VARCHAR(40) NULL AFTER body_area,
            ADD COLUMN IF NOT EXISTS reference_style VARCHAR(160) NULL AFTER tattoo_size,
            ADD COLUMN IF NOT EXISTS reference_link VARCHAR(255) NULL AFTER reference_style,
            ADD COLUMN IF NOT EXISTS previous_tattoos TEXT NULL AFTER reference_link,
            ADD COLUMN IF NOT EXISTS pain_tolerance VARCHAR(40) NULL AFTER previous_tattoos,
            ADD COLUMN IF NOT EXISTS marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER pain_tolerance,
            ADD COLUMN IF NOT EXISTS marketing_channels VARCHAR(120) NULL AFTER marketing_opt_in,
            ADD COLUMN IF NOT EXISTS sms_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER marketing_channels,
            ADD COLUMN IF NOT EXISTS whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_opt_in,
            ADD COLUMN IF NOT EXISTS email_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_opt_in,
            ADD COLUMN IF NOT EXISTS push_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER email_opt_in,
            ADD COLUMN IF NOT EXISTS social_network_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER push_opt_in,
            ADD COLUMN IF NOT EXISTS social_networks VARCHAR(180) NULL AFTER social_network_opt_in,
            ADD COLUMN IF NOT EXISTS share_before_after_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER social_networks,
            ADD COLUMN IF NOT EXISTS data_processing_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER share_before_after_opt_in,
            ADD COLUMN IF NOT EXISTS health_data_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER data_processing_consent,
            ADD COLUMN IF NOT EXISTS truthfulness_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER health_data_consent');
    } catch (Throwable) {
    }
    if ($studioId > 0) {
        $done[$studioId] = true;
    }
}

function studio_ensure_lead_public_update_token_column(array $studio): void
{
    static $done = [];
    $studioId = (int)($studio['id'] ?? 0);
    if ($studioId > 0 && isset($done[$studioId])) {
        return;
    }
    try {
        $pdo = studio_db($studio);
        $pdo->exec('ALTER TABLE leads ADD COLUMN IF NOT EXISTS public_update_token VARCHAR(64) NULL AFTER source');
    } catch (Throwable) {
    }
    if ($studioId > 0) {
        $done[$studioId] = true;
    }
}

function studio_ensure_lead_public_update_token(array $studio, int $leadId): string
{
    studio_ensure_lead_public_update_token_column($studio);
    if ($leadId <= 0) {
        return '';
    }
    $pdo = studio_db($studio);
    $stmt = $pdo->prepare('SELECT public_update_token FROM leads WHERE id = ? LIMIT 1');
    $stmt->execute([$leadId]);
    $token = trim((string)($stmt->fetchColumn() ?: ''));
    if ($token !== '') {
        $customerStmt = $pdo->prepare('SELECT customer_id FROM leads WHERE id = ? LIMIT 1');
        $customerStmt->execute([$leadId]);
        studio_upsert_public_lead_link($studio, $leadId, $token, (int)($customerStmt->fetchColumn() ?: 0));
        return $token;
    }
    $token = bin2hex(random_bytes(16));
    $update = $pdo->prepare('UPDATE leads SET public_update_token = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([$token, $leadId]);
    studio_upsert_public_lead_link($studio, $leadId, $token, (int)($pdo->query('SELECT customer_id FROM leads WHERE id = ' . (int)$leadId . ' LIMIT 1')->fetchColumn() ?: 0));
    return $token;
}

function studio_save_lead(array $studio, array $data): int
{
    $pdo = studio_db($studio);
    studio_ensure_lead_public_update_token_column($studio);
    $id = (int)($data['id'] ?? 0);
    $values = [
        (int)($data['customer_id'] ?? 0) ?: null,
        trim((string)($data['name'] ?? '')),
        trim((string)($data['phone'] ?? '')),
        trim((string)($data['interest'] ?? '')),
        trim((string)($data['status'] ?? 'novo')),
        trim((string)($data['pipeline_stage'] ?? 'entrada')),
        max(0, min(10, (int)($data['lead_score'] ?? 0))),
        money_to_float((string)($data['estimated_value'] ?? '0')),
        trim((string)($data['source'] ?? 'manual')),
    ];

    if ($id > 0) {
        $token = trim((string)($data['public_update_token'] ?? ''));
        if ($token === '') {
            $existing = $pdo->prepare('SELECT public_update_token FROM leads WHERE id = ? LIMIT 1');
            $existing->execute([$id]);
            $token = trim((string)($existing->fetchColumn() ?: ''));
        }
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }
        $stmt = $pdo->prepare(
            'UPDATE leads
             SET customer_id = ?, name = ?, phone = ?, interest = ?, status = ?, pipeline_stage = ?, lead_score = ?, estimated_value = ?, source = ?, public_update_token = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([...$values, $token, $id]);
        studio_upsert_public_lead_link($studio, $id, $token, (int)($values[0] ?? 0) ?: null);
        return $id;
    }

    $limit = plan_limit('max_clients');
    if ($limit > 0 && studio_lead_count($studio) >= $limit) {
        throw new RuntimeException('Seu plano atual permite até ' . $limit . ' clientes/leads cadastrados. Para continuar cadastrando novos contatos, altere para um plano superior.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leads
            (customer_id, name, phone, interest, status, pipeline_stage, lead_score, estimated_value, source, public_update_token, last_contact_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())'
    );
    $token = bin2hex(random_bytes(16));
    $stmt->execute([...$values, $token]);
    $newId = (int)$pdo->lastInsertId();
    studio_upsert_public_lead_link($studio, $newId, $token, (int)($values[0] ?? 0) ?: null);

    return $newId;
}

function studio_save_appointment(array $studio, array $data): int
{
    $pdo = studio_db($studio);
    studio_ensure_appointment_reference_columns($studio);
    $id = (int)($data['id'] ?? 0);
    $existingAppointment = $id > 0 ? studio_find_appointment($studio, $id) : null;
    $durationMinutes = studio_schedule_duration_minutes($studio);
    $leadId = (int)($data['lead_id'] ?? 0);
    $customerId = (int)($data['customer_id'] ?? 0);
    if ($customerId <= 0 && $leadId > 0) {
        $lead = studio_find_lead($studio, $leadId);
        $customerId = (int)($lead['customer_id'] ?? 0);
    }
    $importSource = trim((string)($data['import_source'] ?? ($existingAppointment['import_source'] ?? 'manual')));
    if ($importSource === '') {
        $importSource = 'manual';
    }
    $importUid = trim((string)($data['import_uid'] ?? ($existingAppointment['import_uid'] ?? '')));
    $rawTitle = trim((string)($data['raw_title'] ?? ($existingAppointment['raw_title'] ?? '')));
    if ($rawTitle === '' && $importSource !== 'manual') {
        $rawTitle = trim((string)($existingAppointment['raw_title'] ?? $data['title'] ?? ''));
    }
    $normalized = studio_validate_appointment_payload($studio, $data, $id);
    $appointmentDate = $normalized['appointment_date'];
    $startTime = $normalized['start_time'];
    $endTime = studio_schedule_normalize_end_time($appointmentDate, $startTime, $normalized['end_time'], $durationMinutes);
    $depositValue = $normalized['deposit_value'];
    $requestedStatus = trim((string)($data['status'] ?? ''));
    $status = in_array($requestedStatus, studio_appointment_allowed_statuses(), true)
        ? $requestedStatus
        : ($depositValue > 0 ? 'agendado' : 'pre_agendado');
    if ($depositValue <= 0 && $status === 'agendado') {
        $status = 'pre_agendado';
    }
    $artistId = $normalized['artist_id'] ?: null;
    $leadId = (int)$normalized['lead_id'];
    $customerId = (int)$normalized['customer_id'];
    $pomadaUnitPrice = studio_pomada_unit_price($studio);
    $values = [
        $customerId ?: null,
        $leadId ?: null,
        $artistId,
        trim((string)($data['title'] ?? 'Atendimento')),
        trim((string)($data['description'] ?? '')),
        $appointmentDate,
        $startTime,
        $endTime,
        $status,
        money_to_float((string)($data['value'] ?? '0')),
        $depositValue,
        max(0, (int)($data['pomadas_quantity'] ?? 0)),
        $importSource,
        $importUid !== '' ? $importUid : null,
        $rawTitle !== '' ? $rawTitle : null,
    ];
    if ($values[7] === '') {
        $values[7] = null;
    }
    $attachment = studio_prepare_appointment_reference($studio, $_FILES ?? []);
    $replacedAppointments = [];
    $activeStatuses = studio_appointment_blocking_statuses_for_status($status);
    $overlappingAppointments = studio_find_overlapping_appointments($studio, $appointmentDate, $startTime, $endTime, $artistId, $activeStatuses, $id);
    if ($overlappingAppointments) {
        $conflict = $overlappingAppointments[0];
        $artistName = trim((string)($conflict['artist_name'] ?? ''));
        if ($artistName === '' && $artistId) {
            foreach (studio_list_artists($studio, false) as $artist) {
                if ((int)($artist['id'] ?? 0) === $artistId) {
                    $artistName = trim((string)($artist['name'] ?? '')) ?: 'tatuador selecionado';
                    break;
                }
            }
        }
        if ($artistName === '') {
            $artistName = 'esse tatuador';
        }
        $conflictDate = format_date_pt((string)$conflict['appointment_date']);
        $conflictStart = substr((string)$conflict['start_time'], 0, 5);
        $conflictEnd = substr((string)($conflict['end_time'] ?? $conflict['start_time']), 0, 5);
        $conflictCustomer = trim((string)($conflict['customer_name'] ?? ''));
        $conflictLabel = $conflictCustomer !== '' ? ' com o cliente ' . $conflictCustomer : '';
        throw new RuntimeException(
            'Este horário já está ocupado para o tatuador ' . $artistName . $conflictLabel . ', em ' . $conflictDate . ', das ' . $conflictStart . ' às ' . $conflictEnd . '.'
        );
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE appointments
             SET customer_id = ?, lead_id = ?, artist_id = ?, title = ?, description = ?, appointment_date = ?, start_time = ?, end_time = ?, status = ?, value = ?, deposit_value = ?, pomadas_quantity = ?, import_source = ?, import_uid = ?, raw_title = ?, reference_image_path = ?, reference_image_name = ?, reference_image_mime = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            ...$values,
            $attachment['relativePath'] ?: null,
            $attachment['fileName'] ?: null,
            $attachment['mime'] ?: null,
            $id
        ]);
        studio_sync_lead_from_appointment($studio, $leadId, $values[8], $values[9], $values[5] . ' ' . $values[6]);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO appointments
            (customer_id, lead_id, artist_id, title, description, appointment_date, start_time, end_time, status, value, deposit_value, pomada_unit_price, pomadas_quantity, import_source, import_uid, raw_title, reference_image_path, reference_image_name, reference_image_mime, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $customerId ?: null,
        $leadId ?: null,
        $artistId,
        trim((string)($data['title'] ?? 'Atendimento')),
        trim((string)($data['description'] ?? '')),
        $appointmentDate,
        $startTime,
        $endTime,
        $status,
        money_to_float((string)($data['value'] ?? '0')),
        $depositValue,
        $pomadaUnitPrice,
        max(0, (int)($data['pomadas_quantity'] ?? 0)),
        $importSource,
        $importUid !== '' ? $importUid : null,
        $rawTitle !== '' ? $rawTitle : null,
        $attachment['relativePath'] ?: null,
        $attachment['fileName'] ?: null,
        $attachment['mime'] ?: null,
    ]);

    $appointmentId = (int)$pdo->lastInsertId();
    studio_sync_lead_from_appointment($studio, $leadId, $values[8], $values[9], $values[5] . ' ' . $values[6]);
    return $appointmentId;
}

function studio_apply_appointment_auto_status_rules(array $studio): int
{
    $pdo = studio_db($studio);
    $pdo->prepare(
        "UPDATE appointments
         SET status = 'finalizado', updated_at = NOW()
         WHERE status NOT IN ('finalizado', 'atendido', 'cancelado', 'perdido')
           AND (
                appointment_date < CURDATE()
                OR (appointment_date = CURDATE() AND COALESCE(end_time, start_time) <= CURTIME())
           )"
    )->execute();
    $stmt = $pdo->prepare(
        "UPDATE appointments
         SET status = CASE
             WHEN COALESCE(deposit_value, 0) > 0 THEN 'agendado'
             ELSE 'pre_agendado'
         END,
         updated_at = NOW()
         WHERE status IN ('pre_agendado', 'agendado')"
    );
    $stmt->execute();

    return $stmt->rowCount();
}

function studio_appointment_confirmation_window(array $appointment): bool
{
    $date = trim((string)($appointment['appointment_date'] ?? ''));
    $time = trim((string)($appointment['start_time'] ?? ''));
    if ($date === '' || $time === '') {
        return false;
    }
    try {
        $dt = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('America/Sao_Paulo'));
    } catch (Throwable) {
        return false;
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $hoursUntil = ($dt->getTimestamp() - $now->getTimestamp()) / 3600;
    return $hoursUntil <= 48 && $hoursUntil > 24;
}

function studio_appointment_confirmation_overdue(array $appointment): bool
{
    $date = trim((string)($appointment['appointment_date'] ?? ''));
    $time = trim((string)($appointment['start_time'] ?? ''));
    if ($date === '' || $time === '') {
        return false;
    }
    try {
        $dt = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('America/Sao_Paulo'));
    } catch (Throwable) {
        return false;
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $hoursUntil = ($dt->getTimestamp() - $now->getTimestamp()) / 3600;
    return $hoursUntil <= 24;
}

function studio_appointment_confirmation_phone(array $appointment): string
{
    return normalize_phone((string)($appointment['customer_phone'] ?? $appointment['phone'] ?? ''));
}

function studio_appointment_confirmation_text(array $studio, array $appointment): string
{
    $settings = studio_settings($studio);
    $template = trim((string)($settings['appointment_confirmation_message'] ?? ''));
    if ($template === '') {
        $template = "Oi {{name}}! Sua sessão está confirmada para {{date}} às {{start_time}}. Me responde com *sim* para confirmar, ou avisa se precisar cancelar/alterar.";
    }
    $customerName = trim((string)($appointment['customer_name'] ?? $appointment['lead_name'] ?? $appointment['title'] ?? 'cliente'));
    return studio_format_appointment_message($template, [
        'name' => $customerName,
        'date' => format_date_pt((string)($appointment['appointment_date'] ?? '')),
        'start_time' => substr((string)($appointment['start_time'] ?? ''), 0, 5),
        'end_time' => substr((string)($appointment['end_time'] ?? ''), 0, 5),
        'studio_name' => (string)($studio['name'] ?? 'estudio'),
        'reason' => 'Confirmação automática de agenda.',
    ]);
}

function studio_appointment_confirmation_ai_decision(array $studio, array $appointment, string $reply): ?string
{
    $reply = trim($reply);
    if ($reply === '') {
        return null;
    }

    $config = studio_openai_config($studio);
    if (empty($config['api_key'])) {
        $normalized = remove_accents(lower_text($reply));
        if (contains_any($normalized, ['sim', 'confirmo', 'confirmado', 'confirmar', 'vou', 'estarei', 'ok', 'beleza', 'claro'])) {
            return 'confirmado';
        }
        if (contains_any($normalized, ['nao', 'não', 'cancelar', 'cancela', 'cancelado', 'desmarcar', 'nao vou', 'não vou', 'impossivel', 'impossível'])) {
            return 'cancelado';
        }
        return null;
    }

    $prompt = "Classifique a resposta do cliente para confirmação de agendamento.\n"
        . "Responda somente com JSON valido no formato {\"decision\":\"confirmed|canceled|unknown\"}.\n"
        . "Se a mensagem confirma presença, use confirmed.\n"
        . "Se a mensagem informa cancelamento, recusa, impossibilidade ou desistência, use canceled.\n"
        . "Se estiver ambígua, use unknown.\n\n"
        . "Agendamento:\n"
        . "- Cliente: " . (string)($appointment['customer_name'] ?? $appointment['title'] ?? 'cliente') . "\n"
        . "- Data: " . format_date_pt((string)($appointment['appointment_date'] ?? '')) . "\n"
        . "- Hora: " . substr((string)($appointment['start_time'] ?? ''), 0, 5) . "\n\n"
        . "Resposta do cliente:\n" . $reply;

    $result = studio_openai_text($config['api_key'], $config['model'], 'Você classifica respostas de confirmação de agendamento.', $prompt, (string)($config['base_url'] ?? 'https://api.openai.com/v1'), 45);
    if (empty($result['ok'])) {
        return null;
    }
    $decision = strtolower(trim((string)($result['reply_text'] ?? '')));
    if ($decision === '' && !empty($result['summary'])) {
        $decision = strtolower(trim((string)$result['summary']));
    }
    if (str_contains($decision, 'confirmed')) {
        return 'confirmado';
    }
    if (str_contains($decision, 'canceled')) {
        return 'cancelado';
    }
    return null;
}

function studio_schedule_appointment_confirmations(array $studio): array
{
    $pdo = studio_db($studio);
    $stmt = $pdo->query(
        "SELECT a.*, c.name AS customer_name, c.phone AS customer_phone, l.name AS lead_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN leads l ON l.id = a.lead_id
         WHERE a.status IN ('pre_agendado', 'agendado', 'confirmado')
           AND COALESCE(a.appointment_date, '') <> ''
         ORDER BY a.appointment_date ASC, a.start_time ASC"
    );
    $appointments = $stmt->fetchAll() ?: [];
    $sent = 0;
    $canceled = 0;
    $confirmed = 0;
    $messages = [];

    foreach ($appointments as $appointment) {
        $phone = studio_appointment_confirmation_phone($appointment);
        if ($phone === '') {
            continue;
        }
        $confirmationStatus = trim((string)($appointment['confirmation_status'] ?? ''));
        $requestedAt = trim((string)($appointment['confirmation_requested_at'] ?? ''));
        $sentAt = trim((string)($appointment['confirmation_message_sent_at'] ?? ''));
        if ($confirmationStatus === 'confirmed' || $confirmationStatus === 'canceled') {
            continue;
        }
        if ($requestedAt === '' && studio_appointment_confirmation_window($appointment)) {
            try {
                $message = studio_appointment_confirmation_text($studio, $appointment);
                if ($message !== '') {
                    studio_send_whatsapp_message($studio, [
                        'conversation_id' => (int)($appointment['conversation_id'] ?? 0),
                        'phone' => $phone,
                        'message' => $message,
                    ]);
                    $pdo->prepare(
                        'UPDATE appointments
                         SET confirmation_requested_at = NOW(), confirmation_message_sent_at = NOW(), confirmation_status = "pending", confirmation_last_message = ?, updated_at = NOW()
                         WHERE id = ?'
                    )->execute([$message, (int)$appointment['id']]);
                    $sent++;
                    $messages[] = ['id' => (int)$appointment['id'], 'type' => 'sent'];
                }
            } catch (Throwable $e) {
            }
            continue;
        }
        if ($requestedAt !== '' && $sentAt !== '' && studio_appointment_confirmation_overdue($appointment)) {
            if (in_array((string)($appointment['status'] ?? ''), ['pre_agendado', 'agendado', 'confirmado'], true)) {
                $pdo->prepare(
                    'UPDATE appointments
                     SET status = "cancelado", confirmation_status = "expired", confirmation_response_at = NOW(), updated_at = NOW()
                     WHERE id = ?'
                )->execute([(int)$appointment['id']]);
                $canceled++;
                $messages[] = ['id' => (int)$appointment['id'], 'type' => 'canceled'];
            }
        }
    }

    return [
        'sent' => $sent,
        'canceled' => $canceled,
        'confirmed' => $confirmed,
        'events' => $messages,
    ];
}

function studio_process_appointment_confirmation_reply(array $studio, array $conversation, string $messageBody): ?array
{
    $phone = normalize_phone((string)($conversation['phone'] ?? ''));
    if ($phone === '') {
        return null;
    }
    $pdo = studio_db($studio);
    $stmt = $pdo->prepare(
        "SELECT a.*, c.name AS customer_name, c.phone AS customer_phone, l.name AS lead_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN leads l ON l.id = a.lead_id
         WHERE a.status IN ('pre_agendado', 'agendado', 'confirmado')
           AND COALESCE(c.phone, '') = ?
           AND a.appointment_date >= CURDATE()
         ORDER BY a.appointment_date ASC, a.start_time ASC
         LIMIT 1"
    );
    $stmt->execute([$phone]);
    $appointment = $stmt->fetch();
    if (!is_array($appointment)) {
        return null;
    }
    if (!studio_appointment_confirmation_overdue($appointment) && !studio_appointment_confirmation_window($appointment)) {
        return null;
    }
    $decision = studio_appointment_confirmation_ai_decision($studio, $appointment, $messageBody);
    if ($decision === null) {
        return null;
    }
    $pdo->prepare(
        'UPDATE appointments
         SET status = ?, confirmation_status = ?, confirmation_response_at = NOW(), confirmation_last_message = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([
        $decision,
        $decision,
        mb_substr($messageBody, 0, 2000),
        (int)$appointment['id'],
    ]);

    return [
        'appointment_id' => (int)$appointment['id'],
        'decision' => $decision,
    ];
}

function studio_update_appointment_status(array $studio, int $appointmentId, string $status): void
{
    $pdo = studio_db($studio);
    $appointment = studio_find_appointment($studio, $appointmentId);
    if (!$appointment) {
        throw new RuntimeException('Agendamento nao encontrado.');
    }

    if (!in_array($status, studio_appointment_allowed_statuses(), true)) {
        throw new RuntimeException('Status de agendamento invalido.');
    }

    $stmt = $pdo->prepare('UPDATE appointments SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
    $stmt->execute([$status, $appointmentId]);

    $leadId = (int)($appointment['lead_id'] ?? 0);
    if ($leadId > 0 && $status !== 'falta') {
        studio_sync_lead_from_appointment(
            $studio,
            $leadId,
            $status,
            (string)($appointment['value'] ?? 0),
            (string)($appointment['appointment_date'] ?? '') . ' ' . (string)($appointment['start_time'] ?? '')
        );
    }
}

function studio_ensure_appointment_reference_columns(array $studio): void
{
    $pdo = studio_db($studio);
    $pomadaUnitPrice = studio_pomada_unit_price($studio);
    $columns = [
        'pomada_unit_price' => 'DECIMAL(10,2) NULL AFTER deposit_value',
        'pomadas_quantity' => 'INT NOT NULL DEFAULT 0 AFTER deposit_value',
        'import_source' => 'VARCHAR(40) NULL AFTER deposit_value',
        'import_uid' => 'VARCHAR(190) NULL AFTER import_source',
        'raw_title' => 'VARCHAR(260) NULL AFTER import_uid',
        'reference_image_path' => 'VARCHAR(255) NULL AFTER deposit_value',
        'reference_image_name' => 'VARCHAR(180) NULL AFTER reference_image_path',
        'reference_image_mime' => 'VARCHAR(120) NULL AFTER reference_image_name',
        'confirmation_requested_at' => 'DATETIME NULL AFTER import_uid',
        'confirmation_message_sent_at' => 'DATETIME NULL AFTER confirmation_requested_at',
        'confirmation_status' => 'VARCHAR(30) NULL AFTER confirmation_message_sent_at',
        'confirmation_response_at' => 'DATETIME NULL AFTER confirmation_status',
        'confirmation_last_message' => 'TEXT NULL AFTER confirmation_response_at',
    ];
    foreach ($columns as $column => $definition) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN IF NOT EXISTS ' . $column . ' ' . $definition);
        } catch (Throwable) {
        }
    }
    try {
        $stmt = $pdo->prepare('UPDATE appointments SET pomada_unit_price = COALESCE(pomada_unit_price, ?) WHERE pomada_unit_price IS NULL OR pomada_unit_price = 0');
        $stmt->execute([number_format($pomadaUnitPrice, 2, '.', '')]);
    } catch (Throwable) {
    }
}

function studio_appointment_attachment_dir(array $studio): array
{
    $root = realpath(__DIR__ . '/../');
    if (!$root) {
        throw new RuntimeException('Nao consegui localizar o diretório do projeto.');
    }
    $safeStudio = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($studio['slug'] ?? 'studio')) ?: 'studio';
    $folder = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'appointment-references' . DIRECTORY_SEPARATOR . $safeStudio;
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        throw new RuntimeException('Nao foi possivel criar a pasta de referencias.');
    }
    return [
        'folder' => $folder,
        'relativePrefix' => 'storage/appointment-references/' . $safeStudio . '/',
    ];
}

function studio_prepare_appointment_reference(array $studio, array $files): array
{
    $file = $files['reference_image'] ?? null;
    if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['relativePath' => '', 'mime' => '', 'fileName' => ''];
    }
    if (!empty($file['error'])) {
        throw new RuntimeException('Nao foi possivel ler a imagem de referencia.');
    }
    $mime = trim((string)($file['type'] ?? ''));
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($file['tmp_name']);
    }
    if ($mime !== '' && !str_starts_with($mime, 'image/')) {
        throw new RuntimeException('A referencia precisa ser uma imagem.');
    }
    $mime = $mime !== '' ? $mime : 'image/jpeg';
    $fileName = trim((string)($file['name'] ?? 'referencia.jpg')) ?: 'referencia.jpg';
    $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION)) ?: studio_whatsapp_extension_for_mime($mime, $fileName);
    $storage = studio_appointment_attachment_dir($studio);
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($fileName, PATHINFO_FILENAME)) ?: 'referencia';
    $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $base . ($ext !== '' ? '.' . $ext : '');
    $dest = $storage['folder'] . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Nao foi possivel salvar a referencia.');
    }
    return [
        'relativePath' => $storage['relativePrefix'] . $storedName,
        'mime' => $mime,
        'fileName' => $fileName,
    ];
}

function studio_import_calendar_ics(array $studio, string $icsPath): array
{
    if (!is_file($icsPath)) {
        throw new RuntimeException('Arquivo ICS nao encontrado.');
    }

    $raw = (string)file_get_contents($icsPath);
    if ($raw === '') {
        throw new RuntimeException('Arquivo ICS vazio.');
    }

    $events = studio_parse_ics_events($raw);
    $pdo = studio_db($studio);
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($events as $event) {
        $uid = trim((string)($event['UID'] ?? $event['uid'] ?? ''));
        $recurrenceId = trim((string)($event['RECURRENCE-ID'] ?? $event['recurrence-id'] ?? ''));
        $start = (string)($event['DTSTART'] ?? $event['dtstart'] ?? '');
        $end = (string)($event['DTEND'] ?? $event['dtend'] ?? '');
        $summary = trim((string)($event['SUMMARY'] ?? $event['summary'] ?? ''));
        if ($summary === '' || $start === '') {
            $skipped++;
            continue;
        }

        $startDt = studio_ics_datetime_to_local($start);
        $endDt = $end !== '' ? studio_ics_datetime_to_local($end) : null;
        if (!$startDt) {
            $skipped++;
            continue;
        }

        $title = mb_substr($summary, 0, 180);
        $description = trim((string)($event['DESCRIPTION'] ?? $event['description'] ?? ''));
        $date = $startDt->format('Y-m-d');
        $startTime = $startDt->format('H:i:s');
        $endTime = $endDt ? $endDt->format('H:i:s') : null;
        $importUid = $uid !== ''
            ? sha1($uid . '|' . $recurrenceId)
            : sha1($title . '|' . $date . '|' . $startTime . '|' . $endTime);

        $existingId = studio_find_imported_calendar_appointment_id($studio, $importUid, $title, $date, $startTime, $endTime ?: '');

        $payload = [
            'title' => $title,
            'description' => $description,
            'appointment_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime ?: '',
            'status' => 'confirmado',
            'value' => 0,
            'deposit_value' => 0,
            'customer_id' => 0,
            'lead_id' => 0,
            'artist_id' => 0,
        ];

        if ($existingId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE appointments
                SET title = ?, description = ?, appointment_date = ?, start_time = ?, end_time = ?, status = ?, import_source = ?, import_uid = ?, raw_title = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $payload['title'],
                $payload['description'],
                $payload['appointment_date'],
                $payload['start_time'],
                $payload['end_time'] ?: null,
                $payload['status'],
                'google_calendar',
                $importUid,
                $summary,
                $existingId,
            ]);
            $updated++;
            continue;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO appointments
                (customer_id, lead_id, artist_id, title, description, appointment_date, start_time, end_time, status, value, deposit_value, import_source, import_uid, raw_title, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            null,
            null,
            null,
            $payload['title'],
            $payload['description'],
            $payload['appointment_date'],
            $payload['start_time'],
            $payload['end_time'] ?: null,
            $payload['status'],
            0,
            0,
            'google_calendar',
            $importUid,
            $summary,
        ]);
        $inserted++;
    }

    return [
        'ok' => true,
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'total' => count($events),
    ];
}

function studio_find_imported_calendar_appointment_id(array $studio, string $importUid, string $title, string $date, string $startTime, string $endTime = ''): int
{
    $pdo = studio_db($studio);

    $stmt = $pdo->prepare('SELECT id FROM appointments WHERE import_source IN ("google_calendar", "google_ics") AND import_uid = ? LIMIT 1');
    $stmt->execute([$importUid]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return $existingId;
    }

    $normalizedTitle = normalize_spaces(remove_accents(mb_strtolower($title)));
    $stmt = $pdo->prepare(
        'SELECT id, title, raw_title
         FROM appointments
         WHERE import_source IN ("google_calendar", "google_ics")
           AND appointment_date = ?
           AND start_time = ?
         ORDER BY id DESC
         LIMIT 12'
    );
    $stmt->execute([$date, $startTime]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $existingTitle = normalize_spaces(remove_accents(mb_strtolower((string)($row['raw_title'] ?? $row['title'] ?? ''))));
        if ($existingTitle !== '' && $normalizedTitle !== '') {
            if ($existingTitle === $normalizedTitle || str_contains($existingTitle, $normalizedTitle) || str_contains($normalizedTitle, $existingTitle)) {
                return (int)($row['id'] ?? 0);
            }
        }
    }

    if ($endTime !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, title, raw_title
             FROM appointments
             WHERE import_source IN ("google_calendar", "google_ics")
               AND appointment_date = ?
               AND end_time = ?
             ORDER BY id DESC
             LIMIT 12'
        );
        $stmt->execute([$date, $endTime]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $existingTitle = normalize_spaces(remove_accents(mb_strtolower((string)($row['raw_title'] ?? $row['title'] ?? ''))));
            if ($existingTitle !== '' && $normalizedTitle !== '') {
                if ($existingTitle === $normalizedTitle || str_contains($existingTitle, $normalizedTitle) || str_contains($normalizedTitle, $existingTitle)) {
                    return (int)($row['id'] ?? 0);
                }
            }
        }
    }

    return 0;
}

function studio_analyze_calendar_ics(array $studio, string $icsPath): array
{
    if (!is_file($icsPath)) {
        throw new RuntimeException('Arquivo ICS nao encontrado.');
    }

    $raw = (string)file_get_contents($icsPath);
    if ($raw === '') {
        throw new RuntimeException('Arquivo ICS vazio.');
    }

    $events = studio_parse_ics_events($raw);
    $candidates = [];
    $skipped = [];

    foreach ($events as $event) {
        $parsed = studio_parse_calendar_event_for_crm($event);
        if (!empty($parsed['include'])) {
            $candidates[] = $parsed;
        } else {
            $skipped[] = $parsed;
        }
    }

    return [
        'events_total' => count($events),
        'candidates' => studio_attach_calendar_conflicts($studio, $candidates),
        'skipped' => $skipped,
        'duplicates' => studio_count_existing_imported_appointments($studio, $candidates),
    ];
}

function studio_attach_calendar_conflicts(array $studio, array $items): array
{
    $statuses = ['confirmado', 'pre_agendado', 'em_atendimento', 'concluido'];
    foreach ($items as &$item) {
        $date = (string)($item['date'] ?? '');
        $start = (string)($item['start_time'] ?? '');
        $end = (string)($item['end_time'] ?? '');
        if ($date === '' || $start === '') {
            $item['conflicts'] = [];
            continue;
        }
        if ($end === '') {
            $end = substr($start, 0, 5) . ':59';
        }
        $item['conflicts'] = studio_find_overlapping_appointments($studio, $date, $start, $end, null, $statuses, 0);
    }
    unset($item);

    return $items;
}

function studio_parse_calendar_event_for_crm(array $event): array
{
    $rawTitle = normalize_spaces((string)($event['SUMMARY'] ?? ($event['summary'] ?? '')));
    $description = normalize_spaces((string)($event['DESCRIPTION'] ?? ($event['description'] ?? '')));
    $start = studio_ics_datetime_to_local((string)($event['DTSTART'] ?? ($event['dtstart'] ?? '')));
    $end = studio_ics_datetime_to_local((string)($event['DTEND'] ?? ($event['dtend'] ?? '')));
    $base = [
        'include' => false,
        'reason' => '',
        'raw_title' => $rawTitle,
        'uid' => import_uid((string)($event['UID'] ?? ($event['uid'] ?? ''))),
        'google_uid' => (string)($event['UID'] ?? ($event['uid'] ?? '')),
        'description_original' => $description,
        'date' => $start ? $start->format('Y-m-d') : null,
        'start_time' => $start ? $start->format('H:i:s') : null,
        'end_time' => $end ? $end->format('H:i:s') : null,
    ];

    if (strtoupper((string)($event['STATUS'] ?? ($event['status'] ?? 'CONFIRMED'))) === 'CANCELLED') {
        return array_merge($base, ['reason' => 'cancelado']);
    }
    if (!empty($event['ALL_DAY']) || !empty($event['all_day']) || !$start) {
        return array_merge($base, ['reason' => 'sem horario util']);
    }
    if ($rawTitle === '') {
        return array_merge($base, ['reason' => 'sem titulo']);
    }

    $lower = lower_text($rawTitle . ' ' . $description);
    $normalized = remove_accents($lower);
    $hardSkipWords = [
        'cartorio', 'cognizant', 'edital', 'ultra-som', 'ultra som', 'ubs', 'conselho tutelar',
        'guarda roupa', 'endoscopia', 'pastor', 'curso', 'aniversario', 'reuniao', 'oftalmo',
        'endocrino', 'papai', 'mamae', 'doctor', 'dr.ray', 'terapia', 'psico', 'psicopedagoga',
        'consulta', 'medico', 'faculdade', 'lavar', 'mercado', 'academia', 'dentista',
        'visita tecnica', 'marketing', 'limpeza e etc', 'cobrar sinal', 'padaria', 'restaurante',
    ];
    if (contains_any($normalized, $hardSkipWords)) {
        return array_merge($base, ['reason' => 'parece compromisso pessoal']);
    }

    $phone = extract_phone($rawTitle . ' ' . $description);
    [$value, $valueToken] = extract_event_value($rawTitle);
    $hasServiceKeyword = contains_any($normalized, ['tattoo', 'tatuagem', 'tatuar', 'retoque', 'micro', 'micropigmentacao', 'cilios', 'piercing', 'pomada', 'sinal', 'orcamento', 'cobertura', 'sessao', 'fechamento', 'higienizacao']);
    $looksLikePerson = looks_like_person_title($rawTitle);

    if ($phone === '' && $value <= 0 && !$hasServiceKeyword && !$looksLikePerson) {
        return array_merge($base, ['reason' => 'sem sinal de cliente/atendimento']);
    }

    $parsedTitle = studio_parse_event_title($rawTitle, $valueToken);
    $name = $parsedTitle['name'] !== '' ? $parsedTitle['name'] : $rawTitle;

    $today = new DateTimeImmutable(date('Y-m-d'), new DateTimeZone('America/Sao_Paulo'));
    $appointmentDate = new DateTimeImmutable($start->format('Y-m-d'), new DateTimeZone('America/Sao_Paulo'));
    $isPast = $appointmentDate < $today;
    $paymentText = remove_accents($lower);
    $unconfirmed = str_contains($paymentText, 'sem sinal') || str_contains($paymentText, 'fiado') || str_contains($paymentText, 'negociar');
    $status = $isPast ? 'concluido' : ($unconfirmed ? 'pre_agendado' : 'confirmado');
    $leadStatus = $isPast ? 'fechado' : ($unconfirmed ? 'pre_agendado' : 'agendado');
    $stage = $isPast ? 'agendado' : ($unconfirmed ? 'pre_agendado' : 'agendado');
    $interestParts = [];
    if ($parsedTitle['service_note'] !== '') {
        $interestParts[] = $parsedTitle['service_note'];
    }
    if (preg_match('/\b(\d+)\s*pomadas?\b/iu', $rawTitle, $pomada)) {
        $interestParts[] = $pomada[1] . ' pomada(s)';
    } elseif (str_contains($paymentText, 'pomada')) {
        $interestParts[] = 'pomada';
    }
    if ($description !== '') {
        $interestParts[] = $description;
    }
    $interest = normalize_spaces(implode(' | ', array_filter($interestParts))) ?: 'Agendamento importado do Google Agenda';

    return array_merge($base, [
        'include' => true,
        'reason' => 'candidato',
        'name' => mb_substr($name, 0, 160),
        'phone' => $phone,
        'value' => $value,
        'notes' => $parsedTitle['notes'],
        'interest' => mb_substr($interest, 0, 220),
        'appointment_status' => $status,
        'status' => $leadStatus,
        'pipeline_stage' => $stage,
        'lead_score' => $isPast ? 5 : ($value > 0 ? 8 : 6),
        'recurrence_id' => trim((string)($event['RECURRENCE-ID'] ?? ($event['recurrence-id'] ?? ''))),
    ]);
}

function studio_parse_ics_events(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);
    $unfolded = [];
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        if (isset($unfolded[count($unfolded) - 1]) && ($line[0] === ' ' || $line[0] === "\t")) {
            $unfolded[count($unfolded) - 1] .= ltrim($line);
            continue;
        }
        $unfolded[] = $line;
    }

    $events = [];
    $current = null;
    foreach ($unfolded as $line) {
        if (trim($line) === 'BEGIN:VEVENT') {
            $current = [];
            continue;
        }
        if (trim($line) === 'END:VEVENT') {
            if (is_array($current)) {
                $events[] = $current;
            }
            $current = null;
            continue;
        }
        if (!is_array($current)) {
            continue;
        }

        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $name = strtoupper(trim((string)explode(';', $name, 2)[0]));
        $current[$name] = trim((string)$value);
    }

    return $events;
}

function studio_parse_event_title(string $title, string $valueToken): array
{
    $notes = [];
    if (preg_match_all('/\(([^)]*)\)/u', $title, $matches)) {
        $notes = array_map('normalize_spaces', $matches[1]);
    }

    $clean = preg_replace('/\([^)]*\)/u', ' ', $title) ?? $title;
    if ($valueToken !== '') {
        $clean = str_replace($valueToken, ' ', $clean);
    }
    $clean = preg_replace('/\bR\$\s*[\d\.\,]+|\b[\d\.\,]+\s*\$/iu', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\b\d{2,6}(?:[,.]\d{2})?\s*,?\s*(?:com|pagou sinal|sinal pago|pago|sinal)\b/iu', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\s[-–]\s*\d{2,6}(?:[,.]\d{2})?\b/u', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\b\d+\s*pomadas?\b/iu', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\b(pago|sinal pago|sem sinal|fiado|parcelado|permuta|valor a negociar|vai pagar metade)\b/iu', ' ', $clean) ?? $clean;
    $clean = normalize_spaces($clean);

    $serviceNote = '';
    if (preg_match('/\b(retoque|micro|micropigmentação|micropigmentacao|cílios|cilios|piercing|pomadas?|higienização|higienizacao|cobertura|fechamento)\b.*$/iu', $clean, $serviceMatch, PREG_OFFSET_CAPTURE)) {
        $offset = $serviceMatch[0][1];
        $serviceNote = trim(substr($clean, $offset));
        $clean = trim(substr($clean, 0, $offset));
    }

    $clean = normalize_spaces(trim($clean, " -–\t\n\r\0\x0B"));

    return [
        'name' => $clean,
        'service_note' => $serviceNote,
        'notes' => implode('; ', array_filter($notes)),
    ];
}

function studio_count_existing_imported_appointments(array $studio, array $items): int
{
    $count = 0;
    foreach ($items as $item) {
        if (studio_imported_appointment_exists($studio, (string)($item['uid'] ?? ''))) {
            $count++;
        }
    }

    return $count;
}

function studio_imported_appointment_exists(array $studio, string $uid): bool
{
    $stmt = studio_db($studio)->prepare('SELECT id FROM appointments WHERE import_source IN ("google_calendar", "google_ics") AND import_uid = ? LIMIT 1');
    $stmt->execute([$uid]);

    return (bool)$stmt->fetchColumn();
}

function studio_import_calendar_events(array $studio, array $items): array
{
    $pdo = studio_db($studio);
    $artistId = default_artist_id($studio);
    $result = [
        'customers_created' => 0,
        'leads_created' => 0,
        'appointments_created' => 0,
        'duplicates_skipped' => 0,
        'appointment_ids' => [],
        'lead_ids' => [],
        'customer_ids' => [],
    ];

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            if (studio_imported_appointment_exists($studio, (string)$item['uid'])) {
                $result['duplicates_skipped']++;
                continue;
            }

            $customerId = studio_find_or_create_customer_from_import($studio, $item, $result);
            $result['customer_ids'][] = $customerId;
            $leadId = studio_find_or_create_lead_from_import($studio, $item, $customerId, $result);
            $result['lead_ids'][] = $leadId;
            $description = studio_build_import_description($item);

            $stmt = $pdo->prepare(
                'INSERT INTO appointments
                    (customer_id, lead_id, artist_id, title, description, appointment_date, start_time, end_time, status, value, deposit_value, import_source, import_uid, raw_title, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "google_calendar", ?, ?, NOW(), NOW())'
            );
            $stmt->execute([
                $customerId,
                $leadId,
                $artistId,
                $item['name'],
                $description,
                $item['date'],
                $item['start_time'],
                $item['end_time'],
                $item['appointment_status'],
                $item['value'],
                $item['uid'],
                mb_substr($item['raw_title'], 0, 260),
            ]);
            $result['appointments_created']++;
            $result['appointment_ids'][] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}

function studio_revert_import_calendar_events(array $studio, array $uids): array
{
    $pdo = studio_db($studio);
    $uids = array_values(array_unique(array_filter(array_map('trim', $uids), static fn(string $uid): bool => $uid !== '')));
    if (!$uids) {
        return ['appointments_deleted' => 0, 'leads_deleted' => 0];
    }

    $pdo->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $deletedLeads = 0;

        $stmt = $pdo->prepare('DELETE FROM appointments WHERE import_source IN ("google_calendar", "google_ics") AND import_uid IN (' . $placeholders . ')');
        $stmt->execute($uids);
        $deletedAppointments = $stmt->rowCount();

        try {
            $stmt = $pdo->prepare('DELETE FROM leads WHERE import_source IN ("google_calendar", "google_ics") AND import_uid IN (' . $placeholders . ')');
            $stmt->execute($uids);
            $deletedLeads = $stmt->rowCount();
        } catch (Throwable) {
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'appointments_deleted' => $deletedAppointments ?? 0,
        'leads_deleted' => $deletedLeads,
    ];
}

function studio_find_or_create_customer_from_import(array $studio, array $item, array &$result): int
{
    $pdo = studio_db($studio);
    if (($item['phone'] ?? '') !== '') {
        $customer = studio_find_customer_by_phone($studio, (string)$item['phone']);
        if ($customer) {
            return (int)$customer['id'];
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM customers WHERE LOWER(name) = LOWER(?) ORDER BY id ASC LIMIT 1');
    $stmt->execute([$item['name']]);
    $existing = (int)$stmt->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }

    $stmt = $pdo->prepare('INSERT INTO customers (name, phone, email, instagram, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$item['name'], $item['phone'], 'Importado do Google Agenda. Titulo original: ' . $item['raw_title']]);
    $result['customers_created']++;

    return (int)$pdo->lastInsertId();
}

function studio_meta_ads_field_map(array $fieldData): array
{
    $map = [];
    foreach ($fieldData as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = trim((string)($field['name'] ?? ''));
        $values = is_array($field['values'] ?? null) ? $field['values'] : [];
        if ($name === '' || !$values) {
            continue;
        }
        $map[strtolower($name)] = trim(implode(', ', array_map('strval', $values)));
    }
    return $map;
}

function studio_meta_ads_extract_phone(array $map): string
{
    foreach (['phone_number', 'phone', 'celular', 'telefone', 'whatsapp'] as $key) {
        if (!empty($map[$key])) {
            $raw = preg_replace('/\D+/', '', (string)$map[$key]);
            if ($raw !== '') {
                if (strlen($raw) === 13 && str_starts_with($raw, '55')) {
                    return '+' . $raw;
                }
                if (strlen($raw) === 11) {
                    return '+55' . $raw;
                }
                return '+' . $raw;
            }
        }
    }
    return '';
}

function studio_meta_ads_extract_name(array $map, string $fallback = ''): string
{
    foreach (['full_name', 'name', 'nome', 'nome_completo'] as $key) {
        if (!empty($map[$key])) {
            return trim((string)$map[$key]);
        }
    }
    return trim($fallback);
}

function studio_meta_ads_extract_email(array $map): string
{
    foreach (['email', 'e-mail', 'mail'] as $key) {
        if (!empty($map[$key])) {
            return trim((string)$map[$key]);
        }
    }
    return '';
}

function studio_meta_ads_upsert_lead(array $studio, array $lead, array &$result): int
{
    $pdo = studio_db($studio);
    $leadId = trim((string)($lead['id'] ?? ''));
    if ($leadId === '') {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT id FROM leads WHERE import_source = "meta_ads" AND import_uid = ? LIMIT 1');
    $stmt->execute([$leadId]);
    $existing = (int)$stmt->fetchColumn();

    $fieldMap = studio_meta_ads_field_map((array)($lead['field_data'] ?? []));
    $name = studio_meta_ads_extract_name($fieldMap, (string)($lead['ad_name'] ?? 'Lead Meta Ads'));
    $phone = studio_meta_ads_extract_phone($fieldMap);
    $email = studio_meta_ads_extract_email($fieldMap);
    $interestParts = [];
    if (!empty($lead['campaign_name'])) {
        $interestParts[] = (string)$lead['campaign_name'];
    }
    if (!empty($lead['ad_name'])) {
        $interestParts[] = (string)$lead['ad_name'];
    }
    if (!empty($fieldMap)) {
        $interestParts[] = implode(' | ', array_slice(array_map(static fn(string $k, string $v): string => $k . ': ' . $v, array_keys($fieldMap), array_values($fieldMap)), 0, 4));
    }
    $interest = trim(implode(' · ', array_filter($interestParts)));
    if ($interest === '') {
        $interest = 'Lead Meta Ads';
    }
    $source = 'Meta Ads';
    $status = 'novo';
    $pipelineStage = 'entrada';
    $leadScore = 5;
    $estimatedValue = 0.0;
    $rawTitle = trim((string)($lead['campaign_name'] ?? $lead['ad_name'] ?? 'Lead Meta Ads'));
    $lastContactAt = trim((string)($lead['created_time'] ?? date('Y-m-d H:i:s')));
    $customerId = 0;
    if ($phone !== '') {
        $customerStmt = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
        $customerStmt->execute([$phone]);
        $customerId = (int)$customerStmt->fetchColumn();
    }
    if ($customerId <= 0 && $email !== '') {
        $customerStmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
        $customerStmt->execute([$email]);
        $customerId = (int)$customerStmt->fetchColumn();
    }
    if ($customerId <= 0) {
        $customerStmt = $pdo->prepare('INSERT INTO customers (name, phone, email, notes, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        $customerStmt->execute([
            $name !== '' ? $name : 'Lead Meta Ads',
            $phone,
            $email,
            'Criado automaticamente a partir do formulário Meta Ads. Lead ID: ' . $leadId,
        ]);
        $customerId = (int)$pdo->lastInsertId();
        $result['customers_created'] = (int)($result['customers_created'] ?? 0) + 1;
    }

    if ($existing > 0) {
        $stmt = $pdo->prepare(
            'UPDATE leads
             SET customer_id = ?, name = ?, phone = ?, interest = ?, status = ?, pipeline_stage = ?, lead_score = ?, estimated_value = ?, source = ?, raw_title = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $customerId,
            $name !== '' ? $name : 'Lead Meta Ads',
            $phone,
            $interest,
            $status,
            $pipelineStage,
            $leadScore,
            $estimatedValue,
            $source,
            mb_substr($rawTitle, 0, 260),
            $existing,
        ]);
        $result['leads_updated'] = (int)($result['leads_updated'] ?? 0) + 1;
        return $existing;
    }

    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        'INSERT INTO leads
            (customer_id, name, phone, interest, status, pipeline_stage, lead_score, estimated_value, source, import_source, import_uid, raw_title, public_update_token, last_contact_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "meta_ads", ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $customerId,
        $name !== '' ? $name : 'Lead Meta Ads',
        $phone,
        $interest,
        $status,
        $pipelineStage,
        $leadScore,
        $estimatedValue,
        $source,
        $leadId,
        mb_substr($rawTitle, 0, 260),
        $token,
        $lastContactAt,
    ]);
    $newId = (int)$pdo->lastInsertId();
    studio_upsert_public_lead_link($studio, $newId, $token, $customerId ?: null);
    $result['leads_created'] = (int)($result['leads_created'] ?? 0) + 1;
    return $newId;
}

function studio_meta_ads_sync_leads(array $studio): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $leadFormId = preg_replace('/^act_/', '', trim((string)($settings['meta_ads_lead_form_id'] ?? '')));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0'));
    if ($token === '') {
        return ['ok' => false, 'error' => 'Access Token da Meta nao configurado.'];
    }
    if ($leadFormId === '') {
        return ['ok' => false, 'error' => 'ID do formulario de leads nao configurado.'];
    }

    $response = studio_meta_ads_request($version, '/' . $leadFormId . '/leads', $token, [
        'fields' => 'id,created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name',
        'limit' => 100,
    ]);
    if (!$response['ok']) {
        return $response;
    }

    $items = is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
    $result = [
        'ok' => true,
        'form_id' => $leadFormId,
        'created' => 0,
        'updated' => 0,
        'total' => count($items),
    ];

    foreach ($items as $lead) {
        if (!is_array($lead)) {
            continue;
        }
        studio_meta_ads_upsert_lead($studio, $lead, $result);
    }

    return $result;
}

function studio_find_or_create_lead_from_import(array $studio, array $item, int $customerId, array &$result): int
{
    $pdo = studio_db($studio);
    $stmt = $pdo->prepare('SELECT id FROM leads WHERE import_source = "google_calendar" AND import_uid = ? LIMIT 1');
    $stmt->execute([$item['uid']]);
    $existing = (int)$stmt->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leads
            (customer_id, name, phone, interest, status, pipeline_stage, lead_score, estimated_value, source, import_source, import_uid, raw_title, last_contact_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Google Agenda", "google_calendar", ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $customerId,
        $item['name'],
        $item['phone'],
        $item['interest'],
        $item['status'],
        $item['pipeline_stage'],
        $item['lead_score'],
        $item['value'],
        $item['uid'],
        mb_substr($item['raw_title'], 0, 260),
        $item['date'] . ' ' . $item['start_time'],
    ]);
    $result['leads_created']++;

    return (int)$pdo->lastInsertId();
}

function studio_build_import_description(array $item): string
{
    $parts = [
        'Importado do Google Agenda.',
        'Titulo original: ' . $item['raw_title'],
    ];
    if (!empty($item['notes'])) {
        $parts[] = 'Observacoes do titulo: ' . $item['notes'];
    }
    if (!empty($item['description_original'])) {
        $parts[] = 'Descricao original: ' . $item['description_original'];
    }
    $parts[] = 'UID Google: ' . ($item['google_uid'] ?? '');

    return implode("\n", $parts);
}

function studio_ics_datetime_to_local(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $tz = new DateTimeZone('America/Sao_Paulo');
    if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        return $dt ? $dt->setTimezone($tz) : null;
    }

    if (preg_match('/^\d{8}T\d{6}$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz);
        return $dt ?: null;
    }

    if (preg_match('/^\d{8}$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd', $value, $tz);
        return $dt ?: null;
    }

    return null;
}

function studio_sync_lead_from_appointment(array $studio, int $leadId, string $appointmentStatus, float $value, string $lastContactAt): void
{
    if ($leadId <= 0) {
        return;
    }

    $leadStatus = match ($appointmentStatus) {
        'confirmado', 'em_atendimento' => 'agendado',
        'concluido' => 'fechado',
        'cancelado' => 'perdido',
        default => 'pre_agendado',
    };
    $stage = match ($leadStatus) {
        'fechado' => 'agendado',
        'perdido' => 'entrada',
        default => $leadStatus,
    };

    studio_db($studio)->prepare(
        'UPDATE leads
         SET status = ?, pipeline_stage = ?, estimated_value = GREATEST(COALESCE(estimated_value, 0), ?), last_contact_at = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([$leadStatus, $stage, $value, $lastContactAt, $leadId]);
}

function studio_format_appointment_message(string $template, array $vars): string
{
    $message = trim($template);
    if ($message === '') {
        $message = 'Oi {{name}}, sua vaga foi ocupada por outro agendamento confirmado. Escolha outra data/hora e, para garantir a vaga, envie o sinal.';
    }
    foreach ($vars as $key => $value) {
        $message = str_replace('{{' . $key . '}}', (string)$value, $message);
    }
    return trim(preg_replace('/\R{3,}/', "\n\n", $message) ?: $message);
}

function studio_notify_appointment_replacement(array $studio, array $appointment, array $replacement): void
{
    $settings = studio_settings($studio);
    $template = (string)($settings['appointment_overwrite_message'] ?? '');
    $phone = normalize_phone((string)($appointment['customer_phone'] ?? $appointment['phone'] ?? ''));
    if ($phone === '') {
        return;
    }
    $message = studio_format_appointment_message($template, [
        'name' => $appointment['customer_name'] ?: $appointment['title'] ?: 'cliente',
        'date' => format_date_pt((string)$appointment['appointment_date']),
        'start_time' => substr((string)$appointment['start_time'], 0, 5),
        'end_time' => substr((string)($appointment['end_time'] ?? ''), 0, 5),
        'new_date' => format_date_pt((string)$replacement['appointment_date']),
        'new_start_time' => substr((string)$replacement['start_time'], 0, 5),
        'new_end_time' => substr((string)($replacement['end_time'] ?? ''), 0, 5),
        'studio_name' => (string)($studio['name'] ?? 'estudio'),
        'reason' => 'A vaga foi ocupada por outro agendamento confirmado com sinal pago.',
    ]);
    if ($message !== '') {
        try {
            studio_send_whatsapp_message($studio, [
                'phone' => $phone,
                'message' => $message,
            ]);
        } catch (Throwable) {
        }
    }
}

function studio_find_overlapping_appointments(array $studio, string $appointmentDate, string $startTime, string $endTime, ?int $artistId, array $statuses, int $excludeId = 0): array
{
    $pdo = studio_db($studio);
    $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
    if ($artistId !== null && $artistId > 0) {
        $sql = "SELECT a.*, COALESCE(c.name, a.title) AS customer_name, c.phone AS customer_phone, ta.name AS artist_name
                FROM appointments a
                LEFT JOIN customers c ON c.id = a.customer_id
                LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
                WHERE a.appointment_date = ?
                  AND a.id <> ?
                  AND COALESCE(a.artist_id, 0) = ?
                  AND a.status IN ($statusPlaceholders)
                  AND NOT (COALESCE(a.end_time, a.start_time) <= ? OR a.start_time >= ?)
                ORDER BY a.start_time ASC, a.id ASC";
        $stmt = $pdo->prepare($sql);
        $bind = [$appointmentDate, max(0, $excludeId), $artistId];
        foreach ($statuses as $status) {
            $bind[] = $status;
        }
        $bind[] = $startTime;
        $bind[] = $endTime;
        $stmt->execute($bind);
        return $stmt->fetchAll() ?: [];
    }

    $sql = "SELECT a.*, COALESCE(c.name, a.title) AS customer_name, c.phone AS customer_phone, ta.name AS artist_name
            FROM appointments a
            LEFT JOIN customers c ON c.id = a.customer_id
            LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
            WHERE a.appointment_date = ?
              AND a.id <> ?
              AND a.status IN ($statusPlaceholders)
              AND NOT (COALESCE(a.end_time, a.start_time) <= ? OR a.start_time >= ?)
            ORDER BY a.start_time ASC, a.id ASC";
    $stmt = $pdo->prepare($sql);
    $bind = [$appointmentDate, max(0, $excludeId)];
    foreach ($statuses as $status) {
        $bind[] = $status;
    }
    $bind[] = $startTime;
    $bind[] = $endTime;
    $stmt->execute($bind);
    return $stmt->fetchAll() ?: [];
}

function studio_settings(array $studio): array
{
    $stmt = studio_db($studio)->query('SELECT * FROM studio_settings WHERE id = 1 LIMIT 1');
    $settings = $stmt->fetch();

    return is_array($settings) ? $settings : [];
}

function studio_save_settings(array $studio, array $data): void
{
    $studioName = trim((string)($data['studio_name'] ?? $studio['name']));
    $businessRules = trim((string)($data['business_rules'] ?? ''));
    $aiModel = trim((string)($data['ai_model'] ?? 'llama3:8b'));
    $aiEnabled = !empty($data['ai_enabled']) ? 1 : 0;
    $assistantAutofillEnabled = !empty($data['assistant_autofill_enabled']) ? 1 : 0;
    $openAiKey = trim((string)($data['openai_api_key'] ?? ''));
    $openAiModel = trim((string)($data['openai_model'] ?? 'gpt-4o-mini'));
    $aiWhatsAppPrompt = trim((string)($data['ai_whatsapp_prompt'] ?? ''));
    $aiProvider = (string)($data['ai_provider'] ?? 'ollama');
    if (!in_array($aiProvider, ['openai', 'ollama'], true)) {
        $aiProvider = 'ollama';
    }
    $aiApiBaseUrl = trim((string)($data['ai_api_base_url'] ?? ''));
    $appointmentConfirmationMessage = trim((string)($data['appointment_confirmation_message'] ?? ''));
    $whatsappEnabled = !empty($data['whatsapp_enabled']) ? 1 : 0;
    $whatsappProvider = strtolower(trim((string)($data['whatsapp_provider'] ?? 'baileys')));
    if (!in_array($whatsappProvider, ['baileys', 'official'], true)) {
        $whatsappProvider = 'baileys';
    }
    $whatsappOfficialMode = strtolower(trim((string)($data['whatsapp_official_mode'] ?? 'production')));
    if (!in_array($whatsappOfficialMode, ['production', 'sandbox'], true)) {
        $whatsappOfficialMode = 'production';
    }
    $whatsappDefaultMode = (string)($data['whatsapp_default_mode'] ?? 'human') === 'bot' ? 'bot' : 'human';
    $whatsappServiceUrl = rtrim(trim((string)($data['whatsapp_service_url'] ?? 'http://localhost:3010')), '/') ?: 'http://localhost:3010';
    $whatsappOfficialAppId = trim((string)($data['whatsapp_official_app_id'] ?? ''));
    $whatsappOfficialAppSecret = trim((string)($data['whatsapp_official_app_secret'] ?? ''));
    $whatsappOfficialBusinessAccountId = trim((string)($data['whatsapp_official_business_account_id'] ?? ''));
    $whatsappOfficialPhoneNumberId = trim((string)($data['whatsapp_official_phone_number_id'] ?? ''));
    $whatsappOfficialTestBusinessAccountId = trim((string)($data['whatsapp_official_test_business_account_id'] ?? ''));
    $whatsappOfficialTestPhoneNumberId = trim((string)($data['whatsapp_official_test_phone_number_id'] ?? ''));
    $whatsappOfficialAccessToken = trim((string)($data['whatsapp_official_access_token'] ?? ''));
    $whatsappOfficialVerifyToken = trim((string)($data['whatsapp_official_verify_token'] ?? ''));
    $whatsappOfficialCallbackUrl = trim((string)($data['whatsapp_official_callback_url'] ?? ''));
    $whatsappOfficialApiVersion = trim((string)($data['whatsapp_official_api_version'] ?? 'v22.0'));
    $whatsappOfficialWebhookSecret = trim((string)($data['whatsapp_official_webhook_secret'] ?? ''));
    $whatsappOfficialNotes = trim((string)($data['whatsapp_official_notes'] ?? ''));
    $appointmentWorkDaysRaw = $data['appointment_work_days'] ?? '1,2,3,4,5';
    $appointmentWorkDays = is_array($appointmentWorkDaysRaw)
        ? implode(',', array_values(array_filter(array_map('trim', $appointmentWorkDaysRaw), static fn($value) => $value !== '')))
        : trim((string)$appointmentWorkDaysRaw);
    $appointmentTimeSlots = trim((string)($data['appointment_time_slots'] ?? '10:00,15:00'));
    $pomadaUnitPrice = (float)money_to_float((string)($data['pomada_unit_price'] ?? '100'));
    if ($pomadaUnitPrice < 0) {
        $pomadaUnitPrice = 100;
    }
    $durationHours = (int)($data['appointment_duration_hours'] ?? 0);
    $durationMinutesPart = (int)($data['appointment_duration_minutes_part'] ?? 0);
    if ($durationHours > 0 || $durationMinutesPart > 0) {
        $appointmentDurationMinutes = max(0, ($durationHours * 60) + $durationMinutesPart);
    } else {
        $appointmentDurationMinutes = (int)trim((string)($data['appointment_duration_minutes'] ?? '300'));
    }
    $appointmentOverwriteMessage = trim((string)($data['appointment_overwrite_message'] ?? ''));
    $metaCampaignPhrases = trim((string)($data['meta_campaign_phrases'] ?? "Tenho interesse no fechamento!"));
    $metaAdsEnabled = !empty($data['meta_ads_enabled']) ? 1 : 0;
    $metaAdsAppIdInput = trim((string)($data['meta_ads_app_id'] ?? ''));
    $metaAdsAppSecretInput = trim((string)($data['meta_ads_app_secret'] ?? ''));
    $metaAdsAccessTokenInput = trim((string)($data['meta_ads_access_token'] ?? ''));
    $metaAdsBusinessId = trim((string)($data['meta_ads_business_id'] ?? ''));
    $metaAdsAdAccountId = trim((string)($data['meta_ads_ad_account_id'] ?? ''));
    $metaAdsPixelId = trim((string)($data['meta_ads_pixel_id'] ?? ''));
    $metaAdsLeadFormId = trim((string)($data['meta_ads_lead_form_id'] ?? ''));
    $metaAdsApiVersion = trim((string)($data['meta_ads_api_version'] ?? 'v22.0'));
    $metaAdsRedirectUri = trim((string)($data['meta_ads_redirect_uri'] ?? ''));
    $metaAdsNotes = trim((string)($data['meta_ads_notes'] ?? ''));
    if ($appointmentDurationMinutes <= 0) {
        $appointmentDurationMinutes = 300;
    }

    $pdo = studio_db($studio);
    foreach ([
        'appointment_work_days' => 'VARCHAR(40) NOT NULL DEFAULT "1,2,3,4,5"',
        'appointment_time_slots' => 'VARCHAR(80) NOT NULL DEFAULT "10:00,15:00"',
        'appointment_duration_minutes' => 'INT NOT NULL DEFAULT 300',
        'appointment_overwrite_message' => 'TEXT NULL',
        'meta_campaign_phrases' => 'TEXT NULL',
        'pomada_unit_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 100.00',
        'openai_api_key' => 'TEXT NULL',
        'openai_model' => 'VARCHAR(80) NOT NULL DEFAULT "gpt-4o-mini"',
        'ai_whatsapp_prompt' => 'TEXT NULL',
        'ai_provider' => 'VARCHAR(20) NOT NULL DEFAULT "ollama"',
        'ai_api_base_url' => 'VARCHAR(120) NOT NULL DEFAULT "http://localhost:11434/v1"',
        'assistant_autofill_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'appointment_confirmation_message' => 'TEXT NULL',
        'whatsapp_provider' => 'VARCHAR(16) NOT NULL DEFAULT "baileys"',
        'whatsapp_official_mode' => 'VARCHAR(16) NOT NULL DEFAULT "production"',
        'meta_ads_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'meta_ads_app_id' => 'VARCHAR(40) NULL',
        'meta_ads_app_secret' => 'TEXT NULL',
        'meta_ads_access_token' => 'TEXT NULL',
        'meta_ads_business_id' => 'VARCHAR(40) NULL',
        'meta_ads_ad_account_id' => 'VARCHAR(40) NULL',
        'meta_ads_pixel_id' => 'VARCHAR(40) NULL',
        'meta_ads_lead_form_id' => 'VARCHAR(40) NULL',
        'meta_ads_api_version' => 'VARCHAR(20) NOT NULL DEFAULT "v22.0"',
        'meta_ads_redirect_uri' => 'VARCHAR(255) NULL',
        'meta_ads_notes' => 'TEXT NULL',
        'whatsapp_official_app_id' => 'VARCHAR(40) NULL',
        'whatsapp_official_app_secret' => 'TEXT NULL',
        'whatsapp_official_business_account_id' => 'VARCHAR(40) NULL',
        'whatsapp_official_phone_number_id' => 'VARCHAR(40) NULL',
        'whatsapp_official_test_business_account_id' => 'VARCHAR(40) NULL',
        'whatsapp_official_test_phone_number_id' => 'VARCHAR(40) NULL',
        'whatsapp_official_access_token' => 'TEXT NULL',
        'whatsapp_official_verify_token' => 'VARCHAR(120) NULL',
        'whatsapp_official_callback_url' => 'VARCHAR(255) NULL',
        'whatsapp_official_api_version' => 'VARCHAR(20) NOT NULL DEFAULT "v22.0"',
        'whatsapp_official_webhook_secret' => 'VARCHAR(120) NULL',
        'whatsapp_official_notes' => 'TEXT NULL',
    ] as $column => $definition) {
        try {
            $pdo->exec('ALTER TABLE studio_settings ADD COLUMN IF NOT EXISTS ' . $column . ' ' . $definition);
        } catch (Throwable) {
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE studio_settings
         SET studio_name = ?, business_rules = ?, ai_enabled = ?, assistant_autofill_enabled = ?, ai_model = ?, whatsapp_enabled = ?,
             whatsapp_default_mode = ?, whatsapp_service_url = ?, appointment_work_days = ?, appointment_time_slots = ?, appointment_duration_minutes = ?, appointment_overwrite_message = ?, appointment_confirmation_message = ?, meta_campaign_phrases = ?, pomada_unit_price = ?, openai_api_key = ?, openai_model = ?, ai_whatsapp_prompt = ?, ai_provider = ?, ai_api_base_url = ?, whatsapp_provider = ?, whatsapp_official_mode = ?, meta_ads_enabled = ?, meta_ads_app_id = ?, meta_ads_app_secret = ?, meta_ads_access_token = ?, meta_ads_business_id = ?, meta_ads_ad_account_id = ?, meta_ads_pixel_id = ?, meta_ads_lead_form_id = ?, meta_ads_api_version = ?, meta_ads_redirect_uri = ?, meta_ads_notes = ?, whatsapp_official_app_id = ?, whatsapp_official_app_secret = ?, whatsapp_official_business_account_id = ?, whatsapp_official_phone_number_id = ?, whatsapp_official_test_business_account_id = ?, whatsapp_official_test_phone_number_id = ?, whatsapp_official_access_token = ?, whatsapp_official_verify_token = ?, whatsapp_official_callback_url = ?, whatsapp_official_api_version = ?, whatsapp_official_webhook_secret = ?, whatsapp_official_notes = ?, updated_at = NOW()
         WHERE id = 1'
    );
    $stmt->execute([
        $studioName,
        $businessRules,
        $aiEnabled,
        $assistantAutofillEnabled,
        $aiModel,
        $whatsappEnabled,
        $whatsappDefaultMode,
        $whatsappServiceUrl,
        $appointmentWorkDays,
        $appointmentTimeSlots,
        $appointmentDurationMinutes,
        $appointmentOverwriteMessage,
        $appointmentConfirmationMessage,
        $metaCampaignPhrases !== '' ? $metaCampaignPhrases : "Tenho interesse no fechamento!",
        number_format($pomadaUnitPrice, 2, '.', ''),
        $openAiKey,
        $openAiModel !== '' ? $openAiModel : 'gpt-4o-mini',
        $aiWhatsAppPrompt,
        $aiProvider,
        $aiApiBaseUrl !== '' ? rtrim($aiApiBaseUrl, '/') : 'http://localhost:11434/v1',
        $whatsappProvider,
        $whatsappOfficialMode,
        $metaAdsEnabled,
        $metaAdsAppIdInput !== '' ? $metaAdsAppIdInput : ($settings['meta_ads_app_id'] ?? ''),
        $metaAdsAppSecretInput !== '' ? $metaAdsAppSecretInput : ($settings['meta_ads_app_secret'] ?? ''),
        $metaAdsAccessTokenInput !== '' ? $metaAdsAccessTokenInput : ($settings['meta_ads_access_token'] ?? ''),
        $metaAdsBusinessId,
        $metaAdsAdAccountId,
        $metaAdsPixelId,
        $metaAdsLeadFormId,
        $metaAdsApiVersion !== '' ? $metaAdsApiVersion : 'v22.0',
        $metaAdsRedirectUri,
        $metaAdsNotes,
        $whatsappOfficialAppId !== '' ? $whatsappOfficialAppId : ($settings['whatsapp_official_app_id'] ?? ''),
        $whatsappOfficialAppSecret !== '' ? $whatsappOfficialAppSecret : ($settings['whatsapp_official_app_secret'] ?? ''),
        $whatsappOfficialBusinessAccountId !== '' ? $whatsappOfficialBusinessAccountId : ($settings['whatsapp_official_business_account_id'] ?? ''),
        $whatsappOfficialPhoneNumberId !== '' ? $whatsappOfficialPhoneNumberId : ($settings['whatsapp_official_phone_number_id'] ?? ''),
        $whatsappOfficialTestBusinessAccountId !== '' ? $whatsappOfficialTestBusinessAccountId : ($settings['whatsapp_official_test_business_account_id'] ?? ''),
        $whatsappOfficialTestPhoneNumberId !== '' ? $whatsappOfficialTestPhoneNumberId : ($settings['whatsapp_official_test_phone_number_id'] ?? ''),
        $whatsappOfficialAccessToken !== '' ? $whatsappOfficialAccessToken : ($settings['whatsapp_official_access_token'] ?? ''),
        $whatsappOfficialVerifyToken !== '' ? $whatsappOfficialVerifyToken : ($settings['whatsapp_official_verify_token'] ?? ''),
        $whatsappOfficialCallbackUrl !== '' ? $whatsappOfficialCallbackUrl : ($settings['whatsapp_official_callback_url'] ?? ''),
        $whatsappOfficialApiVersion !== '' ? $whatsappOfficialApiVersion : 'v22.0',
        $whatsappOfficialWebhookSecret !== '' ? $whatsappOfficialWebhookSecret : ($settings['whatsapp_official_webhook_secret'] ?? ''),
        $whatsappOfficialNotes !== '' ? $whatsappOfficialNotes : ($settings['whatsapp_official_notes'] ?? ''),
    ]);

    $currentWhatsappStatus = (string)($studio['whatsapp_status'] ?? 'not_configured');
    $nextWhatsappStatus = $whatsappEnabled
        ? (in_array($currentWhatsappStatus, ['connected', 'waiting_qr'], true) ? $currentWhatsappStatus : 'disconnected')
        : 'not_configured';

    db()->prepare(
        'UPDATE studios SET name = ?, business_rules = ?, ai_model = ?, whatsapp_status = ?, updated_at = NOW() WHERE id = ?'
    )->execute([
        $studioName,
        $businessRules,
        $aiModel,
        $nextWhatsappStatus,
        (int)$studio['id'],
    ]);

    studio_event((int)$studio['id'], 'studio_settings_updated', 'Configuracoes operacionais do CRM atualizadas.');
}

function studio_meta_campaign_phrases(array $studio): array
{
    $settings = studio_settings($studio);
    $raw = trim((string)($settings['meta_campaign_phrases'] ?? "Tenho interesse no fechamento!"));
    if ($raw === '') {
        $raw = "Tenho interesse no fechamento!";
    }

    $phrases = preg_split('/\R+/', $raw) ?: [];
    $phrases = array_values(array_filter(array_map(static function (string $phrase): string {
        return trim($phrase);
    }, $phrases), static fn(string $phrase): bool => $phrase !== ''));

    return $phrases ?: ["Tenho interesse no fechamento!"];
}

function studio_meta_campaign_normalize_text(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    return trim($value);
}

function studio_meta_campaign_escape_like(string $value): string
{
    return strtr($value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]);
}

function studio_meta_campaign_entries(array $studio, string $startAt, string $endAt): array
{
    $phrases = studio_meta_campaign_phrases($studio);
    if (!$phrases) {
        return [];
    }

    $normalizedPhrases = array_values(array_unique(array_map(static function (string $phrase): string {
        return studio_meta_campaign_normalize_text($phrase);
    }, $phrases)));
    $normalizedPhrases = array_values(array_filter($normalizedPhrases, static fn(string $phrase): bool => $phrase !== ''));
    if (!$normalizedPhrases) {
        return [];
    }

    $conditions = implode(' OR ', array_fill(0, count($normalizedPhrases), 'LOWER(TRIM(first_msg.body)) LIKE ? ESCAPE \'\\\\\''));
    $sql = "
        SELECT
            wc.id,
            wc.lead_id,
            wc.customer_id,
            wc.phone,
            wc.name,
            first_msg.body AS first_message_body,
            first_msg.sent_at AS first_message_at,
            l.name AS lead_name,
            l.status AS lead_status,
            l.pipeline_stage,
            l.estimated_value,
            l.updated_at AS lead_updated_at,
            c.name AS customer_name
        FROM whatsapp_conversations wc
        INNER JOIN whatsapp_messages first_msg ON first_msg.id = (
            SELECT wm1.id
            FROM whatsapp_messages wm1
            WHERE wm1.conversation_id = wc.id
              AND wm1.direction = 'in'
              AND COALESCE(TRIM(wm1.body), '') <> ''
            ORDER BY wm1.sent_at ASC, wm1.id ASC
            LIMIT 1
        )
        LEFT JOIN leads l ON l.id = wc.lead_id
        LEFT JOIN customers c ON c.id = wc.customer_id
        WHERE first_msg.sent_at BETWEEN ? AND ?
          AND ($conditions)
        ORDER BY first_msg.sent_at DESC, wc.id DESC
        LIMIT 120
    ";

    $params = [$startAt, $endAt];
    foreach ($normalizedPhrases as $phrase) {
        $params[] = '%' . studio_meta_campaign_escape_like($phrase) . '%';
    }
    $stmt = studio_db($studio)->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function studio_meta_ads_messaging_conversations_summary(array $studio, int $days = 30): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $accountId = preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? '')));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0'));
    if ($token === '' || $accountId === '') {
        return ['ok' => false, 'error' => 'Meta Ads sem token ou conta de anuncio configurados.'];
    }
    $days = max(1, min(365, $days));
    $response = studio_meta_ads_request($version, '/act_' . $accountId . '/insights', $token, [
        'fields' => 'actions,unique_actions',
        'date_preset' => $days <= 7 ? 'last_7d' : ($days <= 30 ? 'last_30d' : 'last_90d'),
        'level' => 'account',
        'limit' => 1,
    ]);
    if (!$response['ok']) {
        return $response;
    }

    $items = is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
    $row = $items[0] ?? [];
    $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
    $uniqueActions = is_array($row['unique_actions'] ?? null) ? $row['unique_actions'] : [];
    $matched = [];
    $countFrom = static function (array $list, array $needles, array &$matched): int {
        $total = 0;
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $actionType = strtolower((string)($entry['action_type'] ?? ''));
            if ($actionType === '') {
                continue;
            }
            foreach ($needles as $needle) {
                if (str_contains($actionType, $needle)) {
                    $total += (int)round((float)($entry['value'] ?? 0));
                    $matched[$actionType] = max($matched[$actionType] ?? 0, (int)round((float)($entry['value'] ?? 0)));
                    break;
                }
            }
        }
        return $total;
    };

    $needles = ['messaging_conversation', 'conversation_started', 'messages_started', 'message', 'lead'];
    $reported = $countFrom($actions, $needles, $matched);
    $uniqueReported = $countFrom($uniqueActions, $needles, $matched);
    if ($reported <= 0 && $uniqueReported > 0) {
        $reported = $uniqueReported;
    }

    return [
        'ok' => true,
        'account_id' => 'act_' . $accountId,
        'days' => $days,
        'reported_conversations' => $reported,
        'unique_reported_conversations' => $uniqueReported,
        'matched_actions' => $matched,
    ];
}

function format_money(float|int|string $value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}
