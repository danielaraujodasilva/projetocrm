CREATE DATABASE IF NOT EXISTS `{{DATABASE_NAME}}`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `{{DATABASE_NAME}}`;

CREATE TABLE IF NOT EXISTS `studio_settings` (
  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `studio_name` VARCHAR(160) NOT NULL,
  `studio_slug` VARCHAR(90) NOT NULL,
  `business_rules` MEDIUMTEXT NULL,
  `ai_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `ai_model` VARCHAR(120) NOT NULL DEFAULT 'llama3:8b',
  `whatsapp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `studio_settings`
  (`id`, `studio_name`, `studio_slug`, `business_rules`, `ai_model`, `created_at`, `updated_at`)
VALUES
  (1, '{{STUDIO_NAME}}', '{{STUDIO_SLUG}}', '{{BUSINESS_RULES}}', '{{AI_MODEL}}', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `studio_name` = VALUES(`studio_name`),
  `studio_slug` = VALUES(`studio_slug`),
  `business_rules` = IF(`business_rules` IS NULL OR `business_rules` = '', VALUES(`business_rules`), `business_rules`),
  `ai_model` = IF(`ai_model` IS NULL OR `ai_model` = '', VALUES(`ai_model`), `ai_model`),
  `updated_at` = NOW();

CREATE TABLE IF NOT EXISTS `customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NULL,
  `phone` VARCHAR(40) NULL,
  `email` VARCHAR(180) NULL,
  `instagram` VARCHAR(120) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customers_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(160) NULL,
  `phone` VARCHAR(40) NULL,
  `interest` VARCHAR(220) NULL,
  `status` VARCHAR(60) NOT NULL DEFAULT 'novo',
  `pipeline_stage` VARCHAR(80) NULL,
  `lead_score` TINYINT UNSIGNED NULL,
  `estimated_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `source` VARCHAR(80) NULL,
  `last_contact_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_leads_status` (`status`),
  KEY `idx_leads_phone` (`phone`),
  CONSTRAINT `fk_leads_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pipeline_stages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(90) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `color` VARCHAR(20) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pipeline_stages_name` (`name`),
  KEY `idx_pipeline_stages_order` (`sort_order`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pipeline_stages` (`name`, `sort_order`, `color`, `created_at`, `updated_at`)
VALUES
  ('entrada', 10, '#2d8992', NOW(), NOW()),
  ('em_conversa', 20, '#1f6f78', NOW(), NOW()),
  ('orcamento', 30, '#a86300', NOW(), NOW()),
  ('pre_agendado', 40, '#7c3aed', NOW(), NOW()),
  ('agendado', 50, '#1d7f48', NOW(), NOW()),
  ('perdido', 90, '#a33b3b', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `sort_order` = VALUES(`sort_order`),
  `color` = VALUES(`color`),
  `updated_at` = NOW();

CREATE TABLE IF NOT EXISTS `appointments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NULL,
  `lead_id` BIGINT UNSIGNED NULL,
  `title` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `appointment_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NULL,
  `status` VARCHAR(60) NOT NULL DEFAULT 'pre_agendado',
  `value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `deposit_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_appointments_date` (`appointment_date`, `start_time`),
  KEY `idx_appointments_status` (`status`),
  CONSTRAINT `fk_appointments_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_lead`
    FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(90) NOT NULL DEFAULT 'Geral',
  `description` VARCHAR(220) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `expense_date` DATE NOT NULL,
  `payment_method` VARCHAR(80) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `idx_expenses_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quick_replies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(140) NOT NULL,
  `shortcut` VARCHAR(80) NULL,
  `category` VARCHAR(80) NOT NULL DEFAULT 'Geral',
  `body` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_quick_replies_shortcut` (`shortcut`),
  KEY `idx_quick_replies_category` (`category`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `quick_replies` (`title`, `shortcut`, `category`, `body`, `is_active`, `created_at`, `updated_at`)
VALUES
  ('Pedir referencia', '/referencia', 'Orcamento', 'Pode me mandar uma referencia da ideia, o tamanho aproximado em cm e o local do corpo?', 1, NOW(), NOW()),
  ('Sinal para reserva', '/sinal', 'Agendamento', 'Para reservar o horario trabalhamos com sinal. Depois eu confirmo tudo certinho com voce.', 1, NOW(), NOW()),
  ('Chamar humano', '/humano', 'Atendimento', 'Vou chamar uma pessoa do estudio para continuar com voce. Pode demorar um pouquinho, mas ja deixei sinalizado aqui.', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `category` = VALUES(`category`),
  `body` = VALUES(`body`),
  `updated_at` = NOW();

CREATE TABLE IF NOT EXISTS `whatsapp_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` BIGINT UNSIGNED NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `phone` VARCHAR(40) NOT NULL,
  `name` VARCHAR(160) NULL,
  `attendance_mode` ENUM('human', 'bot') NOT NULL DEFAULT 'human',
  `ai_last_status` VARCHAR(80) NULL,
  `ai_last_message` TEXT NULL,
  `ai_last_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_whatsapp_conversations_phone` (`phone`),
  CONSTRAINT `fk_whatsapp_conversations_lead`
    FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_whatsapp_conversations_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `direction` ENUM('in', 'out') NOT NULL,
  `sender_type` ENUM('customer', 'human', 'bot', 'system') NOT NULL DEFAULT 'customer',
  `body` MEDIUMTEXT NULL,
  `media_url` VARCHAR(500) NULL,
  `media_mime` VARCHAR(120) NULL,
  `message_id` VARCHAR(180) NULL,
  `sent_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_whatsapp_messages_conversation` (`conversation_id`, `sent_at`),
  CONSTRAINT `fk_whatsapp_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `whatsapp_conversations` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
