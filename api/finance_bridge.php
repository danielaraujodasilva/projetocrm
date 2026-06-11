<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function finance_bridge_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function finance_bridge_secret(): string
{
    $localConfig = __DIR__ . '/../deploy.local.php';
    if (is_file($localConfig)) {
        $config = require $localConfig;
        if (is_array($config)) {
            $secret = trim((string)($config['secret'] ?? ''));
            if ($secret !== '') {
                return $secret;
            }
        }
    }

    return trim((string)(getenv('PROJETOCRM_DEPLOY_WEBHOOK_SECRET') ?: ''));
}

function finance_bridge_authorized(): bool
{
    $secret = finance_bridge_secret();
    if ($secret === '') {
        return false;
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? ''));
    if ($token !== '' && str_starts_with(strtolower($token), 'bearer ')) {
        $token = trim(substr($token, 7));
    }

    $queryToken = trim((string)($_GET['token'] ?? ''));
    $provided = $token !== '' ? $token : $queryToken;

    return $provided !== '' && hash_equals($secret, $provided);
}

function finance_bridge_pick_studio(?int $studioId = null): ?array
{
    $studios = list_studios();
    if ($studioId !== null && $studioId > 0) {
        foreach ($studios as $studio) {
            if ((int)($studio['id'] ?? 0) === $studioId) {
                return $studio;
            }
        }
    }

    return $studios[0] ?? null;
}

if (!finance_bridge_authorized()) {
    finance_bridge_json(['ok' => false, 'error' => 'Não autorizado.'], 403);
}

$resource = trim((string)($_GET['resource'] ?? 'summary'));
$studioId = (int)($_GET['studio_id'] ?? 0);
$studio = finance_bridge_pick_studio($studioId > 0 ? $studioId : null);

if (!$studio) {
    finance_bridge_json(['ok' => false, 'error' => 'Nenhum estúdio encontrado.'], 404);
}

$summary = studio_finance_summary($studio);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$appointments = studio_list_appointments($studio);
if ($from !== '' || $to !== '') {
    $appointments = array_values(array_filter(
        $appointments,
        static function (array $row) use ($from, $to): bool {
            $date = (string)($row['appointment_date'] ?? '');
            if ($date === '') {
                return false;
            }
            if ($from !== '' && $date < $from) {
                return false;
            }
            if ($to !== '' && $date > $to) {
                return false;
            }
            return true;
        }
    ));
}

$mappedAppointments = array_map(static function (array $row): array {
    $expectedAmount = (float)($row['expected_amount'] ?? $row['value'] ?? 0);
    $signalAmount = (float)($row['signal_amount'] ?? $row['deposit_value'] ?? 0);
    $remainingAmount = (float)($row['remaining_amount'] ?? max(0, $expectedAmount - $signalAmount));
    return [
        'external_appointment_id' => 'crm:' . (string)($row['id'] ?? ''),
        'appointment_date' => (string)($row['appointment_date'] ?? ''),
        'client_name' => (string)($row['client_name'] ?? $row['name'] ?? 'Cliente'),
        'client_phone' => (string)($row['client_phone'] ?? $row['phone'] ?? ''),
        'service_name' => (string)($row['service_name'] ?? $row['interest'] ?? 'Agenda'),
        'expected_amount' => $expectedAmount,
        'signal_amount' => $signalAmount,
        'remaining_amount' => $remainingAmount,
        'lead_status' => (string)($row['status'] ?? ''),
        'etapa_funil' => (string)($row['pipeline_stage'] ?? ''),
        'data_ultimo_contato' => (string)($row['appointment_date'] ?? $row['created_at'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'notes' => trim((string)($row['description'] ?? '')),
        'source' => 'crm',
    ];
}, $appointments);

$transactions = [];
foreach (studio_list_expenses($studio) as $expense) {
    $transactions[] = [
        'external_transaction_id' => 'expense:' . (string)($expense['id'] ?? ''),
        'transaction_date' => (string)($expense['expense_date'] ?? ''),
        'due_date' => (string)($expense['expense_date'] ?? ''),
        'paid_date' => (string)($expense['expense_date'] ?? ''),
        'description' => (string)($expense['description'] ?? 'Despesa'),
        'amount' => -abs((float)($expense['amount'] ?? 0)),
        'type' => 'expense',
        'status' => 'paid',
        'payment_method' => (string)($expense['payment_method'] ?? ''),
        'notes' => (string)($expense['notes'] ?? ''),
        'external_account_id' => 'crm:default',
        'source' => 'crm',
        'category' => (string)($expense['category'] ?? 'Geral'),
    ];
}

foreach ($mappedAppointments as $row) {
    $amount = (float)($row['expected_amount'] ?? 0);
    if ($amount <= 0) {
        continue;
    }

    $transactions[] = [
        'external_transaction_id' => 'appointment:' . $row['external_appointment_id'],
        'transaction_date' => (string)($row['appointment_date'] ?? date('Y-m-d')),
        'due_date' => (string)($row['appointment_date'] ?? date('Y-m-d')),
        'paid_date' => null,
        'description' => 'Entrada prevista - ' . (string)($row['client_name'] ?? 'Cliente'),
        'amount' => $amount,
        'type' => 'income',
        'status' => 'planned',
        'payment_method' => 'crm',
        'notes' => 'Gerado a partir da agenda do CRM',
        'external_account_id' => 'crm:agenda',
        'source' => 'crm',
        'category' => 'Agenda',
    ];
}

if ($resource === 'appointments') {
    finance_bridge_json([
        'ok' => true,
        'resource' => 'appointments',
        'studio_id' => (int)($studio['id'] ?? 0),
        'data' => $mappedAppointments,
    ]);
}

if ($resource === 'transactions') {
    finance_bridge_json([
        'ok' => true,
        'resource' => 'transactions',
        'studio_id' => (int)($studio['id'] ?? 0),
        'data' => $transactions,
    ]);
}

finance_bridge_json([
    'ok' => true,
    'resource' => 'summary',
    'studio_id' => (int)($studio['id'] ?? 0),
    'summary' => $summary,
    'appointments_count' => count($mappedAppointments),
    'transactions_count' => count($transactions),
]);
