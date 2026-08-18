-- ============================================================
-- BlotterCast — MySQL schema
-- Run this in phpMyAdmin, or: mysql -u root -p < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS blottercast CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE blottercast;

-- ---------- Reference: barangay zones ----------
CREATE TABLE IF NOT EXISTS zones (
  zone_id   VARCHAR(10)   PRIMARY KEY,   -- 'Zone 1' .. 'Zone 8'
  label     VARCHAR(100)  NOT NULL,
  lat       DECIMAL(9,6)  NOT NULL,
  lng       DECIMAL(9,6)  NOT NULL,
  weight    DECIMAL(4,3)  NOT NULL       -- relative incident-share used only by the seeder
) ENGINE=InnoDB;

-- ---------- Users (simple auth for the prototype) ----------
CREATE TABLE IF NOT EXISTS users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(50)  NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,      -- bcrypt hash
  full_name   VARCHAR(150) NOT NULL,
  email       VARCHAR(150) NULL,
  contact_no  VARCHAR(30)  NULL,
  role        ENUM('System Admin','Barangay Captain','Desk Officer','Data Encoder') NOT NULL DEFAULT 'Desk Officer',
  status      ENUM('Active','Suspended','Inactive') NOT NULL DEFAULT 'Active',
  signature_path VARCHAR(255) NULL,       -- uploaded e-signature image (Barangay Captain), used on printed certificates
  last_login  DATETIME NULL,
  failed_attempts INT NOT NULL DEFAULT 0,        -- consecutive failed logins; drives Security > Account Lockout
  locked_until    DATETIME NULL,                  -- set once failed_attempts hits the configured max; login blocked until this passes
  password_changed_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP, -- drives Security > Password Expiry (days)
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Audit log (system activity trail) ----------
CREATE TABLE IF NOT EXISTS audit_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(50)  NOT NULL,
  action      VARCHAR(50)  NOT NULL,       -- Login, Created, Updated, Deleted, Exported, Imported
  module      VARCHAR(50)  NOT NULL,       -- System, Blotter, Incident, Settlement, Reports, Data
  details     VARCHAR(255) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ---------- Dashboard notifications (system-generated alerts) ----------
-- One row per event; read/unread is tracked per-user in a separate table
-- rather than a single flag, since every account should see its own
-- unread state independent of what other accounts have already seen.
CREATE TABLE IF NOT EXISTS notifications (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  type         ENUM('new_incident','settlement_overdue','high_risk_zone') NOT NULL,
  title        VARCHAR(150) NOT NULL,
  body         VARCHAR(255) NOT NULL,
  severity     ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  link         VARCHAR(100) NULL,          -- page to open when clicked, e.g. incident.html
  ref_table    VARCHAR(50)  NULL,          -- e.g. 'incidents', 'settlements' — what this alert is about
  ref_id       INT NULL,                   -- the row id in ref_table, for de-duplication
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at),
  INDEX idx_dedup (type, ref_table, ref_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_reads (
  user_id          INT NOT NULL,
  notification_id  INT NOT NULL,
  read_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, notification_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Incident reports (the core ML training data) ----------
CREATE TABLE IF NOT EXISTS incidents (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  report_no      VARCHAR(30)   NOT NULL UNIQUE,
  incident_date  DATE          NOT NULL,          -- ISO date, used for time-series features
  time_reported  TIME          NOT NULL,
  hour           TINYINT       NOT NULL,           -- 0-23, denormalized for fast ML queries
  zone_id        VARCHAR(10)   NOT NULL,
  location       VARCHAR(255),
  lat            DECIMAL(9,6),
  lng            DECIMAL(9,6),
  category       VARCHAR(60)   NOT NULL,
  description    TEXT,
  reporter       VARCHAR(150),
  officer        VARCHAR(100),
  priority       ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
  status         ENUM('Under Investigation','Referred','Resolved','Closed') NOT NULL DEFAULT 'Under Investigation',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (zone_id) REFERENCES zones(zone_id),
  INDEX idx_date (incident_date),
  INDEX idx_zone_date (zone_id, incident_date),
  INDEX idx_category (category)
) ENGINE=InnoDB;

-- ---------- Blotter book (Katarungang Pambarangay docket) ----------
CREATE TABLE IF NOT EXISTS blotter_records (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  docket_no         VARCHAR(30)  NOT NULL UNIQUE,
  date_filed        DATE         NOT NULL,
  complainant       VARCHAR(150) NOT NULL,
  complainant_id    INT NULL,        -- linked census_records.id when picked from the search bar; NULL for pre-existing free-text entries
  complainant_addr  VARCHAR(255),
  respondent        VARCHAR(150) NOT NULL,
  respondent_id     INT NULL,        -- linked census_records.id when the respondent is a resident; NULL if outside party or pre-existing free-text
  respondent_addr   VARCHAR(255),
  nature            VARCHAR(100),
  case_type         ENUM('CRIM','CIVIL') NOT NULL DEFAULT 'CRIM',
  status            ENUM('Ongoing','Pending','Resolved') NOT NULL DEFAULT 'Pending',
  zone_id           VARCHAR(10),
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (zone_id) REFERENCES zones(zone_id)
  -- complainant_id/respondent_id -> census_records(id) added further below,
  -- once census_records has been created (it's defined later in this file).
) ENGINE=InnoDB;

-- ---------- Settlement monitor ----------
CREATE TABLE IF NOT EXISTS settlements (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  blotter_id          INT NOT NULL,        -- a settlement must belong to an existing blotter case
  case_no             VARCHAR(30)  NOT NULL UNIQUE,
  case_title          VARCHAR(200),
  complaint_title     VARCHAR(150),
  nature              ENUM('Criminal','Civil') NOT NULL DEFAULT 'Civil',
  date_filed          DATE,
  date_confrontation  DATE,
  action_taken        VARCHAR(100),
  date_settlement     DATE NULL,
  date_execution      DATE NULL,
  main_point          TEXT,
  status              ENUM('Pending','Complied','Not Complied') NOT NULL DEFAULT 'Pending',
  remarks             VARCHAR(255) NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (blotter_id) REFERENCES blotter_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Cached ML results (populated by the Python service) ----------
CREATE TABLE IF NOT EXISTS ml_runs (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  trained_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  record_count   INT NOT NULL,
  -- Three separate task-specific model pairs (thesis SOP 3):
  --   occurrence: Logistic Regression + Random Forest
  --   type:       Decision Tree + Gradient Boosting
  --   hotspot:    Random Forest + Gradient Boosting
  active_occurrence_model VARCHAR(40) NOT NULL DEFAULT 'random_forest',
  active_type_model       VARCHAR(40) NOT NULL DEFAULT 'gradient_boosting',
  active_hotspot_model    VARCHAR(40) NOT NULL DEFAULT 'random_forest',
  occurrence_metrics_json LONGTEXT NOT NULL,  -- {logistic_regression:{...}, random_forest:{...}}
  type_metrics_json       LONGTEXT NOT NULL,  -- {decision_tree:{...}, gradient_boosting:{...}}
  hotspot_metrics_json    LONGTEXT NOT NULL,  -- {random_forest:{...}, gradient_boosting:{...}}
  hotspots_json  LONGTEXT NOT NULL   -- per-zone probability, expected count, top category, peak window, trend
) ENGINE=InnoDB;

-- ---------- System settings (key-value store for all Settings tabs) ----------
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key    VARCHAR(100) PRIMARY KEY,
  setting_value  TEXT NOT NULL,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Census records ----------
CREATE TABLE IF NOT EXISTS census_records (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  resident_no    VARCHAR(20)  NOT NULL UNIQUE,
  last_name      VARCHAR(80)  NOT NULL,
  first_name     VARCHAR(80)  NOT NULL,
  middle_name    VARCHAR(80)  NULL,
  date_of_birth  DATE NULL,
  sex            ENUM('Male','Female') NOT NULL DEFAULT 'Male',
  civil_status   ENUM('Single','Married','Widowed','Separated') NOT NULL DEFAULT 'Single',
  nationality    VARCHAR(60)  NOT NULL DEFAULT 'Filipino',
  zone_id        VARCHAR(10)  NULL,
  address        VARCHAR(255) NULL,
  household_no   VARCHAR(30)  NULL,
  contact_no     VARCHAR(30)  NULL,
  voter_status   ENUM('Registered Voter','Not Registered','Deactivated') NOT NULL DEFAULT 'Not Registered',
  occupation     VARCHAR(100) NULL,
  status         ENUM('Active','Deceased','Transferred') NOT NULL DEFAULT 'Active',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (zone_id) REFERENCES zones(zone_id)
) ENGINE=InnoDB;

-- Now that census_records exists, link blotter_records' complainant_id and
-- respondent_id to it (see the note on blotter_records above for why this
-- is a separate ALTER rather than inline — blotter_records is created
-- earlier in this file, before census_records exists yet). Wrapped in a
-- procedure so re-running schema.sql against a database that already has
-- these constraints doesn't error out and abort the rest of the script —
-- a plain ALTER TABLE ADD CONSTRAINT has no "IF NOT EXISTS" form, unlike
-- ADD COLUMN below.
--
-- DROP PROCEDURE IF EXISTS runs first because if a previous import of this
-- same file was ever interrupted (connection drop, syntax error earlier in
-- the file, etc.) between CREATE PROCEDURE and the DROP PROCEDURE at the
-- end of this block, the procedure is left behind — and a plain
-- CREATE PROCEDURE with no guard then fails on every subsequent import
-- with "PROCEDURE ... already exists", aborting the rest of the script
-- (including every CREATE TABLE after this point) before it ever runs.
DROP PROCEDURE IF EXISTS _bc_add_blotter_fks_if_missing;
DELIMITER $$
CREATE PROCEDURE _bc_add_blotter_fks_if_missing()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_blotter_complainant'
  ) THEN
    ALTER TABLE blotter_records ADD CONSTRAINT fk_blotter_complainant FOREIGN KEY (complainant_id) REFERENCES census_records(id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_blotter_respondent'
  ) THEN
    ALTER TABLE blotter_records ADD CONSTRAINT fk_blotter_respondent FOREIGN KEY (respondent_id) REFERENCES census_records(id) ON DELETE SET NULL;
  END IF;
END$$
DELIMITER ;
CALL _bc_add_blotter_fks_if_missing();
DROP PROCEDURE IF EXISTS _bc_add_blotter_fks_if_missing;

-- ---------- Barangay clearance certificates ----------
CREATE TABLE IF NOT EXISTS barangay_clearance (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  resident_id   INT NOT NULL,        -- a clearance must be issued to an existing census resident
  ctrl_no       VARCHAR(30)  NOT NULL UNIQUE,
  full_name     VARCHAR(150) NOT NULL,
  age           TINYINT UNSIGNED NULL,
  civil_status  ENUM('Single','Married','Widowed','Separated') NOT NULL DEFAULT 'Single',
  address       VARCHAR(255) NULL,
  voter_status  ENUM('Registered Voter','Not Registered','Deactivated') NOT NULL DEFAULT 'Not Registered',
  purpose       VARCHAR(150) NULL,
  or_no         VARCHAR(30)  NULL,
  fee           DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  date_issued   DATE NOT NULL,
  issued_by     VARCHAR(100) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES census_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Certificate of Residency ----------
CREATE TABLE IF NOT EXISTS barangay_residency (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  resident_id     INT NOT NULL,        -- a certificate must be issued to an existing census resident
  ctrl_no         VARCHAR(30)  NOT NULL UNIQUE,
  full_name       VARCHAR(150) NOT NULL,
  age             TINYINT UNSIGNED NULL,
  civil_status    ENUM('Single','Married','Widowed','Separated') NOT NULL DEFAULT 'Single',
  address         VARCHAR(255) NULL,
  years_residency SMALLINT UNSIGNED NULL,  -- the number entered — paired with duration_unit below to know if it's years or months
  duration_unit   ENUM('years','months') NOT NULL DEFAULT 'years',  -- lets a resident who hasn't hit their first year yet be certified in months (2-11) instead of years
  purpose         VARCHAR(150) NULL,
  or_no           VARCHAR(30)  NULL,
  fee             DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  date_issued     DATE NOT NULL,
  issued_by       VARCHAR(100) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES census_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Certificate of Non-Residency ----------
-- Certifies that a person recorded in Census does NOT qualify as a
-- resident of this barangay (e.g. insufficient length of stay, primary
-- residence elsewhere) — the applicant still has to be picked from
-- Census like every other document type here, but the certifying
-- language states non-residency rather than residency.
CREATE TABLE IF NOT EXISTS barangay_non_residency (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  resident_id       INT NOT NULL,
  ctrl_no           VARCHAR(30)  NOT NULL UNIQUE,
  full_name         VARCHAR(150) NOT NULL,
  previous_address  VARCHAR(255) NULL,   -- their former home address in this barangay, per the actual certificate wording
  purpose           VARCHAR(150) NULL,
  or_no             VARCHAR(30)  NULL,
  fee               DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  date_issued       DATE NOT NULL,
  issued_by         VARCHAR(100) NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES census_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS indigency_certificates (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  resident_id   INT NOT NULL,        -- a certificate must be issued to an existing census resident
  ctrl_no       VARCHAR(30)  NOT NULL UNIQUE,
  full_name     VARCHAR(150) NOT NULL,
  age           TINYINT UNSIGNED NULL,
  civil_status  ENUM('Single','Married','Widowed','Separated') NOT NULL DEFAULT 'Single',
  address       VARCHAR(255) NULL,
  purpose       VARCHAR(150) NULL,
  date_issued   DATE NOT NULL,
  issued_by     VARCHAR(100) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES census_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Backup history (real mysqldump runs) ----------
CREATE TABLE IF NOT EXISTS backups (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  file_name   VARCHAR(255) NOT NULL,
  size_bytes  BIGINT NOT NULL DEFAULT 0,
  status      ENUM('Success','Failed') NOT NULL DEFAULT 'Success',
  created_by  VARCHAR(100) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Generated report log ----------
CREATE TABLE IF NOT EXISTS generated_reports (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  report_type   VARCHAR(100) NOT NULL,
  generated_by  VARCHAR(100) NOT NULL,
  period_from   DATE,
  period_to     DATE,
  format        ENUM('PDF','Excel','CSV') NOT NULL DEFAULT 'PDF',
  file_path     VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Reference data ----------
INSERT INTO zones (zone_id, label, lat, lng, weight) VALUES
 ('Zone 1','Zone 1 – Barangay Hall Area',   14.8836, 120.9655, 0.20),
 ('Zone 2','Zone 2 – South Central',        14.8824, 120.9648, 0.11),
 ('Zone 3','Zone 3 – Market Area',          14.8845, 120.9663, 0.18),
 ('Zone 4','Zone 4 – Southeast Residential',14.8818, 120.9660, 0.06),
 ('Zone 5','Zone 5 – Northern Cluster',     14.8852, 120.9650, 0.10),
 ('Zone 6','Zone 6 – West Interior',        14.8830, 120.9636, 0.05),
 ('Zone 7','Zone 7 – Basketball Court Area',14.8842, 120.9641, 0.16),
 ('Zone 8','Zone 8 – East Road Junction',   14.8826, 120.9670, 0.14)
ON DUPLICATE KEY UPDATE label=VALUES(label);

-- Default login: admin / admin123 (bcrypt hash generated by PHP password_hash)
-- The PHP setup script (api/seed.php) creates this account automatically.

-- ---------- Default system settings ----------
INSERT INTO system_settings (setting_key, setting_value) VALUES
 ('barangay_name', 'Barangay Mapulang Lupa'),
 ('municipality', 'Pandi, Bulacan'),
 ('region', 'Region III – Central Luzon'),
 ('captain_name', 'Kapitan Jose Reyes'),
 ('contact_no', '0917-000-0000'),
 ('email', 'mapulanglupa@pandi.gov.ph'),
 ('date_format', 'MM/DD/YYYY'),
 ('time_format', '12-Hour (AM/PM)'),
 ('records_per_page', '6'),
 ('default_language', 'English'),
 ('risk_threshold', '75'),
 ('spike_threshold', '5'),
 ('notif_inapp', '1'),
 ('notif_retrain', '1'),
 ('two_factor_auth', '0'),
 ('lockout_enabled', '1'),
 ('session_timeout', '30'),
 ('max_failed_logins', '5'),
 ('min_password_length', '8'),
 ('password_expiry_days', '90'),
 ('audit_trail', '1'),
 ('data_subject_rights', '1'),
 ('backup_frequency', 'Daily'),
 ('backup_time', '02:00'),
 ('backup_destination', 'Local Storage Device'),
 ('retain_backups_days', '30'),
 ('rto_hours', '4 hours'),
 ('rpo_hours', '24 hours'),
 ('ml_occurrence_model', 'random_forest'),
 ('ml_type_model', 'gradient_boosting'),
 ('ml_hotspot_model', 'random_forest')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ---------- Safe migrations for pre-existing databases ----------
-- schema.sql uses CREATE TABLE IF NOT EXISTS, so re-running it against a
-- database that already has these tables (from before a column below was
-- added) does NOT add the new column automatically — the table is simply
-- left as-is. Each statement here brings an existing installation up to
-- date without touching any data already in it. Safe to run repeatedly.
ALTER TABLE census_records ADD COLUMN IF NOT EXISTS nationality VARCHAR(60) NOT NULL DEFAULT 'Filipino';
ALTER TABLE blotter_records ADD COLUMN IF NOT EXISTS complainant_id INT NULL;
ALTER TABLE blotter_records ADD COLUMN IF NOT EXISTS respondent_id INT NULL;
ALTER TABLE barangay_non_residency ADD COLUMN IF NOT EXISTS previous_address VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_attempts INT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE ml_runs ADD COLUMN IF NOT EXISTS active_occurrence_model VARCHAR(40) NOT NULL DEFAULT 'random_forest';
ALTER TABLE ml_runs ADD COLUMN IF NOT EXISTS active_type_model VARCHAR(40) NOT NULL DEFAULT 'gradient_boosting';
ALTER TABLE ml_runs ADD COLUMN IF NOT EXISTS active_hotspot_model VARCHAR(40) NOT NULL DEFAULT 'random_forest';
ALTER TABLE ml_runs ADD COLUMN IF NOT EXISTS occurrence_metrics_json LONGTEXT NULL;
ALTER TABLE ml_runs ADD COLUMN IF NOT EXISTS type_metrics_json LONGTEXT NULL;
ALTER TABLE ml_runs ADD COLUMN IF NOT EXISTS hotspot_metrics_json LONGTEXT NULL;

-- ---------- App DB user for the Python ML service (connects over TCP) ----------
-- XAMPP's root account is usually socket-auth only, which the Python
-- mysql client can't use, so the ML service connects as this user instead.
CREATE USER IF NOT EXISTS 'blottercast'@'localhost' IDENTIFIED BY 'blottercast';
CREATE USER IF NOT EXISTS 'blottercast'@'127.0.0.1' IDENTIFIED BY 'blottercast';
GRANT ALL PRIVILEGES ON blottercast.* TO 'blottercast'@'localhost';
GRANT ALL PRIVILEGES ON blottercast.* TO 'blottercast'@'127.0.0.1';
FLUSH PRIVILEGES;
