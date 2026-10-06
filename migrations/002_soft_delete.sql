-- =============================================================
-- ClientFlow CRM - Migration 002: soft delete
-- -------------------------------------------------------------
-- For EXISTING installations. A fresh install already has these
-- via the updated database.sql, so this file is a no-op there.
--
-- Run via phpMyAdmin:  XAMPP -> phpMyAdmin -> select clientflow_crm
--                     -> Import -> this file
--
-- Adds deleted_at / deleted_by to clients, leads, deals, tasks and
-- activities. Deletes now stamp the row instead of removing it, so a
-- deleted client no longer takes its deals, tasks and activity
-- history with it. admin/recycle_bin.php can restore anything.
--
-- The ON DELETE CASCADE foreign keys are deliberately left in place:
-- they still matter for the recycle bin's "purge forever" action, which
-- is a genuine hard delete the admin has to confirm.
--
-- Safe to run more than once. No data is dropped or modified.
-- =============================================================

USE `clientflow_crm`;

-- -------------------------------------------------------------
-- Helpers: add a column / index only when missing, so the whole
-- file is idempotent.
-- -------------------------------------------------------------
DROP PROCEDURE IF EXISTS `cf_add_column`;
DROP PROCEDURE IF EXISTS `cf_add_index`;

DELIMITER $$
CREATE PROCEDURE `cf_add_column`(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = 'clientflow_crm'
          AND TABLE_NAME   = tbl
          AND COLUMN_NAME  = col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', def);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE `cf_add_index`(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN col VARCHAR(64))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = 'clientflow_crm'
          AND TABLE_NAME   = tbl
          AND INDEX_NAME   = idx
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD KEY `', idx, '` (`', col, '`)');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$
DELIMITER ;

-- -------------------------------------------------------------
-- 1. deleted_at / deleted_by on the five record tables
-- -------------------------------------------------------------
CALL `cf_add_column`('clients',    'deleted_at', 'DATETIME DEFAULT NULL');
CALL `cf_add_column`('clients',    'deleted_by', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('leads',      'deleted_at', 'DATETIME DEFAULT NULL');
CALL `cf_add_column`('leads',      'deleted_by', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('deals',      'deleted_at', 'DATETIME DEFAULT NULL');
CALL `cf_add_column`('deals',      'deleted_by', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('tasks',      'deleted_at', 'DATETIME DEFAULT NULL');
CALL `cf_add_column`('tasks',      'deleted_by', 'INT UNSIGNED DEFAULT NULL');
CALL `cf_add_column`('activities', 'deleted_at', 'DATETIME DEFAULT NULL');
CALL `cf_add_column`('activities', 'deleted_by', 'INT UNSIGNED DEFAULT NULL');

-- -------------------------------------------------------------
-- 2. Indexes, so "live rows only" stays cheap as the tables grow
-- -------------------------------------------------------------
CALL `cf_add_index`('clients',    'idx_clients_deleted',    'deleted_at');
CALL `cf_add_index`('leads',      'idx_leads_deleted',      'deleted_at');
CALL `cf_add_index`('deals',      'idx_deals_deleted',      'deleted_at');
CALL `cf_add_index`('tasks',      'idx_tasks_deleted',      'deleted_at');
CALL `cf_add_index`('activities', 'idx_activities_deleted', 'deleted_at');

DROP PROCEDURE IF EXISTS `cf_add_column`;
DROP PROCEDURE IF EXISTS `cf_add_index`;

-- -------------------------------------------------------------
-- 3. Nothing to backfill
-- -------------------------------------------------------------
-- deleted_at defaults to NULL and no row has been stamped yet, so every
-- existing row is already live. Stated explicitly so the intent is obvious.
SELECT 'Migration 002 complete - no rows were modified' AS migration_note;
