<?php
/**
 * components/card-tour.php
 *
 * Expects:
 *   $tour         (array)
 *   $tour_gallery (array) — optional
 *
 * Optional:
 *   $tour_main_image (string)
 *   $tour_places     (array) — parsed places_to_visit
 */
$tourGallery = isset($tour_gallery) && is_array($tour_gallery) ? $tour_gallery : [];
$tourPlaces  = isset($tour_places)  && is_array($tour_places)  ? $tour_places  : [];

if (!empty($tour_main_image) && file_exists($tour_main_image)) {
    $mainImage = $tour_main_image;
} elseif (!empty($tourGallery[0]['image']) && !empty($tourGallery[0]['folder'])) {
    $candidate = 'uploads/tours/gallery/' . $tourGallery[0]['folder'] . '/' . $tourGallery[0]['image'];
    $mainImage = file_exists($candidate) ? $candidate : null;
} else {
    $mainImage = null;
}

$maxG = (int)($tour['max_guests'] ?? 10);
if ($maxG <= 5)       { $boat = ['label' => 'Small Boat',  'class' => 'badge-success', 'pax' => '1-5 PAX']; }
elseif ($maxG <= 10)  { $boat = ['label' => 'Medium Boat', 'class' => 'badge-info',    'pax' => '6-10 PAX']; }
elseif ($maxG <= 15)  { $boat = ['label' => 'Large Boat',  'class' => 'badge-warning', 'pax' => '11-15 PAX']; }
else                  { $boat = ['label' => 'Deluxe Boat', 'class' => 'badge-danger',  'pax' => '16-20 PAX']; }

$status = $tour['status'] ?? 'available';
?>
<article class="listing-card card-hover" onclick="openShopeeView(<?php echo (int)$tour['id']; ?>)">    <div class="listing-card__media">
        <?php if ($mainImage): ?>
            <img src="<?php echo htmlspecialchars($mainImage); ?>?v=<?php echo time(); ?>"
                 alt="<?php echo htmlspecialchars($tour['tour_name']); ?>"
                 loading="lazy"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="listing-card__placeholder" style="display:none;">
                <i class="fas fa-ship"></i>
            </div>
        <?php else: ?>
            <div class="listing-card__placeholder">
                <i class="fas fa-ship"></i>
            </div>
        <?php endif; ?>

        <div class="listing-card__badges">
            <span class="badge <?php echo $boat['class']; ?>">
                <i class="fas fa-ship"></i> <?php echo htmlspecialchars($boat['label']); ?>
            </span>
            <span class="badge <?php echo $status === 'available' ? 'badge-glass' : 'badge-danger'; ?>">
                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
            </span>
        </div>
    </div>

    <div class="listing-card__body">
        <h3 class="listing-card__title"><?php echo htmlspecialchars($tour['tour_name']); ?></h3>

        <div class="listing-card__meta">
            <span class="meta-item">
                <i class="fas fa-users"></i> <?php echo htmlspecialchars($boat['pax']); ?>
            </span>
            <?php if (!empty($tourPlaces)): ?>
                <span class="meta-item">
                    <i class="fas fa-map-marked-alt"></i>
                    <?php echo count($tourPlaces); ?> stops
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($tour['description'])): ?>
            <p class="listing-card__desc"><?php echo htmlspecialchars($tour['description']); ?></p>
        <?php endif; ?>

        <div class="listing-card__footer">
            <div class="listing-card__price">
                ₱<?php echo number_format((float)($tour['price_per_boat'] ?? 0)); ?>
                <small>/boat</small>
            </div>
          
        </div>
    </div>
</article>