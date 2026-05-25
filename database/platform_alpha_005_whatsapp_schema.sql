USE `projetocrm_platform`;

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
  KEY `idx_whatsapp_conversations_last` (`last_message_at`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `direction` ENUM('in', 'out') NOT NULL,
  `sender_type` ENUM('customer', 'human', 'bot', 'system') NOT NULL DEFAULT 'customer',
  `body` MEDIUMTEXT NULL,
  `media_url` VARCHAR(500) NULL,
  `media_mime` VARCHAR(120) NULL,
  `media_file_name` VARCHAR(255) NULL,
  `media_file_path` VARCHAR(500) NULL,
  `message_type` VARCHAR(40) NOT NULL DEFAULT 'texto',
  `message_id` VARCHAR(180) NULL,
  `remote_jid` VARCHAR(180) NULL,
  `from_me` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(40) NULL,
  `sent_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  `transcricao` MEDIUMTEXT NULL,
  `transcript` MEDIUMTEXT NULL,
  `transcricao_erro` TEXT NULL,
  `transcript_error` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_whatsapp_messages_conversation` (`conversation_id`, `sent_at`),
  KEY `idx_whatsapp_messages_message_id` (`message_id`),
  CONSTRAINT `fk_whatsapp_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `whatsapp_conversations` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

