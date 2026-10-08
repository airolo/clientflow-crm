-- =============================================================
-- ClientFlow CRM - Migration 005: field-level audit log
-- -------------------------------------------------------------
-- For EXISTING installations. A fresh import of database.sql already has this.
--
-- Records what changed, field by field, and who changed it. Until now the app
-- recorded sign-in attempts and the recycle bin recorded deletions, so "who
-- changed this client's status to inactive" had no answer.
--
-- Append-only: the application never updates or deletes an audit row. That is
-- the property that makes it worth having, and it is a convention rather than
-- something the schema can enforce - see the note at the end of this file.
--
-- Safe to run more than once. Nothing is dropped.
-- =============================================================

USE `clientflow_crm`;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  -- NULL when the person has since been deleted. Set to NULL rather than
  -- cascading, because an audit row that vanishes with the user is worse than
  -- useless.
  `user_id`      INT UNSIGNED DEFAULT NULL,
  -- Copied rather than joined. A trail that cannot be read because the person
  -- left the company is not a trail.
  `user_name`    VARCHAR(100) DEFAULT NULL,
  `action`       ENUM('create','update','delete','restore','purge',
                       'login','login_failed','logout','signup','import',
                       'suspend','activate') NOT NULL,
  `entity_type`  VARCHAR(32) NOT NULL,
  -- Deliberately no foreign key. An audit row has to outlive the record it
  -- describes, including after "Delete forever".
  `entity_id`    INT UNSIGNED DEFAULT NULL,
  -- The record's label at the time, for the same reason.
  `entity_label` VARCHAR(200) DEFAULT NULL,
  -- JSON array of {"field","from","to"} for updates; NULL otherwise. JSON
  -- rather than one row per field: the five record types have different fields,
  -- and a trail that reads top to bottom is worth more here than one you can
  -- run "every change to this column" against in SQL.
  `changes`      TEXT DEFAULT NULL,
  `ip`           VARCHAR(45) DEFAULT NULL,
  `user_agent`   VARCHAR(255) DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Every read is "this workspace's log, newest first".
  KEY `idx_audit_tenant_time`  (`tenant_id`, `created_at`),
  -- "Show me everything that happened to this record."
  KEY `idx_audit_entity`       (`tenant_id`, `entity_type`, `entity_id`),
  -- "Show me what this person did."
  KEY `idx_audit_user_time`    (`tenant_id`, `user_id`, `created_at`),
  -- "Show me only deletions." The filter the recycle bin and the reports page
  -- both want, and the one an auditor asks for first.
  KEY `idx_audit_action_time`  (`tenant_id`, `action`, `created_at`),
  CONSTRAINT `fk_audit_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Note on append-only
-- -------------------------------------------------------------
-- Nothing in the application updates or deletes a row of audit_log, and there
-- is no UI for it. That is deliberate and it is the point of the table.
--
-- It is a convention, not a guarantee. A database user with DELETE or TRUNCATE
-- on this table can still erase it, and so can anyone with filesystem access to
-- a dump. If you need tamper-evidence rather than good intentions, you want
-- shipping audit rows somewhere append-only outside the database - a log
-- pipeline, or periodically copying this table to storage the app cannot write
-- to. Worth knowing before anyone tells you the audit log proves anything.

SELECT COUNT(*) AS audit_rows FROM `audit_log`;

SELECT 'Migration 005 complete' AS migration_note;