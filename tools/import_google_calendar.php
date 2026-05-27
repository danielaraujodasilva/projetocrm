<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$options = getopt('', ['studio:', 'file:', 'mode::', 'limit::']);
$studioId = (int)($options['studio'] ?? 0);
$file = (string)($options['file'] ?? '');
$mode = (string)($options['mode'] ?? 'preview');
$limit = (int)($options['limit'] ?? 20);

if ($studioId <= 0 || $file === '' || !is_file($file)) {
    fwrite(STDERR, "Uso: php tools/import_google_calendar.php --studio=1 --file=C:/agenda.ics --mode=preview|import\n");
    exit(1);
}

if (!in_array($mode, ['preview', 'import'], true)) {
    fwrite(STDERR, "Modo invalido. Use preview ou import.\n");
    exit(1);
}

$studio = get_studio($studioId);
if (!$studio) {
    fwrite(STDERR, "Estudio nao encontrado.\n");
    exit(1);
}

studio_install_database($studio);

$events = parse_ics_events($file);
$analysis = [];
$skipped = [];
foreach ($events as $event) {
    $parsed = parse_calendar_event_for_crm($event);
    if ($parsed['include']) {
        $analysis[] = $parsed;
    } else {
        $skipped[] = $parsed;
    }
}

$duplicates = count_existing_imported_appointments($studio, $analysis);

echo "Arquivo: {$file}\n";
echo "Estudio: {$studio['name']} (#{$studio['id']})\n";
echo "Eventos no ICS: " . count($events) . "\n";
echo "Candidatos a importar: " . count($analysis) . "\n";
echo "Ja existentes no CRM: {$duplicates}\n";
echo "Ignorados pelo filtro: " . count($skipped) . "\n\n";

echo "Amostra de candidatos:\n";
foreach (array_slice($analysis, 0, $limit) as $item) {
    echo '- ' . $item['date'] . ' ' . $item['start_time'] . ' | ' . $item['name'] . ' | ' . money_label($item['value']) . ' | ' . $item['status'] . ' | ' . $item['raw_title'] . "\n";
}

echo "\nAmostra ignorada:\n";
foreach (array_slice($skipped, 0, min(12, $limit)) as $item) {
    echo '- ' . ($item['date'] ?? '-') . ' | ' . $item['raw_title'] . ' | ' . $item['reason'] . "\n";
}

if ($mode === 'preview') {
    echo "\nPREVIEW apenas. Rode com --mode=import para gravar.\n";
    exit(0);
}

$result = import_calendar_events($studio, $analysis);
echo "\nImportacao concluida:\n";
echo 'Clientes criados: ' . $result['customers_created'] . "\n";
echo 'Leads criados: ' . $result['leads_created'] . "\n";
echo 'Agendamentos criados: ' . $result['appointments_created'] . "\n";
echo 'Duplicados pulados: ' . $result['duplicates_skipped'] . "\n";

function parse_ics_events(string $file): array
{
    $raw = (string)file_get_contents($file);
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = preg_replace("/\n[ \t]/", '', $raw) ?? $raw;
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $raw, $matches);

    $events = [];
    foreach ($matches[1] as $block) {
        $events[] = [
            'uid' => ics_property($block, 'UID'),
            'summary' => ics_decode(ics_property($block, 'SUMMARY')),
            'description' => ics_decode(ics_property($block, 'DESCRIPTION')),
            'status' => strtoupper(ics_property($block, 'STATUS') ?: 'CONFIRMED'),
            'dtstart' => ics_property($block, 'DTSTART'),
            'dtend' => ics_property($block, 'DTEND'),
            'all_day' => str_contains($block, 'DTSTART;VALUE=DATE'),
        ];
    }

    return $events;
}

function ics_property(string $block, string $name): string
{
    if (preg_match('/^' . preg_quote($name, '/') . '(?:;[^:]*)?:(.*)$/mi', $block, $match)) {
        return trim($match[1]);
    }

    return '';
}

function ics_decode(string $value): string
{
    $value = str_replace(['\\n', '\\N'], "\n", $value);
    return trim(str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $value));
}

function parse_calendar_event_for_crm(array $event): array
{
    $rawTitle = normalize_spaces((string)$event['summary']);
    $description = normalize_spaces((string)$event['description']);
    $start = parse_ics_datetime((string)$event['dtstart']);
    $end = parse_ics_datetime((string)$event['dtend']);
    $base = [
        'include' => false,
        'reason' => '',
        'raw_title' => $rawTitle,
        'uid' => import_uid((string)$event['uid']),
        'google_uid' => (string)$event['uid'],
        'description_original' => $description,
        'date' => $start ? $start->format('Y-m-d') : null,
        'start_time' => $start ? $start->format('H:i:s') : null,
        'end_time' => $end ? $end->format('H:i:s') : null,
    ];

    if ($event['status'] === 'CANCELLED') {
        return array_merge($base, ['reason' => 'cancelado']);
    }
    if (!empty($event['all_day']) || !$start) {
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
    $hasServiceKeyword = contains_any($normalized, ['tattoo', 'tatuagem', 'tatuar', 'retoque', 'micro', 'micropigmentacao', 'cilios', 'piercing', 'pomada', 'sinal', 'orcamento', 'cobertura', 'sessao', 'fechamento', 'higienizacao', 'permuta']);
    $looksLikePerson = looks_like_person_title($rawTitle);

    if ($phone === '' && $value <= 0 && !$hasServiceKeyword && !$looksLikePerson) {
        return array_merge($base, ['reason' => 'sem sinal de cliente/atendimento']);
    }

    $parsedTitle = parse_event_title($rawTitle, $valueToken);
    $name = $parsedTitle['name'];
    if ($name === '') {
        $name = $rawTitle;
    }

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
    ]);
}

function parse_ics_datetime(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $tz = new DateTimeZone('America/Sao_Paulo');
    if (preg_match('/^\d{8}$/', $value)) {
        return DateTimeImmutable::createFromFormat('!Ymd', $value, $tz) ?: null;
    }
    if (str_ends_with($value, 'Z')) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        return $dt ? $dt->setTimezone($tz) : null;
    }

    return DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz) ?: null;
}

function parse_event_title(string $title, string $valueToken): array
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

function extract_event_value(string $title): array
{
    $candidate = preg_replace('/(?:\+?55\s*)?(?:\(?\d{2}\)?\s*)?\d{4,5}[-\s]?\d{4}/', ' ', $title) ?? $title;
    $patterns = [
        '/R\$\s*([\d\.\,]+)/iu',
        '/([\d\.\,]+)\s*\$/u',
        '/\b(\d{2,6}(?:[,.]\d{2})?)\s*,?\s*(?:com|pagou sinal|sinal pago|pago|sinal)\b/iu',
        '/[-–]\s*(\d{2,6}(?:[,.]\d{2})?)\b/u',
        '/\b(\d{2,6}(?:[,.]\d{2})?)\s*(?:\((?:pago|sinal|sem sinal|fiado|parcelado)[^)]*\))?$/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $candidate, $match)) {
            return [money_to_float($match[1]), $match[0]];
        }
    }

    return [0.0, ''];
}

function extract_phone(string $text): string
{
    if (preg_match('/(?:\+?55\s*)?(?:\(?\d{2}\)?\s*)?\d{4,5}[-\s]?\d{4}/', $text, $match)) {
        return normalize_phone($match[0]);
    }

    return '';
}

function looks_like_person_title(string $title): bool
{
    $clean = remove_accents(lower_text($title));
    $clean = preg_replace('/\([^)]*\)|R\$\s*[\d\.\,]+|[\d\.\,]+\s*\$|[-–]\s*\d{2,6}/u', ' ', $clean) ?? $clean;
    $clean = normalize_spaces($clean);
    $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $alphaWords = array_values(array_filter($words, static fn(string $word): bool => preg_match('/^[a-z]{3,}$/', $word) === 1));

    return count($alphaWords) >= 2 && count($alphaWords) <= 6;
}

function import_calendar_events(array $studio, array $items): array
{
    $pdo = studio_db($studio);
    $artistId = default_artist_id($studio);
    $result = [
        'customers_created' => 0,
        'leads_created' => 0,
        'appointments_created' => 0,
        'duplicates_skipped' => 0,
    ];

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            if (imported_appointment_exists($studio, $item['uid'])) {
                $result['duplicates_skipped']++;
                continue;
            }

            $customerId = find_or_create_customer_from_import($studio, $item, $result);
            $leadId = find_or_create_lead_from_import($studio, $item, $customerId, $result);
            $description = build_import_description($item);

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
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}

function find_or_create_customer_from_import(array $studio, array $item, array &$result): int
{
    $pdo = studio_db($studio);
    if ($item['phone'] !== '') {
        $customer = studio_find_customer_by_phone($studio, $item['phone']);
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

    $stmt = $pdo->prepare('INSERT INTO customers (name, phone, email, instagram, notes, created_at, updated_at) VALUES (?, ?, "", "", ?, NOW(), NOW())');
    $stmt->execute([$item['name'], $item['phone'], 'Importado do Google Agenda. Titulo original: ' . $item['raw_title']]);
    $result['customers_created']++;

    return (int)$pdo->lastInsertId();
}

function find_or_create_lead_from_import(array $studio, array $item, int $customerId, array &$result): int
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

function imported_appointment_exists(array $studio, string $uid): bool
{
    $stmt = studio_db($studio)->prepare('SELECT id FROM appointments WHERE import_source = "google_calendar" AND import_uid = ? LIMIT 1');
    $stmt->execute([$uid]);

    return (bool)$stmt->fetchColumn();
}

function count_existing_imported_appointments(array $studio, array $items): int
{
    $count = 0;
    foreach ($items as $item) {
        if (imported_appointment_exists($studio, $item['uid'])) {
            $count++;
        }
    }

    return $count;
}

function default_artist_id(array $studio): ?int
{
    $artists = studio_list_artists($studio);
    return $artists ? (int)$artists[0]['id'] : null;
}

function build_import_description(array $item): string
{
    $parts = [
        'Importado do Google Agenda.',
        'Titulo original: ' . $item['raw_title'],
    ];
    if ($item['notes'] !== '') {
        $parts[] = 'Observacoes do titulo: ' . $item['notes'];
    }
    if ($item['description_original'] !== '') {
        $parts[] = 'Descricao original: ' . $item['description_original'];
    }
    $parts[] = 'UID Google: ' . $item['google_uid'];

    return implode("\n", $parts);
}

function import_uid(string $uid): string
{
    return sha1($uid !== '' ? $uid : uniqid('calendar-', true));
}

function normalize_spaces(string $value): string
{
    $value = trim($value);
    $normalized = preg_replace('/\s+/u', ' ', $value);
    return $normalized !== null ? $normalized : '';
}

function lower_text(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function remove_accents(string $value): string
{
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return $converted !== false ? $converted : $value;
}

function contains_any(string $value, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($value, $needle)) {
            return true;
        }
    }

    return false;
}

function money_label(float $value): string
{
    return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'sem valor';
}
