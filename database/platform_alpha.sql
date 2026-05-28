CREATE DATABASE IF NOT EXISTS `projetocrm_platform`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `projetocrm_platform`;

CREATE TABLE IF NOT EXISTS `platform_admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(180) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('owner', 'admin') NOT NULL DEFAULT 'admin',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_admins_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commercial_plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(90) NOT NULL,
  `short_description` VARCHAR(255) NULL,
  `description` MEDIUMTEXT NULL,
  `monthly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `annual_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency_code` CHAR(3) NOT NULL DEFAULT 'BRL',
  `recommended` TINYINT(1) NOT NULL DEFAULT 0,
  `studio_limit` INT UNSIGNED NULL,
  `user_limit` INT UNSIGNED NULL,
  `tattoo_artist_limit` INT UNSIGNED NULL,
  `lead_limit` INT UNSIGNED NULL,
  `whatsapp_session_limit` INT UNSIGNED NULL,
  `allow_whatsapp` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_ai` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_data_assistant` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_finance` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_advanced_reports` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_automations` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_multi_studio` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_external_integrations` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_advanced_customization` TINYINT(1) NOT NULL DEFAULT 0,
  `features_text` MEDIUMTEXT NULL,
  `limits_text` MEDIUMTEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_commercial_plans_slug` (`slug`),
  KEY `idx_commercial_plans_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `slug` VARCHAR(90) NOT NULL,
  `status` ENUM('setup', 'active', 'paused', 'disabled') NOT NULL DEFAULT 'setup',
  `owner_name` VARCHAR(160) NULL,
  `owner_email` VARCHAR(180) NULL,
  `owner_phone` VARCHAR(40) NULL,
  `database_name` VARCHAR(100) NOT NULL,
  `database_host` VARCHAR(120) NOT NULL DEFAULT 'localhost',
  `database_user` VARCHAR(120) NOT NULL DEFAULT 'root',
  `plan_id` INT UNSIGNED NULL,
  `plan_name` VARCHAR(80) NOT NULL DEFAULT 'basico',
  `ai_model` VARCHAR(120) NOT NULL DEFAULT 'llama3:8b',
  `business_rules` MEDIUMTEXT NULL,
  `whatsapp_status` ENUM('not_configured', 'waiting_qr', 'connected', 'disconnected', 'error') NOT NULL DEFAULT 'not_configured',
  `whatsapp_session_key` VARCHAR(140) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_studios_slug` (`slug`),
  UNIQUE KEY `uk_studios_database_name` (`database_name`),
  KEY `idx_studios_plan` (`plan_id`),
  KEY `idx_studios_status` (`status`),
  CONSTRAINT `fk_studios_plan`
    FOREIGN KEY (`plan_id`) REFERENCES `commercial_plans` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_studios_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `platform_admins` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studio_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studio_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `message` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_studio_events_studio` (`studio_id`, `created_at`),
  CONSTRAINT `fk_studio_events_studio`
    FOREIGN KEY (`studio_id`) REFERENCES `studios` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studio_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studio_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(180) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('owner', 'manager', 'attendant') NOT NULL DEFAULT 'owner',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_studio_users_email` (`email`),
  KEY `idx_studio_users_studio` (`studio_id`),
  CONSTRAINT `fk_studio_users_studio`
    FOREIGN KEY (`studio_id`) REFERENCES `studios` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `public_lead_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studio_id` INT UNSIGNED NOT NULL,
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `lead_customer_id` BIGINT UNSIGNED NULL,
  `draft_payload` LONGTEXT NULL,
  `last_step` VARCHAR(40) NULL,
  `finished_at` DATETIME NULL,
  `last_accessed_at` DATETIME NULL,
  `last_ip_hash` VARCHAR(120) NULL,
  `last_user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_public_lead_links_token` (`token`),
  UNIQUE KEY `uk_public_lead_links_studio_lead` (`studio_id`, `lead_id`),
  KEY `idx_public_lead_links_studio` (`studio_id`, `created_at`),
  CONSTRAINT `fk_public_lead_links_studio`
    FOREIGN KEY (`studio_id`) REFERENCES `studios` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `public_lead_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `studio_id` INT UNSIGNED NOT NULL,
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `event_name` VARCHAR(60) NOT NULL,
  `event_payload` LONGTEXT NULL,
  `ip_hash` VARCHAR(120) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_public_lead_events_lookup` (`studio_id`, `lead_id`, `event_name`, `created_at`),
  KEY `idx_public_lead_events_token` (`token`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `commercial_plans`
  (`name`, `slug`, `short_description`, `description`, `monthly_price`, `annual_price`, `currency_code`, `recommended`, `studio_limit`, `user_limit`, `tattoo_artist_limit`, `lead_limit`, `whatsapp_session_limit`, `allow_whatsapp`, `allow_ai`, `allow_data_assistant`, `allow_finance`, `allow_advanced_reports`, `allow_automations`, `allow_multi_studio`, `allow_external_integrations`, `allow_advanced_customization`, `features_text`, `limits_text`, `sort_order`, `is_active`, `created_at`, `updated_at`)
VALUES
  ('Basico', 'basico', 'Para tatuadores solo ou estúdios pequenos começando a organizar atendimento e agenda.', 'Para tatuadores solo ou estúdios pequenos começando a organizar atendimento e agenda.', 79.00, 790.00, 'BRL', 0, 1, 2, 1, 500, 0, 1, 0, 0, 1, 0, 0, 0, 0, 0, "Cadastro de clientes\nLeads e funil\nAgenda\nFinanceiro simples\nRespostas rápidas\nRelatórios básicos", "Usuarios: 2\nTatuadores: 1\nClientes/leads: 500\nWhatsApp: limitado", 1, 1, NOW(), NOW()),
  ('Profissional', 'profissional', 'Para estúdios que recebem muitos leads e precisam controlar WhatsApp, agenda, equipe e vendas.', 'Para estúdios que recebem muitos leads e precisam controlar WhatsApp, agenda, equipe e vendas.', 149.00, 1490.00, 'BRL', 1, 1, 5, 5, 3000, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, "Tudo do Básico\nWhatsApp/Baileys\nCentral de atendimento\nRespostas rápidas avançadas\nAgenda com controle de conflitos\nRelatórios gerenciais\nPermissões por usuário\nFollow-up manual/assistido", "Usuarios: 5\nTatuadores: 5\nClientes/leads: 3000\nWhatsApp: 1 sessão", 2, 1, NOW(), NOW()),
  ('Avancado', 'avancado', 'Para estúdios maiores, redes ou operações que querem automação, IA e relatórios avançados.', 'Para estúdios maiores, redes ou operações que querem automação, IA e relatórios avançados.', 299.00, 2990.00, 'BRL', 1, 3, 15, 15, 20000, 3, 1, 1, 1, 1, 1, 1, 1, 1, 1, "Tudo do Profissional\nIA para classificação de leads\nAssistente de dados\nSugestão de respostas por IA\nAutomações de follow-up\nRelatórios avançados/BI\nMulti-estúdio\nIntegrações externas/API\nPersonalização avançada do funil", "Estúdios: 3\nUsuarios: 15\nTatuadores: 15\nClientes/leads: 20000\nWhatsApp: 3 sessões", 3, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `short_description` = VALUES(`short_description`),
  `description` = VALUES(`description`),
  `monthly_price` = VALUES(`monthly_price`),
  `annual_price` = VALUES(`annual_price`),
  `currency_code` = VALUES(`currency_code`),
  `recommended` = VALUES(`recommended`),
  `studio_limit` = VALUES(`studio_limit`),
  `user_limit` = VALUES(`user_limit`),
  `tattoo_artist_limit` = VALUES(`tattoo_artist_limit`),
  `lead_limit` = VALUES(`lead_limit`),
  `whatsapp_session_limit` = VALUES(`whatsapp_session_limit`),
  `allow_whatsapp` = VALUES(`allow_whatsapp`),
  `allow_ai` = VALUES(`allow_ai`),
  `allow_data_assistant` = VALUES(`allow_data_assistant`),
  `allow_finance` = VALUES(`allow_finance`),
  `allow_advanced_reports` = VALUES(`allow_advanced_reports`),
  `allow_automations` = VALUES(`allow_automations`),
  `allow_multi_studio` = VALUES(`allow_multi_studio`),
  `allow_external_integrations` = VALUES(`allow_external_integrations`),
  `allow_advanced_customization` = VALUES(`allow_advanced_customization`),
  `features_text` = VALUES(`features_text`),
  `limits_text` = VALUES(`limits_text`),
  `sort_order` = VALUES(`sort_order`),
  `is_active` = VALUES(`is_active`),
  `updated_at` = NOW();
