-- Migration: add 'Deactivated' to voter_status ENUMs
-- Run this once against an existing database that was created before this
-- change (schema.sql already has it for fresh installs, so you only need
-- this file if your census_records / barangay_clearance tables predate it).
--
-- Usage:
--   mysql -u <user> -p <database_name> < migration_add_voter_status_deactivated.sql

ALTER TABLE census_records
  MODIFY COLUMN voter_status ENUM('Registered Voter','Not Registered','Deactivated') NOT NULL DEFAULT 'Not Registered';

ALTER TABLE barangay_clearance
  MODIFY COLUMN voter_status ENUM('Registered Voter','Not Registered','Deactivated') NOT NULL DEFAULT 'Not Registered';

-- Any resident already marked Deceased should also be moved to a
-- Deactivated voter status, to match the new auto-deactivation rule.
UPDATE census_records SET voter_status = 'Deactivated' WHERE status = 'Deceased';
