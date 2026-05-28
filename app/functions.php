<?php

declare(strict_types=1);

function h(mixed $value): string
{
    $text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return htmlspecialchars(repair_display_text($text), ENT_QUOTES, 'UTF-8');
}

function repair_display_text(string $value): string
{
    $pattern = '/\x{00C3}|\x{00C2}|\x{00C6}|\x{2018}|\x{2019}|\x{201C}|\x{201D}|\x{2026}/u';
    if ($value === '' || !preg_match($pattern, $value)) {
        return $value;
    }

    $current = $value;
    for ($i = 0; $i < 4; $i++) {
        $next = @iconv('UTF-8', 'Windows-1252//IGNORE', $current);
        if (!is_string($next) || $next === '' || $next === $current) {
            break;
        }

        $currentScore = preg_match_all($pattern, $current);
        $nextScore = preg_match_all($pattern, $next);
        if ($nextScore > $currentScore) {
            break;
        }

        $current = $next;
        if (!preg_match($pattern, $current)) {
            break;
        }
    }

    return $current;
}

function normalize_display_value(mixed $value): mixed
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = normalize_display_value($item);
        }
        return $normalized;
    }

    if (is_string($value)) {
        return repair_display_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return $value;
}

function app_config(string $key): array
{
    return $GLOBALS['app_config'][$key] ?? [];
}

function app_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = rtrim(str_replace('/index.php', '', dirname($scriptName)), '/');
    if ($basePath === '' || $basePath === '.' || $basePath === '/') {
        return '';
    }

    return $basePath;
}

function app_url(string $page = 'dashboard', array $params = []): string
{
    $params = array_merge(['page' => $page], $params);
    return app_base_path() . '/index.php?' . http_build_query($params);
}

function app_asset_url(string $path): string
{
    $path = ltrim($path, '/');
    return app_base_path() . '/' . $path;
}

function format_date_pt(string $value, bool $withWeekday = true): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }

    try {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $date = str_contains($value, ' ') || str_contains($value, 'T')
            ? new DateTimeImmutable($value, $tz)
            : new DateTimeImmutable($value . ' 00:00:00', $tz);
        $weekday = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sb'][(int)$date->format('w')] ?? '';
        $label = strtoupper($weekday) . ' - ' . $date->format('d/m/Y');
        return $withWeekday ? $label : $date->format('d/m/Y');
    } catch (Throwable) {
        return $value;
    }
}

function format_datetime_pt(string $value, bool $withWeekday = true): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }

    try {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $date = new DateTimeImmutable($value, $tz);
        $weekday = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sb'][(int)$date->format('w')] ?? '';
        $label = strtoupper($weekday) . ' - ' . $date->format('d/m/Y H:i');
        return $withWeekday ? $label : $date->format('d/m/Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

function normalize_spaces(string $value): string
{
    $value = trim($value);
    $normalized = preg_replace('/\s+/u', ' ', $value);
    return $normalized !== null ? $normalized : '';
}

function import_uid(string $uid): string
{
    return sha1($uid !== '' ? $uid : uniqid('calendar-', true));
}

function default_artist_id(array $studio): ?int
{
    $artists = studio_list_artists($studio);
    return $artists ? (int)$artists[0]['id'] : null;
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
    $escape = function_exists('h') ? 'h' : static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $escape(csrf_token()) . '">';
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

function column_exists(string $table, string $column): bool
{
    try {
        $config = app_config('database');
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([(string)($config['database'] ?? 'projetocrm_platform'), $table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function schema_ready(): bool
{
    return table_exists('platform_admins') && table_exists('studios') && table_exists('studio_events') && table_exists('studio_users');
}

function commercial_plans_ready(): bool
{
    return table_exists('commercial_plans');
}

function studio_plan_assignment_ready(): bool
{
    return commercial_plans_ready() && column_exists('studios', 'plan_id');
}

function seed_default_commercial_plans(): void
{
    if (!commercial_plans_ready()) {
        return;
    }

    $count = (int)db()->query('SELECT COUNT(*) FROM commercial_plans')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $hasExtendedFields = column_exists('commercial_plans', 'short_description');
    if ($hasExtendedFields) {
        $stmt = db()->prepare(
            'INSERT INTO commercial_plans
                (name, slug, short_description, description, monthly_price, annual_price, currency_code, recommended, studio_limit, user_limit, tattoo_artist_limit, lead_limit, whatsapp_session_limit, allow_whatsapp, allow_ai, allow_data_assistant, allow_finance, allow_advanced_reports, allow_automations, allow_multi_studio, allow_external_integrations, allow_advanced_customization, features_text, limits_text, sort_order, is_active, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, "BRL", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
        );

        $defaults = [
            [
                'Basico',
                'basico',
                'Para tatuadores solo ou estdios pequenos comeando a organizar atendimento e agenda.',
                'Para tatuadores solo ou estdios pequenos comeando a organizar atendimento e agenda.',
                79.00,
                790.00,
                0,
                1,
                2,
                1,
                500,
                0,
                0,
                1,
                0,
                0,
                1,
                0,
                0,
                0,
                0,
                0,
                "Cadastro de clientes\nLeads e funil\nAgenda\nFinanceiro simples\nRespostas rpidas\nRelatrios bsicos",
                "Usuarios: 2\nTatuadores: 1\nClientes/leads: 500\nWhatsApp: limitado",
                1,
            ],
            [
                'Profissional',
                'profissional',
                'Para estdios que recebem muitos leads e precisam controlar WhatsApp, agenda, equipe e vendas.',
                'Para estdios que recebem muitos leads e precisam controlar WhatsApp, agenda, equipe e vendas.',
                149.00,
                1490.00,
                1,
                1,
                5,
                5,
                3000,
                1,
                1,
                1,
                1,
                1,
                1,
                0,
                0,
                0,
                0,
                0,
                "Tudo do Bsico\nWhatsApp/Baileys\nCentral de atendimento\nRespostas rpidas avanadas\nAgenda com controle de conflitos\nRelatrios gerenciais\nPermisses por usurio\nFollow-up manual/assistido",
                "Usuarios: 5\nTatuadores: 5\nClientes/leads: 3000\nWhatsApp: 1 sesso",
                2,
            ],
            [
                'Avancado',
                'avancado',
                'Para estdios maiores, redes ou operaes que querem automao, IA e relatrios avanados.',
                'Para estdios maiores, redes ou operaes que querem automao, IA e relatrios avanados.',
                299.00,
                2990.00,
                1,
                3,
                15,
                15,
                20000,
                3,
                1,
                1,
                1,
                1,
                1,
                1,
                1,
                1,
                1,
                1,
                "Tudo do Profissional\nIA para classificao de leads\nAssistente de dados\nSugesto de respostas por IA\nAutomaes de follow-up\nRelatrios avanados/BI\nMulti-estdio\nIntegraes externas/API\nPersonalizao avanada do funil",
                "Estdios: 3\nUsuarios: 15\nTatuadores: 15\nClientes/leads: 20000\nWhatsApp: 3 sesses",
                3,
            ],
        ];
    } else {
        $stmt = db()->prepare(
            'INSERT INTO commercial_plans
                (name, slug, description, monthly_price, annual_price, currency_code, features_text, limits_text, sort_order, is_active, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, "BRL", ?, ?, ?, 1, NOW(), NOW())'
        );

        $defaults = [
            ['Basico', 'basico', 'Para tatuadores solo ou estdios pequenos comeando a organizar atendimento e agenda.', 79.00, 790.00, "Cadastro de clientes\nLeads e funil\nAgenda\nFinanceiro simples\nRespostas rpidas\nRelatrios bsicos", "Usuarios: 2\nTatuadores: 1\nClientes/leads: 500\nWhatsApp: limitado", 1],
            ['Profissional', 'profissional', 'Para estdios que recebem muitos leads e precisam controlar WhatsApp, agenda, equipe e vendas.', 149.00, 1490.00, "Tudo do Bsico\nWhatsApp/Baileys\nCentral de atendimento\nRespostas rpidas avanadas\nAgenda com controle de conflitos\nRelatrios gerenciais\nPermisses por usurio\nFollow-up manual/assistido", "Usuarios: 5\nTatuadores: 5\nClientes/leads: 3000\nWhatsApp: 1 sesso", 2],
            ['Avancado', 'avancado', 'Para estdios maiores, redes ou operaes que querem automao, IA e relatrios avanados.', 299.00, 2990.00, "Tudo do Profissional\nIA para classificao de leads\nAssistente de dados\nSugesto de respostas por IA\nAutomaes de follow-up\nRelatrios avanados/BI\nMulti-estdio\nIntegraes externas/API\nPersonalizao avanada do funil", "Estdios: 3\nUsuarios: 15\nTatuadores: 15\nClientes/leads: 20000\nWhatsApp: 3 sesses", 3],
        ];
    }

    foreach ($defaults as $plan) {
        $stmt->execute($plan);
    }
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
        '' => 'a', '' => 'a', '' => 'a', '' => 'a', '' => 'a',
        '' => 'e', '' => 'e', '' => 'e', '' => 'e',
        '' => 'i', '' => 'i', '' => 'i', '' => 'i',
        '' => 'o', '' => 'o', '' => 'o', '' => 'o', '' => 'o',
        '' => 'u', '' => 'u', '' => 'u', '' => 'u',
        '' => 'c',
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

function list_commercial_plans(bool $onlyActive = false): array
{
    if (!commercial_plans_ready()) {
        return [];
    }

    seed_default_commercial_plans();

    $sql = 'SELECT * FROM commercial_plans';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $stmt = db()->query($sql);
    return $stmt->fetchAll() ?: [];
}

function get_commercial_plan(int $id): ?array
{
    if ($id <= 0 || !commercial_plans_ready()) {
        return null;
    }

    seed_default_commercial_plans();
    $stmt = db()->prepare('SELECT * FROM commercial_plans WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $plan = $stmt->fetch();

    return is_array($plan) ? $plan : null;
}

function get_commercial_plan_by_slug(string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '' || !commercial_plans_ready()) {
        return null;
    }

    seed_default_commercial_plans();
    $stmt = db()->prepare('SELECT * FROM commercial_plans WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $plan = $stmt->fetch();

    return is_array($plan) ? $plan : null;
}

function resolve_studio_plan(array $studio): ?array
{
    $planId = (int)($studio['plan_id'] ?? 0);
    if ($planId > 0) {
        $plan = get_commercial_plan($planId);
        if ($plan) {
            return $plan;
        }
    }

    $planName = trim((string)($studio['plan_name'] ?? ''));
    if ($planName !== '') {
        return get_commercial_plan_by_slug($planName);
    }

    return null;
}

function current_studio_context(): ?array
{
    if (function_exists('current_studio')) {
        $studio = current_studio();
        if (is_array($studio)) {
            return $studio;
        }
    }

    $user = current_studio_user();
    if (is_array($user)) {
        return $user;
    }

    return null;
}

function plan_default_access(): array
{
    return [
        'whatsapp' => true,
        'finance' => true,
        'reports' => true,
        'basic_dashboard' => true,
        'ai' => false,
        'ai_data_assistant' => false,
        'advanced_reports' => false,
        'automations' => false,
        'multi_studio' => false,
        'api_integrations' => false,
        'custom_pipeline' => false,
    ];
}

function plan_feature_map(): array
{
    return [
        'whatsapp' => ['column' => 'allow_whatsapp', 'default' => true],
        'finance' => ['column' => 'allow_finance', 'default' => true],
        'reports' => ['column' => 'allow_advanced_reports', 'default' => true],
        'ai' => ['column' => 'allow_ai', 'default' => false],
        'ai_data_assistant' => ['column' => 'allow_data_assistant', 'default' => false],
        'advanced_reports' => ['column' => 'allow_advanced_reports', 'default' => false],
        'automations' => ['column' => 'allow_automations', 'default' => false],
        'multi_studio' => ['column' => 'allow_multi_studio', 'default' => false],
        'api_integrations' => ['column' => 'allow_external_integrations', 'default' => false],
        'custom_pipeline' => ['column' => 'allow_advanced_customization', 'default' => false],
    ];
}

function plan_limit_map(): array
{
    return [
        'max_studios' => ['column' => 'studio_limit', 'default' => 1],
        'max_users' => ['column' => 'user_limit', 'default' => 1],
        'max_tattooers' => ['column' => 'tattoo_artist_limit', 'default' => 1],
        'max_clients' => ['column' => 'lead_limit', 'default' => 100],
        'max_whatsapp_sessions' => ['column' => 'whatsapp_session_limit', 'default' => 0],
        'max_ai_requests_month' => ['column' => null, 'default' => 0],
    ];
}

function current_studio_plan(): ?array
{
    static $cached = null;
    static $hasCached = false;

    if ($hasCached) {
        return $cached;
    }
    $hasCached = true;

    $studio = current_studio_context();
    if (!$studio) {
        return $cached = null;
    }

    $plan = resolve_studio_plan($studio);
    if ($plan) {
        return $cached = $plan;
    }

    $fallbackSlug = trim((string)($studio['plan_name'] ?? 'basico'));
    return $cached = [
        'id' => null,
        'name' => $fallbackSlug !== '' ? ucfirst(str_replace(['-', '_'], ' ', $fallbackSlug)) : 'Basico',
        'slug' => $fallbackSlug !== '' ? $fallbackSlug : 'basico',
        'short_description' => null,
        'description' => null,
        'monthly_price' => 0,
        'annual_price' => 0,
        'currency_code' => 'BRL',
        'recommended' => 0,
        'studio_limit' => 1,
        'user_limit' => 1,
        'tattoo_artist_limit' => 1,
        'lead_limit' => 100,
        'whatsapp_session_limit' => 0,
        'allow_whatsapp' => 1,
        'allow_ai' => 0,
        'allow_data_assistant' => 0,
        'allow_finance' => 1,
        'allow_advanced_reports' => 0,
        'allow_automations' => 0,
        'allow_multi_studio' => 0,
        'allow_external_integrations' => 0,
        'allow_advanced_customization' => 0,
        'features_text' => null,
        'limits_text' => null,
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function plan_allows(string $resource): bool
{
    $resource = strtolower(trim($resource));
    if ($resource === '') {
        return false;
    }

    $plan = current_studio_plan();
    $map = plan_feature_map();
    $config = $map[$resource] ?? null;
    if (!$config) {
        return false;
    }

    if (!$plan) {
        return (bool)($config['default'] ?? false);
    }

    $column = (string)($config['column'] ?? '');
    if ($column !== '' && array_key_exists($column, $plan)) {
        return !empty($plan[$column]);
    }

    return (bool)($config['default'] ?? false);
}

function plan_limit(string $limitKey): int
{
    $limitKey = strtolower(trim($limitKey));
    if ($limitKey === '') {
        return 0;
    }

    $plan = current_studio_plan();
    $map = plan_limit_map();
    $config = $map[$limitKey] ?? null;
    if (!$config) {
        return 0;
    }

    if (!$plan) {
        return (int)($config['default'] ?? 0);
    }

    $column = (string)($config['column'] ?? '');
    if ($column !== '' && array_key_exists($column, $plan) && $plan[$column] !== null && $plan[$column] !== '') {
        return max(0, (int)$plan[$column]);
    }

    if ($limitKey === 'max_ai_requests_month') {
        $slug = (string)($plan['slug'] ?? '');
        return match ($slug) {
            'avancado' => 10000,
            'profissional' => 2000,
            'basico' => 0,
            default => 0,
        };
    }

    return (int)($config['default'] ?? 0);
}

function plan_blocked_message(string $resource, ?string $minimumPlan = null): string
{
    $resource = strtolower(trim($resource));
    $labels = [
        'whatsapp' => 'WhatsApp',
        'ai' => 'IA',
        'ai_data_assistant' => 'assistente de dados',
        'advanced_reports' => 'relatrios avanados',
        'automations' => 'automaes',
        'multi_studio' => 'multi-estdio',
        'api_integrations' => 'integraes externas',
        'custom_pipeline' => 'funil personalizado',
    ];

    $label = $labels[$resource] ?? 'este recurso';
    $planName = trim((string)($minimumPlan ?? ''));
    if ($planName === '') {
        $planName = match ($resource) {
            'whatsapp', 'finance' => 'Bsico',
            'ai', 'ai_data_assistant', 'advanced_reports', 'automations', 'multi_studio', 'api_integrations', 'custom_pipeline' => 'Profissional',
            default => 'Profissional',
        };
    }

    return 'Este recurso (' . $label . ') est disponvel a partir do plano ' . $planName . '.';
}

function current_studio_plan_name(): string
{
    $plan = current_studio_plan();
    if (is_array($plan) && trim((string)($plan['name'] ?? '')) !== '') {
        return (string)$plan['name'];
    }

    $studio = current_studio_context();
    $fallback = $studio ? trim((string)($studio['plan_name'] ?? '')) : '';
    if ($fallback !== '') {
        return ucfirst(str_replace(['-', '_'], ' ', $fallback));
    }

    return 'Basico';
}

function public_sales_whatsapp_number(): string
{
    $config = app_config('app');
    $raw = trim((string)($config['sales_whatsapp_number'] ?? getenv('CRM_SALES_WHATSAPP_NUMBER') ?? ''));
    $number = preg_replace('/\D+/', '', $raw) ?: '';

    if ($number !== '') {
        return $number;
    }

    return '5511999999999';
}

function public_sales_whatsapp_url(?string $planName = null): string
{
    $planName = trim((string)($planName ?? ''));
    $message = $planName !== ''
        ? 'Ol! Tenho interesse no plano ' . $planName . ' do CRM para estdio de tatuagem.'
        : 'Ol! Tenho interesse nos planos do CRM para estdio de tatuagem.';

    return 'https://wa.me/' . public_sales_whatsapp_number() . '?text=' . rawurlencode($message);
}

function commercial_plan_public_features(array $plan): array
{
    $features = [];
    $featuresText = trim((string)($plan['features_text'] ?? ''));
    if ($featuresText !== '') {
        foreach (preg_split('/\R+/', $featuresText) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $features[] = $line;
            }
        }
    }

    if (!$features) {
        $map = [
            'allow_whatsapp' => 'WhatsApp integrado',
            'allow_ai' => 'IA',
            'allow_data_assistant' => 'Assistente de dados',
            'allow_finance' => 'Financeiro',
            'allow_advanced_reports' => 'Relatrios avanados',
            'allow_automations' => 'Automaes',
            'allow_multi_studio' => 'Multi-estdio',
            'allow_external_integrations' => 'Integraes externas/API',
            'allow_advanced_customization' => 'Personalizao avanada do funil',
        ];

        foreach ($map as $column => $label) {
            if (!empty($plan[$column])) {
                $features[] = $label;
            }
        }

        if (empty($features)) {
            $fallback = [
                'Clientes e leads',
                'Funil de vendas',
                'Agenda',
                'Respostas rpidas',
            ];
            $features = $fallback;
        }
    }

    return array_values(array_unique(array_filter($features)));
}

function commercial_plan_public_limits(array $plan): array
{
    $toLabel = static function ($value): string {
        if ($value === null || $value === '') {
            return 'Ilimitado';
        }

        $value = max(0, (int)$value);
        return $value === 0 ? '0' : (string)$value;
    };

    return [
        ['label' => 'Estdios', 'value' => $toLabel($plan['studio_limit'] ?? null)],
        ['label' => 'Usurios', 'value' => $toLabel($plan['user_limit'] ?? null)],
        ['label' => 'Tatuadores', 'value' => $toLabel($plan['tattoo_artist_limit'] ?? null)],
        ['label' => 'Clientes/leads', 'value' => $toLabel($plan['lead_limit'] ?? null)],
        ['label' => 'Sesses WhatsApp', 'value' => $toLabel($plan['whatsapp_session_limit'] ?? null)],
    ];
}

function commercial_plan_public_flag_rows(array $plan): array
{
    $rows = [
        ['label' => 'Clientes e leads', 'enabled' => true],
        ['label' => 'Funil de vendas', 'enabled' => true],
        ['label' => 'Agenda', 'enabled' => true],
        ['label' => 'Financeiro', 'enabled' => !empty($plan['allow_finance'])],
        ['label' => 'Respostas rpidas', 'enabled' => true],
        ['label' => 'WhatsApp integrado', 'enabled' => !empty($plan['allow_whatsapp'])],
        ['label' => 'IA', 'enabled' => !empty($plan['allow_ai'])],
        ['label' => 'Assistente de dados', 'enabled' => !empty($plan['allow_data_assistant'])],
        ['label' => 'Automaes', 'enabled' => !empty($plan['allow_automations'])],
        ['label' => 'Relatrios avanados', 'enabled' => !empty($plan['allow_advanced_reports'])],
        ['label' => 'Multi-estdio', 'enabled' => !empty($plan['allow_multi_studio'])],
        ['label' => 'Integraes externas/API', 'enabled' => !empty($plan['allow_external_integrations'])],
        ['label' => 'Personalizao avanada do funil', 'enabled' => !empty($plan['allow_advanced_customization'])],
    ];

    return array_values(array_filter($rows, static fn(array $row): bool => true));
}

function studio_user_count(int $studioId): int
{
    if ($studioId <= 0 || !table_exists('studio_users')) {
        return 0;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM studio_users WHERE studio_id = ?');
    $stmt->execute([$studioId]);

    return (int)$stmt->fetchColumn();
}

function studio_artist_count(array $studio): int
{
    try {
        return (int)studio_db($studio)->query('SELECT COUNT(*) FROM tattoo_artists')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function studio_customer_count(array $studio): int
{
    try {
        return (int)studio_db($studio)->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function studio_lead_count(array $studio): int
{
    try {
        return (int)studio_db($studio)->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function studio_whatsapp_session_count(array $studio): int
{
    $sessionKey = trim((string)($studio['whatsapp_session_key'] ?? ''));
    $status = trim((string)($studio['whatsapp_status'] ?? ''));
    if ($sessionKey === '' && $status === '') {
        return 0;
    }

    return 1;
}

function commercial_plan_display_name(?array $plan, ?string $fallback = null): string
{
    if (is_array($plan) && trim((string)($plan['name'] ?? '')) !== '') {
        return (string)$plan['name'];
    }

    $fallback = trim((string)$fallback);
    return $fallback !== '' ? $fallback : 'Sem plano';
}

function studio_plan_snapshot(array $data, ?array $studio = null): array
{
    $planId = (int)($data['plan_id'] ?? ($studio['plan_id'] ?? 0));
    $planName = trim((string)($data['plan_name'] ?? ($studio['plan_name'] ?? 'basico')));

    if (commercial_plans_ready()) {
        seed_default_commercial_plans();
        if ($planId > 0) {
            $plan = get_commercial_plan($planId);
            if ($plan) {
                return [
                    'plan_id' => (int)$plan['id'],
                    'plan_name' => (string)$plan['slug'],
                ];
            }
        }

        if ($planName !== '') {
            $plan = get_commercial_plan_by_slug($planName);
            if ($plan) {
                return [
                    'plan_id' => (int)$plan['id'],
                    'plan_name' => (string)$plan['slug'],
                ];
            }
        }
    }

    return [
        'plan_id' => $planId > 0 ? $planId : null,
        'plan_name' => $planName !== '' ? $planName : 'basico',
    ];
}

function save_commercial_plan(array $data): int
{
    if (!commercial_plans_ready()) {
        throw new RuntimeException('Tabela de planos comerciais ainda nao instalada.');
    }

    $moneyFromInput = static function (string $key) use ($data): float {
        if (!array_key_exists($key, $data)) {
            return 0.0;
        }

        $raw = trim((string)$data[$key]);
        if ($raw === '') {
            return 0.0;
        }

        $raw = str_replace(['R$', ' '], '', $raw);
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);

        return round((float)$raw, 2);
    };

    $nullableIntFromInput = static function (string $key) use ($data): ?int {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $raw = trim((string)$data[$key]);
        if ($raw === '') {
            return null;
        }

        return max(0, (int)$raw);
    };

    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $slug = slugify((string)($data['slug'] ?? $name));
    $description = trim((string)($data['description'] ?? ''));
    $monthlyPrice = $moneyFromInput('monthly_price');
    $annualPrice = $moneyFromInput('annual_price');
    $featuresText = trim((string)($data['features_text'] ?? ''));
    $limitsText = trim((string)($data['limits_text'] ?? ''));
    $sortOrder = (int)($data['sort_order'] ?? 0);
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $recommended = !empty($data['recommended']) ? 1 : 0;
    $shortDescription = trim((string)($data['short_description'] ?? $description));
    $studioLimit = $nullableIntFromInput('studio_limit');
    $userLimit = $nullableIntFromInput('user_limit');
    $tattooArtistLimit = $nullableIntFromInput('tattoo_artist_limit');
    $leadLimit = $nullableIntFromInput('lead_limit');
    $whatsappSessionLimit = $nullableIntFromInput('whatsapp_session_limit');
    $allowWhatsapp = !empty($data['allow_whatsapp']) ? 1 : 0;
    $allowAi = !empty($data['allow_ai']) ? 1 : 0;
    $allowDataAssistant = !empty($data['allow_data_assistant']) ? 1 : 0;
    $allowFinance = !empty($data['allow_finance']) ? 1 : 0;
    $allowAdvancedReports = !empty($data['allow_advanced_reports']) ? 1 : 0;
    $allowAutomations = !empty($data['allow_automations']) ? 1 : 0;
    $allowMultiStudio = !empty($data['allow_multi_studio']) ? 1 : 0;
    $allowExternalIntegrations = !empty($data['allow_external_integrations']) ? 1 : 0;
    $allowAdvancedCustomization = !empty($data['allow_advanced_customization']) ? 1 : 0;

    if ($name === '') {
        throw new RuntimeException('Informe o nome do plano.');
    }

    if ($slug === '') {
        throw new RuntimeException('Informe um slug valido para o plano.');
    }

    if ($monthlyPrice < 0 || $annualPrice < 0) {
        throw new RuntimeException('Os precos do plano nao podem ser negativos.');
    }

    $duplicateStmt = db()->prepare('SELECT id FROM commercial_plans WHERE slug = ? AND id <> ? LIMIT 1');
    $duplicateStmt->execute([$slug, $id]);
    if ((int)($duplicateStmt->fetchColumn() ?: 0) > 0) {
        throw new RuntimeException('Ja existe um plano com esse slug.');
    }

    if ($id > 0) {
        if (column_exists('commercial_plans', 'short_description')) {
            $stmt = db()->prepare(
                'UPDATE commercial_plans
                 SET name = ?, slug = ?, short_description = ?, description = ?, monthly_price = ?, annual_price = ?, currency_code = "BRL", recommended = ?, studio_limit = ?, user_limit = ?, tattoo_artist_limit = ?, lead_limit = ?, whatsapp_session_limit = ?, allow_whatsapp = ?, allow_ai = ?, allow_data_assistant = ?, allow_finance = ?, allow_advanced_reports = ?, allow_automations = ?, allow_multi_studio = ?, allow_external_integrations = ?, allow_advanced_customization = ?, features_text = ?, limits_text = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $name, $slug, $shortDescription, $description, $monthlyPrice, $annualPrice, $recommended, $studioLimit, $userLimit, $tattooArtistLimit,
                $leadLimit, $whatsappSessionLimit, $allowWhatsapp, $allowAi, $allowDataAssistant, $allowFinance, $allowAdvancedReports, $allowAutomations,
                $allowMultiStudio, $allowExternalIntegrations, $allowAdvancedCustomization, $featuresText, $limitsText, $sortOrder, $isActive, $id,
            ]);
        } else {
            $stmt = db()->prepare(
                'UPDATE commercial_plans
                 SET name = ?, slug = ?, description = ?, monthly_price = ?, annual_price = ?, features_text = ?, limits_text = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([$name, $slug, $description, $monthlyPrice, $annualPrice, $featuresText, $limitsText, $sortOrder, $isActive, $id]);
        }
        if (studio_plan_assignment_ready()) {
            db()->prepare('UPDATE studios SET plan_name = ? WHERE plan_id = ?')->execute([$slug, $id]);
        }

        return $id;
    }

    if (column_exists('commercial_plans', 'short_description')) {
        $stmt = db()->prepare(
            'INSERT INTO commercial_plans
                (name, slug, short_description, description, monthly_price, annual_price, currency_code, recommended, studio_limit, user_limit, tattoo_artist_limit, lead_limit, whatsapp_session_limit, allow_whatsapp, allow_ai, allow_data_assistant, allow_finance, allow_advanced_reports, allow_automations, allow_multi_studio, allow_external_integrations, allow_advanced_customization, features_text, limits_text, sort_order, is_active, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, "BRL", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $name, $slug, $shortDescription, $description, $monthlyPrice, $annualPrice, $recommended, $studioLimit, $userLimit, $tattooArtistLimit,
            $leadLimit, $whatsappSessionLimit, $allowWhatsapp, $allowAi, $allowDataAssistant, $allowFinance, $allowAdvancedReports, $allowAutomations,
            $allowMultiStudio, $allowExternalIntegrations, $allowAdvancedCustomization, $featuresText, $limitsText, $sortOrder, $isActive,
        ]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO commercial_plans
                (name, slug, description, monthly_price, annual_price, currency_code, features_text, limits_text, sort_order, is_active, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, "BRL", ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$name, $slug, $description, $monthlyPrice, $annualPrice, $featuresText, $limitsText, $sortOrder, $isActive]);
    }

    return (int)db()->lastInsertId();
}

function delete_commercial_plan(int $id): void
{
    if ($id <= 0 || !commercial_plans_ready()) {
        return;
    }

    if (studio_plan_assignment_ready()) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM studios WHERE plan_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Este plano ja esta vinculado a estudios e nao pode ser removido agora.');
        }
    }

    db()->prepare('DELETE FROM commercial_plans WHERE id = ?')->execute([$id]);
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

    $plan = studio_plan_snapshot($data);

    $nextId = (int)db()->query("SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studios'")->fetchColumn();
    if (studio_plan_assignment_ready()) {
        $stmt = db()->prepare(
            'INSERT INTO studios
                (name, slug, status, owner_name, owner_email, owner_phone, database_name, database_host, database_user, plan_id, plan_name, ai_model, business_rules, whatsapp_session_key, created_by, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
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
            $plan['plan_id'],
            $plan['plan_name'],
            trim((string)($data['ai_model'] ?? 'llama3:8b')),
            trim((string)($data['business_rules'] ?? '')),
            studio_session_key_from_parts($nextId, $slug),
            $adminId,
        ]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO studios
                (name, slug, status, owner_name, owner_email, owner_phone, database_name, database_host, database_user, plan_name, ai_model, business_rules, whatsapp_session_key, created_by, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
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
            $plan['plan_name'],
            trim((string)($data['ai_model'] ?? 'llama3:8b')),
            trim((string)($data['business_rules'] ?? '')),
            studio_session_key_from_parts($nextId, $slug),
            $adminId,
        ]);
    }

    $studioId = (int)db()->lastInsertId();
    studio_event($studioId, 'studio_created', 'Estudio cadastrado na plataforma alpha.');

    return $studioId;
}

function update_studio(array $studio, array $data): void
{
    $plan = studio_plan_snapshot($data, $studio);
    $sessionKey = studio_session_key_from_parts((int)$studio['id'], (string)$studio['slug']);
    if (studio_plan_assignment_ready()) {
        $stmt = db()->prepare(
            'UPDATE studios
             SET name = ?, status = ?, owner_name = ?, owner_email = ?, owner_phone = ?, database_name = ?,
                 database_host = ?, database_user = ?, plan_id = ?, plan_name = ?, ai_model = ?, business_rules = ?,
                 whatsapp_session_key = IF(whatsapp_session_key IS NULL OR whatsapp_session_key = "", ?, whatsapp_session_key),
                 updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            trim((string)($data['name'] ?? $studio['name'])),
            trim((string)($data['status'] ?? $studio['status'])),
            trim((string)($data['owner_name'] ?? '')),
            strtolower(trim((string)($data['owner_email'] ?? ''))),
            trim((string)($data['owner_phone'] ?? '')),
            trim((string)($data['database_name'] ?? $studio['database_name'])),
            trim((string)($data['database_host'] ?? 'localhost')),
            trim((string)($data['database_user'] ?? 'root')),
            $plan['plan_id'],
            $plan['plan_name'],
            trim((string)($data['ai_model'] ?? 'llama3:8b')),
            trim((string)($data['business_rules'] ?? '')),
            $sessionKey,
            (int)$studio['id'],
        ]);
    } else {
        $stmt = db()->prepare(
            'UPDATE studios
             SET name = ?, status = ?, owner_name = ?, owner_email = ?, owner_phone = ?, database_name = ?,
                 database_host = ?, database_user = ?, plan_name = ?, ai_model = ?, business_rules = ?,
                 whatsapp_session_key = IF(whatsapp_session_key IS NULL OR whatsapp_session_key = "", ?, whatsapp_session_key),
                 updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            trim((string)($data['name'] ?? $studio['name'])),
            trim((string)($data['status'] ?? $studio['status'])),
            trim((string)($data['owner_name'] ?? '')),
            strtolower(trim((string)($data['owner_email'] ?? ''))),
            trim((string)($data['owner_phone'] ?? '')),
            trim((string)($data['database_name'] ?? $studio['database_name'])),
            trim((string)($data['database_host'] ?? 'localhost')),
            trim((string)($data['database_user'] ?? 'root')),
            $plan['plan_name'],
            trim((string)($data['ai_model'] ?? 'llama3:8b')),
            trim((string)($data['business_rules'] ?? '')),
            $sessionKey,
            (int)$studio['id'],
        ]);
    }

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

    $existingStmt = db()->prepare('SELECT id, studio_id FROM studio_users WHERE email = ? LIMIT 1');
    $existingStmt->execute([$email]);
    $existingUser = $existingStmt->fetch();
    $existingId = (int)($existingUser['id'] ?? 0);
    $existingStudioId = (int)($existingUser['studio_id'] ?? 0);
    $studioId = (int)$studio['id'];
    $userLimit = plan_limit('max_users');

    if ($userLimit > 0) {
        $currentCount = studio_user_count($studioId);
        $isSameStudioEdit = $existingId > 0 && $existingStudioId === $studioId;
        if (!$isSameStudioEdit && $currentCount >= $userLimit) {
            throw new RuntimeException('Seu plano atual permite at ' . $userLimit . ' usurios. Para adicionar mais usurios, altere para um plano superior.');
        }
    }

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
