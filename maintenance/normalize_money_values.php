<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function maintenance_connect_db(string $database): PDO
{
    $config = app_config('database');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'] ?? 'localhost',
        (int)($config['port'] ?? 3306),
        $database,
        $config['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, (string)($config['username'] ?? 'root'), (string)($config['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function maintenance_fix_table(PDO $pdo, string $table, array $columns, string $threshold = '10000'): array
{
    $results = [];
    foreach ($columns as $column) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        if (!(bool)$stmt->fetchColumn()) {
            continue;
        }

        $update = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = `{$column}` / 100 WHERE `{$column}` >= ? AND MOD(`{$column}`, 100) = 0");
        $update->execute([$threshold]);
        $results[] = $table . '.' . $column . ': ' . $update->rowCount();
    }

    return $results;
}

$central = db();
$studios = $central->query('SELECT id, name, database_name FROM studios ORDER BY id')->fetchAll() ?: [];

$output = [];
foreach ($studios as $studio) {
    $databaseName = trim((string)($studio['database_name'] ?? ''));
    if ($databaseName === '') {
        continue;
    }

    try {
        $pdo = maintenance_connect_db($databaseName);
        $pdo->beginTransaction();
        $changes = [];
        $changes = array_merge($changes, maintenance_fix_table($pdo, 'appointments', ['value', 'deposit_value', 'pomada_unit_price']));
        $changes = array_merge($changes, maintenance_fix_table($pdo, 'expenses', ['amount']));
        $changes = array_merge($changes, maintenance_fix_table($pdo, 'leads', ['estimated_value']));
        $pdo->commit();

        $output[] = $databaseName . ' (' . (string)($studio['name'] ?? 'studio') . '): ' . (empty($changes) ? 'sem ajustes' : implode(', ', $changes));
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $output[] = $databaseName . ' (' . (string)($studio['name'] ?? 'studio') . '): ERRO - ' . $e->getMessage();
    }
}

echo implode(PHP_EOL, $output) . PHP_EOL;
