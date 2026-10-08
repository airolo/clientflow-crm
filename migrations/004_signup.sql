-- =============================================================
-- ClientFlow CRM - Migration 004: self-service signup
-- -------------------------------------------------------------
-- For EXISTING installations. A fresh import of database.sql already has
-- these.
--
-- Adds what a public "create your workspace" form needs:
--   - signup_attempts, so that unauthenticated endpoint can be rate limited
--   - tenants.onboarded_at, so a first-run welcome screen can be shown once and
--     then never again
--
-- Safe to run more than once. Nothing is dropped.
--
-- IMPORTANT: run this BEFORE deploying the signup page. It will not load without
-- these two changes.
-- =============================================================

USE `clientflow_crm`;

DROP PROCEDURE IF EXISTS `cf_add_column`;

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
DELIMITER ;

-- -------------------------------------------------------------
-- 1. Signup throttling
-- -------------------------------------------------------------
-- Signup is the first endpoint in the app reachable with no session, so it is
-- the one an attacker will aim at: to fill the database with junk workspaces,
-- or to grind through password guesses. Both need a record to count against.
--
-- No tenant_id, deliberately: failures are recorded *before* a workspace
-- exists, so there is nothing to reference and a bogus one must not be
-- insertable. Same reasoning as login_attempts.
--
-- A row is written for every attempt, successful or not. The successful rows
-- are what make a limit of "3 signups per email per hour" possible, and they
-- double as a record of which addresses have claimed a workspace.
CREATE TABLE IF NOT EXISTS `signup_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`           VARCHAR(45) NOT NULL,
  `email`        VARCHAR(150) NOT NULL,
  `succeeded`    TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup_email_time` (`email`, `attempted_at`),
  KEY `idx_signup_ip_time`    (`ip`, `attempted_at`),
  KEY `idx_signup_time`       (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 2. First-run flag
-- -------------------------------------------------------------
-- NULL means the owner has not finished onboarding. Set once they reach the end
-- of it, or dismiss it. In the database rather than the session so the welcome
-- screen does not reappear on a different device.
--
-- Added as nullable with no default, so existing rows are unaffected and stay
-- NULL - which is right: an existing install has already been set up.
CALL `cf_add_column`('tenants', 'onboarded_at', 'TIMESTAMP NULL DEFAULT NULL');

DROP PROCEDURE IF EXISTS `cf_add_column`;

-- -------------------------------------------------------------
-- 3. Report
-- -------------------------------------------------------------
SELECT COUNT(*) AS workspaces, SUM(onboarded_at IS NULL) AS awaiting_onboarding
  FROM `tenants`;

SELECT 'Migration 004 complete' AS migration_note;