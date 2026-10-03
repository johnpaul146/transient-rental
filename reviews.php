<?php
session_start();
require_once 'database.php';

// ✅ NEW: Load SystemLogger (safe — won't break if file missing)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// ============================================================
// GET DYNAMIC CONTENT (for hero background + logo)
// ============================================================
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {
    $content = [];
}

// Get logo path
$logo_path = 'uploads/logos/logo.png';
$logo_exists = file_exists('uploads/logos/logo.png');
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
    $logo_exists = file_exists($logo_path);
}

// Get homepage hero image path
$hero_path = 'uploads/hero/hero-bg.jpg';
$hero_exists = file_exists('uploads/hero/hero-bg.jpg');
if(isset($content['site_settings']['hero_image_path']) && !empty($content['site_settings']['hero_image_path'])) {
    $hero_path = $content['site_settings']['hero_image_path'];
    $hero_exists = file_exists($hero_path);
}

// Get footer info
$facebook_link = $content['social']['facebook'] ?? '#';
$location_address = $content['location']['address'] ?? $content['footer']['address'] ?? '123 Transient Street, City';

// ============================================================
// GET ALL FEEDBACK WITH USER INFO
// ============================================================
$all_feedback = $pdo->query("SELECT f.*, u.username 
                             FROM overall_feedback f 
                             JOIN users u ON f.user_id = u.id 
                             ORDER BY f.created_at DESC")->fetchAll();

// Get overall stats
$stats = $pdo->query("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM overall_feedback")->fetch();
$avg_rating = $stats['avg_rating'] ? round($stats['avg_rating'], 1) : 0;
$total_reviews = $stats['total'] ?? 0;

// Rating distribution
$rating_distribution = [];
for($i = 5; $i >= 1; $i--) {
    $count = $pdo->query("SELECT COUNT(*) FROM overall_feedback WHERE rating = $i")->fetchColumn();
    $percentage = $total_reviews > 0 ? round(($count / $total_reviews) * 100) : 0;
    $rating_distribution[$i] = ['count' => $count, 'percentage' => $percentage];
}

function renderTinyStars($rating) {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star" style="color: #f59e0b; font-size: 14px;"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color: #d1d5db; font-size: 14px;"></i>';
        }
    }
    return $html;
}

// ============================================================
// ✅ NEW: Log page view (once per session to avoid spam)
// ============================================================
if (class_exists('SystemLogger')) {
    $view_key = 'logged_view_reviews_php';
    if (empty($_SESSION[$view_key])) {
        SystemLogger::log(
            $pdo,
            'view',
            'review',
            "User '" . ($_SESSION['username'] ?? 'Guest') . "' viewed the public Reviews page" . ($total_reviews > 0 ? " ({$total_reviews} review" . ($total_reviews != 1 ? "s" : "") . " shown)" : " (no reviews yet)"),
            null,
            'page',
            null,
            [
                'page'          => 'reviews.php',
                'total_reviews' => (int)$total_reviews,
                'avg_rating'    => $avg_rating,
                'is_logged_in'  => isset($_SESSION['user_id']),
                'role'          => $_SESSION['role'] ?? 'guest'
            ]
        );
        $_SESSION[$view_key] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Reviews - Transient House & Tours</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/navbar.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
       body {
    min-height:100vh;
}

     

        /* ============================================================
           HERO BANNER - WITH HOMEPAGE PHOTO
           ============================================================ */
       .hero {
    <?php if($hero_exists): ?>
    background: linear-gradient(rgba(11,36,71,.55), rgba(11,36,71,.65)),
                url('<?php echo htmlspecialchars($hero_path); ?>?<?php echo time(); ?>');
    background-size: cover;
    background-position: center;
    <?php else: ?>
    background: linear-gradient(135deg,#0B2447,#0B3D91,#4DA6D9);
    <?php endif; ?>

    height: 420px;
    min-height: 420px;
    padding: 0;

    display:flex;
    align-items:center;
    justify-content:center;

    color:white;
    text-align:center;
    position:relative;
}
        
      .hero-content {
    max-width:800px;
    margin:0 auto;
    padding:80px 20px 0;
    position:relative;
    z-index:1;
}
        
        .hero h1 {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 0 2px 25px rgba(0,0,0,0.25);
        }
        
        .hero h1 i { color: #F4B400; }
        
        .hero p {
            font-size: 16px;
            margin-bottom: 30px;
            opacity: 0.95;
            text-shadow: 0 1px 15px rgba(0,0,0,0.15);
        }

        /* ============================================================
           MAIN CONTAINER
           ============================================================ */
        .main-container {
            max-width: 900px;
            margin: 30px auto 40px;
            padding: 0 20px;
        }

        /* ============================================================
           SUMMARY CARD
           ============================================================ */
        .summary-card {
            background: white;
            border-radius: 24px;
            padding: 35px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            border: 1px solid #e8f0fe;
            margin-bottom: 30px;
        }
        
        .summary-header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e8f0fe;
        }
        
        .summary-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: #0B2447;
            margin-bottom: 8px;
        }
        
        .summary-header h2 i { color: #F4B400; }
        
        .rating-summary {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .rating-summary .big-rating {
            font-size: 56px;
            font-weight: 700;
            color: #4DA6D9;
            line-height: 1;
        }
        
        .rating-summary .stars-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .rating-summary .stars-block .stars { font-size: 22px; }
        
        .rating-summary .stars-block .total-reviews {
            font-size: 13px;
            color: #64748b;
        }
        
        /* ============================================================
           RATING DISTRIBUTION
           ============================================================ */
        .rating-distribution {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .rating-distribution .bar-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .rating-distribution .bar-item .bar-label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            min-width: 35px;
        }
        
        .rating-distribution .bar-item .bar-track {
            flex: 1;
            height: 10px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            min-width: 100px;
        }
        
        .rating-distribution .bar-item .bar-track .bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #F4B400, #fbbf24);
            border-radius: 10px;
            transition: width 0.5s;
        }
        
        .rating-distribution .bar-item .bar-count {
            font-size: 13px;
            color: #64748b;
            min-width: 40px;
            text-align: right;
        }

        /* ============================================================
           REVIEWS LIST CARD
           ============================================================ */
        .reviews-card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            border: 1px solid #e8f0fe;
        }
        
        .reviews-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e8f0fe;
        }
        
        .reviews-card-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #0B2447;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        
        .reviews-card-header h3 i { color: #4DA6D9; }
        
        .reviews-card-header .count-badge {
            background: #eef2ff;
            color: #4DA6D9;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        /* ============================================================
           REVIEW ITEM
           ============================================================ */
        .review-item {
            padding: 20px 0;
            border-bottom: 1px solid #e8f0fe;
        }
        
        .review-item:last-child { border-bottom: none; padding-bottom: 0; }
        
        .review-item .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .review-item .review-user {
            font-weight: 600;
            color: #0B2447;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 15px;
        }
        
        .review-item .review-user .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 15px;
            font-weight: 700;
            flex-shrink: 0;
        }
        
        .review-item .review-user .avatar.anonymous-avatar {
            background: linear-gradient(135deg, #7c3aed, #a78bfa);
        }
        
        .review-item .review-user .anonymous-badge {
            font-size: 10px;
            color: #7c3aed;
            background: #f3e8ff;
            padding: 2px 10px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 4px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .review-item .review-user .anonymous-badge i { font-size: 9px; }
        
        .review-item .review-date {
            font-size: 12px;
            color: #94a3b8;
        }
        
        .review-item .review-stars {
            margin: 5px 0 8px 48px;
        }
        
        .review-item .review-stars i { font-size: 15px; }
        
        .review-item .review-text {
            color: #475569;
            font-size: 14px;
            line-height: 1.6;
            margin-left: 48px;
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 12px;
            border-left: 3px solid #4DA6D9;
            font-style: italic;
        }

        /* ============================================================
           NO REVIEWS STATE
           ============================================================ */
        .no-reviews {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        
        .no-reviews i {
            font-size: 60px;
            margin-bottom: 20px;
            color: #cbd5e1;
            display: block;
        }
        
        .no-reviews h3 {
            color: #64748b;
            font-size: 18px;
            margin-bottom: 8px;
        }
        
        .no-reviews p { font-size: 14px; }


        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .header-content { flex-direction: column; gap: 12px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 40px; width: 40px; }
            .brand-text .brand-name { font-size: 17px; }
            .brand-text .brand-tagline { font-size: 10px; }
            .nav-links { justify-content: center; gap: 4px; }
            .nav-links a { padding: 6px 12px; font-size: 12px; }
            
            .hero { padding: 60px 0; }
            .hero h1 { font-size: 32px; }
            .hero p { font-size: 16px; }
            
            .main-container { margin: 20px auto 30px; }
            .summary-card, .reviews-card { padding: 20px; border-radius: 16px; }
            .summary-header h2 { font-size: 20px; }
            
            .rating-summary { flex-direction: column; gap: 12px; }
            .rating-summary .big-rating { font-size: 44px; }
            
            .review-item .review-header { flex-direction: column; align-items: flex-start; gap: 5px; }
            .review-item .review-stars { margin-left: 48px; }
            .review-item .review-text { margin-left: 48px; }
        }
        
        @media (max-width: 480px) {
            .hero h1 { font-size: 26px; }
            .hero p { font-size: 14px; }
            
            .summary-card, .reviews-card { padding: 16px; border-radius: 14px; }
            .reviews-card-header { flex-direction: column; align-items: flex-start; gap: 8px; }
            
            .review-item .review-user { font-size: 14px; }
            .review-item .review-user .avatar { width: 34px; height: 34px; font-size: 13px; }
            .review-item .review-stars { margin-left: 44px; }
            .review-item .review-text { margin-left: 44px; padding: 10px 12px; font-size: 13px; }
        }
    </style>
</head>
<body>

<?php
$nav_active = 'home';
$nav_variant = 'floating';
include 'components/navbar.php';
?>

<!-- HERO -->
<div class="hero">
    <div class="hero-content">
        <h1><i class="fas fa-star"></i> Customer Reviews</h1>
        <p>Real feedback from our valued guests</p>
    </div>
</div>

<!-- MAIN CONTAINER -->
<div class="main-container">

    <?php if($total_reviews > 0): ?>
    <!-- SUMMARY CARD -->
    <div class="summary-card">
        <div class="summary-header">
            <h2><i class="fas fa-star"></i> Overall Rating</h2>
        </div>

        <div class="rating-summary">
            <div class="big-rating"><?php echo $avg_rating; ?></div>
            <div class="stars-block">
                <div class="stars"><?php echo renderTinyStars($avg_rating); ?></div>
                <div class="total-reviews">Based on <?php echo $total_reviews; ?> review<?php echo $total_reviews != 1 ? 's' : ''; ?></div>
            </div>
        </div>

        <div class="rating-distribution" style="margin-top: 25px;">
            <?php foreach($rating_distribution as $star => $data): ?>
            <div class="bar-item">
                <span class="bar-label"><?php echo $star; ?> ★</span>
                <div class="bar-track">
                    <div class="bar-fill" style="width: <?php echo $data['percentage']; ?>%;"></div>
                </div>
                <span class="bar-count"><?php echo $data['count']; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- REVIEWS LIST -->
    <div class="reviews-card">
        <div class="reviews-card-header">
            <h3><i class="fas fa-comments"></i> All Reviews</h3>
            <?php if($total_reviews > 0): ?>
                <span class="count-badge"><?php echo $total_reviews; ?> total</span>
            <?php endif; ?>
        </div>

        <?php if(count($all_feedback) > 0): ?>
            <?php foreach($all_feedback as $review): ?>
                <?php $is_anonymous = isset($review['is_anonymous']) && $review['is_anonymous'] == 1; ?>
                <div class="review-item">
                    <div class="review-header">
                        <div class="review-user">
                            <?php if($is_anonymous): ?>
                                <div class="avatar anonymous-avatar">
                                    <i class="fas fa-user-secret"></i>
                                </div>
                                <span>Anonymous Guest</span>
                                <span class="anonymous-badge"><i class="fas fa-user-secret"></i> Anonymous</span>
                            <?php else: ?>
                                <div class="avatar"><?php echo strtoupper(substr($review['username'], 0, 1)); ?></div>
                                <span><?php echo htmlspecialchars($review['username']); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="review-date"><i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                    </div>

                    <div class="review-stars"><?php echo renderTinyStars($review['rating']); ?></div>

                    <?php if($review['comment']): ?>
                        <div class="review-text">"<?php echo htmlspecialchars($review['comment']); ?>"</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-reviews">
                <i class="fas fa-comment-slash"></i>
                <h3>No Reviews Yet</h3>
                <p>Be the first to share your experience!</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- FOOTER -->
<?php include 'components/footer.php'; ?>

</body>
</html>