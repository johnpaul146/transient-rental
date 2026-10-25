-- 2026-10-24  Pass 4 — Tours/Boat removal: READ-ONLY checks and export queries.
--
-- NOTHING in this file changes data or schema. Pass 4 needs NO database migration: every Tour table and column is kept
-- (tours, tour_bookings, package_bookings.tour_booking_id / tour_amount, blocked_dates.item_type = 'tour',
--  rebook_requests / emergency_rebook_requests with booking_type = 'tour', booking_payments, system_logs).
-- Run these in phpMyAdmin > SQL (each statement on its own is fine). Written for MariaDB 11.4.

-- ---------------------------------------------------------------------------------------------------------------
-- 1) BEFORE DEPLOYING: Tour work that Admin/Staff can no longer finish in the app after Pass 4
--    (Pass 4 removes every Tour screen, so verify / complete / cancel must be done with the Pass 3 screens first.)
-- ---------------------------------------------------------------------------------------------------------------
-- 1a) Open standalone Tour bookings (pending or confirmed). Expect to resolve these first.
SELECT tb.id, tb.reference_number, tb.booking_status, tb.payment_status, tb.booking_date, tb.guest_name, tb.contact_number,
       tb.total_amount, tb.amount_paid, tb.payment_proof IS NOT NULL AS has_proof
  FROM tour_bookings tb
 WHERE tb.package_id IS NULL AND tb.booking_status IN ('pending','confirmed')
 ORDER BY tb.booking_date;

-- 1b) Packages that still contain a Tour component and have not finished (pending / confirmed)
SELECT p.id, p.reference_number, p.booking_status, p.payment_status, p.tour_amount, p.grand_total, tb.booking_date AS tour_date
  FROM package_bookings p JOIN tour_bookings tb ON tb.id = p.tour_booking_id
 WHERE p.booking_status IN ('pending','confirmed')
 ORDER BY tb.booking_date;

-- 1c) Pending rebook / Emergency Rebook requests of Tours or of packages with a Tour component
SELECT r.id, r.booking_type, r.booking_id, r.reference_number, r.status, r.requested_at
  FROM rebook_requests r
 WHERE r.status = 'pending' AND (r.booking_type = 'tour' OR r.original_schedule LIKE '%"tour"%');
SELECT e.* FROM emergency_rebook_requests e WHERE e.status = 'pending' AND e.booking_type = 'tour';

-- ---------------------------------------------------------------------------------------------------------------
-- 2) AFTER DEPLOYING: prove no new Tour booking or Tour rebook request can exist (all should return 0)
--    Replace '2026-10-25 00:00:00' with the date/time you uploaded Pass 4.
-- ---------------------------------------------------------------------------------------------------------------
SELECT COUNT(*) AS new_tour_bookings        FROM tour_bookings            WHERE created_at   >= '2026-10-25 00:00:00';
SELECT COUNT(*) AS new_tour_in_packages     FROM package_bookings         WHERE created_at   >= '2026-10-25 00:00:00' AND (tour_booking_id IS NOT NULL OR tour_amount <> 0);
SELECT COUNT(*) AS new_tour_rebook_requests FROM rebook_requests          WHERE requested_at >= '2026-10-25 00:00:00' AND (booking_type = 'tour' OR original_schedule LIKE '%"tour"%' OR requested_schedule LIKE '%"tour"%');
SELECT COUNT(*) AS new_tour_emergency       FROM emergency_rebook_requests WHERE booking_type = 'tour' AND requested_at >= '2026-10-25 00:00:00';
SELECT COUNT(*) AS new_tour_blocks          FROM blocked_dates            WHERE item_type = 'tour' AND created_at >= '2026-10-25 00:00:00';
SELECT COUNT(*) AS new_tour_payments        FROM booking_payments         WHERE booking_type = 'tour' AND created_at >= '2026-10-25 00:00:00';

-- ---------------------------------------------------------------------------------------------------------------
-- 3) HISTORICAL DATA IS STILL THERE (compare with your pre-deploy counts; Pass 4 deletes nothing)
-- ---------------------------------------------------------------------------------------------------------------
SELECT 'tours' AS tbl, COUNT(*) AS n FROM tours
UNION ALL SELECT 'tour_bookings', COUNT(*) FROM tour_bookings
UNION ALL SELECT 'package_bookings with tour component', COUNT(*) FROM package_bookings WHERE tour_booking_id IS NOT NULL
UNION ALL SELECT 'booking_payments (tour)', COUNT(*) FROM booking_payments WHERE booking_type = 'tour'
UNION ALL SELECT 'blocked_dates (tour)', COUNT(*) FROM blocked_dates WHERE item_type = 'tour'
UNION ALL SELECT 'rebook_requests (tour)', COUNT(*) FROM rebook_requests WHERE booking_type = 'tour'
UNION ALL SELECT 'system_logs (tour)', COUNT(*) FROM system_logs WHERE module = 'tour' OR target_type IN ('tour','tour_booking');

-- ---------------------------------------------------------------------------------------------------------------
-- 4) SECURE ARCHIVE / ACCOUNTING EXPORT (optional, only if you must keep a separate copy for accounting or legal reasons)
--    Run each query, use phpMyAdmin > Export > CSV, store the files offline (encrypted drive), and keep the database rows.
--    The files contain guest names and phone numbers: do NOT put them in the web folder or in the project ZIP.
-- ---------------------------------------------------------------------------------------------------------------
-- 4a) Every Tour booking (standalone and package components)
SELECT tb.id, tb.reference_number, tb.package_reference, tb.package_id, t.tour_name, tb.booking_date, tb.preferred_time,
       tb.number_of_guests, tb.guest_name, tb.contact_number, tb.total_amount, tb.reservation_fee_amount, tb.amount_paid,
       tb.payment_status, tb.booking_status, tb.gcash_reference, tb.reservation_paid_at, tb.balance_paid_at, tb.cancelled_at,
       tb.cancellation_reason, tb.completed_at, tb.created_at
  FROM tour_bookings tb LEFT JOIN tours t ON t.id = tb.tour_id
 ORDER BY tb.id;
-- 4b) Every Tour payment in the ledger
SELECT bp.id, bp.booking_id, bp.reference_number, bp.payment_type, bp.amount, bp.payment_method, bp.gcash_reference, bp.received_at, bp.notes
  FROM booking_payments bp WHERE bp.booking_type = 'tour' ORDER BY bp.id;
-- 4c) Packages that carried a Tour component (the Tour part of the total)
SELECT p.id, p.reference_number, p.booking_status, p.payment_status, p.house_amount, p.tour_amount, p.food_amount, p.grand_total, p.created_at
  FROM package_bookings p WHERE p.tour_booking_id IS NOT NULL ORDER BY p.id;
-- 4d) Tour catalog
SELECT id, tour_name, tour_type, boat_type, price_per_boat, boat_capacity, max_guests, status, created_at FROM tours ORDER BY id;
-- 4e) Revenue actually received for Tours (standalone + package components) — these amounts are NOT in the Pass 4 reports
SELECT DATE_FORMAT(bp.received_at, '%Y-%m') AS month, COUNT(*) AS payments, SUM(bp.amount) AS received
  FROM booking_payments bp WHERE bp.booking_type = 'tour' GROUP BY month ORDER BY month;

-- ---------------------------------------------------------------------------------------------------------------
-- ROLLBACK: nothing to roll back in the database (this file is read-only). To undo Pass 4, restore the Pass 3 files.
-- ---------------------------------------------------------------------------------------------------------------
