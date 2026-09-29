<?php
session_start();
require_once 'database.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if(!isset($_GET['user_id'])) {
    echo json_encode(['error' => 'User ID required']);
    exit();
}

$user_id = (int)$_GET['user_id'];

// ============================================================
// ✅ FIX: Explicit SELECT — do NOT use `u.*, g.*`
// Reason: Both tables have `id`, `created_at`, `email`, etc.
// When `g.*` comes last, it OVERWRITES `u.id` → breaks edit form
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        u.id          AS user_id,
        u.username,
        u.email,
        u.role,
        u.created_at,
        u.fullname    AS user_fullname,

        g.id          AS guest_id,
        g.full_name   AS guest_full_name,
        g.contact_number,
        g.address,
        g.id_type,
        g.id_number,
        g.emergency_contact,
        g.emergency_number,
        g.profile_photo,
        g.id_photo,

        g.has_asthma,
        g.asthma_severity,
        g.has_inhaler,
        g.asthma_triggers,
        g.last_attack,

        g.has_allergies,
        g.allergy_types,
        g.allergies_details,
        g.allergy_severity,
        g.has_epipen,

        g.has_medical_condition,
        g.medical_conditions,
        g.medications,
        g.blood_type,

        g.has_dietary,
        g.dietary_types,
        g.dietary_other,

        g.has_accessibility,
        g.access_needs,
        g.accessibility_other,

        g.emergency_medication
    FROM users u
    LEFT JOIN guests g ON u.id = g.user_id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$user) {
    echo json_encode(['error' => 'User not found']);
    exit();
}

// Helper function to decode JSON
function decodeJson($value) {
    if (!$value) return [];
    $decoded = json_decode($value, true);
    return $decoded ?: [];
}

// Get profile photo path
$profile_photo = null;
if (!empty($user['profile_photo'])) {
    $paths = [
        "uploads/profile/user_" . $user_id . "/" . $user['profile_photo'],
        "uploads/user_" . $user_id . "/" . $user['profile_photo'],
        "uploads/profile/" . $user['profile_photo']
    ];
    foreach($paths as $path) {
        if(file_exists($path)) {
            $profile_photo = $path;
            break;
        }
    }
}

// Get ID photo path
$id_photo = null;
if (!empty($user['id_photo'])) {
    $paths = [
        "uploads/profile/user_" . $user_id . "/" . $user['id_photo'],
        "uploads/user_" . $user_id . "/" . $user['id_photo']
    ];
    foreach($paths as $path) {
        if(file_exists($path)) {
            $id_photo = $path;
            break;
        }
    }
}

// Decode JSON fields
$allergy_types = decodeJson($user['allergy_types'] ?? null);
$dietary_types = decodeJson($user['dietary_types'] ?? null);
$access_needs  = decodeJson($user['access_needs'] ?? null);

// ============================================================
// ✅ FIX: 'user_id' MUST come from `u.id` — never from `g.id`
// ============================================================
$response = [
    // Basic Info
    'user_id'        => (int)$user['user_id'],
    'username'       => $user['username'],
    'email'          => $user['email'],
    'role'           => $user['role'],
    'created_at'     => $user['created_at'],
    'full_name'      => $user['guest_full_name'] ?? $user['user_fullname'] ?? $user['username'],
    'contact_number' => $user['contact_number'] ?? null,
    'address'        => $user['address'] ?? null,

    // ID Info
    'id_type'   => $user['id_type'] ?? null,
    'id_number' => $user['id_number'] ?? null,

    // Emergency Contact
    'emergency_contact' => $user['emergency_contact'] ?? null,
    'emergency_number'  => $user['emergency_number'] ?? null,

    // Photos
    'profile_photo' => $profile_photo,
    'id_photo'      => $id_photo,

    // ============================================
    // ASTHMA INFO
    // ============================================
    'has_asthma'      => (bool)($user['has_asthma'] ?? false),
    'asthma_severity' => $user['asthma_severity'] ?? null,
    'has_inhaler'     => $user['has_inhaler'] ?? null,
    'asthma_triggers' => $user['asthma_triggers'] ?? null,
    'last_attack'     => $user['last_attack'] ?? null,

    // ============================================
    // ALLERGIES INFO
    // ============================================
    'has_allergies'     => (bool)($user['has_allergies'] ?? false),
    'allergy_types'     => $allergy_types,
    'allergies_details' => $user['allergies_details'] ?? null,
    'allergy_severity'  => $user['allergy_severity'] ?? null,
    'has_epipen'        => $user['has_epipen'] ?? null,

    // ============================================
    // MEDICAL CONDITIONS
    // ============================================
    'has_medical_condition' => (bool)($user['has_medical_condition'] ?? false),
    'medical_conditions'    => $user['medical_conditions'] ?? null,
    'medications'           => $user['medications'] ?? null,
    'blood_type'            => $user['blood_type'] ?? null,

    // ============================================
    // DIETARY RESTRICTIONS
    // ============================================
    'has_dietary'    => (bool)($user['has_dietary'] ?? false),
    'dietary_types'  => $dietary_types,
    'dietary_other'  => $user['dietary_other'] ?? null,

    // ============================================
    // ACCESSIBILITY NEEDS
    // ============================================
    'has_accessibility'    => (bool)($user['has_accessibility'] ?? false),
    'access_needs'         => $access_needs,
    'accessibility_other'  => $user['accessibility_other'] ?? null,

    // ============================================
    // EMERGENCY MEDICATION
    // ============================================
    'emergency_medication' => $user['emergency_medication'] ?? null
];

echo json_encode($response);
exit;