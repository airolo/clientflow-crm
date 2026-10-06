-- =============================================================
-- ClientFlow CRM - Migration 001: sign-in hardening
-- -------------------------------------------------------------
-- For EXISTING installations. A fresh install already has these
-- via the updated database.sql, so this file is a no-op there.
--
-- Run via phpMyAdmin:  XAMPP -> phpMyAdmin -> select clientflow_crm
--                     -> Import -> this file
--
-- Adds:
--   * users.must_change_password - holds seeded/reset accounts on the
--     change-password screen until their published password is replaced
--   * login_attempts - brute-force throttling and a sign-in audit trail
--
-- Safe to run more than once.
-- =============================================================

USE `clientflow_crm`;

-- -------------------------------------------------------------
-- 1. users.must_change_password
-- -------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'clientflow_crm'
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'must_change_password'
);

SET @sql = IF(@col_exists > 0,
    'SELECT ''users.must_change_password already present'' AS migration_note',
    'ALTER TABLE `users`
       ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
         AFTER `is_active`');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 2. login_attempts
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(150) NOT NULL,
  `ip`           VARCHAR(45) NOT NULL,
  `succeeded`    TINYINT(1) NOT NULL DEFAULT 0,
  `user_agent`   VARCHAR(255) DEFAULT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_email_time` (`email`, `attempted_at`),
  KEY `idx_attempts_ip_time`    (`ip`, `attempted_at`),
  KEY `idx_attempts_time`       (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 3. Flag any account still using a seeded demo password
-- -------------------------------------------------------------
-- These four hashes appear in database.sql, so they are public knowledge.
-- They are forced to change on first sign-in. No account is disabled, and
-- there is no way to skip this: must_change_password is only cleared by
-- auth/change_password.php after the password is actually replaced.
UPDATE `users`
   SET `must_change_password` = 1
 WHERE `password_hash` IN (
    '$2y$10$aS0jDmjDd2ZcvT/kATwwi.V50h5rCQhgWKxaEWQ70AokxrBJkguou',
    '$2y$10$RPo0/LTTWko6dSHJEJslvOeQ21suPGapSouCjlOfm4AEGaA/M1vDW'
 );

SELECT 'Migration 001 complete' AS migration_note;
