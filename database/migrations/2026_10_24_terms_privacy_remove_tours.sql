-- 2026-10-24  Terms & Privacy: remove wording that implies the retired Tours/Boat booking service can still be booked or rebooked.
--
-- WHAT IT DOES
--   Edits ONLY the text of site_content rows (terms.body, privacy.body) with narrow, exact-phrase REPLACE() calls.
--   Anything an admin has reworded is left alone (a phrase that is not found is simply not changed).
--   Nothing is deleted. Titles, safety and liability terms for Activities, payment, cancellation and house rules are kept.
--   A copy of the two original rows is saved first in site_content_terms_bak_20261024 (new, plain backup table the app never reads).
--
-- !! EVERY USER WILL BE ASKED TO RE-ACCEPT THE TERMS AFTER THIS RUNS !!
--   The app computes the Terms/Privacy "version" as a hash of these rows (includes/TermsGate.php::getCurrentVersion).
--   Any change to the text changes the hash, so the acceptance popup appears again at the next login / booking.
--   Old acceptance records in user_terms_acceptance are NOT touched. If you roll back, the hash returns to the old value
--   and users who had accepted it are treated as accepted again.
--   Run this only when you are happy for everyone to re-accept (e.g. in a quiet period).
--
-- Safe to run more than once. BACK UP FIRST (phpMyAdmin > Export), then run on the live database.
-- Written for MariaDB 11.4 (the live server). Run each block in order; the whole file can be pasted in one go.

-- 1) Backup of the current text (only if this backup has not been taken already)
-- (CREATE TABLE is DDL and commits by itself, so it sits before the transaction)
CREATE TABLE IF NOT EXISTS `site_content_terms_bak_20261024` (
  `id` int(11) NOT NULL,
  `section_name` varchar(100) NOT NULL,
  `content_key` varchar(100) NOT NULL,
  `content_value` text DEFAULT NULL,
  `backed_up_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

START TRANSACTION;

INSERT INTO `site_content_terms_bak_20261024` (`id`, `section_name`, `content_key`, `content_value`)
SELECT sc.`id`, sc.`section_name`, sc.`content_key`, sc.`content_value`
  FROM `site_content` sc
 WHERE sc.`section_name` IN ('terms','privacy') AND sc.`content_key` = 'body'
   AND NOT EXISTS (SELECT 1 FROM `site_content_terms_bak_20261024` b
                    WHERE b.`section_name` = sc.`section_name` AND b.`content_key` = sc.`content_key`);

-- 2) Terms: tour booking / rebooking wording (payment, cancellation & rebooking sections)
UPDATE `site_content`
   SET `content_value` = REPLACE(REPLACE(REPLACE(`content_value`,
         'every House, Tour, Food or Package booking',                'every House, Food or Package booking'),
         'House, Tour, Food and Package bookings that are confirmed', 'House, Food and Package bookings that are confirmed'),
         'House, Tour, Food and Package rebook requests',             'House, Food and Package rebook requests')
 WHERE `section_name` = 'terms' AND `content_key` = 'body'
   AND `content_value` LIKE '%Tour%';

-- 3) Terms: section 5 becomes Activities only. The safety-instruction and personal-items-liability lines stay, reworded for activities.
UPDATE `site_content`
   SET `content_value` = REPLACE(REPLACE(REPLACE(`content_value`,
         '5. TOURS & ACTIVITIES',                      '5. ACTIVITIES'),
         'Tour schedules may change due to weather',   'Activity schedules may change due to weather'),
         'lost during tours.',                         'lost during activities.')
 WHERE `section_name` = 'terms' AND `content_key` = 'body'
   AND (`content_value` LIKE '%TOURS & ACTIVITIES%' OR `content_value` LIKE '%Tour schedules%' OR `content_value` LIKE '%lost during tours%');

-- 4) Terms: opening line still names the old business "Transient House & Tours"
UPDATE `site_content`
   SET `content_value` = REPLACE(`content_value`, 'Welcome to Transient House & Tours.', 'Welcome to Jerry Dino''s Stay.')
 WHERE `section_name` = 'terms' AND `content_key` = 'body'
   AND `content_value` LIKE '%Welcome to Transient House & Tours.%';

-- 5) Privacy: data may be shared with the people who run the booked activity (not "tour operators")
UPDATE `site_content`
   SET `content_value` = REPLACE(`content_value`, 'shared with tour operators only when necessary', 'shared with activity operators only when necessary')
 WHERE `section_name` = 'privacy' AND `content_key` = 'body'
   AND `content_value` LIKE '%shared with tour operators only when necessary%';

COMMIT;

-- ---------------------------------------------------------------------------------------------------------------
-- VERIFICATION (read-only; run after the script)
-- A) Should return 0 rows once migrated (any row listed still contains tour wording, e.g. text an admin had reworded):
-- SELECT section_name, content_key FROM site_content
--  WHERE section_name IN ('terms','privacy') AND content_key = 'body'
--    AND (content_value LIKE '%House, Tour%' OR content_value LIKE '%TOURS & ACTIVITIES%' OR content_value LIKE '%Tour schedules%'
--         OR content_value LIKE '%during tours%' OR content_value LIKE '%tour operators%' OR content_value LIKE '%Transient House & Tours%');
-- B) The backup holds the original text (expect 2 rows):
-- SELECT section_name, content_key, CHAR_LENGTH(content_value) AS chars, backed_up_at FROM site_content_terms_bak_20261024;
-- C) Activities safety/liability lines are still present (expect 1 row, all three = 1):
-- SELECT (content_value LIKE '%5. ACTIVITIES%') AS heading, (content_value LIKE '%follow all safety instructions%') AS safety,
--        (content_value LIKE '%lost during activities%') AS liability
--   FROM site_content WHERE section_name = 'terms' AND content_key = 'body';
-- D) Acceptance history is untouched (same counts as before; users re-accept under a new version value):
-- SELECT terms_version, COUNT(*) AS acceptances FROM user_terms_acceptance GROUP BY terms_version;
--
-- ROLLBACK (restores the exact original text, so the old version hash returns and earlier acceptances count again):
-- UPDATE site_content sc JOIN site_content_terms_bak_20261024 b
--     ON b.section_name = sc.section_name AND b.content_key = sc.content_key
--    SET sc.content_value = b.content_value
--  WHERE sc.section_name IN ('terms','privacy') AND sc.content_key = 'body';
-- (The backup table can be dropped later with DROP TABLE site_content_terms_bak_20261024; it is not needed by the app.)
