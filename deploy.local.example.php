<?php

return [
    'secret' => 'troque-por-um-segredo-forte-do-webhook',
    'path' => __DIR__,
    'branch' => 'main',
    'ffmpeg' => [
        'enabled' => true,
        'binary' => 'ffmpeg',
        // Em VPS Debian/Ubuntu, o deploy tenta apt-get automaticamente.
        // Em hospedagem gerenciada, informe o comando permitido pelo provedor.
        'install_command' => '',
    ],
    'whatsapp_service' => [
        'enabled' => true,
        'install' => true,
        'restart' => true,
        'port' => 3010,
        'webhook_url' => 'http://localhost/projetocrm/api/whatsapp_webhook.php',
    ],
];
