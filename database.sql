-- =============================================================
-- ClientFlow CRM - Database schema and demo data
-- -------------------------------------------------------------
-- Import via phpMyAdmin:  XAMPP -> phpMyAdmin -> Import -> this file
-- Make sure "Create database" / character set is set to utf8mb4
-- =============================================================

CREATE DATABASE IF NOT EXISTS `clientflow_crm`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `clientflow_crm`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `activities`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `deals`;
DROP TABLE IF EXISTS `leads`;
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;


-- =============================================================
-- users  - login accounts and record ownership
-- =============================================================
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  `phone`         VARCHAR(40) DEFAULT NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  -- Forces a password change before any other page is usable. Set by an admin
  -- on reset, and automatically for seeded demo accounts on first sign-in.
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- login_attempts  - brute-force throttling and sign-in audit trail
-- =============================================================
-- Every sign-in attempt is recorded, successful or not, so repeated failures
-- from one address or against one account can be counted and refused.
-- Rows older than the retention window are pruned opportunistically.
CREATE TABLE `login_attempts` (
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


-- =============================================================
-- clients  - existing customers / accounts
-- =============================================================
CREATE TABLE `clients` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_name`    VARCHAR(150) NOT NULL,
  `contact_person`  VARCHAR(120) NOT NULL,
  `email`           VARCHAR(150) DEFAULT NULL,
  `phone`           VARCHAR(40)  DEFAULT NULL,
  `address`         VARCHAR(255) DEFAULT NULL,
  `status`          ENUM('prospect','active','inactive') NOT NULL DEFAULT 'prospect',
  `assigned_to`     INT UNSIGNED DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clients_status`    (`status`),
  KEY `idx_clients_assigned`  (`assigned_to`),
  KEY `idx_clients_company`   (`company_name`),
  CONSTRAINT `fk_clients_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_clients_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- leads  - prospects in the early sales funnel
-- =============================================================
CREATE TABLE `leads` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_name`        VARCHAR(120) NOT NULL,
  `company`          VARCHAR(150) DEFAULT NULL,
  `email`            VARCHAR(150) DEFAULT NULL,
  `phone`            VARCHAR(40)  DEFAULT NULL,
  `lead_source`      ENUM('website','referral','cold_call','email_campaign','social_media','event','other')
                     NOT NULL DEFAULT 'website',
  `status`           ENUM('new','contacted','qualified','proposal','negotiation','won','lost')
                     NOT NULL DEFAULT 'new',
  `estimated_value`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `assigned_to`      INT UNSIGNED DEFAULT NULL,
  `notes`            TEXT DEFAULT NULL,
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leads_status`   (`status`),
  KEY `idx_leads_source`   (`lead_source`),
  KEY `idx_leads_assigned` (`assigned_to`),
  KEY `idx_leads_created`  (`created_at`),
  CONSTRAINT `fk_leads_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_leads_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- deals  - the sales pipeline board (one row per opportunity)
-- =============================================================
CREATE TABLE `deals` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `deal_title`         VARCHAR(180) NOT NULL,
  `client_id`          INT UNSIGNED DEFAULT NULL,
  `lead_id`            INT UNSIGNED DEFAULT NULL,
  `value`              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `stage`              ENUM('new_lead','contacted','proposal','negotiation','won','lost')
                       NOT NULL DEFAULT 'new_lead',
  `expected_close_date` DATE DEFAULT NULL,
  `assigned_to`        INT UNSIGNED DEFAULT NULL,
  `notes`              TEXT DEFAULT NULL,
  `created_by`         INT UNSIGNED DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deals_stage`   (`stage`),
  KEY `idx_deals_client`  (`client_id`),
  KEY `idx_deals_lead`    (`lead_id`),
  KEY `idx_deals_assigned`(`assigned_to`),
  CONSTRAINT `fk_deals_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_deals_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_deals_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_deals_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- tasks  - follow-ups and to-dos, optionally linked to a client or lead
-- =============================================================
CREATE TABLE `tasks` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(180) NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `client_id`     INT UNSIGNED DEFAULT NULL,
  `lead_id`       INT UNSIGNED DEFAULT NULL,
  `due_date`      DATE DEFAULT NULL,
  `priority`      ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `status`        ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `assigned_to`   INT UNSIGNED DEFAULT NULL,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `completed_at`  DATETIME DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_status`   (`status`),
  KEY `idx_tasks_due`      (`due_date`),
  KEY `idx_tasks_assigned` (`assigned_to`),
  KEY `idx_tasks_client`   (`client_id`),
  CONSTRAINT `fk_tasks_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- activities  - interaction history (calls, emails, meetings, notes)
-- =============================================================
CREATE TABLE `activities` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`   INT UNSIGNED DEFAULT NULL,
  `lead_id`     INT UNSIGNED DEFAULT NULL,
  `type`        ENUM('call','email','meeting','note') NOT NULL DEFAULT 'note',
  `title`       VARCHAR(180) NOT NULL,
  `details`     TEXT DEFAULT NULL,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activities_client`  (`client_id`),
  KEY `idx_activities_lead`    (`lead_id`),
  KEY `idx_activities_created` (`created_at`),
  CONSTRAINT `fk_activities_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_activities_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_activities_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- DEMO DATA
-- -------------------------------------------------------------
-- Login details (also shown on the login page):
--   admin@clientflow.test  / admin123
--   sarah@clientflow.test / staff123   (staff)
--   marcus@clientflow.test/ staff123   (staff)
--   priya@clientflow.test / staff123   (staff)
-- The hashes below were produced with PHP password_hash().
-- =============================================================

-- These four accounts are seeded with publicly known passwords, so each is
-- flagged must_change_password = 1. The sign-in flow refuses to let any of
-- them reach the rest of the app until the password has been changed.
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `phone`, `is_active`, `must_change_password`) VALUES
(1, 'Alex Morgan',   'admin@clientflow.test',  '$2y$10$aS0jDmjDd2ZcvT/kATwwi.V50h5rCQhgWKxaEWQ70AokxrBJkguou', 'admin', '+44 20 7946 0101', 1, 1),
(2, 'Sarah Bennett', 'sarah@clientflow.test', '$2y$10$RPo0/LTTWko6dSHJEJslvOeQ21suPGapSouCjlOfm4AEGaA/M1vDW', 'staff', '+44 20 7946 0102', 1, 1),
(3, 'Marcus Reid',   'marcus@clientflow.test','$2y$10$RPo0/LTTWko6dSHJEJslvOeQ21suPGapSouCjlOfm4AEGaA/M1vDW', 'staff', '+44 20 7946 0103', 1, 1),
(4, 'Priya Shah',    'priya@clientflow.test', '$2y$10$RPo0/LTTWko6dSHJEJslvOeQ21suPGapSouCjlOfm4AEGaA/M1vDW', 'staff', '+44 20 7946 0104', 1, 1);

INSERT INTO `clients` (`id`, `company_name`, `contact_person`, `email`, `phone`, `address`, `status`, `assigned_to`, `notes`, `created_by`) VALUES
(1,  'Northwind Logistics',  'Daniel Okafor',   'daniel@northwind-logistics.test',  '+44 161 496 0110', '12 Kingsway, Manchester M2 4WU',        'active',    2, 'Key account. Renews every March - start renewal conversation in January.', 1),
(2,  'Bluepeak Software',    'Hannah Wilson',   'hannah@bluepeak-software.test',    '+44 117 496 0220', '4 Temple Court, Bristol BS1 4TR',        'active',    3, 'Interested in the Analytics add-on. Procurement is slow, be patient.', 1),
(3,  'Harbourline Marine',    'Tomas Ruiz',      'tomas@harbourline-marine.test',     '+44 23 496 0330', '77 Quay Street, Portsmouth PO1 2QS',      'active',    4, 'Prefers email over calls. Quiet periods around tidal season.', 1),
(4,  'Cedar & Co Retail',     'Grace Adeyemi',   'grace@cedarco-retail.test',        '+44 121 496 0440', '220 Bull Street, Birmingham B4 6AF',     'prospect',  2, 'Met at the Birmingham trade event. Comparing three suppliers.', 1),
(5,  'Ironbridge Media',      'Oliver Bennett',  'oliver@ironbridge-media.test',     '+44 29 496 0550', '5 Millennium Plaza, Cardiff CF10 1BF',    'active',    3, 'Signed annual contract last year, strong renewal chance.', 1),
(6,  'Greenfield Grocers',    'Amelia Stone',    'amelia@greenfield-grocers.test',   '+44 113 496 0660', '18 Market Row, Leeds LS2 7JY',          'prospect',  4, 'Wants a pilot for two stores before a full rollout.', 1),
(7,  'Lumen Health Partners', 'Ravi Patel',      'ravi@lumenhealth-partners.test',   '+44 131 496 0770', '3 Charlotte Square, Edinburgh EH2 4DR',    'active',    2, 'Data processing agreement in place. Strict on GDPR wording.', 1),
(8,  'Stonebridge Logistics', 'Felicia Grant',   'felicia@stonebridge-logistics.test','+44 19 496 0880', '61 Tyne Street, Newcastle NE1 4RF',       'inactive',  3, 'Moved to a competitor in August. Keep warm, revisit next quarter.', 1),
(9,  'Vertex Analytics',      'Noah Fischer',    'noah@vertex-analytics.test',       '+44 20 496 0990', '90 Rivington Street, London EC2A 3AY',    'active',    4, 'Growing fast - 40 new users last month. Upsell opportunity.', 1),
(10, 'Halcyon Interiors',     'Isla Cameron',    'isla@halcyon-interiors.test',      '+44 28 496 1000', '14 Donegall Quay, Belfast BT1 3EA',      'prospect',  2, 'Small team, quick decisions, price sensitive.', 1),
(11, 'Atlas Facilities',      'Ethan Cooper',    'ethan@atlas-facilities.test',      '+44 151 496 1110', '9 James Street, Liverpool L1 4BT',       'active',    3, 'Contract review due at the end of the quarter.', 1),
(12, 'Sable Creative Studio', 'Mia Johansson',   'mia@sable-creative.test',         '+44 33 496 1220', '40 Rose Crescent, Glasgow G1 2PW',       'prospect',  4, 'Referred by Ironbridge Media. Design agency, low volume.', 1);

INSERT INTO `leads` (`id`, `lead_name`, `company`, `email`, `phone`, `lead_source`, `status`, `estimated_value`, `assigned_to`, `notes`, `created_by`, `created_at`) VALUES
(1,  'James Whitfield',  'Whitfield Legal',      'james@whitfield-legal.test',      '+44 20 7946 1201', 'referral',       'negotiation', 18500.00, 2, 'Referred by Northwind. Needs a multi-year rate card.', 2, '2026-06-14 09:12:00'),
(2,  'Aisha Rahman',     'Rahman Textiles',      'aisha@rahman-textiles.test',     '+44 141 496 1302', 'website',        'proposal',     12400.00, 3, 'Downloaded the pricing guide twice. Engage with a case study.', 3, '2026-06-22 11:40:00'),
(3,  'Peter Nowak',      'Nowak Freight',        'peter@nowak-freight.test',       '+44 127 496 1403', 'cold_call',      'contacted',     8600.00, 4, 'Reached on the second call. Budget confirmed for Q4.', 4, '2026-07-02 08:30:00'),
(4,  'Chloe Daniels',    'Daniels Vet Clinic',   'chloe@daniels-vet.test',         '+44 165 496 1504', 'social_media',   'new',           3200.00, 2, 'First enquiry via Instagram. Small clinic, single site.', 2, '2026-07-19 15:05:00'),
(5,  'Nathan Brooks',    'Brooks Construction',  'nathan@brooks-construction.test','+44 113 496 1605', 'event',          'qualified',    27500.00, 3, 'Met at the Leeds Expo. Serious buyer, wants a site visit.', 3, '2026-07-28 10:20:00'),
(6,  'Sofia Ricci',      'Ricci Design House',   'sofia@ricci-design.test',        '+44 20 7946 1706', 'email_campaign', 'won',          9800.00,  4, 'Closed on a 12-month subscription. Handover complete.', 4, '2026-08-03 13:55:00'),
(7,  'Liam O Connor',    'OConnor Haulage',      'liam@oconnor-haulage.test',      '+44 151 496 1807', 'referral',       'proposal',     15750.00, 2, 'Referral from Stonebridge. Comparing us to their current supplier.', 2, '2026-08-11 09:45:00'),
(8,  'Yuki Tanaka',      'Tanaka Robotics',      'yuki@tanaka-robotics.test',      '+44 117 496 1908', 'website',        'contacted',    41000.00, 3, 'Enterprise enquiry. Loop in technical team for scoping.', 3, '2026-08-24 16:30:00'),
(9,  'Grace Thompson',   'Thompson & Partners',  'grace@thompson-partners.test',    '+44 131 496 2009', 'other',          'lost',          6400.00, 4, 'Went with an incumbent. Revisit when their contract ends.', 4, '2026-09-01 12:15:00'),
(10, 'Daniel Okonkwo',   'Okonkwo Foods',        'daniel@okonkwo-foods.test',      '+44 121 496 2110', 'social_media',   'new',           7300.00, 2, 'Came through the LinkedIn post. Wants a demo in October.', 2, '2026-09-12 09:00:00'),
(11, 'Emma Svensson',    'Svensson Timber',      'emma@svensson-timber.test',      '+44 29 496 2211', 'event',          'qualified',    11900.00, 3, 'Cardiff event. Solid prospect, needs finance approval internally.', 3, '2026-09-18 14:10:00'),
(12, 'Ryan Mitchell',    'Mitchell Fitness',     'ryan@mitchell-fitness.test',     '+44 33 496 2312', 'cold_call',      'contacted',     5200.00, 4, 'Cold call went well. Gym group, three locations.', 4, '2026-09-25 10:35:00'),
(13, 'Zara Ahmed',       'Ahmed Consulting',     'zara@ahmed-consulting.test',     '+44 20 7946 2413', 'website',        'proposal',     14300.00, 2, 'Proposal sent Monday. Follow up if no response by Friday.', 2, '2026-10-01 11:00:00'),
(14, 'Victor Laurent',   'Laurent Viticulture',  'victor@laurent-viticulture.test','+44 5 496 2514', 'referral',       'new',          22500.00, 3, 'Intro from Greenfield Grocers. French speaking, email in French accepted.', 3, '2026-10-03 08:50:00');

INSERT INTO `deals` (`id`, `deal_title`, `client_id`, `lead_id`, `value`, `stage`, `expected_close_date`, `assigned_to`, `notes`, `created_by`) VALUES
(1,  'Northwind - Fleet Tracking Renewal', 1,  NULL, 24000.00, 'negotiation', '2026-11-14', 2, 'Negotiating a three-year rate. They want a 10% multi-year discount.', 1),
(2,  'Northwind - Warehouse Expansion',    1,  NULL,  8600.00, 'proposal',     '2026-11-30', 2, 'Second phase covering the new depot.', 1),
(3,  'Bluepeak - Analytics Add-on',        2,  NULL, 15200.00, 'negotiation', '2026-10-28', 3, 'Waiting on their CTO to confirm the data residency question.', 1),
(4,  'Ironbridge - Annual Renewal',        5,  NULL, 17800.00, 'proposal',     '2026-11-05', 3, 'Renewal quote sent, no response yet.', 1),
(5,  'Cedar & Co - Pilot Programme',       4,  NULL,  6200.00, 'contacted',    '2026-12-01', 2, 'Pilot across two flagship stores.', 1),
(6,  'Lumen Health - Compliance Upgrade',  7,  NULL, 19900.00, 'proposal',     '2026-12-12', 2, 'Must include the updated GDPR clauses.', 1),
(7,  'Vertex Analytics - Seat Expansion',  9,  NULL,  9400.00, 'won',          '2026-09-20', 4, 'Signed for 60 additional seats.', 1),
(8,  'Harbourline - Onboarding Package',   3,  NULL, 12800.00, 'won',          '2026-09-08', 4, 'Completed onboarding, first invoice settled.', 1),
(9,  'Atlas Facilities - Multi-Site Rollout', 11, NULL, 32500.00, 'negotiation', '2026-11-20', 3, 'Biggest open opportunity. Legal reviewing the SLA.', 1),
(10, 'Stonebridge - Win-back',             8,  NULL,  7400.00, 'new_lead',     '2027-01-15', 3, 'Their contract ends in January. Early contact made.', 1),
(11, 'Sable Creative - Starter Package',   12, 12,   4800.00, 'new_lead',     '2026-12-20', 4, 'Converted from the Sable referral.', 1),
(12, 'Greenfield Grocers - Store Pilot',   6,  6,    5600.00, 'contacted',    '2026-11-08', 4, 'Pilot before full rollout decision.', 1);

INSERT INTO `tasks` (`id`, `title`, `description`, `client_id`, `lead_id`, `due_date`, `priority`, `status`, `assigned_to`, `created_by`, `completed_at`) VALUES
(1,  'Send renewal quote to Northwind',      'Three-year pricing with the 10% discount approved by finance.', 1,  NULL, '2026-10-07', 'high',     'pending',     2, 1, NULL),
(2,  'Chase Bluepeak CTO on data residency', 'Email and follow up with a call if no answer by Thursday.',      2,  NULL, '2026-10-06', 'high',     'in_progress', 3, 1, NULL),
(3,  'Book site visit with Brooks Construction', 'Half day visit to the Leeds site to scope the rollout.',   NULL, 5,  '2026-10-09', 'medium',   'pending',     3, 1, NULL),
(4,  'Prepare demo for Okonkwo Foods',       'Focus on the inventory module, they asked about stock alerts.',  NULL, 10, '2026-10-08', 'medium',   'pending',     2, 1, NULL),
(5,  'Send contract to Lumen Health',        'Include the updated GDPR clauses agreed with legal.',          7,  NULL, '2026-10-03', 'high',     'completed',   2, 1, '2026-10-02 16:40:00'),
(6,  'Follow up on Ironbridge renewal',      'No response to the quote sent last week. Call the mobile number.', 5, NULL, '2026-10-06', 'medium',   'pending',     3, 1, NULL),
(7,  'Schedule technical scoping with Tanaka Robotics', 'Needs a call with their engineering lead and our architect.', NULL, 8, '2026-10-13', 'high', 'pending', 3, 1, NULL),
(8,  'Prepare Q4 forecast for the board',    'Roll up open pipeline value and weighted forecast.',            NULL, NULL, '2026-10-10', 'medium',  'pending',     1, 1, NULL),
(9,  'Thank Whitfield for the referral',    'Small gesture, they introduced Northwind and Whitfield Legal.',  1,  1,  '2026-06-16', 'low',      'completed',   2, 1, '2026-06-16 10:00:00'),
(10, 'Update Atlas Facilities contact details','New procurement contact joined, update the client record.',       11, NULL, '2026-10-05', 'low',      'completed',   3, 1, '2026-10-04 09:15:00'),
(11, 'Draft proposal for Laurent Viticulture','French first draft, value around 22,500.',                         NULL, 14, '2026-10-12', 'medium',   'pending',     3, 1, NULL),
(12, 'Review Mitchell Fitness contract terms','Check the three-location clause before sending back.',              NULL, 12, '2026-10-07', 'low',       'pending',     4, 1, NULL);

INSERT INTO `activities` (`id`, `client_id`, `lead_id`, `type`, `title`, `details`, `created_by`, `created_at`) VALUES
(1,  1,  NULL, 'call',    'Renewal pricing call',                 'Reviewed three-year rates. Daniel wants a 10% discount in exchange for a 24-month term. Escalated to finance.', 2, '2026-10-02 10:15:00'),
(2,  2,  NULL, 'email',   'Sent analytics proposal',              'Proposal PDF and the security whitepaper attached. Asked for a decision by the 28th.', 3, '2026-10-01 14:40:00'),
(3,  3,  NULL, 'meeting', 'Onboarding review',                    'Walked Tomas through the go-live plan. Everything agreed, invoice already settled.', 4, '2026-09-08 16:00:00'),
(4,  5,  NULL, 'email',   'Renewal quote sent',                   'Sent the annual renewal quote. No reply yet, will call the mobile number.', 3, '2026-09-29 09:30:00'),
(5,  7,  NULL, 'meeting', 'GDPR clause walkthrough',              'Went through the updated data processing clauses with Ravi. Two small edits requested.', 2, '2026-09-26 11:20:00'),
(6,  9,  NULL, 'call',    'Seat expansion signed',                'Confirmed the additional 60 seats. Order form signed, deal marked won.', 4, '2026-09-20 11:05:00'),
(7,  11, NULL, 'email',   'Multi-site rollout terms sent',        'Sent the SLA and the multi-site pricing. Legal team is reviewing.', 3, '2026-09-18 13:45:00'),
(8,  4,  NULL, 'call',    'Pilot scope discussion',               'Grace confirmed two stores for the pilot. Budget sign-off needed from the owner.', 2, '2026-09-17 15:30:00'),
(9,  6,  NULL, 'note',    'Pilot interest confirmed',             'Amelia confirmed the two-store pilot. Decision expected after their review.', 4, '2026-09-15 10:10:00'),
(10, NULL, 1,  'email',   'Rate card sent to Whitfield Legal',    'Sent the multi-year rate card. James is comparing with one other supplier.', 2, '2026-10-03 09:25:00'),
(11, NULL, 3,  'call',    'Second cold call successful',          'Peter confirmed a Q4 budget. Sending the case study next.', 4, '2026-10-02 09:05:00'),
(12, NULL, 5,  'meeting', 'Leeds Expo stand visit',               'Good conversation. Nathan wants a site visit before committing.', 3, '2026-07-28 11:30:00'),
(13, NULL, 8,  'email',   'Technical scoping request',            'Asked Tanaka Robotics for their integration requirements and volumes.', 3, '2026-08-25 08:55:00'),
(14, NULL, 13, 'email',   'Proposal delivered to Ahmed Consulting','Proposal sent. Following up on Friday if there is no reply.', 2, '2026-10-01 11:20:00'),
(15, 8,  NULL, 'note',    'Lost to competitor - win-back plan',    'Felicia confirmed they moved provider. Their contract ends in January so we revisit then.', 3, '2026-08-15 12:00:00'),
(16, 1,  NULL, 'note',    'Account review notes updated',          'Northwind is our longest standing account. Keep response times under four hours.', 1, '2026-08-10 09:00:00'),
(17, 12, NULL, 'call',    'Introductory call with Sable',         'Mia asked about a starter package for a three person studio.', 4, '2026-09-10 14:00:00'),
(18, 10, NULL, 'email',   'Sent portfolio examples',               'Sent three relevant project examples to Isla.', 2, '2026-09-08 10:30:00'),
(19, NULL, 9,  'note',    'Closed as lost',                        'Went with the incumbent. Marked lost with a note to revisit at contract end.', 4, '2026-09-01 12:20:00'),
(20, 2,  NULL, 'call',    'Technical questions answered',          'Answered questions about the reporting module. Hannah will check internally.', 3, '2026-09-05 15:15:00'),
(21, NULL, 11, 'call',    'Qualification call with Svensson',     'Emma confirmed budget is available but needs finance sign-off.', 3, '2026-09-18 14:15:00'),
(22, 7,  NULL, 'email',   'Contract sent to Lumen Health',         'Sent the updated contract. Task completed and marked done.', 2, '2026-10-02 16:35:00'),
(23, NULL, 14, 'email',   'Acknowledgement received from Laurent', 'Victor replied in French confirming receipt. Drafting a proposal next week.', 3, '2026-10-03 09:00:00'),
(24, 5,  NULL, 'meeting', 'Quarterly business review',            'Reviewed usage with Oliver. Very happy, strong renewal likelihood.', 3, '2026-09-22 11:00:00'),
(25, 9,  NULL, 'email',   'Invoice sent for seat expansion',       'Invoice raised for the additional seats. Payment terms 30 days.', 4, '2026-09-20 11:30:00'),
(26, NULL, 4,  'email',   'Welcome pack sent to Daniels Vet',     'Sent the welcome pack and the pricing summary.', 2, '2026-07-20 10:00:00'),
(27, 3,  NULL, 'note',    'Preferred contact method confirmed',     'Tomas prefers email. Do not call unless urgent.', 4, '2026-09-08 16:10:00'),
(28, NULL, 7,  'call',    'Pricing discussion with OConnor',       'Liam walked through their current supplier contract. We are competitive.', 2, '2026-09-25 13:30:00'),
(29, 4,  NULL, 'email',   'Pilot proposal sent to Cedar & Co',     'Sent the pilot proposal with the two store breakdown.', 2, '2026-09-18 09:45:00'),
(30, 6,  NULL, 'call',    'Pilot rollout planning',                'Discussed the store pilot with Amelia. Rollout expected in November.', 4, '2026-09-15 10:20:00');