-- 2026-10-25  Pass 5 — READ-ONLY: current status of the open Tour bookings (and packages that contain a Tour part).
--
-- Every statement is a SELECT. Nothing here changes data or schema, and nothing in Pass 5 changes any booking or payment.
-- Run in phpMyAdmin > SQL on the LIVE database (the Oct 24 snapshot I checked will already be out of date).
-- Written for MariaDB 11.4. "today" is the server's CURDATE().
--
-- HOW TO READ THE `what_to_decide` COLUMN (a hint only — the decision is yours):
--   SERVICE DATE PASSED, NOT MARKED COMPLETE  = the date is over but the booking is still "confirmed"
--   UPCOMING, BALANCE DUE                      = confirmed, only the reservation fee is paid
--   UPCOMING, UNPAID                           = still pending (no confirmed payment)
--   (finished or cancelled rows are listed as DONE and need nothing)

-- 1) The three named standalone Tour bookings
SELECT tb.id, tb.reference_number, tb.booking_status, tb.payment_status, tb.booking_date AS tour_date, tb.preferred_time,
       tb.number_of_guests, tb.total_amount, tb.amount_paid, (tb.total_amount - tb.amount_paid) AS balance,
       tb.guest_name, tb.contact_number, tb.created_at,
       CASE WHEN tb.booking_status IN ('completed','cancelled') THEN 'DONE'
            WHEN tb.booking_date < CURDATE() THEN 'SERVICE DATE PASSED, NOT MARKED COMPLETE'
            WHEN tb.payment_status = 'pending'  THEN 'UPCOMING, UNPAID'
            ELSE 'UPCOMING, BALANCE DUE' END AS what_to_decide
  FROM tour_bookings tb
 WHERE tb.reference_number IN ('TOUR-20261009-7783','TOUR-20261013-1522','TOUR-20261012-7618')
 ORDER BY tb.booking_date;

-- 2) EVERY standalone Tour booking that is still open (pending or confirmed) — catches any created after the three above
SELECT tb.id, tb.reference_number, tb.booking_status, tb.payment_status, tb.booking_date AS tour_date,
       tb.total_amount, tb.amount_paid, (tb.total_amount - tb.amount_paid) AS balance, tb.guest_name, tb.contact_number,
       CASE WHEN tb.booking_date < CURDATE() THEN 'SERVICE DATE PASSED, NOT MARKED COMPLETE'
            WHEN tb.payment_status = 'pending'  THEN 'UPCOMING, UNPAID'
            ELSE 'UPCOMING, BALANCE DUE' END AS what_to_decide
  FROM tour_bookings tb
 WHERE tb.package_id IS NULL AND tb.booking_status IN ('pending','confirmed')
 ORDER BY tb.booking_date;

-- 3) Packages that contain a Tour part and are not finished (pending / confirmed), with the stay dates and the Tour part's own status
SELECT p.id AS package_id, p.reference_number AS package_ref, p.booking_status AS package_status, p.payment_status AS package_payment,
       p.grand_total, p.tour_amount, p.amount_paid, (p.grand_total - p.amount_paid) AS balance,
       hb.check_in_date, hb.check_out_date, tb.reference_number AS tour_part_ref, tb.booking_date AS tour_date,
       tb.booking_status AS tour_part_status, g.full_name AS guest, g.contact_number,
       CASE WHEN tb.booking_date < CURDATE() AND (hb.check_out_date IS NULL OR hb.check_out_date < CURDATE()) THEN 'STAY AND TOUR DATE PASSED, NOT MARKED COMPLETE'
            WHEN p.payment_status = 'pending' THEN 'UPCOMING, UNPAID'
            ELSE 'UPCOMING, BALANCE DUE' END AS what_to_decide
  FROM package_bookings p
  JOIN tour_bookings tb ON tb.id = p.tour_booking_id
  LEFT JOIN house_bookings hb ON hb.id = p.house_booking_id
  LEFT JOIN guests g ON g.id = p.guest_id
 WHERE p.booking_status IN ('pending','confirmed')
 ORDER BY tb.booking_date;

-- 4) Payments recorded in the ledger for those Tour bookings and packages (read-only; shows what the guest already paid)
SELECT bp.id, bp.booking_type, bp.booking_id, bp.reference_number, bp.payment_type, bp.amount, bp.payment_method, bp.gcash_reference, bp.received_at
  FROM booking_payments bp
 WHERE (bp.booking_type = 'tour' AND bp.booking_id IN (SELECT id FROM tour_bookings WHERE booking_status IN ('pending','confirmed')))
    OR (bp.booking_type = 'package' AND bp.booking_id IN (SELECT p.id FROM package_bookings p WHERE p.tour_booking_id IS NOT NULL AND p.booking_status IN ('pending','confirmed')))
 ORDER BY bp.booking_type, bp.booking_id, bp.received_at;

-- 5) Dates currently blocked on the boat/tour calendar by those open bookings (they free up when the booking is completed or cancelled)
SELECT bd.id, bd.item_id AS tour_id, bd.block_date, bd.reason
  FROM blocked_dates bd
 WHERE bd.item_type = 'tour' AND bd.block_date >= CURDATE()
 ORDER BY bd.block_date;

-- 6) Pending rebook / Emergency Rebook requests that touch a Tour or a package with a Tour part
SELECT r.id, r.booking_type, r.booking_id, r.reference_number, r.status, r.requested_at
  FROM rebook_requests r
 WHERE r.status = 'pending' AND (r.booking_type = 'tour' OR r.original_schedule LIKE '%"tour"%');
SELECT e.id, e.booking_type, e.booking_id, e.reference_number, e.status, e.requested_at
  FROM emergency_rebook_requests e
 WHERE e.status = 'pending' AND (e.booking_type = 'tour' OR e.original_schedule LIKE '%"tour"%');

-- 7) ONE-LINE SUMMARY — all three numbers should be 0 once everything is resolved
SELECT (SELECT COUNT(*) FROM tour_bookings WHERE package_id IS NULL AND booking_status IN ('pending','confirmed')) AS open_standalone_tours,
       (SELECT COUNT(*) FROM tour_bookings WHERE package_id IS NULL AND booking_status = 'confirmed' AND booking_date < CURDATE()) AS past_date_not_completed,
       (SELECT COUNT(*) FROM package_bookings WHERE tour_booking_id IS NOT NULL AND booking_status IN ('pending','confirmed')) AS open_packages_with_tour_part;

-- 8) Leftover Tour wording stored in site_content (nothing in the app displays these rows; listed only so you know they exist)
SELECT section_name, content_key, LEFT(content_value, 80) AS value_starts_with
  FROM site_content
 WHERE LOWER(content_value) LIKE '%tour%' AND content_key <> 'google_maps_embed'
 ORDER BY section_name, content_key;
