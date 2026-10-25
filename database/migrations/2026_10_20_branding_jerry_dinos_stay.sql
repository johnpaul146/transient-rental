-- 2026-10-20  Branding: "Jerry Dino's Stay"  (OPTIONAL, data-only, safe to run more than once)
-- The code now falls back to "Jerry Dino's Stay" everywhere. This script only updates site_content rows that STILL hold
-- the old factory default text, so anything you customised in Edit Content is left alone. Nothing is deleted.
-- It does NOT touch the Terms / Privacy bodies: changing them would change the terms version hash and force every user
-- to re-accept. Edit those yourself in Edit Content > Terms if you want the new name there.
-- BACK UP FIRST (phpMyAdmin > Export), then run on the live database.

UPDATE `site_content`
   SET `content_value` = 'Welcome to Jerry Dino''s Stay'
 WHERE `section_name` = 'hero' AND `content_key` = 'title'
   AND `content_value` = 'Welcome to Transient House & Tours';

UPDATE `site_content`
   SET `content_value` = 'Jerry Dino''s Stay. All rights reserved.'
 WHERE `section_name` = 'footer' AND `content_key` = 'copyright'
   AND `content_value` = 'Transient House & Tours. All rights reserved.';

UPDATE `site_content`
   SET `content_value` = 'Choose from our comfortable houses, local food and island activities for your next adventure.'
 WHERE `section_name` = 'cta' AND `content_key` = 'subtitle'
   AND `content_value` = 'Choose from our comfortable houses or exciting tour packages for your next adventure.';

-- Site name used by the admin sidebar / report headers: insert only when no value exists yet.
INSERT INTO `site_content` (`section_name`, `content_key`, `content_value`)
SELECT 'site_settings', 'site_name', 'Jerry Dino''s Stay' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `site_content` WHERE `section_name` = 'site_settings' AND `content_key` = 'site_name');

-- OPTIONAL, NOT RUN BY DEFAULT — Terms / Privacy text that still says "Transient House & Tours".
-- Running this changes the Terms version hash, so EVERY user will be asked to accept the Terms again. Decide first.
-- UPDATE site_content SET content_value = REPLACE(content_value, 'Transient House & Tours', 'Jerry Dino''s Stay')
--  WHERE section_name IN ('terms','privacy') AND content_key = 'body' AND content_value LIKE '%Transient House & Tours%';

-- Verification (read-only):
-- SELECT section_name, content_key, content_value FROM site_content
--  WHERE (section_name='hero' AND content_key='title') OR (section_name='footer' AND content_key='copyright')
--     OR (section_name='cta' AND content_key='subtitle') OR (section_name='site_settings' AND content_key='site_name');

-- ROLLBACK (restores the old defaults ONLY where the new default text is still in place):
-- UPDATE site_content SET content_value='Welcome to Transient House & Tours' WHERE section_name='hero' AND content_key='title' AND content_value='Welcome to Jerry Dino''s Stay';
-- UPDATE site_content SET content_value='Transient House & Tours. All rights reserved.' WHERE section_name='footer' AND content_key='copyright' AND content_value='Jerry Dino''s Stay. All rights reserved.';
-- UPDATE site_content SET content_value='Choose from our comfortable houses or exciting tour packages for your next adventure.' WHERE section_name='cta' AND content_key='subtitle' AND content_value='Choose from our comfortable houses, local food and island activities for your next adventure.';
-- DELETE FROM site_content WHERE section_name='site_settings' AND content_key='site_name' AND content_value='Jerry Dino''s Stay';
