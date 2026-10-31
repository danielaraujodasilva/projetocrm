<?php
/**
 * Link de rastreio de origem de anúncio.
 * Uso: /projetocrm/ir.php?src=meta   (ou src=google, src=tiktok,
 *      src=tiktok_organico, src=instagram, src=indicacao)
 *
 * O que faz:
 *  1. lê o parâmetro src e normaliza (meta|google|instagram|indicacao|outro)
 *  2. lê o click id do Google Ads (gclid|wbraid|gbraid) ou do TikTok (ttclid)
 *  3. grava a origem no cookie (ad_src) e registra a visita em ads_src_hits
 *  4. redireciona para o WhatsApp do estúdio com uma mensagem inicial que carrega
 *     a marca de origem e um código curto [ref:xxxx] quando veio clique de anúncio.
 *
 * POR QUE O CODIGO CURTO (ref)
 * O gclid e longo e nao pode aparecer para o cliente. O site guarda o gclid na
 * visita e manda so o codigo curto no WhatsApp; a ponte le o codigo e o CRM
 * recupera o gclid. E o que permite enviar a conversao REAL (cliente agendou)
 * de volta ao Google Ads.
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

$allowed = ['meta', 'google', 'tiktok', 'tiktok_organico', 'instagram', 'indicacao', 'porta', 'reincidente', 'outro'];
$src = strtolower(trim((string)($_GET['src'] ?? 'outro')));
if (!in_array($src, $allowed, true)) {
    $src = 'outro';
}

$labels = [
    'meta' => 'META',
    'google' => 'GOOGLE',
    'tiktok' => 'TIKTOK',
    'tiktok_organico' => 'TIKTOK',
    'instagram' => 'INSTAGRAM',
    'indicacao' => 'INDICACAO',
    'porta' => 'PORTA',
    'reincidente' => 'REINCIDENTE',
    'outro' => 'OUTRO',
];
$label = $labels[$src];

// Click id do anuncio. So existe quando o clique veio de um anuncio com
// marcacao automatica ligada:
//   gclid  -> clique na web
//   wbraid -> clique em app no iOS
//   gbraid -> clique em app no Android
//   ttclid -> TikTok Ads (parametro oficial de click id)
// Guardado junto com a visita para depois casar a conversao REAL do CRM
// (cliente que agendou) com o clique que a originou.
$clickId = '';
$clickIdCampo = '';
foreach (['gclid', 'wbraid', 'gbraid', 'ttclid'] as $campoClick) {
    $valorClick = trim((string)($_GET[$campoClick] ?? ''));
    if ($valorClick !== '') {
        $clickId = $valorClick;
        $clickIdCampo = $campoClick;
        break;
    }
}
// Codigo curto que viaja dentro da mensagem do WhatsApp: o cliente nao ve o
// gclid cru (que e enorme) e o CRM acha a visita pelo codigo.
$ref = '';

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
        // Colunas novas (click id + codigo curto). IF NOT EXISTS e do MariaDB e
        // mantem o arquivo seguro em bancos onde a tabela ja existia sem elas.
        $pdo->exec('ALTER TABLE ads_src_hits ADD COLUMN IF NOT EXISTS gclid VARCHAR(255) NULL AFTER src');
        $pdo->exec('ALTER TABLE ads_src_hits ADD COLUMN IF NOT EXISTS ttclid VARCHAR(255) NULL AFTER gclid');
        $pdo->exec('ALTER TABLE ads_src_hits ADD COLUMN IF NOT EXISTS ref_code VARCHAR(32) NULL AFTER ttclid');
        $pdo->exec('ALTER TABLE ads_src_hits ADD INDEX IF NOT EXISTS idx_ref (ref_code)');
        if ($clickId !== '') {
            $ref = bin2hex(random_bytes(4));
        }
        $stmt = $pdo->prepare('INSERT INTO ads_src_hits (src, gclid, ttclid, ref_code, ip, user_agent, referer) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $src,
            $clickId !== '' && $clickIdCampo !== 'ttclid' ? $clickId : null,
            $clickId !== '' && $clickIdCampo === 'ttclid' ? $clickId : null,
            $ref !== '' ? $ref : null,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
        ]);
    }
} catch (Throwable $e) {
    // silencioso de proposito: rastreio nao pode derrubar o atendimento
}

// Monta o link do WhatsApp com a marca de origem embutida na mensagem.
// SEMPRE o numero da ponte (Baileys), que e a linha que recebe os anuncios.
// NAO usar whatsapp_official_display_number: aquele e o numero da API oficial
// (Cloud API), que nao e a linha lida pela ponte — era o bug que mandava os
// cliques de anuncio para o numero errado.
$phone = '5511999979785';

$message = 'Ola! Vim pelo ' . $label . ' e quero fazer um orcamento de tatuagem.';
// O codigo curto viaja na mensagem: a ponte le e o CRM casa com a visita,
// recuperando o gclid para enviar a conversao real ao Google Ads.
if ($ref !== '') {
    $message .= "\n\n[ref:" . $ref . "]";
}
$waUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);

header('Cache-Control: no-store');
header('Location: ' . $waUrl, true, 302);
exit;
