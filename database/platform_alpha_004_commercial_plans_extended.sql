USE `projetocrm_platform`;

SET @schema := DATABASE();

SET @has_short_description := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'short_description'
);
SET @sql := IF(@has_short_description = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `short_description` VARCHAR(255) NULL AFTER `slug`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_recommended := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'recommended'
);
SET @sql := IF(@has_recommended = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `recommended` TINYINT(1) NOT NULL DEFAULT 0 AFTER `currency_code`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_studio_limit := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'studio_limit'
);
SET @sql := IF(@has_studio_limit = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `studio_limit` INT UNSIGNED NULL AFTER `recommended`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_user_limit := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'user_limit'
);
SET @sql := IF(@has_user_limit = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `user_limit` INT UNSIGNED NULL AFTER `studio_limit`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tattoo_artist_limit := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'tattoo_artist_limit'
);
SET @sql := IF(@has_tattoo_artist_limit = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `tattoo_artist_limit` INT UNSIGNED NULL AFTER `user_limit`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_lead_limit := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'lead_limit'
);
SET @sql := IF(@has_lead_limit = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `lead_limit` INT UNSIGNED NULL AFTER `tattoo_artist_limit`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_whatsapp_session_limit := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'whatsapp_session_limit'
);
SET @sql := IF(@has_whatsapp_session_limit = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `whatsapp_session_limit` INT UNSIGNED NULL AFTER `lead_limit`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_whatsapp := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_whatsapp'
);
SET @sql := IF(@has_allow_whatsapp = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_whatsapp` TINYINT(1) NOT NULL DEFAULT 0 AFTER `whatsapp_session_limit`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_ai := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_ai'
);
SET @sql := IF(@has_allow_ai = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_ai` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_whatsapp`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_data_assistant := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_data_assistant'
);
SET @sql := IF(@has_allow_data_assistant = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_data_assistant` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_ai`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_finance := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_finance'
);
SET @sql := IF(@has_allow_finance = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_finance` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_data_assistant`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_advanced_reports := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_advanced_reports'
);
SET @sql := IF(@has_allow_advanced_reports = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_advanced_reports` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_finance`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_automations := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_automations'
);
SET @sql := IF(@has_allow_automations = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_automations` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_advanced_reports`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_multi_studio := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_multi_studio'
);
SET @sql := IF(@has_allow_multi_studio = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_multi_studio` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_automations`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_external_integrations := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_external_integrations'
);
SET @sql := IF(@has_allow_external_integrations = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_external_integrations` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_multi_studio`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_allow_advanced_customization := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'commercial_plans'
    AND COLUMN_NAME = 'allow_advanced_customization'
);
SET @sql := IF(@has_allow_advanced_customization = 0,
  'ALTER TABLE `commercial_plans` ADD COLUMN `allow_advanced_customization` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_external_integrations`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_plan_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'studios'
    AND COLUMN_NAME = 'plan_id'
);
SET @sql := IF(@has_plan_id = 0,
  'ALTER TABLE `studios` ADD COLUMN `plan_id` INT UNSIGNED NULL AFTER `database_user`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `studios`
  MODIFY COLUMN `plan_name` VARCHAR(80) NOT NULL DEFAULT 'basico';

SET @has_plan_fk := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'studios'
    AND COLUMN_NAME = 'plan_id'
    AND REFERENCED_TABLE_NAME = 'commercial_plans'
);
SET @sql := IF(@has_plan_fk = 0,
  'ALTER TABLE `studios` ADD CONSTRAINT `fk_studios_plan` FOREIGN KEY (`plan_id`) REFERENCES `commercial_plans` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_plan_idx := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'studios'
    AND INDEX_NAME = 'idx_studios_plan'
);
SET @sql := IF(@has_plan_idx = 0,
  'ALTER TABLE `studios` ADD KEY `idx_studios_plan` (`plan_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `commercial_plans`
SET
  `short_description` = COALESCE(`short_description`, `description`),
  `recommended` = CASE
    WHEN `slug` = 'profissional' THEN 1
    ELSE COALESCE(`recommended`, 0)
  END,
  `studio_limit` = CASE `slug`
    WHEN 'basico' THEN 1
    WHEN 'profissional' THEN 1
    WHEN 'avancado' THEN 3
    ELSE COALESCE(`studio_limit`, 1)
  END,
  `user_limit` = CASE `slug`
    WHEN 'basico' THEN 2
    WHEN 'profissional' THEN 5
    WHEN 'avancado' THEN 15
    ELSE COALESCE(`user_limit`, 1)
  END,
  `tattoo_artist_limit` = CASE `slug`
    WHEN 'basico' THEN 1
    WHEN 'profissional' THEN 5
    WHEN 'avancado' THEN 15
    ELSE COALESCE(`tattoo_artist_limit`, 1)
  END,
  `lead_limit` = CASE `slug`
    WHEN 'basico' THEN 500
    WHEN 'profissional' THEN 3000
    WHEN 'avancado' THEN 20000
    ELSE COALESCE(`lead_limit`, 500)
  END,
  `whatsapp_session_limit` = CASE `slug`
    WHEN 'basico' THEN 0
    WHEN 'profissional' THEN 1
    WHEN 'avancado' THEN 3
    ELSE COALESCE(`whatsapp_session_limit`, 0)
  END,
  `allow_whatsapp` = CASE `slug`
    WHEN 'basico' THEN 0
    ELSE 1
  END,
  `allow_ai` = CASE `slug`
    WHEN 'avancado' THEN 1
    ELSE 0
  END,
  `allow_data_assistant` = CASE `slug`
    WHEN 'avancado' THEN 1
    ELSE 0
  END,
  `allow_finance` = 1,
  `allow_advanced_reports` = CASE `slug`
    WHEN 'basico' THEN 0
    ELSE 1
  END,
  `allow_automations` = CASE `slug`
    WHEN 'avancado' THEN 1
    ELSE 0
  END,
  `allow_multi_studio` = CASE `slug`
    WHEN 'avancado' THEN 1
    ELSE 0
  END,
  `allow_external_integrations` = CASE `slug`
    WHEN 'avancado' THEN 1
    ELSE 0
  END,
  `allow_advanced_customization` = CASE `slug`
    WHEN 'avancado' THEN 1
    ELSE 0
  END;

UPDATE `studios`
SET `plan_name` = CASE
  WHEN `plan_name` IN ('alpha', 'basico') THEN 'basico'
  WHEN `plan_name` IN ('profissional', 'pro') THEN 'profissional'
  WHEN `plan_name` IN ('avancado', 'advanced') THEN 'avancado'
  ELSE `plan_name`
END
WHERE `plan_name` IS NOT NULL;

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
