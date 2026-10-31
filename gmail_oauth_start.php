<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

try {
    $studio = require_studio();
    header('Location: ' . google_gmail_authorization_url($studio));
    exit;
} catch (Throwable $e) {
    flash_set('error', 'Gmail: ' . $e->getMessage());
    redirect_to('studio_agenda');
}