<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function app_config(string $key): array
{
    return $GLOBALS['app_config'][$key] ?? [];
}

function app_url(string $page = 'dashboard', array $params = []): string
{
    $params = array_merge(['page' => $page], $params);
    return 'index.php?' . http_build_query($params);
}

function redirect_to(string $page = 'dashboard', array $params = []): never
{
    header('Location: ' . app_url($page, $params));
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sessao expirada. Volte e tente novamente.');
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config('database');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'] ?? 'localhost',
        (int)($config['port'] ?? 3306),
        $config['database'] ?? 'projetocrm_platform',
        $config['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, (string)($config['username'] ?? 'root'), (string)($config['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function db_status(): array
{
    try {
        db()->query('SELECT 1');
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function table_exists(string $table): bool
{
    try {
        $config = app_config('database');
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $stmt->execute([(string)($config['database'] ?? 'projetocrm_platform'), $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function schema_ready(): bool
{
    return table_exists('platform_admins') && table_exists('studios') && table_exists('studio_events') && table_exists('studio_users');
}

function admin_count(): int
{
    if (!schema_ready()) {
        return 0;
    }

    return (int)db()->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn();
}

function current_admin(): ?array
{
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0 || !schema_ready()) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM platform_admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    $admin = $stmt->fetch();

    return is_array($admin) ? $admin : null;
}

function current_studio_user(): ?array
{
    $id = (int)($_SESSION['studio_user_id'] ?? 0);
    if ($id <= 0 || !schema_ready()) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT su.*, s.name AS studio_name, s.slug AS studio_slug, s.status AS studio_status,
                s.database_name, s.database_host, s.database_user, s.plan_name, s.ai_model,
                s.whatsapp_status, s.whatsapp_session_key
         FROM studio_users su
         INNER JOIN studios s ON s.id = su.studio_id
         WHERE su.id = ? AND su.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect_to('login');
    }

    return $admin;
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = strtr($value, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ]);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'studio';
}

function default_studio_database(string $slug): string
{
    $slug = preg_replace('/[^a-z0-9_]+/', '_', str_replace('-', '_', $slug)) ?? 'studio';
    return 'projetocrm_' . trim($slug, '_');
}

function install_admin(string $name, string $email, string $password): void
{
    $stmt = db()->prepare(
        'INSERT INTO platform_admins (name, email, password_hash, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, "owner", 1, NOW(), NOW())'
    );
    $stmt->execute([
        trim($name),
        strtolower(trim($email)),
        password_hash($password, PASSWORD_DEFAULT),
    ]);
}

function login_admin(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM platform_admins WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $admin = $stmt->fetch();

    if (!is_array($admin) || !password_verify($password, (string)$admin['password_hash'])) {
        return false;
    }

    $_SESSION['admin_id'] = (int)$admin['id'];
    unset($_SESSION['studio_user_id']);
    db()->prepare('UPDATE platform_admins SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([(int)$admin['id']]);

    return true;
}

function logout_admin(): void
{
    unset($_SESSION['admin_id']);
}

function login_studio_user(string $email, string $password): bool
{
    $stmt = db()->prepare(
        'SELECT su.*, s.status AS studio_status
         FROM studio_users su
         INNER JOIN studios s ON s.id = su.studio_id
         WHERE su.email = ? AND su.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!is_array($user) || !password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    if (in_array((string)$user['studio_status'], ['disabled', 'paused'], true)) {
        return false;
    }

    $_SESSION['studio_user_id'] = (int)$user['id'];
    unset($_SESSION['admin_id']);
    db()->prepare('UPDATE studio_users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);

    return true;
}

function logout_studio_user(): void
{
    unset($_SESSION['studio_user_id']);
}

function list_studios(): array
{
    $stmt = db()->query('SELECT * FROM studios ORDER BY created_at DESC, id DESC');
    return $stmt->fetchAll() ?: [];
}

function get_studio(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM studios WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $studio = $stmt->fetch();

    return is_array($studio) ? $studio : null;
}

function get_studio_by_session_key(string $sessionKey): ?array
{
    $stmt = db()->prepare('SELECT * FROM studios WHERE whatsapp_session_key = ? LIMIT 1');
    $stmt->execute([$sessionKey]);
    $studio = $stmt->fetch();

    return is_array($studio) ? $studio : null;
}

function create_studio(array $data, int $adminId): int
{
    $name = trim((string)($data['name'] ?? ''));
    $slug = slugify((string)($data['slug'] ?? $name));
    $databaseName = trim((string)($data['database_name'] ?? ''));
    if ($databaseName === '') {
        $databaseName = default_studio_database($slug);
    }

    $stmt = db()->prepare(
        'INSERT INTO studios
            (name, slug, status, owner_name, owner_email, owner_phone, database_name, database_host, database_user, plan_name, ai_model, business_rules, whatsapp_session_key, created_by, created_at, updated_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $nextId = (int)db()->query("SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studios'")->fetchColumn();
    $stmt->execute([
        $name,
        $slug,
        (string)($data['status'] ?? 'setup'),
        trim((string)($data['owner_name'] ?? '')),
        strtolower(trim((string)($data['owner_email'] ?? ''))),
        trim((string)($data['owner_phone'] ?? '')),
        $databaseName,
        trim((string)($data['database_host'] ?? 'localhost')),
        trim((string)($data['database_user'] ?? 'root')),
        trim((string)($data['plan_name'] ?? 'alpha')),
        trim((string)($data['ai_model'] ?? 'llama3:8b')),
        trim((string)($data['business_rules'] ?? '')),
        studio_session_key_from_parts($nextId, $slug),
        $adminId,
    ]);

    $studioId = (int)db()->lastInsertId();
    studio_event($studioId, 'studio_created', 'Estudio cadastrado na plataforma alpha.');

    return $studioId;
}

function update_studio(array $studio, array $data): void
{
    $stmt = db()->prepare(
        'UPDATE studios
         SET name = ?, status = ?, owner_name = ?, owner_email = ?, owner_phone = ?, database_name = ?,
             database_host = ?, database_user = ?, plan_name = ?, ai_model = ?, business_rules = ?,
             whatsapp_session_key = IF(whatsapp_session_key IS NULL OR whatsapp_session_key = "", ?, whatsapp_session_key),
             updated_at = NOW()
         WHERE id = ?'
    );
    $sessionKey = studio_session_key_from_parts((int)$studio['id'], (string)$studio['slug']);
    $stmt->execute([
        trim((string)($data['name'] ?? $studio['name'])),
        trim((string)($data['status'] ?? $studio['status'])),
        trim((string)($data['owner_name'] ?? '')),
        strtolower(trim((string)($data['owner_email'] ?? ''))),
        trim((string)($data['owner_phone'] ?? '')),
        trim((string)($data['database_name'] ?? $studio['database_name'])),
        trim((string)($data['database_host'] ?? 'localhost')),
        trim((string)($data['database_user'] ?? 'root')),
        trim((string)($data['plan_name'] ?? 'alpha')),
        trim((string)($data['ai_model'] ?? 'llama3:8b')),
        trim((string)($data['business_rules'] ?? '')),
        $sessionKey,
        (int)$studio['id'],
    ]);

    studio_event((int)$studio['id'], 'studio_updated', 'Dados do estudio atualizados.');
}

function studio_session_key_from_parts(int $studioId, string $slug): string
{
    $base = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($slug)) ?: 'studio';
    return trim($base, '-') . '-' . $studioId;
}

function studio_event(int $studioId, string $type, string $message): void
{
    if (!table_exists('studio_events')) {
        return;
    }

    $stmt = db()->prepare('INSERT INTO studio_events (studio_id, type, message, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute([$studioId, $type, $message]);
}

function studio_events(int $studioId): array
{
    $stmt = db()->prepare('SELECT * FROM studio_events WHERE studio_id = ? ORDER BY created_at DESC, id DESC LIMIT 8');
    $stmt->execute([$studioId]);
    return $stmt->fetchAll() ?: [];
}

function studio_users(int $studioId): array
{
    $stmt = db()->prepare('SELECT id, studio_id, name, email, role, is_active, last_login_at, created_at FROM studio_users WHERE studio_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$studioId]);
    return $stmt->fetchAll() ?: [];
}

function create_or_update_studio_owner_user(array $studio, string $name, string $email, string $password): void
{
    $name = trim($name);
    $email = strtolower(trim($email));
    if ($name === '' || $email === '') {
        throw new RuntimeException('Informe nome e email do usuario do estudio.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('A senha do usuario do estudio precisa ter pelo menos 8 caracteres.');
    }

    $stmt = db()->prepare('SELECT id FROM studio_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $stmt = db()->prepare(
            'UPDATE studio_users
             SET studio_id = ?, name = ?, password_hash = ?, role = "owner", is_active = 1, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([(int)$studio['id'], $name, password_hash($password, PASSWORD_DEFAULT), $existingId]);
        studio_event((int)$studio['id'], 'studio_user_updated', 'Acesso principal do estudio atualizado.');
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO studio_users (studio_id, name, email, password_hash, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, "owner", 1, NOW(), NOW())'
    );
    $stmt->execute([(int)$studio['id'], $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    studio_event((int)$studio['id'], 'studio_user_created', 'Acesso principal do estudio criado.');
}

function studio_database_exists(array $studio): bool
{
    $database = (string)($studio['database_name'] ?? '');
    if ($database === '') {
        return false;
    }

    try {
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
        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
        $stmt->execute([$database]);

        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function studio_sql(array $studio): string
{
    $template = (string)file_get_contents(APP_BASE_PATH . '/database/studio_alpha_template.sql');
    $replace = [
        '{{DATABASE_NAME}}' => str_replace('`', '', (string)$studio['database_name']),
        '{{STUDIO_NAME}}' => str_replace(["\\", "'"], ["\\\\", "''"], (string)$studio['name']),
        '{{STUDIO_SLUG}}' => str_replace(["\\", "'"], ["\\\\", "''"], (string)$studio['slug']),
        '{{BUSINESS_RULES}}' => str_replace(["\\", "'"], ["\\\\", "''"], (string)($studio['business_rules'] ?? '')),
        '{{AI_MODEL}}' => str_replace(["\\", "'"], ["\\\\", "''"], (string)($studio['ai_model'] ?? 'llama3:8b')),
    ];

    return strtr($template, $replace);
}

function stats(): array
{
    if (!schema_ready()) {
        return ['studios' => 0, 'active' => 0, 'setup' => 0];
    }

    $row = db()->query(
        "SELECT
            COUNT(*) AS studios,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'setup' THEN 1 ELSE 0 END) AS setup
         FROM studios"
    )->fetch() ?: [];

    return [
        'studios' => (int)($row['studios'] ?? 0),
        'active' => (int)($row['active'] ?? 0),
        'setup' => (int)($row['setup'] ?? 0),
    ];
}
