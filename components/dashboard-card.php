<?php
/**
 * components/dashboard-card.php
 * Reusable stat card for admin dashboards.
 *
 * Expects:
 *   $card = [
 *     'label'  => 'Today\'s Bookings',
 *     'value'  => 24,
 *     'icon'   => 'fa-calendar-check',
 *     'color'  => 'ocean' | 'gold' | 'success' | 'navy' | 'danger',  (optional)
 *     'trend'  => '+12%',                                            (optional)
 *     'sub'    => 'vs yesterday',                                    (optional)
 *     'href'   => 'bookings.php',                                    (optional)
 *   ];
 */
if (!isset($card) || !is_array($card)) {
    return;
}

$color = $card['color'] ?? 'ocean';
$isLink = !empty($card['href']);
$tag = $isLink ? 'a' : 'div';
?>
<<?php echo $tag; ?> <?php if ($isLink): ?>href="<?php echo htmlspecialchars($card['href']); ?>"<?php endif; ?>
    class="dash-card dash-card--<?php echo htmlspecialchars($color); ?>">

    <div class="dash-card__top">
        <div class="dash-card__icon">
            <i class="fas <?php echo htmlspecialchars($card['icon'] ?? 'fa-chart-line'); ?>"></i>
        </div>
        <?php if (!empty($card['trend'])): ?>
            <span class="dash-card__trend <?php echo strpos($card['trend'], '-') === 0 ? 'is-down' : ''; ?>">
                <?php echo htmlspecialchars($card['trend']); ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="dash-card__value"><?php echo htmlspecialchars((string)$card['value']); ?></div>
    <div class="dash-card__label"><?php echo htmlspecialchars($card['label']); ?></div>

    <?php if (!empty($card['sub'])): ?>
        <div class="dash-card__sub"><?php echo htmlspecialchars($card['sub']); ?></div>
    <?php endif; ?>
</<?php echo $tag; ?>>