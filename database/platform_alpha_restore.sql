USE `projetocrm_platform`;

CREATE TABLE IF NOT EXISTS `commercial_plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(90) NOT NULL,
  `short_description` VARCHAR(255) NULL,
  `description` TEXT NULL,
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
  `allow_finance` TINYINT(1) NOT NULL DEFAULT 1,
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
  (`name`, `slug`, `short_description`, `description`, `monthly_price`, `annual_price`, `currency_code`, `recommended`, `studio_limit`, `user_limit`, `tattoo_artist_limit`, `lead_limit`, `whatsapp_session_limit`, `allow_whatsapp`, `allow_ai`, `allow_data_assistant`, `allow_finance`, `allow_advanced_reports`, `allow_automations`, `allow_multi_studio`, `allow_external_integrations`, `allow_advanced_customization`, `features_text`, `limits_text`, `sort_order`, `is_active`, `created_at`, `updated_at`)
VALUES
  ('Basico', 'basico', 'Para tatuadores solo ou estúdios pequenos começando a organizar atendimento e agenda.', 'Para tatuadores solo ou estúdios pequenos começando a organizar atendimento e agenda.', 79.00, 790.00, 'BRL', 0, 1, 2, 1, 500, 0, 1, 0, 0, 1, 0, 0, 0, 0, 0, 'Cadastro de clientes\nLeads e funil\nAgenda\nFinanceiro simples\nRespostas rápidas\nRelatórios básicos', 'Usuarios: 2\nTatuadores: 1\nClientes/leads: 500\nWhatsApp: limitado', 1, 1, NOW(), NOW()),
  ('Profissional', 'profissional', 'Para estúdios que recebem muitos leads e precisam controlar WhatsApp, agenda, equipe e vendas.', 'Para estúdios que recebem muitos leads e precisam controlar WhatsApp, agenda, equipe e vendas.', 149.00, 1490.00, 'BRL', 1, 1, 5, 5, 3000, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 'Tudo do Básico\nWhatsApp/Baileys\nCentral de atendimento\nRespostas rápidas avançadas\nAgenda com controle de conflitos\nRelatórios gerenciais\nPermissões por usuário\nFollow-up manual/assistido', 'Usuarios: 5\nTatuadores: 5\nClientes/leads: 3000\nWhatsApp: 1 sessão', 2, 1, NOW(), NOW()),
  ('Avancado', 'avancado', 'Para estúdios maiores, redes ou operações que querem automação, IA e relatórios avançados.', 'Para estúdios maiores, redes ou operações que querem automação, IA e relatórios avançados.', 299.00, 2990.00, 'BRL', 1, 3, 15, 15, 20000, 3, 1, 1, 1, 1, 1, 1, 1, 1, 1, 'Tudo do Profissional\nIA para classificação de leads\nAssistente de dados\nSugestão de respostas por IA\nAutomações de follow-up\nRelatórios avançados/BI\nMulti-estúdio\nIntegrações externas/API\nPersonalização avançada do funil', 'Estúdios: 3\nUsuarios: 15\nTatuadores: 15\nClientes/leads: 20000\nWhatsApp: 3 sessões', 3, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
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
