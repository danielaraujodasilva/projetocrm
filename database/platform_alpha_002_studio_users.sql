USE `projetocrm_platform`;

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
