<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$android = "12/07/2026 21:15 - Cliente Teste: Oi\n"
    . "12/07/2026 21:15 - Cliente Teste: quero fazer\n"
    . "um orçamento de tatuagem\n"
    . "12/07/2026 21:16 - Fran: Claro, me mande a referência.\n"
    . "12/07/2026 21:17 - Cliente Teste: IMG-20260712-WA0001.jpg (arquivo anexado)\n";
$parsedAndroid = studio_whatsapp_learning_parse_export_text($android);
$assert(count($parsedAndroid['messages']) === 4, 'Android: quantidade de mensagens incorreta.');
$assert(str_contains((string)$parsedAndroid['messages'][1]['body'], 'um orçamento'), 'Android: continuação de mensagem não foi preservada.');
$assert(in_array('IMG-20260712-WA0001.jpg', (array)$parsedAndroid['messages'][3]['attachments'], true), 'Android: anexo não foi reconhecido.');

$ios = "[13/07/2026, 09:00:01] Cliente: Bom dia\n"
    . "[13/07/2026, 09:00:05] Fran: Bom dia! Como posso ajudar?\n"
    . "[13/07/2026, 09:01:00] Cliente: Quero tatuar uma rosa no braço\n"
    . "[13/07/2026, 09:01:08] Fran: Qual tamanho aproximado?\n";
$parsedIos = studio_whatsapp_learning_parse_export_text($ios);
$assert(count($parsedIos['messages']) === 4, 'iPhone: quantidade de mensagens incorreta.');
$assert(count($parsedIos['participants']) === 2, 'iPhone: participantes não foram identificados.');

if (!class_exists('ZipArchive')) {
    $failures[] = 'Extensão ZipArchive não está disponível.';
} else {
    $tempRoot = sys_get_temp_dir();
    $caseDir = $tempRoot . DIRECTORY_SEPARATOR . 'crm_learning_test_' . bin2hex(random_bytes(6));
    mkdir($caseDir, 0700, true);
    try {
        $safeZipPath = $caseDir . DIRECTORY_SEPARATOR . 'safe.zip';
        $safeZip = new ZipArchive();
        $safeZip->open($safeZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $safeZip->addFromString('Conversa do WhatsApp.txt', $android);
        $safeZip->addFromString('IMG-20260712-WA0001.jpg', 'fake-image-content');
        $safeZip->addFromString('executar.exe', 'not-allowed');
        $safeZip->close();

        $unpackDir = $caseDir . DIRECTORY_SEPARATOR . 'unpacked';
        mkdir($unpackDir, 0700, true);
        $files = studio_whatsapp_learning_unpack_zip($safeZipPath, $unpackDir);
        $extensions = array_values(array_map(static fn(array $file): string => (string)$file['extension'], $files));
        sort($extensions);
        $assert($extensions === ['jpg', 'txt'], 'ZIP seguro: allowlist de extensões falhou.');
        $assert(!is_file($unpackDir . DIRECTORY_SEPARATOR . 'executar.exe'), 'ZIP seguro: executável foi gravado.');

        $evilZipPath = $caseDir . DIRECTORY_SEPARATOR . 'traversal.zip';
        $evilZip = new ZipArchive();
        $evilZip->open($evilZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $evilZip->addFromString('../fora.txt', 'não deve sair da pasta');
        $evilZip->close();
        $blocked = false;
        try {
            studio_whatsapp_learning_unpack_zip($evilZipPath, $unpackDir);
        } catch (Throwable) {
            $blocked = true;
        }
        $assert($blocked, 'ZIP malicioso: tentativa de path traversal não foi bloqueada.');
        $assert(!is_file($caseDir . DIRECTORY_SEPARATOR . 'fora.txt'), 'ZIP malicioso: arquivo escapou da pasta temporária.');
    } finally {
        studio_delete_directory($caseDir, $tempRoot);
    }
}

if ($failures) {
    fwrite(STDERR, "Falhas no teste de importação:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Importação de aprendizado: parser e segurança do ZIP aprovados.\n";
