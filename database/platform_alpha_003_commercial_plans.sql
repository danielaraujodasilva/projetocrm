USE `projetocrm_platform`;

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

SET @has_plan_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'studios'
    AND COLUMN_NAME = 'plan_id'
);

SET @sql_add_plan_id := IF(
  @has_plan_id = 0,
  'ALTER TABLE `studios` ADD COLUMN `plan_id` INT UNSIGNED NULL AFTER `database_user`',
  'SELECT 1'
);
PREPARE stmt_add_plan_id FROM @sql_add_plan_id;
EXECUTE stmt_add_plan_id;
DEALLOCATE PREPARE stmt_add_plan_id;

ALTER TABLE `studios`
  MODIFY COLUMN `plan_name` VARCHAR(80) NOT NULL DEFAULT 'basico';

SET @has_plan_fk := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'studios'
    AND COLUMN_NAME = 'plan_id'
    AND REFERENCED_TABLE_NAME = 'commercial_plans'
);

SET @sql_add_plan_fk := IF(
  @has_plan_fk = 0,
  'ALTER TABLE `studios` ADD CONSTRAINT `fk_studios_plan` FOREIGN KEY (`plan_id`) REFERENCES `commercial_plans` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_add_plan_fk FROM @sql_add_plan_fk;
EXECUTE stmt_add_plan_fk;
DEALLOCATE PREPARE stmt_add_plan_fk;

SET @has_plan_idx := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'studios'
    AND INDEX_NAME = 'idx_studios_plan'
);

SET @sql_add_plan_idx := IF(
  @has_plan_idx = 0,
  'ALTER TABLE `studios` ADD KEY `idx_studios_plan` (`plan_id`)',
  'SELECT 1'
);
PREPARE stmt_add_plan_idx FROM @sql_add_plan_idx;
EXECUTE stmt_add_plan_idx;
DEALLOCATE PREPARE stmt_add_plan_idx;

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
