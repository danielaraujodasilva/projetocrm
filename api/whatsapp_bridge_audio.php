<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

require_studio();

$file = basename(trim((string)($_GET['file'] ?? '')));
if ($file === '' || preg_match('/^[A-Za-z0-9._-]+\.(ogg|oga|opus|mp3|wav|m4a|aac|webm)$/i', $file) !== 1) {
    http_response_code(404);
    exit;
}

$baseDir = 'C:\\Users\\server_spd\\Documents\\whatsapp-origin-bridge\\dados\\audios';
$path = $baseDir . DIRECTORY_SEPARATOR . $file;
$realPath = realpath($path);
$realBase = realpath($baseDir);
if ($realPath === false || $realBase === false || !str_starts_with(strtolower($realPath), strtolower($realBase . DIRECTORY_SEPARATOR)) || !is_file($realPath)) {
    http_response_code(404);
    exit;
}

$size = filesize($realPath);
if ($size === false || $size <= 0) {
    http_response_code(404);
    exit;
}

$mimes = [
    'ogg' => 'audio/ogg',
    'oga' => 'audio/ogg',
    'opus' => 'audio/ogg',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'm4a' => 'audio/mp4',
    'aac' => 'audio/aac',
    'webm' => 'audio/webm',
];
$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mime = $mimes[$extension] ?? 'application/octet-stream';
$start = 0;
$end = $size - 1;

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');

$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/i', $range, $matches) === 1) {
    if ($matches[1] === '' && $matches[2] === '') {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    if ($matches[1] === '') {
        $suffix = (int)$matches[2];
        $start = max(0, $size - $suffix);
    } else {
        $start = (int)$matches[1];
        if ($matches[2] !== '') {
            $end = min($end, (int)$matches[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$handle = fopen($realPath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

fseek($handle, $start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(8192, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);
