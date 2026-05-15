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

    return $connections[$key];
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
    $value = str_replace(['R$', ' ', '.'], '', $value);
    $value = str_replace(',', '.', $value);

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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
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

function studio_shell_exec_available(): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('shell_exec', $disabled, true);
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
    $needsInstall = !is_dir($servicePath . '/node_modules');

    if (PHP_OS_FAMILY === 'Windows') {
        $launcher = studio_write_windows_whatsapp_launcher($servicePath, $logFile, $port, $needsInstall);
        $startCommand = 'start "" ' . studio_windows_cmd_arg($launcher);
        shell_exec($startCommand . ' 2>&1');
        $startOutput = $needsInstall ? 'Instalacao/inicio disparados em background.' : 'Inicio disparado em background.';
    } else {
        if ($needsInstall) {
            $installCommand = 'cd ' . escapeshellarg($servicePath) . ' && npm install --omit=dev >> ' . escapeshellarg($logFile) . ' 2>&1';
            shell_exec($installCommand . ' &');
            return [
                'ok' => false,
                'pending' => true,
                'error' => 'Dependencias do servico WhatsApp estao sendo instaladas em background. Aguarde alguns segundos e clique novamente.',
                'log_file' => $logFile,
            ];
        }

        $startCommand = 'cd ' . escapeshellarg($servicePath)
            . ' && WHATSAPP_PORT=' . escapeshellarg($port)
            . ' nohup npm start > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
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
        'pending' => $needsInstall,
        'error' => $needsInstall
            ? 'O servico WhatsApp foi disparado e esta instalando dependencias em background. Aguarde alguns segundos e clique novamente.'
            : 'Tentei iniciar o servico WhatsApp automaticamente, mas ele nao respondeu em ' . studio_whatsapp_service_url($studio) . '/health.',
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
            . escapeshellarg('$pids = @(Get-NetTCPConnection -LocalPort ' . $port . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique); foreach ($pid in $pids) { Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue; Write-Output "Stopped PID $pid"; }');
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
    $needsInstall = !is_dir($servicePath . '/node_modules');

    if (PHP_OS_FAMILY === 'Windows') {
        $launcher = studio_write_windows_whatsapp_reset_launcher($servicePath, $sessionPath, $logFile, $port, $needsInstall);
        shell_exec('start "" ' . studio_windows_cmd_arg($launcher) . ' 2>&1');
    } else {
        $install = $needsInstall ? 'npm install --omit=dev >> ' . escapeshellarg($logFile) . ' 2>&1 && ' : '';
        $command = '(lsof -ti tcp:' . escapeshellarg($port) . ' | xargs -r kill; rm -rf ' . escapeshellarg($sessionPath)
            . '; cd ' . escapeshellarg($servicePath) . ' && ' . $install
            . ' WHATSAPP_PORT=' . escapeshellarg($port)
            . ' nohup npm start >> ' . escapeshellarg($logFile) . ' 2>&1 &) 2>&1';
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

function studio_write_windows_whatsapp_launcher(string $servicePath, string $logFile, string $port, bool $install): string
{
    $launcher = $servicePath . '/whatsapp_service_start.cmd';
    $lines = [
        '@echo off',
        'cd /d "' . str_replace('"', '', $servicePath) . '"',
        'set "WHATSAPP_PORT=' . studio_windows_env_value($port) . '"',
    ];

    if ($install) {
        $lines[] = 'npm.cmd install --omit=dev >> "' . str_replace('"', '', $logFile) . '" 2>&1';
    }

    $lines[] = 'npm.cmd start >> "' . str_replace('"', '', $logFile) . '" 2>&1';
    file_put_contents($launcher, implode("\r\n", $lines) . "\r\n");

    return $launcher;
}

function studio_write_windows_whatsapp_reset_launcher(string $servicePath, string $sessionPath, string $logFile, string $port, bool $install): string
{
    $launcher = $servicePath . '/whatsapp_service_reset.cmd';
    $lines = [
        '@echo off',
        'echo [%date% %time%] Reset WhatsApp session started >> "' . str_replace('"', '', $logFile) . '"',
        'powershell -NoProfile -ExecutionPolicy Bypass -Command "$pids = @(Get-NetTCPConnection -LocalPort ' . preg_replace('/\D+/', '', $port) . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique); foreach ($pid in $pids) { Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue; Write-Output \"Stopped PID $pid\" }" >> "' . str_replace('"', '', $logFile) . '" 2>&1',
        'rmdir /S /Q "' . str_replace('"', '', $sessionPath) . '" >> "' . str_replace('"', '', $logFile) . '" 2>&1',
        'cd /d "' . str_replace('"', '', $servicePath) . '"',
        'set "WHATSAPP_PORT=' . studio_windows_env_value($port) . '"',
    ];

    if ($install) {
        $lines[] = 'npm.cmd install --omit=dev >> "' . str_replace('"', '', $logFile) . '" 2>&1';
    }

    $lines[] = 'npm.cmd start >> "' . str_replace('"', '', $logFile) . '" 2>&1';
    file_put_contents($launcher, implode("\r\n", $lines) . "\r\n");

    return $launcher;
}

function studio_whatsapp_service_status(array $studio): array
{
    $sessionKey = studio_session_key($studio);
    $status = studio_whatsapp_request($studio, 'GET', '/studios/' . rawurlencode($sessionKey) . '/status', [], 5);
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
    $sessionKey = studio_session_key($studio);
    $payload = [
        'studioId' => (int)$studio['id'],
        'studioSlug' => (string)$studio['slug'],
        'studioName' => (string)$studio['name'],
        'webhookUrl' => studio_whatsapp_webhook_url(),
        'webhookToken' => studio_whatsapp_webhook_token($studio),
    ];

    $result = studio_whatsapp_request($studio, 'POST', '/studios/' . rawurlencode($sessionKey) . '/start', $payload, 15);
    if (empty($result['ok'])) {
        $autoStart = studio_whatsapp_start_local_service($studio);
        if (!empty($autoStart['ok'])) {
            usleep(1800000);
            $result = studio_whatsapp_request($studio, 'POST', '/studios/' . rawurlencode($sessionKey) . '/start', $payload, 15);
            if (empty($result['ok'])) {
                $result['auto_start'] = $autoStart;
            }
        } else {
            $result['auto_start'] = $autoStart;
        }
    }

    if (empty($result['ok'])) {
        studio_update_whatsapp_platform_status($studio, 'error');
        return $result;
    }

    $status = (string)($result['status'] ?? 'disconnected');
    $platformStatus = match ($status) {
        'connected' => 'connected',
        'waiting_qr' => 'waiting_qr',
        'starting', 'disconnected' => 'disconnected',
        default => 'error',
    };
    studio_update_whatsapp_platform_status($studio, $platformStatus);
    studio_event((int)$studio['id'], 'whatsapp_session_started', 'Sessao WhatsApp solicitada no servico multi-estudio.');
    return $result;
}

function studio_disconnect_whatsapp_session(array $studio): array
{
    $sessionKey = studio_session_key($studio);
    $result = studio_whatsapp_request($studio, 'POST', '/studios/' . rawurlencode($sessionKey) . '/logout', [], 12);
    studio_update_whatsapp_platform_status($studio, empty($result['ok']) ? 'error' : 'disconnected');
    studio_event((int)$studio['id'], 'whatsapp_session_disconnected', 'Sessao WhatsApp desconectada pelo painel.');

    return $result;
}

function studio_reset_whatsapp_session(array $studio): array
{
    $result = studio_reset_whatsapp_session_locally($studio);

    studio_update_whatsapp_platform_status($studio, empty($result['ok']) ? 'error' : 'disconnected');
    studio_event((int)$studio['id'], 'whatsapp_session_reset', 'Sessao WhatsApp limpa para gerar novo QR Code.');

    return $result;
}

function studio_whatsapp_service_log_tail(int $maxBytes = 5000): string
{
    $paths = [
        APP_BASE_PATH . '/storage/logs/whatsapp_service.log',
        APP_BASE_PATH . '/services/whatsapp/whatsapp_service.log',
    ];

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
    $stats = [
        'leads' => 0,
        'customers' => 0,
        'appointments' => 0,
        'open_value' => 0.0,
        'month_revenue' => 0.0,
        'month_expenses' => 0.0,
        'whatsapp_conversations' => 0,
    ];
    $stats['leads'] = (int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    $stats['customers'] = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    $stats['appointments'] = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status NOT IN ('cancelado')")->fetchColumn();
    $stats['open_value'] = (float)$pdo->query("SELECT COALESCE(SUM(estimated_value), 0) FROM leads WHERE status NOT IN ('perdido', 'fechado')")->fetchColumn();
    $stats['month_revenue'] = (float)$pdo->query("SELECT COALESCE(SUM(value), 0) FROM appointments WHERE DATE_FORMAT(appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND status NOT IN ('cancelado')")->fetchColumn();
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
    $stmt = studio_db($studio)->prepare('SELECT * FROM leads ORDER BY updated_at DESC, id DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function studio_upcoming_appointments(array $studio, int $limit = 8): array
{
    $stmt = studio_db($studio)->prepare(
        "SELECT a.*, COALESCE(c.name, a.title) AS customer_name, ta.name AS artist_name, ta.color AS artist_color
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
         WHERE a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelado')
         ORDER BY a.appointment_date ASC, a.start_time ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function studio_list_customers(array $studio, int $limit = 180): array
{
    $stmt = studio_db($studio)->prepare('SELECT * FROM customers ORDER BY updated_at DESC, id DESC LIMIT ?');
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

function studio_customer_activity(array $studio, int $customerId): array
{
    $pdo = studio_db($studio);
    $leadStmt = $pdo->prepare('SELECT * FROM leads WHERE customer_id = ? ORDER BY updated_at DESC, id DESC LIMIT 40');
    $leadStmt->execute([$customerId]);

    $appointmentStmt = $pdo->prepare(
        "SELECT a.*, c.name AS customer_name, l.name AS lead_name, ta.name AS artist_name, ta.color AS artist_color
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
        "SELECT a.*, c.name AS customer_name, l.name AS lead_name, ta.name AS artist_name, ta.color AS artist_color
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
        "SELECT a.*, c.name AS customer_name, l.name AS lead_name, ta.name AS artist_name, ta.color AS artist_color
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
    $summary = [
        'appointments_month' => 0.0,
        'expenses_month' => 0.0,
        'expenses_total' => 0.0,
        'balance_month' => 0.0,
        'by_category' => [],
    ];
    $summary['appointments_month'] = (float)$pdo->query("SELECT COALESCE(SUM(value), 0) FROM appointments WHERE DATE_FORMAT(appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND status NOT IN ('cancelado')")->fetchColumn();
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
        "SELECT wc.*, c.name AS customer_name, l.name AS lead_name, COUNT(wm.id) AS message_count, COALESCE(wc.last_message_at, MAX(wm.sent_at)) AS message_last_at
         FROM whatsapp_conversations wc
         LEFT JOIN customers c ON c.id = wc.customer_id
         LEFT JOIN leads l ON l.id = wc.lead_id
         LEFT JOIN whatsapp_messages wm ON wm.conversation_id = wc.id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " GROUP BY wc.id
         ORDER BY COALESCE(wc.last_message_at, MAX(wm.sent_at), wc.updated_at) DESC, wc.id DESC
         LIMIT ?";

    $stmt = studio_db($studio)->prepare($sql);
    foreach ($params as $index => $param) {
        $stmt->bindValue($index + 1, $param);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function studio_find_whatsapp_conversation(array $studio, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = studio_db($studio)->prepare(
        "SELECT wc.*, c.name AS customer_name, c.email AS customer_email, c.instagram AS customer_instagram, c.notes AS customer_notes,
                l.name AS lead_name, l.interest AS lead_interest, l.status AS lead_status, l.pipeline_stage AS lead_pipeline_stage, l.estimated_value AS lead_estimated_value
         FROM whatsapp_conversations wc
         LEFT JOIN customers c ON c.id = wc.customer_id
         LEFT JOIN leads l ON l.id = wc.lead_id
         WHERE wc.id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $conversation = $stmt->fetch();

    return is_array($conversation) ? $conversation : null;
}

function studio_whatsapp_messages(array $studio, int $conversationId, int $limit = 80): array
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

    return array_reverse($messages);
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
    $timestamp = (int)($payload['timestamp'] ?? time());
    if ($timestamp > 2000000000) {
        $timestamp = (int)floor($timestamp / 1000);
    }
    $sentAt = date('Y-m-d H:i:s', $timestamp > 0 ? $timestamp : time());
    $remoteJid = trim((string)($payload['remoteJid'] ?? $payload['jidCompleto'] ?? ''));
    $needsHuman = studio_whatsapp_needs_human($body);
    $hasMedia = !empty($payload['mediaBase64']) || !empty($payload['mediaUrl']);
    $score = studio_whatsapp_lead_score($body, $hasMedia);

    $stmt = $pdo->prepare(
        'INSERT INTO whatsapp_messages
            (conversation_id, direction, sender_type, body, media_url, media_mime, message_type, message_id, remote_jid, from_me, status, sent_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        (int)$conversation['id'],
        $direction,
        $senderType,
        $body,
        trim((string)($payload['mediaUrl'] ?? '')),
        trim((string)($payload['mediaMime'] ?? '')),
        $messageType,
        $messageId !== '' ? $messageId : null,
        $remoteJid,
        $fromMe ? 1 : 0,
        $fromMe ? 'sent' : null,
        $sentAt,
    ]);

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

    return ['ok' => true, 'conversation_id' => (int)$conversation['id']];
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
    if ($phone === '' || $message === '') {
        throw new RuntimeException('Informe telefone e mensagem.');
    }

    $conversation = studio_find_whatsapp_conversation_by_phone($studio, $phone);
    $jid = trim((string)($conversation['remote_jid'] ?? ''));
    $sessionKey = studio_session_key($studio);
    $result = studio_whatsapp_request($studio, 'POST', '/studios/' . rawurlencode($sessionKey) . '/send', [
        'numero' => $phone,
        'jid' => $jid,
        'mensagem' => $message,
    ], 25);

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
        'tipoMensagem' => 'texto',
    ]);

    return $result;
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

    $context = studio_data_assistant_context($studio);
    $lower = function_exists('mb_strtolower') ? mb_strtolower($question, 'UTF-8') : strtolower($question);
    $lines = [];
    $lines[] = 'Com base nos dados atuais do estudio:';

    if (str_contains($lower, 'agenda') || str_contains($lower, 'agendamento') || str_contains($lower, 'horario') || str_contains($lower, 'calendario')) {
        $appointments = $context['upcoming_appointments'];
        $lines[] = '- Existem ' . count($appointments) . ' proximos agendamentos no recorte rapido.';
        foreach (array_slice($appointments, 0, 6) as $appointment) {
            $lines[] = '- ' . date('d/m/Y', strtotime((string)$appointment['appointment_date'])) . ' as ' . substr((string)$appointment['start_time'], 0, 5) . ': ' . (($appointment['customer_name'] ?? '') ?: $appointment['title']) . ' com ' . (($appointment['artist_name'] ?? '') ?: 'tatuador nao definido') . ' (' . $appointment['status'] . ').';
        }
        if ($context['appointments_by_artist']) {
            $lines[] = 'Por tatuador nos proximos horarios:';
            foreach ($context['appointments_by_artist'] as $row) {
                $lines[] = '- ' . $row['artist'] . ': ' . (int)$row['qtd'] . ' agendamentos, ' . format_money($row['total'] ?? 0) . '.';
            }
        }
    } elseif (str_contains($lower, 'finance') || str_contains($lower, 'fatur') || str_contains($lower, 'despesa') || str_contains($lower, 'resultado')) {
        $finance = $context['finance'];
        $lines[] = '- Agenda do mes: ' . format_money($finance['appointments_month']);
        $lines[] = '- Despesas do mes: ' . format_money($finance['expenses_month']);
        $lines[] = '- Resultado simples do mes: ' . format_money($finance['balance_month']);
        foreach (array_slice($finance['by_category'], 0, 6) as $row) {
            $lines[] = '- Despesa em ' . (($row['category'] ?? '') ?: 'Geral') . ': ' . format_money($row['total'] ?? 0) . '.';
        }
    } elseif (str_contains($lower, 'whatsapp') || str_contains($lower, 'conversa') || str_contains($lower, 'atencao') || str_contains($lower, 'humano')) {
        $wa = $context['whatsapp'];
        $lines[] = '- WhatsApp tem ' . $wa['total'] . ' conversas, ' . $wa['bot'] . ' em IA e ' . $wa['human'] . ' em humano.';
        $lines[] = '- ' . $wa['needs_human'] . ' conversas estao marcadas como pedindo humano.';
        foreach (array_slice($context['whatsapp_conversations'], 0, 6) as $conversation) {
            $name = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: $conversation['phone']));
            $lines[] = '- ' . $name . ': nota ' . (($conversation['lead_score'] ?? '-') ?: '-') . '/10, modo ' . $conversation['attendance_mode'] . ', ultima mensagem: ' . (($conversation['last_message_preview'] ?? '') ?: '-');
        }
    } else {
        $stats = $context['stats'];
        $lines[] = '- Leads no funil: ' . $stats['leads'] . ', clientes cadastrados: ' . $stats['customers'] . '.';
        $lines[] = '- Valor estimado em oportunidades abertas: ' . format_money($stats['open_value']) . '.';
        $lines[] = '- Proximos atendimentos: ' . $stats['appointments'] . '.';
        $lines[] = '- Resultado simples do mes: ' . format_money($stats['month_revenue'] - $stats['month_expenses']) . '.';
        if ($context['hot_leads']) {
            $lines[] = 'Leads mais promissores pelo score:';
            foreach (array_slice($context['hot_leads'], 0, 7) as $lead) {
                $lines[] = '- ' . (($lead['name'] ?? '') ?: ($lead['phone'] ?? 'Sem nome')) . ': ' . (($lead['lead_score'] ?? '-') ?: '-') . '/10, ' . (($lead['interest'] ?? '') ?: 'sem interesse descrito') . ', status ' . $lead['status'] . '.';
            }
        }
    }

    $lines[] = '';
    $lines[] = 'Sugestao pratica: use essa leitura para priorizar contatos com maior nota, horarios proximos e conversas que pediram humano.';

    return [
        'question' => $question,
        'answer' => implode("\n", $lines),
        'context' => $context,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function studio_save_customer(array $studio, array $data): int
{
    $pdo = studio_db($studio);
    $id = (int)($data['id'] ?? 0);
    $values = [
        trim((string)($data['name'] ?? '')),
        trim((string)($data['phone'] ?? '')),
        strtolower(trim((string)($data['email'] ?? ''))),
        trim((string)($data['instagram'] ?? '')),
        trim((string)($data['notes'] ?? '')),
    ];

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, instagram = ?, notes = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([...$values, $id]);
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO customers (name, phone, email, instagram, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function studio_save_lead(array $studio, array $data): int
{
    $pdo = studio_db($studio);
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
        $stmt = $pdo->prepare(
            'UPDATE leads
             SET customer_id = ?, name = ?, phone = ?, interest = ?, status = ?, pipeline_stage = ?, lead_score = ?, estimated_value = ?, source = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([...$values, $id]);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leads
            (customer_id, name, phone, interest, status, pipeline_stage, lead_score, estimated_value, source, last_contact_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())'
    );
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function studio_save_appointment(array $studio, array $data): int
{
    $pdo = studio_db($studio);
    $id = (int)($data['id'] ?? 0);
    $leadId = (int)($data['lead_id'] ?? 0);
    $customerId = (int)($data['customer_id'] ?? 0);
    if ($customerId <= 0 && $leadId > 0) {
        $lead = studio_find_lead($studio, $leadId);
        $customerId = (int)($lead['customer_id'] ?? 0);
    }
    $values = [
        $customerId ?: null,
        $leadId ?: null,
        (int)($data['artist_id'] ?? 0) ?: null,
        trim((string)($data['title'] ?? 'Atendimento')),
        trim((string)($data['description'] ?? '')),
        trim((string)($data['appointment_date'] ?? date('Y-m-d'))),
        trim((string)($data['start_time'] ?? '10:00')),
        trim((string)($data['end_time'] ?? '')),
        trim((string)($data['status'] ?? 'pre_agendado')),
        money_to_float((string)($data['value'] ?? '0')),
        money_to_float((string)($data['deposit_value'] ?? '0')),
    ];
    if ($values[7] === '') {
        $values[7] = null;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE appointments
             SET customer_id = ?, lead_id = ?, artist_id = ?, title = ?, description = ?, appointment_date = ?, start_time = ?, end_time = ?, status = ?, value = ?, deposit_value = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([...$values, $id]);
        studio_sync_lead_from_appointment($studio, $leadId, $values[8], $values[9], $values[5] . ' ' . $values[6]);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO appointments
            (customer_id, lead_id, artist_id, title, description, appointment_date, start_time, end_time, status, value, deposit_value, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute($values);

    $appointmentId = (int)$pdo->lastInsertId();
    studio_sync_lead_from_appointment($studio, $leadId, $values[8], $values[9], $values[5] . ' ' . $values[6]);

    return $appointmentId;
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
    $whatsappEnabled = !empty($data['whatsapp_enabled']) ? 1 : 0;
    $whatsappDefaultMode = (string)($data['whatsapp_default_mode'] ?? 'human') === 'bot' ? 'bot' : 'human';
    $whatsappServiceUrl = rtrim(trim((string)($data['whatsapp_service_url'] ?? 'http://localhost:3010')), '/') ?: 'http://localhost:3010';

    $stmt = studio_db($studio)->prepare(
        'UPDATE studio_settings
         SET studio_name = ?, business_rules = ?, ai_enabled = ?, ai_model = ?, whatsapp_enabled = ?,
             whatsapp_default_mode = ?, whatsapp_service_url = ?, updated_at = NOW()
         WHERE id = 1'
    );
    $stmt->execute([
        $studioName,
        $businessRules,
        $aiEnabled,
        $aiModel,
        $whatsappEnabled,
        $whatsappDefaultMode,
        $whatsappServiceUrl,
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

function format_money(float|int|string $value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}
