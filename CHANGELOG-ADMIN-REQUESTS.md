# Jerry Dino's Stay — Admin request changes

This is a **change pack**, not a full application archive. Unzip it into the existing project root and overwrite the matching files. It intentionally excludes the full database dump, `.git`, IDE/deployment settings, and credential-bearing configuration files.

## Implemented

1. **Branding:** the shared public header/footer use Jerry Dino's Stay. The homepage uses it as the default hero title while preserving any custom title stored in `site_content`; the migration updates only the old seeded homepage title and copyright if those exact defaults are still present.
2. **Guest calendars:** available dates are green in house, food, package, and guest rebooking calendars. Admin/Staff calendar colors were not changed.
3. **PAX group details:** new house bookings no longer ask for individual guest names. Standalone house, package-house, and Staff walk-in house forms collect the senior-citizen count and a health-condition Yes/No answer; optional details can be entered when relevant. Server-side validation enforces the senior count not exceeding total PAX. Historical guest names are retained for old bookings.
4. **Tours/Boat removal from new booking entry points:** removed from the homepage, public navigation/footer, online package builder, and new Staff walk-in booking options; the old standalone `tours.php` route redirects to the homepage with a notice. Tour tables, old booking/payment records, Admin catalog/management, and legacy rebooking for already-existing tour reservations are retained.


**Scope note:** the separate Activities catalog (such as Banana Boat, Jet Ski, and other activity listings) is not removed by this pack. It removes the Tours/Boat booking module and new booking entry points. Existing tour rebooking remains only for already-existing reservations.

## Database migration — required before deploying the PHP files

Run these in order against the current application database:

1. `database/migrations/20261020_house_senior_health_fields.sql`
2. `database/migrations/20261020_jerry_dino_brand.sql`

The new senior/health columns are nullable for historical rows, so older bookings display **Not recorded** instead of falsely implying that the guest answered No. New forms explicitly store their answers. The migration scripts are designed to be safe to rerun.

## Validation performed

- PHP syntax check passed for all 16 PHP files included in this change pack.
- Inline JavaScript syntax checks passed for 12 extracted script blocks across the changed pages; server-rendered PHP expressions were replaced with placeholders for parsing.
- A food-calendar runtime smoke test rendered the current month and marked future, unblocked dates as available.
- Static checks confirmed no Tour/Boat selector remains in the new package or walk-in booking forms and no per-PAX guest-name input remains in the house/package/walk-in forms.
- No live database migration or end-to-end browser/database test was run in this environment. Back up the database and apply the migrations before deploying.

## Verification corrections

- Admin booking details now preserve `NULL` senior/health fields for historical bookings so they display `Not recorded`, not `0` or `No`.
- The guest package-cart badge no longer counts a stale Tours/Boat item.
- A package cart saved before deployment must be edited to provide the new senior/health answers; confirmation cannot silently record missing answers as zero/No.
- The homepage hero title remains editable from `site_content`; the brand is used when the title is missing or still the original default, and the seed migration updates that default in the database.
- Standalone house booking now revalidates the selected house status and capacity on the server, matching package and walk-in booking checks.
- Fixed the guest food calendar so future, unblocked dates are marked green without referencing an undefined `isPast` variable.
- The health-condition Yes/No field now requires an explicit answer in online, package, and Staff walk-in house booking flows, with server-side validation.
- `tours.php` is now a minimal redirect, with no unreachable standalone booking code left in the file.
