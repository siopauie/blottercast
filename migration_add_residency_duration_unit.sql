-- Migration: add duration_unit to barangay_residency
-- Run this once against an existing database that was created before this
-- change (schema.sql already has this column for fresh installs, so you
-- only need this file if your barangay_residency table predates it).
--
-- Usage:
--   mysql -u <user> -p <database_name> < migration_add_residency_duration_unit.sql

ALTER TABLE barangay_residency
  ADD COLUMN duration_unit ENUM('years','months') NOT NULL DEFAULT 'years'
  AFTER years_residency;
