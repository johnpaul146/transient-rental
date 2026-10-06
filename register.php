<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'database.php';

if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {
    $content = [];
}

$logo_path = 'uploads/logos/logo.png';
$logo_exists = file_exists('uploads/logos/logo.png');
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
    $logo_exists = file_exists($logo_path);
}

$hero_path = 'uploads/hero/hero-bg.jpg';
$hero_exists = file_exists('uploads/hero/hero-bg.jpg');
if(isset($content['site_settings']['hero_image_path']) && !empty($content['site_settings']['hero_image_path'])) {
    $hero_path = $content['site_settings']['hero_image_path'];
    $hero_exists = file_exists($hero_path);
}

$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

// ============================================================
// ✅ NAME VALIDATION — Letters only (with ñ, Ñ, accents, space, - ' .)
// ============================================================
function validateName($name, $field_label) {
    $name = trim($name);
    if ($name === '') return null; // Empty is handled separately

    // Check length
    if (strlen($name) > 50) {
        return "$field_label must not exceed 50 characters";
    }

    // Check if contains numbers
    if (preg_match('/[0-9]/', $name)) {
        return "$field_label cannot contain numbers";
    }

    // Check if contains invalid special characters
    // Allow: letters (a-z, A-Z, ñ, Ñ, accented), spaces, hyphen, apostrophe, period
    if (!preg_match("/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/u", $name)) {
        return "$field_label can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.)";
    }

    return null;
}

function sanitizeName($name) {
    // Remove any characters that are not allowed
    $name = preg_replace("/[^a-zA-ZÀ-ÿñÑ\s\-'.]/u", '', $name);
    // Collapse multiple spaces
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
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
// PHONE VALIDATION HELPERS
// ============================================================
function normalizePhone($phone) {
    if (empty($phone)) return '';
    return preg_replace('/[^0-9]/', '', $phone);
}

function validatePhoneNumber($phone) {

    if (empty($phone)) {
        return "Phone number is required";
    }

    $clean = normalizePhone($phone);


    if (strlen($clean) !== 10) {
        return "Mobile number must be exactly 10 digits";
    }


    if (!preg_match('/^9[0-9]{9}$/', $clean)) {
        return "Mobile number must start with 9";
    }


    return null;
}

function validatePhoneNumberOptional($phone) {

    if (empty($phone)) {
        return null;
    }

    $clean = normalizePhone($phone);


    if (strlen($clean) !== 10) {
        return "Mobile number must be exactly 10 digits";
    }


    if (!preg_match('/^9[0-9]{9}$/', $clean)) {
        return "Mobile number must start with 9";
    }


    return null;
}



function buildFullPhone($suffix, $number) {
    $clean_number = normalizePhone($number);
    if (empty($clean_number)) return '';
    return trim($suffix) . $clean_number;
}

function buildFullName($first, $middle, $last, $suffix) {
    $parts = [];
    if (!empty($first)) $parts[] = trim($first);
    if (!empty($middle)) $parts[] = trim($middle);
    if (!empty($last)) $parts[] = trim($last);
    $name = preg_replace('/\s+/', ' ', implode(' ', $parts));
    if (!empty($suffix)) $name .= ' ' . trim($suffix);
    return $name;
}

// ============================================================
// FORM DATA
// ============================================================
$form_data = [
    'firstname' => '',
    'middlename' => '',
    'lastname' => '',
    'name_suffix' => '',
    'name_suffix_custom' => '',
    'contact_suffix' => '+63',
    'contact' => '',
    'email' => '',
    'address' => '',
    'id_type' => '',
    'id_number' => '',
    'emergency_name' => '',
    'emergency_suffix' => '+63',
    'emergency_number' => '',
    'username' => '',
    'has_asthma' => false,
    'has_inhaler' => '',
    'asthma_severity' => '',
    'asthma_triggers' => '',
    'last_attack' => '',
    'has_allergies' => false,
    'allergies_details' => '',
    'allergy_severity' => '',
    'has_epipen' => '',
    'has_medical' => false,
    'medical_conditions' => '',
    'medications' => '',
    'blood_type' => '',
    'has_dietary' => false,
    'dietary_other' => '',
    'has_accessibility' => false,
    'accessibility_other' => '',
    'emergency_medication' => ''
];

$errors = [];
$registration_success = false;
$registered_username = '';

// ============================================
// PHOTO UPLOAD HELPERS
// ============================================
function handlePhotoUpload($file, $user_id, $type) {
    $target_dir = "uploads/profile/user_" . $user_id . "/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
    if ($file['error'] !== UPLOAD_ERR_OK) return ['error' => 'File upload error: ' . $file['error']];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed_types)) return ['error' => 'Only JPG, PNG, GIF, and WEBP images are allowed'];
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) return ['error' => 'File size must be less than 5MB'];
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $type . '_' . time() . '.' . $extension;
    $target_file = $target_dir . $filename;
    if (move_uploaded_file($file['tmp_name'], $target_file)) return ['success' => true, 'filename' => $filename];
    return ['error' => 'Failed to upload file'];
}

function handleBase64Image($base64_data, $user_id, $type) {
    $target_dir = "uploads/profile/user_" . $user_id . "/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
    $image_parts = explode(';base64,', $base64_data);
    if (count($image_parts) < 2) return ['error' => 'Invalid image data'];
    $image_type = explode('/', $image_parts[0]);
    $image_type = $image_type[1] ?? 'jpg';
    $image_data = base64_decode($image_parts[1]);
    if ($image_data === false) return ['error' => 'Invalid image data'];
    $filename = $type . '_camera_' . time() . '.' . $image_type;
    $target_file = $target_dir . $filename;
    if (file_put_contents($target_file, $image_data)) return ['success' => true, 'filename' => $filename];
    return ['error' => 'Failed to save image'];
}

// ============================================================
// HANDLE REGISTRATION — NO AUTO-LOGIN
// ============================================================
if(isset($_POST['register'])) {
    try {
        foreach($form_data as $key => $value) {
            if(isset($_POST[$key])) {
                if(is_bool($form_data[$key])) $form_data[$key] = true;
                else $form_data[$key] = $_POST[$key];
            }
        }

        // Extract name parts
        $first_name  = trim($_POST['firstname'] ?? '');
        $middle_name = trim($_POST['middlename'] ?? '');
        $last_name   = trim($_POST['lastname'] ?? '');

        // Handle custom suffix
        $name_suffix = trim($_POST['name_suffix'] ?? '');
        if ($name_suffix === '__other__') {
            $name_suffix = trim($_POST['name_suffix_custom'] ?? '');
        }

        // ============================================================
        // ✅ NAME VALIDATION — Letters only, no numbers/special chars
        // ============================================================
        $name_error = validateName($first_name, "First name");
        if ($name_error) $errors['firstname'] = $name_error;

        $name_error = validateName($middle_name, "Middle name");
        if ($name_error) $errors['middlename'] = $name_error;

        $name_error = validateName($last_name, "Last name");
        if ($name_error) $errors['lastname'] = $name_error;

        // Sanitize name parts (remove invalid chars)
        $first_name  = sanitizeName($first_name);
        $middle_name = sanitizeName($middle_name);
        $last_name   = sanitizeName($last_name);
        $name_suffix = preg_replace("/[^a-zA-Z.\s]/", '', $name_suffix);
        $name_suffix = preg_replace('/\s+/', ' ', $name_suffix);
        $name_suffix = trim($name_suffix);

        // Build full name
        $fullname = buildFullName($first_name, $middle_name, $last_name, $name_suffix);

        // Normalize phones
        $clean_contact = normalizePhone($_POST['contact'] ?? '');
        $clean_emergency = normalizePhone($_POST['emergency_number'] ?? '');

        $contact_suffix = trim($_POST['contact_suffix'] ?? '+63');
        $emergency_suffix = trim($_POST['emergency_suffix'] ?? '+63');

        $full_contact = buildFullPhone($contact_suffix, $clean_contact);
        $full_emergency = buildFullPhone($emergency_suffix, $clean_emergency);

        // Additional validation
        if(empty($first_name)) $errors['firstname'] = "First name is required";
        if(empty($last_name)) $errors['lastname'] = "Last name is required";

        if(empty($_POST['contact'])) {
            $errors['contact'] = "Contact number is required";
        } else {
            $contactError = validatePhoneNumber($_POST['contact']);
            if ($contactError) $errors['contact'] = $contactError;
        }

        if(!empty($_POST['emergency_number'])) {
            $emergencyError = validatePhoneNumberOptional($_POST['emergency_number']);
            if ($emergencyError) $errors['emergency_number'] = $emergencyError;
        }

        // ID NUMBER VALIDATION — STRICT 5-20 CHARACTERS
        $id_number = trim($_POST['id_number'] ?? '');

        if(empty($id_number)) {
            $errors['id_number'] = "ID number is required";
        } elseif(strlen($id_number) < 5) {
            $errors['id_number'] = "ID number must be at least 5 characters";
        } elseif(strlen($id_number) > 20) {
            $errors['id_number'] = "ID number must not exceed 20 characters";
        } elseif(!preg_match('/^[A-Za-z0-9\-]+$/', $id_number)) {
            $errors['id_number'] = "ID number can only contain letters, numbers, and hyphens (no spaces)";
        }

        if(empty($_POST['email'])) {
            $errors['email'] = "Email is required";
        } elseif(!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Please enter a valid email address";
        }
        if(empty($_POST['id_type'])) $errors['id_type'] = "Please select an ID type";

        if(empty($_POST['username'])) {
            $errors['username'] = "Username is required";
        } elseif(strlen($_POST['username']) < 4) {
            $errors['username'] = "Username must be at least 4 characters";
        } elseif(!preg_match('/^[a-zA-Z0-9_]+$/', $_POST['username'])) {
            $errors['username'] = "Username can only contain letters, numbers, and underscore";
        }

        if(empty($_POST['password'])) {
            $errors['password'] = "Password is required";
        } else {
            $passwordErrors = validatePassword($_POST['password']);
            if (!empty($passwordErrors)) $errors['password'] = implode("<br>", $passwordErrors);
        }

        if($_POST['password'] != $_POST['confirm_password']) {
            $errors['confirm_password'] = "Passwords do not match";
        }

        if(empty($errors['username'])) {
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$_POST['username']]);
            if($check->fetch()) $errors['username'] = "Username already taken!";
        }

        if(empty($errors['email'])) {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$_POST['email']]);
            if($check->fetch()) $errors['email'] = "Email already registered!";
        }

        if(!empty($errors)) throw new Exception("Please fix the errors below");

        $pdo->beginTransaction();

        $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        // Insert user
        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, fullname, role) VALUES (?, ?, ?, ?, 'guest')");
        $stmt->execute([$_POST['username'], $hashed_password, $_POST['email'], $fullname]);
        $user_id = $pdo->lastInsertId();

        // Handle Profile Photo
        $profile_photo = null;
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $result = handlePhotoUpload($_FILES['profile_photo'], $user_id, 'profile');
            if (isset($result['error'])) throw new Exception("Profile photo error: " . $result['error']);
            $profile_photo = $result['filename'];
        } elseif (isset($_POST['profile_photo_base64']) && !empty($_POST['profile_photo_base64'])) {
            $result = handleBase64Image($_POST['profile_photo_base64'], $user_id, 'profile');
            if (isset($result['error'])) throw new Exception("Profile photo error: " . $result['error']);
            $profile_photo = $result['filename'];
        }

        // Handle ID Photo
        $id_photo = null;
        if (isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK) {
            $result = handlePhotoUpload($_FILES['id_photo'], $user_id, 'id');
            if (isset($result['error'])) throw new Exception("ID photo error: " . $result['error']);
            $id_photo = $result['filename'];
        } elseif (isset($_POST['id_photo_base64']) && !empty($_POST['id_photo_base64'])) {
            $result = handleBase64Image($_POST['id_photo_base64'], $user_id, 'id');
            if (isset($result['error'])) throw new Exception("ID photo error: " . $result['error']);
            $id_photo = $result['filename'];
        }

        // Collect allergy types
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

        // INSERT into guests
        $stmt = $pdo->prepare("INSERT INTO guests (
            user_id, full_name, first_name, middle_name, last_name, name_suffix,
            contact_number, email, address, 
            id_type, id_number, emergency_contact, emergency_number,
            has_asthma, asthma_severity, has_inhaler, asthma_triggers, last_attack,
            has_allergies, allergy_types, allergies_details, allergy_severity, has_epipen,
            has_medical_condition, medical_conditions, medications, blood_type,
            has_dietary, dietary_types, dietary_other,
            has_accessibility, access_needs, accessibility_other,
            emergency_medication, profile_photo, id_photo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $has_asthma = isset($_POST['has_asthma']) ? 1 : 0;
        $has_allergies = isset($_POST['has_allergies']) ? 1 : 0;
        $has_medical = isset($_POST['has_medical']) ? 1 : 0;
        $has_dietary = isset($_POST['has_dietary']) ? 1 : 0;
        $has_accessibility = isset($_POST['has_accessibility']) ? 1 : 0;

        $stmt->execute([
            $user_id,
            $fullname,
            $first_name  ?: null,
            $middle_name ?: null,
            $last_name   ?: null,
            $name_suffix ?: null,
            $full_contact,
            $_POST['email'],
            $_POST['address'],
            $_POST['id_type'],
            $id_number,
            $_POST['emergency_name'],
            $full_emergency,
            $has_asthma,
            $_POST['asthma_severity'] ?? null,
            $_POST['has_inhaler'] ?? null,
            $_POST['asthma_triggers'] ?? null,
            $_POST['last_attack'] ?? null,
            $has_allergies,
            json_encode($allergy_types),
            $_POST['allergies_details'] ?? null,
            $_POST['allergy_severity'] ?? null,
            $_POST['has_epipen'] ?? null,
            $has_medical,
            $_POST['medical_conditions'] ?? null,
            $_POST['medications'] ?? null,
            $_POST['blood_type'] ?? null,
            $has_dietary,
            json_encode($dietary_types),
            $_POST['dietary_other'] ?? null,
            $has_accessibility,
            json_encode($access_needs),
            $_POST['accessibility_other'] ?? null,
            $_POST['emergency_medication'] ?? null,
            $profile_photo,
            $id_photo
        ]);

        $pdo->commit();

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'register',
                'auth',
                "New user registered: {$_POST['username']} ({$_POST['email']})",
                $user_id,
                'user'
            );
        }

        $_SESSION['registration_success'] = true;
        $_SESSION['registered_username'] = $_POST['username'];

        header("Location: login.php?registered=1");
        exit();

    } catch(Exception $e) {
        $error = "Error: " . $e->getMessage();
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function isChecked($field) {
    global $form_data;
    return isset($form_data[$field]) && $form_data[$field] ? 'checked' : '';
}
function selected($field, $value) {
    global $form_data;
    return (isset($form_data[$field]) && $form_data[$field] == $value) ? 'selected' : '';
}
function value($field) {
    global $form_data;
    return isset($form_data[$field]) ? htmlspecialchars($form_data[$field]) : '';
}
function hasError($field) {
    global $errors;
    return isset($errors[$field]) ? 'error' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Register — <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            padding: 40px 20px;
            position: relative;
            background-color: #0B2447;
            <?php if($hero_exists): ?>
            background-image:
                linear-gradient(135deg, rgba(11, 36, 71, 0.75), rgba(11, 61, 145, 0.65)),
                url('<?php echo htmlspecialchars($hero_path); ?>?<?php echo time(); ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            <?php else: ?>
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            <?php endif; ?>
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(ellipse at center, transparent 0%, rgba(0,0,0,0.35) 100%);
            pointer-events: none;
            z-index: 0;
        }

        .register-container {
            position: relative;
            z-index: 1;
            max-width: 950px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
        }

        .register-header {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            color: white;
            padding: 35px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .register-header::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(244, 180, 0, 0.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .register-header .logo-image {
            width: 80px; height: 80px;
            border-radius: 20px;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.35);
            padding: 4px;
            background: white;
            margin: 0 auto 15px;
            display: block;
            position: relative; z-index: 1;
            box-shadow: 0 8px 25px rgba(0,0,0,0.25);
        }

        .register-header .logo-icon {
            width: 70px; height: 70px;
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 15px;
            font-size: 32px; color: #F4B400;
            position: relative; z-index: 1;
        }

        .register-header h1 {
            font-size: 26px; font-weight: 700;
            margin: 0 0 4px 0;
            position: relative; z-index: 1;
        }

        .register-header p {
            font-size: 13px; color: #e0eeff;
            margin: 0; opacity: 0.85;
            position: relative; z-index: 1;
        }

        .register-body { padding: 40px; }

        /* =========================
   REGISTER WIZARD
   ========================= */

.wizard-steps{
    display:flex;
    justify-content:center;
    gap:12px;
    margin-bottom:35px;
    flex-wrap:wrap;
}

.wizard-step-indicator{
    padding:10px 18px;
    border-radius:30px;
    background:#eef4fb;
    color:#64748b;
    font-size:13px;
    font-weight:700;
    transition:.3s;
}

.wizard-step-indicator.active{
    background:#0B7CC1;
    color:white;
}

.wizard-panel{
    display:none;
}

.wizard-panel.active{
    display:block;
}

.wizard-buttons{
    display:flex;
    justify-content:flex-end;
    gap:15px;
    margin-top:40px;
    padding-top:25px;
    border-top:1px solid #E8F0FE;
}

.wizard-buttons button{
    padding:14px 30px;
    border:none;
    border-radius:12px;
    font-weight:700;
    cursor:pointer;
}

.btn-prev{
    background:#e2e8f0;
    color:#06263D;
}

.btn-next{
    background:#F4B400;
    color:#06263D;
}

        h4 {
            color: #0B2447;
            margin: 30px 0 20px 0;
            font-weight: 600; font-size: 16px;
            display: flex; align-items: center; gap: 10px;
        }

        h4 .section-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 15px; flex-shrink: 0;
        }

        .section-divider {
            height: 2px;
            background: linear-gradient(90deg, #4DA6D9, transparent);
            margin: 5px 0 20px 0;
            opacity: 0.4;
        }

        .form-group { margin-bottom: 20px; position: relative; }

        label {
            display: block; margin-bottom: 8px;
            color: #475569; font-weight: 600;
            font-size: 13px; letter-spacing: 0.3px;
        }

        .form-control, .form-select {
            width: 100%; padding: 12px 16px;
            border: 2px solid #e2e8f0; border-radius: 12px;
            font-size: 14px; transition: all 0.3s;
            background: #fafafa;
        }

        .form-control:focus, .form-select:focus {
            outline: none; border-color: #4DA6D9;
            box-shadow: 0 0 0 4px rgba(77, 166, 217, 0.15);
            background: white;
        }

        .form-control.error, .form-select.error {
            border-color: #dc2626; background-color: #fee2e2;
        }

        .form-row { display: grid; grid-template-columns: 1fr 1fr 1fr 140px; gap: 18px; }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }

        .profile-photo-center { display: flex; justify-content: center; margin: 10px 0 20px 0; }

        .photo-upload-container {
            display: flex; flex-direction: column;
            align-items: center; gap: 12px;
        }

        .photo-upload-container.profile-photo .photo-preview {
            width: 140px; height: 140px; border-radius: 50%;
        }

        .photo-upload-container.id-photo .photo-preview {
            width: 100%; height: 130px; border-radius: 12px;
        }

        .photo-preview {
            border: 2px dashed #cbd5e1;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; background: #f8fafc;
            cursor: pointer; transition: all 0.3s;
            position: relative;
        }

        .photo-preview:hover { border-color: #4DA6D9; background: #f0f7fb; }
        .photo-preview.has-image { border-style: solid; border-color: #4DA6D9; }
        .photo-preview img { width: 100%; height: 100%; object-fit: cover; }

        .photo-preview .placeholder {
            text-align: center; color: #94a3b8; padding: 15px;
        }

        .photo-preview .placeholder i {
            font-size: 32px; display: block;
            margin-bottom: 6px; color: #cbd5e1;
        }

        .photo-preview .placeholder span { font-size: 11px; display: block; }

        .photo-preview .photo-overlay {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(11, 36, 71, 0.55);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.3s;
            border-radius: inherit;
        }

        .photo-preview:hover .photo-overlay { opacity: 1; }
        .photo-preview .photo-overlay i { color: white; font-size: 28px; }

        .photo-actions {
            display: flex; gap: 8px;
            flex-wrap: wrap; justify-content: center;
            width: 100%;
        }

        .photo-actions .btn-photo {
            padding: 6px 14px;
            border: none; border-radius: 8px;
            font-size: 11px; font-weight: 600;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center; gap: 5px;
        }

        .photo-actions .btn-photo:hover { transform: translateY(-2px); }
        .photo-actions .btn-upload { background: #4DA6D9; color: white; }
        .photo-actions .btn-upload:hover { background: #3a8bbf; }
        .photo-actions .btn-camera { background: #10b981; color: white; }
        .photo-actions .btn-camera:hover { background: #059669; }
        .photo-actions .btn-remove { background: #ef4444; color: white; }
        .photo-actions .btn-remove:hover { background: #dc2626; }
        .photo-actions .btn-remove:disabled { opacity: 0.4; cursor: not-allowed; }
        .photo-actions .btn-remove:disabled:hover { transform: none; }

        .hidden-file-input { display: none; }

        .id-section-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 18px;
            align-items: start;
        }

        .id-section-grid .form-group { margin-bottom: 0; }

        .health-section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 16px; padding: 25px;
            margin: 30px 0; border: 1px solid #e2e8f0;
        }

        .health-section h4 { margin-top: 0; color: #ef4444; }
        .health-section h4 .section-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }

        .checkbox-group {
            display: flex; align-items: center; gap: 12px;
            margin: 15px 0; padding: 10px 15px;
            background: white; border-radius: 10px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
        }

        .checkbox-group:hover { border-color: #4DA6D9; }

        .checkbox-group input[type="checkbox"] {
            width: 20px; height: 20px;
            cursor: pointer; accent-color: #4DA6D9;
        }

        .checkbox-group label {
            margin-bottom: 0; cursor: pointer;
            font-weight: 500; color: #1e293b;
        }

        .sub-section {
            margin-left: 30px; padding: 18px;
            background: white; border-radius: 12px;
            margin-top: 10px; border: 1px solid #e2e8f0;
            display: none;
        }

        .sub-section.visible {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .required-field { color: #dc2626; margin-left: 3px; }
        .field-info { color: #94a3b8; font-size: 11px; margin-top: 5px; }

        .btn-register {
            width: 100%; padding: 16px;
            background: #F4B400; color: #0B2447;
            border: none; border-radius: 12px;
            font-size: 16px; font-weight: 700;
            cursor: pointer; transition: all 0.3s;
            margin-top: 25px; letter-spacing: 0.5px;
            box-shadow: 0 6px 20px rgba(244, 180, 0, 0.3);
            display: flex; align-items: center; justify-content: center;
            gap: 10px;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(244, 180, 0, 0.45);
            background: #e6a800; color: #0B2447;
        }

        .login-link {
            text-align: center; margin-top: 20px;
            color: #64748b; font-size: 14px;
        }

        .login-link a {
            color: #4DA6D9; text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover { text-decoration: underline; color: #3a8bbf; }

        .alert {
            padding: 15px 20px; border-radius: 12px;
            margin-bottom: 20px; display: flex;
            align-items: center; gap: 10px; font-size: 13px;
        }

        .alert-error {
            background: #fee2e2; color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-error i { font-size: 18px; }

        .error-field-label {
            color: #dc2626; font-size: 12px;
            margin-top: 5px; display: flex;
            align-items: center; gap: 5px;
        }

        .error-summary {
            background: #fee2e2; border: 2px solid #dc2626;
            border-radius: 12px; padding: 20px;
            margin-bottom: 30px; text-align: center;
        }

        .error-summary h4 {
            color: #991b1b; font-size: 18px;
            margin: 0 0 5px 0;
        }

        .error-summary p { color: #7f1d1d; margin: 0; font-size: 14px; }

        .password-wrapper { position: relative; width: 100%; }
        .password-wrapper .form-control { padding-right: 45px; width: 100%; }

        .password-wrapper .toggle-password {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            padding: 6px 8px; cursor: pointer;
            color: #94a3b8; font-size: 18px;
            transition: color 0.3s; z-index: 5;
            line-height: 1;
            display: flex; align-items: center; justify-content: center;
        }

        .password-wrapper .toggle-password:hover { color: #4DA6D9; }
        .password-wrapper .toggle-password.active { color: #4DA6D9; }
        .password-wrapper .toggle-password i { pointer-events: none; font-size: 18px; }

        .password-requirements {
            font-size: 11px; margin-top: 6px;
            padding: 6px 12px; background: #f8fafc;
            border-radius: 6px; border: 1px solid #e2e8f0;
            display: flex; flex-wrap: wrap;
            gap: 8px 12px; align-items: center;
        }

        .password-requirements .req {
            display: inline-flex; align-items: center;
            gap: 4px; font-size: 11px; padding: 2px 0;
        }

        .password-requirements .req .check { color: #10b981; }
        .password-requirements .req .cross { color: #ef4444; }
        .password-requirements .req .pending { color: #94a3b8; }

        .confirm-feedback {
            font-size: 12px; margin-top: 5px;
            display: block; clear: both;
        }

        .camera-modal {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(11, 36, 71, 0.95);
            z-index: 10000; align-items: center;
            justify-content: center; flex-direction: column;
        }

        .camera-modal.active { display: flex; }

        .camera-modal video {
            max-width: 90%; max-height: 65vh;
            border-radius: 16px; background: #000;
            border: 2px solid #4DA6D9;
        }

        .camera-modal .camera-controls {
            margin-top: 25px; display: flex; gap: 15px;
        }

        .camera-modal .camera-controls button {
            padding: 14px 35px; border: none;
            border-radius: 12px; font-size: 16px;
            font-weight: 600; cursor: pointer;
            transition: all 0.3s;
            display: flex; align-items: center; gap: 10px;
        }

        .camera-modal .camera-controls .btn-capture { background: #10b981; color: white; }
        .camera-modal .camera-controls .btn-capture:hover { background: #059669; transform: scale(1.05); }
        .camera-modal .camera-controls .btn-close-camera { background: #ef4444; color: white; }
        .camera-modal .camera-controls .btn-close-camera:hover { background: #dc2626; }

        .phone-input-group {
            display: flex; gap: 8px; align-items: stretch;
        }

        .phone-suffix-select {
            min-width: 90px; max-width: 100px;
            padding: 12px 10px; border: 2px solid #e2e8f0;
            border-radius: 12px; font-size: 13px;
            font-weight: 600; background: #f8fafc;
            color: #0B2447; cursor: pointer;
            transition: all 0.3s; flex-shrink: 0;
        }

        .phone-suffix-select:focus {
            outline: none; border-color: #4DA6D9;
            box-shadow: 0 0 0 4px rgba(77, 166, 217, 0.15);
            background: white;
        }

        .phone-input-wrapper {
            position: relative; flex: 1; min-width: 0;
        }

        .phone-input-wrapper .form-control {
            padding-left: 38px; padding-right: 60px;
            font-family: 'Courier New', monospace;
            font-weight: 600; letter-spacing: 1px;
        }

        .phone-input-wrapper .phone-icon {
            position: absolute; left: 13px; top: 50%;
            transform: translateY(-50%);
            color: #94a3b8; font-size: 14px;
            pointer-events: none;
        }

        .phone-input-wrapper .digit-count {
            position: absolute; right: 10px; top: 50%;
            transform: translateY(-50%);
            font-size: 10px; color: #94a3b8;
            background: #f1f5f9; padding: 2px 7px;
            border-radius: 10px; font-weight: 600;
            pointer-events: none;
        }

        .phone-input-wrapper .digit-count.complete {
            color: #10b981; background: #d1fae5;
        }

        .id-number-wrapper {
            position: relative;
        }

        .id-number-wrapper .form-control {
            padding-right: 70px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .id-number-wrapper .id-char-count {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            font-size: 10px; color: #94a3b8;
            background: #f1f5f9; padding: 3px 8px;
            border-radius: 10px; font-weight: 600;
            pointer-events: none;
            transition: all 0.2s;
        }

        .id-number-wrapper .id-char-count.valid {
            color: #10b981; background: #d1fae5;
        }

        .id-number-wrapper .id-char-count.warning {
            color: #f59e0b; background: #fef3c7;
        }

        .id-number-wrapper .id-char-count.danger {
            color: #ef4444; background: #fee2e2;
        }

        .name-suffix-custom-wrapper {
            display: none; margin-top: 8px;
        }
        .name-suffix-custom-wrapper.visible { display: block; }

        /* ✅ Name field with input restriction indicator */
        .name-input-wrapper {
            position: relative;
        }

        .name-input-wrapper .name-hint-icon {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            color: #cbd5e1; font-size: 12px;
            pointer-events: none;
            transition: color 0.3s;
        }

        .name-input-wrapper .form-control:focus ~ .name-hint-icon {
            color: #4DA6D9;
        }

        @media (max-width: 900px) {
            .form-row { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 768px) {
            .form-row, .form-row-2, .form-row-3 { grid-template-columns: 1fr; }
            .id-section-grid { grid-template-columns: 1fr; }
            .register-header { padding: 25px 20px; }
            .register-header h1 { font-size: 22px; }
            .register-header .logo-image { width: 65px; height: 65px; }
            .register-header .logo-icon { width: 58px; height: 58px; font-size: 26px; }
            .register-body { padding: 22px; }
            .photo-upload-container.profile-photo .photo-preview { width: 120px; height: 120px; }
            .camera-modal video { max-height: 50vh; }
            .password-wrapper .toggle-password { font-size: 16px; right: 10px; }
            .password-requirements { font-size: 10px; padding: 4px 10px; gap: 4px 8px; }
        }

        @media (max-width: 480px) {
            body { padding: 20px 12px; }
            .register-header { padding: 20px 15px; }
            .register-header h1 { font-size: 19px; }
            .register-body { padding: 18px 15px; }
            .photo-actions .btn-photo { font-size: 12px; padding: 8px 14px; min-height: 36px; }
            .password-wrapper .toggle-password { font-size: 14px; right: 8px; padding: 4px 6px; }
            .password-requirements {
                font-size: 9px; padding: 4px 8px;
                gap: 3px 6px; flex-direction: column;
                align-items: flex-start;
            }
            .phone-suffix-select { min-width: 80px; font-size: 12px; }
            .id-number-wrapper .form-control { padding-right: 60px; font-size: 13px; }
            .id-number-wrapper .id-char-count { font-size: 9px; padding: 2px 6px; right: 8px; }
        }
        @media (max-width: 400px) {
            .phone-input-group { flex-direction: column; }
            .phone-input-group .phone-suffix-select { width: 100%; max-width: none; }
        }
/* ==========================================
   REGISTER TYPOGRAPHY + COLOR CONSISTENCY
   ========================================== */

.register-container,
.register-container * {
    font-family:
        'Inter',
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif !important;
}


/* Main text */
.register-body {
    color:#06263D;
}


/* Section titles */
.register-body h4 {
    color:#06263D !important;
    font-weight:800 !important;
}


/* Section icons */
.section-icon {
    background:#0B7CC1 !important;
}


/* Divider */
.section-divider {
    background:
    linear-gradient(
        90deg,
        #0B7CC1,
        transparent
    ) !important;
}


/* Labels */
.register-body label {
    color:#334155 !important;
    font-weight:600 !important;
}


/* Inputs */
.form-control,
.form-select,
.phone-suffix-select {

    background:#FFFFFF !important;
    color:#06263D !important;

    border-color:#CBD5E1 !important;
}


/* Placeholder */
.form-control::placeholder {
    color:#94A3B8 !important;
}


/* Focus */
.form-control:focus,
.form-select:focus {

    border-color:#0B7CC1 !important;

    box-shadow:
    0 0 0 4px rgba(11,124,193,.12) !important;
}


/* Helper text */
.field-info {
    color:#64748B !important;
}


/* Wizard text */
.wizard-step-indicator {

    font-family:'Inter',sans-serif !important;
    font-weight:700 !important;
}


.wizard-step-indicator.active {

    background:#0B7CC1 !important;
}


/* Buttons */
.btn-prev,
.btn-next,
.btn-register {

    font-family:'Inter',sans-serif !important;
    font-weight:800 !important;
}


/* Login link */
.login-link a {

    color:#0B7CC1 !important;

}

/* =====================================
   REGISTER WIZARD ACTION DESIGN
   ===================================== */

.wizard-buttons {
    display:flex !important;
    justify-content:space-between !important;
    align-items:center;
    gap:20px;
    margin-top:40px !important;
    padding-top:25px;
    border-top:1px solid #E8F0FE;
}


/* Previous Button */

.btn-prev {
    min-width:150px;
    height:48px;
    padding:0 28px !important;

    background:#F1F5F9 !important;
    color:#06263D !important;

    border:1px solid #CBD5E1 !important;
    border-radius:14px !important;

    font-size:15px !important;
    font-weight:700 !important;

    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;

    transition:.25s;
}


.btn-prev:hover {

    background:#E2E8F0 !important;

    transform:translateY(-2px);
}



/* Next Button */

.btn-next {

    min-width:150px;
    height:48px;
    padding:0 28px !important;

    background:#F4B400 !important;
    color:#06263D !important;

    border:none !important;
    border-radius:14px !important;

    font-size:15px !important;
    font-weight:800 !important;

    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;

    box-shadow:
    0 8px 20px rgba(244,180,0,.25);

    transition:.25s;
}


.btn-next:hover {

    background:#E5A900 !important;

    transform:translateY(-2px);

    box-shadow:
    0 12px 28px rgba(244,180,0,.35);
}



/* Create Account final button */

.btn-register {

    height:52px !important;

    border-radius:14px !important;

    font-size:16px !important;

    box-shadow:
    0 10px 25px rgba(244,180,0,.3);
}



/* Login link redesign */

.login-link {

    margin-top:28px !important;

    padding-top:20px;

    border-top:1px solid #E8F0FE;

    font-size:14px !important;

    color:#64748B !important;

}


.login-link a {

    display:inline-flex;

    margin-left:5px;

    padding:6px 14px;

    background:#E8F4FC;

    color:#0B7CC1 !important;

    border-radius:20px;

    font-weight:700 !important;

    text-decoration:none !important;

    transition:.25s;

}


.login-link a:hover {

    background:#0B7CC1;

    color:white !important;

}

/* Font Awesome rendering fix */
i.fas,
i.fa,
i.far,
i.fab {
    font-style: normal !important;
}

.fa,
.fas {
    font-family: "Font Awesome 6 Free" !important;
    font-weight: 900 !important;
}

.fab {
    font-family: "Font Awesome 6 Brands" !important;
}

/* Header back home button */

.header-back-home {

    display:inline-flex;

    align-items:center;

    gap:8px;

    margin-top:18px;

    padding:8px 18px;

    border-radius:20px;

    background:rgba(255,255,255,.15);

    border:1px solid rgba(255,255,255,.35);

    color:white !important;

    text-decoration:none;

    font-size:13px;

    font-weight:700;

    transition:.25s;

}


.header-back-home:hover {

    background:#F4B400;

    color:#06263D !important;

    transform:translateY(-2px);

}
        
    </style>
</head>
<body>

<div class="register-container">

   <div class="register-header">

    <?php if($logo_exists && !is_dir($logo_path)): ?>
        <img src="<?php echo htmlspecialchars($logo_path); ?>?<?php echo time(); ?>"
             alt="<?php echo htmlspecialchars($site_name); ?>"
             class="logo-image">
    <?php else: ?>
        <div class="logo-icon">
            <i class="fas fa-umbrella-beach"></i>
        </div>
    <?php endif; ?>

    <h1>
        <i class="fas fa-user-plus"></i>
        Create Account
    </h1>

    <p>
        Join <?php echo htmlspecialchars($site_name); ?> today
    </p>


    <a href="index.php" class="header-back-home">
        <i class="fas fa-arrow-left"></i>
        Back to Homepage
    </a>

</div>

    <div class="register-body">

    <div class="wizard-steps">
    <div class="wizard-step-indicator active" data-step-indicator="1">
        Step 1: Personal Information
    </div>

    <div class="wizard-step-indicator" data-step-indicator="2">
        Step 2: Verification
    </div>

    <div class="wizard-step-indicator" data-step-indicator="3">
        Step 3: Account Setup
    </div>
</div>

    <?php if(!empty($errors)): ?>
        <div class="error-summary">
            <h4><i class="fas fa-exclamation-triangle"></i> Please fix the following:</h4>
            <p>The fields with red backgrounds need to be fixed.</p>
        </div>
    <?php endif; ?>

    <?php if(isset($error)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="registerForm" enctype="multipart/form-data">

<div class="wizard-panel active" data-step="1">

        <!-- PROFILE PHOTO -->
        <h4>
            <span class="section-icon"><i class="fas fa-user-circle"></i></span>
            Profile Photo
        </h4>
        <div class="section-divider"></div>

        <div class="profile-photo-center">
            <div class="photo-upload-container profile-photo">
                <div class="photo-preview" id="profilePreview" onclick="document.getElementById('profile_photo_input').click()">
                    <div class="placeholder">
                        <i class="fas fa-user"></i>
                        <span>Upload Photo</span>
                    </div>
                    <div class="photo-overlay">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                <div class="photo-actions">
                    <button type="button" class="btn-photo btn-upload" onclick="document.getElementById('profile_photo_input').click()">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                    <button type="button" class="btn-photo btn-camera" onclick="openCamera('profile')">
                        <i class="fas fa-camera"></i> Camera
                    </button>
                    <button type="button" class="btn-photo btn-remove" id="profileRemoveBtn" onclick="removePhoto('profile')" disabled>
                        <i class="fas fa-times"></i> Remove
                    </button>
                </div>
                <input type="file" id="profile_photo_input" name="profile_photo" class="hidden-file-input" accept="image/*" onchange="previewPhoto(this, 'profile')">
                <input type="hidden" id="profile_photo_base64" name="profile_photo_base64">
            </div>
        </div>

        <!-- PERSONAL INFORMATION -->
        <h4>
            <span class="section-icon"><i class="fas fa-user"></i></span>
            Personal Information
        </h4>
        <div class="section-divider"></div>

        <div class="form-row">
            <div class="form-group">
                <label>First Name <span class="required-field">*</span></label>
                <div class="name-input-wrapper">
                    <input type="text"
                           name="firstname"
                           class="form-control <?php echo hasError('firstname'); ?>"
                           value="<?php echo value('firstname'); ?>"
                           placeholder="First Name"
                           maxlength="50"
                           data-name-field="1"
                           autocomplete="given-name"
                           required>
                    <i class="fas fa-font name-hint-icon"></i>
                </div>
                <?php if(hasError('firstname')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['firstname']; ?></div>
                <?php endif; ?>
                <div class="field-info">Letters only — no numbers or special characters</div>
            </div>
            <div class="form-group">
                <label>Middle Name</label>
                <div class="name-input-wrapper">
                    <input type="text"
                           name="middlename"
                           class="form-control <?php echo hasError('middlename'); ?>"
                           value="<?php echo value('middlename'); ?>"
                           placeholder="Middle Name (Optional)"
                           maxlength="50"
                           data-name-field="1"
                           autocomplete="additional-name">
                    <i class="fas fa-font name-hint-icon"></i>
                </div>
                <?php if(hasError('middlename')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['middlename']; ?></div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Last Name <span class="required-field">*</span></label>
                <div class="name-input-wrapper">
                    <input type="text"
                           name="lastname"
                           class="form-control <?php echo hasError('lastname'); ?>"
                           value="<?php echo value('lastname'); ?>"
                           placeholder="Last Name"
                           maxlength="50"
                           data-name-field="1"
                           autocomplete="family-name"
                           required>
                    <i class="fas fa-font name-hint-icon"></i>
                </div>
                <?php if(hasError('lastname')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['lastname']; ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Suffix</label>
                <select name="name_suffix" id="name_suffix" class="form-select" onchange="toggleNameSuffixCustom()">
                    <option value="" <?php echo selected('name_suffix', ''); ?>>None</option>
                    <option value="Jr." <?php echo selected('name_suffix', 'Jr.'); ?>>Jr. (Junior)</option>
                    <option value="Sr." <?php echo selected('name_suffix', 'Sr.'); ?>>Sr. (Senior)</option>
                    <option value="II" <?php echo selected('name_suffix', 'II'); ?>>II (2nd)</option>
                    <option value="III" <?php echo selected('name_suffix', 'III'); ?>>III (3rd)</option>
                    <option value="IV" <?php echo selected('name_suffix', 'IV'); ?>>IV (4th)</option>
                    <option value="V" <?php echo selected('name_suffix', 'V'); ?>>V (5th)</option>
                    <option value="VI" <?php echo selected('name_suffix', 'VI'); ?>>VI (6th)</option>
                    <option value="VII" <?php echo selected('name_suffix', 'VII'); ?>>VII (7th)</option>
                    <option value="VIII" <?php echo selected('name_suffix', 'VIII'); ?>>VIII (8th)</option>
                    <option value="IX" <?php echo selected('name_suffix', 'IX'); ?>>IX (9th)</option>
                    <option value="X" <?php echo selected('name_suffix', 'X'); ?>>X (10th)</option>
                    <option value="__other__" <?php echo selected('name_suffix', '__other__'); ?>>+ Other...</option>
                </select>
            </div>
        </div>

        <div class="form-group name-suffix-custom-wrapper" id="nameSuffixCustomWrapper">
            <label>Custom Suffix</label>
            <input type="text" name="name_suffix_custom" id="name_suffix_custom" class="form-control" value="<?php echo value('name_suffix_custom'); ?>" placeholder="e.g., LPT, DVM, DPT" maxlength="20">
            <div class="field-info">
                <i class="fas fa-info-circle"></i> Max 20 characters (letters, dots, and spaces only)
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group">
                <label>Contact Number <span class="required-field">*</span></label>
                <div class="phone-input-group">
                    <select name="contact_suffix" class="phone-suffix-select" aria-label="Country code">
                        <option value="+63" <?php echo selected('contact_suffix', '+63'); ?>>+63 🇵🇭</option>
                        <option value="+1" <?php echo selected('contact_suffix', '+1'); ?>>+1 🇺🇸</option>
                        <option value="+44" <?php echo selected('contact_suffix', '+44'); ?>>+44 🇬🇧</option>
                        <option value="+61" <?php echo selected('contact_suffix', '+61'); ?>>+61 🇦🇺</option>
                        <option value="+81" <?php echo selected('contact_suffix', '+81'); ?>>+81 🇯🇵</option>
                        <option value="+82" <?php echo selected('contact_suffix', '+82'); ?>>+82 🇰🇷</option>
                        <option value="+86" <?php echo selected('contact_suffix', '+86'); ?>>+86 🇨🇳</option>
                        <option value="+65" <?php echo selected('contact_suffix', '+65'); ?>>+65 🇸🇬</option>
                        <option value="+60" <?php echo selected('contact_suffix', '+60'); ?>>+60 🇲🇾</option>
                        <option value="+62" <?php echo selected('contact_suffix', '+62'); ?>>+62 🇮🇩</option>
                        <option value="+66" <?php echo selected('contact_suffix', '+66'); ?>>+66 🇹🇭</option>
                        <option value="+84" <?php echo selected('contact_suffix', '+84'); ?>>+84 🇻🇳</option>
                        <option value="+91" <?php echo selected('contact_suffix', '+91'); ?>>+91 🇮🇳</option>
                        <option value="+971" <?php echo selected('contact_suffix', '+971'); ?>>+971 🇦🇪</option>
                        <option value="+966" <?php echo selected('contact_suffix', '+966'); ?>>+966 🇸🇦</option>
                    </select>
                    <div class="phone-input-wrapper">
                        <i class="fas fa-phone phone-icon"></i>
                        <input type="tel" 
                               name="contact" 
                               id="contact_input"
                               class="form-control <?php echo hasError('contact'); ?>" 
                               value="<?php echo value('contact'); ?>" 
                               placeholder="9123456789" 
                               maxlength="10"
                               inputmode="numeric"
                               autocomplete="tel"
                               required>
                        <span class="digit-count" id="contactDigitCount">0/10</span>
                    </div>
                </div>
                <?php if(hasError('contact')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['contact']; ?></div>
                <?php endif; ?>
                <div class="field-info">
                    <i class="fas fa-info-circle"></i> Select country code, then enter your number
                </div>
            </div>
            
            <div class="form-group">
                <label>Email <span class="required-field">*</span></label>
                <input type="email" name="email" class="form-control <?php echo hasError('email'); ?>" value="<?php echo value('email'); ?>" placeholder="your@email.com" required>
                <?php if(hasError('email')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['email']; ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label>Address</label>
            <input type="text" name="address" class="form-control" value="<?php echo value('address'); ?>" placeholder="Your complete address">
        </div>

        <!-- IDENTIFICATION -->
        <h4>
            <span class="section-icon"><i class="fas fa-id-card"></i></span>
            Identification
        </h4>
        <div class="section-divider"></div>

        <div class="id-section-grid">
            <div class="form-group">
                <label>ID Type <span class="required-field">*</span></label>
                <select name="id_type" class="form-select <?php echo hasError('id_type'); ?>" required>
                    <option value="">Select ID Type</option>
                    <option value="Passport" <?php echo selected('id_type', 'Passport'); ?>>Passport</option>
                    <option value="Driver's License" <?php echo selected('id_type', "Driver's License"); ?>>Driver's License</option>
                    <option value="National ID" <?php echo selected('id_type', 'National ID'); ?>>National ID</option>
                    <option value="UMID" <?php echo selected('id_type', 'UMID'); ?>>UMID</option>
                    <option value="Postal ID" <?php echo selected('id_type', 'Postal ID'); ?>>Postal ID</option>
                </select>
                <?php if(hasError('id_type')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['id_type']; ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>ID Number <span class="required-field">*</span></label>
                <div class="id-number-wrapper">
                    <input type="text"
                           name="id_number"
                           id="id_number_input"
                           class="form-control <?php echo hasError('id_number'); ?>"
                           value="<?php echo value('id_number'); ?>"
                           placeholder="e.g., AB1234567"
                           minlength="5"
                           maxlength="20"
                           autocomplete="off"
                           required>
                    <span class="id-char-count" id="idCharCount">0/20</span>
                </div>
                <?php if(hasError('id_number')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['id_number']; ?></div>
                <?php endif; ?>
                <div class="field-info">
                    <i class="fas fa-info-circle"></i> 5-20 characters only (letters, numbers, hyphen)
                </div>
            </div>

            <div>
                <label>ID Photo <span class="required-field">*</span></label>
                <div class="photo-upload-container id-photo">
                    <div class="photo-preview" id="idPreview" onclick="document.getElementById('id_photo_input').click()">
                        <div class="placeholder">
                            <i class="fas fa-id-card"></i>
                            <span>Upload ID</span>
                        </div>
                        <div class="photo-overlay">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    <div class="photo-actions">
                        <button type="button" class="btn-photo btn-upload" onclick="document.getElementById('id_photo_input').click()">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <button type="button" class="btn-photo btn-camera" onclick="openCamera('id')">
                            <i class="fas fa-camera"></i> Camera
                        </button>
                        <button type="button" class="btn-photo btn-remove" id="idRemoveBtn" onclick="removePhoto('id')" disabled>
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                    <input type="file" id="id_photo_input" name="id_photo" class="hidden-file-input" accept="image/*" onchange="previewPhoto(this, 'id')">
                    <input type="hidden" id="id_photo_base64" name="id_photo_base64">
                </div>
            </div>
        </div>
</div>

<div class="wizard-panel" data-step="2">
        <!-- EMERGENCY CONTACT -->
        <h4>
            <span class="section-icon"><i class="fas fa-phone-alt"></i></span>
            Emergency Contact
        </h4>
        <div class="section-divider"></div>

        <div class="form-row-2">
            <div class="form-group">
                <label>Emergency Contact Name</label>
                <input type="text" name="emergency_name" class="form-control" value="<?php echo value('emergency_name'); ?>" placeholder="Person to contact in emergency">
            </div>
            
            <div class="form-group">
                <label>Emergency Contact Number</label>
                <div class="phone-input-group">
                    <select name="emergency_suffix" class="phone-suffix-select" aria-label="Country code">
                        <option value="+63" <?php echo selected('emergency_suffix', '+63'); ?>>+63 🇵🇭</option>
                        <option value="+1" <?php echo selected('emergency_suffix', '+1'); ?>>+1 🇺🇸</option>
                        <option value="+44" <?php echo selected('emergency_suffix', '+44'); ?>>+44 🇬🇧</option>
                        <option value="+61" <?php echo selected('emergency_suffix', '+61'); ?>>+61 🇦🇺</option>
                        <option value="+81" <?php echo selected('emergency_suffix', '+81'); ?>>+81 🇯🇵</option>
                        <option value="+82" <?php echo selected('emergency_suffix', '+82'); ?>>+82 🇰🇷</option>
                        <option value="+86" <?php echo selected('emergency_suffix', '+86'); ?>>+86 🇨🇳</option>
                        <option value="+65" <?php echo selected('emergency_suffix', '+65'); ?>>+65 🇸🇬</option>
                        <option value="+60" <?php echo selected('emergency_suffix', '+60'); ?>>+60 🇲🇾</option>
                        <option value="+62" <?php echo selected('emergency_suffix', '+62'); ?>>+62 🇮🇩</option>
                        <option value="+66" <?php echo selected('emergency_suffix', '+66'); ?>>+66 🇹🇭</option>
                        <option value="+84" <?php echo selected('emergency_suffix', '+84'); ?>>+84 🇻🇳</option>
                        <option value="+91" <?php echo selected('emergency_suffix', '+91'); ?>>+91 🇮🇳</option>
                        <option value="+971" <?php echo selected('emergency_suffix', '+971'); ?>>+971 🇦🇪</option>
                        <option value="+966" <?php echo selected('emergency_suffix', '+966'); ?>>+966 🇸🇦</option>
                    </select>
                    <div class="phone-input-wrapper">
                        <i class="fas fa-phone-alt phone-icon"></i>
                        <input type="tel" 
                               name="emergency_number" 
                               id="emergency_input"
                               class="form-control <?php echo hasError('emergency_number'); ?>" 
                               value="<?php echo value('emergency_number'); ?>" 
                               placeholder="9123456789" 
                              maxlength="10"
                               inputmode="numeric"
                               autocomplete="tel">
                        <span class="digit-count" id="emergencyDigitCount">0/10</span>
                    </div>
                </div>
                <?php if(hasError('emergency_number')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['emergency_number']; ?></div>
                <?php endif; ?>
                <div class="field-info">
                    <i class="fas fa-info-circle"></i> Optional — select country code then enter number
                </div>
            </div>
        </div>

        <!-- HEALTH INFORMATION -->
        <div class="health-section">
            <h4>
                <span class="section-icon"><i class="fas fa-heartbeat"></i></span>
                Health Information <span style="font-size: 13px; color: #94a3b8; font-weight: 400;">(Optional)</span>
            </h4>

            <div class="checkbox-group">
                <input type="checkbox" name="has_asthma" id="has_asthma" value="1" <?php echo isChecked('has_asthma'); ?>>
                <label for="has_asthma">I have Asthma</label>
            </div>
            <div id="asthma_section" class="sub-section <?php echo $form_data['has_asthma'] ? 'visible' : ''; ?>">
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
                    <input type="text" name="asthma_triggers" class="form-control" value="<?php echo value('asthma_triggers'); ?>" placeholder="e.g., dust, pollen, exercise">
                </div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="has_allergies" id="has_allergies" value="1" <?php echo isChecked('has_allergies'); ?>>
                <label for="has_allergies">I have Allergies</label>
            </div>
            <div id="allergies_section" class="sub-section <?php echo $form_data['has_allergies'] ? 'visible' : ''; ?>">
                <div class="form-group">
                    <label>Allergy Details</label>
                    <input type="text" name="allergies_details" class="form-control" value="<?php echo value('allergies_details'); ?>" placeholder="e.g., peanuts, seafood, dust">
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="has_epipen" id="has_epipen" value="yes" <?php echo isChecked('has_epipen'); ?>>
                    <label for="has_epipen">I carry an EpiPen</label>
                </div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="has_medical" id="has_medical" value="1" <?php echo isChecked('has_medical'); ?>>
                <label for="has_medical">I have other medical conditions</label>
            </div>
            <div id="medical_section" class="sub-section <?php echo $form_data['has_medical'] ? 'visible' : ''; ?>">
                <div class="form-group">
                    <label>Medical Conditions</label>
                    <input type="text" name="medical_conditions" class="form-control" value="<?php echo value('medical_conditions'); ?>" placeholder="e.g., diabetes, high blood">
                </div>
                <div class="form-group">
                    <label>Current Medications</label>
                    <input type="text" name="medications" class="form-control" value="<?php echo value('medications'); ?>" placeholder="e.g., insulin, amlodipine">
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

            <div class="checkbox-group">
                <input type="checkbox" name="has_dietary" id="has_dietary" value="1" <?php echo isChecked('has_dietary'); ?>>
                <label for="has_dietary">I have dietary restrictions</label>
            </div>
            <div id="dietary_section" class="sub-section <?php echo $form_data['has_dietary'] ? 'visible' : ''; ?>">
                <div class="form-group">
                    <label>Other dietary restrictions</label>
                    <input type="text" name="dietary_other" class="form-control" value="<?php echo value('dietary_other'); ?>" placeholder="Please specify">
                </div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="has_accessibility" id="has_accessibility" value="1" <?php echo isChecked('has_accessibility'); ?>>
                <label for="has_accessibility">I have accessibility needs</label>
            </div>
            <div id="accessibility_section" class="sub-section <?php echo $form_data['has_accessibility'] ? 'visible' : ''; ?>">
                <div class="form-group">
                    <label>Other accessibility needs</label>
                    <input type="text" name="accessibility_other" class="form-control" value="<?php echo value('accessibility_other'); ?>" placeholder="Please specify">
                </div>
            </div>

            <div class="form-group">
                <label>Emergency Medication (if any)</label>
                <input type="text" name="emergency_medication" class="form-control" value="<?php echo value('emergency_medication'); ?>" placeholder="e.g., EpiPen, inhaler, insulin">
            </div>
        </div>

        </div>

<div class="wizard-panel" data-step="3">
        <!-- ACCOUNT INFORMATION -->
        <h4>
            <span class="section-icon"><i class="fas fa-lock"></i></span>
            Account Information
        </h4>
        <div class="section-divider"></div>

        <div class="form-row-3">
            <div class="form-group">
                <label>Username <span class="required-field">*</span></label>
                <input type="text" name="username" class="form-control <?php echo hasError('username'); ?>" value="<?php echo value('username'); ?>" placeholder="Choose a username" required>
                <?php if(hasError('username')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['username']; ?></div>
                <?php endif; ?>
                <div class="field-info">Min 4 chars (letters, numbers, _)</div>
            </div>

            <div class="form-group">
                <label>Password <span class="required-field">*</span></label>
                <div class="password-wrapper">
                    <input type="password" name="password" class="form-control <?php echo hasError('password'); ?>" id="password" placeholder="Enter password" required>
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password visibility">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <?php if(hasError('password')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['password']; ?></div>
                <?php endif; ?>

                <div class="password-requirements" id="passwordRequirements" style="display:none;">
                    <span class="req" id="reqLength"><span class="pending"><i class="fas fa-circle"></i></span> 8 characters</span>
                    <span class="req" id="reqUppercase"><span class="pending"><i class="fas fa-circle"></i></span> Uppercase</span>
                    <span class="req" id="reqNumber"><span class="pending"><i class="fas fa-circle"></i></span> Number</span>
                </div>

                <div class="field-info">
                    <i class="fas fa-info-circle"></i>
                    Must be at least 8 characters, contain 1 uppercase letter and 1 number
                </div>
            </div>

            <div class="form-group">
                <label>Confirm Password <span class="required-field">*</span></label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" class="form-control <?php echo hasError('confirm_password'); ?>" id="confirm_password" placeholder="Confirm password" required>
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility('confirm_password', this)" aria-label="Toggle password visibility">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <?php if(hasError('confirm_password')): ?>
                    <div class="error-field-label"><i class="fas fa-exclamation-circle"></i> <?php echo $errors['confirm_password']; ?></div>
                <?php endif; ?>
                <div id="confirmPasswordFeedback" class="confirm-feedback"></div>
            </div>
        </div>

        </div>

<div class="wizard-buttons">

    <button type="button" class="btn-prev" id="prevBtn">
        Previous
    </button>

    <button type="button" class="btn-next" id="nextBtn">
        Next
    </button>

</div>

<button type="submit" name="register" class="btn-register" id="submitBtn">            <i class="fas fa-user-plus"></i> Create Account
        </button>

        <p class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </p>
    </form>

    </div><!-- /register-body -->
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
let currentCameraTarget = 'profile';
let cameraStream = null;

function toggleNameSuffixCustom() {
    const select = document.getElementById('name_suffix');
    const wrapper = document.getElementById('nameSuffixCustomWrapper');
    const customInput = document.getElementById('name_suffix_custom');
    if (!select || !wrapper) return;
    if (select.value === '__other__') {
        wrapper.classList.add('visible');
        if (customInput) customInput.focus();
    } else {
        wrapper.classList.remove('visible');
        if (customInput) customInput.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    toggleNameSuffixCustom();
});

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

function setupPhoneInput(inputId, counterId) {

    const input = document.getElementById(inputId);
    const counter = document.getElementById(counterId);

    if (!input || !counter) return;


    function update() {

        let cleaned = input.value.replace(/[^0-9]/g,'');


        // PH mobile rule
        if(cleaned.length > 0 && cleaned[0] !== '9') {

            cleaned = cleaned.substring(1);
        }


        if(cleaned.length > 10){

            cleaned = cleaned.slice(0,10);
        }


        if(input.value !== cleaned){

            input.value = cleaned;
        }


        counter.textContent = cleaned.length + '/10';


        if(cleaned.length === 10){

            counter.classList.add('complete');

        } else {

            counter.classList.remove('complete');

        }

    }


    input.addEventListener('input', update);


    input.addEventListener('keypress', function(e){

        const char = String.fromCharCode(e.which);


        if(!/[0-9]/.test(char)){

            e.preventDefault();
            return;
        }


        // first digit must be 9
        if(this.value.length === 0 && char !== '9'){

            e.preventDefault();

        }


        if(this.value.length >= 10){

            e.preventDefault();

        }

    });


    update();

}

// ============================================================
// ✅ NAME FIELD RESTRICTION — Letters only (no numbers, no special chars)
// Allow: a-z, A-Z, ñ, Ñ, accented letters, space, hyphen, apostrophe, period
// ============================================================
function setupNameInputs() {
    const nameInputs = document.querySelectorAll('input[data-name-field="1"]');

    nameInputs.forEach(function(input) {
        // ✅ LIVE SANITIZATION — Remove invalid characters as user types
        input.addEventListener('input', function() {
            const originalValue = this.value;
            // Remove numbers and invalid special chars
            let cleaned = this.value.replace(/[^a-zA-ZÀ-ÿñÑ\s\-'.]/g, '');
            // Collapse multiple spaces
            cleaned = cleaned.replace(/\s+/g, ' ');

            if (originalValue !== cleaned) {
                const cursorPos = this.selectionStart;
                const removed = originalValue.length - cleaned.length;
                this.value = cleaned;
                try {
                    this.setSelectionRange(
                        Math.max(0, cursorPos - removed),
                        Math.max(0, cursorPos - removed)
                    );
                } catch (e) {}
            }
        });

        // ✅ BLOCK INVALID KEYS on keypress
        input.addEventListener('keypress', function(e) {
            // Allow control keys
            if (e.which === 8 || e.which === 0 || e.which === 13) return;
            if (e.ctrlKey || e.metaKey) return; // Allow Ctrl+C, Ctrl+V, etc.

            const char = String.fromCharCode(e.which);
            // Allow letters, spaces, hyphen, apostrophe, period
            const validPattern = /^[a-zA-ZÀ-ÿñÑ\s\-'.]$/;
            if (!validPattern.test(char)) {
                e.preventDefault();
            }
        });

        // ✅ SANITIZE PASTED CONTENT
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const cleaned = pastedText.replace(/[^a-zA-ZÀ-ÿñÑ\s\-'.]/g, '').replace(/\s+/g, ' ');
            const start = this.selectionStart;
            const end = this.selectionEnd;
            const currentValue = this.value;
            const newValue = (currentValue.slice(0, start) + cleaned + currentValue.slice(end)).slice(0, 50);
            this.value = newValue;
            const newPos = start + cleaned.length;
            try {
                this.setSelectionRange(newPos, newPos);
            } catch (err) {}
            this.dispatchEvent(new Event('input'));
        });

        // ✅ PREVENT DROP of invalid content
        input.addEventListener('drop', function(e) {
            e.preventDefault();
            const droppedText = e.dataTransfer.getData('text');
            const cleaned = droppedText.replace(/[^a-zA-ZÀ-ÿñÑ\s\-'.]/g, '').replace(/\s+/g, ' ');
            const start = this.selectionStart;
            const end = this.selectionEnd;
            const currentValue = this.value;
            const newValue = (currentValue.slice(0, start) + cleaned + currentValue.slice(end)).slice(0, 50);
            this.value = newValue;
            const newPos = start + cleaned.length;
            try {
                this.setSelectionRange(newPos, newPos);
            } catch (err) {}
            this.dispatchEvent(new Event('input'));
        });
    });
}

// ============================================================
// ID NUMBER LIMIT — 5 to 20 chars, letters/numbers/hyphen only
// ============================================================
function setupIdNumberInput() {
    const input = document.getElementById('id_number_input');
    const counter = document.getElementById('idCharCount');
    if (!input || !counter) return;

    const MIN_LEN = 5;
    const MAX_LEN = 20;

    function updateCounter() {
        const len = input.value.length;
        counter.textContent = len + '/' + MAX_LEN;

        counter.classList.remove('valid', 'warning', 'danger');

        if (len === 0) {
            counter.classList.add('danger');
        } else if (len < MIN_LEN) {
            counter.classList.add('warning');
        } else if (len <= MAX_LEN) {
            counter.classList.add('valid');
        } else {
            counter.classList.add('danger');
        }
    }

    input.addEventListener('input', function() {
        let cleaned = this.value.replace(/[^A-Za-z0-9\-]/g, '');
        if (cleaned.length > MAX_LEN) cleaned = cleaned.slice(0, MAX_LEN);

        if (this.value !== cleaned) {
            const cursorPos = this.selectionStart;
            const removed = this.value.length - cleaned.length;
            this.value = cleaned;
            try {
                this.setSelectionRange(
                    Math.max(0, cursorPos - removed),
                    Math.max(0, cursorPos - removed)
                );
            } catch (e) {}
        }

        updateCounter();
    });

    input.addEventListener('paste', function() {
        setTimeout(() => {
            let cleaned = this.value.replace(/[^A-Za-z0-9\-]/g, '').slice(0, MAX_LEN);
            if (this.value !== cleaned) this.value = cleaned;
            updateCounter();
        }, 0);
    });

    input.addEventListener('keypress', function(e) {
        const char = String.fromCharCode(e.which);
        if (e.which === 8 || e.which === 0 || e.which === 13) return;
        if (!/[A-Za-z0-9\-]/.test(char)) {
            e.preventDefault();
            return;
        }
        if (this.value.length >= MAX_LEN && this.selectionStart === this.selectionEnd) {
            e.preventDefault();
        }
    });

    input.addEventListener('drop', function(e) {
        e.preventDefault();
    });

    updateCounter();
}

document.addEventListener('DOMContentLoaded', function() {
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
            if(checkbox.checked) section.classList.add('visible');
            checkbox.addEventListener('change', function() {
                section.classList.toggle('visible', this.checked);
            });
        }
    });
    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            updatePasswordStrength(this.value);
        });
    }
    setupPhoneInput('contact_input', 'contactDigitCount');
    setupPhoneInput('emergency_input', 'emergencyDigitCount');
    setupIdNumberInput();
    setupNameInputs(); // ✅ Set up name field restrictions
});

function updatePasswordStrength(password) {
    const requirementsDiv = document.getElementById('passwordRequirements');
    const reqLength = document.getElementById('reqLength');
    const reqUppercase = document.getElementById('reqUppercase');
    const reqNumber = document.getElementById('reqNumber');
    if (password.length === 0) {
        requirementsDiv.style.display = 'none';
        return;
    }
    requirementsDiv.style.display = 'flex';
    if (password.length >= 8) {
        reqLength.className = 'req check';
        reqLength.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> 8 characters';
    } else {
        reqLength.className = 'req cross';
        reqLength.innerHTML = '<span class="cross"><i class="fas fa-times-circle"></i></span> 8 characters';
    }
    if (/[A-Z]/.test(password)) {
        reqUppercase.className = 'req check';
        reqUppercase.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> Uppercase';
    } else {
        reqUppercase.className = 'req cross';
        reqUppercase.innerHTML = '<span class="cross"><i class="fas fa-times-circle"></i></span> Uppercase';
    }
    if (/[0-9]/.test(password)) {
        reqNumber.className = 'req check';
        reqNumber.innerHTML = '<span class="check"><i class="fas fa-check-circle"></i></span> Number';
    } else {
        reqNumber.className = 'req cross';
        reqNumber.innerHTML = '<span class="cross"><i class="fas fa-times-circle"></i></span> Number';
    }
}

function previewPhoto(input, type) {
    const preview = document.getElementById(type + 'Preview');
    const removeBtn = document.getElementById(type + 'RemoveBtn');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" alt="${type} photo">
                <div class="photo-overlay"><i class="fas fa-camera"></i></div>`;
            preview.classList.add('has-image');
            removeBtn.disabled = false;
            document.getElementById(type + '_photo_base64').value = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removePhoto(type) {
    const preview = document.getElementById(type + 'Preview');
    const input = document.getElementById(type + '_photo_input');
    const hiddenInput = document.getElementById(type + '_photo_base64');
    const removeBtn = document.getElementById(type + 'RemoveBtn');
    const icon = type === 'profile' ? 'user' : 'id-card';
    const label = type === 'profile' ? 'Upload Photo' : 'Upload ID';
    preview.innerHTML = `
        <div class="placeholder">
            <i class="fas fa-${icon}"></i>
            <span>${label}</span>
        </div>
        <div class="photo-overlay"><i class="fas fa-camera"></i></div>
    `;
    preview.classList.remove('has-image');
    input.value = '';
    hiddenInput.value = '';
    removeBtn.disabled = true;
}

function openCamera(type) {
    currentCameraTarget = type;
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
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
    const preview = document.getElementById(currentCameraTarget + 'Preview');
    const removeBtn = document.getElementById(currentCameraTarget + 'RemoveBtn');
    preview.innerHTML = `<img src="${dataUrl}" alt="${currentCameraTarget} photo">
        <div class="photo-overlay"><i class="fas fa-camera"></i></div>`;
    preview.classList.add('has-image');
    removeBtn.disabled = false;
    document.getElementById(currentCameraTarget + '_photo_base64').value = dataUrl;
    document.getElementById(currentCameraTarget + '_photo_input').value = '';
    closeCamera();
}

const passwordInput = document.getElementById('password');
const confirmInput = document.getElementById('confirm_password');
const confirmFeedback = document.getElementById('confirmPasswordFeedback');

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

if (passwordInput) {
    passwordInput.addEventListener('input', function() {
        updatePasswordStrength(this.value);
        checkPasswordMatch();
    });
}
if (confirmInput) {
    confirmInput.addEventListener('input', checkPasswordMatch);
}

// Username sanitizer (letters, numbers, underscore only)
document.querySelector('input[name="username"]')?.addEventListener('input', function() {
    this.value = this.value.replace(/[^a-zA-Z0-9_]/g, '');
});

// Custom suffix sanitizer (letters, dots, spaces only)
document.getElementById('name_suffix_custom')?.addEventListener('input', function() {
    this.value = this.value.replace(/[^A-Za-z.\s]/g, '');
});

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);

/* =========================
   REGISTER WIZARD LOGIC
   ========================= */

let currentStep = 1;

const panels = document.querySelectorAll('.wizard-panel');
const indicators = document.querySelectorAll('.wizard-step-indicator');

const nextBtn = document.getElementById('nextBtn');
const prevBtn = document.getElementById('prevBtn');
const submitBtn = document.getElementById('submitBtn');


function showStep(step){

    panels.forEach(panel=>{
        panel.classList.remove('active');

        if(panel.dataset.step == step){
            panel.classList.add('active');
        }
    });


    indicators.forEach(ind=>{
        ind.classList.remove('active');

        if(ind.dataset.stepIndicator == step){
            ind.classList.add('active');
        }
    });


    prevBtn.style.display = step === 1 ? 'none':'block';

    nextBtn.style.display = step === 3 ? 'none':'block';

    submitBtn.style.display = step === 3 ? 'flex':'none';
}


function validateStep(step){

    let panel=document.querySelector(
        `.wizard-panel[data-step="${step}"]`
    );

    let required=panel.querySelectorAll(
        'input[required], select[required]'
    );


    for(let field of required){

        if(!field.value.trim()){

            field.focus();

            alert(
              "Please complete all required fields before continuing."
            );

            return false;
        }
    }


    return true;
}


nextBtn.addEventListener('click',()=>{

    if(!validateStep(currentStep)){
        return;
    }


    if(currentStep < 3){

        currentStep++;

        showStep(currentStep);
    }

});


prevBtn.addEventListener('click',()=>{

    if(currentStep > 1){

        currentStep--;

        showStep(currentStep);
    }

});


showStep(currentStep);
</script>


</body>
</html>