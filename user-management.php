<?php
session_start();
require_once 'database.php';
require_once 'includes/sidebar-counts.php';

// ============================================================
// ✅ NEW: Load SystemLogger
// ============================================================
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// ============================================================
// PASSWORD VALIDATION FUNCTION
// ============================================================
function validatePassword($password) {
    $errors = [];
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters long";
    if (!preg_match('/[A-Z]/', $password)) $errors[] = "Password must contain at least one uppercase letter (A-Z)";
    if (!preg_match('/[0-9]/', $password)) $errors[] = "Password must contain at least one number (0-9)";
    return $errors;
}

// ============================================================
// ✅ FULL NAME VALIDATION — Letters only
// ============================================================
function validateFullName($name) {
    $name = trim($name);
    if ($name === '') return "Full name is required";
    if (strlen($name) > 80) return "Full name must not exceed 80 characters";
    if (preg_match('/[0-9]/', $name)) return "Full name cannot contain numbers";
    if (!preg_match("/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/u", $name)) {
        return "Full name can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.)";
    }
    return null;
}

function sanitizeFullName($name) {
    $name = preg_replace("/[^a-zA-ZÀ-ÿñÑ\s\-'.]/u", '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// ============================================================
// ✅ ID NUMBER VALIDATION
// ============================================================
function validateIdNumber($id_number) {
    if (empty($id_number)) return null;
    $id_number = trim($id_number);
    if (!preg_match('/^[A-Za-z0-9\-]{5,20}$/', $id_number)) {
        return "ID number must be 5-20 characters (letters, numbers, hyphen only)";
    }
    return null;
}

// ============================================================
// ✅ PHONE VALIDATION (country-aware)
// ============================================================
function validatePhoneNumber($phone, $required = false, $suffix = '') {
    if (empty($phone)) {
        return $required ? "Phone number is required" : null;
    }
    $clean = preg_replace('/[^0-9]/', '', $phone);

    $rules = [
        '+63'  => ['min' => 10, 'max' => 10, 'prefix' => '9'],
        '+1'   => ['min' => 10, 'max' => 10, 'prefix' => ''],
        '+44'  => ['min' => 10, 'max' => 10, 'prefix' => ''],
        '+61'  => ['min' => 9,  'max' => 9,  'prefix' => ''],
        '+81'  => ['min' => 10, 'max' => 10, 'prefix' => ''],
        '+82'  => ['min' => 10, 'max' => 10, 'prefix' => ''],
        '+86'  => ['min' => 11, 'max' => 11, 'prefix' => ''],
        '+65'  => ['min' => 8,  'max' => 8,  'prefix' => ''],
        '+60'  => ['min' => 9,  'max' => 10, 'prefix' => ''],
        '+62'  => ['min' => 10, 'max' => 11, 'prefix' => ''],
        '+66'  => ['min' => 9,  'max' => 9,  'prefix' => ''],
        '+84'  => ['min' => 9,  'max' => 9,  'prefix' => ''],
        '+91'  => ['min' => 10, 'max' => 10, 'prefix' => ''],
        '+971' => ['min' => 9,  'max' => 9,  'prefix' => ''],
        '+966' => ['min' => 9,  'max' => 9,  'prefix' => ''],
    ];

    $rule = $rules[$suffix] ?? ['min' => 7, 'max' => 15, 'prefix' => ''];

    if (strlen($clean) < $rule['min'] || strlen($clean) > $rule['max']) {
        if ($rule['min'] === $rule['max']) {
            return "Phone number must be exactly {$rule['min']} digits for {$suffix}";
        }
        return "Phone number must be between {$rule['min']} and {$rule['max']} digits for {$suffix}";
    }

    if (!empty($rule['prefix']) && !str_starts_with($clean, $rule['prefix'])) {
        return "Phone number for {$suffix} must start with {$rule['prefix']}";
    }

    return null;
}

// ============================================================
// ✅ BUILD FULL PHONE WITH COUNTRY CODE
// ============================================================
function buildFullPhone($suffix, $number) {
    $clean_number = preg_replace('/[^0-9]/', '', $number);
    if (empty($clean_number)) return '';
    return trim($suffix) . $clean_number;
}

// Check if user is logged in
require_once 'includes/auth.php';
requireAdmin();

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

// Get user info
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) { $user_info = []; }

// ============================================================
// ✅ Resolve avatar (photo or initial)
// ============================================================
if (!function_exists('getAdminAvatar')) {
    function getAdminAvatar($user_info) {
        if (empty($user_info['profile_photo'])) return null;
        $user_id = (int)($user_info['id'] ?? 0);
        if ($user_id <= 0) return null;

        $paths = [
            'uploads/profile/user_' . $user_id . '/' . $user_info['profile_photo'],
            'uploads/staff/' . $user_info['profile_photo'],
            'uploads/profile/' . $user_info['profile_photo'],
        ];
        foreach ($paths as $path) {
            if (file_exists($path) && !is_dir($path)) return $path;
        }
        return null;
    }
}

$admin_avatar = getAdminAvatar($user_info);
$admin_initial = strtoupper(substr($user_info['fullname'] ?? $user_info['username'] ?? 'U', 0, 1));
$admin_display_name = $user_info['fullname'] ?? $user_info['username'] ?? 'User';

// ============================================================
// ✅ SIDEBAR BADGE COUNTS — Booking, Reviews, System Logs
// ============================================================




// ADD USER - ADMIN ONLY
if(isset($_POST['add_user']) && $is_admin) {
    try {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $email = trim($_POST['email']);
        $fullname = trim($_POST['fullname']);
        $role = $_POST['role'];
        $phone_suffix = trim($_POST['phone_suffix'] ?? '+63');
        $phone_number = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $id_type = trim($_POST['id_type'] ?? '');
        $id_number = trim($_POST['id_number'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $emergency_suffix = trim($_POST['emergency_suffix'] ?? '+63');
        $emergency_number_raw = trim($_POST['emergency_number'] ?? '');

        $name_error = validateFullName($fullname);
        if ($name_error) throw new Exception($name_error);
        $fullname = sanitizeFullName($fullname);

        if (!empty($emergency_contact)) {
            $ec_error = validateFullName($emergency_contact);
            if ($ec_error) throw new Exception("Emergency contact name: " . $ec_error);
            $emergency_contact = sanitizeFullName($emergency_contact);
        }

        $phone = buildFullPhone($phone_suffix, $phone_number);
        $emergency_number = buildFullPhone($emergency_suffix, $emergency_number_raw);

        $passwordErrors = validatePassword($password);
        if (!empty($passwordErrors)) throw new Exception(implode("<br>", $passwordErrors));

        $idError = validateIdNumber($id_number);
        if ($idError) throw new Exception($idError);

        $phoneError = validatePhoneNumber($phone_number, false, $phone_suffix);
        if ($phoneError) throw new Exception($phoneError);

        $emergencyError = validatePhoneNumber($emergency_number_raw, false, $emergency_suffix);
        if ($emergencyError) throw new Exception($emergencyError);

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if($check->fetch()) throw new Exception("Username already exists!");

        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if($check->fetch()) throw new Exception("Email already registered!");

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, fullname, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $hashed, $email, $fullname, $role]);
        $user_id = $pdo->lastInsertId();

        if($role == 'guest') {
            $stmt = $pdo->prepare("INSERT INTO guests (user_id, full_name, contact_number, email, address, id_type, id_number, emergency_contact, emergency_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $fullname, $phone, $email, $address, $id_type, $id_number, $emergency_contact, $emergency_number]);
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'create', 'user',
                "Added new user: {$username} ({$role})",
                $user_id, 'user', null, [
                    'username' => $username,
                    'email'    => $email,
                    'fullname' => $fullname,
                    'role'     => $role
                ]);
        }

        $_SESSION['flash_success'] = "User added successfully!";
        header("Location: user-management.php");
        exit();
    } catch(Exception $e) {
        $error = "Failed to add user: " . $e->getMessage();
    }
}

// EDIT USER - ADMIN ONLY
if(isset($_POST['edit_user']) && $is_admin) {
    try {
        $user_id = (int)$_POST['user_id'];
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $fullname = trim($_POST['fullname']);
        $role = $_POST['role'];
        $phone_suffix = trim($_POST['phone_suffix'] ?? '+63');
        $phone_number = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $id_type = trim($_POST['id_type'] ?? '');
        $id_number = trim($_POST['id_number'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $emergency_suffix = trim($_POST['emergency_suffix'] ?? '+63');
        $emergency_number_raw = trim($_POST['emergency_number'] ?? '');
        $new_password = $_POST['new_password'] ?? '';

        $name_error = validateFullName($fullname);
        if ($name_error) throw new Exception($name_error);
        $fullname = sanitizeFullName($fullname);

        if (!empty($emergency_contact)) {
            $ec_error = validateFullName($emergency_contact);
            if ($ec_error) throw new Exception("Emergency contact name: " . $ec_error);
            $emergency_contact = sanitizeFullName($emergency_contact);
        }

        $phone = buildFullPhone($phone_suffix, $phone_number);
        $emergency_number = buildFullPhone($emergency_suffix, $emergency_number_raw);

        if(empty($user_id)) throw new Exception("Invalid user ID.");

        if(!empty($new_password)) {
            $passwordErrors = validatePassword($new_password);
            if (!empty($passwordErrors)) throw new Exception(implode("<br>", $passwordErrors));
        }

        $idError = validateIdNumber($id_number);
        if ($idError) throw new Exception($idError);

        $phoneError = validatePhoneNumber($phone_number, false, $phone_suffix);
        if ($phoneError) throw new Exception($phoneError);

        $emergencyError = validatePhoneNumber($emergency_number_raw, false, $emergency_suffix);
        if ($emergencyError) throw new Exception($emergencyError);

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$username, $user_id]);
        if($check->fetch()) throw new Exception("Username already exists! (Used by another account)");

        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $user_id]);
        if($check->fetch()) throw new Exception("Email already registered! (Used by another account)");

        $old_stmt = $pdo->prepare("SELECT username, email, fullname, role FROM users WHERE id = ?");
        $old_stmt->execute([$user_id]);
        $old_data = $old_stmt->fetch();

        if(!empty($new_password)) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, email = ?, fullname = ?, role = ? WHERE id = ?");
            $stmt->execute([$username, $hashed, $email, $fullname, $role, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, fullname = ?, role = ? WHERE id = ?");
            $stmt->execute([$username, $email, $fullname, $role, $user_id]);
        }

        $check_guest = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
        $check_guest->execute([$user_id]);
        $guest_exists = $check_guest->fetch();

        if($role == 'guest') {
            if($guest_exists) {
                $stmt = $pdo->prepare("UPDATE guests SET full_name = ?, contact_number = ?, email = ?, address = ?, id_type = ?, id_number = ?, emergency_contact = ?, emergency_number = ? WHERE user_id = ?");
                $stmt->execute([$fullname, $phone, $email, $address, $id_type, $id_number, $emergency_contact, $emergency_number, $user_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO guests (user_id, full_name, contact_number, email, address, id_type, id_number, emergency_contact, emergency_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $fullname, $phone, $email, $address, $id_type, $id_number, $emergency_contact, $emergency_number]);
            }
        } else {
            if($guest_exists) $pdo->prepare("DELETE FROM guests WHERE user_id = ?")->execute([$user_id]);
        }

        if (class_exists('SystemLogger')) {
            $changed = [];
            if ($old_data) {
                if ($old_data['username'] !== $username) $changed[] = 'username';
                if ($old_data['email'] !== $email) $changed[] = 'email';
                if ($old_data['fullname'] !== $fullname) $changed[] = 'fullname';
                if ($old_data['role'] !== $role) $changed[] = 'role';
            }
            if (!empty($new_password)) $changed[] = 'password';

            $desc = "Updated user: {$username}";
            if (!empty($changed)) $desc .= " (changed: " . implode(', ', $changed) . ")";

            SystemLogger::log($pdo, 'update', 'user',
                $desc,
                $user_id, 'user',
                $old_data ?: null,
                ['username' => $username, 'email' => $email, 'fullname' => $fullname, 'role' => $role]);
        }

        $_SESSION['flash_success'] = "User updated successfully!";
        header("Location: user-management.php");
        exit();
    } catch(Exception $e) {
        $error = "Failed to update user: " . $e->getMessage();
    }
}

// GET ALL USERS
$users = [];
try {
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $select_fields = ['id', 'username', 'email', 'role', 'created_at'];
    if(in_array('fullname', $columns)) $select_fields[] = 'fullname as full_name';
    elseif(in_array('full_name', $columns)) $select_fields[] = 'full_name as full_name';
    elseif(in_array('name', $columns)) $select_fields[] = 'name as full_name';
    else $select_fields[] = "'' as full_name";
    if(in_array('phone', $columns)) $select_fields[] = 'phone';
    else $select_fields[] = "'' as phone";

    $query = "SELECT " . implode(', ', $select_fields) . " FROM users ORDER BY id";
    $users = $pdo->query($query)->fetchAll();
} catch(PDOException $e) { $error = "Error loading users: " . $e->getMessage(); }

// Sort: Admin → Staff → Guest
usort($users, function($a, $b) {
    $role_order = ['admin' => 0, 'staff' => 1, 'guest' => 2];
    $ra = $role_order[$a['role']] ?? 3;
    $rb = $role_order[$b['role']] ?? 3;
    if ($ra != $rb) return $ra - $rb;
    return strcasecmp($a['username'], $b['username']);
});

// Search
$search = isset($_GET['search']) ? $_GET['search'] : '';
if($search) {
    $filtered = [];
    foreach($users as $u) {
        if(stripos($u['username'], $search) !== false || stripos($u['full_name'], $search) !== false || stripos($u['email'], $search) !== false) {
            $filtered[] = $u;
        }
    }
    $users = $filtered;
}

// ===============================
// USER MANAGEMENT STATISTICS
// ===============================

$total_users = count($users);

$total_guests = 0;
$total_staff = 0;
$total_admins = 0;

foreach($users as $statUser){

    if($statUser['role'] === 'guest'){
        $total_guests++;
    }

    elseif($statUser['role'] === 'staff'){
        $total_staff++;
    }

    elseif($statUser['role'] === 'admin'){
        $total_admins++;
    }
}

// Bookings count
$user_bookings_count = [];
foreach($users as $u) {
    $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $guest_stmt->execute([$u['id']]);
    $guest = $guest_stmt->fetch();
    if($guest) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM house_bookings WHERE guest_id = ?");
        $stmt->execute([$guest['id']]);
        $house_count = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tour_bookings WHERE guest_id = ?");
        $stmt->execute([$guest['id']]);
        $tour_count = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM food_bookings WHERE guest_id = ?");
        $stmt->execute([$guest['id']]);
        $food_count = $stmt->fetchColumn();
        $user_bookings_count[$u['id']] = $house_count + $tour_count + $food_count;
    } else {
        $user_bookings_count[$u['id']] = 0;
    }
}

// HANDLE DELETE USER
if(isset($_POST['delete_user']) && $is_admin) {
    try {
        $user_id = (int)$_POST['user_id'];
        $delete_reason = trim($_POST['delete_reason'] ?? '');
        $delete_reason_other = trim($_POST['delete_reason_other'] ?? '');
        $delete_reason_notes = trim($_POST['delete_reason_notes'] ?? '');

        $final_reason = $delete_reason;
        if ($delete_reason === 'Other' && !empty($delete_reason_other)) {
            $final_reason = $delete_reason_other;
        } elseif ($delete_reason === 'Other') {
            $final_reason = 'No specific reason provided';
        }
        if (!empty($delete_reason_notes)) {
            $final_reason .= ' — Notes: ' . $delete_reason_notes;
        }

        $stmt = $pdo->prepare("SELECT id, username, email, role, fullname FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) throw new Exception("User not found.");
        if ($user['role'] === 'admin') throw new Exception("Cannot delete admin user!");

        try {
            if (file_exists('config/mail_config.php') && !preg_match('/@walkin\.invalid$/i', (string)$user['email'])) { // walk-in placeholder addresses are never emailed
                require_once 'config/mail_config.php';
                $mail = MailConfig::getInstance()->getMailer();
                $mail->clearAddresses();
                $mail->addAddress($user['email'], $user['fullname'] ?: $user['username']);
                $mail->Subject = 'Account Deleted — ' . ($site_name ?? 'Transient House & Tours');
                $mail->Body = '<html><body style="font-family: Arial, sans-serif; padding: 20px; background: #f5f7fa;"><div style="max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 40px;"><h2 style="color: #0B2447;">Account Deleted</h2><p>Dear <strong>' . htmlspecialchars($user['fullname'] ?: $user['username']) . '</strong>,</p><p>Your account (<strong>' . htmlspecialchars($user['username']) . '</strong>) has been permanently deleted.</p><div style="background: #fef3c7; padding: 15px; border-radius: 8px; color: #92400e;"><strong>Reason:</strong><br>' . htmlspecialchars($final_reason) . '</div></div></body></html>';
                $mail->AltBody = "Your account ({$user['username']}) has been permanently deleted.\n\nReason: {$final_reason}";
                $mail->send();
            }
        } catch (Exception $mailEx) {
            error_log("Delete user email failed: " . $mailEx->getMessage());
        }

        $pdo->prepare("DELETE FROM guests WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'delete', 'user',
                "Deleted user: {$user['username']} ({$user['role']}) — Reason: {$final_reason}",
                $user_id, 'user',
                ['username' => $user['username'], 'email' => $user['email'], 'role' => $user['role']],
                null, 'warning');
        }

        $_SESSION['flash_success'] = "User '{$user['username']}' deleted.";
        header("Location: user-management.php");
        exit();
    } catch(Exception $e) {
        $error = "Failed to delete user: " . $e->getMessage();
    }
}

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if(isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out",
            (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) $content[$row['section_name']][$row['content_key']] = $row['content_value'];
} catch(PDOException $e) { $content = []; }

$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) $nav_logo = $content['site_settings']['logo_path'];
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>User Management - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; overflow-x: hidden; }
        .app-container { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar { width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2); padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; border-right: 2px solid rgba(77, 166, 217, 0.15); z-index: 100; flex-shrink: 0; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }
        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; overflow: hidden; }
        .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; }
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; transform: rotate(90deg); }
        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active i { color: #7bb8f0; }
        .nav-link .nav-badge { margin-left: auto; background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 99; opacity: 0; transition: opacity 0.3s ease; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; align-items: center; justify-content: center; border: 1px solid rgba(77, 166, 217, 0.2); }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        .menu-toggle .fa-bars { transition: transform 0.3s ease; }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; }

        @media (max-width: 1024px) {
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); width: 280px; z-index: 1000; padding-top: 25px; }
            .sidebar.open { transform: translateX(0); box-shadow: 4px 0 30px rgba(0,0,0,0.4); }
            .menu-toggle { display: flex; }
            .sidebar-overlay.active { display: block; }
            .main-content { padding: 70px 16px 20px !important; }
            .sidebar-close-btn { display: flex; }
        }
        @media (max-width: 480px) {
            .sidebar { width: 85%; max-width: 300px; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; }
            .main-content { padding: 60px 12px 16px !important; }
        }

        .main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; }

        /* TOP BAR */
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11, 36, 71, 0.1); flex-wrap: wrap; gap: 10px; }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; }

        .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }

        .top-bar .user-profile .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            flex-shrink: 0;
            overflow: hidden;
            border: 2px solid rgba(77, 166, 217, 0.25);
        }
        .top-bar .user-profile .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .top-bar .user-profile .user-name { color: #0B2447; font-weight: 600; font-size: 14px; }
        .top-bar .user-profile .user-role { color: #4a6a8c; font-size: 12px; }

        .mobile-role-badge,
        .mobile-avatar {
            display: none;
        }

        @media (max-width: 768px) {
            .top-bar {
                position: relative;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                gap: 8px;
                padding-bottom: 15px;
                padding-left: 100px;
                padding-right: 100px;
                min-height: 130px;
            }

            .top-bar .page-title {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
                width: 100%;
            }

            .top-bar .page-title h1 {
                font-size: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                gap: 8px;
                margin: 0;
            }
            .top-bar .page-title h1 > i { font-size: 18px; }

            .top-bar .page-title h1 .mobile-role-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                white-space: nowrap;
            }
            .top-bar .page-title h1 .mobile-role-badge.admin {
                background: rgba(239, 68, 68, 0.12);
                color: #ef4444;
                border: 1.5px solid rgba(239, 68, 68, 0.25);
            }
            .top-bar .page-title h1 .mobile-role-badge.staff {
                background: rgba(251, 191, 36, 0.15);
                color: #d97706;
                border: 1.5px solid rgba(251, 191, 36, 0.3);
            }

            .top-bar .page-title h1 .mobile-avatar {
                display: inline-flex;
                position: absolute;
                top: 50%;
                right: 14px;
                transform: translateY(-50%);
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: 700;
                font-size: 32px;
                border: 4px solid #4DA6D9;
                flex-shrink: 0;
                box-shadow:
                    0 6px 20px rgba(77, 166, 217, 0.4),
                    0 0 0 4px rgba(255, 255, 255, 1),
                    0 0 0 7px rgba(77, 166, 217, 0.4);
                overflow: hidden;
            }
            .top-bar .page-title h1 .mobile-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                border-radius: 50%;
            }

            .top-bar .page-title p {
                font-size: 12px;
                text-align: center;
                margin: 0;
            }
            .top-bar .user-profile {
                display: none !important;
            }
        }

        @media (max-width: 480px) {
            .top-bar {
                padding-left: 92px;
                padding-right: 92px;
                min-height: 120px;
            }
            .top-bar .page-title h1 { font-size: 17px; gap: 6px; }
            .top-bar .page-title h1 > i { font-size: 15px; }
            .top-bar .page-title p { font-size: 11px; }
            .top-bar .page-title h1 .mobile-avatar {
                width: 72px;
                height: 72px;
                font-size: 28px;
                right: 12px;
            }
            .top-bar .page-title h1 .mobile-role-badge {
                font-size: 10px;
                padding: 3px 10px;
            }
        }

        .page-title-banner { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; box-shadow: 0 10px 30px rgba(11, 36, 71, 0.15); position: relative; overflow: hidden; }
        .page-title-banner .banner-content { position: relative; z-index: 1; }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner h1 i { margin-right: 10px; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }

        @media (max-width: 768px) {
            .page-title-banner { padding: 20px; text-align: center; border-radius: 16px; }
            .page-title-banner .underline { margin: 8px auto 0; }
            .page-title-banner h1 { font-size: 22px; }
        }

        .card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); margin-bottom: 30px; border: 1px solid #e8f0fe; overflow: hidden; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 15px; }
        .card-header h2 { font-size: 17px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
        .card-header h2 i { color: #4DA6D9; background: #eef2ff; padding: 8px; border-radius: 8px; font-size: 14px; }
        .card-header .header-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        @media (max-width: 768px) {
            .card { padding: 18px 15px; border-radius: 14px; }
            .card-header { flex-direction: column; align-items: stretch; gap: 10px; }
            .card-header h2 { font-size: 15px; }
        }

        .search-box { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search-box input { padding: 8px 15px; border: 2px solid #e2e8f0; border-radius: 10px; width: 250px; font-size: 14px; background: #fafafa; }
        .search-box input:focus { outline: none; border-color: #4DA6D9; background: white; }
        .search-box button { padding: 8px 20px; background: #4DA6D9; color: white; border: none; border-radius: 10px; cursor: pointer; font-weight: 500; }
        .btn-clear { background: #64748b; color: white; padding: 8px 15px; text-decoration: none; border-radius: 10px; font-size: 13px; font-weight: 500; }
        .btn-clear:hover { background: #475569; color: white; }

        @media (max-width: 768px) {
            .search-box { flex-direction: column; width: 100%; }
            .search-box input { width: 100%; }
        }

        .btn-sm { padding: 8px 18px; font-size: 13px; border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s; display: inline-block; text-decoration: none; margin: 2px; font-weight: 600; white-space: nowrap; }
        .btn-sm:hover { transform: translateY(-2px); }
        .btn-success { background: #10b981; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-info { background: #0ea5e9; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
        .btn-disabled:hover { transform: none; background: #e2e8f0; }

        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -5px; }
        table { width: 100%; border-collapse: collapse; min-width: 300px; }
        th { text-align: left; padding: 12px 14px; background: #f8fafc; color: #0B2447; font-weight: 600; font-size: 11px; text-transform: uppercase; white-space: nowrap; }
        td { padding: 12px 14px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 13px; vertical-align: middle; word-break: break-word; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f8fafc; }

        .badge { padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; text-transform: uppercase; white-space: nowrap; }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-info { background: #dbeafe; color: #3b82f6; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-danger { background: #fee2e2; color: #ef4444; }
        .badge-admin { background: #fee2e2; color: #ef4444; }
        .badge-staff { background: #fef3c7; color: #f59e0b; }
        .badge-guest { background: #e6f7e6; color: #10b981; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; padding: 30px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; }
        .modal-header h3 { font-size: 20px; font-weight: 700; color: #0B2447; margin: 0; }
        .modal-header h3 i { margin-right: 10px; color: #4DA6D9; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; background: none; border: none; padding: 0 10px; line-height: 1; }
        .modal-header .close:hover { color: #ef4444; }
        .modal-body { padding: 5px 0; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-group label .required { color: #dc2626; margin-left: 3px; }
        .form-group .form-control, .form-group .form-select { width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; background: #fafafa; max-width: 100%; overflow-wrap: anywhere; word-break: break-word; box-sizing: border-box; }
        .form-group .form-control:focus, .form-group .form-select:focus { outline: none; border-color: #4DA6D9; background: white; }
        .form-group .form-control.error { border-color: #dc2626; background: #fee2e2; }
        .form-group .help-text { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .name-input-wrapper { position: relative; }
        .name-input-wrapper .form-control { padding-right: 40px; }
        .name-input-wrapper .name-hint-icon {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            color: #cbd5e1; font-size: 13px;
            pointer-events: none; transition: color 0.3s;
        }
        .name-input-wrapper .form-control:focus ~ .name-hint-icon { color: #4DA6D9; }
        .name-error-msg {
            color: #dc2626; font-size: 12px;
            margin-top: 5px; display: none;
            align-items: center; gap: 5px;
        }
        .name-error-msg.show { display: flex; }

        .modal-content input,
        .modal-content select,
        .modal-content textarea {
            max-width: 100%;
            box-sizing: border-box;
        }

        .password-wrapper { position: relative; width: 100%; }
        .password-wrapper .form-control { padding-right: 45px; }
        .password-wrapper .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; padding: 6px 8px; cursor: pointer; color: #94a3b8; font-size: 16px; }
        .password-wrapper .toggle-password:hover { color: #4DA6D9; }
        .password-wrapper .toggle-password.active { color: #4DA6D9; }
        .password-wrapper .toggle-password i { pointer-events: none; }

        .password-requirements { font-size: 11px; margin-top: 6px; padding: 6px 12px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0; display: flex; flex-wrap: wrap; gap: 8px 12px; }
        .password-requirements .req { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; }
        .password-requirements .req .check { color: #10b981; }
        .password-requirements .req .cross { color: #ef4444; }
        .password-requirements .req .pending { color: #94a3b8; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-section-title { font-size: 14px; font-weight: 600; color: #4DA6D9; margin: 20px 0 15px; border-bottom: 1px solid #e8f0fe; padding-bottom: 10px; }
        .form-section-title i { margin-right: 8px; }

        @media (max-width: 768px) {
            .modal-content { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
        }

        .phone-input-group {
            display: flex;
            gap: 8px;
            align-items: stretch;
        }

        .phone-suffix-select {
            min-width: 95px;
            max-width: 110px;
            padding: 10px 8px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
            color: #0B2447;
            cursor: pointer;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .phone-suffix-select:focus {
            outline: none;
            border-color: #4DA6D9;
            background: white;
            box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1);
        }

        .phone-input-wrapper {
            position: relative;
            flex: 1;
            min-width: 0;
        }

        .phone-input-wrapper .form-control {
            padding-left: 38px;
            padding-right: 50px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .phone-input-wrapper .phone-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 13px;
            pointer-events: none;
        }

        .phone-input-wrapper .digit-count {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            color: #94a3b8;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
            pointer-events: none;
        }

        .phone-input-wrapper .digit-count.complete {
            color: #10b981;
            background: #d1fae5;
        }

        @media (max-width: 480px) {
            .phone-suffix-select {
                min-width: 80px;
                font-size: 12px;
                padding: 10px 6px;
            }
        }

        .user-profile-header { text-align: center; margin-bottom: 20px; }
        .user-avatar { width: 100px; height: 100px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; overflow: hidden; border: 3px solid #4DA6D9; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-avatar .default-icon { font-size: 40px; color: white; }
        .user-name { font-size: 20px; font-weight: 700; color: #1e293b; }
        .user-role { font-size: 13px; color: #4DA6D9; }
        .user-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .user-detail { padding: 10px 15px; background: #f8fafc; border-radius: 10px; display: flex; align-items: center; gap: 12px; border: 1px solid #e8f0fe; }
        .user-detail .detail-icon { width: 30px; color: #4DA6D9; font-size: 14px; text-align: center; flex-shrink: 0; }
        .user-detail .detail-content { flex: 1; min-width: 0; }
        .user-detail .detail-label { font-size: 9px; color: #94a3b8; text-transform: uppercase; font-weight: 600; }
        .user-detail .detail-value { font-weight: 500; color: #1e293b; font-size: 13px; word-wrap: break-word; overflow-wrap: anywhere; word-break: break-word; overflow: hidden; text-overflow: ellipsis; }
        .user-detail.full-width { grid-column: 1 / -1; }

        .health-tag { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; margin: 2px; }
        .health-tag.asthma { background: #fef3c7; color: #f59e0b; }
        .health-tag.allergy { background: #fee2e2; color: #ef4444; }
        .health-tag.medical { background: #dbeafe; color: #3b82f6; }
        .health-tag.dietary { background: #e6f7e6; color: #10b981; }
        .health-tag.access { background: #f3e8ff; color: #8b5cf6; }

        .id-photo-container { text-align: center; margin-top: 10px; }
        .id-photo-container img { max-width: 100%; max-height: 250px; border-radius: 10px; border: 2px solid #e2e8f0; object-fit: contain; background: white; }

        @media (max-width: 768px) {
            .user-detail-grid { grid-template-columns: 1fr; }
        }

        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }

        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 48px; margin-bottom: 15px; color: #cbd5e1; }

        .staff-restricted-badge { display: inline-block; background: rgba(251, 191, 36, 0.15); color: #fbbf24; padding: 2px 10px; border-radius: 20px; font-size: 9px; font-weight: 600; text-transform: uppercase; border: 1px solid rgba(251, 191, 36, 0.2); }

        .footer { background: #0B2447; color: #b3d9ff; padding: 15px 0; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77, 166, 217, 0.15); }
        .footer i { color: #4DA6D9; }

        .booking-cards-mobile {
            display: none;
            flex-direction: column;
            gap: 12px;
        }
        @media (min-width: 769px) {
            .booking-cards-mobile { display: none !important; }
        }
        @media (max-width: 768px) {
            .table-responsive.desktop-table { display: none !important; }
            .booking-cards-mobile { display: flex; }
        }

        .booking-card-mobile {
            background: white;
            border-radius: 14px;
            padding: 14px;
            border: 1px solid #e8f0fe;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .booking-card-mobile .card-top-row {
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: 10px;
        }
        .booking-card-mobile .card-ref {
            font-weight: 700; color: #0B2447; font-size: 13px;
            word-break: break-all; line-height: 1.4;
        }
        .booking-card-mobile .card-badges {
            display: flex; gap: 4px; flex-wrap: wrap;
            justify-content: flex-end; flex-shrink: 0;
        }
        .booking-card-mobile .card-row {
            display: flex; align-items: center; gap: 8px;
            font-size: 12.5px; color: #475569;
        }
        .booking-card-mobile .card-row i {
            width: 16px; color: #4DA6D9;
            flex-shrink: 0; text-align: center;
        }
        .booking-card-mobile .card-row .card-label {
            color: #94a3b8; font-size: 10px; font-weight: 700;
            min-width: 46px; text-transform: uppercase;
        }
        .booking-card-mobile .card-row .card-value {
            font-weight: 600; color: #0B2447;
            word-break: break-word; flex: 1; min-width: 0;
        }
        .booking-card-mobile .card-actions {
            display: flex; gap: 8px; flex-wrap: wrap;
            padding-top: 8px; border-top: 1px solid #f1f5f9;
        }
        .booking-card-mobile .btn-card-action {
            flex: 1; min-width: 80px; min-height: 42px;
            padding: 10px 14px; border: none; border-radius: 10px;
            font-weight: 700; font-size: 12.5px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px; text-decoration: none; transition: all 0.2s;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
        }
        .booking-card-mobile .btn-card-action:active { transform: scale(0.97); }
        .booking-card-mobile .btn-view-mobile {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white; box-shadow: 0 4px 12px rgba(14, 165, 233, 0.25);
        }
        .booking-card-mobile .btn-edit-mobile {
            background: #f59e0b; color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
        }
        .booking-card-mobile .btn-delete-mobile {
            background: #ef4444; color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }
        .booking-card-mobile .btn-disabled-mobile {
            background: #e2e8f0; color: #94a3b8; cursor: not-allowed;
        }

        /* LOGOUT MODAL */
        .logout-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; }
        .logout-modal-overlay.show { display: flex; }
        .logout-modal { background: white; border-radius: 24px; max-width: 400px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); border-top: 6px solid #ef4444; }
        .logout-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; }
        .logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
        .logout-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
        .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-logout-cancel, .btn-logout-confirm { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .btn-logout-cancel { background: #e2e8f0; color: #475569; }
        .btn-logout-confirm { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        @media (max-width: 480px) {
            .logout-modal { padding: 28px 22px 20px; }
            .logout-modal-actions { flex-direction: column-reverse; }
            .btn-logout-cancel, .btn-logout-confirm { width: 100%; }
        }

        /* ===============================
   USER MANAGEMENT REDESIGN
================================ */

.user-stats-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:20px;
    margin-bottom:25px;
}

.user-stat-card{
    background:white;
    border-radius:20px;
    padding:22px;
    border:1px solid #e8f0fe;
    box-shadow:0 10px 30px rgba(11,36,71,.08);
    display:flex;
    align-items:center;
    gap:15px;
}

.user-stat-icon{
    width:55px;
    height:55px;
    border-radius:16px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    color:white;
}

.user-stat-icon.blue{
    background:#4DA6D9;
}

.user-stat-icon.green{
    background:#10b981;
}

.user-stat-icon.yellow{
    background:#f59e0b;
}

.user-stat-icon.red{
    background:#ef4444;
}

.user-stat-number{
    font-size:28px;
    font-weight:800;
    color:#0B2447;
}

.user-stat-label{
    font-size:13px;
    color:#64748b;
}


.user-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    flex-wrap:wrap;
}

.user-toolbar-left{
    display:flex;
    align-items:center;
    gap:10px;
}


.user-search{
    display:flex;
    gap:10px;
}

.user-search input{
    width:300px;
    padding:12px 16px;
    border-radius:12px;
    border:2px solid #e2e8f0;
}


.user-filter{
    padding:12px 16px;
    border-radius:12px;
    border:2px solid #e2e8f0;
}


@media(max-width:900px){

    .user-stats-grid{
        grid-template-columns:repeat(2,1fr);
    }

}


@media(max-width:600px){

    .user-stats-grid{
        grid-template-columns:1fr;
    }

    .user-search input{
        width:100%;
    }

}

/* ===============================
   USER TOOLBAR REDESIGN
================================ */

.user-management-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    flex-wrap:wrap;
    padding:20px;
    background:#ffffff;
    border-radius:18px;
    border:1px solid #e8f0fe;
}


.user-toolbar-title{
    display:flex;
    align-items:center;
    gap:12px;
}


.user-toolbar-title i{
    width:42px;
    height:42px;
    border-radius:12px;
    background:#e8f5ff;
    color:#4DA6D9;
    display:flex;
    align-items:center;
    justify-content:center;
}


.user-toolbar-title h2{
    margin:0;
    font-size:20px;
    color:#0B2447;
}


.user-toolbar-actions{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}


.user-toolbar-actions input{
    width:320px;
    padding:12px 16px;
    border-radius:12px;
    border:2px solid #e2e8f0;
    font-size:14px;
}


.user-toolbar-actions input:focus{
    outline:none;
    border-color:#4DA6D9;
}


.user-search-btn{
    padding:12px 20px;
    border-radius:12px;
    background:#4DA6D9;
    color:white;
    border:none;
    font-weight:600;
}


.user-add-btn{
    padding:12px 20px;
    border-radius:12px;
    background:#10b981;
    color:white;
    border:none;
    font-weight:600;
}


@media(max-width:768px){

    .user-management-toolbar{
        flex-direction:column;
        align-items:stretch;
    }

    .user-toolbar-actions{
        width:100%;
    }

    .user-toolbar-actions input{
        width:100%;
    }

}

/* ===============================
   USER CARD GRID
================================ */

.user-card-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:22px;
    padding:25px;
    width:100%;
    box-sizing:border-box;
}


.user-card{
    background:white;
    border-radius:24px;
    border:1px solid #e5edf5;
    padding:20px;
    box-shadow:
        0 8px 25px rgba(6,38,61,.06);
    transition:.25s ease;
    position:relative;
}


.user-card:hover{
    transform:translateY(-6px);
    box-shadow:
        0 18px 45px rgba(6,38,61,.12);
}


.user-card-top{
    display:flex;
    align-items:center;
    gap:15px;
    padding-bottom:18px;
    border-bottom:1px solid #edf2f7;
}


.user-card .user-avatar{
    width:60px;
    height:60px;
    font-size:24px;
    flex-shrink:0;
}


.user-name{
    font-size:20px;
    font-weight:800;
    color:#0B2447;
}


.user-username{
    font-size:13px;
    color:#64748b;
}


.user-info{
    margin-top:18px;
}


.user-info-item{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:14px;
    font-size:14px;
}


.user-info-item strong{
    color:#64748b;
}


.user-card-actions{
    margin-top:20px;
    padding-top:15px;
    border-top:1px solid #edf2f7;
}


.user-card-actions button{
    border-radius:12px!important;
}


.user-card-top{
    display:flex;
    align-items:center;
    gap:15px;
}


.user-card .user-avatar{
    width:55px;
    height:55px;
    border-radius:50%;
    background:#4DA6D9;
    color:white;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    font-weight:700;
}


.user-name{
    font-size:18px;
    font-weight:700;
    color:#0B2447;
}


.user-username{
    color:#64748b;
    font-size:14px;
}

.user-card{
    min-width:0;
    overflow:hidden;
}


.user-card .user-username{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}


.user-info{
    margin-top:20px;
}


.user-info-item{
    margin-bottom:12px;
    color:#475569;
}


.user-info-item strong{
    color:#0B2447;
}


.user-card-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}


.user-card-actions button{
    flex:1;
    min-width:90px;
}


.user-card-actions button,
.user-card-actions a{
    flex:1;
}


@media(max-width:1100px){

    .user-card-grid{
        grid-template-columns:repeat(2,1fr);
    }

}


@media(max-width:700px){

    .user-card-grid{
        grid-template-columns:1fr;
    }

}

.role-badge{
    display:inline-block;
    padding:5px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    margin-left:8px;
}


.role-badge.admin{
    background:#fee2e2;
    color:#dc2626;
}


.role-badge.staff{
    background:#fef3c7;
    color:#d97706;
}


.role-badge.guest{
    background:#dcfce7;
    color:#16a34a;
}


.booking-count{
    display:inline-block;
    margin-left:8px;
    background:#dcfce7;
    color:#16a34a;
    padding:5px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

    

/* PROFESSIONAL USER TABLE UI FIX */
.professional-user-table-wrap{
    width:100%;
    overflow-x:auto;
    border-radius:18px;
    background:#fff;
}
.professional-user-table{
    width:100%;
    min-width:1200px;
    table-layout:fixed;
    border-collapse:separate;
    border-spacing:0;
}
.professional-user-table th{
    padding:16px 14px;
    font-size:12px;
    letter-spacing:.4px;
    color:#0B2447;
    background:#f8fafc;
    white-space:nowrap;
}
.professional-user-table td{
    padding:18px 14px;
    vertical-align:middle;
    height:80px;
}
.professional-user-table th:nth-child(1){width:45px}

.professional-user-table th:nth-child(2){
    width:170px;
}

.professional-user-table th:nth-child(3){
    width:200px;
}

.professional-user-table th:nth-child(4){
    width:260px;
}

.professional-user-table th:nth-child(5){width:110px}
.professional-user-table th:nth-child(6){width:120px}
.professional-user-table th:nth-child(7){width:130px}
.professional-user-table th:nth-child(8){width:150px}

.table-user{
    display:flex;
    align-items:center;
    gap:12px;
    min-width:0;
}
.table-avatar{
    width:42px;
    height:42px;
    flex:none;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#4DA6D9;
    color:white;
    font-weight:800;
}
.table-user strong{
    display:block;
    max-width:90px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.email-cell{
    word-break:break-word;
    line-height:1.35;
}

.professional-user-table .role-badge{
    margin:0;
    min-width:72px;
    text-align:center;
    padding:7px 10px;
    white-space:nowrap;
}

.professional-user-table .booking-count{
    margin:0;
    white-space:nowrap;
}

.table-actions{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:nowrap;
}
.table-actions .btn-sm{
    width:42px;
    height:42px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:12px;
}

@media(max-width:1200px){
    .professional-user-table{
        min-width:1050px;
    }
}

/* ===============================
   USER TABLE ACTIONS
================================ */

.table-actions{
    display:flex;
    gap:8px;
    align-items:center;
    justify-content:center;
    flex-wrap:nowrap;
}

.user-action-btn{
    width:42px;
    height:38px;
    padding:0;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    white-space:nowrap;
}


.table-actions{
    display:flex;
    gap:6px;
    justify-content:center;
}

.actions-column{
    white-space:nowrap;
}


.user-action-btn i{
    font-size:13px;
}


.user-action-btn:hover{
    transform:translateY(-2px);
}


.user-action-view{
    background:#0ea5e9;
    color:white;
}


.user-action-edit{
    background:#f59e0b;
    color:white;
}


.user-action-delete{
    background:#ef4444;
    color:white;
}


@media(max-width:900px){

    .user-action-btn span{
        display:none;
    }

    .user-action-btn{
        width:38px;
        padding:0;
    }

}

/* USER TABLE ALIGNMENT FIX */

.users-table{
    width:100%;
    table-layout:fixed;
}


.users-table th,
.users-table td{
    vertical-align:middle;
}


.users-table th:nth-child(1),
.users-table td:nth-child(1){
    width:50px;
}


.users-table th:nth-child(2),
.users-table td:nth-child(2){
    width:120px;
}


.users-table th:nth-child(3),
.users-table td:nth-child(3){
    width:180px;
}


.users-table th:nth-child(4),
.users-table td:nth-child(4){
    width:240px;
}


.users-table th:nth-child(5),
.users-table td:nth-child(5){
    width:110px;
}


.users-table th:nth-child(6),
.users-table td:nth-child(6){
    width:130px;
}


.users-table th:nth-child(7),
.users-table td:nth-child(7){
    width:130px;
}


.users-table th:nth-child(8),
.users-table td:nth-child(8){
    width:260px;
}


.users-table td{
    overflow:hidden;
    text-overflow:ellipsis;
}


.users-table td:nth-child(4){
    word-break:break-word;
}

        .nav-link .nav-badge.blocked { background: rgba(100, 116, 139, 0.3); color: #cbd5e1; }
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
                            <img src="<?php echo htmlspecialchars($nav_logo); ?>?<?php echo time(); ?>" alt="<?php echo htmlspecialchars($site_name); ?>">
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
            <li class="nav-item"><a href="user-management.php" class="nav-link active"><i class="fas fa-users"></i><span>User Management</span><?php if($is_staff): ?><span class="nav-badge" style="background: rgba(251, 191, 36, 0.2); color: #fbbf24;">View</span><?php endif; ?></a></li>
            <li class="nav-item"><a href="house-dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>House Management</span></a></li>
            <li class="nav-item"><a href="tour-dashboard.php" class="nav-link"><i class="fas fa-umbrella-beach"></i><span>Tour Management</span></a></li>
            <li class="nav-item"><a href="activities-dashboard.php" class="nav-link"><i class="fas fa-water"></i><span>Activities Management</span></a></li>
<li class="nav-item">
                <a href="food-dashboard.php" class="nav-link">
                    <i class="fas fa-utensils"></i><span>Food Management</span></a>
            </li>

            <!-- ✅ BOOKING — badge = pending bookings -->
            <li class="nav-item">
                <a href="booking-management.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i><span>Booking Management</span>
                    <?php if($sidebar_pending_bookings > 0): ?>
                        <span class="nav-badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;"><?php echo $sidebar_pending_bookings; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item"><a href="blocked-dates.php" class="nav-link"><i class="fas fa-ban"></i><span>Blocked Dates</span></a></li>

            <!-- ✅ REVIEWS — badge = PENDING only -->
            <li class="nav-item">
                <a href="reviews-management.php" class="nav-link">
                    <i class="fas fa-star"></i><span>Reviews Management</span>
                    <?php if($sidebar_pending_reviews > 0): ?>
                        <span class="nav-badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981;"><?php echo $sidebar_pending_reviews; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Sales Report</span></a></li>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span><?php if($is_staff): ?><span class="nav-badge" style="background: rgba(251, 191, 36, 0.2); color: #fbbf24;">View</span><?php endif; ?></a></li>

            <?php if($is_admin): ?>
            <!-- ✅ SYSTEM LOGS — badge = failed only -->
            <li class="nav-item">
                <a href="system-logs.php" class="nav-link">
                    <i class="fas fa-history"></i><span>System Logs</span>
                    <?php if($sidebar_failed_logs > 0): ?>
                        <span class="nav-badge"><?php echo $sidebar_failed_logs; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <div class="nav-divider"></div>

            <li class="nav-item">
                <a href="admin-profile.php" class="nav-link">
                    <i class="fas fa-user-circle"></i><span>My Profile</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="#" class="nav-link" onclick="openLogoutModal(event); return false;">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- TOP BAR -->
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-users"></i>
                    User Management

                    <span class="mobile-role-badge <?php echo $is_admin ? 'admin' : 'staff'; ?>">
                        <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                        <?php echo $is_admin ? 'Admin' : 'Staff'; ?>
                    </span>
                    <span class="mobile-avatar" title="<?php echo htmlspecialchars($admin_display_name); ?>">
                        <?php if($admin_avatar): ?>
                            <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $admin_initial; ?>
                        <?php endif; ?>
                    </span>
                </h1>
                <p>Manage all registered users and their accounts</p>
            </div>

            <div class="user-profile">
                <div class="user-info" style="text-align: right;">
                    <div class="user-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
                    <div class="user-role">
                        <?php if($is_admin): ?><i class="fas fa-crown" style="color: #fbbf24;"></i> Admin
                        <?php else: ?><i class="fas fa-user-tie" style="color: #fbbf24;"></i> Staff<?php endif; ?>
                    </div>
                </div>
                <div class="avatar">
                    <?php if($admin_avatar): ?>
                        <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo $admin_initial; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if(isset($success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if(isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        

       <div class="page-title-banner">
    <div class="banner-content">
        <h1>
            <i class="fas fa-users-cog"></i>
            User Management
        </h1>

        <p style="margin-top:8px;opacity:.85;">
            Manage accounts, roles, and customer activity
        </p>

        <div class="underline"></div>
    </div>
</div>

<div class="user-stats-grid">

    <div class="user-stat-card">
        <div class="user-stat-icon blue">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <div class="user-stat-number">
                <?php echo $total_users; ?>
            </div>
            <div class="user-stat-label">
                Total Users
            </div>
        </div>
    </div>


    <div class="user-stat-card">
        <div class="user-stat-icon green">
            <i class="fas fa-user"></i>
        </div>
        <div>
            <div class="user-stat-number">
                <?php echo $total_guests; ?>
            </div>
            <div class="user-stat-label">
                Guests
            </div>
        </div>
    </div>


    <div class="user-stat-card">
        <div class="user-stat-icon yellow">
            <i class="fas fa-user-tie"></i>
        </div>
        <div>
            <div class="user-stat-number">
                <?php echo $total_staff; ?>
            </div>
            <div class="user-stat-label">
                Staff
            </div>
        </div>
    </div>


    <div class="user-stat-card">
        <div class="user-stat-icon red">
            <i class="fas fa-crown"></i>
        </div>
        <div>
            <div class="user-stat-number">
                <?php echo $total_admins; ?>
            </div>
            <div class="user-stat-label">
                Administrators
            </div>
        </div>
    </div>

</div>


        <div class="card">
           <div class="user-management-toolbar">

    <div class="user-toolbar-title">
        <i class="fas fa-users"></i>

        <div>
            <h2>Registered Users</h2>
            <small style="color:#64748b;">
                Manage accounts and permissions
            </small>
        </div>
    </div>


    <div class="user-toolbar-actions">
        <select id="roleFilter" class="user-filter">
    <option value="all">All Roles</option>
    <option value="admin">Admin</option>
    <option value="staff">Staff</option>
    <option value="guest">Guest</option>
</select>

        <form method="GET" style="display:flex;gap:10px;">

            <input 
    type="text"
    id="userSearchInput"
    placeholder="Search users..."
    autocomplete="off"
>

            <button class="user-search-btn">
                <i class="fas fa-search"></i>
                Search
            </button>

        </form>


        <?php if($is_admin): ?>

        <button 
            class="user-add-btn"
            onclick="showModal('addUser')">

            <i class="fas fa-user-plus"></i>
            Add User

        </button>

        <?php endif; ?>

    </div>

</div>

            <!-- PROFESSIONAL USER TABLE -->
            <div class="professional-user-table-wrap">
                <table class="professional-user-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Registered</th>
                            <th>Bookings</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
               <?php foreach($users as $index => $user): ?>

<?php
    $userId = $user['id'];
    $bookings = $user_bookings_count[$userId] ?? 0;
    $role = strtolower($user['role']);
?>

<tr class="user-row"
data-role="<?php echo $role; ?>"
data-search="<?php echo strtolower(htmlspecialchars(
    $user['username'].' '.
    $user['full_name'].' '.
    $user['email']
)); ?>">


    <td><?php echo $index + 1; ?></td>
                            <td>
                                <div class="table-user">
                                    <div class="table-avatar"><?php echo strtoupper(substr($user['username'],0,1)); ?></div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($user['username']); ?></strong><?php if (preg_match('/@walkin\.invalid$/i', (string)($user['email'] ?? ''))): ?> <span title="Created by staff for a walk-in booking. This account cannot sign in." style="display:inline-block;margin-left:4px;padding:1px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:10px;font-weight:700;vertical-align:middle;">WALK-IN</span><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($user['full_name'] ?? 'N/A'); ?></td>
                            <td class="email-cell"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <span class="role-badge <?php echo $role; ?>">
                                    <?php echo ucfirst($role); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td><span class="booking-count"><?php echo $bookings; ?> bookings</span></td>
                            <td>
                                <div class="table-actions">


<button
class="user-action-btn user-action-view"
onclick="viewUser(<?php echo $userId; ?>)"
title="View user details">

<i class="fas fa-eye"></i>

</button>


<?php if($is_admin): ?>


<button
class="user-action-btn user-action-edit"
onclick="editUser(<?php echo $userId; ?>)"
title="Edit user">

<i class="fas fa-pen"></i>

</button>


<?php if($role !== 'admin'): ?>


<button
class="user-action-btn user-action-delete"
onclick="openDeleteUserModal(
<?php echo $userId; ?>,
'<?php echo addslashes($user['username']); ?>',
'<?php echo addslashes($user['email']); ?>'
)"
title="Delete user">

<i class="fas fa-trash"></i>

</button>


<?php endif; ?>


<?php endif; ?>


</div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <div class="footer">
            <p>
                <i class="fas fa-umbrella-beach"></i>
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Huddled Islands Tour and Reservation. All rights reserved.'); ?>
                <span style="opacity: 0.3; margin: 0 10px;">|</span>
                <span style="color: #7bb8f0; font-size: 11px;">
                    <i class="fas fa-user-shield"></i> <?php echo $is_admin ? 'Administrator' : 'Staff'; ?> Access
                </span>
            </p>
        </div>
    </div>
</div>

<!-- VIEW USER MODAL -->
<div class="modal" id="viewUserModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-circle"></i> User Details</h3>
            <button class="close" onclick="closeModal('viewUser')">&times;</button>
        </div>
        <div id="userDetails">
            <div style="text-align: center; padding: 20px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #4DA6D9;"></i>
                <p style="margin-top: 10px; color: #94a3b8;">Loading user details...</p>
            </div>
        </div>
    </div>
</div>

<?php if($is_admin): ?>
<!-- ADD USER MODAL -->
<div class="modal" id="addUserModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Add New User</h3>
            <button class="close" onclick="closeModal('addUser')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" onsubmit="return validateAddUserForm()">
                <div class="form-section-title"><i class="fas fa-lock"></i> Account Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Username <span class="required">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                        <div class="help-text">Minimum 4 characters</div>
                    </div>
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="add_password" class="form-control" required>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('add_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-requirements" id="addPasswordRequirements" style="display:none;">
                            <span class="req" id="addReqLength"><span class="pending"><i class="fas fa-circle"></i></span> 8 characters</span>
                            <span class="req" id="addReqUppercase"><span class="pending"><i class="fas fa-circle"></i></span> Uppercase</span>
                            <span class="req" id="addReqNumber"><span class="pending"><i class="fas fa-circle"></i></span> Number</span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <div class="name-input-wrapper">
                        <input type="text"
                               name="fullname"
                               id="add_fullname"
                               class="form-control"
                               placeholder="Enter full name"
                               maxlength="80"
                               data-name-field="1"
                               autocomplete="name"
                               required>
                        <i class="fas fa-font name-hint-icon"></i>
                    </div>
                    <div class="name-error-msg" id="addFullNameError">
                        <i class="fas fa-exclamation-circle"></i> <span id="addFullNameErrorText"></span>
                    </div>
                    <div class="help-text">Letters only — no numbers or special characters</div>
                </div>

                <div class="form-group">
                    <label>Role <span class="required">*</span></label>
                    <select name="role" class="form-select" required>
                        <option value="guest">Guest</option>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-section-title"><i class="fas fa-user"></i> Personal Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <div class="phone-input-group">
                            <select name="phone_suffix" id="add_phone_suffix" class="phone-suffix-select" aria-label="Country code">
                                <option value="+63">+63 🇵🇭</option>
                                <option value="+1">+1 🇺🇸</option>
                                <option value="+44">+44 🇬🇧</option>
                                <option value="+61">+61 🇦🇺</option>
                                <option value="+81">+81 🇯🇵</option>
                                <option value="+82">+82 🇰🇷</option>
                                <option value="+86">+86 🇨🇳</option>
                                <option value="+65">+65 🇸🇬</option>
                                <option value="+60">+60 🇲🇾</option>
                                <option value="+62">+62 🇮🇩</option>
                                <option value="+66">+66 🇹🇭</option>
                                <option value="+84">+84 🇻🇳</option>
                                <option value="+91">+91 🇮🇳</option>
                                <option value="+971">+971 🇦🇪</option>
                                <option value="+966">+966 🇸🇦</option>
                            </select>
                            <div class="phone-input-wrapper">
                                <i class="fas fa-phone phone-icon"></i>
                                <input type="tel"
                                       name="phone"
                                       id="add_phone"
                                       class="form-control"
                                       placeholder="9123456789"
                                       maxlength="10"
                                       inputmode="numeric"
                                       autocomplete="tel"
                                       oninput="handlePhoneInput(this, 'add_contact')">
                                <span class="digit-count" id="add_contact_count">0</span>
                            </div>
                        </div>
                        <div class="help-text">Select country code, then enter your number</div>
                    </div>
                    <div class="form-group">
                        <label>ID Type</label>
                        <select name="id_type" class="form-select">
                            <option value="">Select ID Type</option>
                            <option value="Passport">Passport</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="National ID">National ID</option>
                            <option value="UMID">UMID</option>
                            <option value="Postal ID">Postal ID</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>ID Number</label>
                        <input type="text"
                               name="id_number"
                               id="add_id_number"
                               class="form-control"
                               placeholder="e.g., AB1234567"
                               maxlength="20"
                               autocomplete="off"
                               oninput="sanitizeIdNumber(this)">
                        <div class="help-text">5-20 characters — letters, numbers, and hyphens only</div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" class="form-control" placeholder="Complete address">
                    </div>
                </div>
                <div class="form-section-title"><i class="fas fa-phone-alt"></i> Emergency Contact</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <div class="name-input-wrapper">
                            <input type="text"
                                   name="emergency_contact"
                                   id="add_emergency_contact"
                                   class="form-control"
                                   placeholder="Enter emergency contact name"
                                   maxlength="80"
                                   data-name-field="1"
                                   autocomplete="name">
                            <i class="fas fa-font name-hint-icon"></i>
                        </div>
                        <div class="name-error-msg" id="addEmergencyContactError">
                            <i class="fas fa-exclamation-circle"></i> <span id="addEmergencyContactErrorText"></span>
                        </div>
                        <div class="help-text">Letters only — no numbers or special characters</div>
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Number</label>
                        <div class="phone-input-group">
                            <select name="emergency_suffix" id="add_emergency_suffix" class="phone-suffix-select" aria-label="Country code">
                                <option value="+63">+63 🇵🇭</option>
                                <option value="+1">+1 🇺🇸</option>
                                <option value="+44">+44 🇬🇧</option>
                                <option value="+61">+61 🇦🇺</option>
                                <option value="+81">+81 🇯🇵</option>
                                <option value="+82">+82 🇰🇷</option>
                                <option value="+86">+86 🇨🇳</option>
                                <option value="+65">+65 🇸🇬</option>
                                <option value="+60">+60 🇲🇾</option>
                                <option value="+62">+62 🇮🇩</option>
                                <option value="+66">+66 🇹🇭</option>
                                <option value="+84">+84 🇻🇳</option>
                                <option value="+91">+91 🇮🇳</option>
                                <option value="+971">+971 🇦🇪</option>
                                <option value="+966">+966 🇸🇦</option>
                            </select>
                            <div class="phone-input-wrapper">
                                <i class="fas fa-phone-alt phone-icon"></i>
                                <input type="tel"
                                       name="emergency_number"
                                       id="add_emergency_number"
                                       class="form-control"
                                       placeholder="9123456789"
                                       maxlength="10"
                                       inputmode="numeric"
                                       autocomplete="tel"
                                       oninput="handlePhoneInput(this, 'add_emergency')">
                                <span class="digit-count" id="add_emergency_count">0</span>
                            </div>
                        </div>
                        <div class="help-text">Optional — select country code then enter number</div>
                    </div>
                </div>
                <button type="submit" name="add_user" class="btn-sm btn-success" style="width: 100%; padding: 12px; font-size: 16px; margin-top: 10px;">
                    <i class="fas fa-save"></i> Add User
                </button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal" id="editUserModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit"></i> Edit User</h3>
            <button class="close" onclick="closeModal('editUser')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="editUserForm" onsubmit="return validateEditUserForm()">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-section-title"><i class="fas fa-lock"></i> Account Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Username <span class="required">*</span></label>
                        <input type="text" name="username" id="edit_username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="new_password" id="edit_password" class="form-control" placeholder="Leave blank to keep current">
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('edit_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-requirements" id="editPasswordRequirements" style="display:none;">
                            <span class="req" id="editReqLength"><span class="pending"><i class="fas fa-circle"></i></span> 8 characters</span>
                            <span class="req" id="editReqUppercase"><span class="pending"><i class="fas fa-circle"></i></span> Uppercase</span>
                            <span class="req" id="editReqNumber"><span class="pending"><i class="fas fa-circle"></i></span> Number</span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" id="edit_email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <div class="name-input-wrapper">
                        <input type="text"
                               name="fullname"
                               id="edit_fullname"
                               class="form-control"
                               placeholder="Enter full name"
                               maxlength="80"
                               data-name-field="1"
                               autocomplete="name"
                               required>
                        <i class="fas fa-font name-hint-icon"></i>
                    </div>
                    <div class="name-error-msg" id="editFullNameError">
                        <i class="fas fa-exclamation-circle"></i> <span id="editFullNameErrorText"></span>
                    </div>
                    <div class="help-text">Letters only — no numbers or special characters</div>
                </div>

                <div class="form-group">
                    <label>Role <span class="required">*</span></label>
                    <select name="role" id="edit_role" class="form-select" required>
                        <option value="guest">Guest</option>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-section-title"><i class="fas fa-user"></i> Personal Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <div class="phone-input-group">
                            <select name="phone_suffix" id="edit_phone_suffix" class="phone-suffix-select" aria-label="Country code">
                                <option value="+63">+63 🇵🇭</option>
                                <option value="+1">+1 🇺🇸</option>
                                <option value="+44">+44 🇬🇧</option>
                                <option value="+61">+61 🇦🇺</option>
                                <option value="+81">+81 🇯🇵</option>
                                <option value="+82">+82 🇰🇷</option>
                                <option value="+86">+86 🇨🇳</option>
                                <option value="+65">+65 🇸🇬</option>
                                <option value="+60">+60 🇲🇾</option>
                                <option value="+62">+62 🇮🇩</option>
                                <option value="+66">+66 🇹🇭</option>
                                <option value="+84">+84 🇻🇳</option>
                                <option value="+91">+91 🇮🇳</option>
                                <option value="+971">+971 🇦🇪</option>
                                <option value="+966">+966 🇸🇦</option>
                            </select>
                            <div class="phone-input-wrapper">
                                <i class="fas fa-phone phone-icon"></i>
                                <input type="tel"
                                       name="phone"
                                       id="edit_phone"
                                       class="form-control"
                                       placeholder="9123456789"
                                       maxlength="10"
                                       inputmode="numeric"
                                       autocomplete="tel"
                                       oninput="handlePhoneInput(this, 'edit_contact')">
                                <span class="digit-count" id="edit_contact_count">0</span>
                            </div>
                        </div>
                        <div class="help-text">Select country code, then enter number</div>
                    </div>
                    <div class="form-group">
                        <label>ID Type</label>
                        <select name="id_type" id="edit_id_type" class="form-select">
                            <option value="">Select ID Type</option>
                            <option value="Passport">Passport</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="National ID">National ID</option>
                            <option value="UMID">UMID</option>
                            <option value="Postal ID">Postal ID</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>ID Number</label>
                        <input type="text"
                               name="id_number"
                               id="edit_id_number"
                               class="form-control"
                               placeholder="e.g., AB1234567"
                               maxlength="20"
                               autocomplete="off"
                               oninput="sanitizeIdNumber(this)">
                        <div class="help-text">5-20 characters — letters, numbers, and hyphens only</div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" id="edit_address" class="form-control">
                    </div>
                </div>
                <div class="form-section-title"><i class="fas fa-phone-alt"></i> Emergency Contact</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <div class="name-input-wrapper">
                            <input type="text"
                                   name="emergency_contact"
                                   id="edit_emergency_contact"
                                   class="form-control"
                                   placeholder="Enter emergency contact name"
                                   maxlength="80"
                                   data-name-field="1"
                                   autocomplete="name">
                            <i class="fas fa-font name-hint-icon"></i>
                        </div>
                        <div class="name-error-msg" id="editEmergencyContactError">
                            <i class="fas fa-exclamation-circle"></i> <span id="editEmergencyContactErrorText"></span>
                        </div>
                        <div class="help-text">Letters only — no numbers or special characters</div>
                    </div>
                    <div class="form-group">
                        <label>Emergency Contact Number</label>
                        <div class="phone-input-group">
                            <select name="emergency_suffix" id="edit_emergency_suffix" class="phone-suffix-select" aria-label="Country code">
                                <option value="+63">+63 🇵🇭</option>
                                <option value="+1">+1 🇺🇸</option>
                                <option value="+44">+44 🇬🇧</option>
                                <option value="+61">+61 🇦🇺</option>
                                <option value="+81">+81 🇯🇵</option>
                                <option value="+82">+82 🇰🇷</option>
                                <option value="+86">+86 🇨🇳</option>
                                <option value="+65">+65 🇸🇬</option>
                                <option value="+60">+60 🇲🇾</option>
                                <option value="+62">+62 🇮🇩</option>
                                <option value="+66">+66 🇹🇭</option>
                                <option value="+84">+84 🇻🇳</option>
                                <option value="+91">+91 🇮🇳</option>
                                <option value="+971">+971 🇦🇪</option>
                                <option value="+966">+966 🇸🇦</option>
                            </select>
                            <div class="phone-input-wrapper">
                                <i class="fas fa-phone-alt phone-icon"></i>
                                <input type="tel"
                                       name="emergency_number"
                                       id="edit_emergency_number"
                                       class="form-control"
                                       placeholder="9123456789"
                                       maxlength="10"
                                       inputmode="numeric"
                                       autocomplete="tel"
                                       oninput="handlePhoneInput(this, 'edit_emergency')">
                                <span class="digit-count" id="edit_emergency_count">0</span>
                            </div>
                        </div>
                        <div class="help-text">Optional — select country code then enter number</div>
                    </div>
                </div>
                <button type="submit" name="edit_user" class="btn-sm btn-warning" style="width: 100%; padding: 12px; font-size: 16px; margin-top: 10px;">
                    <i class="fas fa-save"></i> Update User
                </button>
            </form>
        </div>
    </div>
</div>

<!-- DELETE USER MODAL -->
<div class="modal" id="deleteUserModal">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header" style="border-bottom: 2px solid #fee2e2;">
            <h3 style="color: #991b1b;"><i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> Delete User?</h3>
            <button class="close" onclick="closeModal('deleteUser')">&times;</button>
        </div>
        <div class="modal-body" style="text-align: center; padding: 10px 0;">
            <div style="font-size: 56px; color: #ef4444; margin-bottom: 12px;">
                <i class="fas fa-user-slash"></i>
            </div>
            <p style="color: #475569; font-size: 15px; margin-bottom: 6px;">Are you sure you want to delete</p>
            <p style="font-weight: 700; color: #0B2447; font-size: 18px; margin-bottom: 4px;">
                <span id="delete_username"></span>
            </p>
            <p style="font-size: 13px; color: #94a3b8; margin-bottom: 20px;">
                <i class="fas fa-envelope"></i> <span id="delete_email"></span>
            </p>

            <form method="POST" id="deleteUserForm">
                <input type="hidden" name="user_id" id="delete_user_id">

                <div style="text-align: left; margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                        <i class="fas fa-comment-alt" style="color: #ef4444;"></i> Reason for deletion <span style="color: #dc2626;">*</span>
                    </label>
                    <select name="delete_reason" id="delete_reason" class="form-control" required
                            style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; background: #fafafa;"
                            onchange="toggleReasonOther()">
                        <option value="">-- Select a reason --</option>
                        <option value="Violation of Terms of Service">Violation of Terms of Service</option>
                        <option value="Spam or fake bookings">Spam or fake bookings</option>
                        <option value="Fraudulent activity">Fraudulent activity</option>
                        <option value="Inappropriate behavior">Inappropriate behavior</option>
                        <option value="Duplicate account">Duplicate account</option>
                        <option value="User requested deletion">User requested deletion</option>
                        <option value="Inactive account">Inactive account</option>
                        <option value="Other">Other (specify below)</option>
                    </select>
                </div>

                <div id="reasonOtherWrapper" style="display: none; text-align: left; margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                        <i class="fas fa-pen" style="color: #ef4444;"></i> Please specify
                    </label>
                    <input type="text" name="delete_reason_other" id="delete_reason_other"
                           class="form-control"
                           placeholder="Type the reason here..."
                           style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; background: #fafafa;">
                </div>

                <div style="text-align: left; margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                        <i class="fas fa-sticky-note" style="color: #64748b;"></i> Additional notes <span style="color: #94a3b8; font-weight: 400;">(optional)</span>
                    </label>
                    <textarea name="delete_reason_notes" id="delete_reason_notes"
                              class="form-control"
                              rows="3"
                              placeholder="Add more details here..."
                              style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; background: #fafafa; resize: vertical; font-family: inherit;"></textarea>
                </div>

                <div style="background: #fee2e2; padding: 12px 15px; border-radius: 10px; text-align: left; font-size: 12.5px; color: #991b1b; margin-bottom: 15px; border-left: 4px solid #ef4444;">
                    <strong>⚠️ This action cannot be undone!</strong><br>
                    • All data will be permanently removed.<br>
                    • The reason will be <strong>sent to the user via email</strong>.
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn-sm"
                            style="flex: 1; padding: 12px; background: #e2e8f0; color: #475569; border-radius: 10px; cursor: pointer; font-weight: 700;"
                            onclick="closeModal('deleteUser')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="delete_user" class="btn-sm btn-danger"
                            style="flex: 1; padding: 12px; background: #ef4444; color: white; border-radius: 10px; cursor: pointer; font-weight: 700;">
                        <i class="fas fa-trash"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- LOGOUT MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon"><i class="fas fa-sign-out-alt"></i></div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="?logout=1" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<script>
// ============================================================
// SIDEBAR TOGGLE
// ============================================================
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('menuToggle');
    const willOpen = !sidebar.classList.contains('open');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    toggleBtn.classList.toggle('active');
    if (willOpen && window.innerWidth <= 1024) document.body.classList.add('sidebar-open-mobile');
    else document.body.classList.remove('sidebar-open-mobile');
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('open')) toggleSidebar();
    }
});

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 1024 && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        document.getElementById('menuToggle').classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
        document.body.style.overflow = 'auto';
    }
});

// ============================================================
// ✅ FULL NAME INPUT — Letters only restriction
// ============================================================
function setupFullNameInput(inputId, errorBoxId, errorTextId) {
    const input = document.getElementById(inputId);
    const errorBox = document.getElementById(errorBoxId);
    const errorText = document.getElementById(errorTextId);
    if (!input) return;

    const NAME_PATTERN = /^[a-zA-ZÀ-ÿñÑ\s\-'.]*$/;

    input.addEventListener('input', function() {
        const original = this.value;
        let cleaned = this.value.replace(/[^a-zA-ZÀ-ÿñÑ\s\-'.]/g, '');
        cleaned = cleaned.replace(/\s+/g, ' ');

        if (original !== cleaned) {
            const cursorPos = this.selectionStart;
            const removed = original.length - cleaned.length;
            this.value = cleaned;
            try {
                this.setSelectionRange(
                    Math.max(0, cursorPos - removed),
                    Math.max(0, cursorPos - removed)
                );
            } catch (e) {}
        }

        if (errorBox && errorText) {
            if (cleaned.length > 0) {
                if (/\d/.test(cleaned)) {
                    errorBox.classList.add('show');
                    errorText.textContent = 'Name cannot contain numbers.';
                    input.classList.add('error');
                } else if (!NAME_PATTERN.test(cleaned)) {
                    errorBox.classList.add('show');
                    errorText.textContent = 'Name contains invalid characters.';
                    input.classList.add('error');
                } else {
                    errorBox.classList.remove('show');
                    input.classList.remove('error');
                }
            } else {
                errorBox.classList.remove('show');
                input.classList.remove('error');
            }
        }
    });

    input.addEventListener('keypress', function(e) {
        if (e.which === 8 || e.which === 0 || e.which === 13) return;
        if (e.ctrlKey || e.metaKey) return;
        const char = String.fromCharCode(e.which);
        if (!/^[a-zA-ZÀ-ÿñÑ\s\-'.]$/.test(char)) {
            e.preventDefault();
        }
    });

    input.addEventListener('paste', function(e) {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text');
        const cleaned = pasted.replace(/[^a-zA-ZÀ-ÿñÑ\s\-'.]/g, '').replace(/\s+/g, ' ');
        const start = this.selectionStart;
        const end = this.selectionEnd;
        const currentValue = this.value;
        const newValue = (currentValue.slice(0, start) + cleaned + currentValue.slice(end)).slice(0, 80);
        this.value = newValue;
        const newPos = start + cleaned.length;
        try {
            this.setSelectionRange(newPos, newPos);
        } catch (err) {}
        this.dispatchEvent(new Event('input'));
    });

    input.addEventListener('drop', function(e) {
        e.preventDefault();
    });
}

document.addEventListener('DOMContentLoaded', function() {
    setupFullNameInput('add_fullname', 'addFullNameError', 'addFullNameErrorText');
    setupFullNameInput('edit_fullname', 'editFullNameError', 'editFullNameErrorText');
    setupFullNameInput('add_emergency_contact', 'addEmergencyContactError', 'addEmergencyContactErrorText');
    setupFullNameInput('edit_emergency_contact', 'editEmergencyContactError', 'editEmergencyContactErrorText');
});

// ============================================================
// ✅ COUNTRY CODE → PHONE LIMITS + AUTO-PREFIX FOR PH
// ============================================================
const PHONE_LIMITS = {
    '+63':  { max: 10, prefix: '9', placeholder: '9123456789' },
    '+1':   { max: 10, prefix: '',  placeholder: '2025551234' },
    '+44':  { max: 10, prefix: '',  placeholder: '7911123456' },
    '+61':  { max: 9,  prefix: '',  placeholder: '412345678' },
    '+81':  { max: 10, prefix: '',  placeholder: '9012345678' },
    '+82':  { max: 10, prefix: '',  placeholder: '1012345678' },
    '+86':  { max: 11, prefix: '',  placeholder: '13123456789' },
    '+65':  { max: 8,  prefix: '',  placeholder: '81234567' },
    '+60':  { max: 10, prefix: '',  placeholder: '123456789' },
    '+62':  { max: 11, prefix: '',  placeholder: '8123456789' },
    '+66':  { max: 9,  prefix: '',  placeholder: '812345678' },
    '+84':  { max: 9,  prefix: '',  placeholder: '912345678' },
    '+91':  { max: 10, prefix: '',  placeholder: '9876543210' },
    '+971': { max: 9,  prefix: '',  placeholder: '501234567' },
    '+966': { max: 9,  prefix: '',  placeholder: '501234567' }
};

function applyCountryCodeRules(selectEl, inputEl, counterId) {
    if (!selectEl || !inputEl) return;
    const code = selectEl.value;
    const rules = PHONE_LIMITS[code] || { max: 15, prefix: '', placeholder: '9123456789' };

    inputEl.setAttribute('maxlength', rules.max);
    inputEl.setAttribute('placeholder', rules.placeholder);

    let current = inputEl.value.replace(/[^0-9]/g, '');

    if (rules.prefix === '9' && current.length > 0 && !current.startsWith('9')) {
        current = '9' + current;
    }

    if (current.length > rules.max) {
        current = current.slice(0, rules.max);
    }

    inputEl.value = current;

    if (counterId) {
        const counterEl = document.getElementById(counterId + '_count');
        if (counterEl) {
            counterEl.textContent = current.length;
            counterEl.classList.toggle('complete', current.length >= 7);
        }
    }
}

function handlePhoneInput(input, counterId) {
    const group = input.closest('.phone-input-group');
    if (!group) return;
    const selectEl = group.querySelector('.phone-suffix-select');
    const code = selectEl ? selectEl.value : '';
    const rules = PHONE_LIMITS[code] || { max: 15, prefix: '', placeholder: '' };

    let cleaned = input.value.replace(/[^0-9]/g, '');

    if (rules.prefix === '9' && cleaned.length > 0 && !cleaned.startsWith('9')) {
        cleaned = '9' + cleaned;
    }

    if (cleaned.length > rules.max) {
        cleaned = cleaned.slice(0, rules.max);
    }

    if (input.value !== cleaned) {
        const cursorPos = input.selectionStart;
        const removed = input.value.length - cleaned.length;
        input.value = cleaned;
        try {
            const newPos = Math.max(0, cursorPos - removed);
            input.setSelectionRange(newPos, newPos);
        } catch (e) {}
    }

    if (counterId) {
        const counterEl = document.getElementById(counterId + '_count');
        if (counterEl) {
            counterEl.textContent = cleaned.length;
            counterEl.classList.toggle('complete', cleaned.length >= 7);
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const phonePairs = [
        { select: 'add_phone_suffix',      input: 'add_phone',             counter: 'add_contact' },
        { select: 'add_emergency_suffix',  input: 'add_emergency_number',  counter: 'add_emergency' },
        { select: 'edit_phone_suffix',     input: 'edit_phone',            counter: 'edit_contact' },
        { select: 'edit_emergency_suffix', input: 'edit_emergency_number', counter: 'edit_emergency' }
    ];

    phonePairs.forEach(pair => {
        const selectEl = document.getElementById(pair.select);
        const inputEl = document.getElementById(pair.input);
        if (!selectEl || !inputEl) return;

        applyCountryCodeRules(selectEl, inputEl, pair.counter);

        selectEl.addEventListener('change', function() {
            applyCountryCodeRules(selectEl, inputEl, pair.counter);
        });
    });
});

// ============================================================
// ✅ ID NUMBER SANITIZER
// ============================================================
function sanitizeIdNumber(input) {
    input.value = input.value.replace(/[^A-Za-z0-9\-]/g, '');
    if (input.value.length > 20) {
        input.value = input.value.slice(0, 20);
    }
}

// ============================================================
// ✅ FORM VALIDATION
// ============================================================
function validateAddUserForm() {
    var fullName = document.getElementById('add_fullname');
    var phone = document.getElementById('add_phone');
    var emergency = document.getElementById('add_emergency_number');
    var idNumber = document.getElementById('add_id_number');
    var emergencyName = document.getElementById('add_emergency_contact');
    var phoneSuffix = document.getElementById('add_phone_suffix');
    var emergencySuffix = document.getElementById('add_emergency_suffix');

    if (fullName) {
        const val = fullName.value.trim();
        if (!val) { alert('Full name is required.'); fullName.focus(); return false; }
        if (val.length > 80) { alert('Full name must not exceed 80 characters.'); return false; }
        if (/\d/.test(val)) { alert('Full name cannot contain numbers.'); fullName.focus(); return false; }
        if (!/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/.test(val)) {
            alert("Full name can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.).");
            fullName.focus();
            return false;
        }
    }

    if (emergencyName) {
        const ecVal = emergencyName.value.trim();
        if (ecVal.length > 80) { alert('Emergency contact name must not exceed 80 characters.'); return false; }
        if (/\d/.test(ecVal)) { alert('Emergency contact name cannot contain numbers.'); emergencyName.focus(); return false; }
        if (ecVal && !/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/.test(ecVal)) {
            alert("Emergency contact name can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.).");
            emergencyName.focus();
            return false;
        }
    }

    if (phone) phone.value = phone.value.replace(/[^0-9]/g, '');
    if (emergency) emergency.value = emergency.value.replace(/[^0-9]/g, '');

    if (phone && phone.value) {
        const rules = PHONE_LIMITS[phoneSuffix?.value] || { max: 15 };
        if (phone.value.length < 7 || phone.value.length > rules.max) {
            alert('Phone number must be between 7 and ' + rules.max + ' digits. You entered ' + phone.value.length + ' digit(s).');
            phone.focus();
            return false;
        }
    }
    if (emergency && emergency.value) {
        const rules = PHONE_LIMITS[emergencySuffix?.value] || { max: 15 };
        if (emergency.value.length < 7 || emergency.value.length > rules.max) {
            alert('Emergency contact number must be between 7 and ' + rules.max + ' digits. You entered ' + emergency.value.length + ' digit(s).');
            emergency.focus();
            return false;
        }
    }

    if (idNumber.value && !/^[A-Za-z0-9\-]{5,20}$/.test(idNumber.value)) {
        alert('ID number must be 5-20 characters (letters, numbers, hyphen only).');
        idNumber.focus();
        return false;
    }
    return true;
}

function validateEditUserForm() {
    var fullName = document.getElementById('edit_fullname');
    var phone = document.getElementById('edit_phone');
    var emergency = document.getElementById('edit_emergency_number');
    var idNumber = document.getElementById('edit_id_number');
    var emergencyName = document.getElementById('edit_emergency_contact');
    var phoneSuffix = document.getElementById('edit_phone_suffix');
    var emergencySuffix = document.getElementById('edit_emergency_suffix');

    if (fullName) {
        const val = fullName.value.trim();
        if (!val) { alert('Full name is required.'); fullName.focus(); return false; }
        if (val.length > 80) { alert('Full name must not exceed 80 characters.'); return false; }
        if (/\d/.test(val)) { alert('Full name cannot contain numbers.'); fullName.focus(); return false; }
        if (!/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/.test(val)) {
            alert("Full name can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.).");
            fullName.focus();
            return false;
        }
    }

    if (emergencyName) {
        const ecVal = emergencyName.value.trim();
        if (ecVal.length > 80) { alert('Emergency contact name must not exceed 80 characters.'); return false; }
        if (/\d/.test(ecVal)) { alert('Emergency contact name cannot contain numbers.'); emergencyName.focus(); return false; }
        if (ecVal && !/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/.test(ecVal)) {
            alert("Emergency contact name can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.).");
            emergencyName.focus();
            return false;
        }
    }

    if (phone) phone.value = phone.value.replace(/[^0-9]/g, '');
    if (emergency) emergency.value = emergency.value.replace(/[^0-9]/g, '');

    if (phone && phone.value) {
        const rules = PHONE_LIMITS[phoneSuffix?.value] || { max: 15 };
        if (phone.value.length < 7 || phone.value.length > rules.max) {
            alert('Phone number must be between 7 and ' + rules.max + ' digits. You entered ' + phone.value.length + ' digit(s).');
            phone.focus();
            return false;
        }
    }
    if (emergency && emergency.value) {
        const rules = PHONE_LIMITS[emergencySuffix?.value] || { max: 15 };
        if (emergency.value.length < 7 || emergency.value.length > rules.max) {
            alert('Emergency contact number must be between 7 and ' + rules.max + ' digits. You entered ' + emergency.value.length + ' digit(s).');
            emergency.focus();
            return false;
        }
    }

    if (idNumber.value && !/^[A-Za-z0-9\-]{5,20}$/.test(idNumber.value)) {
        alert('ID number must be 5-20 characters (letters, numbers, hyphen only).');
        idNumber.focus();
        return false;
    }
    return true;
}

// ============================================================
// ✅ SPLIT FULL PHONE INTO SUFFIX + NUMBER
// ============================================================
function splitPhone(fullPhone) {
    const countryCodes = [
        '+971', '+966', '+91', '+86', '+84', '+82', '+81', '+66', '+65', '+63',
        '+62', '+61', '+60', '+44', '+1'
    ];
    const clean = (fullPhone || '').trim();
    if (!clean) return { suffix: '+63', number: '' };

    for (const code of countryCodes) {
        if (clean.startsWith(code)) {
            return {
                suffix: code,
                number: clean.substring(code.length).replace(/[^0-9]/g, '')
            };
        }
    }
    return {
        suffix: '+63',
        number: clean.replace(/[^0-9]/g, '')
    };
}

// ============================================================
// MODAL FUNCTIONS
// ============================================================
function showModal(type) {
    document.getElementById(type + 'Modal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(type) {
    document.getElementById(type + 'Modal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// TOGGLE PASSWORD VISIBILITY
// ============================================================
function togglePasswordVisibility(inputId, button) {
    const input = document.getElementById(inputId);
    const icon = button.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
        button.classList.add('active');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
        button.classList.remove('active');
    }
}

// ============================================================
// PASSWORD STRENGTH
// ============================================================
function updatePasswordStrength(password, prefix) {
    const requirementsDiv = document.getElementById(prefix + 'PasswordRequirements');
    const reqLength = document.getElementById(prefix + 'ReqLength');
    const reqUppercase = document.getElementById(prefix + 'ReqUppercase');
    const reqNumber = document.getElementById(prefix + 'ReqNumber');
    if (!requirementsDiv || !reqLength || !reqUppercase || !reqNumber) return;
    if (password.length === 0) { requirementsDiv.style.display = 'none'; return; }
    requirementsDiv.style.display = 'flex';
    if (password.length >= 8) { reqLength.className = 'req check'; reqLength.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> 8 characters'; }
    else { reqLength.className = 'req cross'; reqLength.innerHTML = '<span class="cross"><i class="fas fa-times-circle"></i></span> 8 characters'; }
    if (/[A-Z]/.test(password)) { reqUppercase.className = 'req check'; reqUppercase.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> Uppercase'; }
    else { reqUppercase.className = 'req cross'; reqUppercase.innerHTML = '<span class="cross"><i class="fas fa-times-circle"></i></span> Uppercase'; }
    if (/[0-9]/.test(password)) { reqNumber.className = 'req check'; reqNumber.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> Number'; }
    else { reqNumber.className = 'req cross'; reqNumber.innerHTML = '<span class="cross"><i class="fas fa-times-circle"></i></span> Number'; }
}

document.addEventListener('DOMContentLoaded', function() {
    const addPasswordInput = document.getElementById('add_password');
    if (addPasswordInput) addPasswordInput.addEventListener('input', function() { updatePasswordStrength(this.value, 'add'); });
    const editPasswordInput = document.getElementById('edit_password');
    if (editPasswordInput) editPasswordInput.addEventListener('input', function() { updatePasswordStrength(this.value, 'edit'); });
});

// ============================================================
// VIEW USER
// ============================================================
function viewUser(userId) {
    document.getElementById('userDetails').innerHTML = `
        <div style="text-align: center; padding: 20px;">
            <i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #4DA6D9;"></i>
            <p style="margin-top: 10px; color: #94a3b8;">Loading user details...</p>
        </div>
    `;
    showModal('viewUser');

    fetch('get_user_details.php?user_id=' + userId)
        .then(response => { if (!response.ok) throw new Error('Network error'); return response.json(); })
        .then(data => {
            if (data.error) {
                document.getElementById('userDetails').innerHTML = `<div style="text-align: center; padding: 20px; color: #ef4444;"><i class="fas fa-exclamation-circle" style="font-size: 30px;"></i><p style="margin-top: 10px;">${data.error}</p></div>`;
                return;
            }

            let html = `
                <div class="user-profile-header">
                    <div class="user-avatar">
                        ${data.profile_photo ? `<img src="${data.profile_photo}?${Date.now()}" alt="Profile Photo">` : `<span class="default-icon"><i class="fas fa-user"></i></span>`}
                    </div>
                    <div class="user-name">${data.full_name || data.username}</div>
                    <div class="user-role">
                        <span class="badge ${data.role == 'admin' ? 'badge-admin' : (data.role == 'staff' ? 'badge-staff' : 'badge-guest')}">
                            <i class="fas fa-${data.role == 'admin' ? 'crown' : (data.role == 'staff' ? 'user-tie' : 'user')}"></i>
                            ${data.role || 'Guest'}
                        </span>
                    </div>
                </div>
                <div class="user-detail-grid">
                    <div class="user-detail"><div class="detail-icon"><i class="fas fa-user"></i></div><div class="detail-content"><div class="detail-label">Username</div><div class="detail-value">${data.username}</div></div></div>
                    <div class="user-detail"><div class="detail-icon"><i class="fas fa-envelope"></i></div><div class="detail-content"><div class="detail-label">Email</div><div class="detail-value">${data.email}</div></div></div>
                    <div class="user-detail"><div class="detail-icon"><i class="fas fa-phone"></i></div><div class="detail-content"><div class="detail-label">Contact Number</div><div class="detail-value">${data.contact_number || 'Not provided'}</div></div></div>
                    <div class="user-detail"><div class="detail-icon"><i class="fas fa-map-marker-alt"></i></div><div class="detail-content"><div class="detail-label">Address</div><div class="detail-value">${data.address || 'Not provided'}</div></div></div>
                    <div class="user-detail"><div class="detail-icon"><i class="fas fa-id-card"></i></div><div class="detail-content"><div class="detail-label">ID Type / Number</div><div class="detail-value">${data.id_type || 'N/A'}: ${data.id_number || 'N/A'}</div></div></div>
                    <div class="user-detail"><div class="detail-icon"><i class="fas fa-phone-alt"></i></div><div class="detail-content"><div class="detail-label">Emergency Contact</div><div class="detail-value">${data.emergency_contact || 'N/A'} (${data.emergency_number || 'N/A'})</div></div></div>
                    <div class="user-detail full-width"><div class="detail-icon"><i class="fas fa-calendar-alt"></i></div><div class="detail-content"><div class="detail-label">Registered Date</div><div class="detail-value">${new Date(data.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div></div></div>
                </div>
            `;

            const hasHealth = data.has_asthma || data.has_allergies || data.has_medical_condition || data.has_dietary || data.has_accessibility;
            if (hasHealth) {
                html += `<div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #f1f5f9;"><div style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 10px;"><i class="fas fa-heartbeat" style="color: #ef4444;"></i> Health Information</div><div style="display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 10px;">`;
                if (data.has_asthma) html += `<span class="health-tag asthma"><i class="fas fa-lungs"></i> Asthma${data.asthma_severity ? ' (' + data.asthma_severity + ')' : ''}</span>`;
                if (data.has_allergies) html += `<span class="health-tag allergy"><i class="fas fa-allergies"></i> Allergies</span>`;
                if (data.has_medical_condition) html += `<span class="health-tag medical"><i class="fas fa-notes-medical"></i> Medical</span>`;
                if (data.has_dietary) html += `<span class="health-tag dietary"><i class="fas fa-utensils"></i> Dietary</span>`;
                if (data.has_accessibility) html += `<span class="health-tag access"><i class="fas fa-wheelchair"></i> Access</span>`;
                html += `</div></div>`;
            }

            if (data.id_photo) {
                html += `<div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #f1f5f9;"><div style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 8px;"><i class="fas fa-id-card" style="color: #4DA6D9;"></i> ID Photo</div><div class="id-photo-container"><img src="${data.id_photo}?${Date.now()}" alt="ID Photo" onerror="this.src='https://via.placeholder.com/300x200?text=No+ID+Photo'"></div></div>`;
            }

            document.getElementById('userDetails').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('userDetails').innerHTML = `<div style="text-align: center; padding: 20px; color: #ef4444;"><i class="fas fa-exclamation-circle" style="font-size: 30px;"></i><p style="margin-top: 10px;">Failed to load user details.</p><p style="font-size: 12px; margin-top: 5px; color: #94a3b8;">Error: ${error.message}</p></div>`;
        });
}

// ============================================================
// EDIT USER — with phone splitting
// ============================================================
function editUser(userId) {
    document.getElementById('edit_user_id').value = '';
    document.getElementById('edit_username').value = 'Loading...';
    document.getElementById('edit_email').value = 'Loading...';
    document.getElementById('edit_fullname').value = 'Loading...';
    document.getElementById('edit_role').value = '';
    document.getElementById('edit_phone').value = '';
    document.getElementById('edit_address').value = '';
    document.getElementById('edit_id_type').value = '';
    document.getElementById('edit_id_number').value = '';
    document.getElementById('edit_emergency_contact').value = '';
    document.getElementById('edit_emergency_number').value = '';
    document.getElementById('edit_password').value = '';
    const editReqsInit = document.getElementById('editPasswordRequirements');
    if (editReqsInit) editReqsInit.style.display = 'none';

    showModal('editUser');

    fetch('get_user_details.php?user_id=' + userId)
        .then(response => { if (!response.ok) throw new Error('HTTP error! status: ' + response.status); return response.json(); })
        .then(data => {
            if (data.error) { alert('Failed to load user data: ' + data.error); closeModal('editUser'); return; }
            document.getElementById('edit_user_id').value = data.user_id || userId;
            document.getElementById('edit_username').value = data.username || '';
            document.getElementById('edit_email').value = data.email || '';
            document.getElementById('edit_fullname').value = data.full_name || '';
            document.getElementById('edit_role').value = data.role || 'guest';

            const phoneParts = splitPhone(data.contact_number || '');
            document.getElementById('edit_phone_suffix').value = phoneParts.suffix;
            document.getElementById('edit_phone').value = phoneParts.number;
            document.getElementById('edit_contact_count').textContent = phoneParts.number.length;

            document.getElementById('edit_address').value = data.address || '';
            document.getElementById('edit_id_type').value = data.id_type || '';
            document.getElementById('edit_id_number').value = data.id_number || '';
            document.getElementById('edit_emergency_contact').value = data.emergency_contact || '';

            const emergencyParts = splitPhone(data.emergency_number || '');
            document.getElementById('edit_emergency_suffix').value = emergencyParts.suffix;
            document.getElementById('edit_emergency_number').value = emergencyParts.number;
            document.getElementById('edit_emergency_count').textContent = emergencyParts.number.length;

            document.getElementById('edit_password').value = '';

            applyCountryCodeRules(
                document.getElementById('edit_phone_suffix'),
                document.getElementById('edit_phone'),
                'edit_contact'
            );
            applyCountryCodeRules(
                document.getElementById('edit_emergency_suffix'),
                document.getElementById('edit_emergency_number'),
                'edit_emergency'
            );

            const editErr = document.getElementById('editFullNameError');
            if (editErr) editErr.classList.remove('show');
            document.getElementById('edit_fullname').classList.remove('error');

            const ecErr = document.getElementById('editEmergencyContactError');
            if (ecErr) ecErr.classList.remove('show');
            document.getElementById('edit_emergency_contact').classList.remove('error');
        })
        .catch(error => { alert('Failed to load user data: ' + error.message); closeModal('editUser'); });
}

// ============================================================
// DELETE USER MODAL
// ============================================================
function openDeleteUserModal(userId, username, email) {
    document.getElementById('delete_user_id').value = userId;
    document.getElementById('delete_username').textContent = username;
    document.getElementById('delete_email').textContent = email;

    document.getElementById('delete_reason').value = '';
    document.getElementById('delete_reason_other').value = '';
    document.getElementById('delete_reason_notes').value = '';
    document.getElementById('reasonOtherWrapper').style.display = 'none';

    showModal('deleteUser');
}

function toggleReasonOther() {
    const select = document.getElementById('delete_reason');
    const wrapper = document.getElementById('reasonOtherWrapper');
    if (select.value === 'Other') {
        wrapper.style.display = 'block';
        document.getElementById('delete_reason_other').focus();
    } else {
        wrapper.style.display = 'none';
        document.getElementById('delete_reason_other').value = '';
    }
}

// ============================================================
// LOGOUT MODAL
// ============================================================
function openLogoutModal(event) {
    if (event) event.preventDefault();
    const sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) {
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('menuToggle');
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        if (toggleBtn) toggleBtn.classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
    }
    document.getElementById('logoutModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('logoutModal');
        if (modal && modal.classList.contains('show')) closeLogoutModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeLogoutModal();
        });
    }
});

// Close modals on outside click
window.onclick = function(event) {
    if(event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

// Auto-hide alerts
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);


document.addEventListener("DOMContentLoaded", function(){

    const searchInput = document.getElementById("userSearchInput");
    const roleFilter = document.getElementById("roleFilter");
    const rows = document.querySelectorAll(".user-row");


    function filterUsers(){

        const keyword = searchInput.value.toLowerCase().trim();
        const role = roleFilter.value;


        rows.forEach(row => {

            const text = row.dataset.search;
            const userRole = row.dataset.role;


            const matchSearch = text.includes(keyword);

            const matchRole =
                role === "all" ||
                userRole === role;


            if(matchSearch && matchRole){
                row.style.display="";
            }
            else{
                row.style.display="none";
            }

        });

    }


    searchInput.addEventListener("input", filterUsers);

    roleFilter.addEventListener("change", filterUsers);

});
</script>

</body>
</html>