-- 2026-10-20  House bookings: senior citizens + health condition (replaces the individual guest-name list)
-- Safe to run more than once (MariaDB 10.0+/11.x: ADD COLUMN IF NOT EXISTS). Additive only: nothing is dropped or rewritten.
-- Existing rows stay NULL = "Not recorded" (they are NOT converted to 0 / No).
-- If your live database already has these three columns, this script changes nothing.
-- BACK UP FIRST (phpMyAdmin > Export), then run on the live database.

ALTER TABLE `house_bookings`
  ADD COLUMN IF NOT EXISTS `senior_citizens` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `has_health_condition` TINYINT(1) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `health_condition_details` VARCHAR(500) DEFAULT NULL;

-- Verification (read-only): expect 3 rows
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'house_bookings'
--    AND COLUMN_NAME IN ('senior_citizens','has_health_condition','health_condition_details');

-- ROLLBACK (only if you must; this DELETES the values recorded by new bookings):
-- ALTER TABLE `house_bookings` DROP COLUMN `senior_citizens`, DROP COLUMN `has_health_condition`, DROP COLUMN `health_condition_details`;
