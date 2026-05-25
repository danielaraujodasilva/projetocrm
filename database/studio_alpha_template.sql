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
  `whatsapp_default_mode` ENUM('human', 'bot') NOT NULL DEFAULT 'human',
  `whatsapp_service_url` VARCHAR(220) NOT NULL DEFAULT 'http://localhost:3010',
  `appointment_work_days` VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5',
  `appointment_time_slots` VARCHAR(80) NOT NULL DEFAULT '10:00,15:00',
  `appointment_duration_minutes` INT NOT NULL DEFAULT 300,
  `appointment_overwrite_message` TEXT NULL,
  `meta_campaign_phrases` TEXT NULL,
  `pomada_unit_price` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  `whatsapp_webhook_token` VARCHAR(120) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `studio_settings`
  ADD COLUMN IF NOT EXISTS `whatsapp_default_mode` ENUM('human', 'bot') NOT NULL DEFAULT 'human' AFTER `whatsapp_enabled`,
  ADD COLUMN IF NOT EXISTS `whatsapp_service_url` VARCHAR(220) NOT NULL DEFAULT 'http://localhost:3010' AFTER `whatsapp_default_mode`,
  ADD COLUMN IF NOT EXISTS `appointment_work_days` VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5' AFTER `whatsapp_service_url`,
  ADD COLUMN IF NOT EXISTS `appointment_time_slots` VARCHAR(80) NOT NULL DEFAULT '10:00,15:00' AFTER `appointment_work_days`,
  ADD COLUMN IF NOT EXISTS `appointment_duration_minutes` INT NOT NULL DEFAULT 300 AFTER `appointment_time_slots`,
  ADD COLUMN IF NOT EXISTS `appointment_overwrite_message` TEXT NULL AFTER `appointment_duration_minutes`,
  ADD COLUMN IF NOT EXISTS `meta_campaign_phrases` TEXT NULL AFTER `appointment_overwrite_message`,
  ADD COLUMN IF NOT EXISTS `pomada_unit_price` DECIMAL(10,2) NOT NULL DEFAULT 100.00 AFTER `meta_campaign_phrases`,
  ADD COLUMN IF NOT EXISTS `whatsapp_webhook_token` VARCHAR(120) NULL AFTER `whatsapp_service_url`;

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
  `import_source` VARCHAR(40) NULL,
  `import_uid` VARCHAR(190) NULL,
  `raw_title` VARCHAR(260) NULL,
  `last_contact_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_leads_status` (`status`),
  KEY `idx_leads_phone` (`phone`),
  UNIQUE KEY `uk_leads_import_uid` (`import_source`, `import_uid`),
  CONSTRAINT `fk_leads_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `import_source` VARCHAR(40) NULL AFTER `source`,
  ADD COLUMN IF NOT EXISTS `import_uid` VARCHAR(190) NULL AFTER `import_source`,
  ADD COLUMN IF NOT EXISTS `raw_title` VARCHAR(260) NULL AFTER `import_uid`,
  ADD UNIQUE KEY IF NOT EXISTS `uk_leads_import_uid` (`import_source`, `import_uid`);

CREATE TABLE IF NOT EXISTS `tattoo_artists` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(140) NOT NULL,
  `specialty` VARCHAR(160) NULL,
  `color` VARCHAR(20) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tattoo_artists_active` (`is_active`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tattoo_artists` (`id`, `name`, `specialty`, `color`, `is_active`, `created_at`, `updated_at`)
VALUES
  (1, '{{STUDIO_NAME}}', 'Tatuagem', '#1f6f78', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name` = IF(`name` IS NULL OR `name` = '', VALUES(`name`), `name`),
  `updated_at` = NOW();

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
  `artist_id` INT UNSIGNED NULL,
  `title` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `appointment_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NULL,
  `status` VARCHAR(60) NOT NULL DEFAULT 'pre_agendado',
  `value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `deposit_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pomada_unit_price` DECIMAL(10,2) NULL,
  `pomadas_quantity` INT NOT NULL DEFAULT 0,
  `import_source` VARCHAR(40) NULL,
  `import_uid` VARCHAR(190) NULL,
  `raw_title` VARCHAR(260) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_appointments_date` (`appointment_date`, `start_time`),
  KEY `idx_appointments_status` (`status`),
  KEY `idx_appointments_artist` (`artist_id`),
  UNIQUE KEY `uk_appointments_import_uid` (`import_source`, `import_uid`),
  CONSTRAINT `fk_appointments_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_lead`
    FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_artist`
    FOREIGN KEY (`artist_id`) REFERENCES `tattoo_artists` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `artist_id` INT UNSIGNED NULL AFTER `lead_id`,
  ADD COLUMN IF NOT EXISTS `import_source` VARCHAR(40) NULL AFTER `deposit_value`,
  ADD COLUMN IF NOT EXISTS `import_uid` VARCHAR(190) NULL AFTER `import_source`,
  ADD COLUMN IF NOT EXISTS `raw_title` VARCHAR(260) NULL AFTER `import_uid`,
  ADD COLUMN IF NOT EXISTS `pomada_unit_price` DECIMAL(10,2) NULL AFTER `deposit_value`,
  ADD COLUMN IF NOT EXISTS `pomadas_quantity` INT NOT NULL DEFAULT 0 AFTER `pomada_unit_price`,
  ADD UNIQUE KEY IF NOT EXISTS `uk_appointments_import_uid` (`import_source`, `import_uid`),
  ADD INDEX IF NOT EXISTS `idx_appointments_artist` (`artist_id`);

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
  `remote_jid` VARCHAR(180) NULL,
  `attendance_mode` ENUM('human', 'bot') NOT NULL DEFAULT 'human',
  `needs_human` TINYINT(1) NOT NULL DEFAULT 0,
  `lead_score` TINYINT UNSIGNED NULL,
  `ai_last_status` VARCHAR(80) NULL,
  `ai_last_message` TEXT NULL,
  `ai_last_at` DATETIME NULL,
  `last_message_preview` VARCHAR(260) NULL,
  `last_message_direction` ENUM('in', 'out') NULL,
  `last_message_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_whatsapp_conversations_phone` (`phone`),
  KEY `idx_whatsapp_conversations_last` (`last_message_at`, `updated_at`),
  CONSTRAINT `fk_whatsapp_conversations_lead`
    FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_whatsapp_conversations_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `whatsapp_conversations`
  ADD COLUMN IF NOT EXISTS `remote_jid` VARCHAR(180) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `needs_human` TINYINT(1) NOT NULL DEFAULT 0 AFTER `attendance_mode`,
  ADD COLUMN IF NOT EXISTS `lead_score` TINYINT UNSIGNED NULL AFTER `needs_human`,
  ADD COLUMN IF NOT EXISTS `last_message_preview` VARCHAR(260) NULL AFTER `ai_last_at`,
  ADD COLUMN IF NOT EXISTS `last_message_direction` ENUM('in', 'out') NULL AFTER `last_message_preview`,
  ADD COLUMN IF NOT EXISTS `last_message_at` DATETIME NULL AFTER `last_message_direction`,
  ADD INDEX IF NOT EXISTS `idx_whatsapp_conversations_last` (`last_message_at`, `updated_at`);

CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `direction` ENUM('in', 'out') NOT NULL,
  `sender_type` ENUM('customer', 'human', 'bot', 'system') NOT NULL DEFAULT 'customer',
  `body` MEDIUMTEXT NULL,
  `media_url` VARCHAR(500) NULL,
  `media_mime` VARCHAR(120) NULL,
  `message_type` VARCHAR(40) NOT NULL DEFAULT 'texto',
  `message_id` VARCHAR(180) NULL,
  `remote_jid` VARCHAR(180) NULL,
  `from_me` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(40) NULL,
  `sent_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_whatsapp_messages_conversation` (`conversation_id`, `sent_at`),
  KEY `idx_whatsapp_messages_message_id` (`message_id`),
  CONSTRAINT `fk_whatsapp_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `whatsapp_conversations` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `whatsapp_messages`
  ADD COLUMN IF NOT EXISTS `message_type` VARCHAR(40) NOT NULL DEFAULT 'texto' AFTER `media_mime`,
  ADD COLUMN IF NOT EXISTS `remote_jid` VARCHAR(180) NULL AFTER `message_id`,
  ADD COLUMN IF NOT EXISTS `from_me` TINYINT(1) NOT NULL DEFAULT 0 AFTER `remote_jid`,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(40) NULL AFTER `from_me`,
  ADD INDEX IF NOT EXISTS `idx_whatsapp_messages_message_id` (`message_id`);
