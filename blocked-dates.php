<?php
session_start();
date_default_timezone_set('Asia/Manila'); // same timezone as the other admin pages
require_once 'database.php';
require_once 'includes/sidebar-counts.php';
require_once 'includes/AvailabilityService.php';

// ✅ Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

require_once 'includes/auth.php';
requireAdminOrStaff();

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

// ============================================================
// AUTO-CREATE blocked_dates TABLE (unchanged schema)
// ============================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_dates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_type ENUM('house', 'tour', 'food') NOT NULL,
        item_id INT NOT NULL,
        block_date DATE NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        block_type ENUM('walk_in', 'maintenance', 'special_occasion', 'owner_use', 'other') DEFAULT 'walk_in',
        blocked_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_block (item_type, item_id, block_date),
        INDEX idx_item (item_type, item_id, block_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(PDOException $e) {}

// Staff block requests (staff cannot block directly; an administrator approves)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_date_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_type ENUM('house', 'tour', 'food') NOT NULL,
        item_id INT NOT NULL,
        dates_json TEXT NOT NULL,
        block_type ENUM('walk_in', 'maintenance', 'special_occasion', 'owner_use', 'other') NOT NULL DEFAULT 'other',
        note VARCHAR(255) DEFAULT NULL,
        status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        requested_by INT NOT NULL,
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        review_note VARCHAR(255) DEFAULT NULL,
        INDEX idx_status (status, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(PDOException $e) {}

// ✅ Older installs: add 'food' to the ENUM — only if it's actually missing
//    (previously this ALTER ran on every page load)
try {
    $col = $pdo->query("SHOW COLUMNS FROM blocked_dates LIKE 'item_type'")->fetch();
    if ($col && stripos((string)$col['Type'], "'food'") === false) {
        $pdo->exec("ALTER TABLE blocked_dates MODIFY COLUMN item_type ENUM('house', 'tour', 'food') NOT NULL");
    }
} catch(PDOException $e) {}

// ============================================================
// BLOCKING RULES / RESOURCE REGISTRY
// Resource *types* map 1:1 to the blocked_dates.item_type ENUM.
// The actual resources (houses, tours, food items) are always
// loaded from the database — nothing is hardcoded.
// ============================================================
const BD_AUTO_PREFIX = 'Auto-blocked from booking #'; // written by booking-management.php
const BD_MAX_DATES   = 366;

function bd_resources(): array {
    return [
        'house' => [
            'label' => 'House', 'plural' => 'Houses', 'icon' => 'home',
            'table' => 'houses', 'name_col' => 'house_name',
            'b_table' => 'house_bookings', 'b_fk' => 'house_id',
            'b_start' => 'check_in_date', 'b_end' => 'check_out_date',
        ],
        'tour' => [
            'label' => 'Tour', 'plural' => 'Tours', 'icon' => 'umbrella-beach',
            'table' => 'tours', 'name_col' => 'tour_name',
            'b_table' => 'tour_bookings', 'b_fk' => 'tour_id',
            'b_start' => 'booking_date', 'b_end' => null,
        ],
        'food' => [
            'label' => 'Food', 'plural' => 'Food Items', 'icon' => 'utensils',
            'table' => 'food_items', 'name_col' => 'name',
            'b_table' => 'food_bookings', 'b_fk' => 'food_id',
            'b_start' => 'preferred_date', 'b_end' => null,
        ],
    ];
}

// DB enum value => label shown in the UI
function bd_reasons(): array {
    return [
        'walk_in'          => ['label' => 'Walk-in',       'icon' => 'walking'],
        'maintenance'      => ['label' => 'Maintenance',   'icon' => 'tools'],
        'owner_use'        => ['label' => 'Owner Use',     'icon' => 'crown'],
        'special_occasion' => ['label' => 'Special Event', 'icon' => 'gift'],
        'other'            => ['label' => 'Other',         'icon' => 'ellipsis-h'],
    ];
}

// Block types STAFF may unblock. None: unblocking re-opens dates for booking, so it is
// admin-only. Staff submit block requests instead (see 'request' action below).
function bd_staff_unblock_types(): array {
    return [];
}

function bd_is_booking_linked(array $b): bool {
    return strpos((string)($b['reason'] ?? ''), BD_AUTO_PREFIX) === 0;
}

function bd_can_unblock(array $b, bool $is_admin): bool {
    if ($is_admin) return true;
    if (bd_is_booking_linked($b)) return false; // managed from Booking Management
    return in_array($b['block_type'] ?? '', bd_staff_unblock_types(), true);
}

function bd_unblock_denied_reason(array $b): string {
    if (bd_is_booking_linked($b)) return 'This date is held by a booking. It is released automatically when the booking is cancelled or rescheduled in Booking Management.';
    return 'Only an administrator can unblock this type of restriction.';
}

function bd_fmt_date(string $d): string {
    return date('M d, Y', strtotime($d));
}

function bd_flash_set(string $type, string $html): void {
    $_SESSION['bd_flash'] = ['type' => $type, 'msg' => $html];
}

// Redirect target after a POST (whitelisted keys only — no open redirect)
function bd_return_url(): string {
    $allowed = ['tab', 'cal_type', 'cal_id', 'filter_type', 'filter_status', 'filter_month', 'search', 'page'];
    $in = [];
    parse_str((string)($_POST['return_qs'] ?? ''), $in);
    $qs = [];
    foreach ($allowed as $k) {
        if (isset($in[$k]) && is_scalar($in[$k]) && (string)$in[$k] !== '') $qs[$k] = (string)$in[$k];
    }
    return 'blocked-dates.php' . ($qs ? '?' . http_build_query($qs) : '');
}

// JSON string of Y-m-d dates → sorted, de-duplicated, validated array
function bd_parse_dates($raw): array {
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr) || empty($arr)) throw new Exception("No dates selected.");
    $out = [];
    foreach ($arr as $d) {
        $dt = is_string($d) ? DateTime::createFromFormat('!Y-m-d', $d) : false;
        if (!$dt || $dt->format('Y-m-d') !== $d) throw new Exception("One or more dates are invalid.");
        $out[$d] = true;
    }
    $out = array_keys($out);
    sort($out);
    if (count($out) > BD_MAX_DATES) throw new Exception("You can block at most " . BD_MAX_DATES . " dates at a time.");
    return $out;
}

// Active bookings that overlap the given dates → [ 'Y-m-d' => ['REF', ...] ]
// Only reservations that hold dates conflict: confirmed / completed (same rule as
// AvailabilityService). Pending/unpaid reservations never block availability.
function bd_booking_conflicts(PDO $pdo, string $type, int $id, array $dates): array {
    if (empty($dates)) return [];
    $cfg   = bd_resources()[$type];
    $start = $cfg['b_start'];
    $end   = $cfg['b_end'] ?: $start;
    $min   = $dates[0];
    $max   = $dates[count($dates) - 1];

    $sql = "SELECT * FROM `{$cfg['b_table']}`
            WHERE `{$cfg['b_fk']}` = ?
              AND booking_status IN ('confirmed', 'completed')
              AND `{$start}` <= ? AND `{$end}` " . ($cfg['b_end'] ? '>' : '>=') . " ?";   // house: check-out day is not occupied
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$id, $max, $min]);
        $rows = $st->fetchAll();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') return []; // bookings table doesn't exist → nothing booked
        throw $e;                                  // anything else: fail closed
    }

    $conf = [];
    foreach ($rows as $r) {
        $s   = $r[$start];
        $e   = $r[$end];
        $ref = $r['reference_number'] ?? ('#' . $r['id']);
        foreach ($dates as $d) {
            // House stays occupy nights [check-in, check-out); the check-out day stays free.
            if ($d >= $s && ($cfg['b_end'] ? $d < $e : $d <= $e)) $conf[$d][] = $ref;
        }
    }
    return $conf;
}

// Classify every requested date
function bd_analyze(PDO $pdo, string $type, int $id, array $dates, string $today): array {
    $past = []; $future = [];
    foreach ($dates as $d) { if ($d < $today) $past[] = $d; else $future[] = $d; }

    $existing = [];
    $st = $pdo->prepare("SELECT block_date FROM blocked_dates WHERE item_type = ? AND item_id = ? AND block_date BETWEEN ? AND ?");
    $st->execute([$type, $id, $dates[0], $dates[count($dates) - 1]]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $existing[$d] = true;

    $conflicts = bd_booking_conflicts($pdo, $type, $id, $future);

    $blockable = []; $dups = []; $conf_out = [];
    foreach ($future as $d) {
        if (isset($conflicts[$d]))    $conf_out[$d] = $conflicts[$d];
        elseif (isset($existing[$d])) $dups[] = $d;
        else                          $blockable[] = $d;
    }

    return [
        'past'      => $past,
        'duplicates'=> $dups,
        'conflicts' => $conf_out,
        'blockable' => $blockable,
        'can_save'  => empty($past) && empty($conf_out) && !empty($blockable),
    ];
}

// Shared validation for preview + save
function bd_validate_request(PDO $pdo, array $post, string $today): array {
    $resources = bd_resources();
    $type = (string)($post['item_type'] ?? '');
    $id   = (int)($post['item_id'] ?? 0);
    if (!isset($resources[$type])) throw new Exception("Please choose a valid resource type.");
    if ($id <= 0) throw new Exception("Please choose a resource.");

    $cfg = $resources[$type];
    $st = $pdo->prepare("SELECT `{$cfg['name_col']}` FROM `{$cfg['table']}` WHERE id = ?");
    $st->execute([$id]);
    $name = $st->fetchColumn();
    if ($name === false) throw new Exception("That {$cfg['label']} no longer exists.");

    $dates = bd_parse_dates($post['dates_json'] ?? '[]');

    $reasons    = bd_reasons();
    $block_type = (string)($post['block_type'] ?? '');
    if (!isset($reasons[$block_type])) throw new Exception("Please choose a reason.");

    $note = trim((string)($post['note'] ?? ''));
    if (mb_strlen($note) > 255) throw new Exception("The note is too long (255 characters max).");
    if ($block_type === 'other' && $note === '') throw new Exception("Please describe the reason when choosing “Other”.");
    if (stripos($note, BD_AUTO_PREFIX) === 0) throw new Exception("That note is reserved for system-generated blocks.");

    return [
        'type' => $type, 'id' => $id, 'cfg' => $cfg, 'name' => (string)$name,
        'dates' => $dates, 'block_type' => $block_type, 'note' => $note,
        'analysis' => bd_analyze($pdo, $type, $id, $dates, $today),
    ];
}

$today = date('Y-m-d');

// CSRF token for this page's forms
if (empty($_SESSION['bd_csrf'])) {
    $_SESSION['bd_csrf'] = bin2hex(random_bytes(16));
}
$bd_csrf = $_SESSION['bd_csrf'];

// ============================================================
// GET DYNAMIC CONTENT
// ============================================================
$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}

$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) {}
$admin_display_name = $user_info['fullname'] ?? $user_info['username'] ?? 'User';

// ============================================================
// ✅ ACTION HANDLERS (preview / block / unblock)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bd_action'])) {
    $act = (string)$_POST['bd_action'];

    // ---------- PREVIEW (AJAX → JSON, nothing is saved) ----------
    if ($act === 'preview') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($bd_csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception("Your session expired. Please refresh the page.");
            $v = bd_validate_request($pdo, $_POST, $today);
            echo json_encode(array_merge(
                ['ok' => true, 'resource_name' => $v['name'], 'type_label' => $v['cfg']['label']],
                $v['analysis']
            ));
        } catch (PDOException $e) {
            error_log('blocked-dates preview failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'A database error occurred while checking these dates.']);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    // ---------- STAFF: REQUEST A BLOCK (nothing is blocked until an admin approves) ----------
    if ($act === 'request') {
        try {
            if (!hash_equals($bd_csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception("Your session expired. Please try again.");
            if (!$is_staff) throw new Exception("Administrators can block dates directly.");
            $v = bd_validate_request($pdo, $_POST, $today);
            $a = $v['analysis'];
            if (!empty($a['past'])) throw new Exception("Past dates can't be blocked (" . count($a['past']) . " selected).");
            if (!empty($a['conflicts'])) {
                throw new Exception("Can't request a block — active booking(s) exist on: " . implode(', ', array_map('bd_fmt_date', array_slice(array_keys($a['conflicts']), 0, 5))) . ". Cancel or reschedule the booking first.");
            }
            if (empty($a['blockable'])) throw new Exception("Nothing to request — every selected date is already blocked.");

            $dj = json_encode(array_values($a['blockable']));
            $dup = $pdo->prepare("SELECT id FROM blocked_date_requests WHERE status='pending' AND item_type=? AND item_id=? AND dates_json=? LIMIT 1");
            $dup->execute([$v['type'], $v['id'], $dj]);
            if ($dup->fetchColumn()) throw new Exception("An identical block request is already waiting for admin approval.");

            $pdo->prepare("INSERT INTO blocked_date_requests (item_type, item_id, dates_json, block_type, note, requested_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$v['type'], $v['id'], $dj, $v['block_type'], $v['note'] !== '' ? $v['note'] : null, $_SESSION['user_id']]);
            $rid = (int)$pdo->lastInsertId();

            if (class_exists('SystemLogger')) {
                SystemLogger::created($pdo, 'blocked_dates',
                    "Staff requested block — {$v['cfg']['label']} \"{$v['name']}\" · " . count($a['blockable']) . " date(s) (pending admin approval)",
                    $rid, ['item_type' => $v['type'], 'item_id' => $v['id'], 'item_name' => $v['name'], 'dates' => $a['blockable'], 'block_type' => $v['block_type'], 'request_id' => $rid]);
            }
            bd_flash_set('success', "Block request submitted for <strong>" . htmlspecialchars($v['name']) . "</strong> (" . count($a['blockable']) . " date(s)). An administrator will review it; nothing is blocked until it is approved.");
        } catch (PDOException $e) {
            error_log('blocked-dates request failed: ' . $e->getMessage());
            bd_flash_set('danger', 'A database error occurred. Your request was not saved.');
        } catch (Exception $e) {
            bd_flash_set('danger', $e->getMessage());
        }
        header('Location: ' . bd_return_url());
        exit();
    }

    // ---------- ADMIN: REJECT A REQUEST ----------
    if ($act === 'reject_request') {
        try {
            if (!hash_equals($bd_csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception("Your session expired. Please try again.");
            if (!$is_admin) throw new Exception("Only an administrator can review block requests.");
            $rid = (int)($_POST['request_id'] ?? 0);
            $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 255);
            $u = $pdo->prepare("UPDATE blocked_date_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=? AND status='pending'");
            $u->execute([$_SESSION['user_id'], $note !== '' ? $note : null, $rid]);
            if ($u->rowCount() < 1) throw new Exception("That request was already reviewed.");
            if (class_exists('SystemLogger')) {
                SystemLogger::log($pdo, 'update', 'blocked_dates', "Block request #{$rid} rejected", $rid, 'blocked_dates', null, ['status' => 'rejected', 'note' => $note]);
            }
            bd_flash_set('success', "Block request #{$rid} rejected.");
        } catch (PDOException $e) {
            error_log('blocked-dates reject failed: ' . $e->getMessage());
            bd_flash_set('danger', 'A database error occurred. Nothing was changed.');
        } catch (Exception $e) {
            bd_flash_set('danger', $e->getMessage());
        }
        header('Location: ' . bd_return_url());
        exit();
    }

    // ---------- ADMIN: APPROVE A REQUEST -> runs through the normal BLOCK path below ----------
    $bd_from_request = 0;
    if ($act === 'approve_request') {
        $act = 'approve_failed';
        try {
            if (!$is_admin) throw new Exception("Only an administrator can review block requests.");
            $rid = (int)($_POST['request_id'] ?? 0);
            $q = $pdo->prepare("SELECT * FROM blocked_date_requests WHERE id = ? AND status = 'pending'");
            $q->execute([$rid]);
            $rq = $q->fetch();
            if (!$rq) throw new Exception("That request was already reviewed.");
            $_POST['item_type']  = $rq['item_type'];
            $_POST['item_id']    = $rq['item_id'];
            $_POST['dates_json'] = $rq['dates_json'];
            $_POST['block_type'] = $rq['block_type'];
            $_POST['note']       = (string)($rq['note'] ?? '');
            $bd_from_request = $rid;
            $act = 'block';
        } catch (Exception $e) {
            bd_flash_set('danger', $e->getMessage());
            header('Location: ' . bd_return_url());
            exit();
        }
    }

    // ---------- BLOCK ----------
    if ($act === 'block') {
        try {
            if (!hash_equals($bd_csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception("Your session expired. Please try again.");
            if (!$is_admin) throw new Exception("Only an administrator can block dates directly. Please submit a block request instead.");
            $v = bd_validate_request($pdo, $_POST, $today);
            $a = $v['analysis'];

            if (!empty($a['past'])) {
                throw new Exception("Past dates can't be blocked (" . count($a['past']) . " selected).");
            }
            if (!empty($a['conflicts'])) {
                $parts = [];
                foreach ($a['conflicts'] as $d => $refs) {
                    $parts[] = bd_fmt_date($d) . ' (' . htmlspecialchars(implode(', ', array_unique($refs))) . ')';
                }
                if (class_exists('SystemLogger')) {
                    SystemLogger::log($pdo, 'error', 'blocked_dates',
                        "Block rejected — active bookings on {$v['cfg']['label']} \"{$v['name']}\": " . implode(', ', array_keys($a['conflicts'])),
                        $v['id'], 'blocked_dates', null,
                        ['item_type' => $v['type'], 'item_id' => $v['id'], 'conflicts' => $a['conflicts']], 'warning');
                }
                throw new Exception("Can't block — active booking(s) on: " . implode('; ', array_slice($parts, 0, 5)) . (count($parts) > 5 ? '…' : '') . ". Cancel or reschedule the booking first.");
            }
            if (empty($a['blockable'])) {
                throw new Exception("Nothing to block — every selected date is already blocked.");
            }

            $inserted = [];
            $raced    = 0;
            $pdo->beginTransaction();
            try {
                // Same lock as payment confirmation / rebooking: the item row is locked,
                // so a fee confirmation for this item cannot slip in between check and insert.
                AvailabilityService::lockItem($pdo, $v['type'], $v['id']);
                $again = bd_booking_conflicts($pdo, $v['type'], $v['id'], $a['blockable']);
                if (!empty($again)) throw new Exception("A booking was just made on one of these dates. Please review and try again.");

                $ins = $pdo->prepare("INSERT INTO blocked_dates (item_type, item_id, block_date, reason, block_type, blocked_by) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($a['blockable'] as $d) {
                    try {
                        $ins->execute([$v['type'], $v['id'], $d, $v['note'] !== '' ? $v['note'] : null, $v['block_type'], $_SESSION['user_id']]);
                        $inserted[] = $d;
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') { $raced++; continue; } // duplicate (unique_block)
                        throw $e;
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $skipped = count($a['duplicates']) + $raced;
            $n = count($inserted);
            $range = $n > 0 ? (bd_fmt_date($inserted[0]) . ($n > 1 ? ' – ' . bd_fmt_date($inserted[$n - 1]) : '')) : '';

            if (class_exists('SystemLogger')) {
                $reasons = bd_reasons();
                SystemLogger::created($pdo, 'blocked_dates',
                    "Blocked {$v['cfg']['label']} \"{$v['name']}\" — {$n} date(s)" . ($range ? " ({$range})" : '') .
                    " · " . $reasons[$v['block_type']]['label'] . ($skipped ? " · {$skipped} already blocked" : ''),
                    $v['id'],
                    [
                        'item_type' => $v['type'], 'item_id' => $v['id'], 'item_name' => $v['name'],
                        'dates' => $inserted, 'block_type' => $v['block_type'], 'note' => $v['note'],
                        'skipped_duplicates' => $skipped,
                    ]);
            }

            if ($bd_from_request > 0) {
                $pdo->prepare("UPDATE blocked_date_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'")
                    ->execute([$_SESSION['user_id'], $bd_from_request]);
                if (class_exists('SystemLogger')) {
                    SystemLogger::log($pdo, 'update', 'blocked_dates', "Block request #{$bd_from_request} approved ({$n} date(s) blocked)", $bd_from_request, 'blocked_dates', null, ['status' => 'approved']);
                }
            }

            $msg = "Blocked <strong>" . htmlspecialchars($v['name']) . "</strong> for <strong>{$n}</strong> date(s)";
            if ($range) $msg .= " (" . htmlspecialchars($range) . ")";
            $msg .= ". These dates are now unavailable for online booking.";
            if ($skipped > 0) $msg .= " <span style='color:#b45309;'>{$skipped} date(s) were already blocked and skipped.</span>";
            bd_flash_set('success', $msg);
        } catch (PDOException $e) {
            error_log('blocked-dates block failed: ' . $e->getMessage());
            bd_flash_set('danger', 'A database error occurred. Nothing was saved.');
        } catch (Exception $e) {
            bd_flash_set('danger', ($e->getMessage() === '' ? 'Something went wrong.' : $e->getMessage()));
        }
        header('Location: ' . bd_return_url());
        exit();
    }

    // ---------- UNBLOCK ----------
    if ($act === 'unblock') {
        try {
            if (!hash_equals($bd_csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception("Your session expired. Please try again.");
            $id = (int)($_POST['block_id'] ?? 0);
            if ($id <= 0) throw new Exception("Invalid block ID.");

            $stmt = $pdo->prepare("SELECT * FROM blocked_dates WHERE id = ?");
            $stmt->execute([$id]);
            $block = $stmt->fetch();
            if (!$block) throw new Exception("That blocked date no longer exists (it may already have been unblocked).");

            if (!bd_can_unblock($block, $is_admin)) {
                if (class_exists('SystemLogger')) {
                    SystemLogger::log($pdo, 'error', 'blocked_dates',
                        "Unblock denied for {$block['item_type']} #{$block['item_id']} on {$block['block_date']} ({$block['block_type']})",
                        $id, 'blocked_dates', $block, null, 'warning');
                }
                throw new Exception(bd_unblock_denied_reason($block));
            }

            $cfgs = bd_resources();
            $label = $cfgs[$block['item_type']]['label'] ?? ucfirst($block['item_type']);
            $rname = '';
            if (isset($cfgs[$block['item_type']])) {
                $c = $cfgs[$block['item_type']];
                $q = $pdo->prepare("SELECT `{$c['name_col']}` FROM `{$c['table']}` WHERE id = ?");
                $q->execute([$block['item_id']]);
                $rname = (string)$q->fetchColumn();
            }

            $pdo->prepare("DELETE FROM blocked_dates WHERE id = ?")->execute([$id]);

            if (class_exists('SystemLogger')) {
                SystemLogger::deleted($pdo, 'blocked_dates',
                    "Unblocked {$label}" . ($rname !== '' ? " \"{$rname}\"" : " #{$block['item_id']}") . " on {$block['block_date']}",
                    $id, array_merge($block, ['item_name' => $rname]));
            }

            bd_flash_set('success', "Date <strong>" . bd_fmt_date($block['block_date']) . "</strong> unblocked" .
                ($rname !== '' ? " for <strong>" . htmlspecialchars($rname) . "</strong>" : '') . ". It is available for online booking again.");
        } catch (PDOException $e) {
            error_log('blocked-dates unblock failed: ' . $e->getMessage());
            bd_flash_set('danger', 'A database error occurred. Nothing was changed.');
        } catch (Exception $e) {
            bd_flash_set('danger', $e->getMessage());
        }
        header('Location: ' . bd_return_url());
        exit();
    }
}

// Flash message (set by the handlers above, shown once after redirect)
$success = null; $error = null;
if (!empty($_SESSION['bd_flash'])) {
    if ($_SESSION['bd_flash']['type'] === 'success') $success = $_SESSION['bd_flash']['msg'];
    else $error = $_SESSION['bd_flash']['msg'];
    unset($_SESSION['bd_flash']);
}

// ============================================================
// HANDLE LOGOUT
// ============================================================
if(isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth', "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out", (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

// ============================================================
// FILTERS
// ============================================================
// ---- Block requests (admin: pending queue · staff: own recent requests) ----
$block_requests = [];
try {
    if ($is_admin) {
        $rq = $pdo->query("SELECT r.*, u.fullname AS requester_name, u.username AS requester_username FROM blocked_date_requests r LEFT JOIN users u ON u.id = r.requested_by WHERE r.status = 'pending' ORDER BY r.requested_at ASC LIMIT 50");
    } else {
        $rq = $pdo->prepare("SELECT r.*, u.fullname AS requester_name, u.username AS requester_username FROM blocked_date_requests r LEFT JOIN users u ON u.id = r.requested_by WHERE r.requested_by = ? ORDER BY r.requested_at DESC LIMIT 10");
        $rq->execute([$_SESSION['user_id']]);
    }
    $block_requests = $rq->fetchAll();
    $bd_cfgs = bd_resources();
    foreach ($block_requests as &$__r) {
        $__r['item_name'] = 'Unknown resource';
        if (isset($bd_cfgs[$__r['item_type']])) {
            $c = $bd_cfgs[$__r['item_type']];
            $nq = $pdo->prepare("SELECT `{$c['name_col']}` FROM `{$c['table']}` WHERE id = ?");
            $nq->execute([$__r['item_id']]);
            $nm = $nq->fetchColumn();
            if ($nm !== false) $__r['item_name'] = (string)$nm;
        }
        $__r['dates'] = json_decode((string)$__r['dates_json'], true) ?: [];
    }
    unset($__r);
} catch (PDOException $e) { $block_requests = []; }

$type_keys     = array_keys(bd_resources());
$current_tab = 'list';
$filter_type   = in_array($_GET['filter_type'] ?? 'all', array_merge(['all'], $type_keys), true) ? ($_GET['filter_type'] ?? 'all') : 'all';
$filter_status = in_array($_GET['filter_status'] ?? 'current', ['current', 'past', 'all'], true) ? ($_GET['filter_status'] ?? 'current') : 'current';
$filter_month  = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['filter_month'] ?? '')) ? $_GET['filter_month'] : '';
$search        = trim((string)($_GET['search'] ?? ''));
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;


// Resources, loaded dynamically per type
$resource_lists = [];
foreach (bd_resources() as $t => $cfg) {
    try {
        $resource_lists[$t] = $pdo->query("SELECT id, `{$cfg['name_col']}` AS name FROM `{$cfg['table']}` ORDER BY `{$cfg['name_col']}`")->fetchAll();
    } catch (PDOException $e) {
        $resource_lists[$t] = [];
    }
}

// Shared SQL fragments: resource name + who blocked it
$bd_joins = ''; $bd_name_parts = [];
foreach (bd_resources() as $t => $cfg) {
    $alias = "r_{$t}";
    $bd_joins .= " LEFT JOIN `{$cfg['table']}` {$alias} ON bd.item_type = '{$t}' AND {$alias}.id = bd.item_id";
    $bd_name_parts[] = "{$alias}.`{$cfg['name_col']}`";
}
$bd_joins .= " LEFT JOIN users bu ON bu.id = bd.blocked_by";
$bd_name_expr = 'COALESCE(' . implode(', ', $bd_name_parts) . ')';

// ============================================================
// BLOCKED DATES LIST (filtered + paginated)
// ============================================================
$where = []; $params = [];
if ($filter_type !== 'all')      { $where[] = "bd.item_type = ?"; $params[] = $filter_type; }
if ($filter_status === 'current'){ $where[] = "bd.block_date >= ?"; $params[] = $today; }
if ($filter_status === 'past')   { $where[] = "bd.block_date < ?";  $params[] = $today; }
if ($filter_month)               { $where[] = "DATE_FORMAT(bd.block_date, '%Y-%m') = ?"; $params[] = $filter_month; }
if ($search !== '') {
    $where[] = "(bd.reason LIKE ? OR bd.block_date LIKE ? OR {$bd_name_expr} LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

$stmt = $pdo->prepare("SELECT COUNT(*) FROM blocked_dates bd {$bd_joins} {$where_sql}");
$stmt->execute($params);
$list_total  = (int)$stmt->fetchColumn();
$total_pages = max(1, (int)ceil($list_total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;
$order_dir   = ($filter_status === 'past') ? 'DESC' : 'ASC';

$stmt = $pdo->prepare("SELECT bd.*, {$bd_name_expr} AS item_name, COALESCE(bu.fullname, bu.username) AS blocked_by_name
    FROM blocked_dates bd {$bd_joins}
    {$where_sql}
    ORDER BY bd.block_date {$order_dir}, bd.item_type, bd.item_id
    LIMIT {$per_page} OFFSET {$offset}");
$stmt->execute($params);
$blocked_dates = $stmt->fetchAll();

// ============================================================
// OVERVIEW
// ============================================================
$total_blocks    = (int)$pdo->query("SELECT COUNT(*) FROM blocked_dates")->fetchColumn();
$upcoming_blocks = 0; $next7_blocks = 0; $resources_affected = 0;
$st = $pdo->prepare("SELECT COUNT(*) FROM blocked_dates WHERE block_date >= ?");
$st->execute([$today]);
$upcoming_blocks = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM blocked_dates WHERE block_date BETWEEN ? AND ?");
$st->execute([$today, date('Y-m-d', strtotime('+6 days'))]);
$next7_blocks = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(DISTINCT item_type, item_id) FROM blocked_dates WHERE block_date >= ?");
$st->execute([$today]);
$resources_affected = (int)$st->fetchColumn();

$by_type = [];
foreach ($pdo->query("SELECT item_type, COUNT(*) c FROM blocked_dates GROUP BY item_type")->fetchAll() as $r) {
    $by_type[$r['item_type']] = (int)$r['c'];
}

// Recent restrictions: one row per blocking action (same resource + reason + minute + user)
$recent_blocks = [];
try {
    $recent_blocks = $pdo->query("SELECT bd.item_type, bd.item_id, bd.block_type,
            MIN(bd.block_date) AS first_date, MAX(bd.block_date) AS last_date, COUNT(*) AS day_count,
            MAX(bd.reason) AS reason, MAX(bd.created_at) AS created_at,
            MAX({$bd_name_expr}) AS item_name, MAX(COALESCE(bu.fullname, bu.username)) AS blocked_by_name
        FROM blocked_dates bd {$bd_joins}
        GROUP BY bd.item_type, bd.item_id, bd.block_type, bd.blocked_by, DATE_FORMAT(bd.created_at, '%Y-%m-%d %H:%i')
        ORDER BY MAX(bd.created_at) DESC, MAX(bd.id) DESC
        LIMIT 5")->fetchAll();
} catch (PDOException $e) {
    $recent_blocks = [];
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blocked Dates - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; }

        .app-container { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar { width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2); padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; border-right: 2px solid rgba(77, 166, 217, 0.15); flex-shrink: 0; z-index: 100; transition: transform 0.3s ease; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }
        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); overflow: hidden; }
        .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; }
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; }
        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.2); }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link .nav-badge { margin-left: auto; background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 99; opacity: 0; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; align-items: center; justify-content: center; border: 1px solid rgba(77, 166, 217, 0.2); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; }

        @media (max-width: 1024px) {
            .sidebar { position: fixed; transform: translateX(-100%); z-index: 1000; }
            .sidebar.open { transform: translateX(0); }
            .menu-toggle { display: flex; }
            .main-content { padding: 70px 16px 20px !important; }
            .sidebar-close-btn { display: flex; }
        }

        /* MAIN */
        .main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11, 36, 71, 0.1); flex-wrap: wrap; gap: 10px; }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }
        .user-profile { display: flex; align-items: center; gap: 15px; }
        .user-profile .avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 18px; border: 2px solid rgba(77, 166, 217, 0.2); }
        .user-profile .user-name { color: #0B2447; font-weight: 600; font-size: 14px; }
        .user-profile .user-role { color: #4a6a8c; font-size: 12px; text-align: right; }

        .page-title-banner { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; box-shadow: 0 10px 30px rgba(11, 36, 71, 0.15); }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner h1 i { margin-right: 10px; opacity: 0.9; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }
        .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #4DA6D9; border-radius: 16px; padding: 22px 20px; color: white; box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2); border: 1px solid rgba(255,255,255,0.15); }
        .stat-icon { width: 44px; height: 44px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; border: 1px solid rgba(255,255,255,0.1); margin-bottom: 10px; }
        .stat-number { font-size: 26px; font-weight: 700; color: white; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 12px; font-weight: 500; margin-top: 2px; }

/* BLOCKED DATE STAT CARD - MATCH ADMIN STYLE */

.blocked-stats .stat-card {
    background: white !important;
    min-height: 170px;
    padding: 22px 20px;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.06);
    border: 1px solid #e8f0fe;
    color: #0B2447;
}


.blocked-stats .stat-icon {
    margin-bottom: 14px;
    color: white;
}


/* Icon colors */

.blocked-stats .stat-total .stat-icon {
    background: rgba(11,36,71,0.15);
    color: #0B2447;
}

.blocked-stats .stat-house .stat-icon {
    background: rgba(14,165,233,0.15);
    color: #0284c7;
}

.blocked-stats .stat-tour .stat-icon {
    background: rgba(16,185,129,0.15);
    color: #10b981;
}

.blocked-stats .stat-food .stat-icon {
    background: rgba(245,158,11,0.15);
    color: #f59e0b;
}

.blocked-stats .stat-upcoming .stat-icon {
    background: rgba(139,92,246,0.15);
    color: #8b5cf6;
}


/* Text */

.blocked-stats .stat-number {
    color: #0B2447;
    font-size: 30px;
    font-weight: 800;
}


.blocked-stats .stat-label {
    color: #0B2447;
    font-size: 14px;
    font-weight: 700;
}


.blocked-stats .stat-description {
    color: #64748b;
    font-size: 12px;
    margin-top: 6px;
}
        
        /* ALERTS */
        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }

        /* CARDS */
        .card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); margin-bottom: 30px; border: 1px solid #e8f0fe; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 15px; }
        .card-header h2 { font-size: 17px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
        .card-header h2 i { color: #4DA6D9; background: #eef2ff; padding: 8px; border-radius: 8px; font-size: 14px; }

        /* FORMS */
        .form-label { display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-control, .form-select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .form-grid .full-width { grid-column: 1 / -1; }

        .btn-primary { padding: 12px 24px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.4); background: #e6a800; }

        .btn-secondary { padding: 10px 20px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }

        .btn-danger-sm { padding: 5px 12px; background: #ef4444; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 11px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .btn-danger-sm:hover { background: #dc2626; transform: translateY(-1px); }

        .btn-success-sm { padding: 5px 12px; background: #10b981; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 11px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .btn-success-sm:hover { background: #059669; transform: translateY(-1px); }

        /* TABLE */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th { text-align: left; padding: 10px 12px; background: #f8fafc; color: #0B2447; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; }
        td { padding: 10px 12px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 13px; vertical-align: middle; }
        tr:hover { background: #f8fafc; }

        /* BADGES */
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; white-space: nowrap; }
        .badge-walk_in { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .badge-maintenance { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
        .badge-special_occasion { background: #f3e8ff; color: #6b21a8; border: 1px solid #c4b5fd; }
        .badge-owner_use { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .badge-other { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-house { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
        .badge-tour { background: #e6f7e6; color: #10b981; border: 1px solid #a7f3d0; }
        .badge-food { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }

        /* EMPTY STATE */
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 60px; color: #cbd5e1; display: block; margin-bottom: 20px; }
        .empty-state h3 { color: #1e293b; margin-bottom: 10px; }

        /* FILTER BAR */
        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .filter-bar select, .filter-bar input { padding: 8px 12px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 13px; background: white; }
        .filter-bar select:focus, .filter-bar input:focus { outline: none; border-color: #4DA6D9; }

        /* TAB SWITCHER */
        .tab-switcher { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn { padding: 10px 20px; border-radius: 10px; background: white; border: 2px solid #e8f0fe; cursor: pointer; font-weight: 600; font-size: 13px; color: #4a6a8c; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.2s; }
        .tab-btn:hover { background: #f0f7fb; border-color: #4DA6D9; }
        .tab-btn.active { background: #4DA6D9; color: white; border-color: #4DA6D9; }
        .tab-btn.calendar-tab.active { background: #8b5cf6; border-color: #8b5cf6; }

     
        .selection-toolbar .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .selection-toolbar .btn-clear-selection { background: rgba(255,255,255,0.15); color: white; }
        .selection-toolbar .btn-clear-selection:hover { background: rgba(255,255,255,0.25); }
        .selection-toolbar .btn-block-selected { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4); }
        .selection-toolbar .btn-block-selected:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.6); }

       

        /* MODAL */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 3000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; padding: 28px; animation: modalSlideIn 0.3s ease; box-shadow: 0 30px 60px rgba(0,0,0,0.3); }
        @keyframes modalSlideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; }
        .modal-header h3 { font-size: 18px; font-weight: 700; color: #0B2447; display: flex; align-items: center; gap: 10px; }
        .modal-header h3 i { color: #4DA6D9; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; transition: color 0.3s; background: none; border: none; padding: 0 10px; line-height: 1; }
        .modal-header .close:hover { color: #ef4444; }

        .modal-info-box { background: linear-gradient(135deg, #f0f7fb 0%, #e8f4fc 100%); border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; border-left: 4px solid #4DA6D9; }
        .modal-info-box .info-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
        .modal-info-box .info-row .info-label { color: #64748b; font-weight: 500; }
        .modal-info-box .info-row .info-value { color: #0B2447; font-weight: 700; }

        .block-type-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 15px; }
        .block-type-option { position: relative; cursor: pointer; }
        .block-type-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        .block-type-option-label { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 12.5px; font-weight: 600; color: #475569; transition: all 0.2s; }
        .block-type-option-label i { color: #94a3b8; font-size: 14px; }
        .block-type-option input[type="radio"]:checked + .block-type-option-label { background: #e0f2fe; border-color: #0ea5e9; color: #0369a1; }
        .block-type-option input[type="radio"]:checked + .block-type-option-label i { color: #0ea5e9; }
        .block-type-option:hover .block-type-option-label { border-color: #0ea5e9; }

        /* SELECTED DATE CHIP */
        .selected-date-chip { display: inline-flex; align-items: center; gap: 6px; background: #dbeafe; color: #0369a1; padding: 5px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; border: 1px solid #93c5fd; transition: all 0.2s; }
        .selected-date-chip:hover { background: #bfdbfe; }
        .selected-date-chip .remove-chip { background: #0369a1; color: white; width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; cursor: pointer; border: none; line-height: 1; padding: 0; }
        .selected-date-chip .remove-chip:hover { background: #ef4444; }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .top-bar { flex-direction: column; align-items: flex-start; }
            .page-title-banner { padding: 20px; }
            .page-title-banner h1 { font-size: 22px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px 14px; }
            .stat-number { font-size: 20px; }
            .card { padding: 18px 15px; }
            .form-grid { grid-template-columns: 1fr; }
            table { min-width: 600px; }
            th, td { font-size: 11px; padding: 8px; }
            .block-type-grid { grid-template-columns: 1fr; }

            #itemCalendar { min-height: 400px; }
            .fc-daygrid-day { min-height: 60px !important; }
            .fc-daygrid-day-frame { min-height: 60px !important; }
            .fc-daygrid-day-number { font-size: 12px !important; padding: 4px 5px !important; }
            .fc-daygrid-day.selected-date .fc-daygrid-day-number { width: 22px !important; height: 22px !important; margin: 2px !important; }
            .fc-daygrid-day-events { margin-top: 22px !important; }
            .fc-toolbar-title { font-size: 16px !important; }
        }
        @media (max-width: 480px) {
            .main-content { padding: 60px 12px 16px !important; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; }
            .sidebar { width: 85%; max-width: 300px; }
            .stats-grid { grid-template-columns: 1fr; }
        }
            .nav-link .nav-badge.blocked { background: rgba(100, 116, 139, 0.3); color: #cbd5e1; }

        /* =====================================================
           RESERVATION-STYLE WORKFLOW (overview / list / wizard)
           ===================================================== */
        .page-title-banner.banner-flex { display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap; }
        .btn-block-availability { display: inline-flex; align-items: center; gap: 10px; padding: 13px 24px; background: white; color: #0B2447; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 6px 20px rgba(0,0,0,0.18); transition: all 0.2s; font-family: inherit; }
        .btn-block-availability i { color: #4DA6D9; }
        .btn-block-availability:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,0,0,0.25); }

        .section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #4a6a8c; margin: 0 0 12px; }
        .blocked-stats { margin-bottom: 22px; }
        .blocked-stats .stat-card { min-height: 0; }

        .overview-grid { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 8px; }

        .recent-list { list-style: none; margin: 0; padding: 0; }
        .recent-item { display: flex; align-items: center; gap: 14px; padding: 12px 0; border-bottom: 1px solid #e8f0fe; }
        .recent-item:last-child { border-bottom: none; padding-bottom: 0; }
        .recent-item:first-child { padding-top: 0; }
        .recent-icon { width: 40px; height: 40px; border-radius: 12px; background: #eef6fc; color: #4DA6D9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; }
        .recent-body { flex: 1; min-width: 0; }
        .recent-body .r-title { font-weight: 700; color: #0B2447; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .recent-body .r-sub { color: #64748b; font-size: 12px; margin-top: 2px; }
        .recent-meta { text-align: right; font-size: 11px; color: #94a3b8; flex-shrink: 0; }

        .tab-btn.calendar-tab.active { background: #4DA6D9; border-color: #4DA6D9; }

        /* status pills */
        .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .status-pill::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .status-upcoming { background: #e0f2fe; color: #0369a1; }
        .status-today { background: #dcfce7; color: #15803d; }
        .status-expired { background: #f1f5f9; color: #64748b; }
        .tag-linked { display: inline-flex; align-items: center; gap: 5px; margin-left: 6px; padding: 3px 9px; border-radius: 20px; font-size: 10px; font-weight: 700; background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; white-space: nowrap; }
        .cell-resource { display: flex; flex-direction: column; gap: 5px; align-items: flex-start; }
        .cell-resource .r-name { font-weight: 700; color: #0B2447; font-size: 14px; }
        .cell-reason { display: flex; flex-direction: column; gap: 5px; align-items: flex-start; }
        .cell-reason .r-note { font-size: 12.5px; color: #475569; }
        .cell-reason .r-meta { font-size: 11px; color: #94a3b8; }
        .action-locked { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: #94a3b8; cursor: help; }
        .btn-unblock { padding: 7px 14px; background: white; color: #0369a1; border: 2px solid #bae6fd; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit; }
        .btn-unblock:hover { background: #e0f2fe; border-color: #4DA6D9; }

        .pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding-top: 18px; margin-top: 8px; border-top: 2px solid #e8f0fe; font-size: 13px; color: #64748b; }
        .pagination .pages { display: flex; gap: 6px; }
        .pagination a, .pagination span.cur { padding: 7px 13px; border-radius: 8px; border: 2px solid #e8f0fe; background: white; color: #4a6a8c; font-weight: 600; text-decoration: none; }
        .pagination a:hover { border-color: #4DA6D9; background: #f0f7fb; }
        .pagination span.cur { background: #4DA6D9; border-color: #4DA6D9; color: white; }

        /* ---------- wizard ---------- */
        .modal-content.wizard { max-width: 660px; padding: 0; overflow: hidden; display: flex; flex-direction: column; }
        .wizard-head { padding: 22px 28px 0; }
        .wizard-head .modal-header { margin-bottom: 18px; }
        .stepper { display: flex; align-items: center; gap: 0; padding: 0 28px 18px; border-bottom: 2px solid #e8f0fe; }
        .stepper .st { display: flex; align-items: center; gap: 8px; flex: 1; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.3px; }
        .stepper .st:last-child { flex: 0 0 auto; }
        .stepper .st .dot { width: 28px; height: 28px; border-radius: 50%; border: 2px solid #cbd5e1; background: white; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; color: #94a3b8; }
        .stepper .st .bar { flex: 1; height: 2px; background: #e2e8f0; margin: 0 8px; }
        .stepper .st.active { color: #0B2447; }
        .stepper .st.active .dot { border-color: #4DA6D9; background: #4DA6D9; color: white; }
        .stepper .st.done .dot { border-color: #4DA6D9; background: #e0f2fe; color: #0369a1; }
        .stepper .st.done .bar { background: #4DA6D9; }
        .stepper .st .lbl { white-space: nowrap; }
        .wizard-body { padding: 24px 28px; overflow-y: auto; min-height: 300px; max-height: 58vh; }
        .wizard-foot { display: flex; justify-content: space-between; gap: 10px; padding: 16px 28px; background: #f8fafc; border-top: 2px solid #e8f0fe; }
        .wizard-foot .spacer { flex: 1; }
        @media (max-height: 640px) { .wizard-body { min-height: 0; max-height: none; flex: 1 1 auto; } .wizard-head { padding-top: 14px; } .stepper { padding-bottom: 12px; } .wizard-foot { padding-top: 10px; padding-bottom: 10px; } }
        .btn-blue { padding: 11px 22px; background: #4DA6D9; color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; font-family: inherit; }
        .btn-blue:hover:not(:disabled) { background: #3a8bbf; transform: translateY(-1px); }
        .btn-blue:disabled { background: #cbd5e1; cursor: not-allowed; }
        .btn-navy { background: #0B2447; }
        .btn-navy:hover:not(:disabled) { background: #0B3D91; }
        .btn-ghost { padding: 11px 20px; background: white; color: #475569; border: 2px solid #e2e8f0; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; }
        .btn-ghost:hover { background: #f1f5f9; }

        .step-title { font-size: 17px; font-weight: 700; color: #0B2447; margin-bottom: 4px; }
        .step-sub { font-size: 13px; color: #64748b; margin-bottom: 18px; }
        .choice-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .choice-card { background: white; border: 2px solid #e2e8f0; border-radius: 14px; padding: 20px 12px; text-align: center; cursor: pointer; transition: all 0.2s; font-family: inherit; }
        .choice-card i { font-size: 26px; color: #4DA6D9; display: block; margin-bottom: 10px; }
        .choice-card .c-name { font-weight: 700; color: #0B2447; font-size: 15px; }
        .choice-card .c-count { font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .choice-card:hover { border-color: #4DA6D9; background: #f0f7fb; }
        .choice-card.selected { border-color: #4DA6D9; background: #e0f2fe; box-shadow: 0 0 0 3px rgba(77,166,217,0.15); }

        .resource-search { margin-bottom: 12px; }
        .resource-list { display: flex; flex-direction: column; gap: 8px; max-height: 280px; overflow-y: auto; padding-right: 4px; }
        .resource-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 2px solid #e2e8f0; border-radius: 12px; background: white; cursor: pointer; text-align: left; font-family: inherit; font-size: 14px; font-weight: 600; color: #0B2447; transition: all 0.2s; }
        .resource-row i { color: #4DA6D9; width: 20px; text-align: center; }
        .resource-row:hover { border-color: #4DA6D9; background: #f0f7fb; }
        .resource-row.selected { border-color: #4DA6D9; background: #e0f2fe; }
        .resource-row .check { margin-left: auto; color: #4DA6D9; opacity: 0; }
        .resource-row.selected .check { opacity: 1; }

        .mode-toggle { display: inline-flex; background: #f1f5f9; border-radius: 10px; padding: 4px; margin-bottom: 18px; gap: 4px; }
        .mode-toggle button { padding: 8px 18px; border: none; background: transparent; border-radius: 8px; font-weight: 600; font-size: 13px; color: #64748b; cursor: pointer; font-family: inherit; }
        .mode-toggle button.active { background: white; color: #0369a1; box-shadow: 0 1px 4px rgba(0,0,0,0.12); }
        .date-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 14px; }
        .field-help { font-size: 12px; color: #64748b; margin-top: 6px; }
        .field-error { font-size: 12.5px; color: #b91c1c; background: #fee2e2; border-left: 4px solid #ef4444; border-radius: 8px; padding: 9px 12px; margin-top: 12px; }
        .days-pill { display: inline-flex; align-items: center; gap: 8px; background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 13px; padding: 7px 14px; border-radius: 20px; }

        .summary-box { background: #f0f7fb; border-left: 4px solid #4DA6D9; border-radius: 12px; padding: 14px 18px; margin-bottom: 14px; }
        .summary-box .info-row { display: flex; justify-content: space-between; gap: 16px; padding: 5px 0; font-size: 13px; }
        .summary-box .info-label { color: #64748b; font-weight: 500; flex-shrink: 0; }
        .summary-box .info-value { color: #0B2447; font-weight: 700; text-align: right; word-break: break-word; }
        .notice { border-radius: 10px; padding: 12px 15px; font-size: 13px; margin-bottom: 12px; border-left: 4px solid; }
        .notice.ok { background: #e0f2fe; color: #075985; border-color: #4DA6D9; }
        .notice.warn { background: #fffbeb; color: #92400e; border-color: #f59e0b; }
        .notice.bad { background: #fee2e2; color: #991b1b; border-color: #ef4444; }
        .notice .chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
        .notice .chip { background: rgba(255,255,255,0.75); border-radius: 6px; padding: 3px 8px; font-size: 11.5px; font-weight: 600; }
        .loading-line { display: flex; align-items: center; gap: 10px; color: #64748b; font-size: 13px; padding: 20px 0; }

        .modal-content.small { max-width: 480px; }

        @media (max-width: 600px) {
            .stepper .st .lbl { display: none; }
            .choice-grid { grid-template-columns: 1fr; }
            .wizard-head, .wizard-body, .wizard-foot, .stepper { padding-left: 18px; padding-right: 18px; }
            .btn-block-availability { width: 100%; justify-content: center; }
            .recent-meta { display: none; }
        }

        /* =====================================================
           MOBILE COMPACT LAYOUT (CSS only; desktop untouched)
           ===================================================== */
        @media (max-width: 768px) {
            .main-content { padding-top: 12px !important; }
            .main-content .top-bar { flex-direction: row; flex-wrap: nowrap; align-items: center; justify-content: space-between; gap: 10px; min-height: 48px; margin-bottom: 12px; padding: 0 0 10px 54px; }
            .main-content .top-bar .page-title { flex: 1 1 auto; min-width: 0; }
            .main-content .top-bar .page-title h1 { font-size: 17px; line-height: 1.2; }
            .main-content .top-bar .page-title p { display: none; }
            .main-content .top-bar .user-profile { flex: 0 0 auto; flex-direction: row-reverse; gap: 8px; margin-left: auto; max-width: 55%; }
            .main-content .top-bar .user-profile > div[style] { line-height: 1.2; }
            .main-content .top-bar .user-profile .user-name { font-size: 12px; max-width: 110px; }
            .main-content .top-bar .user-profile .user-role { font-size: 11px; }
            .main-content .top-bar .user-profile .avatar { width: 34px; height: 34px; font-size: 14px; }

            .main-content .page-title-banner { padding: 16px; margin-bottom: 14px; border-radius: 16px; gap: 12px; }
            .main-content .page-title-banner h1 { font-size: 19px; margin-bottom: 0; }
            .main-content .page-title-banner .underline { margin-top: 6px; height: 2px; }
            .main-content .page-title-banner p { font-size: 13px; line-height: 1.4; margin-top: 6px !important; }
            .main-content .page-title-banner .btn-block-availability { width: 100%; justify-content: center; padding: 11px 16px; font-size: 13.5px; }

            .section-title { margin-bottom: 8px; font-size: 12px; }
            .main-content .blocked-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
            .main-content .blocked-stats .stat-card { display: grid; grid-template-columns: 34px minmax(0, 1fr); column-gap: 10px; row-gap: 0; align-items: center; min-height: 0; padding: 12px; border-radius: 14px; }
            .main-content .blocked-stats .stat-icon { grid-row: 1 / span 2; width: 34px; height: 34px; margin: 0; border-radius: 10px; font-size: 15px; }
            .main-content .blocked-stats .stat-number { grid-column: 2; font-size: 20px; line-height: 1.1; }
            .main-content .blocked-stats .stat-label { grid-column: 2; font-size: 11.5px; line-height: 1.25; margin-top: 1px; }
            .main-content .blocked-stats .stat-description { grid-column: 1 / -1; font-size: 10.5px; line-height: 1.3; margin-top: 6px; }
        }
        @media (max-width: 480px) {
            .main-content { padding-top: 10px !important; }
            .main-content .top-bar { padding-left: 50px; }
            .main-content .top-bar .page-title h1 { font-size: 15.5px; }
            .main-content .top-bar .user-profile .user-name { max-width: 80px; }
            .main-content .blocked-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
    <link rel="stylesheet" href="assets/css/admin-responsive.css">
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<div class="app-container">
    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-header-top">
                <a href="admin-dashboard.php" class="logo">
                    <div class="logo-icon">
                        <?php if($nav_logo_exists): ?>
                            <img src="<?php echo htmlspecialchars($nav_logo); ?>?<?php echo time(); ?>" alt="Logo">
                        <?php else: ?>
                            <i class="fas fa-umbrella-beach"></i>
                        <?php endif; ?>
                    </div>
                    <div class="logo-text">
                        <span class="main">Hundred Islands</span>
                        <span class="sub">Reservation System</span>
                    </div>
                </a>
                <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Close menu">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="role-badge <?php echo $is_admin ? 'admin' : 'staff'; ?>">
                <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                <?php echo $is_admin ? 'Administrator' : 'Staff'; ?>
            </div>
        </div>

        <ul class="nav-menu">
            <li class="nav-item"><a href="admin-dashboard.php" class="nav-link"><i class="fas fa-th-large"></i><span>Dashboard</span></a></li>
            <?php if($is_admin): ?>
            <li class="nav-item"><a href="user-management.php" class="nav-link"><i class="fas fa-users"></i><span>User Management</span></a></li>
            <?php endif; ?>
            <li class="nav-item"><a href="house-dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>House Management</span></a></li>
            <li class="nav-item"><a href="tour-dashboard.php" class="nav-link"><i class="fas fa-umbrella-beach"></i><span>Tour Management</span></a></li>
            <li class="nav-item"><a href="activities-dashboard.php" class="nav-link"><i class="fas fa-water"></i><span>Activities Management</span></a></li>
            <li class="nav-item"><a href="food-dashboard.php" class="nav-link"><i class="fas fa-utensils"></i><span>Food Management</span></a></li>
            <li class="nav-item"><a href="booking-management.php" class="nav-link"><i class="fas fa-calendar-check"></i><span>Booking Management</span><?php if($sidebar_pending_bookings > 0): ?><span class="nav-badge" style="background: rgba(245,158,11,0.2); color:#f59e0b;"><?php echo $sidebar_pending_bookings; ?></span><?php endif; ?></a></li>
<li class="nav-item">
    <a href="blocked-dates.php" class="nav-link active">
        <i class="fas fa-ban"></i>
        <span>Blocked Dates</span>
    </a>
</li>            <li class="nav-item"><a href="reviews-management.php" class="nav-link"><i class="fas fa-star"></i><span>Reviews Management</span><?php if($sidebar_pending_reviews > 0): ?><span class="nav-badge" style="background: rgba(16,185,129,0.2); color:#10b981;"><?php echo $sidebar_pending_reviews; ?></span><?php endif; ?></a></li>
            <?php if(!empty($is_admin)): ?><li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Sales Report</span></a></li><?php endif; ?>
            <?php if($is_admin): ?>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>
            <li class="nav-item"><a href="system-logs.php" class="nav-link"><i class="fas fa-history"></i><span>System Logs</span><?php if($sidebar_failed_logs > 0): ?><span class="nav-badge"><?php echo $sidebar_failed_logs; ?></span><?php endif; ?></a></li>
            <?php endif; ?>
            <div class="nav-divider"></div>
            <li class="nav-item"><a href="admin-profile.php" class="nav-link"><i class="fas fa-user-circle"></i><span>My Profile</span></a></li>
            <li class="nav-item"><a href="?logout=1" class="nav-link" onclick="return confirm('Logout?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">

        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-ban"></i> Blocked Dates Management</h1>
                <p>Restrict availability for houses, tours, and food items</p>
            </div>
            <div class="user-profile">
                <div style="text-align: right;">
                    <div class="user-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
                    <div class="user-role">
                        <?php if($is_admin): ?><i class="fas fa-crown" style="color: #fbbf24;"></i> Admin
                        <?php else: ?><i class="fas fa-user-tie" style="color: #fbbf24;"></i> Staff<?php endif; ?>
                    </div>
                </div>
                <div class="avatar"><?php echo strtoupper(substr($admin_display_name, 0, 1)); ?></div>
            </div>
        </div>

        <?php if($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span><?php echo $success; ?></span></div>
        <?php endif; ?>
        <?php if($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <span><?php echo $error; ?></span></div>
        <?php endif; ?>

        <div class="page-title-banner banner-flex">
            <div>
                <h1><i class="fas fa-ban"></i> Blocked Dates</h1>
                <div class="underline"></div>
                <p style="margin-top: 8px;">Dates listed here can't be booked online. Walk-ins, maintenance, owner use and special events all start here.</p>
            </div>
            <button type="button" class="btn-block-availability" onclick="openBlockWizard()">
                <i class="fas fa-plus"></i> <?php echo $is_admin ? 'Block Availability' : 'Request a Block'; ?>
            </button>
        </div>

        <?php if (!$is_admin): ?>
        <div class="alert alert-info" style="background:#e0f2fe;border:1px solid #bae6fd;color:#075985;"><i class="fas fa-info-circle"></i> <span>Staff access: you can view blocked dates and submit block requests. An administrator approves requests before any date is blocked.</span></div>
        <?php endif; ?>

        <?php if (!empty($block_requests)): ?>
        <div class="card" id="blockRequests">
            <div class="card-header">
                <h2><i class="fas fa-inbox"></i> <?php echo $is_admin ? 'Pending Block Requests (' . count($block_requests) . ')' : 'My Block Requests'; ?></h2>
            </div>
            <ul class="recent-list">
                <?php foreach ($block_requests as $br):
                    $bcfg   = bd_resources()[$br['item_type']] ?? null;
                    $blabel = bd_reasons()[$br['block_type']]['label'] ?? ucwords(str_replace('_', ' ', (string)$br['block_type']));
                    $bd_dates = $br['dates']; sort($bd_dates);
                    $bcount = count($bd_dates);
                    $bfirst = $bcount ? $bd_dates[0] : null; $blast = $bcount ? $bd_dates[$bcount - 1] : null;
                    $bstat  = $br['status'];
                    $bcolor = $bstat === 'approved' ? '#047857' : ($bstat === 'rejected' ? '#b91c1c' : '#b45309');
                ?>
                <li class="recent-item" style="flex-wrap:wrap;gap:10px;">
                    <div class="recent-icon"><i class="fas fa-<?php echo $bcfg ? $bcfg['icon'] : 'ban'; ?>"></i></div>
                    <div class="recent-body" style="min-width:200px;">
                        <div class="r-title"><?php echo htmlspecialchars($br['item_name']); ?></div>
                        <div class="r-sub">
                            <?php if ($bfirst): echo bd_fmt_date($bfirst); if ($bfirst !== $blast) echo ' – ' . bd_fmt_date($blast); endif; ?>
                            · <?php echo $bcount; ?> day<?php echo $bcount === 1 ? '' : 's'; ?>
                            · <?php echo htmlspecialchars($blabel); ?>
                            <?php if (!empty($br['note'])): ?> · “<?php echo htmlspecialchars($br['note']); ?>”<?php endif; ?>
                        </div>
                        <div class="r-sub">Requested by <?php echo htmlspecialchars($br['requester_name'] ?: ($br['requester_username'] ?? 'staff')); ?> · <?php echo date('M d, h:i A', strtotime($br['requested_at'])); ?>
                            <?php if (!$is_admin): ?> · <strong style="color:<?php echo $bcolor; ?>;"><?php echo ucfirst($bstat); ?></strong><?php if (!empty($br['review_note'])): ?> (<?php echo htmlspecialchars($br['review_note']); ?>)<?php endif; ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($is_admin && $bstat === 'pending'): ?>
                    <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($bd_csrf); ?>">
                        <input type="hidden" name="request_id" value="<?php echo (int)$br['id']; ?>">
                        <input type="hidden" name="return_qs" value="<?php echo htmlspecialchars($_SERVER['QUERY_STRING'] ?? ''); ?>">
                        <input type="text" name="review_note" maxlength="255" placeholder="Note (optional)" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;min-width:140px;">
                        <button type="submit" name="bd_action" value="approve_request" class="btn-blue" style="padding:7px 14px;"><i class="fas fa-check"></i> Approve</button>
                        <button type="submit" name="bd_action" value="reject_request" class="btn-danger-sm"><i class="fas fa-times"></i> Reject</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ===================== 1. OVERVIEW ===================== -->
        <div class="section-title">Overview</div>
        <div class="stats-grid blocked-stats">
            <div class="stat-card stat-total">
                <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
                <div class="stat-number"><?php echo $total_blocks; ?></div>
                <div class="stat-label">Total Blocked Dates</div>
                <div class="stat-description">
                    <?php
                    $bits = [];
                    foreach (bd_resources() as $t => $cfg) { $bits[] = $cfg['plural'] . ' ' . ($by_type[$t] ?? 0); }
                    echo htmlspecialchars(implode(' · ', $bits));
                    ?>
                </div>
            </div>
            <div class="stat-card stat-upcoming">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo $upcoming_blocks; ?></div>
                <div class="stat-label">Upcoming Blocked Dates</div>
                <div class="stat-description">Today and later</div>
            </div>
            <div class="stat-card stat-house">
                <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
                <div class="stat-number"><?php echo $next7_blocks; ?></div>
                <div class="stat-label">Next 7 Days</div>
                <div class="stat-description">Restrictions starting soon</div>
            </div>
            <div class="stat-card stat-tour">
                <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                <div class="stat-number"><?php echo $resources_affected; ?></div>
                <div class="stat-label">Resources Restricted</div>
                <div class="stat-description">With at least one upcoming block</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-history"></i> Recent Restrictions</h2>
            </div>
            <?php if (!empty($recent_blocks)): ?>
            <ul class="recent-list">
                <?php foreach ($recent_blocks as $rb):
                    $rcfg   = bd_resources()[$rb['item_type']] ?? null;
                    $rlabel = bd_reasons()[$rb['block_type']]['label'] ?? ucwords(str_replace('_', ' ', (string)$rb['block_type']));
                    $same   = ($rb['first_date'] === $rb['last_date']);
                    $rlinked = bd_is_booking_linked(['reason' => $rb['reason']]);
                ?>
                <li class="recent-item">
                    <div class="recent-icon"><i class="fas fa-<?php echo $rcfg ? $rcfg['icon'] : 'ban'; ?>"></i></div>
                    <div class="recent-body">
                        <div class="r-title"><?php echo htmlspecialchars($rb['item_name'] ?? 'Unknown resource'); ?></div>
                        <div class="r-sub">
                            <?php echo bd_fmt_date($rb['first_date']); ?><?php if(!$same): ?> – <?php echo bd_fmt_date($rb['last_date']); ?><?php endif; ?>
                            · <?php echo (int)$rb['day_count']; ?> day<?php echo $rb['day_count'] > 1 ? 's' : ''; ?>
                            · <?php echo $rlinked ? 'Booking hold' : htmlspecialchars($rlabel); ?>
                        </div>
                    </div>
                    <div class="recent-meta">
                        <?php echo htmlspecialchars($rb['blocked_by_name'] ?? 'System'); ?><br>
                        <?php echo date('M d, h:i A', strtotime($rb['created_at'])); ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="empty-state" style="padding: 30px 20px;">
                <i class="fas fa-calendar-check" style="font-size: 40px; margin-bottom: 12px;"></i>
                <h3>No restrictions yet</h3>
                <p>Use “Block Availability” to restrict a house, tour, or food item.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===================== TABS ===================== -->
        <div class="tab-switcher">
            <a href="?tab=list" class="tab-btn <?php echo $current_tab === 'list' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> Blocked Dates List
            </a>
           
        </div>

        <?php if ($current_tab === 'list'): ?>
        <!-- ===================== 2. BLOCKED DATES LIST ===================== -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Blocked Dates (<?php echo $list_total; ?>)</h2>
                <div class="filter-bar">
                    <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <input type="hidden" name="tab" value="list">
<select id="filterType" name="filter_type">                            <option value="all">All Resources</option>
                            <?php foreach (bd_resources() as $t => $cfg): ?>
                            <option value="<?php echo $t; ?>" <?php echo $filter_type === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($cfg['plural']); ?> only</option>
                            <?php endforeach; ?>
                        </select>
                      <select id="filterStatus" name="filter_status">
                            <option value="current" <?php echo $filter_status === 'current' ? 'selected' : ''; ?>>Active &amp; Upcoming</option>
                            <option value="past" <?php echo $filter_status === 'past' ? 'selected' : ''; ?>>Expired</option>
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All dates</option>
                        </select>
<input 
    id="filterMonth"
    type="month"
    name="filter_month"
    value="<?php echo htmlspecialchars($filter_month); ?>"
>                       <input 
    id="blockSearch"
    type="text"
    name="search"
    value="<?php echo htmlspecialchars($search); ?>"
    placeholder="Search resource, reason, or date..."
    style="min-width: 220px;"
>
                        <span style="font-size:12px;color:#94a3b8;">
    Live search
</span>
                        <?php if($filter_type !== 'all' || $filter_status !== 'current' || $filter_month || $search !== ''): ?>
                            <a href="?tab=list" class="btn-secondary" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">
                               <i class="fas fa-times"></i> Reset Filters
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <?php if(!empty($blocked_dates)): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Resource</th>
                            <th>Date</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($blocked_dates as $bd):
                            $cfg     = bd_resources()[$bd['item_type']] ?? ['label' => ucfirst($bd['item_type']), 'icon' => 'ban'];
                            $rlabel  = bd_reasons()[$bd['block_type']]['label'] ?? ucwords(str_replace('_', ' ', (string)$bd['block_type']));
                            $linked  = bd_is_booking_linked($bd);
                            $can     = bd_can_unblock($bd, $is_admin);
                            if ($bd['block_date'] < $today)        { $st_cls = 'status-expired';  $st_txt = 'Expired'; }
                            elseif ($bd['block_date'] === $today)  { $st_cls = 'status-today';    $st_txt = 'Active Today'; }
                            else                                   { $st_cls = 'status-upcoming'; $st_txt = 'Upcoming'; }
                            $note = (string)($bd['reason'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <div class="cell-resource">
                                    <span class="r-name"><?php echo htmlspecialchars($bd['item_name'] ?? 'Unknown'); ?></span>
                                    <span class="badge badge-<?php echo htmlspecialchars($bd['item_type']); ?>"><i class="fas fa-<?php echo $cfg['icon']; ?>"></i> <?php echo htmlspecialchars($cfg['label']); ?></span>
                                </div>
                            </td>
                            <td><strong style="color: #0B2447; white-space: nowrap;"><?php echo date('M d, Y', strtotime($bd['block_date'])); ?></strong><br><span style="font-size: 11px; color: #94a3b8;"><?php echo date('l', strtotime($bd['block_date'])); ?></span></td>
                            <td>
                                <div class="cell-reason">
                                    <span class="badge badge-<?php echo htmlspecialchars($bd['block_type']); ?>"><?php echo htmlspecialchars($rlabel); ?></span>
                                    <?php if ($note !== ''): ?><span class="r-note"><?php echo htmlspecialchars($note); ?></span><?php endif; ?>
                                    <span class="r-meta">by <?php echo htmlspecialchars($bd['blocked_by_name'] ?? 'System'); ?> · <?php echo date('M d, h:i A', strtotime($bd['created_at'])); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="status-pill <?php echo $st_cls; ?>"><?php echo $st_txt; ?></span>
                                <?php if ($linked): ?><span class="tag-linked"><i class="fas fa-link"></i> Booking</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($can): ?>
                                <button type="button" class="btn-unblock"
                                    data-id="<?php echo (int)$bd['id']; ?>"
                                    data-resource="<?php echo htmlspecialchars($bd['item_name'] ?? 'Unknown'); ?>"
                                    data-type="<?php echo htmlspecialchars($cfg['label']); ?>"
                                    data-date="<?php echo htmlspecialchars(bd_fmt_date($bd['block_date'])); ?>"
                                    data-reason="<?php echo htmlspecialchars($rlabel . ($note !== '' ? ' — ' . $note : '')); ?>"
                                    data-linked="<?php echo $linked ? '1' : '0'; ?>"
                                    onclick="openUnblockFromButton(this)">
                                    <i class="fas fa-unlock"></i> Unblock
                                </button>
                                <?php else: ?>
                                <span class="action-locked" title="<?php echo htmlspecialchars(bd_unblock_denied_reason($bd)); ?>"><i class="fas fa-lock"></i> <?php echo $linked ? 'Managed by booking' : 'Admin only'; ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1):
                $pq = $_GET; unset($pq['page']);
            ?>
            <div class="pagination">
                <div>Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $list_total); ?> of <?php echo $list_total; ?></div>
                <div class="pages">
                    <?php if ($page > 1): ?><a href="?<?php echo htmlspecialchars(http_build_query(array_merge($pq, ['tab' => 'list', 'page' => $page - 1]))); ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
                    <span class="cur"><?php echo $page; ?> / <?php echo $total_pages; ?></span>
                    <?php if ($page < $total_pages): ?><a href="?<?php echo htmlspecialchars(http_build_query(array_merge($pq, ['tab' => 'list', 'page' => $page + 1]))); ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-check"></i>
                    <h3>No Blocked Dates Found</h3>
                    <p>Nothing matches these filters — everything is open for online booking.</p>
                    <button type="button" class="btn-blue" style="margin-top: 16px;" onclick="openBlockWizard()"><i class="fas fa-plus"></i> Block Availability</button>
                </div>
                        <?php endif; ?>

        <?php endif; ?>

        </div>

      

    </div>
</div>



<!-- ===================== BLOCK AVAILABILITY WIZARD ===================== -->
<div class="modal" id="blockModal">
    <div class="modal-content wizard">
        <div class="wizard-head">
            <div class="modal-header">
                <h3><i class="fas fa-ban"></i> <?php echo $is_admin ? 'Block Availability' : 'Request a Block'; ?></h3>
                <button type="button" class="close" onclick="closeBlockWizard()" aria-label="Close">&times;</button>
            </div>
        </div>
        <div class="stepper" id="wizStepper"></div>
        <div class="wizard-body" id="wizBody"></div>
        <div class="wizard-foot">
            <button type="button" class="btn-ghost" id="wizBack"><i class="fas fa-arrow-left"></i> Back</button>
            <span class="spacer"></span>
            <button type="button" class="btn-blue" id="wizNext">Next <i class="fas fa-arrow-right"></i></button>
        </div>
    </div>
</div>

<!-- ===================== UNBLOCK CONFIRMATION ===================== -->
<div class="modal" id="unblockModal">
    <div class="modal-content small">
        <div class="modal-header">
            <h3><i class="fas fa-unlock"></i> Unblock Date</h3>
            <button type="button" class="close" onclick="closeUnblock()" aria-label="Close">&times;</button>
        </div>
        <form method="POST" id="unblockForm">
            <input type="hidden" name="bd_action" value="unblock">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($bd_csrf); ?>">
            <input type="hidden" name="block_id" id="unblock_block_id">
            <input type="hidden" name="return_qs" value="<?php echo htmlspecialchars($_SERVER['QUERY_STRING'] ?? ''); ?>">

            <div class="summary-box">
                <div class="info-row"><span class="info-label">Resource</span><span class="info-value" id="ub_resource">—</span></div>
                <div class="info-row"><span class="info-label">Date</span><span class="info-value" id="ub_date">—</span></div>
                <div class="info-row"><span class="info-label">Reason</span><span class="info-value" id="ub_reason">—</span></div>
            </div>
            <div class="notice warn" id="ub_warn"><i class="fas fa-exclamation-triangle"></i> This date will become available for online booking again.</div>
            <div class="notice bad" id="ub_denied" style="display:none;"></div>

            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn-ghost" style="flex: 1; justify-content: center;" onclick="closeUnblock()">Cancel</button>
                <button type="submit" class="btn-blue btn-navy" id="ub_confirm" style="flex: 1; justify-content: center;"><i class="fas fa-unlock"></i> Unblock</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================================
// DATA FROM PHP (resources are loaded from the database)
// ============================================================
var BD = <?php
    $bd_js = [
        'resources' => [],
        'reasons'   => [],
        'today'     => $today,
        'csrf'      => $bd_csrf,
        'maxDates'  => BD_MAX_DATES,
        'returnQs'  => $_SERVER['QUERY_STRING'] ?? '',
        'isStaff'   => !$is_admin,
    ];
    foreach (bd_resources() as $t => $cfg) {
        $bd_js['resources'][$t] = [
            'label'  => $cfg['label'],
            'plural' => $cfg['plural'],
            'icon'   => $cfg['icon'],
            'items'  => $resource_lists[$t],
        ];
    }
    foreach (bd_reasons() as $k => $r) { $bd_js['reasons'][$k] = $r; }
    echo json_encode($bd_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;



// ============================================================
// SMALL HELPERS
// ============================================================
var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function mk(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
}
function fmtDate(s) {
    var p = s.split('-');
    return MONTHS[parseInt(p[1], 10) - 1] + ' ' + parseInt(p[2], 10) + ', ' + p[0];
}
function toUTC(s) { var p = s.split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]); }
function expandRange(a, b) {
    var out = [], t = toUTC(a), end = toUTC(b);
    while (t <= end && out.length <= BD.maxDates + 1) {
        out.push(new Date(t).toISOString().slice(0, 10));
        t += 86400000;
    }
    return out;
}
function summarizeDates(dates) {
    if (!dates.length) return '—';
    if (dates.length === 1) return fmtDate(dates[0]);
    var contiguous = (toUTC(dates[dates.length - 1]) - toUTC(dates[0])) / 86400000 === dates.length - 1;
    return contiguous
        ? fmtDate(dates[0]) + ' – ' + fmtDate(dates[dates.length - 1]) + ' (' + dates.length + ' days)'
        : dates.length + ' selected dates (' + fmtDate(dates[0]) + ' … ' + fmtDate(dates[dates.length - 1]) + ')';
}



// ============================================================
// UNBLOCK MODAL (shared by list + calendar)
// ============================================================
function showUnblock(d) {
    document.getElementById('unblock_block_id').value = d.id;
    document.getElementById('ub_resource').textContent = d.resource;
    document.getElementById('ub_date').textContent = d.date;
    document.getElementById('ub_reason').textContent = d.reason;
    var denied = document.getElementById('ub_denied');
    var warn = document.getElementById('ub_warn');
    var confirmBtn = document.getElementById('ub_confirm');
    if (d.canUnblock) {
        denied.style.display = 'none';
        warn.style.display = '';
        confirmBtn.style.display = '';
        confirmBtn.disabled = false;
    } else {
        denied.textContent = d.denyReason || 'You do not have permission to unblock this date.';
        denied.style.display = '';
        warn.style.display = 'none';
        confirmBtn.style.display = 'none';
    }
    document.getElementById('unblockModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function openUnblockFromButton(btn) {
    showUnblock({
        id: btn.dataset.id, resource: btn.dataset.resource, date: btn.dataset.date,
        reason: btn.dataset.reason, canUnblock: true
    });
}

function closeUnblock() {
    document.getElementById('unblockModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// BLOCK AVAILABILITY WIZARD
//   1 Resource type → 2 Resource → 3 Date(s) → 4 Reason → 5 Confirm
// ============================================================
var STEPS = ['Type', 'Resource', 'Dates', 'Reason', 'Confirm'];
var W = null;

function newWizardState() {
    return { step: 1, type: '', id: '', name: '', mode: 'single', start: '', end: '', dates: [], custom: false,
             reasonType: 'walk_in', note: '', preview: null, previewing: false, error: '' };
}

function openBlockWizard(preset) {
    W = newWizardState();
    if (preset && preset.type && BD.resources[preset.type]) {
        W.type = preset.type; W.id = String(preset.id); W.name = preset.name || '';
        if (preset.dates && preset.dates.length) { W.dates = preset.dates.slice(); W.custom = true; W.mode = 'custom'; W.step = 4; }
        else { W.step = 3; }
    }
    document.getElementById('blockModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    renderWizard();
}
function closeBlockWizard() {
    document.getElementById('blockModal').classList.remove('show');
    document.body.style.overflow = 'auto';
    W = null;
}


function computeDates() {
    if (W.mode === 'custom') return W.dates;
    if (W.mode === 'single') return W.start ? [W.start] : [];
    if (W.start && W.end && W.end >= W.start) return expandRange(W.start, W.end);
    return [];
}

function validateStep(step) {
    if (step === 1) return W.type ? '' : 'Choose a resource type to continue.';
    if (step === 2) return W.id ? '' : 'Choose a resource to continue.';
    if (step === 3) {
        if (W.mode === 'custom') return W.dates.length ? '' : 'No dates selected.';
        if (!W.start) return 'Pick a date to continue.';
        if (W.start < BD.today) return 'Past dates can’t be blocked.';
        if (W.mode === 'range') {
            if (!W.end) return 'Pick an end date.';
            if (W.end < W.start) return 'The end date can’t be before the start date.';
            if (expandRange(W.start, W.end).length > BD.maxDates) return 'A range can cover at most ' + BD.maxDates + ' days.';
        }
        return '';
    }
    if (step === 4) {
        if (!W.reasonType) return 'Choose a reason.';
        if (W.reasonType === 'other' && !W.note.trim()) return 'Please describe the reason when choosing “Other”.';
        return '';
    }
    return '';
}

function renderWizard() {
    if (!W) return;
    // stepper
    var st = document.getElementById('wizStepper');
    st.innerHTML = '';
    STEPS.forEach(function(label, i) {
        var n = i + 1;
        var item = mk('div', 'st' + (n === W.step ? ' active' : (n < W.step ? ' done' : '')));
        var dot = mk('span', 'dot');
        if (n < W.step) { var ic = mk('i', 'fas fa-check'); dot.appendChild(ic); } else { dot.textContent = n; }
        item.appendChild(dot);
        item.appendChild(mk('span', 'lbl', label));
        if (n < STEPS.length) item.appendChild(mk('span', 'bar'));
        st.appendChild(item);
    });

    var body = document.getElementById('wizBody');
    body.innerHTML = '';
    if (W.step === 1) renderStepType(body);
    else if (W.step === 2) renderStepResource(body);
    else if (W.step === 3) renderStepDates(body);
    else if (W.step === 4) renderStepReason(body);
    else renderStepConfirm(body);

    var back = document.getElementById('wizBack');
    var next = document.getElementById('wizNext');
    back.style.visibility = W.step === 1 ? 'hidden' : 'visible';
    back.onclick = function() { W.error = ''; W.step = Math.max(1, W.step - 1); renderWizard(); };

    if (W.step < 5) {
        next.className = 'btn-blue';
        next.innerHTML = 'Next <i class="fas fa-arrow-right"></i>';
        next.disabled = !!validateStep(W.step) && (W.step === 1 || W.step === 2);
        next.onclick = function() {
            var err = validateStep(W.step);
            if (err) { W.error = err; renderWizard(); return; }
            W.error = '';
            W.step++;
            if (W.step === 5) { W.preview = null; loadPreview(); }
            renderWizard();
        };
    } else {
        next.className = 'btn-blue btn-navy';
        next.innerHTML = BD.isStaff ? '<i class="fas fa-paper-plane"></i> Submit Request' : '<i class="fas fa-check"></i> Confirm &amp; Block';
        next.disabled = !(W.preview && W.preview.ok && W.preview.can_save) || W.previewing;
        next.onclick = submitBlock;
    }
}

function stepHeader(body, title, sub) {
    body.appendChild(mk('div', 'step-title', title));
    body.appendChild(mk('div', 'step-sub', sub));
}
function errorLine(body) {
    if (W.error) body.appendChild(mk('div', 'field-error', W.error));
}

// ---- Step 1
function renderStepType(body) {
    stepHeader(body, 'What do you want to block?', 'Choose the type of resource.');
    var grid = mk('div', 'choice-grid');
    Object.keys(BD.resources).forEach(function(t) {
        var r = BD.resources[t];
        var card = mk('button', 'choice-card' + (W.type === t ? ' selected' : ''));
        card.type = 'button';
        card.appendChild(mk('i', 'fas fa-' + r.icon));
        card.appendChild(mk('div', 'c-name', r.label));
        card.appendChild(mk('div', 'c-count', r.items.length + ' available'));
        card.onclick = function() {
            if (W.type !== t) { W.type = t; W.id = ''; W.name = ''; }
            W.error = ''; W.step = 2; renderWizard();
        };
        grid.appendChild(card);
    });
    body.appendChild(grid);
    errorLine(body);
}

// ---- Step 2
function renderStepResource(body) {
    var r = BD.resources[W.type];
    stepHeader(body, 'Which ' + r.label.toLowerCase() + '?', 'Pick the specific ' + r.label.toLowerCase() + ' to restrict.');
    if (!r.items.length) {
        body.appendChild(mk('div', 'notice warn', 'No ' + r.plural.toLowerCase() + ' found. Add one in its management page first.'));
        return;
    }
    var search = mk('input', 'form-control resource-search');
    search.type = 'text'; search.placeholder = 'Search ' + r.plural.toLowerCase() + '…';
    var list = mk('div', 'resource-list');
    function draw() {
        list.innerHTML = '';
        var q = search.value.trim().toLowerCase();
        var shown = 0;
        r.items.forEach(function(it) {
            if (q && String(it.name).toLowerCase().indexOf(q) === -1) return;
            shown++;
            var row = mk('button', 'resource-row' + (String(it.id) === W.id ? ' selected' : ''));
            row.type = 'button';
            row.appendChild(mk('i', 'fas fa-' + r.icon));
            row.appendChild(mk('span', '', it.name));
            row.appendChild(mk('i', 'fas fa-check-circle check'));
            row.onclick = function() {
                W.id = String(it.id); W.name = it.name; W.error = '';
                W.step = 3; renderWizard();
            };
            list.appendChild(row);
        });
        if (!shown) list.appendChild(mk('div', 'step-sub', 'No matches.'));
    }
    search.oninput = draw;
    if (r.items.length > 6) body.appendChild(search);
    body.appendChild(list);
    draw();
    errorLine(body);
}

// ---- Step 3
function renderStepDates(body) {
    stepHeader(body, 'When?', 'Block a single date or a continuous range. Past dates aren’t allowed.');

    var toggle = mk('div', 'mode-toggle');
    var modes = [['single', 'Single date'], ['range', 'Date range']];
    if (W.custom) modes.push(['custom', 'Calendar selection']);
    modes.forEach(function(m) {
        var b = mk('button', W.mode === m[0] ? 'active' : '', m[1]);
        b.type = 'button';
        b.onclick = function() { W.mode = m[0]; W.error = ''; renderWizard(); };
        toggle.appendChild(b);
    });
    body.appendChild(toggle);

    if (W.mode === 'custom') {
        body.appendChild(mk('div', 'step-sub', W.dates.length + ' date(s) picked on the calendar:'));
        var chips = mk('div', 'chips');
        chips.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;max-height:140px;overflow-y:auto;';
        W.dates.forEach(function(d) { chips.appendChild(mk('span', 'selected-date-chip', fmtDate(d))); });
        body.appendChild(chips);
        errorLine(body);
        return;
    }

    var fields = mk('div', 'date-fields');
    var g1 = mk('div');
    g1.appendChild(mk('label', 'form-label', W.mode === 'range' ? 'Start date' : 'Date'));
    var start = mk('input', 'form-control'); start.type = 'date'; start.min = BD.today; start.value = W.start;
    start.onchange = function() {
        W.start = start.value;
        if (W.mode === 'range' && W.end && W.end < W.start) W.end = W.start;
        W.error = ''; renderWizard();
    };
    g1.appendChild(start); fields.appendChild(g1);

    if (W.mode === 'range') {
        var g2 = mk('div');
        g2.appendChild(mk('label', 'form-label', 'End date (inclusive)'));
        var end = mk('input', 'form-control'); end.type = 'date'; end.min = W.start || BD.today; end.value = W.end;
        end.onchange = function() { W.end = end.value; W.error = ''; renderWizard(); };
        g2.appendChild(end); fields.appendChild(g2);
    }
    body.appendChild(fields);

    var ds = computeDates();
    if (ds.length) {
        var pill = mk('span', 'days-pill');
        pill.appendChild(mk('i', 'fas fa-calendar-day'));
        pill.appendChild(document.createTextNode(' ' + ds.length + ' day' + (ds.length > 1 ? 's' : '') + ' · ' + summarizeDates(ds)));
        body.appendChild(pill);
    }
    errorLine(body);
}

// ---- Step 4
function renderStepReason(body) {
    stepHeader(body, 'Why is it being blocked?', 'The reason is saved with the block and shown in the audit log.');
    var grid = mk('div', 'block-type-grid');
    Object.keys(BD.reasons).forEach(function(k) {
        var lab = mk('label', 'block-type-option');
        var inp = mk('input'); inp.type = 'radio'; inp.name = 'wiz_reason'; inp.value = k; inp.checked = (W.reasonType === k);
        inp.onchange = function() { W.reasonType = k; W.error = ''; renderWizard(); };
        var span = mk('span', 'block-type-option-label');
        span.appendChild(mk('i', 'fas fa-' + BD.reasons[k].icon));
        span.appendChild(document.createTextNode(' ' + BD.reasons[k].label));
        lab.appendChild(inp); lab.appendChild(span);
        grid.appendChild(lab);
    });
    body.appendChild(grid);

    body.appendChild(mk('label', 'form-label', W.reasonType === 'other' ? 'Describe the reason *' : 'Note (optional)'));
    var note = mk('input', 'form-control'); note.type = 'text'; note.maxLength = 255; note.value = W.note;
    note.placeholder = W.reasonType === 'walk_in' ? 'e.g., Walk-in group booked at the counter' : 'Add details for your team';
    note.oninput = function() { W.note = note.value; };
    body.appendChild(note);
    body.appendChild(mk('div', 'field-help', 'Applies to every selected date.'));
    errorLine(body);
}

// ---- Step 5
function renderStepConfirm(body) {
    var r = BD.resources[W.type];
    stepHeader(body, 'Review & confirm', 'Nothing is saved until you confirm.');
    W.dates = (W.mode === 'custom') ? W.dates : computeDates();

    var box = mk('div', 'summary-box');
    function row(l, v) {
        var d = mk('div', 'info-row');
        d.appendChild(mk('span', 'info-label', l));
        d.appendChild(mk('span', 'info-value', v));
        box.appendChild(d);
    }
    row('Resource', r.label + ' — ' + W.name);
    row('Date(s)', summarizeDates(W.dates));
    row('Reason', BD.reasons[W.reasonType].label);
    if (W.note.trim()) row('Note', W.note.trim());
    body.appendChild(box);

    if (W.previewing || !W.preview) {
        var ld = mk('div', 'loading-line');
        ld.appendChild(mk('i', 'fas fa-circle-notch fa-spin'));
        ld.appendChild(document.createTextNode(' Checking availability…'));
        body.appendChild(ld);
        return;
    }
    var p = W.preview;
    if (!p.ok) { body.appendChild(mk('div', 'notice bad', p.error || 'Could not check these dates.')); return; }

    function chipsNotice(cls, text, items) {
        var n = mk('div', 'notice ' + cls);
        n.appendChild(mk('strong', '', text));
        if (items && items.length) {
            var c = mk('div', 'chips');
            items.slice(0, 40).forEach(function(t) { c.appendChild(mk('span', 'chip', t)); });
            if (items.length > 40) c.appendChild(mk('span', 'chip', '+' + (items.length - 40) + ' more'));
            n.appendChild(c);
        }
        body.appendChild(n);
    }
    if (p.past.length) chipsNotice('bad', 'Past dates can’t be blocked — go back and fix:', p.past.map(fmtDate));
    var confKeys = Object.keys(p.conflicts || {});
    if (confKeys.length) chipsNotice('bad', 'Active bookings exist on these dates — cancel or reschedule them first:',
        confKeys.map(function(d) { return fmtDate(d) + ' (' + p.conflicts[d].join(', ') + ')'; }));
    if (p.duplicates.length) chipsNotice('warn', p.duplicates.length + ' date(s) already blocked — will be skipped:', p.duplicates.map(fmtDate));
    if (p.can_save) chipsNotice('ok', p.blockable.length + ' date(s) will be blocked and unavailable for online booking.', p.blockable.length <= 12 ? p.blockable.map(fmtDate) : null);
    else if (!p.past.length && !confKeys.length) chipsNotice('bad', 'Nothing to block — every selected date is already blocked.');
}

function loadPreview() {
    W.previewing = true; W.preview = null;
    var fd = new FormData();
    fd.append('bd_action', 'preview');
    fd.append('csrf', BD.csrf);
    fd.append('item_type', W.type);
    fd.append('item_id', W.id);
    fd.append('dates_json', JSON.stringify(computeDates()));
    fd.append('block_type', W.reasonType);
    fd.append('note', W.note.trim());
    var mine = W;
    fetch('blocked-dates.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) { if (W !== mine) return; W.preview = j; W.previewing = false; renderWizard(); })
        .catch(function() { if (W !== mine) return; W.preview = { ok: false, error: 'Could not reach the server. Please refresh and try again.' }; W.previewing = false; renderWizard(); });
}

function submitBlock() {
    if (!W || !W.preview || !W.preview.can_save) return;
    var btn = document.getElementById('wizNext');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> ' + (BD.isStaff ? 'Submitting…' : 'Saving…');

    var form = document.createElement('form');
    form.method = 'POST';
    function add(n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i); }
    add('bd_action', BD.isStaff ? 'request' : 'block');
    add('csrf', BD.csrf);
    add('item_type', W.type);
    add('item_id', W.id);
    add('dates_json', JSON.stringify(computeDates()));
    add('block_type', W.reasonType);
    add('note', W.note.trim());
    add('return_qs', BD.returnQs);
    document.body.appendChild(form);
    form.submit();
}

// Esc closes whichever modal is open
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('blockModal').classList.contains('show')) closeBlockWizard();
    if (document.getElementById('unblockModal').classList.contains('show')) closeUnblock();
    var sb = document.getElementById('sidebar');
    if (sb && sb.classList.contains('open')) toggleSidebar();
});

// ============================================================
// SIDEBAR
// ============================================================
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    document.body.classList.toggle('sidebar-open-mobile');
}

// Auto-dismiss alerts
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(function() { alert.remove(); }, 500);
    });
}, 8000);

// ============================================
// BLOCKED DATES LIVE SEARCH
// ============================================

document.addEventListener("DOMContentLoaded", function(){

    const search = document.getElementById("blockSearch");

    if(!search) return;


    search.addEventListener("input", function(){

        const keyword = this.value.toLowerCase().trim();

        const rows = document.querySelectorAll(
            "table tbody tr"
        );


        rows.forEach(function(row){

            const text = row.innerText.toLowerCase();

            if(text.includes(keyword)){
                row.style.display = "";
            }
            else{
                row.style.display = "none";
            }

        });


    });

});

// ============================================
// AUTO APPLY DROPDOWN FILTERS
// ============================================

document.addEventListener("DOMContentLoaded", function(){

    const filters = [
        "filterType",
        "filterStatus",
        "filterMonth"
    ];


    filters.forEach(function(id){

        const el = document.getElementById(id);

        if(el){

            el.addEventListener("change", function(){

                this.form.submit();

            });

        }

    });

});
</script>

</body>
</html>