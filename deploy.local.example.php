<?php

return [
    'secret' => 'troque-por-um-segredo-forte-do-webhook',
    'path' => __DIR__,
    'branch' => 'main',
    'whatsapp_service' => [
        'enabled' => true,
        'install' => true,
        'restart' => true,
        'port' => 3010,
        'webhook_url' => 'http://localhost/projetocrm/api/whatsapp_webhook.php',
    ],
];
