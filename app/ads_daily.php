<?php
/**
 * Painel de Retorno dos Anúncios (ads_daily)
 * Schema + lançamento diário de gastos por canal (Meta / Google / TikTok).
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

    // TikTok entra com orçamento inicial de R$ 20/dia. Fica inativo na projeção
    // mensal até a campanha estar realmente rodando (evita projetar 30 dias de
    // um teste de 4 dias). O gasto real entra em ads_daily normalmente.
    $pdo->exec('INSERT IGNORE INTO ads_channel_config (channel, daily_budget, active) VALUES ("meta", 35.00, 1), ("google", 50.00, 1), ("tiktok", 20.00, 0)');

    // Quebra do MESMO gasto por campanha. Fica em tabela separada de ads_daily de
    // proposito: ads_daily guarda a linha [SYNC META]/[SYNC GOOGLE] com o total do
    // dia e e ela que o painel soma (regra anti-duplicidade). Guardar a campanha
    // aqui deixa ver o gasto por campanha sem somar o mesmo dinheiro 2x.
    $pdo->exec('CREATE TABLE IF NOT EXISTS ads_campaign_daily (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        campaign_date DATE NOT NULL,
        channel VARCHAR(24) NOT NULL DEFAULT "meta",
        campaign_id VARCHAR(64) NOT NULL DEFAULT "",
        campaign_name VARCHAR(180) NOT NULL DEFAULT "",
        spend DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ads_campaign_daily (campaign_date, channel, campaign_id),
        KEY idx_ads_campaign_dia (campaign_date, channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // De qual CAMPANHA veio cada anuncio do Meta.
    // A ponte grava o id do anuncio (source_id do pacote ctwaContext) em
    // ads_origin_hits.ctwa_clid, mas o rastreio por WhatsApp Web nao conhece a
    // campanha — quem sabe e a API do Meta. Aqui fica o resultado dessa consulta,
    // uma linha por anuncio, para o painel atribuir lead por campanha sem
    // perguntar de novo a cada abertura de tela.
    $pdo->exec('CREATE TABLE IF NOT EXISTS ads_meta_ad (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ad_id VARCHAR(64) NOT NULL,
        campaign_id VARCHAR(64) NOT NULL DEFAULT "",
        campaign_name VARCHAR(180) NOT NULL DEFAULT "",
        estado VARCHAR(24) NOT NULL DEFAULT "ok",
        tentado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ads_meta_ad (ad_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
