<?php
session_start();
require_once 'database.php';
require_once 'includes/TermsGate.php';
TermsGate::enforceGuest($pdo, true); // Terms & Privacy must be accepted before guest features

if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

require_once 'includes/TermsGate.php';
$termsGate = new TermsGate($pdo);

if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/* ------------------------------------------------------------
   SAFE QUERY HELPERS (avoid fatal errors on missing tables)
   ------------------------------------------------------------ */
function safe_query_value(PDO $pdo, string $sql, $default = 0) {
    try { return $pdo->query($sql)->fetchColumn() ?? $default; }
    catch (PDOException $e) { return $default; }
}
function safe_query_rows(PDO $pdo, string $sql): array {
    try { $rows = $pdo->query($sql)->fetchAll(); return is_array($rows) ? $rows : []; }
    catch (PDOException $e) { return []; }
}

/* ------------------------------------------------------------
   DYNAMIC CONTENT
   ------------------------------------------------------------ */
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while ($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch (PDOException $e) { $content = []; }

$logo_path = $content['site_settings']['logo_path'] ?? 'uploads/logos/logo.png';
$hero_path = $content['site_settings']['hero_image_path'] ?? 'uploads/hero/hero-bg.jpg';
$hero_exists = !empty($hero_path) && file_exists($hero_path) && !is_dir($hero_path);

/* ------------------------------------------------------------
   COUNTS
   ------------------------------------------------------------ */
$houses_count      = (int) safe_query_value($pdo, "SELECT COUNT(*) FROM houses");
$tours_count       = (int) safe_query_value($pdo, "SELECT COUNT(*) FROM tours");
$available_houses  = (int) safe_query_value($pdo, "SELECT COUNT(*) FROM houses WHERE status = 'available'");
$available_tours   = (int) safe_query_value($pdo, "SELECT COUNT(*) FROM tours WHERE status = 'available'");
$activities_count  = (int) safe_query_value($pdo, "SELECT COUNT(*) FROM activities");
$food_count        = (int) safe_query_value($pdo, "SELECT COUNT(*) FROM food_items");

/* ------------------------------------------------------------
   FEATURED ITEMS
   ------------------------------------------------------------ */
$featured_activities = safe_query_rows($pdo,
    "SELECT * FROM activities WHERE is_featured = 1 AND status = 'available' ORDER BY id LIMIT 3");

$featured_food = safe_query_rows($pdo,
    "SELECT * FROM food_items WHERE is_featured = 1 AND is_available = 1 ORDER BY id LIMIT 3");

$featured_houses = safe_query_rows($pdo,
    "SELECT * FROM houses WHERE status = 'available' ORDER BY id DESC LIMIT 6");

$featured_tours = safe_query_rows($pdo,
    "SELECT * FROM tours WHERE status = 'available' ORDER BY id DESC LIMIT 3");

/* House galleries */
$featured_house_gallery = [];
foreach ($featured_houses as $fh) {
    try {
        $gStmt = $pdo->prepare("SELECT * FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC LIMIT 1");
        $gStmt->execute([$fh['id']]);
        $featured_house_gallery[$fh['id']] = $gStmt->fetchAll();
    } catch (PDOException $e) {
        $featured_house_gallery[$fh['id']] = [];
    }
}

/* Tour galleries */
$featured_tour_gallery = [];
foreach ($featured_tours as $ft) {
    $folder = !empty($ft['folder_name'])
        ? $ft['folder_name']
        : str_replace(' ', '_', trim($ft['tour_name']));
    $path = 'uploads/tours/gallery/' . $folder . '/';
    $imgs = [];
    if (is_dir($path)) {
        foreach (scandir($path) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $imgs[] = ['image' => $file, 'folder' => $folder];
            }
        }
    }
    $featured_tour_gallery[$ft['id']] = $imgs;
}

/* ------------------------------------------------------------
   TERMS ACCEPTANCE
   ------------------------------------------------------------ */
if (isset($_POST['accept_terms']) && isset($_SESSION['user_id'])) {
    $termsGate->accept((int)$_SESSION['user_id'], getClientIp());
    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'accept', 'terms',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' accepted Terms & Privacy Policy (v" . $termsGate->getCurrentVersion() . ")",
            (int)$_SESSION['user_id'], 'user', null,
            ['version' => $termsGate->getCurrentVersion(), 'ip' => getClientIp()]);
    }
    unset($_SESSION['show_terms_modal']);
    $_SESSION['terms_accepted'] = true;
    header("Location: index.php?terms_accepted=1");
    exit();
}

$show_registration_success = isset($_GET['registered']) && $_GET['registered'] == 1;

/* ------------------------------------------------------------
   OVERALL FEEDBACK
   ------------------------------------------------------------ */
if (isset($_POST['submit_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $rating       = (int)$_POST['rating'];
        $comment      = $_POST['comment'] ?? '';
        $user_id      = (int)$_SESSION['user_id'];
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        $check = $pdo->prepare("SELECT id FROM overall_feedback WHERE user_id = ?");
        $check->execute([$user_id]);
        $is_update = (bool)$check->fetch();

        if ($is_update) {
            $stmt = $pdo->prepare("UPDATE overall_feedback SET rating = ?, comment = ?, updated_at = NOW(), is_anonymous = ? WHERE user_id = ?");
            $stmt->execute([$rating, $comment, $is_anonymous, $user_id]);
            $feedback_success = "Thank you! Your feedback has been updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO overall_feedback (user_id, rating, comment, is_anonymous) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $rating, $comment, $is_anonymous]);
            $feedback_success = "Thank you for your feedback! Your review has been submitted.";
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, $is_update ? 'update' : 'create', 'review',
                "User '" . ($_SESSION['username'] ?? 'Unknown') . "' " . ($is_update ? "updated" : "submitted") . " overall review — Rating: {$rating}/5",
                null, 'overall_feedback', null,
                ['rating' => $rating, 'anonymous' => $is_anonymous, 'has_comment' => !empty($comment)]);
        }

        header("Location: index.php?feedback_success=1");
        exit();
    } catch (Exception $e) {
        $feedback_error = "Failed to submit feedback: " . $e->getMessage();
    }
}

if (isset($_GET['delete_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $user_id = (int)$_SESSION['user_id'];
        $log_stmt = $pdo->prepare("SELECT rating, comment FROM overall_feedback WHERE user_id = ?");
        $log_stmt->execute([$user_id]);
        $old_feedback = $log_stmt->fetch();

        $pdo->prepare("DELETE FROM overall_feedback WHERE user_id = ?")->execute([$user_id]);
        $feedback_success = "Your review has been deleted successfully.";

        if (class_exists('SystemLogger') && $old_feedback) {
            SystemLogger::log($pdo, 'delete', 'review',
                "User '" . ($_SESSION['username'] ?? 'Unknown') . "' deleted own overall review",
                $user_id, 'user', null, null, 'warning');
        }
        header("Location: index.php?feedback_success=1");
        exit();
    } catch (Exception $e) {
        $feedback_error = "Failed to delete review: " . $e->getMessage();
    }
}

/* ------------------------------------------------------------
   REVIEWS DATA
   ------------------------------------------------------------ */
$rating_filter = $_GET['rating_filter'] ?? 'all';

$overall_stats = ['avg_rating' => 0, 'total_reviews' => 0];
try {
    $row = $pdo->query("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM overall_feedback")->fetch();
    if ($row) $overall_stats = $row;
} catch (PDOException $e) {}

$avg_rating    = $overall_stats['avg_rating'] ? round((float)$overall_stats['avg_rating'], 1) : 0;
$total_reviews = (int)($overall_stats['total_reviews'] ?? 0);

if ($rating_filter !== 'all') {
    try {
        $stmt = $pdo->prepare("SELECT f.*, u.username FROM overall_feedback f JOIN users u ON f.user_id = u.id WHERE f.rating = ? ORDER BY f.created_at DESC LIMIT 5");
        $stmt->execute([$rating_filter]);
        $all_feedback = $stmt->fetchAll();
    } catch (PDOException $e) { $all_feedback = []; }
} else {
    $all_feedback = safe_query_rows($pdo,
        "SELECT f.*, u.username FROM overall_feedback f JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC LIMIT 5");
}

$total_feedback_count = (int) safe_query_value($pdo, "SELECT COUNT(*) FROM overall_feedback");

/* ------------------------------------------------------------
   CURRENT USER FEEDBACK
   ------------------------------------------------------------ */
$user_has_feedback = false;
$user_feedback     = null;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM overall_feedback WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_feedback = $stmt->fetch();
        $user_has_feedback = ($user_feedback !== false);
    } catch (PDOException $e) {}
}

/* ------------------------------------------------------------
   HELPERS
   ------------------------------------------------------------ */
function renderStars($rating) {
    $html = '';
    $full = floor($rating);
    $half = ($rating - $full) >= 0.5;
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $full)        $html .= '<i class="fas fa-star" style="color:#f59e0b;"></i>';
        elseif ($i == $full + 1 && $half) $html .= '<i class="fas fa-star-half-alt" style="color:#f59e0b;"></i>';
        else                    $html .= '<i class="far fa-star" style="color:#f59e0b;"></i>';
    }
    return $html;
}
function renderTinyStars($rating) {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= ($i <= $rating)
            ? '<i class="fas fa-star" style="color:#f59e0b; font-size:11px;"></i>'
            : '<i class="far fa-star" style="color:#d1d5db; font-size:11px;"></i>';
    }
    return $html;
}
function displayUsername($username, $is_anonymous = 0) {
    return $is_anonymous == 1 ? '***' : htmlspecialchars($username);
}
function getGoogleMapsUrl($address) {
    if (empty($address) || $address === '#') return '#';
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address);
}

/* ------------------------------------------------------------
   SOCIAL / LOCATION
   ------------------------------------------------------------ */
$facebook_link     = $content['social']['facebook'] ?? '#';
$location_address  = $content['location']['address'] ?? $content['footer']['address'] ?? '123 Transient Street, City';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';
$maps_url          = getGoogleMapsUrl($location_address);
$default_map_url   = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3863!2d119!3d16!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0!2z!5e0!3m2!1sen!2sph!4v1';
$map_embed         = !empty($google_maps_embed) && $google_maps_embed !== '#' ? $google_maps_embed : $default_map_url;

/* ------------------------------------------------------------
   TERMS STATE
   ------------------------------------------------------------ */
$force_must_accept = false;
if (!empty($_SESSION['show_terms_modal'])) {
    $force_must_accept = true;
}
if (!$force_must_accept
    && isset($_SESSION['user_id'])
    && ($_SESSION['role'] ?? '') === 'guest') {
    if (!$termsGate->hasAccepted((int)$_SESSION['user_id'])) {
        $force_must_accept = true;
    }
}

$termsContent = $termsGate->getContent();
$termsVersion = $termsGate->getCurrentVersion();

/* ------------------------------------------------------------
   LOGO + LOGIN
   ------------------------------------------------------------ */
$sidebar_logo = $logo_path;
$sidebar_logo_exists = !empty($sidebar_logo) && file_exists($sidebar_logo) && !is_dir($sidebar_logo);
$is_logged_in = isset($_SESSION['user_id']);

$nav_active = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#06263D">
    <title>Transient House &amp; Tours — Hundred Islands, Alaminos</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
</head>
<body>

<?php include 'components/navbar.php'; ?>

<!-- ============================================================
     HERO
     ============================================================ -->
<section class="hero">
    <div class="hero__bg"
         <?php if ($hero_exists): ?>
             style="background-image: url('<?php echo htmlspecialchars($hero_path); ?>?v=<?php echo time(); ?>');"
         <?php endif; ?>></div>

    <div class="container">
        <div class="hero__inner">

            <div class="hero__content">
                <span class="hero__eyebrow">
                    <i class="fas fa-map-marker-alt"></i>
                    Hundred Islands &bull; Alaminos, Pangasinan
                </span>

                <h1><?php echo htmlspecialchars($content['hero']['title'] ?? 'Welcome to Transient House & Tours'); ?></h1>

                <p class="hero__subtitle">
                    <?php echo htmlspecialchars($content['hero']['subtitle'] ?? 'Your home away from home and gateway to unforgettable island adventures.'); ?>
                </p>

                <div class="hero__actions">
                    <a href="houses.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-bed"></i> Find a place to stay
                    </a>
                    <a href="packages.php" class="btn btn-ghost-light btn-lg">
                        <i class="fas fa-suitcase"></i> Build your package
                    </a>
                </div>

                <div class="hero__trust">
                    <span class="trust-item"><i class="fas fa-headset"></i> Local booking support</span>
                    <span class="trust-item"><i class="fas fa-shield-alt"></i> Clean booking records</span>
                    <span class="trust-item"><i class="fas fa-star"></i> <?php echo $avg_rating > 0 ? $avg_rating : '4.8'; ?> rating from travelers</span>
                </div>
            </div>

            <aside class="hero__planner" aria-label="Plan your island getaway">
                <div class="planner-eyebrow">Start here</div>
                <h3>Plan your island getaway</h3>
                <p class="planner-sub">Choose what you need and continue directly to the right booking page.</p>

                <div class="planner-options">
                    <a href="houses.php" class="planner-option">
                        <span class="planner-option__icon"><i class="fas fa-bed"></i></span>
                        <span class="planner-option__text">
                            <span class="planner-option__title">Book a stay</span>
                            <span class="planner-option__desc"><?php echo (int)$available_houses; ?> houses currently available</span>
                        </span>
                        <i class="fas fa-arrow-right planner-option__arrow"></i>
                    </a>

                    <a href="tours.php" class="planner-option">
                        <span class="planner-option__icon"><i class="fas fa-ship"></i></span>
                        <span class="planner-option__text">
                            <span class="planner-option__title">Explore island tours</span>
                            <span class="planner-option__desc"><?php echo (int)$available_tours; ?> tour options available</span>
                        </span>
                        <i class="fas fa-arrow-right planner-option__arrow"></i>
                    </a>

                    <a href="activities.php" class="planner-option">
                        <span class="planner-option__icon"><i class="fas fa-water"></i></span>
                        <span class="planner-option__text">
                            <span class="planner-option__title">Add activities</span>
                            <span class="planner-option__desc">Water and adventure experiences</span>
                        </span>
                        <i class="fas fa-arrow-right planner-option__arrow"></i>
                    </a>

                    <a href="food.php" class="planner-option">
                        <span class="planner-option__icon"><i class="fas fa-utensils"></i></span>
                        <span class="planner-option__text">
                            <span class="planner-option__title">Order local food</span>
                            <span class="planner-option__desc">Meals and group food packages</span>
                        </span>
                        <i class="fas fa-arrow-right planner-option__arrow"></i>
                    </a>
                </div>
            </aside>

        </div>
    </div>
</section>

<!-- ============================================================
     EXPERIENCE
     ============================================================ -->

     <section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">One place to plan it all</span>
            <h2>One place to plan the whole experience</h2>
            <p>
                From where you sleep to what you eat — everything for your Hundred Islands trip,
                in one clean booking flow.
            </p>
        </div>

        <div class="experience-grid">

            <!-- Stay -->
            <a href="houses.php" class="experience-card">
                <img class="experience-card__img"
                     src="uploads/experience/stay.jpg"
                     alt="Stay"
                     loading="lazy">

                <div class="experience-card__overlay"></div>

                <div class="experience-card__body">
                    <span class="experience-card__icon">
                        <i class="fas fa-bed"></i>
                    </span>

                    <h3 class="experience-card__title">Stay</h3>

                    <p class="experience-card__desc">
                        Comfortable transient houses a few minutes from Lucap Wharf.
                    </p>

                    <span class="experience-card__arrow">
                        Explore stays <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>


            <!-- Island Tours -->
            <a href="tours.php" class="experience-card">
                <img class="experience-card__img"
                     src="uploads/experience/tours.jpg"
                     alt="Island Tours"
                     loading="lazy">

                <div class="experience-card__overlay"></div>

                <div class="experience-card__body">
                    <span class="experience-card__icon">
                        <i class="fas fa-ship"></i>
                    </span>

                    <h3 class="experience-card__title">Island Tours</h3>

                    <p class="experience-card__desc">
                        Boat tours to Governor's, Quezon, Marcos, and Children's Islands.
                    </p>

                    <span class="experience-card__arrow">
                        See tours <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>


            <!-- Activities -->
            <a href="activities.php" class="experience-card">
                <img class="experience-card__img"
                     src="uploads/experience/activities.jpg"
                     alt="Activities"
                     loading="lazy">

                <div class="experience-card__overlay"></div>

                <div class="experience-card__body">
                    <span class="experience-card__icon">
                        <i class="fas fa-water"></i>
                    </span>

                    <h3 class="experience-card__title">Activities</h3>

                    <p class="experience-card__desc">
                        Snorkeling, kayaking, banana boat rides, and island hopping.
                    </p>

                    <span class="experience-card__arrow">
                        Add activities <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>


            <!-- Food -->
            <a href="food.php" class="experience-card">
                <img class="experience-card__img"
                     src="uploads/experience/food.jpg"
                     alt="Food"
                     loading="lazy">

                <div class="experience-card__overlay"></div>

                <div class="experience-card__body">
                    <span class="experience-card__icon">
                        <i class="fas fa-utensils"></i>
                    </span>

                    <h3 class="experience-card__title">Food</h3>

                    <p class="experience-card__desc">
                        Boodle fights, seafood platters, and local Pangasinan dishes.
                    </p>

                    <span class="experience-card__arrow">
                        View menu <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </a>

        </div>
    </div>
</section>

<!-- ============================================================
     HOUSES
     ============================================================ -->
<section class="section section-tight" style="background: var(--color-white);">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">Comfortable bases</span>
            <h2>Comfortable bases for your island trip</h2>
            <p>Handpicked transient houses with the essentials you need.</p>
        </div>

        <?php if (!empty($featured_houses)): ?>
            <div class="grid grid-auto-3">
                <?php foreach ($featured_houses as $house):
                    $house_gallery = $featured_house_gallery[$house['id']] ?? [];
                    include 'components/card-house.php';
                endforeach; ?>
            </div>

            <div class="text-center" style="margin-top: var(--space-8);">
                <a href="houses.php" class="btn btn-ocean btn-lg">
                    Browse all houses <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php else: ?>
            <div class="empty-state card card-body text-center">
                <i class="fas fa-home"></i>
                <p>No houses available yet. Please check back later.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================================
     TOURS
     ============================================================ -->
<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">Popular island tours</span>
            <h2>Popular island tours</h2>
            <p>Hop on a boat and discover why travelers keep coming back.</p>
        </div>

        <?php if (!empty($featured_tours)): ?>
            <div class="grid grid-auto-3">
                <?php foreach ($featured_tours as $tour):
                    $tour_gallery = $featured_tour_gallery[$tour['id']] ?? [];
                    include 'components/card-tour.php';
                endforeach; ?>
            </div>

            <div class="text-center" style="margin-top: var(--space-8);">
                <a href="tours.php" class="btn btn-ocean btn-lg">
                    Explore all tours <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php else: ?>
            <div class="empty-state card card-body text-center">
                <i class="fas fa-ship"></i>
                <p>No tours available yet. Please check back later.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================================
     ACTIVITIES + FOOD
     ============================================================ -->
<section class="section section-tight" style="background: var(--color-white);">
    <div class="container">

        <div class="section-head left" style="margin-bottom: var(--space-6);">
            <span class="eyebrow">Things to do</span>
            <h2>Featured activities</h2>
        </div>

        <?php if (!empty($featured_activities)): ?>
            <div class="grid grid-auto-3">
                <?php foreach ($featured_activities as $activity):
                    include 'components/card-activity.php';
                endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state card card-body text-center" style="margin-bottom: var(--space-8);">
                <i class="fas fa-water"></i>
                <p>No featured activities yet.</p>
            </div>
        <?php endif; ?>

        <div class="section-head left" style="margin-top: var(--space-10); margin-bottom: var(--space-6);">
            <span class="eyebrow">Local flavors</span>
            <h2>Featured food</h2>
        </div>

        <?php if (!empty($featured_food)): ?>
            <div class="grid grid-auto-3">
                <?php foreach ($featured_food as $food):
                    $foodImage = null;
                    if (!empty($food['image']) && $food['image'] !== 'default-food.jpg') {
                        $candidate = 'uploads/foods/' . $food['image'];
                        if (file_exists($candidate)) $foodImage = $candidate;
                    }
                ?>
                <article class="listing-card card-hover" onclick="window.location.href='food.php'">
                    <div class="listing-card__media">
                        <?php if ($foodImage): ?>
                            <img src="<?php echo htmlspecialchars($foodImage); ?>?v=<?php echo time(); ?>"
                                 alt="<?php echo htmlspecialchars($food['name']); ?>"
                                 loading="lazy"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="listing-card__placeholder" style="display:none;">
                                <i class="fas fa-utensils"></i>
                            </div>
                        <?php else: ?>
                            <div class="listing-card__placeholder"><i class="fas fa-utensils"></i></div>
                        <?php endif; ?>

                        <div class="listing-card__badges">
                            <span class="badge badge-glass">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $food['category'] ?? 'Food'))); ?>
                            </span>
                        </div>
                    </div>

                    <div class="listing-card__body">
                        <h3 class="listing-card__title"><?php echo htmlspecialchars($food['name']); ?></h3>

                        <?php if (!empty($food['description'])): ?>
                            <p class="listing-card__desc"><?php echo htmlspecialchars($food['description']); ?></p>
                        <?php endif; ?>

                        <div class="listing-card__footer">
                            <div class="listing-card__price">₱<?php echo number_format((float)$food['price']); ?></div>
                            <span class="listing-card__cta">View menu <i class="fas fa-arrow-right"></i></span>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state card card-body text-center">
                <i class="fas fa-utensils"></i>
                <p>No featured food yet.</p>
            </div>
        <?php endif; ?>

        <div class="text-center" style="margin-top: var(--space-8);">
            <a href="activities.php" class="btn btn-ocean">
                <i class="fas fa-water"></i> See all activities
            </a>
            <a href="food.php" class="btn btn-ghost">
                <i class="fas fa-utensils"></i> View food menu
            </a>
        </div>
    </div>
</section>

<!-- ============================================================
     REVIEWS
     ============================================================ -->
<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">What travelers say</span>
            <h2>What our guests say</h2>
        </div>

        <div class="reviews-layout">
            <div class="reviews-summary">
                <div class="reviews-summary__score">
                    <?php echo $avg_rating > 0 ? number_format($avg_rating, 1) : '—'; ?>
                </div>
                <div class="reviews-summary__stars">
                    <?php
                    for ($i = 1; $i <= 5; $i++) {
                        if ($i <= floor($avg_rating)) {
                            echo '<i class="fas fa-star"></i>';
                        } elseif ($i == floor($avg_rating) + 1 && ($avg_rating - floor($avg_rating)) >= 0.5) {
                            echo '<i class="fas fa-star-half-alt"></i>';
                        } else {
                            echo '<i class="far fa-star"></i>';
                        }
                    }
                    ?>
                </div>
                <div class="reviews-summary__label">
                    Based on <?php echo (int)$total_reviews; ?> traveler review<?php echo $total_reviews != 1 ? 's' : ''; ?>
                </div>
                <a href="reviews.php" class="reviews-summary__btn">
                    <i class="fas fa-comments"></i> Read all reviews
                </a>
            </div>

            <div class="reviews-list">
                <?php if (!empty($all_feedback)): ?>
                    <?php foreach ($all_feedback as $review):
                        $is_anon = !empty($review['is_anonymous']);
                    ?>
                        <article class="review-card">
                            <div class="review-card__header">
                                <div class="review-card__user">
                                    <div class="review-card__avatar">
                                        <?php if ($is_anon): ?>
                                            <i class="fas fa-user-secret"></i>
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($review['username'] ?? 'U', 0, 1)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="review-card__name">
                                            <?php echo $is_anon ? 'Anonymous' : htmlspecialchars($review['username']); ?>
                                        </div>
                                        <div class="review-card__date">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="stars"><?php echo renderTinyStars((int)$review['rating']); ?></div>
                            </div>

                            <?php if (!empty($review['comment'])): ?>
                                <p class="review-card__comment">
                                    &ldquo;<?php echo htmlspecialchars($review['comment']); ?>&rdquo;
                                </p>
                            <?php else: ?>
                                <p class="review-card__comment text-muted" style="font-style: normal;">No comment provided.</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card card-body text-center">
                        <i class="fas fa-comment-slash" style="font-size: 40px; color: var(--color-text-muted); margin-bottom: 12px; display: block;"></i>
                        <p class="text-soft">No reviews yet. Be the first to share your experience.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     CTA BANNER
     ============================================================ -->
<section class="section section-tight">
    <div class="container">
        <div class="cta-banner">
            <div class="cta-banner__bg"
                 <?php if ($hero_exists): ?>
                     style="background-image: url('<?php echo htmlspecialchars($hero_path); ?>?v=<?php echo time(); ?>');"
                 <?php endif; ?>></div>

            <h2><?php echo htmlspecialchars($content['cta']['title'] ?? 'Ready to Book Your Stay?'); ?></h2>
            <p><?php echo htmlspecialchars($content['cta']['subtitle'] ?? 'Choose from our comfortable houses or exciting tour packages.'); ?></p>

            <div class="cta-banner__actions">
                <a href="houses.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-home"></i>
                    View Houses
                </a>
                <a href="packages.php" class="btn btn-ghost-light btn-lg">
                    <i class="fas fa-box-open"></i> Build a package
                </a>
            </div>
        </div>
    </div>
</section>

<?php include 'components/footer.php'; ?>

<?php include 'includes/terms-modal.php'; /* Terms & Privacy acceptance (reuses TermsGate state) */ ?>

<!-- ============================================================
     ALERTS
     ============================================================ -->
<?php if ($show_registration_success): ?>
    <div class="toast-stack" id="toastStack">
        <div class="toast toast--success">
            <i class="fas fa-check-circle"></i>
            <div><strong>Welcome!</strong><p>Registration successful. You are now logged in.</p></div>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($feedback_success) || isset($_GET['feedback_success'])): ?>
    <div class="toast-stack" id="toastStack">
        <div class="toast toast--success">
            <i class="fas fa-check-circle"></i>
            <div><strong>Thank you!</strong><p><?php echo htmlspecialchars($feedback_success ?? 'Your feedback has been recorded.'); ?></p></div>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($feedback_error)): ?>
    <div class="toast-stack" id="toastStack">
        <div class="toast toast--danger">
            <i class="fas fa-exclamation-circle"></i>
            <div><strong>Error</strong><p><?php echo htmlspecialchars($feedback_error); ?></p></div>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================
     LOGOUT MODAL
     ============================================================ -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon"><i class="fas fa-sign-out-alt"></i></div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="logout.php" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<!-- ============================================================
     OVERALL FEEDBACK MODAL
     ============================================================ -->
<div class="modal" id="overallFeedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-star" style="color: #f59e0b;"></i>
                <?php echo $user_has_feedback ? 'Edit Your Review' : 'Rate Your Experience'; ?>
            </h3>
            <span class="close" onclick="closeOverallFeedbackModal()">&times;</span>
        </div>

        <div id="overallFeedbackContent">
            <?php if (isset($_SESSION['user_id'])): ?>
                <form method="POST" id="feedbackForm">
                    <input type="hidden" name="rating" id="feedback_rating" value="<?php echo $user_has_feedback ? (int)$user_feedback['rating'] : 0; ?>" required>

                    <div style="margin-bottom: 15px;">
                        <label style="display:block; text-align:center; font-weight:600; color:#06263D; margin-bottom:10px;">Your Overall Rating</label>
                        <div class="rating-input" id="ratingInput" style="display:flex; justify-content:center; gap:8px; font-size:32px; cursor:pointer;">
                            <i class="fas fa-star" data-rating="1" onclick="setRating(1)"></i>
                            <i class="fas fa-star" data-rating="2" onclick="setRating(2)"></i>
                            <i class="fas fa-star" data-rating="3" onclick="setRating(3)"></i>
                            <i class="fas fa-star" data-rating="4" onclick="setRating(4)"></i>
                            <i class="fas fa-star" data-rating="5" onclick="setRating(5)"></i>
                        </div>
                        <p id="ratingText" style="text-align:center; color:#4a6a8c; margin-top:5px;">
                            <?php
                            if ($user_has_feedback && $user_feedback['rating'] > 0) {
                                $rt = [1=>'Very Poor',2=>'Poor',3=>'Average',4=>'Good',5=>'Excellent!'];
                                echo $rt[$user_feedback['rating']] ?? 'Select a rating';
                            } else { echo 'Select a rating'; }
                            ?>
                        </p>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:8px; font-weight:600; color:#06263D;">Your Comment</label>
                        <textarea name="comment" class="form-control" rows="3" placeholder="Share your overall experience..."><?php echo $user_has_feedback ? htmlspecialchars($user_feedback['comment']) : ''; ?></textarea>
                    </div>

                    <div style="margin-bottom:15px; display:flex; align-items:center; gap:10px; padding:10px 15px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                        <input type="checkbox" name="is_anonymous" id="feedback_is_anonymous" value="1" <?php echo ($user_has_feedback && !empty($user_feedback['is_anonymous'])) ? 'checked' : ''; ?>>
                        <label for="feedback_is_anonymous" style="margin-bottom:0; cursor:pointer; font-weight:500; color:#1e293b;">
                            <i class="fas fa-user-secret" style="color:#7c3aed;"></i> Post as Anonymous
                        </label>
                    </div>

                    <button type="submit" name="submit_feedback" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane"></i>
                        <?php echo $user_has_feedback ? 'Update Review' : 'Submit Review'; ?>
                    </button>
                </form>
            <?php else: ?>
                <div style="text-align:center; padding:30px; color:#4a6a8c;">
                    <i class="fas fa-lock" style="font-size:48px; margin-bottom:15px; color:#0B7CC1;"></i>
                    <p>Please <a href="login.php" style="color:#0B7CC1; font-weight:600;">login</a> to leave a review.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
/* Logout modal */
function openLogoutModal(e) {
    if (e) e.preventDefault();
    var m = document.getElementById('logoutModal');
    if (m) { m.classList.add('show'); document.body.style.overflow = 'hidden'; }
}
function closeLogoutModal() {
    var m = document.getElementById('logoutModal');
    if (m) { m.classList.remove('show'); document.body.style.overflow = 'auto'; }
}
document.addEventListener('DOMContentLoaded', function(){
    var m = document.getElementById('logoutModal');
    if (m) m.addEventListener('click', function(e){ if (e.target === this) closeLogoutModal(); });
});

/* Overall feedback modal */
function openOverallFeedbackModal(e) {
    if (e) e.preventDefault();
    var m = document.getElementById('overallFeedbackModal');
    if (m) { m.classList.add('show'); document.body.style.overflow = 'hidden'; }
}
function closeOverallFeedbackModal() {
    var m = document.getElementById('overallFeedbackModal');
    if (m) { m.classList.remove('show'); document.body.style.overflow = 'auto'; }
}

var selectedRating = <?php echo $user_has_feedback ? (int)$user_feedback['rating'] : 0; ?>;
var ratingTexts = { 1:'Very Poor', 2:'Poor', 3:'Average', 4:'Good', 5:'Excellent!' };

function setRating(rating) {
    selectedRating = rating;
    var input = document.getElementById('feedback_rating');
    if (input) input.value = rating;
    var txt = document.getElementById('ratingText');
    if (txt) txt.textContent = ratingTexts[rating] || 'Select a rating';
    document.querySelectorAll('#ratingInput i').forEach(function(star, i){
        star.style.color = i < rating ? '#f59e0b' : '#d4dce4';
    });
}
document.addEventListener('DOMContentLoaded', function(){ if (selectedRating > 0) setRating(selectedRating); });

/* Toast auto-dismiss */
setTimeout(function() {
    var stack = document.getElementById('toastStack');
    if (stack) { stack.style.transition = 'opacity 0.4s'; stack.style.opacity = '0'; setTimeout(function(){ stack.remove(); }, 400); }
}, 4500);
</script>

</body>
</html>