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
               AND TABLE_NAME IN ("studio_settings", "customers", "leads", "pipeline_stages", "appointments", "expenses", "quick_replies", "whatsapp_conversations", "whatsapp_messages")'
        );
        $stmt->execute([$config['database']]);

        return (int)$stmt->fetchColumn() === 9;
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
        "SELECT a.*, COALESCE(c.name, a.title) AS customer_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         WHERE a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelado')
         ORDER BY a.appointment_date ASC, a.start_time ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function studio_list_customers(array $studio): array
{
    return studio_db($studio)->query('SELECT * FROM customers ORDER BY updated_at DESC, id DESC LIMIT 100')->fetchAll() ?: [];
}

function studio_list_leads(array $studio): array
{
    return studio_db($studio)->query('SELECT * FROM leads ORDER BY updated_at DESC, id DESC LIMIT 120')->fetchAll() ?: [];
}

function studio_list_appointments(array $studio): array
{
    $stmt = studio_db($studio)->query(
        "SELECT a.*, c.name AS customer_name, l.name AS lead_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN leads l ON l.id = a.lead_id
         ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
         LIMIT 120"
    );

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
            SUM(CASE WHEN ai_last_status IS NOT NULL AND ai_last_status <> '' THEN 1 ELSE 0 END) AS analyzed
         FROM whatsapp_conversations"
    )->fetch() ?: [];

    return [
        'total' => (int)($row['total'] ?? 0),
        'bot' => (int)($row['bot'] ?? 0),
        'human' => (int)($row['human'] ?? 0),
        'analyzed' => (int)($row['analyzed'] ?? 0),
    ];
}

function studio_list_whatsapp_conversations(array $studio): array
{
    return studio_db($studio)->query(
        "SELECT wc.*, c.name AS customer_name, l.name AS lead_name, COUNT(wm.id) AS message_count, MAX(wm.sent_at) AS last_message_at
         FROM whatsapp_conversations wc
         LEFT JOIN customers c ON c.id = wc.customer_id
         LEFT JOIN leads l ON l.id = wc.lead_id
         LEFT JOIN whatsapp_messages wm ON wm.conversation_id = wc.id
         GROUP BY wc.id
         ORDER BY COALESCE(MAX(wm.sent_at), wc.updated_at) DESC, wc.id DESC
         LIMIT 120"
    )->fetchAll() ?: [];
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
    $values = [
        (int)($data['customer_id'] ?? 0) ?: null,
        (int)($data['lead_id'] ?? 0) ?: null,
        trim((string)($data['title'] ?? 'Atendimento')),
        trim((string)($data['description'] ?? '')),
        trim((string)($data['appointment_date'] ?? date('Y-m-d'))),
        trim((string)($data['start_time'] ?? '10:00')),
        trim((string)($data['end_time'] ?? '')),
        trim((string)($data['status'] ?? 'pre_agendado')),
        money_to_float((string)($data['value'] ?? '0')),
        money_to_float((string)($data['deposit_value'] ?? '0')),
    ];
    if ($values[6] === '') {
        $values[6] = null;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE appointments
             SET customer_id = ?, lead_id = ?, title = ?, description = ?, appointment_date = ?, start_time = ?, end_time = ?, status = ?, value = ?, deposit_value = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([...$values, $id]);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO appointments
            (customer_id, lead_id, title, description, appointment_date, start_time, end_time, status, value, deposit_value, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
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

    $stmt = studio_db($studio)->prepare(
        'UPDATE studio_settings
         SET studio_name = ?, business_rules = ?, ai_enabled = ?, ai_model = ?, whatsapp_enabled = ?, updated_at = NOW()
         WHERE id = 1'
    );
    $stmt->execute([
        $studioName,
        $businessRules,
        $aiEnabled,
        $aiModel,
        $whatsappEnabled,
    ]);

    db()->prepare(
        'UPDATE studios SET name = ?, business_rules = ?, ai_model = ?, whatsapp_status = ?, updated_at = NOW() WHERE id = ?'
    )->execute([
        $studioName,
        $businessRules,
        $aiModel,
        $whatsappEnabled ? 'disconnected' : 'not_configured',
        (int)$studio['id'],
    ]);

    studio_event((int)$studio['id'], 'studio_settings_updated', 'Configuracoes operacionais do CRM atualizadas.');
}

function format_money(float|int|string $value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}
