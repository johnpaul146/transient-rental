<?php
/**
 * tours.php — RETIRED.
 * The Tours/Boat booking service was removed. This URL no longer shows a booking page and never writes anything:
 * every request (GET or a hand-made POST) is redirected to the homepage before any booking code can run.
 * Historical tour bookings, payments and package components stay in the database and remain visible read-only
 * (My Dashboard / Booking Management). Activities (activities.php) are a separate feature and are NOT affected.
 */
header('Location: index.php', true, 302);
exit();
