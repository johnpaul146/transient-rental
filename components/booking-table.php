<?php
/**
 * components/booking-table.php
 * Reusable modern booking table + mobile card list.
 *
 * Expects:
 *   $bookings   (array) — rows to render
 *   $table_type ('house' | 'tour' | 'food' | 'package')
 *
 * Optional:
 *   $table_empty_message (string)
 *   $table_actions_html  (string) — HTML rendered in the Actions column
 *                                   Set to null to render default actions.
 *
 * Default actions assume functions like openShopeeView or viewBooking exist
 * in the parent scope. You can override via $table_actions_html.
 */
$tableType = $table_type ?? 'house';
$emptyMsg  = $table_empty_message ?? 'No bookings yet.';
$actionsOverride = $table_actions_html ?? null;

/* Build column definitions based on type */
$columns = match ($tableType) {
    'house' => [
        ['key' => 'reference_number', 'label' => 'Ref #',     'format' => 'ref'],
        ['key' => 'guest_name',       'label' => 'Guest',     'format' => 'text'],
        ['key' => 'house_name',       'label' => 'House',     'format' => 'text'],
        ['key' => 'check_in_date',    'label' => 'Check In',  'format' => 'date'],
        ['key' => 'check_out_date',   'label' => 'Check Out', 'format' => 'date'],
        ['key' => 'total_amount',     'label' => 'Total',     'format' => 'money'],
        ['key' => 'payment_status',   'label' => 'Payment',   'format' => 'payment'],
        ['key' => 'booking_status',   'label' => 'Status',    'format' => 'status'],
    ],
    'tour' => [
        ['key' => 'reference_number', 'label' => 'Ref #',    'format' => 'ref'],
        ['key' => 'guest_name',       'label' => 'Guest',    'format' => 'text'],
        ['key' => 'tour_name',        'label' => 'Tour',     'format' => 'text'],
        ['key' => 'booking_date',     'label' => 'Date',     'format' => 'date'],
        ['key' => 'number_of_guests', 'label' => 'Pax',      'format' => 'text'],
        ['key' => 'total_amount',     'label' => 'Total',    'format' => 'money'],
        ['key' => 'payment_status',   'label' => 'Payment',  'format' => 'payment'],
        ['key' => 'booking_status',   'label' => 'Status',   'format' => 'status'],
    ],
    'food' => [
        ['key' => 'reference_number', 'label' => 'Ref #',    'format' => 'ref'],
        ['key' => 'guest_name',       'label' => 'Guest',    'format' => 'text'],
        ['key' => 'food_name',        'label' => 'Food',     'format' => 'text'],
        ['key' => 'preferred_date',   'label' => 'Date',     'format' => 'date'],
        ['key' => 'quantity',         'label' => 'Qty',      'format' => 'text'],
        ['key' => 'total_amount',     'label' => 'Total',    'format' => 'money'],
        ['key' => 'payment_status',   'label' => 'Payment',  'format' => 'payment'],
        ['key' => 'booking_status',   'label' => 'Status',   'format' => 'status'],
    ],
    'package' => [
        ['key' => 'reference_number', 'label' => 'Ref #',    'format' => 'ref'],
        ['key' => 'guest_name',       'label' => 'Guest',    'format' => 'text'],
        ['key' => 'grand_total',      'label' => 'Total',    'format' => 'money'],
        ['key' => 'payment_status',   'label' => 'Payment',  'format' => 'payment'],
        ['key' => 'booking_status',   'label' => 'Status',   'format' => 'status'],
        ['key' => 'created_at',       'label' => 'Booked',   'format' => 'datetime'],
    ],
    default => [],
};

function booking_table_format_cell(string $format, $value): string {
    if ($value === null || $value === '') return '<span class="text-muted">—</span>';
    return match ($format) {
        'ref'      => '<strong class="bt-ref">' . htmlspecialchars((string)$value) . '</strong>',
        'money'    => '₱' . number_format((float)$value, 2),
        'date'     => date('M d, Y', strtotime((string)$value)),
        'datetime' => date('M d, Y', strtotime((string)$value)),
        'text'     => htmlspecialchars((string)$value),
        'payment'  => match (strtolower((string)$value)) {
            'paid'      => '<span class="badge badge-success">Paid</span>',
            'pending'   => '<span class="badge badge-warning">Pending</span>',
            'cancelled' => '<span class="badge badge-danger">Cancelled</span>',
            default     => '<span class="badge badge-info">' . htmlspecialchars((string)$value) . '</span>',
        },
        'status'   => match (strtolower((string)$value)) {
            'confirmed'  => '<span class="badge badge-info">Confirmed</span>',
            'pending'    => '<span class="badge badge-warning">Pending</span>',
            'completed'  => '<span class="badge badge-success">Completed</span>',
            'cancelled'  => '<span class="badge badge-danger">Cancelled</span>',
            default      => '<span class="badge badge-navy">' . htmlspecialchars((string)$value) . '</span>',
        },
        default => htmlspecialchars((string)$value),
    };
}
?>
<div class="booking-table-wrapper">

    <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p><?php echo htmlspecialchars($emptyMsg); ?></p>
        </div>
    <?php else: ?>

        <!-- Desktop table -->
        <div class="booking-table-scroll desktop-only">
            <table class="booking-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th><?php echo htmlspecialchars($col['label']); ?></th>
                        <?php endforeach; ?>
                        <?php if ($actionsOverride !== false): ?>
                            <th class="booking-table__actions-col">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                                <td data-label="<?php echo htmlspecialchars($col['label']); ?>">
                                    <?php echo booking_table_format_cell($col['format'], $b[$col['key']] ?? null); ?>
                                </td>
                            <?php endforeach; ?>
                            <?php if ($actionsOverride !== false): ?>
                                <td class="booking-table__actions-col">
                                    <?php
                                    if ($actionsOverride !== null) {
                                        echo $actionsOverride;
                                    } else {
                                        echo '<span class="text-muted" style="font-size:12px;">—</span>';
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="booking-cards">
            <?php foreach ($bookings as $b): ?>
                <div class="booking-card">
                    <div class="booking-card__head">
                        <span class="booking-card__ref">
                            <?php echo htmlspecialchars($b['reference_number'] ?? 'N/A'); ?>
                        </span>
                        <?php if (isset($b['payment_status'])): ?>
                            <span class="booking-card__status">
                                <?php echo booking_table_format_cell('payment', $b['payment_status']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($columns as $col):
                        if (in_array($col['format'], ['ref','payment'], true)) continue;
                    ?>
                        <div class="booking-card__row">
                            <span class="booking-card__label"><?php echo htmlspecialchars($col['label']); ?></span>
                            <span class="booking-card__value">
                                <?php echo booking_table_format_cell($col['format'], $b[$col['key']] ?? null); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($actionsOverride !== false && $actionsOverride !== null): ?>
                        <div class="booking-card__actions"><?php echo $actionsOverride; ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>