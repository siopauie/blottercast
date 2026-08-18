-- ============================================================
-- BlotterCast — Supabase (PostgreSQL) Schema
-- Copy and run this script in your Supabase SQL Editor:
-- https://supabase.com/dashboard/project/_/sql/new
-- ============================================================

-- ---------- Reference: barangay zones ----------
CREATE TABLE IF NOT EXISTS zones (
  zone_id   VARCHAR(10)   PRIMARY KEY,
  label     VARCHAR(100)  NOT NULL,
  lat       NUMERIC(9,6)  NOT NULL,
  lng       NUMERIC(9,6)  NOT NULL,
  weight    NUMERIC(4,3)  NOT NULL
);

-- ---------- Users ----------
CREATE TABLE IF NOT EXISTS users (
  id                  SERIAL PRIMARY KEY,
  username            VARCHAR(50)  NOT NULL UNIQUE,
  password            VARCHAR(255) NOT NULL,
  full_name           VARCHAR(150) NOT NULL,
  email               VARCHAR(150),
  contact_no          VARCHAR(30),
  role                VARCHAR(30)  NOT NULL DEFAULT 'Desk Officer',
  status              VARCHAR(20)  NOT NULL DEFAULT 'Active',
  signature_path      VARCHAR(255),
  last_login          TIMESTAMP,
  failed_attempts     INT NOT NULL DEFAULT 0,
  locked_until        TIMESTAMP,
  password_changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------- Audit log ----------
CREATE TABLE IF NOT EXISTS audit_logs (
  id          SERIAL PRIMARY KEY,
  username    VARCHAR(50)  NOT NULL,
  action      VARCHAR(50)  NOT NULL,
  module      VARCHAR(50)  NOT NULL,
  details     VARCHAR(255),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs (created_at);

-- ---------- Dashboard notifications ----------
CREATE TABLE IF NOT EXISTS notifications (
  id           SERIAL PRIMARY KEY,
  type         VARCHAR(30) NOT NULL,
  title        VARCHAR(150) NOT NULL,
  body         VARCHAR(255) NOT NULL,
  severity     VARCHAR(20) NOT NULL DEFAULT 'info',
  link         VARCHAR(100),
  ref_table    VARCHAR(50),
  ref_id       INT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_notif_created ON notifications (created_at);

CREATE TABLE IF NOT EXISTS notification_reads (
  user_id          INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  notification_id  INT NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
  read_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, notification_id)
);

-- ---------- Incidents ----------
CREATE TABLE IF NOT EXISTS incidents (
  id             SERIAL PRIMARY KEY,
  report_no      VARCHAR(30)   NOT NULL UNIQUE,
  incident_date  DATE          NOT NULL,
  time_reported  TIME          NOT NULL,
  hour           SMALLINT      NOT NULL,
  zone_id        VARCHAR(10)   NOT NULL REFERENCES zones(zone_id),
  location       VARCHAR(255),
  lat            NUMERIC(9,6),
  lng            NUMERIC(9,6),
  category       VARCHAR(60)   NOT NULL,
  description    TEXT,
  reporter       VARCHAR(150),
  officer        VARCHAR(100),
  priority       VARCHAR(20) NOT NULL DEFAULT 'Medium',
  status         VARCHAR(30) NOT NULL DEFAULT 'Under Investigation',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_inc_date ON incidents (incident_date);
CREATE INDEX IF NOT EXISTS idx_inc_zone_date ON incidents (zone_id, incident_date);
CREATE INDEX IF NOT EXISTS idx_inc_category ON incidents (category);

-- ---------- Census records ----------
CREATE TABLE IF NOT EXISTS census_records (
  id             SERIAL PRIMARY KEY,
  resident_no    VARCHAR(20)  NOT NULL UNIQUE,
  last_name      VARCHAR(80)  NOT NULL,
  first_name     VARCHAR(80)  NOT NULL,
  middle_name    VARCHAR(80),
  date_of_birth  DATE,
  sex            VARCHAR(10) NOT NULL DEFAULT 'Male',
  civil_status   VARCHAR(20) NOT NULL DEFAULT 'Single',
  nationality    VARCHAR(60) NOT NULL DEFAULT 'Filipino',
  zone_id        VARCHAR(10) REFERENCES zones(zone_id),
  address        VARCHAR(255),
  household_no   VARCHAR(30),
  contact_no     VARCHAR(30),
  voter_status   VARCHAR(30) NOT NULL DEFAULT 'Not Registered',
  occupation     VARCHAR(100),
  status         VARCHAR(20) NOT NULL DEFAULT 'Active',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_census_name ON census_records (last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_census_zone ON census_records (zone_id);

-- ---------- Blotter records ----------
CREATE TABLE IF NOT EXISTS blotter_records (
  id                SERIAL PRIMARY KEY,
  docket_no         VARCHAR(30)  NOT NULL UNIQUE,
  date_filed        DATE         NOT NULL,
  complainant       VARCHAR(150) NOT NULL,
  complainant_id    INT REFERENCES census_records(id) ON DELETE SET NULL,
  complainant_addr  VARCHAR(255),
  respondent        VARCHAR(150) NOT NULL,
  respondent_id     INT REFERENCES census_records(id) ON DELETE SET NULL,
  respondent_addr   VARCHAR(255),
  nature            VARCHAR(100),
  case_type         VARCHAR(20) NOT NULL DEFAULT 'CRIM',
  status            VARCHAR(20) NOT NULL DEFAULT 'Pending',
  zone_id           VARCHAR(10) REFERENCES zones(zone_id),
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_blotter_date_id ON blotter_records (date_filed DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_blotter_complainant_id ON blotter_records (complainant_id);
CREATE INDEX IF NOT EXISTS idx_blotter_respondent_id ON blotter_records (respondent_id);
CREATE INDEX IF NOT EXISTS idx_blotter_status ON blotter_records (status);

-- ---------- Settlement monitor ----------
CREATE TABLE IF NOT EXISTS settlements (
  id                  SERIAL PRIMARY KEY,
  blotter_id          INT NOT NULL REFERENCES blotter_records(id) ON DELETE CASCADE,
  case_no             VARCHAR(30)  NOT NULL UNIQUE,
  case_title          VARCHAR(200),
  complaint_title     VARCHAR(150),
  nature              VARCHAR(20) NOT NULL DEFAULT 'Civil',
  date_filed          DATE,
  date_confrontation  DATE,
  action_taken        VARCHAR(100),
  date_settlement     DATE,
  date_execution      DATE,
  main_point          TEXT,
  status              VARCHAR(20) NOT NULL DEFAULT 'Pending',
  remarks             VARCHAR(255),
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_settlements_blotter_id ON settlements (blotter_id);
CREATE INDEX IF NOT EXISTS idx_settlements_date_id ON settlements (date_filed DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_settlements_status ON settlements (status);

-- ---------- ML Runs ----------
CREATE TABLE IF NOT EXISTS ml_runs (
  id                      SERIAL PRIMARY KEY,
  trained_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  record_count            INT NOT NULL,
  active_occurrence_model VARCHAR(40) NOT NULL DEFAULT 'random_forest',
  active_type_model       VARCHAR(40) NOT NULL DEFAULT 'gradient_boosting',
  active_hotspot_model    VARCHAR(40) NOT NULL DEFAULT 'random_forest',
  occurrence_metrics_json TEXT NOT NULL,
  type_metrics_json       TEXT NOT NULL,
  hotspot_metrics_json    TEXT NOT NULL,
  hotspots_json           TEXT NOT NULL
);

-- ---------- System settings ----------
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key    VARCHAR(100) PRIMARY KEY,
  setting_value  TEXT NOT NULL,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------- Document Certificates ----------
CREATE TABLE IF NOT EXISTS barangay_clearance (
  id            SERIAL PRIMARY KEY,
  resident_id   INT NOT NULL REFERENCES census_records(id) ON DELETE CASCADE,
  ctrl_no       VARCHAR(30)  NOT NULL UNIQUE,
  full_name     VARCHAR(150) NOT NULL,
  age           SMALLINT,
  civil_status  VARCHAR(20) NOT NULL DEFAULT 'Single',
  address       VARCHAR(255),
  voter_status  VARCHAR(30) NOT NULL DEFAULT 'Not Registered',
  purpose       VARCHAR(150),
  or_no         VARCHAR(30),
  fee           NUMERIC(10,2) NOT NULL DEFAULT 20.00,
  date_issued   DATE NOT NULL,
  issued_by     VARCHAR(100),
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS barangay_residency (
  id              SERIAL PRIMARY KEY,
  resident_id     INT NOT NULL REFERENCES census_records(id) ON DELETE CASCADE,
  ctrl_no         VARCHAR(30)  NOT NULL UNIQUE,
  full_name       VARCHAR(150) NOT NULL,
  age             SMALLINT,
  civil_status    VARCHAR(20) NOT NULL DEFAULT 'Single',
  address         VARCHAR(255),
  years_residency SMALLINT,
  duration_unit   VARCHAR(20) NOT NULL DEFAULT 'years',
  purpose         VARCHAR(150),
  or_no           VARCHAR(30),
  fee             NUMERIC(10,2) NOT NULL DEFAULT 20.00,
  date_issued     DATE NOT NULL,
  issued_by       VARCHAR(100),
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS barangay_non_residency (
  id                SERIAL PRIMARY KEY,
  resident_id       INT NOT NULL REFERENCES census_records(id) ON DELETE CASCADE,
  ctrl_no           VARCHAR(30)  NOT NULL UNIQUE,
  full_name         VARCHAR(150) NOT NULL,
  previous_address  VARCHAR(255),
  purpose           VARCHAR(150),
  or_no             VARCHAR(30),
  fee               NUMERIC(10,2) NOT NULL DEFAULT 20.00,
  date_issued       DATE NOT NULL,
  issued_by         VARCHAR(100),
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS indigency_certificates (
  id            SERIAL PRIMARY KEY,
  resident_id   INT NOT NULL REFERENCES census_records(id) ON DELETE CASCADE,
  ctrl_no       VARCHAR(30)  NOT NULL UNIQUE,
  full_name     VARCHAR(150) NOT NULL,
  age           SMALLINT,
  civil_status  VARCHAR(20) NOT NULL DEFAULT 'Single',
  address       VARCHAR(255),
  purpose       VARCHAR(150),
  date_issued   DATE NOT NULL,
  issued_by     VARCHAR(100),
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS backups (
  id          SERIAL PRIMARY KEY,
  file_name   VARCHAR(255) NOT NULL,
  size_bytes  BIGINT NOT NULL DEFAULT 0,
  status      VARCHAR(20) NOT NULL DEFAULT 'Success',
  created_by  VARCHAR(100),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS generated_reports (
  id            SERIAL PRIMARY KEY,
  report_type   VARCHAR(100) NOT NULL,
  generated_by  VARCHAR(100) NOT NULL,
  period_from   DATE,
  period_to     DATE,
  format        VARCHAR(20) NOT NULL DEFAULT 'PDF',
  file_path     VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------- Reference Data Initializers ----------
INSERT INTO zones (zone_id, label, lat, lng, weight) VALUES
 ('Zone 1','Zone 1 – Barangay Hall Area',   14.8836, 120.9655, 0.20),
 ('Zone 2','Zone 2 – South Central',        14.8824, 120.9648, 0.11),
 ('Zone 3','Zone 3 – Market Area',          14.8845, 120.9663, 0.18),
 ('Zone 4','Zone 4 – Southeast Residential',14.8818, 120.9660, 0.06),
 ('Zone 5','Zone 5 – Northern Cluster',     14.8852, 120.9650, 0.10),
 ('Zone 6','Zone 6 – West Interior',        14.8830, 120.9636, 0.05),
 ('Zone 7','Zone 7 – Basketball Court Area',14.8842, 120.9641, 0.16),
 ('Zone 8','Zone 8 – East Road Junction',   14.8826, 120.9670, 0.14)
ON CONFLICT (zone_id) DO UPDATE SET label = EXCLUDED.label;

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
ON CONFLICT (setting_key) DO NOTHING;

-- ---------- Default User Accounts ----------
INSERT INTO users (username, password, full_name, email, contact_no, role, status) VALUES
 ('admin',    '$2y$10$83eRfqKumjsPb6awZCWnWufUjJQYDNrAS7JefoX8.xNiTWDrnywO2', 'Juan Dela Cruz',     'admin@mapulanglupa.gov.ph',    '0917-000-0001', 'System Admin',     'Active'),
 ('kapitan',  '$2y$10$MU1wJRAapBuFi7uCpUpADuCpyoohsd.qnYZrhS2pKBHn/41cCSYb.', 'Kapitan Jose Reyes', 'kapitan@mapulanglupa.gov.ph',  '0917-000-0002', 'Barangay Captain', 'Active'),
 ('jdelacuz', '$2y$10$h3SgudFs/ZH0zTPNYFpGxeC./nrW5Dej91fIq1pwPaZErdSIpraW.', 'Juan Dela Cruz II',  'jdelacruz@mapulanglupa.gov.ph','0917-000-0003', 'Desk Officer',     'Active'),
 ('msantos',  '$2y$10$h3SgudFs/ZH0zTPNYFpGxeC./nrW5Dej91fIq1pwPaZErdSIpraW.', 'Maria Santos',       'msantos@mapulanglupa.gov.ph',  '0917-000-0004', 'Desk Officer',     'Active'),
 ('pencoder', '$2y$10$NpApjKWbJzDnbxEU2t123O2XkZBADPu4HKbwXVyb82am3Vb.vIyaa', 'Pedro Encoder',      'pencoder@mapulanglupa.gov.ph', '0917-000-0005', 'Data Encoder',     'Active')
ON CONFLICT (username) DO UPDATE SET password = EXCLUDED.password;

