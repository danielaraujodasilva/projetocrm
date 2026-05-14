<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$options = getopt('', ['studio:', 'file:', 'mode::', 'limit::', 'include-internal::']);
$studioId = (int)($options['studio'] ?? 0);
$file = (string)($options['file'] ?? '');
$mode = (string)($options['mode'] ?? 'preview');
$limit = max(1, (int)($options['limit'] ?? 18));
$includeInternal = in_array((string)($options['include-internal'] ?? '0'), ['1', 'true', 'yes'], true);

if ($file === 'latest') {
    $file = import_wa_latest_export_file();
}

if ($studioId <= 0 || $file === '' || !is_file($file)) {
    fwrite(STDERR, "Uso: php tools/import_whatsapp_web_json.php --studio=1 --file=latest|C:/arquivo.json --mode=preview|import\n");
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

$json = json_decode((string)file_get_contents($file), true);
if (!is_array($json) || !isset($json['chats']) || !is_array($json['chats'])) {
    fwrite(STDERR, "Arquivo JSON invalido ou sem lista de conversas.\n");
    exit(1);
}

$items = import_wa_analyze_export($json, $includeInternal);
$summary = import_wa_summary($items);

echo "Arquivo: {$file}\n";
echo "Estudio: {$studio['name']} (#{$studio['id']})\n";
echo 'Conversas no JSON: ' . count($json['chats']) . "\n";
echo 'Conversas candidatas: ' . $summary['candidate_chats'] . "\n";
echo 'Conversas internas/ruido ignoradas: ' . $summary['skipped_chats'] . "\n";
echo 'Mensagens uteis candidatas: ' . $summary['candidate_messages'] . "\n";
echo 'Mensagens existentes no banco: ' . import_wa_count_existing_messages($studio, $items) . "\n\n";

echo "Amostra de importacao:\n";
foreach (array_slice(array_values(array_filter($items, static fn(array $item): bool => !$item['skip'])), 0, $limit) as $item) {
    echo '- ' . $item['name'] . ' | ' . $item['phone'] . ' | nota ' . $item['lead_score'] . '/10 | ' . $item['status'] . ' | ' . count($item['messages']) . " mensagens\n";
    if ($item['preview'] !== '') {
        echo '  ' . import_wa_one_line($item['preview'], 140) . "\n";
    }
}

echo "\nAmostra ignorada:\n";
foreach (array_slice(array_values(array_filter($items, static fn(array $item): bool => $item['skip'])), 0, min(10, $limit)) as $item) {
    echo '- ' . $item['name'] . ' | ' . $item['reason'] . "\n";
}

if ($mode === 'preview') {
    echo "\nPREVIEW apenas. Rode com --mode=import para gravar.\n";
    exit(0);
}

$result = import_wa_insert_items($studio, $items);
echo "\nImportacao concluida:\n";
echo 'Clientes criados: ' . $result['customers_created'] . "\n";
echo 'Leads criados: ' . $result['leads_created'] . "\n";
echo 'Conversas criadas: ' . $result['conversations_created'] . "\n";
echo 'Conversas atualizadas: ' . $result['conversations_updated'] . "\n";
echo 'Mensagens criadas: ' . $result['messages_created'] . "\n";
echo 'Mensagens duplicadas puladas: ' . $result['messages_duplicates'] . "\n";
echo 'Conversas ignoradas: ' . $result['chats_skipped'] . "\n";

function import_wa_latest_export_file(): string
{
    $dir = APP_BASE_PATH . '/storage/whatsapp-web-collector/exports';
    $files = glob($dir . '/*.json') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return $files[0] ?? '';
}

function import_wa_analyze_export(array $json, bool $includeInternal): array
{
    $collectedAt = import_wa_collected_at($json);
    $items = [];

    foreach ($json['chats'] as $index => $chat) {
        if (!is_array($chat)) {
            continue;
        }

        $title = import_wa_chat_title($chat);
        $messages = import_wa_clean_messages($chat, $index, $collectedAt);
        $combined = trim(implode("\n", array_column($messages, 'body')));
        $phone = import_wa_extract_phone($title . "\n" . $combined);
        $hasRealPhone = $phone !== '';
        if (!$hasRealPhone) {
            $phone = 'web_' . substr(sha1($title !== '' ? $title : 'chat-' . $index), 0, 14);
        }

        $name = import_wa_guess_name($title, $combined, $hasRealPhone);
        $hasMedia = import_wa_has_media($messages);
        $leadScore = studio_whatsapp_lead_score($combined, $hasMedia);
        $leadInfo = import_wa_lead_info($combined, $hasMedia);
        $isInternal = import_wa_is_internal_chat($title, $phone, $combined);
        $skip = false;
        $reason = '';

        if (!$includeInternal && $isInternal) {
            $skip = true;
            $reason = 'conversa interna/operacional';
        } elseif (!$messages) {
            $skip = true;
            $reason = 'sem mensagens uteis depois da limpeza';
        } elseif (trim($combined) === '') {
            $skip = true;
            $reason = 'sem texto util';
        }

        $items[] = [
            'index' => $index,
            'skip' => $skip,
            'reason' => $reason,
            'name' => $name,
            'title' => $title,
            'phone' => $phone,
            'has_real_phone' => $hasRealPhone,
            'messages' => $messages,
            'combined' => $combined,
            'preview' => import_wa_last_preview($messages),
            'lead_score' => $leadScore,
            'needs_human' => studio_whatsapp_needs_human($combined),
            'status' => $leadInfo['status'],
            'pipeline_stage' => $leadInfo['pipeline_stage'],
            'interest' => $leadInfo['interest'],
            'estimated_value' => $leadInfo['estimated_value'],
            'import_uid' => sha1('whatsapp-web|' . $phone . '|' . $title),
        ];
    }

    return import_wa_dedupe_items($items);
}

function import_wa_chat_title(array $chat): string
{
    foreach (['title', 'chat_list_title', 'name'] as $field) {
        $value = trim((string)($chat[$field] ?? ''));
        if ($value !== '' && !preg_match('/^\d+\s+mensagens?\s+nao\s+lidas?$/iu', import_wa_remove_accents($value))) {
            return $value;
        }
    }

    return 'Contato WhatsApp Web';
}

function import_wa_collected_at(array $json): DateTimeImmutable
{
    $raw = (string)($json['collected_at'] ?? $json['collectedAt'] ?? '');
    if ($raw !== '') {
        try {
            return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        } catch (Throwable) {
        }
    }

    return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
}

function import_wa_clean_messages(array $chat, int $chatIndex, DateTimeImmutable $collectedAt): array
{
    $messages = [];
    $rawMessages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
    foreach ($rawMessages as $messageIndex => $message) {
        if (!is_array($message)) {
            continue;
        }

        $rawText = (string)($message['text'] ?? $message['raw_text'] ?? '');
        $clean = import_wa_clean_text($rawText);
        $type = import_wa_message_type($rawText, $clean);
        if ($clean === '' && $type === 'texto') {
            continue;
        }
        if ($clean === '') {
            $clean = '[' . $type . ']';
        }

        $direction = (string)($message['direction'] ?? 'in') === 'out' || preg_match('/^Voce\s*\n/iu', import_wa_remove_accents($rawText)) ? 'out' : 'in';
        if ($direction === 'out') {
            $clean = preg_replace('/^Você\s*\n|^Voce\s*\n/iu', '', $clean) ?? $clean;
        }

        $time = import_wa_message_datetime((string)($message['time'] ?? ''), $collectedAt);
        $providedId = trim((string)($message['id'] ?? ''));
        $messageId = 'web:' . ($providedId !== '' ? $providedId : sha1($chatIndex . '|' . $messageIndex . '|' . $rawText));

        $messages[] = [
            'body' => mb_substr($clean, 0, 6000),
            'direction' => $direction,
            'sender_type' => $direction === 'out' ? 'human' : 'customer',
            'from_me' => $direction === 'out',
            'message_type' => $type,
            'message_id' => $messageId,
            'sent_at' => $time->format('Y-m-d H:i:s'),
        ];
    }

    return $messages;
}

function import_wa_clean_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = preg_split('/\n+/', $text) ?: [];
    $cleanLines = [];
    foreach ($lines as $line) {
        $line = import_wa_spaces(trim($line));
        if ($line === '') {
            continue;
        }
        if (import_wa_is_noise_line($line)) {
            continue;
        }
        $cleanLines[] = $line;
    }

    $clean = trim(implode("\n", $cleanLines));
    $plain = import_wa_remove_accents(mb_strtolower($clean, 'UTF-8'));
    if ($plain === '~' || str_contains($plain, 'nao esta nos seus contatos') || str_contains($plain, 'ativou as mensagens temporarias')) {
        return '';
    }

    return $clean;
}

function import_wa_is_noise_line(string $line): bool
{
    $plain = import_wa_remove_accents(mb_strtolower($line, 'UTF-8'));
    $noise = [
        'conversa atribuida a smb agent',
        'nao esta nos seus contatos',
        'nenhum grupo em comum',
        'ferramentas de seguranca',
        'bloquear',
        'clique para mudar',
        'ativou as mensagens temporarias',
        'todas as novas mensagens desaparecerao',
        'anuncio do instagram',
        'anuncio do facebook',
        'mostrar detalhes',
        'mensagem de saudacao automatica',
        'nao foi possivel carregar a mensagem',
        'voce recebeu uma mensagem no seu celular',
        'nao e compativel com sua versao do whatsapp web',
        'use seu celular para acessa-la',
        'clique para atualizar',
        'criptografia de ponta a ponta',
    ];

    foreach ($noise as $needle) {
        if (str_contains($plain, $needle)) {
            return true;
        }
    }

    return $plain === '~';
}

function import_wa_message_type(string $rawText, string $clean): string
{
    $plain = import_wa_remove_accents(mb_strtolower($rawText . "\n" . $clean, 'UTF-8'));
    if (preg_match('/\b\d+:\d{2}\b/u', $plain) && (str_contains($plain, '1,0') || str_contains($plain, 'audio'))) {
        return 'audio';
    }
    if (str_contains($plain, 'video')) {
        return 'video';
    }
    if (str_contains($plain, 'foto') || str_contains($plain, 'imagem')) {
        return 'image';
    }
    if (str_contains($plain, '.pdf') || str_contains($plain, 'documento')) {
        return 'document';
    }

    return 'texto';
}

function import_wa_message_datetime(string $time, DateTimeImmutable $base): DateTimeImmutable
{
    $time = trim($time);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $match)) {
        return $base->setTime((int)$match[1], (int)$match[2]);
    }

    return $base;
}

function import_wa_extract_phone(string $text): string
{
    if (preg_match('/(?:\+?55\s*)?(?:\(?\d{2}\)?\s*)?\d{4,5}[-\s]?\d{4}/', $text, $match)) {
        return normalize_phone($match[0]);
    }

    return '';
}

function import_wa_guess_name(string $title, string $combined, bool $titleWasPhone): string
{
    if (!$titleWasPhone && !str_starts_with($title, '+') && !preg_match('/^\d/', $title)) {
        return mb_substr($title, 0, 160);
    }

    if (preg_match('/meu nome (?:e|eh|ehh|é)\s+([A-Za-zÀ-ÿ ]{2,80})/iu', $combined, $match)) {
        $combinedPlain = import_wa_remove_accents(mb_strtolower($combined, 'UTF-8'));
        if (str_contains($combinedPlain, 'sou secretaria') || str_contains($combinedPlain, 'secretaria do estudio')) {
            return 'Contato WhatsApp Web';
        }
        $name = import_wa_spaces($match[1]);
        $plain = import_wa_remove_accents(mb_strtolower($name, 'UTF-8'));
        if (!str_contains($plain, 'secretaria') && !str_contains($plain, 'estudio') && !str_contains($plain, 'tatuador')) {
            return mb_substr($name, 0, 160);
        }
    }

    return 'Contato WhatsApp Web';
}

function import_wa_dedupe_items(array $items): array
{
    $byFingerprint = [];
    foreach ($items as $pos => $item) {
        $messageIds = array_column($item['messages'], 'message_id');
        if (!$messageIds) {
            continue;
        }
        $fingerprint = sha1(implode('|', $messageIds));
        if (!isset($byFingerprint[$fingerprint])) {
            $byFingerprint[$fingerprint] = $pos;
            continue;
        }

        $previousPos = $byFingerprint[$fingerprint];
        $previous = $items[$previousPos];
        $currentWins = (!$previous['has_real_phone'] && $item['has_real_phone']) || ($previous['skip'] && !$item['skip']);

        if ($currentWins) {
            $items[$pos] = import_wa_merge_duplicate_item($item, $previous);
            $items[$previousPos]['skip'] = true;
            $items[$previousPos]['reason'] = 'duplicada no arquivo';
            $byFingerprint[$fingerprint] = $pos;
        } else {
            $items[$previousPos] = import_wa_merge_duplicate_item($previous, $item);
            $items[$pos]['skip'] = true;
            $items[$pos]['reason'] = 'duplicada no arquivo';
        }
    }

    return $items;
}

function import_wa_merge_duplicate_item(array $main, array $duplicate): array
{
    if (import_wa_is_generic_name((string)$main['name']) && !import_wa_is_generic_name((string)$duplicate['name'])) {
        $main['name'] = $duplicate['name'];
    }
    if (!$main['has_real_phone'] && !empty($duplicate['has_real_phone'])) {
        $main['phone'] = $duplicate['phone'];
        $main['has_real_phone'] = true;
        $main['import_uid'] = sha1('whatsapp-web|' . $main['phone'] . '|' . $main['title']);
    }

    return $main;
}

function import_wa_is_generic_name(string $name): bool
{
    return $name === '' || $name === 'Contato WhatsApp Web' || str_starts_with($name, '+') || preg_match('/^\d/', $name) === 1;
}

function import_wa_has_media(array $messages): bool
{
    foreach ($messages as $message) {
        if (($message['message_type'] ?? 'texto') !== 'texto') {
            return true;
        }
    }

    return false;
}

function import_wa_lead_info(string $text, bool $hasMedia): array
{
    $plain = import_wa_remove_accents(mb_strtolower($text, 'UTF-8'));
    $status = 'novo';
    $stage = 'entrada';
    if (import_wa_contains_any($plain, ['sinal', 'pix', 'pago', 'pagamento', 'fechar', 'fechado', 'agendar', 'agenda', 'horario', 'amanha'])) {
        $status = 'pre_agendado';
        $stage = 'pre_agendado';
    } elseif (import_wa_contains_any($plain, ['valor', 'preco', 'orcamento', 'quanto', 'fica'])) {
        $status = 'orcamento';
        $stage = 'orcamento';
    } elseif (import_wa_contains_any($plain, ['interesse', 'tatuagem', 'tattoo', 'fechamento', 'cobertura', 'retoque'])) {
        $status = 'em_conversa';
        $stage = 'em_conversa';
    }

    $interest = import_wa_interest($text, $hasMedia);

    return [
        'status' => $status,
        'pipeline_stage' => $stage,
        'interest' => $interest,
        'estimated_value' => import_wa_extract_money($text),
    ];
}

function import_wa_interest(string $text, bool $hasMedia): string
{
    $lines = preg_split('/\n+/', $text) ?: [];
    $best = '';
    foreach ($lines as $line) {
        $line = import_wa_spaces($line);
        $plain = import_wa_remove_accents(mb_strtolower($line, 'UTF-8'));
        if (mb_strlen($line) >= 8 && import_wa_contains_any($plain, ['tattoo', 'tatuagem', 'fechamento', 'cobertura', 'retoque', 'orcamento', 'valor', 'braco', 'perna', 'mao', 'costas', 'pomada'])) {
            $best = $line;
            break;
        }
    }

    if ($best === '') {
        $best = $hasMedia ? 'Conversa importada do WhatsApp Web com midia' : 'Conversa importada do WhatsApp Web';
    }

    return mb_substr($best, 0, 220);
}

function import_wa_extract_money(string $text): float
{
    $withoutPhones = preg_replace('/(?:\+?55\s*)?(?:\(?\d{2}\)?\s*)?\d{4,5}[-\s]?\d{4}/', ' ', $text) ?? $text;
    $patterns = [
        '/R\$\s*([\d\.\,]+)/iu',
        '/\b(\d{2,6}(?:[,.]\d{2})?)\s*(?:com|sem|pago|sinal|pix|reais|pomada)\b/iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $withoutPhones, $match)) {
            return money_to_float($match[1]);
        }
    }

    return 0.0;
}

function import_wa_is_internal_chat(string $title, string $phone, string $text): bool
{
    $plainTitle = import_wa_remove_accents(mb_strtolower($title, 'UTF-8'));
    $plainText = import_wa_remove_accents(mb_strtolower($text, 'UTF-8'));
    $internalNames = ['daniel tatuador', 'theo tatuador', 'black line', 'francielen bernardo'];
    foreach ($internalNames as $name) {
        if (str_contains($plainTitle, $name)) {
            return true;
        }
    }

    if ($phone === '5511999979785') {
        return true;
    }

    return str_contains($plainText, 'sou secretaria do estudio') && substr_count($plainText, "\n") <= 4;
}

function import_wa_insert_items(array $studio, array $items): array
{
    $pdo = studio_db($studio);
    $result = [
        'customers_created' => 0,
        'leads_created' => 0,
        'conversations_created' => 0,
        'conversations_updated' => 0,
        'messages_created' => 0,
        'messages_duplicates' => 0,
        'chats_skipped' => 0,
    ];

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            if ($item['skip']) {
                $result['chats_skipped']++;
                continue;
            }

            $customerId = import_wa_find_or_create_customer($studio, $item, $result);
            $leadId = import_wa_find_or_create_lead($studio, $item, $customerId, $result);
            $conversation = import_wa_find_conversation($studio, $item['phone']);
            if ($conversation) {
                $conversationId = (int)$conversation['id'];
                $result['conversations_updated']++;
                $pdo->prepare(
                    'UPDATE whatsapp_conversations
                     SET customer_id = COALESCE(customer_id, ?),
                         lead_id = COALESCE(lead_id, ?),
                         name = COALESCE(NULLIF(name, ""), ?),
                         needs_human = GREATEST(needs_human, ?),
                         lead_score = GREATEST(COALESCE(lead_score, 0), ?),
                         updated_at = NOW()
                     WHERE id = ?'
                )->execute([$customerId, $leadId, $item['name'], $item['needs_human'] ? 1 : 0, $item['lead_score'], $conversationId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO whatsapp_conversations
                        (lead_id, customer_id, phone, name, remote_jid, attendance_mode, needs_human, lead_score, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, "human", ?, ?, NOW(), NOW())'
                );
                $stmt->execute([$leadId, $customerId, $item['phone'], $item['name'], 'web:' . $item['import_uid'], $item['needs_human'] ? 1 : 0, $item['lead_score']]);
                $conversationId = (int)$pdo->lastInsertId();
                $result['conversations_created']++;
            }

            foreach ($item['messages'] as $message) {
                if (import_wa_message_exists($studio, $message['message_id'])) {
                    $result['messages_duplicates']++;
                    continue;
                }
                import_wa_insert_message($studio, $conversationId, $message, $item);
                $result['messages_created']++;
            }

            import_wa_update_last_message($studio, $conversationId, $item);
            $pdo->prepare('UPDATE leads SET lead_score = GREATEST(COALESCE(lead_score, 0), ?), last_contact_at = COALESCE(last_contact_at, ?), updated_at = NOW() WHERE id = ?')
                ->execute([$item['lead_score'], import_wa_last_sent_at($item['messages']), $leadId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}

function import_wa_find_or_create_customer(array $studio, array $item, array &$result): int
{
    $pdo = studio_db($studio);
    if ($item['has_real_phone']) {
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
    $stmt->execute([$item['name'], $item['has_real_phone'] ? $item['phone'] : '', 'Importado do WhatsApp Web. Titulo original: ' . $item['title']]);
    $result['customers_created']++;

    return (int)$pdo->lastInsertId();
}

function import_wa_find_or_create_lead(array $studio, array $item, int $customerId, array &$result): int
{
    $pdo = studio_db($studio);
    $stmt = $pdo->prepare('SELECT id FROM leads WHERE import_source = "whatsapp_web" AND import_uid = ? LIMIT 1');
    $stmt->execute([$item['import_uid']]);
    $existing = (int)$stmt->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }

    if ($item['has_real_phone']) {
        $lead = studio_find_lead_by_phone($studio, $item['phone']);
        if ($lead) {
            return (int)$lead['id'];
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leads
            (customer_id, name, phone, interest, status, pipeline_stage, lead_score, estimated_value, source, import_source, import_uid, raw_title, last_contact_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "WhatsApp Web", "whatsapp_web", ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $customerId,
        $item['name'],
        $item['has_real_phone'] ? $item['phone'] : '',
        $item['interest'],
        $item['status'],
        $item['pipeline_stage'],
        $item['lead_score'],
        $item['estimated_value'],
        $item['import_uid'],
        mb_substr($item['title'], 0, 260),
        import_wa_last_sent_at($item['messages']),
    ]);
    $result['leads_created']++;

    return (int)$pdo->lastInsertId();
}

function import_wa_find_conversation(array $studio, string $phone): ?array
{
    if (!str_starts_with($phone, 'web_')) {
        $conversation = studio_find_whatsapp_conversation_by_phone($studio, $phone);
        if ($conversation) {
            return $conversation;
        }
    }

    $stmt = studio_db($studio)->prepare('SELECT * FROM whatsapp_conversations WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $conversation = $stmt->fetch();

    return is_array($conversation) ? $conversation : null;
}

function import_wa_message_exists(array $studio, string $messageId): bool
{
    $stmt = studio_db($studio)->prepare('SELECT id FROM whatsapp_messages WHERE message_id = ? LIMIT 1');
    $stmt->execute([$messageId]);

    return (bool)$stmt->fetchColumn();
}

function import_wa_insert_message(array $studio, int $conversationId, array $message, array $item): void
{
    $stmt = studio_db($studio)->prepare(
        'INSERT INTO whatsapp_messages
            (conversation_id, direction, sender_type, body, media_url, media_mime, message_type, message_id, remote_jid, from_me, status, sent_at, created_at)
         VALUES (?, ?, ?, ?, "", "", ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $conversationId,
        $message['direction'],
        $message['sender_type'],
        $message['body'],
        $message['message_type'],
        $message['message_id'],
        'web:' . $item['import_uid'],
        $message['from_me'] ? 1 : 0,
        $message['direction'] === 'out' ? 'imported' : null,
        $message['sent_at'],
    ]);
}

function import_wa_update_last_message(array $studio, int $conversationId, array $item): void
{
    $last = null;
    foreach ($item['messages'] as $message) {
        if ($last === null || strcmp($message['sent_at'], $last['sent_at']) >= 0) {
            $last = $message;
        }
    }
    if (!$last) {
        return;
    }

    $preview = $last['body'] !== '' ? mb_substr($last['body'], 0, 250) : '[' . $last['message_type'] . ']';
    studio_db($studio)->prepare(
        'UPDATE whatsapp_conversations
         SET last_message_preview = ?, last_message_direction = ?, last_message_at = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([$preview, $last['direction'], $last['sent_at'], $conversationId]);
}

function import_wa_count_existing_messages(array $studio, array $items): int
{
    $count = 0;
    foreach ($items as $item) {
        if ($item['skip']) {
            continue;
        }
        foreach ($item['messages'] as $message) {
            if (import_wa_message_exists($studio, $message['message_id'])) {
                $count++;
            }
        }
    }

    return $count;
}

function import_wa_summary(array $items): array
{
    $summary = ['candidate_chats' => 0, 'skipped_chats' => 0, 'candidate_messages' => 0];
    foreach ($items as $item) {
        if ($item['skip']) {
            $summary['skipped_chats']++;
            continue;
        }
        $summary['candidate_chats']++;
        $summary['candidate_messages'] += count($item['messages']);
    }

    return $summary;
}

function import_wa_last_preview(array $messages): string
{
    $last = end($messages);
    return is_array($last) ? (string)$last['body'] : '';
}

function import_wa_last_sent_at(array $messages): ?string
{
    $last = null;
    foreach ($messages as $message) {
        if ($last === null || strcmp($message['sent_at'], $last) >= 0) {
            $last = $message['sent_at'];
        }
    }

    return $last;
}

function import_wa_contains_any(string $value, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($value, $needle)) {
            return true;
        }
    }

    return false;
}

function import_wa_spaces(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function import_wa_remove_accents(string $value): string
{
    return strtr($value, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ç' => 'c', 'Ç' => 'C',
    ]);
}

function import_wa_one_line(string $value, int $max): string
{
    $value = import_wa_spaces(str_replace("\n", ' | ', $value));
    return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 3) . '...' : $value;
}
