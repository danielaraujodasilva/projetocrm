<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('APP_BASE_PATH', dirname(__DIR__));

$baseConfig = require APP_BASE_PATH . '/config/database.php';
$localConfigPath = APP_BASE_PATH . '/config/database.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];

if (!is_array($localConfig)) {
    $localConfig = [];
}

$GLOBALS['app_config'] = [
    'database' => array_merge($baseConfig, $localConfig),
];

require APP_BASE_PATH . '/app/functions.php';
require APP_BASE_PATH . '/app/studio_crm.php';
