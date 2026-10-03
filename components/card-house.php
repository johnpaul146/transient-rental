<?php
/**
 * components/card-house.php
 *
 * Expects:
 *   $house        (array)  — current row from the houses loop
 *   $house_gallery (array) — optional gallery rows for this house
 *
 * Optional:
 *   $house_main_image (string) — pre-resolved image path
 */

// Prevent editor/runtime warnings when this component is opened directly.
if (!isset($house) || !is_array($house)) {
    return;
}

$houseGallery = isset($house_gallery) && is_array($house_gallery) ? $house_gallery : [];

if (!empty($house_main_image) && file_exists($house_main_image)) {
    $mainImage = $house_main_image;
} elseif (!empty($house['image']) && $house['image'] !== 'default-house.jpg') {
    if (file_exists('uploads/houses/' . $house['image'])) {
        $mainImage = 'uploads/houses/' . $house['image'];
    } elseif (file_exists('uploads/houses/gallery/' . $house['image'])) {
        $mainImage = 'uploads/houses/gallery/' . $house['image'];
    } else {
        $mainImage = null;
    }
} else {
    $mainImage = null;
    foreach ($houseGallery as $g) {
        if (!empty($g['is_main'])) {
            $p = 'uploads/houses/gallery/' . $g['image'];
            if (file_exists($p)) { $mainImage = $p; break; }
        }
    }
    if (!$mainImage && !empty($houseGallery[0]['image'])) {
        $p = 'uploads/houses/gallery/' . $houseGallery[0]['image'];
        if (file_exists($p)) $mainImage = $p;
    }
}

$status       = $house['status'] ?? 'available';
$galleryCount = count($houseGallery);
$amenities    = !empty($house['amenities'])
    ? array_slice(array_filter(array_map('trim', explode(',', $house['amenities']))), 0, 3)
    : [];
?>
<article class="listing-card card-hover" onclick="openShopeeView(<?php echo (int)$house['id']; ?>)">    <div class="listing-card__media">
        <?php if ($mainImage): ?>
            <img src="<?php echo htmlspecialchars($mainImage); ?>?v=<?php echo time(); ?>"
                 alt="<?php echo htmlspecialchars($house['house_name']); ?>"
                 loading="lazy"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="listing-card__placeholder" style="display:none;">
                <i class="fas fa-home"></i>
            </div>
        <?php else: ?>
            <div class="listing-card__placeholder">
                <i class="fas fa-home"></i>
            </div>
        <?php endif; ?>

        <div class="listing-card__badges">
            <span class="badge <?php echo $status === 'available' ? 'badge-success' : 'badge-warning'; ?>">
                <i class="fas fa-circle" style="font-size:6px;"></i>
                <?php echo ucfirst($status); ?>
            </span>
            <?php if ($galleryCount > 0): ?>
                <span class="badge badge-glass">
                    <i class="fas fa-images"></i> <?php echo $galleryCount; ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="listing-card__body">
        <h3 class="listing-card__title"><?php echo htmlspecialchars($house['house_name']); ?></h3>

        <div class="listing-card__meta">
            <span class="meta-item">
                <i class="fas fa-users"></i>
                <?php echo (int)($house['capacity'] ?? 0); ?> pax
            </span>
            <span class="meta-item">
                <i class="fas fa-bed"></i>
                <?php echo (int)($house['bedrooms'] ?? 0); ?> bed<?php echo ((int)($house['bedrooms'] ?? 0)) !== 1 ? 's' : ''; ?>
            </span>
            <?php if (!empty($amenities)): ?>
                <span class="meta-item">
                    <i class="fas fa-check-circle"></i>
                    <?php echo count($amenities); ?>+ amenities
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($house['description'])): ?>
            <p class="listing-card__desc"><?php echo htmlspecialchars($house['description']); ?></p>
        <?php endif; ?>

        <div class="listing-card__footer">
            <div class="listing-card__price">
                ₱<?php echo number_format((float)$house['price_per_night']); ?>
                <small>/night</small>
            </div>
            
        </div>
    </div>
</article>