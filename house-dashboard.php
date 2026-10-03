<?php
session_start();

if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: index.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

require_once 'database.php';

// ============================================================
// ✅ NEW: Load SystemLogger
// ============================================================
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) {
    $user_info = [];
}

// ============================================================
// ✅ NEW: Resolve avatar (photo or initial)
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
// AJAX HANDLERS
// ============================================================

if (isset($_GET['ajax_get_gallery'])) {
    header('Content-Type: application/json');
    try {
        $house_id = (int)$_GET['house_id'];
        $stmt = $pdo->prepare("SELECT * FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC, id ASC");
        $stmt->execute([$house_id]);
        $images = $stmt->fetchAll();
        
        $stmt2 = $pdo->prepare("SELECT image FROM houses WHERE id = ?");
        $stmt2->execute([$house_id]);
        $main = $stmt2->fetchColumn();
        
        echo json_encode(['success' => true, 'images' => $images, 'main_image' => $main]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

if (isset($_POST['ajax_upload_multiple'])) {
    header('Content-Type: application/json');
    try {
        if (!$is_admin && !$is_staff) throw new Exception("Unauthorized");
        
        if (!file_exists('uploads/houses/gallery/')) {
            mkdir('uploads/houses/gallery/', 0755, true);
        }
        
        $house_id = (int)$_POST['house_id'];
        if ($house_id <= 0) throw new Exception("Invalid house ID");
        
        $chk = $pdo->prepare("SELECT id FROM houses WHERE id = ?");
        $chk->execute([$house_id]);
        if (!$chk->fetch()) throw new Exception("House not found");
        
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $uploaded = [];
        $failed = [];
        
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM house_gallery WHERE house_id = ?");
        $countStmt->execute([$house_id]);
        $existing_count = (int)$countStmt->fetchColumn();
        
        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM house_gallery WHERE house_id = ?");
        $sortStmt->execute([$house_id]);
        $max_sort = (int)$sortStmt->fetchColumn();
        
        $sort_counter = $max_sort + 1;
        $is_first_ever = ($existing_count === 0);
        
        if (isset($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['name'])) {
            $files = $_FILES['gallery_images'];
            $total = count($files['name']);
            
            for ($i = 0; $i < $total; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) { $failed[] = $files['name'][$i]; continue; }
                
                $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) { $failed[] = $files['name'][$i]; continue; }
                if ($files['size'][$i] > 10 * 1024 * 1024) { $failed[] = $files['name'][$i] . ' (too large)'; continue; }
                
                $safe_orig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $files['name'][$i]);
                $new_filename = time() . '_' . $i . '_' . $house_id . '_' . $safe_orig;
                
                if (move_uploaded_file($files['tmp_name'][$i], "uploads/houses/gallery/" . $new_filename)) {
                    $is_main = ($is_first_ever && $i === 0) ? 1 : 0;
                    
                    if ($is_main) {
                        $pdo->prepare("UPDATE house_gallery SET is_main = 0 WHERE house_id = ?")->execute([$house_id]);
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO house_gallery (house_id, image, is_main, sort_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$house_id, $new_filename, $is_main, $sort_counter]);
                    
                    if ($is_main) {
                        $pdo->prepare("UPDATE houses SET image = ? WHERE id = ?")->execute([$new_filename, $house_id]);
                    }
                    
                    $uploaded[] = ['image' => $new_filename, 'is_main' => $is_main];
                    $sort_counter++;
                } else {
                    $failed[] = $files['name'][$i];
                }
            }
        }
        
        $stmt = $pdo->prepare("SELECT * FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC, id ASC");
        $stmt->execute([$house_id]);
        $gallery = $stmt->fetchAll();
        
        if (!empty($uploaded) && class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'upload', 'house',
                "Uploaded " . count($uploaded) . " gallery image(s) for house ID {$house_id}",
                $house_id, 'house');
        }
        
        echo json_encode([
            'success' => true,
            'message' => count($uploaded) . ' image(s) uploaded successfully!' . (count($failed) > 0 ? ' ' . count($failed) . ' failed.' : ''),
            'uploaded_count' => count($uploaded),
            'failed_count' => count($failed),
            'failed_files' => $failed,
            'gallery' => $gallery,
            'gallery_count' => count($gallery),
            't' => time()
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

if (isset($_POST['ajax_delete_gallery'])) {
    header('Content-Type: application/json');
    try {
        if (!$is_admin && !$is_staff) throw new Exception("Unauthorized");
        
        $img_id = (int)$_POST['img_id'];
        
        $stmt = $pdo->prepare("SELECT house_id, image, is_main FROM house_gallery WHERE id = ?");
        $stmt->execute([$img_id]);
        $img = $stmt->fetch();
        
        if (!$img) throw new Exception("Image not found");
        
        $filename = $img['image'];
        if (!empty($filename) && $filename !== 'default-house.jpg') {
            $paths = ["uploads/houses/gallery/" . $filename, "uploads/houses/" . $filename];
            foreach ($paths as $path) { if (is_file($path)) @unlink($path); }
        }
        
        $pdo->prepare("DELETE FROM house_gallery WHERE id = ?")->execute([$img_id]);
        
        $was_main = $img['is_main'] == 1;
        $new_main = null;
        
        if ($was_main) {
            $check = $pdo->prepare("SELECT id, image FROM house_gallery WHERE house_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
            $check->execute([$img['house_id']]);
            $next = $check->fetch();
            
            if ($next) {
                $pdo->prepare("UPDATE house_gallery SET is_main = 1 WHERE id = ?")->execute([$next['id']]);
                $pdo->prepare("UPDATE houses SET image = ? WHERE id = ?")->execute([$next['image'], $img['house_id']]);
                $new_main = $next['image'];
            } else {
                $pdo->prepare("UPDATE houses SET image = 'default-house.jpg' WHERE id = ?")->execute([$img['house_id']]);
                $new_main = 'default-house.jpg';
            }
        }
        
        $stmt = $pdo->prepare("SELECT * FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC, id ASC");
        $stmt->execute([$img['house_id']]);
        $updated_gallery = $stmt->fetchAll();
        
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'delete', 'house',
                "Deleted gallery image for house ID {$img['house_id']}",
                $img['house_id'], 'house', null, null, 'warning');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Image permanently deleted!',
            'gallery' => $updated_gallery,
            'new_main' => $new_main,
            'gallery_count' => count($updated_gallery),
            't' => time()
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

if (isset($_POST['ajax_set_main'])) {
    header('Content-Type: application/json');
    try {
        if (!$is_admin && !$is_staff) throw new Exception("Unauthorized");
        
        $img_id = (int)$_POST['img_id'];
        
        $stmt = $pdo->prepare("SELECT house_id, image FROM house_gallery WHERE id = ?");
        $stmt->execute([$img_id]);
        $img = $stmt->fetch();
        
        if (!$img) throw new Exception("Image not found");
        
        $pdo->prepare("UPDATE house_gallery SET is_main = 0 WHERE house_id = ?")->execute([$img['house_id']]);
        $pdo->prepare("UPDATE house_gallery SET is_main = 1 WHERE id = ?")->execute([$img_id]);
        $pdo->prepare("UPDATE houses SET image = ? WHERE id = ?")->execute([$img['image'], $img['house_id']]);
        
        $stmt = $pdo->prepare("SELECT * FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC, id ASC");
        $stmt->execute([$img['house_id']]);
        $updated_gallery = $stmt->fetchAll();
        
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'update', 'house',
                "Set main image for house ID {$img['house_id']}",
                $img['house_id'], 'house');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Set as main image!',
            'gallery' => $updated_gallery,
            'new_main' => $img['image'],
            't' => time()
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================================
// HELPERS
// ============================================================
if (!function_exists('deleteHouseImageFile')) {
    function deleteHouseImageFile($filename) {
        if (empty($filename) || $filename === 'default-house.jpg') return;
        $paths = ["uploads/houses/" . $filename, "uploads/houses/gallery/" . $filename];
        foreach ($paths as $path) { if (is_file($path)) @unlink($path); }
    }
}

if (!function_exists('resolveHouseMainImage')) {
    function resolveHouseMainImage($house, $gallery) {
        if (!empty($house['image']) && $house['image'] !== 'default-house.jpg') {
            $main_path = 'uploads/houses/' . $house['image'];
            $gal_path  = 'uploads/houses/gallery/' . $house['image'];
            if (file_exists($main_path)) return $main_path;
            if (file_exists($gal_path))  return $gal_path;
        }
        foreach ($gallery as $img) {
            if (!empty($img['is_main'])) {
                $path = 'uploads/houses/gallery/' . $img['image'];
                if (file_exists($path)) return $path;
            }
        }
        if (!empty($gallery)) {
            $path = 'uploads/houses/gallery/' . $gallery[0]['image'];
            if (file_exists($path)) return $path;
        }
        return null;
    }
}

// HANDLE ADD HOUSE
if(isset($_POST['add_house']) && ($is_admin || $is_staff)) {
    try {
        if (!file_exists('uploads/houses/')) mkdir('uploads/houses/', 0755, true);
        if (!file_exists('uploads/houses/gallery/')) mkdir('uploads/houses/gallery/', 0755, true);
        
        $house_image = 'default-house.jpg';
        $main_uploaded = false;
        
        if(isset($_FILES['house_image']) && $_FILES['house_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES["house_image"]["name"], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($ext, $allowed)) {
                $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $_POST['house_name']);
                $new_filename = time() . '_' . $safe_name . '.' . $ext;
                
                if(move_uploaded_file($_FILES["house_image"]["tmp_name"], "uploads/houses/" . $new_filename)) {
                    $house_image = $new_filename;
                    $main_uploaded = true;
                }
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO houses (house_name, description, price_per_night, capacity, bedrooms, amenities, status, image) VALUES (?, ?, ?, ?, ?, ?, 'available', ?)");
        $stmt->execute([
            $_POST['house_name'], $_POST['description'], $_POST['price_per_night'],
            $_POST['capacity'], $_POST['bedrooms'], $_POST['amenities'], $house_image
        ]);
        
        $house_id = $pdo->lastInsertId();
        
        if ($main_uploaded) {
            $stmt = $pdo->prepare("INSERT INTO house_gallery (house_id, image, is_main, sort_order) VALUES (?, ?, 1, 0)");
            $stmt->execute([$house_id, $house_image]);
        }
        
        $gallery_count = 0;
        if(isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
            $files = $_FILES['gallery_images'];
            $is_first = $main_uploaded ? false : true;
            $sort_start = $main_uploaded ? 1 : 0;
            
            if(is_array($files['name'])) {
                for($i = 0; $i < count($files['name']); $i++) {
                    if($files['error'][$i] == 0) {
                        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        if (!in_array($ext, $allowed)) continue;
                        
                        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $_POST['house_name']);
                        $new_filename = time() . '_' . $i . '_' . $safe_name . '.' . $ext;
                        
                        if(move_uploaded_file($files['tmp_name'][$i], "uploads/houses/gallery/" . $new_filename)) {
                            $is_main = $is_first ? 1 : 0;
                            $stmt = $pdo->prepare("INSERT INTO house_gallery (house_id, image, is_main, sort_order) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$house_id, $new_filename, $is_main, $sort_start + $i]);
                            $is_first = false;
                            $gallery_count++;
                        }
                    }
                }
            }
        }
        
        $success = "House added successfully!";
        if ($main_uploaded) $success .= " Main image uploaded.";
        if ($gallery_count > 0) $success .= " " . $gallery_count . " gallery images uploaded.";
        
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'create', 'house',
                "Added new house: {$_POST['house_name']}",
                $house_id, 'house', null, [
                    'house_name' => $_POST['house_name'],
                    'price'      => $_POST['price_per_night'],
                    'capacity'   => $_POST['capacity'],
                    'bedrooms'   => $_POST['bedrooms'],
                    'status'     => 'available',
                    'has_image'  => $main_uploaded,
                    'gallery'    => $gallery_count
                ]);
        }
        
    } catch(Exception $e) {
        $error = "Failed to add house: " . $e->getMessage();
    }
}

// HANDLE EDIT HOUSE
if(isset($_POST['edit_house']) && ($is_admin || $is_staff)) {
    try {
        $house_id = (int)$_POST['house_id'];
        
        $stmt = $pdo->prepare("SELECT * FROM houses WHERE id = ?");
        $stmt->execute([$house_id]);
        $current = $stmt->fetch();
        
        if (!$current) throw new Exception("House not found.");
        
        $old_image = $current['image'] ?? 'default-house.jpg';
        $house_image = $old_image;
        $image_action = 'none';
        
        if (isset($_POST['remove_main_image']) && $_POST['remove_main_image'] == '1') {
            deleteHouseImageFile($old_image);
            $pdo->prepare("DELETE FROM house_gallery WHERE house_id = ? AND image = ?")->execute([$house_id, $old_image]);
            
            $next = $pdo->prepare("SELECT id, image FROM house_gallery WHERE house_id = ? ORDER BY sort_order ASC LIMIT 1");
            $next->execute([$house_id]);
            $next_img = $next->fetch();
            
            if ($next_img) {
                $house_image = $next_img['image'];
                $pdo->prepare("UPDATE house_gallery SET is_main = 1 WHERE id = ?")->execute([$next_img['id']]);
            } else {
                $house_image = 'default-house.jpg';
            }
            $image_action = 'removed';
        }
        elseif (isset($_FILES['house_image']) && $_FILES['house_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES["house_image"]["name"], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($ext, $allowed)) throw new Exception("Invalid image format.");
            
            $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $_POST['house_name']);
            $new_filename = time() . '_' . $safe_name . '.' . $ext;
            
            if (move_uploaded_file($_FILES["house_image"]["tmp_name"], "uploads/houses/" . $new_filename)) {
                if ($old_image && $old_image !== 'default-house.jpg') {
                    deleteHouseImageFile($old_image);
                }
                $house_image = $new_filename;
                $image_action = 'replaced';
            }
        }
        
        $stmt = $pdo->prepare("UPDATE houses SET house_name=?, description=?, price_per_night=?, capacity=?, bedrooms=?, amenities=?, status=?, image=? WHERE id=?");
        $stmt->execute([
            $_POST['house_name'], $_POST['description'], $_POST['price_per_night'],
            $_POST['capacity'], $_POST['bedrooms'], $_POST['amenities'],
            $_POST['status'], $house_image, $house_id
        ]);
        
        if ($image_action === 'replaced') {
            $pdo->prepare("DELETE FROM house_gallery WHERE house_id = ? AND image = ?")->execute([$house_id, $old_image]);
            $pdo->prepare("UPDATE house_gallery SET is_main = 0 WHERE house_id = ?")->execute([$house_id]);
            
            $check = $pdo->prepare("SELECT id FROM house_gallery WHERE house_id = ? AND image = ?");
            $check->execute([$house_id, $house_image]);
            $existing = $check->fetch();
            
            if ($existing) {
                $pdo->prepare("UPDATE house_gallery SET is_main = 1, sort_order = 0 WHERE id = ?")->execute([$existing['id']]);
            } else {
                $pdo->prepare("INSERT INTO house_gallery (house_id, image, is_main, sort_order) VALUES (?, ?, 1, 0)")->execute([$house_id, $house_image]);
            }
        }
        
        $success = "House updated successfully!";
        if ($image_action === 'replaced') $success .= " Main image replaced.";
        if ($image_action === 'removed') $success .= " Main image removed.";
        
        if (class_exists('SystemLogger')) {
            $changed = [];
            if ($current['house_name'] !== $_POST['house_name']) $changed[] = 'name';
            if ($current['price_per_night'] != $_POST['price_per_night']) $changed[] = 'price';
            if ($current['capacity'] != $_POST['capacity']) $changed[] = 'capacity';
            if ($current['bedrooms'] != $_POST['bedrooms']) $changed[] = 'bedrooms';
            if ($current['status'] !== $_POST['status']) $changed[] = 'status';
            if ($image_action !== 'none') $changed[] = 'image (' . $image_action . ')';

            $desc = "Updated house: {$_POST['house_name']}";
            if (!empty($changed)) $desc .= " (changed: " . implode(', ', $changed) . ")";

            SystemLogger::log($pdo, 'update', 'house',
                $desc,
                $house_id, 'house',
                ['house_name' => $current['house_name'], 'price' => $current['price_per_night'], 'status' => $current['status']],
                ['house_name' => $_POST['house_name'], 'price' => $_POST['price_per_night'], 'status' => $_POST['status']]);
        }
        
    } catch(Exception $e) {
        $error = "Failed to update house: " . $e->getMessage();
    }
}

// HANDLE DELETE HOUSE
if(isset($_GET['delete_house']) && ($is_admin || $is_staff)) {
    try {
        $house_id = (int)$_GET['delete_house'];
        
        $stmt = $pdo->prepare("SELECT house_name, image FROM houses WHERE id = ?");
        $stmt->execute([$house_id]);
        $house = $stmt->fetch();
        
        if($house && !empty($house['image'])) {
            deleteHouseImageFile($house['image']);
        }
        
        $gallery = $pdo->prepare("SELECT image FROM house_gallery WHERE house_id = ?");
        $gallery->execute([$house_id]);
        while($img = $gallery->fetch()) {
            deleteHouseImageFile($img['image']);
        }
        
        $pdo->prepare("DELETE FROM house_gallery WHERE house_id = ?")->execute([$house_id]);
        $pdo->prepare("DELETE FROM houses WHERE id = ?")->execute([$house_id]);
        
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'delete', 'house',
                "Deleted house: " . ($house['house_name'] ?? "ID {$house_id}") . " (with all gallery images)",
                $house_id, 'house',
                ['house_name' => $house['house_name'] ?? null],
                null, 'warning');
        }
        
        header("Location: house-dashboard.php?deleted=1");
        exit();
        
    } catch(Exception $e) {
        $error = "Failed to delete house: " . $e->getMessage();
    }
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

// GET DATA
$houses = [];
try {
    $stmt = $pdo->query("SELECT * FROM houses ORDER BY id DESC");
    $houses = $stmt->fetchAll();
} catch(PDOException $e) {
    $houses = [];
}

$house_gallery = [];
foreach($houses as $house) {
    $stmt = $pdo->prepare("SELECT * FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC, id ASC");
    $stmt->execute([$house['id']]);
    $house_gallery[$house['id']] = $stmt->fetchAll();
}

$total_houses = count($houses);

$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {
    $content = [];
}

// ============================================================
// ✅ ACCURATE BADGE COUNTS
// ============================================================
// ✅ Reviews: only UNREAD by admin (using session tracking)
$total_reviews = 0;
try {
    $total_reviews = (int)$pdo->query("SELECT COUNT(*) FROM overall_feedback")->fetchColumn();

    $viewed_reviews = $_SESSION['viewed_reviews_count'] ?? 0;
    $total_reviews = max(0, $total_reviews - $viewed_reviews);
} catch(PDOException $e) {}

// ✅ Bookings: only NEW bookings (pending status + not yet viewed)
$total_bookings_pending = 0;
try {
    $stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM house_bookings WHERE booking_status = 'pending') +
            (SELECT COUNT(*) FROM tour_bookings WHERE booking_status = 'pending') +
            (SELECT COUNT(*) FROM food_bookings WHERE booking_status = 'pending') AS total
    ");
    $total_bookings_pending = (int)$stmt->fetchColumn();

    $viewed_bookings = $_SESSION['viewed_bookings_count'] ?? 0;
    $total_bookings_pending = max(0, $total_bookings_pending - $viewed_bookings);
} catch(PDOException $e) {}

// ✅ System Logs: only failed logs not yet viewed
$log_stats = ['failed' => 0];
try {
    $log_stats['failed'] = (int)$pdo->query("SELECT COUNT(*) FROM system_logs WHERE status = 'failed'")->fetchColumn();

    $viewed_logs = $_SESSION['viewed_logs_count'] ?? 0;
    $log_stats['failed'] = max(0, $log_stats['failed'] - $viewed_logs);
} catch (PDOException $e) {}

// Food count (no badge needed but kept for safety)
$total_food = 0;
try { $total_food = $pdo->query("SELECT COUNT(*) FROM food_items")->fetchColumn(); } catch(PDOException $e) {}

$gallery_count = [];
foreach($houses as $house) {
    $gallery_count[$house['id']] = count($house_gallery[$house['id']] ?? []);
}

$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);

$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

function getHouseMainImagePath($house, $gallery) {
    if (!empty($house['image']) && $house['image'] !== 'default-house.jpg') {
        $main_path = 'uploads/houses/' . $house['image'];
        $gal_path  = 'uploads/houses/gallery/' . $house['image'];
        if (file_exists($main_path)) return $main_path;
        if (file_exists($gal_path))  return $gal_path;
    }
    foreach ($gallery as $img) {
        if (!empty($img['is_main'])) {
            $path = 'uploads/houses/gallery/' . $img['image'];
            if (file_exists($path)) return $path;
        }
    }
    if (!empty($gallery)) {
        $path = 'uploads/houses/gallery/' . $gallery[0]['image'];
        if (file_exists($path)) return $path;
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>House Management - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb; min-height: 100vh; overflow-x: hidden;
        }
        .app-container { display: flex; min-height: 100vh; }
        
        /* SIDEBAR */
        .sidebar {
            width: 280px; background: #0B2447;
            box-shadow: 4px 0 20px rgba(0,0,0,0.2);
            padding: 25px 0; position: sticky; top: 0;
            height: 100vh; overflow-y: auto;
            border-right: 2px solid rgba(77, 166, 217, 0.15);
            transition: transform 0.3s ease, width 0.3s ease;
            z-index: 100; flex-shrink: 0;
        }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }
        
        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); overflow: hidden; }
        .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; letter-spacing: 0.3px; }
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; transition: all 0.2s; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; transform: rotate(90deg); }
        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.2); }
        
        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; position: relative; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active i { color: #7bb8f0; }
        .nav-link .nav-badge { margin-left: auto; background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }
        
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 99; opacity: 0; transition: opacity 0.3s ease; }
        .sidebar-overlay.active { display: block; opacity: 1; }
        
        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.3); align-items: center; justify-content: center; border: 1px solid rgba(77, 166, 217, 0.2); }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        .menu-toggle .fa-bars { transition: transform 0.3s ease; }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8); }
        
        @media (max-width: 1024px) {
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); width: 280px; z-index: 1000; box-shadow: none; border-radius: 0; padding-top: 25px; }
            .sidebar.open { transform: translateX(0); box-shadow: 4px 0 30px rgba(0,0,0,0.4); }
            .menu-toggle { display: flex; }
            .sidebar-overlay.active { display: block; }
            .main-content { padding: 70px 16px 20px !important; }
            .sidebar-close-btn { display: flex; }
        }
        @media (max-width: 480px) {
            .sidebar { width: 85%; max-width: 300px; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .main-content { padding: 60px 12px 16px !important; }
            .sidebar-header .logo .logo-text .main { font-size: 16px; }
            .sidebar-header .logo .logo-icon { width: 40px; height: 40px; font-size: 18px; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
        }
        
        .main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; transition: padding 0.3s ease; }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(11, 36, 71, 0.1);
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }

        .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }

        .top-bar .user-profile .avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 26px;
            border: 3px solid rgba(77, 166, 217, 0.35);
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(77, 166, 217, 0.35);
        }
        .top-bar .user-profile .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .top-bar .user-profile .user-name { color: #0B2447; font-weight: 600; font-size: 15px; }
        .top-bar .user-profile .user-role { color: #4a6a8c; font-size: 13px; }

        .top-bar .page-title h1 .title-badge,
        .top-bar .page-title h1 .avatar-mobile {
            display: none;
        }

        .title-badge {
            font-size: 13px;
            font-weight: 700;
            padding: 5px 16px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .title-badge.badge-admin {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1.5px solid rgba(239, 68, 68, 0.3);
        }
        .title-badge.badge-staff {
            background: rgba(251, 191, 36, 0.15);
            color: #d97706;
            border: 1.5px solid rgba(251, 191, 36, 0.3);
        }

        .avatar-mobile {
            display: none;
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
        .avatar-mobile img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
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
            
            .top-bar .page-title h1 .title-badge {
                display: inline-flex;
                font-size: 11px;
                padding: 4px 12px;
            }
            .top-bar .page-title h1 .avatar-mobile {
                display: inline-flex;
                position: absolute;
                top: 50%;
                right: 14px;
                transform: translateY(-50%);
            }
            
            .top-bar .page-title p { font-size: 12px; text-align: center; margin: 0; }
            .top-bar .user-profile { display: none !important; }
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
            .top-bar .page-title h1 .avatar-mobile {
                width: 72px;
                height: 72px;
                font-size: 28px;
                right: 12px;
            }
            .top-bar .page-title h1 .title-badge { font-size: 10px; padding: 3px 10px; }
        }
        
        .page-title-banner {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            border-radius: 20px;
            padding: 30px 35px;
            margin-bottom: 30px;
            color: white;
            box-shadow: 0 10px 30px rgba(11, 36, 71, 0.15);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        .page-title-banner::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); animation: rotate 20s linear infinite; }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .page-title-banner .banner-content { position: relative; z-index: 1; }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner h1 i { margin-right: 10px; opacity: 0.9; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin: 8px auto 0; opacity: 0.5; }
        .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }
        .page-title-banner p .staff-notice { display: inline-block; background: rgba(251, 191, 36, 0.2); color: #fbbf24; padding: 2px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; border: 1px solid rgba(251, 191, 36, 0.2); margin-top: 5px; }
        
        @media (max-width: 768px) {
            .page-title-banner { padding: 20px; border-radius: 16px; }
            .page-title-banner h1 { font-size: 22px; }
        }
        @media (max-width: 480px) {
            .page-title-banner { padding: 15px; border-radius: 12px; }
            .page-title-banner h1 { font-size: 18px; }
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
            .card-header h2 i { padding: 6px; font-size: 12px; }
        }
        @media (max-width: 480px) {
            .card { padding: 12px 10px; border-radius: 12px; }
            .card-header h2 { font-size: 14px; }
        }
        
        .btn { padding: 10px 20px; border: none; border-radius: 10px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
        .btn-primary { background: #4DA6D9; color: white; }
        .btn-primary:hover { background: #3a8bbf; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(77, 166, 217, 0.4); }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; transform: translateY(-2px); }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-2px); }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; transform: translateY(-2px); }
        .btn-info { background: #0ea5e9; color: white; }
        .btn-info:hover { background: #0284c7; transform: translateY(-2px); }
        .btn-sm { padding: 6px 14px; font-size: 12px; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; white-space: nowrap; font-weight: 600; }
        .btn-sm:hover { transform: translateY(-2px); }
        
        .house-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
        }

        .house-card {
            background: #4DA6D9;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.15);
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.15);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .house-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 40px rgba(77, 166, 217, 0.25);
        }

        .house-card .house-image-wrapper {
            position: relative;
            width: 100%;
            height: 200px;
            overflow: hidden;
            background: rgba(255,255,255,0.1);
            flex-shrink: 0;
        }

        .house-card .house-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .house-card:hover .house-image { transform: scale(1.03); }

        .house-card .house-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: rgba(255,255,255,0.3);
        }

        .house-card .status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 2;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .house-card .status-badge i { margin-right: 4px; }
        .house-card .status-badge.status-available { background: rgba(16, 185, 129, 0.95); color: white; }
        .house-card .status-badge.status-maintenance { background: rgba(245, 158, 11, 0.95); color: white; }
        .house-card .status-badge.status-unavailable { background: rgba(239, 68, 68, 0.95); color: white; }

        .house-card .gallery-count-badge {
            position: absolute;
            bottom: 12px;
            left: 12px;
            background: rgba(0,0,0,0.65);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            backdrop-filter: blur(5px);
            z-index: 2;
        }
        .house-card .gallery-count-badge i { margin-right: 4px; }

        .house-card .house-content {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .house-card .house-content h4 {
            font-size: 18px;
            font-weight: 700;
            color: white;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        .house-card .house-content h4 i { margin-right: 6px; opacity: 0.8; font-size: 16px; }

        .house-card .house-meta {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .house-card .house-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: rgba(255,255,255,0.9);
            font-size: 13px;
        }
        .house-card .house-meta .meta-item i {
            color: white;
            font-size: 13px;
            opacity: 0.85;
        }

        .house-card .house-desc {
            color: rgba(255,255,255,0.9);
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 39px;
        }

        .house-card .price-tag {
            font-size: 22px;
            font-weight: 700;
            color: #F4B400;
            margin: 4px 0 10px;
            line-height: 1.2;
        }
        .house-card .price-tag small {
            font-size: 13px;
            font-weight: 400;
            color: rgba(255,255,255,0.7);
            margin-left: 2px;
        }

        .house-card .amenities-preview {
            background: rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 10px 12px;
            margin: 4px 0 12px;
            font-size: 12px;
            color: rgba(255,255,255,0.9);
            line-height: 1.7;
        }
        .house-card .amenities-preview .amenities-title {
            display: block;
            font-weight: 700;
            color: #F4B400;
            margin-bottom: 4px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .house-card .amenities-preview .amenity-line {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 1px 0;
        }
        .house-card .amenities-preview .amenity-line i {
            color: #F4B400;
            font-size: 11px;
            margin-top: 3px;
            flex-shrink: 0;
        }

        .house-card .house-actions {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,0.12);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .house-card .btn-card {
            flex: 1;
            min-width: 70px;
            min-height: 40px;
            padding: 10px 12px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 12.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
        }
        .house-card .btn-card:active { transform: scale(0.97); }

        .house-card .btn-gallery-card {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }
        .house-card .btn-edit-card {
            background: #f59e0b;
            color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        .house-card .btn-delete-card {
            background: #ef4444;
            color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .desktop-table {
            display: block;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            border: 1px solid #e8f0fe;
        }

        .house-grid.mobile-only {
            display: none;
        }

        .manage-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .manage-table thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        .manage-table thead th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .manage-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }

        .manage-table tbody tr:hover {
            background: #f8fafc;
        }

        .manage-table tbody tr:last-child {
            border-bottom: none;
        }

        .manage-table tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            color: #475569;
            font-size: 14px;
        }

        .manage-table .image-cell {
            width: 80px;
        }

        .manage-table .image-cell img {
            width: 70px;
            height: 55px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e8f0fe;
            display: block;
        }

        .manage-table .image-cell .no-image {
            width: 70px;
            height: 55px;
            background: #f1f5f9;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 20px;
            border: 1px solid #e8f0fe;
        }

        .manage-table .name-cell {
            color: #0B2447;
            font-weight: 600;
            font-size: 15px;
        }

        .manage-table .desc-cell {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .manage-table .price-cell {
            font-weight: 700;
            color: #0B2447;
            white-space: nowrap;
        }

        .capacity-badge {
            display: inline-block;
            padding: 5px 12px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            line-height: 1.3;
            white-space: nowrap;
        }

        .capacity-badge i {
            margin-right: 3px;
        }

        .capacity-badge small {
            font-size: 10px;
            font-weight: 500;
            opacity: 0.85;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .status-pill.status-available {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pill.status-maintenance {
            background: #fef3c7;
            color: #92400e;
        }

        .status-pill.status-unavailable {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-gallery-count {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            background: linear-gradient(135deg, #4DA6D9, #3a8bbf);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 3px 10px rgba(77, 166, 217, 0.25);
            white-space: nowrap;
        }

        .btn-gallery-count:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(77, 166, 217, 0.4);
        }

        .btn-gallery-count i {
            font-size: 13px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: nowrap;
        }

        .action-buttons .btn-action {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            white-space: nowrap;
        }

        .action-buttons .btn-edit {
            background: #f59e0b;
            color: white;
            box-shadow: 0 3px 10px rgba(245, 158, 11, 0.25);
        }

        .action-buttons .btn-edit:hover {
            background: #d97706;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.4);
        }

        .action-buttons .btn-delete {
            background: #ef4444;
            color: white;
            box-shadow: 0 3px 10px rgba(239, 68, 68, 0.25);
        }

        .action-buttons .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(239, 68, 68, 0.4);
        }

        @media (min-width: 769px) {
            .desktop-table { display: block !important; }
            .house-grid.mobile-only { display: none !important; }
        }

        @media (max-width: 768px) {
            .desktop-table { display: none !important; }
            .house-grid.mobile-only {
                display: grid !important;
                grid-template-columns: 1fr;
                gap: 18px;
            }
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
            background: #f8fafc;
            border-radius: 16px;
            border: 2px dashed #e8f0fe;
        }
        .empty-state i {
            font-size: 60px;
            display: block;
            margin-bottom: 15px;
            color: #cbd5e1;
        }
        .empty-state p { font-size: 15px; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto; padding: 30px; animation: modalSlideIn 0.3s ease; box-shadow: 0 30px 60px rgba(0,0,0,0.3); }
        @keyframes modalSlideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; margin-bottom: 20px; }
        .modal-header h3 { font-size: 20px; font-weight: 700; color: #0B2447; margin: 0; }
        .modal-header h3 i { margin-right: 10px; color: #4DA6D9; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; transition: color 0.3s; background: none; border: none; padding: 0 10px; line-height: 1; }
        .modal-header .close:hover { color: #ef4444; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-group label .required { color: #dc2626; margin-left: 3px; }
        .form-control, .form-select { width: 100%; padding: 10px 12px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-section-title { font-size: 14px; font-weight: 600; color: #4DA6D9; margin: 20px 0 15px; border-bottom: 1px solid #e8f0fe; padding-bottom: 10px; }
        .form-section-title i { margin-right: 8px; }
        .file-upload-hint { font-size: 12px; color: #94a3b8; margin-top: 5px; }
        
        .current-image-preview { margin-top: 10px; padding: 15px; background: #f8fafc; border-radius: 10px; border: 1px dashed #cbd5e1; text-align: center; transition: all 0.3s; }
        .current-image-preview img { max-width: 100%; max-height: 200px; border-radius: 8px; object-fit: cover; border: 2px solid #e8f0fe; }
        .current-image-preview .label { font-size: 11px; color: #64748b; margin-bottom: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
        .current-image-preview .no-image { color: #94a3b8; font-size: 12px; padding: 30px; font-style: italic; }
        
        .remove-image-box { display: flex; align-items: flex-start; gap: 10px; padding: 12px 15px; background: #fef2f2; border: 2px solid #fecaca; border-radius: 10px; cursor: pointer; transition: all 0.2s; margin-top: 10px; }
        .remove-image-box:hover { border-color: #ef4444; background: #fee2e2; }
        .remove-image-box input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #ef4444; margin-top: 2px; flex-shrink: 0; }
        .remove-image-box label { margin-bottom: 0; cursor: pointer; font-weight: 600; color: #991b1b; font-size: 13px; line-height: 1.5; }
        .remove-image-box label i { color: #ef4444; margin-right: 4px; }
        
        .multi-upload-box { background: #f0f7fb; border: 2px dashed #4DA6D9; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s; margin-top: 10px; position: relative; }
        .multi-upload-box:hover { background: #e0f0fa; border-color: #3a8bbf; transform: translateY(-2px); }
        .multi-upload-box.dragover { background: #d0e8f5; border-color: #10b981; border-style: solid; transform: scale(1.02); }
        .multi-upload-box .upload-icon { font-size: 40px; color: #4DA6D9; margin-bottom: 10px; display: block; }
        .multi-upload-box .upload-title { font-weight: 700; color: #0B2447; font-size: 14px; margin-bottom: 5px; }
        .multi-upload-box .upload-subtitle { color: #64748b; font-size: 12px; }
        .multi-upload-box input[type="file"] { display: none; }
        
        .file-preview-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; padding: 12px; background: white; border-radius: 10px; border: 1px solid #e8f0fe; max-height: 200px; overflow-y: auto; }
        .file-preview-item { position: relative; width: 70px; height: 70px; border-radius: 8px; overflow: hidden; border: 2px solid #e8f0fe; flex-shrink: 0; }
        .file-preview-item img { width: 100%; height: 100%; object-fit: cover; }
        .file-preview-item .remove-file { position: absolute; top: 2px; right: 2px; background: rgba(239, 68, 68, 0.9); color: white; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .file-preview-item .remove-file:hover { background: #dc2626; transform: scale(1.1); }
        .file-preview-item .file-size { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.7); color: white; font-size: 9px; padding: 2px; text-align: center; }
        
        .upload-progress { display: none; margin-top: 12px; padding: 12px; background: white; border-radius: 10px; border: 1px solid #e8f0fe; }
        .upload-progress.active { display: block; }
        .progress-bar-wrapper { width: 100%; height: 8px; background: #e8f0fe; border-radius: 10px; overflow: hidden; margin-top: 8px; }
        .progress-bar-fill { height: 100%; background: linear-gradient(90deg, #4DA6D9, #10b981); width: 0%; transition: width 0.3s ease; border-radius: 10px; }
        .progress-text { font-size: 12px; color: #64748b; display: flex; justify-content: space-between; font-weight: 600; }
        
        .selected-count { display: inline-flex; align-items: center; gap: 6px; background: #10b981; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; margin-top: 8px; }
        
        @media (max-width: 768px) {
            .modal-content { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .form-section-title { text-align: center; }
        }
        @media (max-width: 480px) {
            .modal-content { padding: 15px; max-width: 95%; }
            .file-preview-item { width: 55px; height: 55px; }
        }
        
        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; }
        .gallery-item { background: #f8fafc; border-radius: 12px; overflow: hidden; border: 1px solid #e8f0fe; text-align: center; padding: 10px; position: relative; transition: all 0.2s; }
        .gallery-item.deleting { opacity: 0.4; transform: scale(0.95); pointer-events: none; }
        .gallery-item.newly-added { animation: popIn 0.5s ease; }
        @keyframes popIn { 0% { transform: scale(0.5); opacity: 0; } 60% { transform: scale(1.05); } 100% { transform: scale(1); opacity: 1; } }
        .gallery-item img { width: 100%; height: 120px; object-fit: cover; border-radius: 8px; }
        .gallery-item .gallery-actions { display: flex; gap: 5px; justify-content: center; margin-top: 8px; flex-wrap: wrap; }
        .gallery-item .gallery-actions .btn-sm { padding: 3px 8px; font-size: 10px; }
        .badge-main { background: #f59e0b; color: #0B2447; font-size: 9px; padding: 1px 8px; border-radius: 12px; }

        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }
        .alert i { font-size: 18px; }
        
        .footer { background: #0B2447; color: #b3d9ff; padding: 15px 0; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77, 166, 217, 0.15); }
        .footer i { color: #4DA6D9; }
        
        .toast-notification { position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 15px 25px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 9999; font-weight: 600; display: flex; align-items: center; gap: 10px; animation: slideIn 0.3s ease; max-width: 350px; }
        .toast-notification.error { background: #ef4444; }
        .toast-notification.warning { background: #f59e0b; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }

        .logout-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; animation: logoutFadeIn 0.2s ease; overscroll-behavior: contain; }
        .logout-modal-overlay.show { display: flex; }
        @keyframes logoutFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .logout-modal { background: white; border-radius: 24px; max-width: 400px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); animation: logoutSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); border-top: 6px solid #ef4444; max-height: 90vh; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        @keyframes logoutSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .logout-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; animation: logoutPulse 2s ease-in-out infinite; }
        @keyframes logoutPulse { 0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); } 50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); } }
        .logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
        .logout-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
        .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-logout-cancel, .btn-logout-confirm { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.25s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; -webkit-tap-highlight-color: rgba(0,0,0,0.1); touch-action: manipulation; }
        .btn-logout-cancel { background: #e2e8f0; color: #475569; }
        .btn-logout-cancel:hover, .btn-logout-cancel:active { background: #cbd5e1; transform: translateY(-2px); }
        .btn-logout-confirm { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3); }
        .btn-logout-confirm:hover, .btn-logout-confirm:active { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45); color: white; }
        @media (max-width: 480px) {
            .logout-modal { padding: 28px 22px 20px; border-radius: 20px; }
            .logout-modal-icon { width: 65px; height: 65px; font-size: 28px; margin-bottom: 14px; }
            .logout-modal h3 { font-size: 19px; }
            .logout-modal p { font-size: 13px; margin-bottom: 20px; }
            .logout-modal-actions { flex-direction: column-reverse; }
            .btn-logout-cancel, .btn-logout-confirm { width: 100%; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<div class="app-container">
    
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
            <?php if($is_admin): ?>
            <li class="nav-item"><a href="user-management.php" class="nav-link"><i class="fas fa-users"></i><span>User Management</span></a></li>
            <?php endif; ?>
            <li class="nav-item"><a href="house-dashboard.php" class="nav-link active"><i class="fas fa-home"></i><span>House Management</span></a></li>
            <li class="nav-item"><a href="tour-dashboard.php" class="nav-link"><i class="fas fa-umbrella-beach"></i><span>Tour Management</span></a></li>
            <li class="nav-item"><a href="activities-dashboard.php" class="nav-link"><i class="fas fa-water"></i><span>Activities Management</span></a></li>
            <li class="nav-item">
                <a href="food-dashboard.php" class="nav-link">
                    <i class="fas fa-utensils"></i><span>Food Management</span>
                </a>
            </li>

            <!-- ✅ Booking Management — badge lang sa PENDING bookings -->
            <li class="nav-item">
                <a href="booking-management.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i><span>Booking Management</span>
                    <?php if($total_bookings_pending > 0): ?>
                        <span class="nav-badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;"><?php echo $total_bookings_pending; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <!-- ✅ Block Dates nav item -->
            <li class="nav-item">
                <a href="blocked-dates.php" class="nav-link">
                    <i class="fas fa-ban"></i><span>Block Dates</span>
                </a>
            </li>

            <!-- ✅ Reviews Management — badge lang kung may BAGONG reviews -->
            <li class="nav-item">
                <a href="reviews-management.php" class="nav-link">
                    <i class="fas fa-star"></i><span>Reviews Management</span>
                    <?php if($total_reviews > 0): ?>
                        <span class="nav-badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981;"><?php echo $total_reviews; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Sales Report</span></a></li>
            <?php if($is_admin): ?>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>
            <!-- ✅ System Logs — badge lang kung may FAILED logs -->
            <li class="nav-item">
                <a href="system-logs.php" class="nav-link">
                    <i class="fas fa-history"></i><span>System Logs</span>
                    <?php if($log_stats['failed'] > 0): ?>
                        <span class="nav-badge"><?php echo $log_stats['failed']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <div class="nav-divider"></div>
            <li class="nav-item"><a href="admin-profile.php" class="nav-link"><i class="fas fa-user-circle"></i><span>My Profile</span></a></li>
            <li class="nav-item">
                <a href="#" class="nav-link" onclick="openLogoutModal(event); return false;">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="main-content">
        
        <!-- TOP BAR -->
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-home"></i> House Management

                    <span class="title-badge <?php echo $is_admin ? 'badge-admin' : 'badge-staff'; ?>">
                        <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                        <?php echo $is_admin ? 'Admin' : 'Staff'; ?>
                    </span>
                    <span class="avatar-mobile" title="<?php echo htmlspecialchars($admin_display_name); ?>">
                        <?php if($admin_avatar): ?>
                            <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $admin_initial; ?>
                        <?php endif; ?>
                    </span>
                </h1>
                <p>Manage your transient houses, view availability, and update details</p>
            </div>

            <div class="user-profile">
                <div class="user-info" style="text-align: right;">
                    <div class="user-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
                    <div class="user-role">
                        <?php if($is_admin): ?>
                            <i class="fas fa-crown" style="color: #fbbf24;"></i> Admin
                        <?php else: ?>
                            <i class="fas fa-user-tie" style="color: #fbbf24;"></i> Staff
                        <?php endif; ?>
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
        
        <?php if(isset($_GET['deleted'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> House and all its images permanently deleted!</div>
        <?php endif; ?>
        
        <?php if(isset($success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if(isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-home"></i> House Management</h1>
                <div class="underline"></div>
                <p>
                    Manage all transient houses, view availability, and update details
                    <?php if($is_staff): ?>
                        <br><span class="staff-notice"><i class="fas fa-user-tie"></i> Staff Access - Full Management</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Manage Houses</h2>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="showModal('addHouse')">
                        <i class="fas fa-plus"></i> Add New House
                    </button>
                </div>
            </div>
            
            <?php if(count($houses) > 0): ?>

            <div class="table-responsive desktop-table">
                <table class="manage-table">
                    <thead>
                        <tr>
                            <th>IMAGE</th>
                            <th>HOUSE NAME</th>
                            <th>PRICE/NIGHT</th>
                            <th>CAPACITY</th>
                            <th>STATUS</th>
                            <th>GALLERY</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($houses as $house): 
                            $gallery = $house_gallery[$house['id']] ?? [];
                            $main_image = getHouseMainImagePath($house, $gallery);
                            $status = $house['status'] ?? 'available';
                            $status_label = ucfirst($status);
                            $status_icon = $status === 'available' ? 'check-circle' : ($status === 'maintenance' ? 'tools' : 'times-circle');
                        ?>
                        <tr>
                            <td class="image-cell">
                                <?php if($main_image): ?>
                                    <img src="<?php echo htmlspecialchars($main_image); ?>?v=<?php echo time(); ?>" 
                                         alt="<?php echo htmlspecialchars($house['house_name']); ?>"
                                         onerror="this.src='https://via.placeholder.com/60x60/4DA6D9/ffffff?text=House'">
                                <?php else: ?>
                                    <div class="no-image"><i class="fas fa-home"></i></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="name-cell">
                                    <strong><?php echo htmlspecialchars($house['house_name']); ?></strong>
                                </div>
                                <?php if(!empty($house['description'])): ?>
                                    <div class="desc-cell"><?php echo htmlspecialchars($house['description']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="price-cell">
                                ₱<?php echo number_format($house['price_per_night']); ?>
                            </td>
                            <td>
                                <span class="capacity-badge">
                                    <i class="fas fa-users"></i>
                                    <?php echo $house['capacity']; ?> pax
                                    <br>
                                    <small><?php echo $house['bedrooms']; ?> bedroom<?php echo $house['bedrooms'] != 1 ? 's' : ''; ?></small>
                                </span>
                            </td>
                            <td>
                                <span class="status-pill status-<?php echo $status; ?>">
                                    <i class="fas fa-<?php echo $status_icon; ?>"></i>
                                    <?php echo $status_label; ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn-gallery-count"
                                        onclick="showGallery(<?php echo $house['id']; ?>, '<?php echo addslashes($house['house_name']); ?>')">
                                    <i class="fas fa-images"></i> (<?php echo count($gallery); ?>)
                                </button>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-action btn-edit" 
                                            onclick='editHouse(<?php echo htmlspecialchars(json_encode($house), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="?delete_house=<?php echo $house['id']; ?>" 
                                       class="btn-action btn-delete"
                                       onclick="return confirm('Delete this house and all its gallery images? This cannot be undone!')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="house-grid mobile-only">
                <?php foreach($houses as $house): 
                    $gallery = $house_gallery[$house['id']] ?? [];
                    $main_image = getHouseMainImagePath($house, $gallery);
                    $amenities = !empty($house['amenities']) ? array_filter(array_map('trim', explode(',', $house['amenities']))) : [];
                    $display_amenities = array_slice($amenities, 0, 3);
                    $status = $house['status'] ?? 'available';
                    $status_label = ucfirst($status);
                    $status_icon = $status === 'available' ? 'check-circle' : ($status === 'maintenance' ? 'tools' : 'times-circle');
                ?>
                <div class="house-card">
                    <div class="house-image-wrapper">
                        <?php if($main_image): ?>
                            <img src="<?php echo htmlspecialchars($main_image); ?>?v=<?php echo time(); ?>" 
                                 alt="<?php echo htmlspecialchars($house['house_name']); ?>" 
                                 class="house-image"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="house-image-placeholder" style="display:none;">
                                <i class="fas fa-home"></i>
                            </div>
                        <?php else: ?>
                            <div class="house-image-placeholder">
                                <i class="fas fa-home"></i>
                            </div>
                        <?php endif; ?>
                        
                        <span class="status-badge status-<?php echo $status; ?>">
                            <i class="fas fa-<?php echo $status_icon; ?>"></i>
                            <?php echo $status_label; ?>
                        </span>
                        
                        <?php if(count($gallery) > 0): ?>
                        <span class="gallery-count-badge">
                            <i class="fas fa-images"></i> <?php echo count($gallery); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="house-content">
                        <h4>
                            <i class="fas fa-home"></i>
                            <?php echo htmlspecialchars($house['house_name']); ?>
                        </h4>
                        
                        <div class="house-meta">
                            <div class="meta-item">
                                <i class="fas fa-users"></i>
                                <span><?php echo $house['capacity']; ?> pax</span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-bed"></i>
                                <span><?php echo $house['bedrooms']; ?> bed<?php echo $house['bedrooms'] != 1 ? 's' : ''; ?></span>
                            </div>
                        </div>
                        
                        <?php if(!empty($house['description'])): ?>
                        <div class="house-desc">
                            <?php echo htmlspecialchars($house['description']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="price-tag">
                            ₱<?php echo number_format($house['price_per_night']); ?> <small>/night</small>
                        </div>
                        
                        <?php if(!empty($display_amenities)): ?>
                        <div class="amenities-preview">
                            <span class="amenities-title">
                                <i class="fas fa-check-circle"></i> Inclusions:
                            </span>
                            <?php foreach($display_amenities as $amenity): ?>
                                <div class="amenity-line">
                                    <i class="fas fa-check"></i>
                                    <span><?php echo htmlspecialchars($amenity); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if(count($amenities) > 3): ?>
                                <div class="amenity-line" style="color: rgba(255,255,255,0.65); font-style: italic;">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>+<?php echo count($amenities) - 3; ?> more inclusions</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="house-actions">
                            <button type="button" class="btn-card btn-gallery-card"
                                    onclick="showGallery(<?php echo $house['id']; ?>, '<?php echo addslashes($house['house_name']); ?>')">
                                <i class="fas fa-images"></i> Gallery
                            </button>
                            <button type="button" class="btn-card btn-edit-card"
                                    onclick='editHouse(<?php echo htmlspecialchars(json_encode($house), ENT_QUOTES, "UTF-8"); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <a href="?delete_house=<?php echo $house['id']; ?>" 
                               class="btn-card btn-delete-card"
                               onclick="return confirm('Delete this house and all its gallery images? This cannot be undone!')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-home"></i>
                <p>No houses found. Click "Add New House" to get started!</p>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>
                <i class="fas fa-umbrella-beach"></i>
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Huddled Islands Tour and Reservation. All rights reserved.'); ?>
                <span style="opacity: 0.3; margin: 0 10px;">|</span>
                <span style="color: #7bb8f0; font-size: 11px;">
                    <i class="fas fa-user-shield"></i> 
                    <?php echo $is_admin ? 'Administrator' : 'Staff'; ?> Access
                </span>
            </p>
        </div>
    </div>
</div>

<!-- ADD HOUSE MODAL -->
<div class="modal" id="addHouseModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add New House</h3>
            <button class="close" onclick="hideModal('addHouse')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-section-title"><i class="fas fa-home"></i> House Information</div>
                
                <div class="form-group">
                    <label>House Name <span class="required">*</span></label>
                    <input type="text" name="house_name" class="form-control" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Price per Night (₱) <span class="required">*</span></label>
                        <input type="number" name="price_per_night" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Capacity (persons) <span class="required">*</span></label>
                        <input type="number" name="capacity" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Bedrooms <span class="required">*</span></label>
                        <input type="number" name="bedrooms" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-select">
                            <option value="available">Available</option>
                            <option value="maintenance">Under Maintenance</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Amenities (comma separated)</label>
                    <textarea name="amenities" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Main House Image</label>
                    <input type="file" name="house_image" class="form-control" accept="image/*">
                </div>
                
                <div class="form-group">
                    <label>Gallery Images (Multiple)</label>
                    <input type="file" name="gallery_images[]" class="form-control" accept="image/*" multiple>
                </div>
                
                <button type="submit" name="add_house" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Add House
                </button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT HOUSE MODAL -->
<div class="modal" id="editHouseModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit House</h3>
            <button class="close" onclick="hideModal('editHouse')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" id="editHouseForm">
                <input type="hidden" name="house_id" id="edit_house_id">
                <input type="hidden" name="remove_main_image" id="edit_remove_main_image" value="0">
                
                <div class="form-section-title"><i class="fas fa-home"></i> House Information</div>
                
                <div class="form-group">
                    <label>House Name <span class="required">*</span></label>
                    <input type="text" name="house_name" id="edit_house_name" class="form-control" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Price per Night (₱) <span class="required">*</span></label>
                        <input type="number" name="price_per_night" id="edit_price" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Capacity (persons) <span class="required">*</span></label>
                        <input type="number" name="capacity" id="edit_capacity" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Bedrooms <span class="required">*</span></label>
                        <input type="number" name="bedrooms" id="edit_bedrooms" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="available">Available</option>
                            <option value="maintenance">Under Maintenance</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Amenities (comma separated)</label>
                    <textarea name="amenities" id="edit_amenities" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-section-title"><i class="fas fa-image"></i> Main House Image</div>
                
                <div class="form-group">
                    <label>Current Main Image</label>
                    <div class="current-image-preview" id="current_main_image_preview"></div>
                </div>
                
                <div class="remove-image-box" id="remove_image_box" onclick="toggleRemoveImage()">
                    <input type="checkbox" id="remove_image_checkbox" onclick="event.stopPropagation(); toggleRemoveImage();">
                    <label for="remove_image_checkbox">
                        <i class="fas fa-trash-alt"></i> Remove current main image permanently
                        <div style="font-weight: 400; font-size: 11px; color: #b91c1c; margin-top: 4px;">
                            Deletes the image from the server. If there are other gallery images, the first one will become the new main image.
                        </div>
                    </label>
                </div>
                
                <div class="form-group" style="margin-top: 15px;">
                    <label><i class="fas fa-upload"></i> Upload New Main Image</label>
                    <input type="file" name="house_image" id="edit_house_image" class="form-control" accept="image/*">
                    <div class="file-upload-hint">
                        <i class="fas fa-info-circle"></i> 
                        Upload a new image to replace the current main image. The old image file will be permanently deleted.
                    </div>
                </div>
                
                <button type="submit" name="edit_house" class="btn btn-primary" style="width: 100%; margin-top: 15px;">
                    <i class="fas fa-save"></i> Update House
                </button>
            </form>
        </div>
    </div>
</div>

<!-- GALLERY MODAL — WITH MULTI-UPLOAD -->
<div class="modal" id="galleryModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-images"></i> Gallery - <span id="galleryHouseName"></span></h3>
            <button class="close" onclick="hideModal('gallery')">&times;</button>
        </div>
        <div class="modal-body">
            
            <div id="galleryContent">
                <div style="text-align: center; padding: 20px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #4DA6D9;"></i>
                    <p style="margin-top: 10px; color: #94a3b8;">Loading gallery...</p>
                </div>
            </div>
            
            <hr style="margin: 20px 0;">
            
            <div class="form-section-title">
                <i class="fas fa-cloud-upload-alt"></i> Upload Multiple Images
            </div>
            
            <input type="hidden" id="gallery_house_id">
            
            <div class="multi-upload-box" id="multiUploadBox" onclick="document.getElementById('multiFileInput').click()">
                <i class="fas fa-cloud-upload-alt upload-icon"></i>
                <div class="upload-title">Click to Select or Drag & Drop</div>
                <div class="upload-subtitle">You can select MULTIPLE images at once (JPG, PNG, GIF, WEBP)</div>
                <div class="upload-subtitle" style="margin-top: 6px; font-size: 11px;">
                    <i class="fas fa-info-circle"></i> Max 10MB per file
                </div>
                <input type="file" id="multiFileInput" name="gallery_images[]" accept="image/*" multiple>
            </div>
            
            <div id="selectedCountBox" style="display: none; text-align: center; margin-top: 10px;">
                <span class="selected-count">
                    <i class="fas fa-check-circle"></i>
                    <span id="selectedCountText">0 images selected</span>
                </span>
            </div>
            
            <div class="file-preview-list" id="filePreviewList" style="display: none;"></div>
            
            <div class="upload-progress" id="uploadProgress">
                <div class="progress-text">
                    <span id="progressLabel">Uploading...</span>
                    <span id="progressPercent">0%</span>
                </div>
                <div class="progress-bar-wrapper">
                    <div class="progress-bar-fill" id="progressFill"></div>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <button type="button" class="btn btn-success" id="uploadBtn" style="flex: 1;" onclick="uploadMultipleImages()" disabled>
                    <i class="fas fa-upload"></i> Upload Selected Images
                </button>
                <button type="button" class="btn btn-secondary" id="clearBtn" style="background: #64748b; color: white;" onclick="clearSelectedFiles()" disabled>
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
            
        </div>
    </div>
</div>

<!-- LOGOUT CONFIRMATION MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
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
// STATE
// ============================================================
let currentGalleryHouseId = null;
let currentGalleryHouseName = '';
let currentMainImage = '';
let selectedFiles = [];

// ============================================================
// SIDEBAR
// ============================================================
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('menuToggle');

    const willOpen = !sidebar.classList.contains('open');

    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    toggleBtn.classList.toggle('active');

    if (willOpen && window.innerWidth <= 1024) {
        document.body.classList.add('sidebar-open-mobile');
    } else {
        document.body.classList.remove('sidebar-open-mobile');
    }

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
// MODAL
// ============================================================
function showModal(type) {
    document.getElementById(type + 'Modal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function hideModal(type) {
    document.getElementById(type + 'Modal').classList.remove('show');
    document.body.style.overflow = 'auto';
    
    if (type === 'gallery') {
        clearSelectedFiles();
    }
}

// ============================================================
// TOAST
// ============================================================
function showToast(message, type = 'success') {
    const existing = document.querySelector('.toast-notification');
    if (existing) existing.remove();
    
    const toast = document.createElement('div');
    toast.className = 'toast-notification ' + (type !== 'success' ? type : '');
    const icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-circle' : 'exclamation-triangle');
    toast.innerHTML = '<i class="fas fa-' + icon + '"></i> ' + message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 2500);
}

// ============================================================
// TOGGLE REMOVE IMAGE
// ============================================================
function toggleRemoveImage() {
    const checkbox = document.getElementById('remove_image_checkbox');
    const hidden = document.getElementById('edit_remove_main_image');
    const preview = document.getElementById('current_main_image_preview');
    
    checkbox.checked = !checkbox.checked;
    
    if (checkbox.checked) {
        hidden.value = '1';
        preview.style.opacity = '0.4';
        preview.style.filter = 'grayscale(1)';
    } else {
        hidden.value = '0';
        preview.style.opacity = '1';
        preview.style.filter = 'none';
    }
}

// ============================================================
// EDIT HOUSE
// ============================================================
function editHouse(house) {
    document.getElementById('edit_house_id').value = house.id;
    document.getElementById('edit_house_name').value = house.house_name;
    document.getElementById('edit_price').value = house.price_per_night;
    document.getElementById('edit_capacity').value = house.capacity;
    document.getElementById('edit_bedrooms').value = house.bedrooms;
    document.getElementById('edit_description').value = house.description || '';
    document.getElementById('edit_amenities').value = house.amenities || '';
    document.getElementById('edit_status').value = house.status;
    
    document.getElementById('remove_image_checkbox').checked = false;
    document.getElementById('edit_remove_main_image').value = '0';
    document.getElementById('edit_house_image').value = '';
    
    const preview = document.getElementById('current_main_image_preview');
    preview.style.opacity = '1';
    preview.style.filter = 'none';
    
    const filename = house.image || 'default-house.jpg';
    const t = Date.now();
    const candidates = [
        'uploads/houses/' + filename + '?v=' + t,
        'uploads/houses/gallery/' + filename + '?v=' + t
    ];
    
    if (house.image && house.image !== 'default-house.jpg') {
        preview.innerHTML = `
            <div class="label"><i class="fas fa-image"></i> Current Main Image</div>
            <img src="${candidates[0]}" alt="Current Main Image"
                 onerror="if(this.src.indexOf('gallery') === -1 && this.src.indexOf('/gallery/') === -1){ this.src='${candidates[1]}'; } else { this.parentElement.innerHTML='<div class=\\'label\\'><i class=\\'fas fa-image\\'></i> Current Main Image</div><div class=\\'no-image\\'><i class=\\'fas fa-exclamation-triangle\\'></i> Image file not found on server</div>'; }">
            <div style="font-size: 11px; color: #64748b; margin-top: 8px; word-break: break-all;">
                <i class="fas fa-file-image"></i> ${filename}
            </div>
        `;
    } else {
        preview.innerHTML = `
            <div class="label"><i class="fas fa-image"></i> Current Main Image</div>
            <div class="no-image">
                <i class="fas fa-image" style="font-size: 32px; display: block; margin-bottom: 8px; opacity: 0.5;"></i>
                No main image uploaded
            </div>
        `;
    }
    
    showModal('editHouse');
}

// ============================================================
// SHOW GALLERY
// ============================================================
function showGallery(houseId, houseName) {
    currentGalleryHouseId = houseId;
    currentGalleryHouseName = houseName;
    
    document.getElementById('galleryHouseName').textContent = houseName;
    document.getElementById('gallery_house_id').value = houseId;
    document.getElementById('galleryContent').innerHTML = `
        <div style="text-align: center; padding: 20px;">
            <i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #4DA6D9;"></i>
            <p style="margin-top: 10px; color: #94a3b8;">Loading gallery...</p>
        </div>
    `;
    
    clearSelectedFiles();
    
    showModal('gallery');
    loadGallery(houseId);
}

function loadGallery(houseId) {
    fetch('house-dashboard.php?ajax_get_gallery=1&house_id=' + houseId + '&_=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                renderGalleryError(data.message || 'Failed to load gallery');
                return;
            }
            currentMainImage = data.main_image;
            renderGallery(data.images, data.main_image);
        })
        .catch(error => {
            console.error('Gallery load error:', error);
            renderGalleryError('Network error. Please try again.');
        });
}

function renderGallery(images, mainImage) {
    const container = document.getElementById('galleryContent');
    const t = Date.now();
    
    if (!images || images.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #94a3b8;">
                <i class="fas fa-images" style="font-size: 48px; display: block; margin-bottom: 10px; color: #cbd5e1;"></i>
                <p style="font-size: 14px;">No gallery images yet.</p>
                <p style="font-size: 12px; margin-top: 8px;">Upload images using the form below.</p>
            </div>
        `;
        return;
    }
    
    let html = '<div class="gallery-grid">';
    images.forEach(img => {
        const isMain = img.is_main == 1;
        html += `
            <div class="gallery-item" id="gallery-item-${img.id}">
                <img src="uploads/houses/gallery/${img.image}?v=${t}" alt="Gallery Image" onerror="this.src='https://via.placeholder.com/150x120?text=Image+Missing'">
                ${isMain ? '<div class="badge-main"><i class="fas fa-star"></i> Main</div>' : ''}
                <div class="gallery-actions">
                    ${!isMain ? `<button class="btn-sm btn-warning" onclick="setMainImage(${img.id})" title="Set as main"><i class="fas fa-star"></i> Set Main</button>` : ''}
                    <button class="btn-sm btn-danger" onclick="deleteGalleryImage(${img.id})" title="Delete permanently"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

function renderGalleryError(message) {
    document.getElementById('galleryContent').innerHTML = `
        <div style="text-align: center; padding: 20px; color: #ef4444;">
            <i class="fas fa-exclamation-circle" style="font-size: 30px;"></i>
            <p style="margin-top: 10px;">${message}</p>
        </div>
    `;
}

// ============================================================
// MULTI-FILE UPLOAD — File Selection
// ============================================================
const multiFileInput = document.getElementById('multiFileInput');
const multiUploadBox = document.getElementById('multiUploadBox');
const filePreviewList = document.getElementById('filePreviewList');
const selectedCountBox = document.getElementById('selectedCountBox');
const selectedCountText = document.getElementById('selectedCountText');
const uploadBtn = document.getElementById('uploadBtn');
const clearBtn = document.getElementById('clearBtn');

multiFileInput.addEventListener('change', function(e) {
    handleFilesSelected(this.files);
});

multiUploadBox.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.stopPropagation();
    this.classList.add('dragover');
});

multiUploadBox.addEventListener('dragleave', function(e) {
    e.preventDefault();
    e.stopPropagation();
    this.classList.remove('dragover');
});

multiUploadBox.addEventListener('drop', function(e) {
    e.preventDefault();
    e.stopPropagation();
    this.classList.remove('dragover');
    
    if (e.dataTransfer.files.length > 0) {
        handleFilesSelected(e.dataTransfer.files);
    }
});

function handleFilesSelected(files) {
    const allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    const maxSize = 10 * 1024 * 1024;
    
    let added = 0;
    let rejected = 0;
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        
        if (!allowed.includes(file.type)) {
            rejected++;
            continue;
        }
        
        if (file.size > maxSize) {
            rejected++;
            continue;
        }
        
        const isDuplicate = selectedFiles.some(f => f.name === file.name && f.size === file.size);
        if (!isDuplicate) {
            selectedFiles.push(file);
            added++;
        }
    }
    
    updateFilePreviewUI();
    
    if (rejected > 0) {
        showToast(rejected + ' file(s) rejected (invalid type or too large)', 'warning');
    }
    if (added > 0) {
        showToast(added + ' image(s) added to upload queue', 'success');
    }
}

function updateFilePreviewUI() {
    if (selectedFiles.length === 0) {
        filePreviewList.style.display = 'none';
        selectedCountBox.style.display = 'none';
        uploadBtn.disabled = true;
        clearBtn.disabled = true;
        filePreviewList.innerHTML = '';
        return;
    }
    
    filePreviewList.style.display = 'flex';
    selectedCountBox.style.display = 'block';
    uploadBtn.disabled = false;
    clearBtn.disabled = false;
    
    selectedCountText.textContent = selectedFiles.length + ' image' + (selectedFiles.length !== 1 ? 's' : '') + ' selected';
    
    filePreviewList.innerHTML = '';
    selectedFiles.forEach((file, index) => {
        const div = document.createElement('div');
        div.className = 'file-preview-item';
        
        const reader = new FileReader();
        reader.onload = function(e) {
            div.innerHTML = `
                <img src="${e.target.result}" alt="${file.name}">
                <button type="button" class="remove-file" onclick="removeFileFromQueue(${index})" title="Remove">
                    <i class="fas fa-times"></i>
                </button>
                <div class="file-size">${(file.size / 1024).toFixed(0)}KB</div>
            `;
        };
        reader.readAsDataURL(file);
        filePreviewList.appendChild(div);
    });
}

function removeFileFromQueue(index) {
    selectedFiles.splice(index, 1);
    updateFilePreviewUI();
}

function clearSelectedFiles() {
    selectedFiles = [];
    multiFileInput.value = '';
    filePreviewList.innerHTML = '';
    filePreviewList.style.display = 'none';
    selectedCountBox.style.display = 'none';
    uploadBtn.disabled = true;
    clearBtn.disabled = true;
    document.getElementById('uploadProgress').classList.remove('active');
}

// ============================================================
// MULTI-FILE UPLOAD — Actually Upload
// ============================================================
function uploadMultipleImages() {
    if (selectedFiles.length === 0) {
        showToast('Please select images first', 'warning');
        return;
    }
    
    const houseId = document.getElementById('gallery_house_id').value;
    if (!houseId) {
        showToast('House ID missing', 'error');
        return;
    }
    
    const progressDiv = document.getElementById('uploadProgress');
    const progressFill = document.getElementById('progressFill');
    const progressPercent = document.getElementById('progressPercent');
    const progressLabel = document.getElementById('progressLabel');
    
    progressDiv.classList.add('active');
    progressFill.style.width = '0%';
    progressPercent.textContent = '0%';
    progressLabel.textContent = 'Uploading ' + selectedFiles.length + ' image(s)...';
    
    uploadBtn.disabled = true;
    clearBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('ajax_upload_multiple', '1');
    formData.append('house_id', houseId);
    selectedFiles.forEach((file) => {
        formData.append('gallery_images[]', file);
    });
    
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            const percent = Math.round((e.loaded / e.total) * 100);
            progressFill.style.width = percent + '%';
            progressPercent.textContent = percent + '%';
            if (percent === 100) {
                progressLabel.textContent = 'Processing on server...';
            }
        }
    });
    
    xhr.addEventListener('load', function() {
        if (xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);
                
                if (data.success) {
                    progressFill.style.width = '100%';
                    progressPercent.textContent = '100%';
                    progressLabel.textContent = 'Upload complete!';
                    
                    showToast(data.message || 'Images uploaded!', 'success');
                    
                    clearSelectedFiles();
                    renderGallery(data.gallery, currentMainImage);
                    
                    setTimeout(() => {
                        document.querySelectorAll('.gallery-item').forEach((el, idx) => {
                            if (idx < data.uploaded_count) {
                                el.classList.add('newly-added');
                            }
                        });
                    }, 100);
                    
                    updateHouseCard(houseId, null, data.gallery_count);
                    
                    setTimeout(() => {
                        progressDiv.classList.remove('active');
                    }, 1500);
                    
                } else {
                    showToast(data.message || 'Upload failed', 'error');
                    progressDiv.classList.remove('active');
                    uploadBtn.disabled = false;
                    clearBtn.disabled = false;
                }
            } catch (e) {
                console.error('Parse error:', e, 'Response:', xhr.responseText);
                showToast('Server returned invalid response', 'error');
                progressDiv.classList.remove('active');
                uploadBtn.disabled = false;
                clearBtn.disabled = false;
            }
        } else {
            showToast('Upload failed (HTTP ' + xhr.status + ')', 'error');
            progressDiv.classList.remove('active');
            uploadBtn.disabled = false;
            clearBtn.disabled = false;
        }
    });
    
    xhr.addEventListener('error', function() {
        showToast('Network error during upload', 'error');
        progressDiv.classList.remove('active');
        uploadBtn.disabled = false;
        clearBtn.disabled = false;
    });
    
    xhr.open('POST', 'house-dashboard.php');
    xhr.send(formData);
}

// ============================================================
// DELETE GALLERY IMAGE
// ============================================================
function deleteGalleryImage(imgId) {
    if (!confirm('Permanently delete this image?\n\nThis CANNOT be undone.')) return;
    
    const item = document.getElementById('gallery-item-' + imgId);
    if (item) item.classList.add('deleting');
    
    fetch('house-dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_delete_gallery=1&img_id=' + imgId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || 'Image deleted permanently', 'success');
            currentMainImage = data.new_main;
            renderGallery(data.gallery, data.new_main);
            updateHouseCard(currentGalleryHouseId, data.new_main, data.gallery_count);
            
            if (data.gallery_count === 0) {
                setTimeout(() => {
                    showToast('All images removed. Gallery is now empty.', 'warning');
                }, 1200);
            }
        } else {
            showToast(data.message || 'Failed to delete image', 'error');
            if (item) item.classList.remove('deleting');
        }
    })
    .catch(error => {
        console.error('Delete error:', error);
        showToast('Network error. Please try again.', 'error');
        if (item) item.classList.remove('deleting');
    });
}

// ============================================================
// SET MAIN IMAGE
// ============================================================
function setMainImage(imgId) {
    fetch('house-dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_set_main=1&img_id=' + imgId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || 'Main image updated!', 'success');
            currentMainImage = data.new_main;
            renderGallery(data.gallery, data.new_main);
            updateHouseCard(currentGalleryHouseId, data.new_main);
        } else {
            showToast(data.message || 'Failed to update', 'error');
        }
    })
    .catch(error => {
        console.error('Set main error:', error);
        showToast('Network error. Please try again.', 'error');
    });
}

// ============================================================
// UPDATE HOUSE CARD (image + gallery count)
// ============================================================
function updateHouseCard(houseId, newMainImage, galleryCount) {
    const allRows = document.querySelectorAll('.manage-table tbody tr');
    allRows.forEach(row => {
        const editBtn = row.querySelector('.btn-action.btn-edit');
        if (!editBtn) return;
        try {
            const onclickAttr = editBtn.getAttribute('onclick');
            const match = onclickAttr.match(/editHouse\((\{.*?\})\)/);
            if (match) {
                const house = JSON.parse(match[1]);
                if (house.id == houseId) {
                    if (newMainImage && newMainImage !== 'default-house.jpg') {
                        const imgCell = row.querySelector('.image-cell img');
                        if (imgCell) {
                            imgCell.src = 'uploads/houses/gallery/' + newMainImage + '?v=' + Date.now();
                            imgCell.onerror = function() {
                                this.src = 'uploads/houses/' + newMainImage + '?v=' + Date.now();
                            };
                        } else {
                            const noImageDiv = row.querySelector('.image-cell .no-image');
                            if (noImageDiv) {
                                const newImg = document.createElement('img');
                                newImg.src = 'uploads/houses/gallery/' + newMainImage + '?v=' + Date.now();
                                newImg.alt = house.house_name;
                                newImg.onerror = function() {
                                    this.src = 'uploads/houses/' + newMainImage + '?v=' + Date.now();
                                };
                                noImageDiv.replaceWith(newImg);
                            }
                        }
                    }
                    
                    if (galleryCount !== undefined) {
                        const galleryBtn = row.querySelector('.btn-gallery-count');
                        if (galleryBtn) {
                            galleryBtn.innerHTML = '<i class="fas fa-images"></i> (' + galleryCount + ')';
                        }
                    }
                }
            }
        } catch(e) { console.error('Table update error:', e); }
    });

    const cards = document.querySelectorAll('.house-card');
    cards.forEach(card => {
        const editBtn = card.querySelector('.btn-edit-card');
        if (!editBtn) return;
        
        try {
            const onclickAttr = editBtn.getAttribute('onclick');
            const match = onclickAttr.match(/editHouse\((\{.*?\})\)/);
            if (match) {
                const house = JSON.parse(match[1]);
                if (house.id == houseId) {
                    if (newMainImage && newMainImage !== 'default-house.jpg') {
                        const imgWrapper = card.querySelector('.house-image-wrapper');
                        const oldImg = imgWrapper.querySelector('.house-image');
                        const placeholder = imgWrapper.querySelector('.house-image-placeholder');
                        const t = Date.now();
                        
                        const newImg = document.createElement('img');
                        newImg.className = 'house-image';
                        newImg.src = 'uploads/houses/gallery/' + newMainImage + '?v=' + t;
                        newImg.alt = house.house_name;
                        newImg.onerror = function() {
                            this.src = 'uploads/houses/' + newMainImage + '?v=' + t;
                        };
                        
                        if (oldImg) {
                            oldImg.replaceWith(newImg);
                        } else if (placeholder) {
                            placeholder.replaceWith(newImg);
                            newImg.style.display = 'block';
                        }
                    }
                    
                    if (galleryCount !== undefined) {
                        const galleryBadge = card.querySelector('.gallery-count-badge');
                        if (galleryBadge) {
                            galleryBadge.innerHTML = '<i class="fas fa-images"></i> ' + galleryCount;
                        } else if (galleryCount > 0) {
                            const wrapper = card.querySelector('.house-image-wrapper');
                            if (wrapper) {
                                const badge = document.createElement('span');
                                badge.className = 'gallery-count-badge';
                                badge.innerHTML = '<i class="fas fa-images"></i> ' + galleryCount;
                                wrapper.appendChild(badge);
                            }
                        }
                    }
                }
            }
        } catch(e) { console.error('Update card error:', e); }
    });
}

// ============================================================
// VALIDATION
// ============================================================
document.getElementById('editHouseForm').addEventListener('submit', function(e) {
    const removeChecked = document.getElementById('remove_image_checkbox').checked;
    const fileSelected = document.getElementById('edit_house_image').files.length > 0;
    
    if (removeChecked && fileSelected) {
        if (!confirm('You have BOTH:\n\n✓ Check "Remove" — current main image will be deleted\n✓ Selected new image to upload\n\nContinue?')) {
            e.preventDefault();
            return false;
        }
    } else if (removeChecked) {
        if (!confirm('Permanently delete the current main image?\n\nThis CANNOT be undone.')) {
            e.preventDefault();
            return false;
        }
    }
    
    return true;
});

// ============================================================
// MODAL CLOSE
// ============================================================
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(function(modal) {
            modal.classList.remove('show');
        });
        document.body.style.overflow = 'auto';
    }
});

// ============================================================
// LOGOUT CONFIRMATION MODAL
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

    setTimeout(function() {
        const cancelBtn = document.querySelector('.btn-logout-cancel');
        if (cancelBtn) cancelBtn.focus();
    }, 100);
}

function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('logoutModal');
        if (modal && modal.classList.contains('show')) {
            closeLogoutModal();
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeLogoutModal();
            }
        });
    }
});

// ============================================================
// AUTO-HIDE ALERTS
// ============================================================
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