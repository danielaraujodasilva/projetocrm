<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionLifetime = 60 * 60 * 24 * 30;
    if (PHP_SAPI !== 'cli') {
        session_set_cookie_params([
            'lifetime' => $sessionLifetime,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_start();
}

define('APP_BASE_PATH', dirname(__DIR__));

$baseConfig = require APP_BASE_PATH . '/config/database.php';
$localConfigPath = APP_BASE_PATH . '/config/database.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
$baseAppConfig = is_file(APP_BASE_PATH . '/config/app.php') ? require APP_BASE_PATH . '/config/app.php' : [];

if (!is_array($localConfig)) {
    $localConfig = [];
}
if (!is_array($baseAppConfig)) {
    $baseAppConfig = [];
}

$GLOBALS['app_config'] = [
    'database' => array_merge($baseConfig, $localConfig),
    'app' => $baseAppConfig,
];

require APP_BASE_PATH . '/app/functions.php';
require APP_BASE_PATH . '/app/studio_crm.php';
require_once APP_BASE_PATH . '/app/tattoo_image_studio_override_v2.php';
require_once __DIR__ . '/whatsapp_official_runtime.php';
