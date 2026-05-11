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
  `plan_name` VARCHAR(80) NOT NULL DEFAULT 'alpha',
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
  KEY `idx_studios_status` (`status`),
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
