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
  `description` TEXT NULL,
  `monthly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `annual_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency_code` CHAR(3) NOT NULL DEFAULT 'BRL',
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

INSERT INTO `commercial_plans`
  (`name`, `slug`, `description`, `monthly_price`, `annual_price`, `currency_code`, `features_text`, `limits_text`, `sort_order`, `is_active`, `created_at`, `updated_at`)
VALUES
  ('Basico', 'basico', 'Entrada para estudios menores que querem organizar atendimento, agenda e operacao base.', 149.90, 1499.00, 'BRL', "CRM do estudio\nAgenda\nWhatsApp humano\nRelatorios basicos", "usuarios: 2\ntatuadores: 2\nleads_ativos: 500", 1, 1, NOW(), NOW()),
  ('Profissional', 'profissional', 'Plano principal para estudios em operacao diaria com WhatsApp, IA e mais equipe.', 249.90, 2499.00, 'BRL', "CRM completo\nAgenda\nWhatsApp com IA\nRespostas rapidas\nRelatorios", "usuarios: 5\ntatuadores: 5\nleads_ativos: 2000", 2, 1, NOW(), NOW()),
  ('Avancado', 'avancado', 'Plano para estudios com mais volume, equipe maior e uso intenso da operacao.', 399.90, 3999.00, 'BRL', "CRM completo\nAgenda\nWhatsApp com IA\nAssistente de dados\nRelatorios avancados", "usuarios: 15\ntatuadores: 12\nleads_ativos: 10000", 3, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `monthly_price` = VALUES(`monthly_price`),
  `annual_price` = VALUES(`annual_price`),
  `currency_code` = VALUES(`currency_code`),
  `features_text` = VALUES(`features_text`),
  `limits_text` = VALUES(`limits_text`),
  `sort_order` = VALUES(`sort_order`),
  `is_active` = VALUES(`is_active`),
  `updated_at` = NOW();
