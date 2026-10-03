<?php
/**
 * components/card-activity.php
 *
 * Expects:
 *   $activity (array)
 */

if (!isset($activity) || !is_array($activity)) {
    return;
}

$status = $activity['status'] ?? 'available';
$icon     = !empty($activity['icon']) ? $activity['icon'] : 'fas fa-water';
$imgPath  = null;
if (!empty($activity['image']) && $activity['image'] !== 'default-activity.jpg') {
    $candidate = 'uploads/activities/' . $activity['image'];
    if (file_exists($candidate)) $imgPath = $candidate;
}
?>
<article class="listing-card card-hover activity-card" onclick="openShopeeView(<?php echo (int)$activity['id']; ?>)">

    <div class="listing-card__media activity-card__media">
        <?php if ($imgPath): ?>
            <img src="<?php echo htmlspecialchars($imgPath); ?>?v=<?php echo time(); ?>"
                 alt="<?php echo htmlspecialchars($activity['name']); ?>"
                 loading="lazy"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="listing-card__placeholder" style="display:none;">
                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
            </div>
        <?php else: ?>
            <div class="listing-card__placeholder">
                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
            </div>
        <?php endif; ?>

        <div class="listing-card__badges">
            <span class="badge badge-glass">
                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($activity['category'] ?? 'Activity'); ?>
            </span>
            <?php if (!empty($activity['is_featured'])): ?>
                <span class="badge badge-gold"><i class="fas fa-star"></i> Popular</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="listing-card__body">
        <h3 class="listing-card__title"><?php echo htmlspecialchars($activity['name']); ?></h3>

        <?php if (!empty($activity['description'])): ?>
            <p class="listing-card__desc"><?php echo htmlspecialchars($activity['description']); ?></p>
        <?php endif; ?>

        <div class="listing-card__footer">
            <?php if (!empty($activity['price']) && (float)$activity['price'] > 0): ?>
                <div class="listing-card__price">
                    ₱<?php echo number_format((float)$activity['price']); ?>
                    <small><?php echo htmlspecialchars($activity['price_unit'] ?? ''); ?></small>
                </div>
            <?php else: ?>
                <div class="listing-card__price text-muted" style="font-size: var(--fs-base);">Ask for price</div>
            <?php endif; ?>
            <span class="listing-card__cta">
                Explore <i class="fas fa-arrow-right"></i>
            </span>
        </div>
    </div>
</article>