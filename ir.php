<?php
/**
 * Link de rastreio de origem de anúncio.
 * Uso: /projetocrm/ir.php?src=meta   (ou src=google, src=instagram, src=indicacao)
 *
 * O que faz:
 *  1. lê o parâmetro src e normaliza (meta|google|instagram|indicacao|outro)
 *  2. grava a origem no cookie (ad_src) e registra a visita em ads_src_hits
 *  3. redireciona para o WhatsApp do estúdio com uma mensagem inicial que carrega a marca de origem
 *
 * Assim, quando o cliente manda a primeira mensagem, a origem viaja junto
 * ("Vim pelo META") e o webhook consegue gravar em leads.source automaticamente.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$studio = null;
try {
    $studio = get_studio(1);
} catch (Throwable $e) {
    $studio = null;
}

$allowed = ['meta', 'google', 'instagram', 'indicacao', 'porta', 'reincidente', 'outro'];
$src = strtolower(trim((string)($_GET['src'] ?? 'outro')));
if (!in_array($src, $allowed, true)) {
    $src = 'outro';
}

$labels = [
    'meta' => 'META',
    'google' => 'GOOGLE',
    'instagram' => 'INSTAGRAM',
    'indicacao' => 'INDICACAO',
    'porta' => 'PORTA',
    'reincidente' => 'REINCIDENTE',
    'outro' => 'OUTRO',
];
$label = $labels[$src];

// Cookie de origem (30 dias) - permite marcar a conversa mesmo se o cliente demorar a escrever.
setcookie('ad_src', $src, [
    'expires' => time() + 60 * 60 * 24 * 30,
    'path' => '/',
    'httponly' => false,
    'samesite' => 'Lax',
]);

// Registra a visita no banco (best effort, nunca quebra o redirect).
try {
    if ($studio) {
        $pdo = studio_db($studio);
        $pdo->exec('CREATE TABLE IF NOT EXISTS ads_src_hits (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            src VARCHAR(32) NOT NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            referer VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_src_date (src, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $stmt = $pdo->prepare('INSERT INTO ads_src_hits (src, ip, user_agent, referer) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $src,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
        ]);
    }
} catch (Throwable $e) {
    // silencioso de proposito: rastreio nao pode derrubar o atendimento
}

// Monta o link do WhatsApp com a marca de origem embutida na mensagem.
$phone = '5511957867798';
try {
    $settings = $studio ? studio_settings($studio) : [];
    $candidate = preg_replace('/\D+/', '', (string)($settings['whatsapp_official_display_number'] ?? ''));
    if ($candidate !== '' && strlen($candidate) >= 12) {
        $phone = $candidate;
    }
} catch (Throwable $e) {
    // mantem o numero padrao
}

$message = 'Ola! Vim pelo ' . $label . ' e quero fazer um orcamento de tatuagem.';
$waUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);

header('Cache-Control: no-store');
header('Location: ' . $waUrl, true, 302);
exit;
