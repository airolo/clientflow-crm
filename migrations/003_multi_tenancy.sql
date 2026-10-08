-- =============================================================
-- ClientFlow CRM - Migration 003: multi-tenancy
-- -------------------------------------------------------------
-- For EXISTING installations. A fresh import of database.sql already has
-- these tables.
--
-- Adds a tenants table and a tenant_id on users, clients, leads, deals, tasks
-- and activities. Existing data is adopted by a single default tenant so
-- nothing is lost or re-entered.
--
-- Safe to run more than once. Nothing is dropped.
--
-- IMPORTANT: run this BEFORE deploying the Phase 0 application code. The code
-- filters every query on tenant_id and will error without the column.
-- =============================================================

USE `clientflow_crm`;

-- -------------------------------------------------------------
-- 1. The tenants table
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `slug`       VARCHAR(60) NOT NULL,
  `plan`       ENUM('trial','free','pro') NOT NULL DEFAULT 'trial',
  `status`     ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `currency`   VARCHAR(8)  NOT NULL DEFAULT 'GBP',
  `timezone`   VARCHAR(64) NOT NULL DEFAULT 'Europe/London',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenants_slug` (`slug`),
  KEY `idx_tenants_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adopt every existing row under one workspace. Change the name and slug if
-- you like: they are what the sign-in form asks for.
INSERT INTO `tenants` (`id`, `name`, `slug`, `plan`, `status`, `currency`, `timezone`)
VALUES (1, 'My Business', 'my-business', 'pro', 'active', 'GBP', 'Europe/London')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- -------------------------------------------------------------
-- Helpers: add a column / index only when missing
-- -------------------------------------------------------------
DROP PROCEDURE IF EXISTS `cf_add_column`;
DROP PROCEDURE IF EXISTS `cf_add_index`;

DELIMITER $$
CREATE PROCEDURE `cf_add_column`(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = 'clientflow_crm' AND TABLE_NAME = tbl AND COLUMN_NAME = col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', def);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE `cf_add_index`(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN def TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = 'clientflow_crm' AND TABLE_NAME = tbl AND INDEX_NAME = idx
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD KEY `', idx, '` ', def);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$
DELIMITER ;

-- -------------------------------------------------------------
-- 2. tenant_id on every record table, added nullable so it can be
--    backfilled before being made NOT NULL.
-- -------------------------------------------------------------
CALL `cf_add_column`('users',      'tenant_id', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('clients',    'tenant_id', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('leads',      'tenant_id', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('deals',      'tenant_id', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('tasks',      'tenant_id', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('activities', 'tenant_id', 'INT UNSIGNED DEFAULT NULL');

-- Everything that already exists belongs to tenant 1.
UPDATE `users`      SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `clients`    SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `leads`      SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `deals`      SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `tasks`      SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `activities` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;

-- Now make it mandatory, so no future row can omit it.
ALTER TABLE `users`      MODIFY `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `clients`    MODIFY `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `leads`      MODIFY `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `deals`      MODIFY `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `tasks`      MODIFY `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `activities` MODIFY `tenant_id` INT UNSIGNED NOT NULL;

-- -------------------------------------------------------------
-- 3. Indexes: (tenant_id, deleted_at) replaces the deleted_at-only index,
--    since every query now filters on both.
-- -------------------------------------------------------------
CALL `cf_add_index`('users',      'idx_users_tenant',          '(`tenant_id`)');
CALL `cf_add_index`('clients',    'idx_clients_tenant_deleted', '(`tenant_id`, `deleted_at`)');
CALL `cf_add_index`('leads',      'idx_leads_tenant_deleted',   '(`tenant_id`, `deleted_at`)');
CALL `cf_add_index`('deals',      'idx_deals_tenant_deleted',   '(`tenant_id`, `deleted_at`)');
CALL `cf_add_index`('tasks',      'idx_tasks_tenant_deleted',   '(`tenant_id`, `deleted_at`)');
CALL `cf_add_index`('activities', 'idx_activities_tenant_deleted', '(`tenant_id`, `deleted_at`)');

-- The old single-column indexes are redundant now, but dropping an index that
-- may already be renamed would break a re-run, so they are left alone if
-- present. They are harmless.

-- -------------------------------------------------------------
-- 4. Email uniqueness becomes per tenant
-- -------------------------------------------------------------
-- Two businesses may both have an admin@company.com. The global unique index
-- has to go before the composite one can exist. Conditional, so re-running this
-- file does not fail with "Can't DROP INDEX".
SET @has_global = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'clientflow_crm' AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email'
);
SET @s = IF(@has_global > 0,
    'ALTER TABLE `users` DROP INDEX `uq_users_email`',
    'SELECT ''uq_users_email already removed'' AS migration_note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CALL `cf_add_index`('users', 'uq_users_tenant_email', '(`tenant_id`, `email`)');
CALL `cf_add_index`('users', 'idx_users_email',        '(`email`)');

-- -------------------------------------------------------------
-- 5. Foreign keys, so an orphan tenant_id is impossible
-- -------------------------------------------------------------
-- One per record table. Each is added only when missing, using prepared
-- statements rather than a stored procedure: procedures need DELIMITER handling
-- that behaves differently depending on how the file is run, and this has to
-- work identically via phpMyAdmin, the CLI and `source`.
SET @fk_users = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='clientflow_crm' AND CONSTRAINT_NAME='fk_users_tenant');
SET @s = IF(@fk_users > 0, 'SELECT ''fk_users_tenant present'' AS n',
    'ALTER TABLE `users` ADD CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk_clients = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='clientflow_crm' AND CONSTRAINT_NAME='fk_clients_tenant');
SET @s = IF(@fk_clients > 0, 'SELECT ''fk_clients_tenant present'' AS n',
    'ALTER TABLE `clients` ADD CONSTRAINT `fk_clients_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk_leads = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='clientflow_crm' AND CONSTRAINT_NAME='fk_leads_tenant');
SET @s = IF(@fk_leads > 0, 'SELECT ''fk_leads_tenant present'' AS n',
    'ALTER TABLE `leads` ADD CONSTRAINT `fk_leads_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk_deals = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='clientflow_crm' AND CONSTRAINT_NAME='fk_deals_tenant');
SET @s = IF(@fk_deals > 0, 'SELECT ''fk_deals_tenant present'' AS n',
    'ALTER TABLE `deals` ADD CONSTRAINT `fk_deals_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk_tasks = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='clientflow_crm' AND CONSTRAINT_NAME='fk_tasks_tenant');
SET @s = IF(@fk_tasks > 0, 'SELECT ''fk_tasks_tenant present'' AS n',
    'ALTER TABLE `tasks` ADD CONSTRAINT `fk_tasks_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk_activities = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='clientflow_crm' AND CONSTRAINT_NAME='fk_activities_tenant');
SET @s = IF(@fk_activities > 0, 'SELECT ''fk_activities_tenant present'' AS n',
    'ALTER TABLE `activities` ADD CONSTRAINT `fk_activities_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

DROP PROCEDURE IF EXISTS `cf_add_column`;
DROP PROCEDURE IF EXISTS `cf_add_index`;

-- -------------------------------------------------------------
-- 6. Report what happened
-- -------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM `tenants`)                                     AS tenants,
    (SELECT COUNT(*) FROM `users` WHERE `tenant_id` = 1)                 AS users,
    (SELECT COUNT(*) FROM `clients` WHERE `tenant_id` = 1)               AS clients,
    (SELECT COUNT(*) FROM `leads` WHERE `tenant_id` = 1)                 AS leads,
    (SELECT COUNT(*) FROM `deals` WHERE `tenant_id` = 1)                 AS deals,
    (SELECT COUNT(*) FROM `tasks` WHERE `tenant_id` = 1)                 AS tasks,
    (SELECT COUNT(*) FROM `activities` WHERE `tenant_id` = 1)            AS activities;

SELECT 'Migration 003 complete - sign in with workspace "my-business"' AS migration_note;