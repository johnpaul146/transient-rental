<?php
session_start();
require_once 'database.php';

// ✅ NEW: Load SystemLogger (safe — won't break if file missing)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');   // never show errors to visitors (production)
ini_set('log_errors', '1');

// ============================================================
// PASSWORD VALIDATION FUNCTION
// ============================================================
function validatePassword($password) {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter (A-Z)";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number (0-9)";
    }
    return $errors;
}

// ============================================================
// ✅ FULL NAME VALIDATION — Letters only (no numbers, no invalid special chars)
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
// ✅ NEW: PHONE VALIDATION (7-15 digits)
// ============================================================
function validatePhoneNumber($phone, $required = false) {
    if (empty($phone)) {
        return $required ? "Phone number is required" : null;
    }
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($clean) < 7 || strlen($clean) > 15) {
        return "Phone number must be between 7 and 15 digits";
    }
    return null;
}

// ============================================================
// ✅ NEW: BUILD FULL PHONE WITH COUNTRY CODE
// ============================================================
function buildFullPhone($suffix, $number) {
    $clean_number = preg_replace('/[^0-9]/', '', $number);
    if (empty($clean_number)) return '';
    return trim($suffix) . $clean_number;
}

// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get guest info
$stmt = $pdo->prepare("SELECT * FROM guests WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$guest = $stmt->fetch();

// ============================================
// PHOTO UPLOAD - Using profile/user_{id}/ folder
// ============================================
function handlePhotoUpload($file, $user_id, $type) {
    $target_dir = "uploads/profile/user_" . $user_id . "/";

    if (!file_exists($target_dir)) {
        if (!mkdir($target_dir, 0777, true)) {
            return ['error' => 'Failed to create upload directory'];
        }
    }

    if (!is_writable($target_dir)) {
        chmod($target_dir, 0777);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $error_msg = $error_messages[$file['error']] ?? 'Unknown error';
        return ['error' => 'File upload error: ' . $error_msg];
    }

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed_extensions)) {
        return ['error' => 'Only JPG, PNG, GIF, and WEBP images are allowed'];
    }

    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return ['error' => 'File size must be less than 5MB'];
    }

    $filename = $type . '_' . time() . '.' . $extension;
    $target_file = $target_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        return ['success' => true, 'filename' => $filename];
    } else {
        $error = error_get_last();
        return ['error' => 'Failed to upload file: ' . ($error['message'] ?? 'Unknown error')];
    }
}

function handleBase64Image($base64_data, $user_id, $type) {
    $target_dir = "uploads/profile/user_" . $user_id . "/";

    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $image_parts = explode(';base64,', $base64_data);
    if (count($image_parts) < 2) {
        return ['error' => 'Invalid image data'];
    }

    $image_type = explode('/', $image_parts[0]);
    $image_type = $image_type[1] ?? 'jpg';

    $image_data = base64_decode($image_parts[1]);
    if ($image_data === false) {
        return ['error' => 'Invalid image data'];
    }

    $filename = $type . '_camera_' . time() . '.' . $image_type;
    $target_file = $target_dir . $filename;

    if (file_put_contents($target_file, $image_data)) {
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['error' => 'Failed to save image'];
    }
}

// ============================================
// GET PHOTO PATHS
// ============================================
function getProfilePhoto($guest, $user_id) {
    if ($guest && !empty($guest['profile_photo'])) {
        $path = "uploads/profile/user_" . $user_id . "/" . $guest['profile_photo'];
        if (file_exists($path)) {
            return $path;
        }
        $old_path = "uploads/user_" . $user_id . "/" . $guest['profile_photo'];
        if (file_exists($old_path)) {
            return $old_path;
        }
        $old_profile_path = "uploads/profile/" . $guest['profile_photo'];
        if (file_exists($old_profile_path)) {
            return $old_profile_path;
        }
        return $path;
    }
    return null;
}

function getIDPhoto($guest, $user_id) {
    if ($guest && !empty($guest['id_photo'])) {
        $path = "uploads/profile/user_" . $user_id . "/" . $guest['id_photo'];
        if (file_exists($path)) {
            return $path;
        }
        $old_path = "uploads/user_" . $user_id . "/" . $guest['id_photo'];
        if (file_exists($old_path)) {
            return $old_path;
        }
        return $path;
    }
    return null;
}

// ============================================
// DECODE JSON HELPER
// ============================================
function decodeJson($value) {
    if (!$value) return [];
    $decoded = json_decode($value, true);
    return $decoded ?: [];
}

function encodeJson($value) {
    if (empty($value)) return null;
    return json_encode($value);
}

// ============================================
// HANDLE PROFILE UPDATE
// ============================================
if(isset($_POST['update_profile'])) {
    try {
        $user_id = $_SESSION['user_id'];

        // ✅ NEW: Snapshot old values BEFORE update — for detailed logging
        $old_user = [
            'email'    => $user['email'] ?? null,
            'password' => $user['password'] ?? null,
        ];
        $old_guest = $guest ? [
            'full_name'          => $guest['full_name'] ?? null,
            'contact_number'     => $guest['contact_number'] ?? null,
            'address'            => $guest['address'] ?? null,
            'profile_photo'      => $guest['profile_photo'] ?? null,
            'has_asthma'         => $guest['has_asthma'] ?? null,
            'has_allergies'      => $guest['has_allergies'] ?? null,
            'has_medical_condition' => $guest['has_medical_condition'] ?? null,
            'has_dietary'        => $guest['has_dietary'] ?? null,
            'has_accessibility'  => $guest['has_accessibility'] ?? null,
        ] : null;

        // ✅ NEW: Validate and sanitize full name
        $full_name_raw = trim($_POST['full_name'] ?? '');
        $name_error = validateFullName($full_name_raw);
        if ($name_error) throw new Exception($name_error);
        $full_name = sanitizeFullName($full_name_raw);

        // ✅ NEW: Build full contact number with country code
        $contact_suffix = trim($_POST['contact_suffix'] ?? '+63');
        $contact_number = trim($_POST['contact'] ?? '');
        $contact_full = buildFullPhone($contact_suffix, $contact_number);

        // ✅ Validate phone
        $phoneError = validatePhoneNumber($contact_number, false);
        if ($phoneError) throw new Exception($phoneError);

        $pdo->beginTransaction();

        // Update users table
        $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->execute([$_POST['email'], $user_id]);

        // Update password with validation
        $password_changed = false;
        if(!empty($_POST['new_password'])) {
            $passwordErrors = validatePassword($_POST['new_password']);
            if (!empty($passwordErrors)) {
                throw new Exception(implode("<br>", $passwordErrors));
            }

            if($_POST['new_password'] == $_POST['confirm_password']) {
                // Always store a password_hash() hash, never the plain password
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $user_id]);
                $password_changed = true;
            } else {
                throw new Exception("Passwords do not match!");
            }
        }

        // Handle profile photo (ID photo is READ-ONLY)
        $profile_photo = $guest['profile_photo'] ?? null;
        $photo_action  = 'none';

        if(isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
            if($profile_photo && file_exists("uploads/profile/user_" . $user_id . "/" . $profile_photo)) {
                unlink("uploads/profile/user_" . $user_id . "/" . $profile_photo);
            }
            if($profile_photo && file_exists("uploads/user_" . $user_id . "/" . $profile_photo)) {
                unlink("uploads/user_" . $user_id . "/" . $profile_photo);
            }
            if($profile_photo && file_exists("uploads/profile/" . $profile_photo)) {
                unlink("uploads/profile/" . $profile_photo);
            }
            $profile_photo = null;
            $photo_action = 'removed';
        } else {
            $has_file_upload = isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK;
            $has_base64 = isset($_POST['profile_photo_base64']) && !empty($_POST['profile_photo_base64']);

            if($has_file_upload) {
                if($profile_photo) {
                    if(file_exists("uploads/profile/user_" . $user_id . "/" . $profile_photo)) unlink("uploads/profile/user_" . $user_id . "/" . $profile_photo);
                    if(file_exists("uploads/user_" . $user_id . "/" . $profile_photo)) unlink("uploads/user_" . $user_id . "/" . $profile_photo);
                    if(file_exists("uploads/profile/" . $profile_photo)) unlink("uploads/profile/" . $profile_photo);
                }
                $result = handlePhotoUpload($_FILES['profile_photo'], $user_id, 'profile');
                if(isset($result['error'])) throw new Exception("Profile photo error: " . $result['error']);
                $profile_photo = $result['filename'];
                $photo_action = 'uploaded';
            } elseif($has_base64) {
                if($profile_photo) {
                    if(file_exists("uploads/profile/user_" . $user_id . "/" . $profile_photo)) unlink("uploads/profile/user_" . $user_id . "/" . $profile_photo);
                    if(file_exists("uploads/user_" . $user_id . "/" . $profile_photo)) unlink("uploads/user_" . $user_id . "/" . $profile_photo);
                    if(file_exists("uploads/profile/" . $profile_photo)) unlink("uploads/profile/" . $profile_photo);
                }
                $result = handleBase64Image($_POST['profile_photo_base64'], $user_id, 'camera');
                if(isset($result['error'])) throw new Exception("Profile photo error: " . $result['error']);
                $profile_photo = $result['filename'];
                $photo_action = 'camera';
            }
        }

        // Collect health data
        $allergy_types = [];
        if(isset($_POST['allergy_food'])) $allergy_types[] = 'food';
        if(isset($_POST['allergy_dust'])) $allergy_types[] = 'dust';
        if(isset($_POST['allergy_pet'])) $allergy_types[] = 'pet';
        if(isset($_POST['allergy_latex'])) $allergy_types[] = 'latex';
        if(isset($_POST['allergy_medicine'])) $allergy_types[] = 'medicine';
        if(isset($_POST['allergy_insect'])) $allergy_types[] = 'insect';

        $dietary_types = [];
        if(isset($_POST['diet_vegetarian'])) $dietary_types[] = 'vegetarian';
        if(isset($_POST['diet_vegan'])) $dietary_types[] = 'vegan';
        if(isset($_POST['diet_gluten'])) $dietary_types[] = 'gluten';
        if(isset($_POST['diet_halal'])) $dietary_types[] = 'halal';
        if(isset($_POST['diet_kosher'])) $dietary_types[] = 'kosher';
        if(isset($_POST['diet_diabetic'])) $dietary_types[] = 'diabetic';

        $access_needs = [];
        if(isset($_POST['access_wheelchair'])) $access_needs[] = 'wheelchair';
        if(isset($_POST['access_ground'])) $access_needs[] = 'ground';
        if(isset($_POST['access_visual'])) $access_needs[] = 'visual';
        if(isset($_POST['access_hearing'])) $access_needs[] = 'hearing';

        // Update guests table
        if($guest) {
            $stmt = $pdo->prepare("UPDATE guests SET
                full_name = ?, contact_number = ?, address = ?, profile_photo = ?,
                has_asthma = ?, asthma_severity = ?, has_inhaler = ?, asthma_triggers = ?, last_attack = ?,
                has_allergies = ?, allergy_types = ?, allergies_details = ?, allergy_severity = ?, has_epipen = ?,
                has_medical_condition = ?, medical_conditions = ?, medications = ?, blood_type = ?,
                has_dietary = ?, dietary_types = ?, dietary_other = ?,
                has_accessibility = ?, access_needs = ?, accessibility_other = ?,
                emergency_medication = ?
                WHERE user_id = ?");
            $stmt->execute([
                $full_name, $contact_full, $_POST['address'], $profile_photo,
                isset($_POST['has_asthma']) ? 1 : 0, $_POST['asthma_severity'] ?? null,
                $_POST['has_inhaler'] ?? null, $_POST['asthma_triggers'] ?? null, $_POST['last_attack'] ?? null,
                isset($_POST['has_allergies']) ? 1 : 0, encodeJson($allergy_types),
                $_POST['allergies_details'] ?? null, $_POST['allergy_severity'] ?? null, $_POST['has_epipen'] ?? null,
                isset($_POST['has_medical']) ? 1 : 0, $_POST['medical_conditions'] ?? null,
                $_POST['medications'] ?? null, $_POST['blood_type'] ?? null,
                isset($_POST['has_dietary']) ? 1 : 0, encodeJson($dietary_types), $_POST['dietary_other'] ?? null,
                isset($_POST['has_accessibility']) ? 1 : 0, encodeJson($access_needs),
                $_POST['accessibility_other'] ?? null, $_POST['emergency_medication'] ?? null,
                $user_id
            ]);
        }

        $pdo->commit();

        // ✅ NEW: Detailed log for profile update
        if (class_exists('SystemLogger')) {
            $changed = [];

            // Track user table changes
            if ($old_user['email'] !== $_POST['email']) {
                $changed[] = 'email';
            }
            if ($password_changed) {
                $changed[] = 'password';
            }

            // Track guest table changes
            if ($guest && $old_guest) {
                if ($old_guest['full_name'] !== $full_name) $changed[] = 'full_name';
                if ($old_guest['contact_number'] !== $contact_full) $changed[] = 'contact';
                if ($old_guest['address'] !== $_POST['address']) $changed[] = 'address';

                if ((int)$old_guest['has_asthma'] !== (isset($_POST['has_asthma']) ? 1 : 0)) $changed[] = 'asthma_info';
                if ((int)$old_guest['has_allergies'] !== (isset($_POST['has_allergies']) ? 1 : 0)) $changed[] = 'allergies_info';
                if ((int)$old_guest['has_medical_condition'] !== (isset($_POST['has_medical']) ? 1 : 0)) $changed[] = 'medical_info';
                if ((int)$old_guest['has_dietary'] !== (isset($_POST['has_dietary']) ? 1 : 0)) $changed[] = 'dietary_info';
                if ((int)$old_guest['has_accessibility'] !== (isset($_POST['has_accessibility']) ? 1 : 0)) $changed[] = 'accessibility_info';
            }

            // Track photo action
            if ($photo_action !== 'none') {
                $changed[] = 'profile_photo (' . $photo_action . ')';
            }

            $desc = "User '" . ($_SESSION['username'] ?? 'Unknown') . "' updated own profile";
            if (!empty($changed)) {
                $desc .= " (changed: " . implode(', ', $changed) . ")";
            } else {
                $desc .= " (no changes detected)";
            }

            SystemLogger::log(
                $pdo,
                'update',
                'profile',
                $desc,
                (int)$user_id,
                'user',
                $old_user + ($old_guest ?: []),
                [
                    'email'          => $_POST['email'],
                    'full_name'      => $full_name,
                    'contact'        => $contact_full,
                    'address'        => $_POST['address'] ?? null,
                    'photo_action'   => $photo_action,
                    'password_changed' => $password_changed,
                    'page'           => 'edit-profile.php'
                ]
            );
        }

        $_SESSION['profile_update_success'] = "Profile updated successfully!";
        header("Location: profile.php?success=1");
        exit();

    } catch(Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ============================================
// HELPER: Check if checkbox should be checked
// ============================================
function isChecked($field) {
    global $guest;
    return (isset($guest[$field]) && $guest[$field] == 1) ? 'checked' : '';
}

function isCheckedValue($field, $value) {
    global $guest;
    return (isset($guest[$field]) && $guest[$field] == $value) ? 'checked' : '';
}

function selected($field, $value) {
    global $guest;
    return (isset($guest[$field]) && $guest[$field] == $value) ? 'selected' : '';
}

function isAllergyChecked($type) {
    global $guest;
    if (isset($guest['allergy_types'])) {
        $types = json_decode($guest['allergy_types'], true);
        if (is_array($types) && in_array($type, $types)) return 'checked';
    }
    return '';
}

function isDietaryChecked($type) {
    global $guest;
    if (isset($guest['dietary_types'])) {
        $types = json_decode($guest['dietary_types'], true);
        if (is_array($types) && in_array($type, $types)) return 'checked';
    }
    return '';
}

function isAccessChecked($type) {
    global $guest;
    if (isset($guest['access_needs'])) {
        $types = json_decode($guest['access_needs'], true);
        if (is_array($types) && in_array($type, $types)) return 'checked';
    }
    return '';
}

// ============================================================
// ✅ NEW: Split full phone into suffix + number for form display
// ============================================================
function splitPhoneForForm($fullPhone) {
    $countryCodes = [
        '+971', '+966', '+91', '+86', '+84', '+82', '+81', '+66', '+65', '+63',
        '+62', '+61', '+60', '+44', '+1'
    ];
    $clean = trim($fullPhone ?? '');
    if (empty($clean)) {
        return ['suffix' => '+63', 'number' => ''];
    }

    foreach ($countryCodes as $code) {
        if (strpos($clean, $code) === 0) {
            return [
                'suffix' => $code,
                'number' => preg_replace('/[^0-9]/', '', substr($clean, strlen($code)))
            ];
        }
    }
    // No prefix found — assume +63
    return [
        'suffix' => '+63',
        'number' => preg_replace('/[^0-9]/', '', $clean)
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Edit Profile - Transient House & Tours</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           OCEAN BLUE THEME — matches the rest of the system
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb;
            min-height: 100vh;
            padding: 0;
        }

        /* ---------- HEADER ---------- */
        .header {
            background: #0B2447;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            padding: 12px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 2px solid rgba(77, 166, 217, 0.2);
        }

        .header-content {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo-wrapper {
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
        }

        .logo-wrapper .logo-icon {
            height: 50px;
            width: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            border: 2px solid #4DA6D9;
        }

        .brand-text { display: flex; flex-direction: column; line-height: 1.2; }
        .brand-text .brand-name { font-size: 20px; font-weight: 700; color: white; letter-spacing: -0.5px; }
        .brand-text .brand-tagline { font-size: 11px; color: #7bb8f0; font-weight: 500; letter-spacing: 0.3px; }

        .back-btn {
            padding: 8px 16px;
            border-radius: 8px;
            background: rgba(77, 166, 217, 0.2);
            color: #b3d9ff;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: 1px solid rgba(77, 166, 217, 0.2);
        }

        .back-btn:hover {
            background: rgba(77, 166, 217, 0.35);
            color: white;
        }

        /* ---------- MAIN CONTAINER ---------- */
        .main-container {
            max-width: 900px;
            margin: 30px auto 40px;
            padding: 0 20px;
        }

        .edit-card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            border: 1px solid #e8f0fe;
            position: relative;
            overflow: hidden;
        }

        .edit-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 6px;
            background: linear-gradient(90deg, #4DA6D9, #7bb8f0, #4DA6D9);
            background-size: 200% 100%;
            animation: gradientMove 3s linear infinite;
        }

        @keyframes gradientMove {
            0% { background-position: 0% 0%; }
            100% { background-position: 200% 0%; }
        }

        /* ---------- EDIT HEADER ---------- */
        .edit-header { text-align: center; margin-bottom: 30px; }
        .edit-header h1 {
            font-size: 30px; font-weight: 700; color: #0B2447; margin-bottom: 8px;
        }
        .edit-header h1 i { color: #4DA6D9; margin-right: 10px; }
        .edit-header p { color: #64748b; font-size: 15px; }

        /* ---------- ALERT ---------- */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .alert-error { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }
        .alert i { font-size: 18px; }

        /* ---------- AVATAR SECTION ---------- */
        .avatar-section { text-align: center; margin-bottom: 30px; }
        .avatar-wrapper { position: relative; display: inline-block; }

        .avatar {
            width: 120px; height: 120px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 10px;
            color: white; font-size: 48px;
            overflow: hidden;
            border: 4px solid #4DA6D9;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        .avatar:hover { transform: scale(1.05); box-shadow: 0 10px 30px rgba(77, 166, 217, 0.35); }
        .avatar:hover .avatar-overlay { opacity: 1; }
        .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .avatar .default-icon { font-size: 48px; color: white; }

        .avatar-overlay {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(11, 36, 71, 0.55);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.3s;
            border-radius: 50%;
        }
        .avatar-overlay i { color: white; font-size: 24px; }

        .avatar-name { font-size: 18px; font-weight: 600; color: #0B2447; overflow-wrap: anywhere; }
        .avatar-sub { font-size: 13px; color: #4a6a8c; margin: 2px 0 12px; }

        /* ---------- PHOTO ACTIONS ---------- */
        .photo-actions {
            display: flex; gap: 10px; justify-content: center;
            flex-wrap: wrap; margin-top: 10px;
        }
        .photo-actions .btn-photo {
            padding: 8px 18px;
            border: none; border-radius: 10px;
            font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.3s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .photo-actions .btn-photo:hover { transform: translateY(-2px); }
        .photo-actions .btn-upload { background: #4DA6D9; color: white; }
        .photo-actions .btn-upload:hover { background: #3a8bbf; box-shadow: 0 4px 12px rgba(77, 166, 217, 0.3); }
        .photo-actions .btn-camera { background: #10b981; color: white; }
        .photo-actions .btn-camera:hover { background: #059669; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .photo-actions .btn-remove { background: #ef4444; color: white; }
        .photo-actions .btn-remove:hover { background: #dc2626; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
        .photo-actions .btn-remove:disabled { opacity: 0.4; cursor: not-allowed; }
        .photo-actions .btn-remove:disabled:hover { transform: none; }
        .hidden-file-input { display: none; }

        /* ---------- FORM SECTIONS ---------- */
        .form-section {
            background: #f8fafc;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #e8f0fe;
        }

        .section-title {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e8f0fe;
        }
        .section-title i {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 18px;
            flex-shrink: 0;
        }
        .section-title h3 { font-size: 18px; font-weight: 600; color: #0B2447; margin: 0; }

        /* ---------- FORM GROUPS ---------- */
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; margin-bottom: 8px;
            font-weight: 600; color: #0B2447; font-size: 13px;
        }
        .form-group label i { color: #4DA6D9; margin-right: 8px; width: 18px; }

        .input-wrapper { position: relative; }
        .input-wrapper i {
            position: absolute; left: 15px; top: 50%;
            transform: translateY(-50%);
            color: #94a3b8; font-size: 16px;
        }
        .input-wrapper .form-control { padding-left: 45px; }

        .form-control, .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e8f0fe;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
            color: #1e293b;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #4DA6D9;
            box-shadow: 0 0 0 4px rgba(77, 166, 217, 0.1);
            background: white;
        }
        .form-control[readonly] {
            background: #f1f5f9;
            cursor: not-allowed;
        }
        .form-control.error { border-color: #dc2626; background: #fee2e2; }

        .input-hint { font-size: 12px; color: #94a3b8; margin-top: 5px; }
        .input-hint i { color: #4DA6D9; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }

        /* ---------- ✅ NAME INPUT WRAPPER ---------- */
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

        /* ---------- ✅ NEW: PHONE INPUT GROUP ---------- */
        .phone-input-group {
            display: flex;
            gap: 8px;
            align-items: stretch;
        }

        .phone-suffix-select {
            min-width: 95px;
            max-width: 110px;
            padding: 12px 8px;
            border: 2px solid #e8f0fe;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            background: white;
            color: #0B2447;
            cursor: pointer;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .phone-suffix-select:focus {
            outline: none;
            border-color: #4DA6D9;
            background: white;
            box-shadow: 0 0 0 4px rgba(77, 166, 217, 0.1);
        }

        .phone-input-wrapper {
            position: relative;
            flex: 1;
            min-width: 0;
        }

        .phone-input-wrapper .form-control {
            padding-left: 42px;
            padding-right: 50px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .phone-input-wrapper .phone-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
            pointer-events: none;
        }

        .phone-input-wrapper .digit-count {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11px;
            color: #94a3b8;
            background: #f1f5f9;
            padding: 2px 8px;
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

        /* ---------- READ-ONLY ID FIELDS ---------- */
        .readonly-field {
            background: #f1f5f9 !important;
            cursor: not-allowed !important;
            color: #64748b !important;
        }
        .readonly-photo { opacity: 0.7; cursor: not-allowed; filter: grayscale(0.2); }
        .readonly-badge {
            display: inline-block;
            background: rgba(245, 158, 11, 0.15);
            color: #b45309;
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 12px;
            margin-left: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .photo-preview-small {
            width: 80px; height: 80px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e2e8f0;
        }
        .id-photo-section { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }

        /* ---------- INFO BOX ---------- */
        .info-box {
            background: #e8f4fc;
            border-radius: 12px;
            padding: 15px 18px;
            margin-bottom: 20px;
            display: flex; align-items: center; gap: 12px;
            border-left: 4px solid #4DA6D9;
        }
        .info-box i { font-size: 20px; color: #4DA6D9; }
        .info-box p { color: #1e293b; font-size: 13px; margin: 0; }

        /* ---------- PASSWORD FIELD WITH EYE ICON ---------- */
        .password-wrapper { position: relative; width: 100%; }
        .password-wrapper .form-control { padding-right: 45px; width: 100%; }
        .password-wrapper .toggle-password {
            position: absolute;
            right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            padding: 6px 8px; cursor: pointer;
            color: #94a3b8; font-size: 18px;
            transition: color 0.3s;
            z-index: 5;
            line-height: 1;
            display: flex; align-items: center; justify-content: center;
        }
        .password-wrapper .toggle-password:hover { color: #4DA6D9; }
        .password-wrapper .toggle-password.active { color: #4DA6D9; }
        .password-wrapper .toggle-password i { pointer-events: none; font-size: 18px; }

        .password-requirements {
            font-size: 11px;
            margin-top: 8px;
            padding: 8px 12px;
            background: #f0f7fb;
            border-radius: 8px;
            border: 1px solid #e8f0fe;
            display: flex; flex-wrap: wrap;
            gap: 8px 14px;
            align-items: center;
        }
        .password-requirements .req {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; padding: 2px 0;
        }
        .password-requirements .req .check { color: #10b981; }
        .password-requirements .req .cross { color: #ef4444; }
        .password-requirements .req .pending { color: #94a3b8; }

        .confirm-feedback {
            font-size: 12px; margin-top: 5px;
            display: block; clear: both;
        }

        /* ---------- HEALTH SECTION ---------- */
        .health-section {
            background: #f8fafc;
            border-radius: 16px;
            padding: 25px;
            margin: 20px 0;
            border: 1px solid #e8f0fe;
        }
        .health-section h4 {
            font-size: 17px; font-weight: 700; color: #0B2447;
            margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .health-section .section-icon { color: #4DA6D9; }

        .checkbox-group {
            display: flex; align-items: center; gap: 12px;
            margin: 12px 0; padding: 12px 18px;
            background: white;
            border-radius: 10px;
            border: 1px solid #e8f0fe;
            transition: all 0.2s;
        }
        .checkbox-group:hover { border-color: #4DA6D9; }
        .checkbox-group input[type="checkbox"] {
            width: 18px; height: 18px;
            cursor: pointer;
            accent-color: #4DA6D9;
        }
        .checkbox-group label {
            margin-bottom: 0; cursor: pointer;
            font-weight: 500; color: #1e293b;
            font-size: 14px;
        }
        .checkbox-group label i { color: #4DA6D9; margin-right: 6px; }

        .sub-section {
            margin-left: 30px;
            padding: 18px;
            background: white;
            border-radius: 12px;
            margin-top: 10px;
            border: 1px solid #e8f0fe;
            display: none;
        }
        .sub-section.visible { display: block; animation: slideDown 0.3s ease; }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ---------- ACTION BUTTONS ---------- */
        .action-buttons {
            display: flex; gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        .btn-save {
            flex: 2;
            padding: 15px;
            background: linear-gradient(135deg, #4DA6D9, #4DA6D9);
            color: #0B2447;
            border: none; border-radius: 12px;
            font-weight: 700; font-size: 15px;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 6px 20px #4DA6D9;
            min-width: 200px;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px #4DA6D9;
        }

        .btn-cancel {
            flex: 1;
            padding: 15px;
            background: #64748b;
            color: white;
            border: none; border-radius: 12px;
            font-weight: 600; font-size: 15px;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            text-decoration: none;
            min-width: 150px;
        }
        .btn-cancel:hover { background: #475569; transform: translateY(-2px); color: white; }

        /* ---------- CAMERA MODAL ---------- */
        .camera-modal {
            display: none;
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(11, 36, 71, 0.95);
            z-index: 10000;
            align-items: center; justify-content: center;
            flex-direction: column;
        }
        .camera-modal.active { display: flex; }
        .camera-modal video {
            max-width: 90%; max-height: 65vh;
            border-radius: 16px;
            background: #000;
            border: 2px solid #4DA6D9;
        }
        .camera-modal .camera-controls {
            margin-top: 25px;
            display: flex; gap: 15px;
        }
        .camera-modal .camera-controls button {
            padding: 14px 35px;
            border: none; border-radius: 12px;
            font-size: 16px; font-weight: 600;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center; gap: 10px;
        }
        .camera-modal .camera-controls .btn-capture { background: #10b981; color: white; }
        .camera-modal .camera-controls .btn-capture:hover { background: #059669; transform: scale(1.05); }
        .camera-modal .camera-controls .btn-close-camera { background: #ef4444; color: white; }
        .camera-modal .camera-controls .btn-close-camera:hover { background: #dc2626; }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 768px) {
            .edit-card { padding: 25px 20px; }
            .form-row { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .edit-header h1 { font-size: 24px; }
            .camera-modal video { max-height: 50vh; }
            .sub-section { margin-left: 10px; }
            .header-content { flex-direction: column; gap: 12px; }
        }

        @media (max-width: 480px) {
            .edit-card { padding: 20px 15px; }
            .photo-actions .btn-photo { font-size: 12px; padding: 8px 14px; min-height: 36px; }
        }
        @media (max-width: 400px) {
            .phone-input-group { flex-direction: column; }
            .phone-input-group .phone-suffix-select { width: 100%; max-width: none; }
        }

        /* ===== UX POLISH: spacing, photo controls, security, mobile ===== */
        .form-section, .health-section { margin-bottom: 22px; }
        .section-title { gap: 12px; flex-wrap: wrap; }
        .section-title h3 { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 10px; }
        .readonly-badge { white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; }
        .readonly-badge i { font-size: 10px; background: none; width: auto; height: auto; padding: 0; color: inherit; }
        .avatar-section { padding-bottom: 6px; }
        .photo-hint { font-size: 12.5px; color: #5b6b7e; margin: 10px auto 0; max-width: 420px; line-height: 1.5; }
        .photo-actions { gap: 10px; flex-wrap: wrap; justify-content: center; }
        .photo-actions .btn-photo { min-height: 42px; padding: 9px 18px; border-radius: 10px; font-size: 13px; }
        .photo-actions .btn-photo:focus-visible, .btn-save:focus-visible, .btn-cancel:focus-visible { outline: 3px solid rgba(77,166,217,.5); outline-offset: 2px; }
        .form-control:focus { outline: 3px solid rgba(77,166,217,.3); outline-offset: 0; }
        .sub-heading { font-size: 14px; font-weight: 700; color: #0B2447; margin: 22px 0 4px; display: flex; align-items: center; gap: 8px; padding-top: 16px; border-top: 1px solid #e8f0fe; }
        .sub-heading i { color: #4DA6D9; }
        .sub-note { font-size: 12.5px; color: #5b6b7e; margin: 0 0 14px; }
        .input-hint { color: #5b6b7e; }
        @media (max-width: 600px) {
            .main-container { padding-left: 10px; padding-right: 10px; }
            .form-section, .health-section { padding: 16px 14px; margin-bottom: 16px; }
            .photo-actions { flex-direction: column; align-items: stretch; max-width: 280px; margin: 0 auto; }
            .photo-actions .btn-photo { width: 100%; justify-content: center; }
            .checkbox-group { padding: 12px; }
        }
    </style>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="header-content">
        <a href="index.php" class="logo-wrapper">
            <div class="logo-icon"><i class="fas fa-umbrella-beach"></i></div>
            <div class="brand-text">
                <span class="brand-name">Transient House & Tours</span>
                <span class="brand-tagline">Your Home Away From Home</span>
            </div>
        </a>
        <a href="profile.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Profile
        </a>
    </div>
</div>

<!-- Main Container -->
<div class="main-container">
    <div class="edit-card">

        <!-- Edit Header -->
        <div class="edit-header">
            <h1><i class="fas fa-user-edit"></i> Edit Profile</h1>
            <p>Update your personal information and account settings</p>
        </div>

        <!-- Error Alert -->
        <?php if(isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="editProfileForm">

            <!-- ============================================ -->
            <!-- PROFILE PHOTO SECTION -->
            <!-- ============================================ -->
            <div class="avatar-section">
                <div class="avatar-wrapper">
                    <div class="avatar" id="avatarPreview" onclick="document.getElementById('profile_photo_input').click()">
                        <?php
                        $profile_photo = getProfilePhoto($guest, $_SESSION['user_id']);
                        if ($profile_photo): ?>
                            <img src="<?php echo $profile_photo; ?>?<?php echo time(); ?>" alt="Profile Photo">
                        <?php else: ?>
                            <span class="default-icon"><i class="fas fa-user"></i></span>
                        <?php endif; ?>
                        <div class="avatar-overlay">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                </div>
                <div class="avatar-name">
                    <?php echo $guest ? htmlspecialchars($guest['full_name']) : htmlspecialchars($user['username']); ?>
                </div>
                <div class="avatar-sub">Update your personal information</div>
                <div class="photo-actions">
                    <button type="button" class="btn-photo btn-upload" onclick="document.getElementById('profile_photo_input').click()">
                        <i class="fas fa-upload"></i> Upload Photo
                    </button>
                    <button type="button" class="btn-photo btn-camera" onclick="openCamera('profile')">
                        <i class="fas fa-camera"></i> Camera
                    </button>
                    <?php if($profile_photo): ?>
                        <button type="button" class="btn-photo btn-remove" onclick="removePhoto('profile')">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn-photo btn-remove" id="removePhotoBtn" disabled>
                            <i class="fas fa-times"></i> Remove
                        </button>
                    <?php endif; ?>
                </div>
                <div class="photo-hint"><i class="fas fa-info-circle"></i> Choose a photo from your device or take one with your camera. Tap the picture to change it.</div>
                <input type="file" id="profile_photo_input" name="profile_photo" class="hidden-file-input" accept="image/*" onchange="previewPhoto(this, 'profile')">
                <input type="hidden" id="profile_photo_base64" name="profile_photo_base64">
                <input type="hidden" name="remove_photo" id="remove_photo" value="0">
            </div>

            <!-- ============================================ -->
            <!-- PERSONAL INFORMATION -->
            <!-- ============================================ -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-user"></i>
                    <h3>Personal Information</h3>
                </div>

                <!-- ✅ FULL NAME with letters-only restriction -->
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Full Name</label>
                    <div class="name-input-wrapper">
                        <input type="text"
                               name="full_name"
                               id="full_name"
                               class="form-control"
                               value="<?php echo $guest ? htmlspecialchars($guest['full_name']) : ''; ?>"
                               placeholder="Enter your full name"
                               maxlength="80"
                               data-name-field="1"
                               autocomplete="name"
                               required>
                        <i class="fas fa-font name-hint-icon"></i>
                    </div>
                    <div class="name-error-msg" id="fullNameError">
                        <i class="fas fa-exclamation-circle"></i> <span id="fullNameErrorText"></span>
                    </div>
                    <div class="input-hint">
                        <i class="fas fa-info-circle"></i> Letters only — no numbers or special characters
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Contact Number</label>
                        <?php
                        // ✅ Split existing contact into suffix + number
                        $contact_parts = splitPhoneForForm($guest['contact_number'] ?? '');
                        ?>
                        <div class="phone-input-group">
                            <select name="contact_suffix" id="contact_suffix" class="phone-suffix-select" aria-label="Country code">
                                <option value="+63" <?php echo $contact_parts['suffix'] === '+63' ? 'selected' : ''; ?>>+63 🇵🇭</option>
                                <option value="+1" <?php echo $contact_parts['suffix'] === '+1' ? 'selected' : ''; ?>>+1 🇺🇸</option>
                                <option value="+44" <?php echo $contact_parts['suffix'] === '+44' ? 'selected' : ''; ?>>+44 🇬🇧</option>
                                <option value="+61" <?php echo $contact_parts['suffix'] === '+61' ? 'selected' : ''; ?>>+61 🇦🇺</option>
                                <option value="+81" <?php echo $contact_parts['suffix'] === '+81' ? 'selected' : ''; ?>>+81 🇯🇵</option>
                                <option value="+82" <?php echo $contact_parts['suffix'] === '+82' ? 'selected' : ''; ?>>+82 🇰🇷</option>
                                <option value="+86" <?php echo $contact_parts['suffix'] === '+86' ? 'selected' : ''; ?>>+86 🇨🇳</option>
                                <option value="+65" <?php echo $contact_parts['suffix'] === '+65' ? 'selected' : ''; ?>>+65 🇸🇬</option>
                                <option value="+60" <?php echo $contact_parts['suffix'] === '+60' ? 'selected' : ''; ?>>+60 🇲🇾</option>
                                <option value="+62" <?php echo $contact_parts['suffix'] === '+62' ? 'selected' : ''; ?>>+62 🇮🇩</option>
                                <option value="+66" <?php echo $contact_parts['suffix'] === '+66' ? 'selected' : ''; ?>>+66 🇹🇭</option>
                                <option value="+84" <?php echo $contact_parts['suffix'] === '+84' ? 'selected' : ''; ?>>+84 🇻🇳</option>
                                <option value="+91" <?php echo $contact_parts['suffix'] === '+91' ? 'selected' : ''; ?>>+91 🇮🇳</option>
                                <option value="+971" <?php echo $contact_parts['suffix'] === '+971' ? 'selected' : ''; ?>>+971 🇦🇪</option>
                                <option value="+966" <?php echo $contact_parts['suffix'] === '+966' ? 'selected' : ''; ?>>+966 🇸🇦</option>
                            </select>
                            <div class="phone-input-wrapper">
                                <i class="fas fa-phone phone-icon"></i>
                                <input type="tel"
                                       name="contact"
                                       id="contact_input"
                                       class="form-control"
                                       value="<?php echo htmlspecialchars($contact_parts['number']); ?>"
                                       placeholder="9123456789"
                                       maxlength="15"
                                       inputmode="numeric"
                                       autocomplete="tel"
                                       oninput="validatePhone(this, 'contact')">
                                <span class="digit-count" id="contact_count"><?php echo strlen($contact_parts['number']); ?></span>
                            </div>
                        </div>
                        <div class="input-hint"><i class="fas fa-info-circle"></i> Select country code, then enter your number</div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Address</label>
                        <div class="input-wrapper">
                            <i class="fas fa-map-marker-alt"></i>
                            <input type="text" name="address" class="form-control"
                                   value="<?php echo $guest ? htmlspecialchars($guest['address']) : ''; ?>"
                                   placeholder="Your address">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- IDENTIFICATION - READ ONLY -->
            <!-- ============================================ -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-id-card"></i>
                    <h3>Identification <span class="readonly-badge"><i class="fas fa-lock"></i> Read-only</span></h3>
                </div>

                <div class="info-box">
                    <i class="fas fa-shield-alt"></i>
                    <p>For security reasons, submitted identification cannot be changed. Please contact support for corrections.</p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-id-card"></i> ID Type</label>
                        <div class="input-wrapper">
                            <i class="fas fa-id-card"></i>
                            <input type="text" class="form-control readonly-field"
                                   value="<?php echo htmlspecialchars($guest['id_type'] ?: 'Not provided'); ?>"
                                   readonly>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> ID Number</label>
                        <div class="input-wrapper">
                            <i class="fas fa-hashtag"></i>
                            <input type="text" class="form-control readonly-field"
                                   value="<?php echo htmlspecialchars($guest['id_number'] ?: 'Not provided'); ?>"
                                   readonly>
                        </div>
                    </div>
                </div>

                <!-- ID Photo - READ ONLY -->
                <div class="form-group" style="margin-top: 15px;">
                    <label><i class="fas fa-image"></i> ID Photo <span class="readonly-badge"><i class="fas fa-lock"></i> Read-only</span></label>
                    <div class="id-photo-section">
                        <?php
                        $id_photo = getIDPhoto($guest, $_SESSION['user_id']);
                        if ($id_photo): ?>
                            <img src="<?php echo $id_photo; ?>?<?php echo time(); ?>" alt="ID Photo" class="photo-preview-small readonly-photo" id="idPreviewImg">
                        <?php else: ?>
                            <div style="width: 80px; height: 80px; border: 2px dashed #e8f0fe; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f8fafc;">
                                <i class="fas fa-id-card" style="font-size: 30px; color: #cbd5e1;"></i>
                            </div>
                        <?php endif; ?>
                        <div style="font-size: 12px; color: #94a3b8;">
                            <i class="fas fa-lock"></i> ID photo is read-only.
                        </div>
                    </div>
                </div>

                <!-- Emergency Contact (Read-only) -->
                <div class="form-row" style="margin-top: 15px;">
                    <div class="form-group">
                        <label><i class="fas fa-phone-alt"></i> Emergency Contact</label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone-alt"></i>
                            <input type="text" class="form-control readonly-field"
                                   value="<?php echo htmlspecialchars($guest['emergency_contact'] ?? 'Not provided'); ?>"
                                   readonly>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-phone-alt"></i> Emergency Number</label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone-alt"></i>
                            <input type="text" class="form-control readonly-field"
                                   value="<?php echo htmlspecialchars($guest['emergency_number'] ?? 'Not provided'); ?>"
                                   readonly>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- ACCOUNT SETTINGS -->
            <!-- ============================================ -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-lock"></i>
                    <h3>Security Settings</h3>
                </div>

                <p class="sub-note" style="margin-top:0;">Manage the email you use to sign in and, if you wish, set a new password.</p>

                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email Address</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($user['email']); ?>"
                               placeholder="your@email.com" required>
                    </div>
                </div>

                <div class="sub-heading"><i class="fas fa-key"></i> Change Password <span style="font-weight:500;color:#5b6b7e;font-size:12.5px;">(optional)</span></div>
                <p class="sub-note">Leave both password fields blank to keep your current password.</p>

                <div class="form-row">
                    <!-- New Password -->
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> New Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="new_password" id="new_password" class="form-control"
                                   placeholder="Enter new password">
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('new_password', this)" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>

                        <div class="input-hint">
                            <i class="fas fa-info-circle"></i>
                            Must be at least 8 characters, contain 1 uppercase letter and 1 number
                        </div>

                        <div class="password-requirements" id="passwordRequirements" style="display:none;">
                            <span class="req" id="reqLength"><span class="pending"><i class="fas fa-circle"></i></span> 8 characters</span>
                            <span class="req" id="reqUppercase"><span class="pending"><i class="fas fa-circle"></i></span> Uppercase</span>
                            <span class="req" id="reqNumber"><span class="pending"><i class="fas fa-circle"></i></span> Number</span>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control"
                                   placeholder="Confirm new password">
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('confirm_password', this)" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="confirmPasswordFeedback" class="confirm-feedback"></div>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- HEALTH INFORMATION -->
            <!-- ============================================ -->
            <div class="health-section">
                <h4>
                    <i class="fas fa-heartbeat section-icon"></i>
                    Health &amp; Accessibility <span style="font-size: 13px; color: #64748b; font-weight: 400;">(Optional)</span>
                </h4>

                <!-- Asthma -->
                <div class="checkbox-group">
                    <input type="checkbox" name="has_asthma" id="has_asthma" value="1" <?php echo isChecked('has_asthma'); ?>>
                    <label for="has_asthma"><i class="fas fa-lungs"></i> I have Asthma</label>
                </div>
                <div id="asthma_section" class="sub-section <?php echo (isset($guest['has_asthma']) && $guest['has_asthma'] == 1) ? 'visible' : ''; ?>">
                    <div class="form-group">
                        <label>Do you bring your inhaler?</label>
                        <select name="has_inhaler" class="form-select">
                            <option value="">Select</option>
                            <option value="yes" <?php echo selected('has_inhaler', 'yes'); ?>>Yes</option>
                            <option value="no" <?php echo selected('has_inhaler', 'no'); ?>>No</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Asthma Severity</label>
                        <select name="asthma_severity" class="form-select">
                            <option value="">Select</option>
                            <option value="mild" <?php echo selected('asthma_severity', 'mild'); ?>>Mild</option>
                            <option value="moderate" <?php echo selected('asthma_severity', 'moderate'); ?>>Moderate</option>
                            <option value="severe" <?php echo selected('asthma_severity', 'severe'); ?>>Severe</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Asthma Triggers</label>
                        <input type="text" name="asthma_triggers" class="form-control"
                               value="<?php echo htmlspecialchars($guest['asthma_triggers'] ?? ''); ?>"
                               placeholder="e.g., dust, pollen, exercise">
                    </div>
                    <div class="form-group">
                        <label>Last Attack</label>
                        <input type="text" name="last_attack" class="form-control"
                               value="<?php echo htmlspecialchars($guest['last_attack'] ?? ''); ?>"
                               placeholder="When was your last attack?">
                    </div>
                </div>

                <!-- Allergies -->
                <div class="checkbox-group">
                    <input type="checkbox" name="has_allergies" id="has_allergies" value="1" <?php echo isChecked('has_allergies'); ?>>
                    <label for="has_allergies"><i class="fas fa-allergies"></i> I have Allergies</label>
                </div>
                <div id="allergies_section" class="sub-section <?php echo (isset($guest['has_allergies']) && $guest['has_allergies'] == 1) ? 'visible' : ''; ?>">
                    <div class="form-group">
                        <label>Allergy Details</label>
                        <input type="text" name="allergies_details" class="form-control"
                               value="<?php echo htmlspecialchars($guest['allergies_details'] ?? ''); ?>"
                               placeholder="e.g., peanuts, seafood, dust">
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="has_epipen" id="has_epipen" value="yes" <?php echo isCheckedValue('has_epipen', 'yes'); ?>>
                        <label for="has_epipen"><i class="fas fa-syringe"></i> I carry an EpiPen</label>
                    </div>
                </div>

                <!-- Medical Conditions -->
                <div class="checkbox-group">
                    <input type="checkbox" name="has_medical" id="has_medical" value="1" <?php echo isChecked('has_medical_condition'); ?>>
                    <label for="has_medical"><i class="fas fa-notes-medical"></i> I have other medical conditions</label>
                </div>
                <div id="medical_section" class="sub-section <?php echo (isset($guest['has_medical_condition']) && $guest['has_medical_condition'] == 1) ? 'visible' : ''; ?>">
                    <div class="form-group">
                        <label>Medical Conditions</label>
                        <input type="text" name="medical_conditions" class="form-control"
                               value="<?php echo htmlspecialchars($guest['medical_conditions'] ?? ''); ?>"
                               placeholder="e.g., diabetes, high blood">
                    </div>
                    <div class="form-group">
                        <label>Current Medications</label>
                        <input type="text" name="medications" class="form-control"
                               value="<?php echo htmlspecialchars($guest['medications'] ?? ''); ?>"
                               placeholder="e.g., insulin, amlodipine">
                    </div>
                    <div class="form-group">
                        <label>Blood Type</label>
                        <select name="blood_type" class="form-select">
                            <option value="">Select</option>
                            <option value="A+" <?php echo selected('blood_type', 'A+'); ?>>A+</option>
                            <option value="A-" <?php echo selected('blood_type', 'A-'); ?>>A-</option>
                            <option value="B+" <?php echo selected('blood_type', 'B+'); ?>>B+</option>
                            <option value="B-" <?php echo selected('blood_type', 'B-'); ?>>B-</option>
                            <option value="O+" <?php echo selected('blood_type', 'O+'); ?>>O+</option>
                            <option value="O-" <?php echo selected('blood_type', 'O-'); ?>>O-</option>
                            <option value="AB+" <?php echo selected('blood_type', 'AB+'); ?>>AB+</option>
                            <option value="AB-" <?php echo selected('blood_type', 'AB-'); ?>>AB-</option>
                        </select>
                    </div>
                </div>

                <!-- Dietary Restrictions -->
                <div class="checkbox-group">
                    <input type="checkbox" name="has_dietary" id="has_dietary" value="1" <?php echo isChecked('has_dietary'); ?>>
                    <label for="has_dietary"><i class="fas fa-utensils"></i> I have dietary restrictions</label>
                </div>
                <div id="dietary_section" class="sub-section <?php echo (isset($guest['has_dietary']) && $guest['has_dietary'] == 1) ? 'visible' : ''; ?>">
                    <div class="form-group">
                        <label>Other dietary restrictions</label>
                        <input type="text" name="dietary_other" class="form-control"
                               value="<?php echo htmlspecialchars($guest['dietary_other'] ?? ''); ?>"
                               placeholder="Please specify">
                    </div>
                </div>

                <!-- Accessibility Needs -->
                <div class="checkbox-group">
                    <input type="checkbox" name="has_accessibility" id="has_accessibility" value="1" <?php echo isChecked('has_accessibility'); ?>>
                    <label for="has_accessibility"><i class="fas fa-wheelchair"></i> I have accessibility needs</label>
                </div>
                <div id="accessibility_section" class="sub-section <?php echo (isset($guest['has_accessibility']) && $guest['has_accessibility'] == 1) ? 'visible' : ''; ?>">
                    <div class="form-group">
                        <label>Other accessibility needs</label>
                        <input type="text" name="accessibility_other" class="form-control"
                               value="<?php echo htmlspecialchars($guest['accessibility_other'] ?? ''); ?>"
                               placeholder="Please specify">
                    </div>
                </div>

                <div class="form-group">
                    <label>Emergency Medication (if any)</label>
                    <input type="text" name="emergency_medication" class="form-control"
                           value="<?php echo htmlspecialchars($guest['emergency_medication'] ?? ''); ?>"
                           placeholder="e.g., EpiPen, inhaler, insulin">
                </div>
            </div>

            <!-- ============================================ -->
            <!-- ACTION BUTTONS -->
            <!-- ============================================ -->
            <div class="action-buttons">
                <button type="submit" name="update_profile" class="btn-save">
                    <i class="fas fa-save"></i> Save Profile Changes
                </button>
                <a href="profile.php" class="btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>

        </form>
    </div>
</div>

<!-- Camera Modal -->
<div class="camera-modal" id="cameraModal">
    <video id="cameraVideo" autoplay playsinline></video>
    <div class="camera-controls">
        <button class="btn-capture" onclick="capturePhoto()">
            <i class="fas fa-camera"></i> Capture
        </button>
        <button class="btn-close-camera" onclick="closeCamera()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>

<script>
let cameraStream = null;
let currentCameraTarget = 'profile';

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
// ✅ FULL NAME INPUT — Letters only restriction
// ============================================================
function setupFullNameInput() {
    const input = document.getElementById('full_name');
    const errorBox = document.getElementById('fullNameError');
    const errorText = document.getElementById('fullNameErrorText');
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

        if (cleaned.length > 0) {
            if (/\d/.test(cleaned)) {
                errorBox.classList.add('show');
                errorText.textContent = 'Full name cannot contain numbers.';
                input.classList.add('error');
            } else if (!NAME_PATTERN.test(cleaned)) {
                errorBox.classList.add('show');
                errorText.textContent = 'Full name contains invalid characters.';
                input.classList.add('error');
            } else {
                errorBox.classList.remove('show');
                input.classList.remove('error');
            }
        } else {
            errorBox.classList.remove('show');
            input.classList.remove('error');
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

// ============================================================
// ✅ PHONE VALIDATION with digit counter
// ============================================================
function validatePhone(input, counterId) {
    let cleaned = input.value.replace(/[^0-9]/g, '');

    if (cleaned.length > 15) {
        cleaned = cleaned.slice(0, 15);
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

// ============================================
// TOGGLE HEALTH SECTIONS
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // ✅ Setup full name restriction
    setupFullNameInput();

    const sections = [
        { checkbox: 'has_asthma', section: 'asthma_section' },
        { checkbox: 'has_allergies', section: 'allergies_section' },
        { checkbox: 'has_medical', section: 'medical_section' },
        { checkbox: 'has_dietary', section: 'dietary_section' },
        { checkbox: 'has_accessibility', section: 'accessibility_section' }
    ];

    sections.forEach(function(item) {
        const checkbox = document.getElementById(item.checkbox);
        const section = document.getElementById(item.section);
        if(checkbox && section) {
            checkbox.addEventListener('change', function() {
                section.classList.toggle('visible', this.checked);
            });
        }
    });

    // ✅ Update digit counters on page load
    const contactInput = document.getElementById('contact_input');
    if (contactInput) validatePhone(contactInput, 'contact');
});

// ============================================
// PROFILE PHOTO FUNCTIONS
// ============================================
function previewPhoto(input, type) {
    const preview = document.getElementById('avatarPreview');
    const removeBtn = document.getElementById('removePhotoBtn');
    const removePhotoInput = document.getElementById('remove_photo');

    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" alt="Profile Photo">
                <div class="avatar-overlay">
                    <i class="fas fa-camera"></i>
                </div>`;
            document.getElementById('profile_photo_base64').value = e.target.result;
            if (removeBtn) removeBtn.disabled = false;
            removePhotoInput.value = '0';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removePhoto(type) {
    if (!confirm('Remove your profile photo?')) return;

    const preview = document.getElementById('avatarPreview');
    const input = document.getElementById('profile_photo_input');
    const hiddenInput = document.getElementById('profile_photo_base64');
    const removeBtn = document.getElementById('removePhotoBtn');
    const removePhotoInput = document.getElementById('remove_photo');

    preview.innerHTML = `<span class="default-icon"><i class="fas fa-user"></i></span>
        <div class="avatar-overlay">
            <i class="fas fa-camera"></i>
        </div>`;
    input.value = '';
    hiddenInput.value = '';
    removePhotoInput.value = '1';
    if (removeBtn) removeBtn.disabled = true;
}

// ============================================
// CAMERA FUNCTIONS
// ============================================
function openCamera(type) {
    currentCameraTarget = type;
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }
    })
    .then(function(stream) {
        cameraStream = stream;
        video.srcObject = stream;
        video.play();
    })
    .catch(function(err) {
        alert('Unable to access camera. Please allow camera access or use file upload instead.');
        closeCamera();
    });
}

function closeCamera() {
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');

    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    video.srcObject = null;
    modal.classList.remove('active');
    document.body.style.overflow = 'auto';
}

function capturePhoto() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    const dataUrl = canvas.toDataURL('image/jpeg', 0.9);

    if (currentCameraTarget === 'profile') {
        const preview = document.getElementById('avatarPreview');
        const removeBtn = document.getElementById('removePhotoBtn');
        const removePhotoInput = document.getElementById('remove_photo');

        preview.innerHTML = `<img src="${dataUrl}" alt="Profile Photo">
            <div class="avatar-overlay">
                <i class="fas fa-camera"></i>
            </div>`;
        document.getElementById('profile_photo_base64').value = dataUrl;
        document.getElementById('profile_photo_input').value = '';
        removePhotoInput.value = '0';
        if (removeBtn) removeBtn.disabled = false;
    }

    closeCamera();
}

// ============================================
// PASSWORD STRENGTH INDICATOR - REAL-TIME
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('new_password');
    const confirmInput = document.getElementById('confirm_password');
    const requirementsDiv = document.getElementById('passwordRequirements');
    const reqLength = document.getElementById('reqLength');
    const reqUppercase = document.getElementById('reqUppercase');
    const reqNumber = document.getElementById('reqNumber');
    const confirmFeedback = document.getElementById('confirmPasswordFeedback');

    if (passwordInput) {
        passwordInput.addEventListener('focus', function() {
            requirementsDiv.style.display = 'flex';
        });

        passwordInput.addEventListener('blur', function() {
            if (this.value === '') {
                requirementsDiv.style.display = 'none';
            }
        });

        passwordInput.addEventListener('input', function() {
            const password = this.value;
            requirementsDiv.style.display = 'flex';

            if (password.length >= 8) {
                reqLength.className = 'req check';
                reqLength.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> 8 characters';
            } else {
                reqLength.className = password.length > 0 ? 'req cross' : 'req pending';
                reqLength.innerHTML = password.length > 0 ? '<span class="cross"><i class="fas fa-times-circle"></i></span> 8 characters' : '<span class="pending"><i class="fas fa-circle"></i></span> 8 characters';
            }

            if (/[A-Z]/.test(password)) {
                reqUppercase.className = 'req check';
                reqUppercase.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> Uppercase';
            } else {
                reqUppercase.className = password.length > 0 ? 'req cross' : 'req pending';
                reqUppercase.innerHTML = password.length > 0 ? '<span class="cross"><i class="fas fa-times-circle"></i></span> Uppercase' : '<span class="pending"><i class="fas fa-circle"></i></span> Uppercase';
            }

            if (/[0-9]/.test(password)) {
                reqNumber.className = 'req check';
                reqNumber.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> Number';
            } else {
                reqNumber.className = password.length > 0 ? 'req cross' : 'req pending';
                reqNumber.innerHTML = password.length > 0 ? '<span class="cross"><i class="fas fa-times-circle"></i></span> Number' : '<span class="pending"><i class="fas fa-circle"></i></span> Number';
            }

            checkPasswordMatch();
        });
    }

    if (confirmInput) {
        confirmInput.addEventListener('input', function() {
            checkPasswordMatch();
        });
    }

    function checkPasswordMatch() {
        if (!passwordInput || !confirmInput) return;

        const password = passwordInput.value;
        const confirm = confirmInput.value;

        if (confirm === '') {
            confirmFeedback.textContent = '';
            confirmFeedback.style.color = '#94a3b8';
            confirmInput.classList.remove('error');
            return;
        }

        if (password === confirm) {
            confirmFeedback.innerHTML = '✅ Passwords match';
            confirmFeedback.style.color = '#10b981';
            confirmInput.classList.remove('error');
        } else {
            confirmFeedback.innerHTML = '❌ Passwords do not match';
            confirmFeedback.style.color = '#ef4444';
            confirmInput.classList.add('error');
        }
    }
});

// ============================================
// FORM VALIDATION (BEFORE SUBMIT)
// ============================================
document.getElementById('editProfileForm').addEventListener('submit', function(e) {
    const fullName = document.getElementById('full_name').value.trim();
    const password = document.querySelector('input[name="new_password"]').value;
    const confirm = document.querySelector('input[name="confirm_password"]').value;
    const contact = document.querySelector('input[name="contact"]');

    // ✅ Validate full name
    if (!fullName) {
        e.preventDefault();
        alert('Please enter your full name.');
        document.getElementById('full_name').focus();
        return false;
    }
    if (fullName.length > 80) {
        e.preventDefault();
        alert('Full name must not exceed 80 characters.');
        return false;
    }
    if (/\d/.test(fullName)) {
        e.preventDefault();
        alert('Full name cannot contain numbers.');
        document.getElementById('full_name').focus();
        return false;
    }
    if (!/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/.test(fullName)) {
        e.preventDefault();
        alert("Full name can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.).");
        document.getElementById('full_name').focus();
        return false;
    }

    // ✅ Validate phone
    if (contact) {
        const cleanPhone = contact.value.replace(/[^0-9]/g, '');
        if (cleanPhone.length > 0 && (cleanPhone.length < 7 || cleanPhone.length > 15)) {
            e.preventDefault();
            alert('Contact number must be between 7 and 15 digits. You entered ' + cleanPhone.length + ' digit(s).');
            contact.focus();
            return false;
        }
    }

    if(password && password.length < 8) {
        e.preventDefault();
        alert('Password must be at least 8 characters long.');
        return false;
    }

    if(password && !/[A-Z]/.test(password)) {
        e.preventDefault();
        alert('Password must contain at least one uppercase letter (A-Z).');
        return false;
    }

    if(password && !/[0-9]/.test(password)) {
        e.preventDefault();
        alert('Password must contain at least one number (0-9).');
        return false;
    }

    if(password && password !== confirm) {
        e.preventDefault();
        alert('Passwords do not match!');
        return false;
    }

    return true;
});

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>

</body>
</html>