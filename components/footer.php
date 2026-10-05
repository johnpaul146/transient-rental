<?php
/**
 * components/footer.php
 * Dark navy footer.
 *
 * Requires (from parent scope):
 *   $content, $facebook_link, $location_address, $maps_url, $map_embed
 */

$content = $content ?? [
    'footer' => []
];

$facebook_link = trim((string)($facebook_link ?? ''));
$location_address = $location_address ?? 'Hundred Islands, Alaminos, Pangasinan';
$maps_url = $maps_url ?? '#';
$map_embed = $map_embed ?? '';

?>
<footer class="site-footer">
    <div class="container">
        <div class="site-footer__grid">

            <!-- Column 1: Brand + about -->
            <div class="site-footer__col">
                <div class="site-footer__brand">
                    <span class="site-footer__brand-icon"><i class="fas fa-umbrella-beach"></i></span>
                    <span class="site-footer__brand-text">
                        <span class="site-footer__brand-name">Transient House &amp; Tours</span>
                        <span class="site-footer__brand-sub">Hundred Islands &bull; Alaminos, Pangasinan</span>
                    </span>
                </div>
                <p class="site-footer__about">
                    <?php echo htmlspecialchars($content['footer']['company_description']
                        ?? 'Your trusted partner for comfortable accommodations and exciting island adventures.'); ?>
                </p>

                <div class="site-footer__contact">
                    <div class="site-footer__contact-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php if ($maps_url !== '#'): ?>
                            <a href="<?php echo htmlspecialchars($maps_url); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo htmlspecialchars($location_address); ?>
                            </a>
                        <?php else: ?>
                            <span><?php echo htmlspecialchars($location_address); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="site-footer__contact-item">
                        <i class="fas fa-phone"></i>
                        <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $content['footer']['phone'] ?? '+639123456789'); ?>">
                            <?php echo htmlspecialchars($content['footer']['phone'] ?? '+63 912 345 6789'); ?>
                        </a>
                    </div>
                    <div class="site-footer__contact-item">
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:<?php echo htmlspecialchars($content['footer']['email'] ?? 'info@transientrental.com'); ?>">
                            <?php echo htmlspecialchars($content['footer']['email'] ?? 'info@transientrental.com'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Column 2: Explore links -->
            <div class="site-footer__col">
                <h4 class="site-footer__heading">Explore</h4>
                <ul class="site-footer__list">
                    <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="houses.php"><i class="fas fa-chevron-right"></i> Stay</a></li>
                    <li><a href="tours.php"><i class="fas fa-chevron-right"></i> Tours</a></li>
                    <li><a href="activities.php"><i class="fas fa-chevron-right"></i> Activities</a></li>
                    <li><a href="food.php"><i class="fas fa-chevron-right"></i> Food</a></li>
                    <li><a href="packages.php"><i class="fas fa-chevron-right"></i> Packages</a></li>
                </ul>
            </div>

            <!-- Column 3: Booking links -->
            <div class="site-footer__col">
                <h4 class="site-footer__heading">Booking</h4>
                <ul class="site-footer__list">
                    <li><a href="packages.php"><i class="fas fa-chevron-right"></i> Build a Package</a></li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li><a href="profile.php"><i class="fas fa-chevron-right"></i> My Profile</a></li>
                        <li><a href="reviews.php"><i class="fas fa-chevron-right"></i> Reviews</a></li>
                    <?php else: ?>
                        <li><a href="login.php"><i class="fas fa-chevron-right"></i> Login</a></li>
                        <li><a href="register.php"><i class="fas fa-chevron-right"></i> Create Account</a></li>
                        <li><a href="reviews.php"><i class="fas fa-chevron-right"></i> Reviews</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Column 4: Map -->
            <div class="site-footer__col">
                <h4 class="site-footer__heading">Find Us</h4>
                <?php if (!empty($map_embed) && $map_embed !== '#'): ?>
                    <div class="site-footer__map">
                        <iframe
                            src="<?php echo htmlspecialchars($map_embed); ?>"
                            width="100%" height="100%"
                            style="border:0;" allowfullscreen="" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            title="Location Map">
                        </iframe>
                    </div>
                <?php else: ?>
                    <p class="site-footer__about">Map location is not configured yet.</p>
                <?php endif; ?>
                <?php if ($facebook_link !== '' && $facebook_link !== '#'): ?>
                    <div class="site-footer__social">
                        <a href="<?php echo htmlspecialchars($facebook_link); ?>"
                           target="_blank" rel="noopener noreferrer" title="Facebook" aria-label="Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="site-footer__bottom">
            <div class="site-footer__copy">
                &copy; <?php echo date('Y'); ?> Transient House &amp; Tours. All rights reserved.
            </div>
            <div class="site-footer__links">
                <a onclick="reopenTermsModal('privacy'); return false;">Privacy Policy</a>
                <a onclick="reopenTermsModal('terms'); return false;">Terms &amp; Conditions</a>
            </div>
        </div>
    </div>
</footer>