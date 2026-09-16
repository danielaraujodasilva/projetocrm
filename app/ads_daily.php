<?php
/**
 * Painel de Retorno dos Anúncios (ads_daily)
 * Schema + lançamento diário de gastos por canal (Meta / Google).
 */
declare(strict_types=1);

function studio_ads_daily_ensure_schema($pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS ads_daily (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        campaign_date DATE NOT NULL,
        channel VARCHAR(24) NOT NULL DEFAULT "meta",
        campaign_name VARCHAR(180) NULL,
        spend DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        leads_direct INT NOT NULL DEFAULT 0,
        notes VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ads_daily (campaign_date, channel, campaign_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS ads_channel_config (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        channel VARCHAR(24) NOT NULL,
        daily_budget DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ads_channel (channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('INSERT IGNORE INTO ads_channel_config (channel, daily_budget) VALUES ("meta", 35.00), ("google", 50.00)');
}
