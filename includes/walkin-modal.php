<?php
/**
 * includes/walkin-modal.php
 *
 * "+ Create Walk-in Booking" modal for Booking Management (admin + staff).
 * Include once, near the end of booking-management.php, after the other modals.
 * Expects: $pdo, $can_manage_booking.   The server side lives in
 * includes/WalkInBookingService.php (this file only collects input and posts it
 * to booking-management.php, action=create_walkin_booking, answered as JSON).
 */
if (empty($can_manage_booking)) return;

if (empty($_SESSION['wk_csrf'])) $_SESSION['wk_csrf'] = bin2hex(random_bytes(16));

$wk_data = ['houses' => [], 'tours' => [], 'foods' => []];
try {
    foreach ($pdo->query("SELECT id, house_name, price_per_night, capacity FROM houses WHERE status = 'available' ORDER BY house_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $wk_data['houses'][] = ['id' => (int)$r['id'], 'name' => $r['house_name'], 'price' => (float)$r['price_per_night'], 'capacity' => (int)$r['capacity']];
    }
    foreach ($pdo->query("SELECT id, tour_name, price_per_boat, max_guests FROM tours WHERE status = 'available' ORDER BY tour_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $wk_data['tours'][] = ['id' => (int)$r['id'], 'name' => $r['tour_name'], 'price' => (float)$r['price_per_boat'], 'max' => (int)$r['max_guests']];
    }
    foreach ($pdo->query("SELECT id, name, price, size_variations FROM food_items WHERE is_available = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sizes = [];
        $vars = !empty($r['size_variations']) ? json_decode($r['size_variations'], true) : null;
        if (is_array($vars)) foreach ($vars as $i => $v) { if (isset($v['size'], $v['price'])) $sizes[] = ['i' => (int)$i, 'size' => (string)$v['size'], 'price' => (float)$v['price']]; }
        $wk_data['foods'][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'sizes' => $sizes];
    }
} catch (PDOException $e) { error_log('walkin-modal data: ' . $e->getMessage()); }
$wk_today = date('Y-m-d');
$wk_fee = PaymentService::RESERVATION_FEE;
?>
<style>
    .wk-open-btn { margin-top: 14px; display: inline-flex; align-items: center; gap: 8px; background: #fff; color: #0B2447; border: none; border-radius: 12px; padding: 11px 18px; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 6px 16px rgba(0,0,0,.18); }
    .wk-open-btn:hover { transform: translateY(-1px); }
    #walkinModal { z-index: 1100; }
    #wkForm { display: flex; flex-direction: column; flex: 1; min-height: 0; margin: 0; }
    #walkinModal .modal-content { max-width: 720px; width: 94%; padding: 0; overflow: hidden; display: flex; flex-direction: column; max-height: 92vh; }
    .wk-head { padding: 18px 22px 12px; border-bottom: 2px solid #e8f0fe; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .wk-head h3 { margin: 0; font-size: 18px; color: #0B2447; }
    .wk-close{
    width:42px;
    height:42px;
    border-radius:50%;
    border:1px solid #dbe4f0;
    background:#f8fbff;
    color:#0B2447;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:26px;
    font-weight:700;
    cursor:pointer;
    transition:.2s ease;
    flex-shrink:0;
}

.wk-close:hover{
    background:#eaf3ff;
    transform:scale(1.05);
}

.wk-close span{
    line-height:1;
    margin-top:-2px;
}
    .wk-steps { display: flex; gap: 6px; padding: 12px 22px 0; }
    .wk-step { flex: 1; text-align: center; font-size: 12px; font-weight: 700; color: #94a3b8; padding: 8px 4px; border-bottom: 3px solid #e2e8f0; }
    .wk-step.active { color: #0B3D91; border-color: #0B3D91; } .wk-step.done { color: #059669; border-color: #10b981; }
    .wk-body { padding: 16px 22px; overflow-y: auto; flex: 1; }
    .wk-foot { padding: 14px 22px; border-top: 2px solid #e8f0fe; display: flex; gap: 10px; justify-content: space-between; background: #fff; }
    .wk-btn { padding: 11px 20px; border: none; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; }
    .wk-btn.ghost { background: #e2e8f0; color: #475569; } .wk-btn.primary { background: linear-gradient(135deg,#0B3D91,#4DA6D9); color: #fff; } .wk-btn.ok { background: linear-gradient(135deg,#10b981,#059669); color: #fff; }
    .wk-btn:disabled { opacity: .55; cursor: not-allowed; }
    .wk-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; } .wk-field { margin-bottom: 12px; min-width: 0; }
    .wk-field label { display: block; font-weight: 600; font-size: 13px; color: #1e293b; margin-bottom: 5px; } .wk-field label .req { color: #dc2626; } .wk-field small { color: #64748b; font-size: 12px; }
    .wk-field input, .wk-field select, .wk-field textarea { width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: #fafafa; box-sizing: border-box; }
    .wk-field input.wk-locked { background: #f1f5f9; color: #475569; cursor: not-allowed; border-style: dashed; }
    .wk-stay-note { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; border-radius: 10px; padding: 9px 12px; font-size: 13px; line-height: 1.5; margin-bottom: 10px; overflow-wrap: anywhere; } .wk-field small.wk-stay-warn, .wk-stay-warn { display: block; margin-top: 4px; font-size: 12px; color: #b91c1c !important; font-weight: 600; }
    .wk-lock-note { display: block; margin-top: 4px; font-size: 11px; color: #94a3b8; }
    .wk-field input:focus, .wk-field select:focus, .wk-field textarea:focus { outline: none; border-color: #4DA6D9; background: #fff; }
    .wk-seg { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
    .wk-seg button { flex: 1; min-width: 140px; padding: 10px; border-radius: 10px; border: 2px solid #e2e8f0; background: #fff; font-weight: 700; color: #475569; cursor: pointer; }
    .wk-seg button.on { border-color: #0B3D91; background: #eff6ff; color: #0B3D91; }
    .wk-results { border: 2px solid #e2e8f0; border-radius: 10px; max-height: 220px; overflow-y: auto; margin-top: 6px; }
    .wk-result { padding: 10px 12px; border-bottom: 1px solid #eef2f7; cursor: pointer; display: flex; justify-content: space-between; gap: 8px; align-items: center; }
    .wk-result:last-child { border-bottom: 0; } .wk-result:hover { background: #f1f7ff; } .wk-result b { color: #0f172a; } .wk-result span { color: #64748b; font-size: 12px; }
    .wk-tag { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #e0f2fe; color: #075985; white-space: nowrap; } .wk-tag.walk { background: #fef3c7; color: #92400e; }
    .wk-chosen { background: #ecfdf5; border: 2px solid #a7f3d0; border-radius: 10px; padding: 10px 12px; margin-top: 10px; display: flex; justify-content: space-between; gap: 8px; align-items: center; }
    .wk-types { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
    .wk-type { border: 2px solid #e2e8f0; border-radius: 12px; padding: 12px 6px; text-align: center; background: #fff; cursor: pointer; font-weight: 700; color: #475569; font-size: 13px; }
    .wk-type i { display: block; font-size: 20px; margin-bottom: 6px; color: #4DA6D9; } .wk-type.on { border-color: #0B3D91; background: #eff6ff; color: #0B3D91; }
    .wk-sec { border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 12px; background: #fcfdff; } .wk-sec h4 { margin: 0 0 10px; font-size: 14px; color: #0B2447; }
    .wk-checks { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; } .wk-checks label { display: flex; gap: 6px; align-items: center; font-weight: 600; font-size: 14px; }
    .wk-total { background: #f1f5f9; border-radius: 10px; padding: 12px 14px; display: flex; justify-content: space-between; font-weight: 700; color: #0f172a; }
    .wk-pay { display: grid; gap: 8px; margin-bottom: 12px; } .wk-pay label { display: flex; gap: 10px; align-items: flex-start; border: 2px solid #e2e8f0; border-radius: 12px; padding: 12px; cursor: pointer; font-size: 14px; }
    .wk-pay label.on { border-color: #0B3D91; background: #eff6ff; } .wk-pay input { margin-top: 3px; } .wk-pay small { display: block; color: #64748b; font-weight: 400; margin-top: 2px; }
    .wk-summary { border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; margin-bottom: 12px; font-size: 14px; } .wk-summary div { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; } .wk-summary span:first-child { color: #64748b; }
    .wk-err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; display: none; } .wk-err.show { display: block; }
    .wk-done { text-align: center; padding: 18px 6px; } .wk-done .big { font-size: 44px; color: #10b981; } .wk-done .ref { font-size: 20px; font-weight: 800; color: #0B2447; margin: 8px 0; letter-spacing: .5px; }
    /* availability calendar (walk-in) — the server (AvailabilityService) decides every state; this only draws it */
    .wk-datebtn { width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: #fafafa; box-sizing: border-box; text-align: left; cursor: pointer; color: #1e293b; font-family: inherit; display: flex; align-items: center; gap: 8px; min-height: 42px; }
    .wk-datebtn::before { content: "\f073"; font-family: "Font Awesome 5 Free", "Font Awesome 6 Free", FontAwesome; font-weight: 900; color: #4DA6D9; }
    .wk-datebtn.empty { color: #64748b; } .wk-datebtn:focus-visible { outline: 2px solid #4DA6D9; outline-offset: 1px; }
    .wk-cal { border: 1px solid #dbe4f0; border-radius: 12px; padding: 10px; margin: 0 0 12px; background: #fff; max-width: 100%; box-sizing: border-box; overflow: hidden; }
    .wk-cal-hint { font-size: 13px; color: #475569; padding: 6px 4px; line-height: 1.5; }
    .wk-cal-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
    .wk-cal-title { font-size: 14px; color: #0B2447; text-align: center; flex: 1; }
    .wk-cal-nav { width: 36px; height: 36px; border-radius: 50%; border: 1px solid #dbe4f0; background: #f8fbff; color: #0B2447; font-size: 18px; line-height: 1; cursor: pointer; flex-shrink: 0; padding: 0; }
    .wk-cal-nav:disabled { opacity: .35; cursor: not-allowed; }
    .wk-cal-dow, .wk-cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 3px; }
    .wk-cal-dow span { text-align: center; font-size: 11px; font-weight: 700; color: #64748b; padding: 2px 0; }
    .wk-d { position: relative; height: 40px; min-width: 0; border: 1px solid #bbf7d0; background: #f0fdf4; color: #14532d; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; font-family: inherit; }
    .wk-d.pad { visibility: hidden; border: 0; background: none; cursor: default; }
    .wk-d.booked { background: #fee2e2; border-color: #fecaca; color: #991b1b; cursor: not-allowed; text-decoration: line-through; }
    .wk-d.blocked { background: #e2e8f0; border-color: #cbd5e1; color: #475569; cursor: not-allowed; background-image: repeating-linear-gradient(45deg, transparent 0 4px, rgba(100,116,139,.22) 4px 6px); }
    .wk-d.past, .wk-d.outside, .wk-d.over { background: #f8fafc; border-color: #eef2f7; color: #b6c0cd; cursor: not-allowed; }
    .wk-d.range { background: #dbeafe; border-color: #bfdbfe; color: #1e3a8a; }
    .wk-d.sel { background: #0B3D91; border-color: #0B3D91; color: #fff; text-decoration: none; }
    .wk-d.today { box-shadow: inset 0 0 0 2px #f59e0b; }
    .wk-d:focus-visible { outline: 2px solid #4DA6D9; outline-offset: 1px; z-index: 1; }
    .wk-cal-legend { display: flex; flex-wrap: wrap; gap: 6px 12px; margin-top: 10px; font-size: 12px; color: #475569; }
    .wk-cal-legend span { display: inline-flex; align-items: center; gap: 5px; }
    .wk-cal-legend i { width: 14px; height: 14px; border-radius: 4px; border: 1px solid #cbd5e1; display: inline-block; flex-shrink: 0; }
    .wk-cal-legend .l-free { background: #f0fdf4; border-color: #bbf7d0; } .wk-cal-legend .l-booked { background: #fee2e2; border-color: #fecaca; } .wk-cal-legend .l-blocked { background: #e2e8f0; background-image: repeating-linear-gradient(45deg, transparent 0 3px, rgba(100,116,139,.3) 3px 5px); } .wk-cal-legend .l-sel { background: #0B3D91; border-color: #0B3D91; } .wk-cal-legend .l-today { background: #fff; box-shadow: inset 0 0 0 2px #f59e0b; }
    .wk-cal-msg { margin-top: 8px; font-size: 12.5px; font-weight: 600; color: #b91c1c; min-height: 0; overflow-wrap: anywhere; } .wk-cal-msg.info { color: #1e40af; } .wk-cal-msg:empty { display: none; }
    .wk-cal.loading .wk-cal-grid { opacity: .55; }
    .wk-hide { display: none !important; }
    @media (max-width: 600px) { .wk-d { height: 42px; } .wk-row { grid-template-columns: 1fr; } .wk-types { grid-template-columns: repeat(2, 1fr); } #walkinModal .modal-content { width: 100%; max-height: 100vh; height: 100vh; border-radius: 0; } .wk-head, .wk-body, .wk-foot { padding-left: 14px; padding-right: 14px; } .wk-steps { padding: 10px 14px 0; } .wk-btn { flex: 1; } }
</style>

<div class="modal" id="walkinModal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-labelledby="wkTitle">
        <div class="wk-head">
            <h3 id="wkTitle"><i class="fas fa-user-plus" style="color:#4DA6D9;"></i> Create Walk-in Booking</h3>
<button type="button" class="wk-close" onclick="wkClose()" aria-label="Close">
    <span>&times;</span>
</button>        </div>
        <div class="wk-steps" id="wkSteps"><div class="wk-step">1 · Guest</div><div class="wk-step">2 · Booking</div><div class="wk-step">3 · Payment</div></div>
        <form id="wkForm" onsubmit="return false;" autocomplete="off">
            <input type="hidden" name="create_walkin_booking" value="1">
            <input type="hidden" name="wk_csrf" value="<?php echo htmlspecialchars($_SESSION['wk_csrf']); ?>">
            <input type="hidden" name="wk_nonce" id="wkNonce" value="">
            <input type="hidden" name="guest_mode" id="wkGuestMode" value="new">
            <input type="hidden" name="guest_id" id="wkGuestId" value="">
            <input type="hidden" name="booking_type" id="wkType" value="">
            <div class="wk-body" id="wkBody">
                <div class="wk-err" id="wkErr" role="alert"></div>

                <!-- STEP 1 · GUEST -->
                <div data-step="1">
                    <div class="wk-seg">
                        <button type="button" id="wkModeNew" class="on" onclick="wkMode('new')"><i class="fas fa-user-plus"></i> New walk-in guest</button>
                        <button type="button" id="wkModeSearch" onclick="wkMode('existing')"><i class="fas fa-search"></i> Search existing guest</button>
                    </div>
                    <div id="wkExisting" class="wk-hide">
                        <div class="wk-field"><label>Search by name, contact number or e-mail</label><input type="search" id="wkSearch" placeholder="Type at least 2 characters…"></div>
                        <div class="wk-results wk-hide" id="wkResults"></div>
                        <div class="wk-chosen wk-hide" id="wkChosen"></div>
                    </div>
                    <div id="wkNew">
                        <div class="wk-row">
                            <div class="wk-field"><label>Full name <span class="req">*</span></label><input type="text" name="wg_name" maxlength="60" placeholder="e.g. Juan Dela Cruz"></div>
                            <div class="wk-field"><label>Contact number <span class="req">*</span></label><input type="tel" name="wg_contact" maxlength="20" inputmode="tel" placeholder="09123456789"></div>
                        </div>
                        <div class="wk-row">
                            <div class="wk-field"><label>E-mail <small>(optional — leave empty if none)</small></label><input type="email" name="wg_email" maxlength="100"></div>
                            <div class="wk-field"><label>Address <small>(optional)</small></label><input type="text" name="wg_address" maxlength="500"></div>
                        </div>
                        <div class="wk-row">
                            <div class="wk-field"><label>ID type <small>(optional)</small></label>
                                <select name="wg_id_type"><option value="">—</option><option>National ID</option><option>Driver's License</option><option>Passport</option><option>Student ID</option><option>Voter's ID</option><option>Other</option></select></div>
                            <div class="wk-field"><label>ID number <small>(optional)</small></label><input type="text" name="wg_id_number" maxlength="100"></div>
                        </div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Emergency contact name <small>(optional)</small></label><input type="text" name="wg_emergency_name" maxlength="100"></div>
                            <div class="wk-field"><label>Emergency contact number <small>(optional)</small></label><input type="tel" name="wg_emergency_number" maxlength="20" inputmode="tel"></div>
                        </div>
                        <small style="color:#64748b;">No account, password, OTP or ID upload is needed. The guest is saved as a walk-in guest.</small>
                    </div>
                </div>

                <!-- STEP 2 · BOOKING -->
                <div data-step="2" class="wk-hide">
                    <div class="wk-types" id="wkTypes">
                        <div class="wk-type" data-type="house"><i class="fas fa-home"></i>House</div>
                        <div class="wk-type" data-type="tour"><i class="fas fa-ship"></i>Tour / Boat</div>
                        <div class="wk-type" data-type="food"><i class="fas fa-utensils"></i>Food</div>
                        <div class="wk-type" data-type="package"><i class="fas fa-box-open"></i>Package</div>
                    </div>
                    <div class="wk-checks wk-hide" id="wkPkgItems">
                        <strong style="width:100%;font-size:13px;">Package includes:</strong>
                        <label><input type="checkbox" name="package_items[]" value="house"> House</label>
                        <label><input type="checkbox" name="package_items[]" value="tour"> Tour / Boat</label>
                        <label><input type="checkbox" name="package_items[]" value="food"> Food</label>
                    </div>

                    <div class="wk-sec wk-hide" id="wkSecHouse"><h4><i class="fas fa-home"></i> House</h4>
                        <div class="wk-field"><label>House <span class="req">*</span></label><select name="house_id" id="wkHouse"></select></div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Check-in <span class="req">*</span></label><input type="hidden" name="check_in" id="wkIn" value=""><button type="button" class="wk-datebtn" id="wkInBtn" data-empty="Select check-in date"></button></div>
                            <div class="wk-field"><label>Check-out <span class="req">*</span></label><input type="hidden" name="check_out" id="wkOut" value=""><button type="button" class="wk-datebtn" id="wkOutBtn" data-empty="Select check-out date"></button></div>
                        </div>
                        <div class="wk-cal" id="wkCalHouse" data-cal="house"></div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Check-in time</label><input type="time" name="check_in_time" id="wkInTime" value="14:00"></div>
                            <div class="wk-field"><label><i class="fas fa-lock"></i> Check-out time <small>(automatic)</small></label><input type="time" name="check_out_time" id="wkOutTime" value="14:00" readonly tabindex="-1" aria-readonly="true" class="wk-locked"><small class="wk-lock-note">Checkout time follows the check-in time.</small></div>
                        </div>
                        <div class="wk-field"><label>Number of guests <span class="req">*</span> <small id="wkHouseCap"></small></label><input type="number" name="guests" id="wkPax" min="1" value="1"></div>
                        <div class="wk-field"><label>Guest names <small>(optional · one per line · must match the number of guests)</small></label><textarea name="guest_names" rows="2"></textarea></div>
                    </div>

                    <div class="wk-sec wk-hide" id="wkSecTour"><h4><i class="fas fa-ship"></i> Tour / Boat</h4>
                        <div class="wk-stay-note wk-hide" data-for="tour"></div>
                        <div class="wk-field"><label>Boat / tour <span class="req">*</span></label><select name="tour_id" id="wkTour"></select></div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Tour date <span class="req">*</span></label><input type="hidden" name="tour_date" id="wkTourDate" value=""><button type="button" class="wk-datebtn" id="wkTourDateBtn" data-empty="Select tour date"></button></div>
                            <div class="wk-field"><label>Time <span class="req">*</span></label><input type="time" name="tour_time" id="wkTourTime" value="08:00"></div>
                        </div>
                        <div class="wk-cal" id="wkCalTour" data-cal="tour"></div>
                        <div class="wk-field"><label>Number of guests <span class="req">*</span> <small id="wkTourCap"></small></label><input type="number" name="tour_guests" min="1" value="1"></div>
                    </div>

                    <div class="wk-sec wk-hide" id="wkSecFood"><h4><i class="fas fa-utensils"></i> Food</h4>
                        <div class="wk-stay-note wk-hide" data-for="food"></div>
                        <div class="wk-field"><label>Food package <span class="req">*</span></label><select name="food_id" id="wkFood"></select></div>
                        <div class="wk-field wk-hide" id="wkSizeWrap"><label>Size <span class="req">*</span></label><select name="size_variant" id="wkSize"></select></div>
                        <div class="wk-field"><label>Quantity <span class="req">*</span></label><input type="number" name="food_quantity" id="wkQty" min="1" max="<?php echo (int)PaymentService::FOOD_MAX_QUANTITY; ?>" step="1" value="1" inputmode="numeric"></div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Date <span class="req">*</span></label><input type="hidden" name="food_date" id="wkFoodDate" value=""><button type="button" class="wk-datebtn" id="wkFoodDateBtn" data-empty="Select date"></button></div>
                            <div class="wk-field"><label>Time <span class="req">*</span></label><input type="time" name="food_time" id="wkFoodTime" value="12:00"></div>
                        </div>
                        <div class="wk-cal" id="wkCalFood" data-cal="food"></div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Pickup or delivery</label><select name="fulfillment" id="wkFulfil"><option value="pickup">Pickup</option><option value="delivery">Delivery</option></select></div>
                            <div class="wk-field wk-hide" id="wkAddrWrap"><label>Delivery location</label><small class="wk-lock-note" style="font-size:12px;color:#475569;">Delivery goes to the guest's own confirmed house stay, during that stay only. Otherwise choose Pickup.</small></div>
                            <div class="wk-field wk-hide" id="wkAddrAuto"><label>Delivery location</label><small class="wk-lock-note" style="font-size:12px;color:#475569;">Delivered to the booked house/unit (package with a house).</small></div>
                        </div>
                    </div>
                    <div class="wk-total"><span>Estimated total</span><span id="wkTotal">₱0.00</span></div>
                </div>

                <!-- STEP 3 · PAYMENT -->
                <div data-step="3" class="wk-hide">
                    <div class="wk-summary" id="wkSummary"></div>
                    <div class="wk-pay" id="wkPay">
                        <label class="on"><input type="radio" name="payment_option" value="pay_later" checked><span><strong>Pay later</strong><small>Booking stays Pending / Unpaid. The guest pays the reservation fee later.</small></span></label>
                        <label><input type="radio" name="payment_option" value="cash"><span><strong>Cash</strong><small>Record the <?php echo PaymentService::peso($wk_fee); ?> reservation fee as received in cash. Status becomes Reservation Paid and the booking is confirmed.</small></span></label>
                        <label><input type="radio" name="payment_option" value="gcash"><span><strong>GCash</strong><small>Record the reservation fee received by GCash (enter the reference number).</small></span></label>
                    </div>
                    <div class="wk-field wk-hide" id="wkGcashWrap"><label>GCash reference number <span class="req">*</span></label><input type="text" name="gcash_reference" maxlength="30" placeholder="e.g. 1234567890123"></div>
                    <div class="wk-field"><label>Special requests / notes <small>(optional)</small></label><textarea name="special_requests" rows="2" maxlength="1000"></textarea></div>
                </div>

                <!-- RESULT -->
                <div data-step="done" class="wk-hide">
                    <div class="wk-done">
                        <div class="big"><i class="fas fa-check-circle"></i></div>
                        <div style="font-weight:700;color:#0f172a;">Walk-in booking created</div>
                        <div class="ref" id="wkRef">—</div>
                        <div id="wkDoneMsg" style="color:#475569;font-size:14px;"></div>
                    </div>
                </div>
            </div>
            <div class="wk-foot">
                <button type="button" class="wk-btn ghost" id="wkBack" onclick="wkGoBack()">Cancel</button>
                <button type="button" class="wk-btn primary" id="wkNext" onclick="wkGoNext()">Next</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var DATA = <?php echo json_encode($wk_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    var FEE = <?php echo (float)$wk_fee; ?>;
    var S = { step: 1, type: '', mode: 'new', chosen: null, busy: false, done: false };
    var $ = function (id) { return document.getElementById(id); };
    var form = $('wkForm');
    function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function q(sel, root) { return Array.prototype.slice.call((root || form).querySelectorAll(sel)); }
    function val(name) { var el = form.elements[name]; return el ? String(el.value || '').trim() : ''; }

    function fillSelect(sel, items, label) {
        sel.innerHTML = '<option value="">— choose —</option>' + items.map(function (i) { return '<option value="' + i.id + '">' + esc(i.name) + ' · ' + label(i) + '</option>'; }).join('');
    }
    fillSelect($('wkHouse'), DATA.houses, function (i) { return peso(i.price) + '/night · up to ' + i.capacity; });
    fillSelect($('wkTour'), DATA.tours, function (i) { return peso(i.price) + ' · up to ' + i.max; });
    fillSelect($('wkFood'), DATA.foods, function (i) { return i.sizes.length ? 'sizes from ' + peso(Math.min.apply(null, i.sizes.map(function (s) { return s.price; }))) : peso(i.price); });
    if (!DATA.houses.length) $('wkHouse').innerHTML = '<option value="">No available houses</option>';
    if (!DATA.tours.length) $('wkTour').innerHTML = '<option value="">No available tours</option>';
    if (!DATA.foods.length) $('wkFood').innerHTML = '<option value="">No available food</option>';

    function find(list, id) { id = parseInt(id, 10); for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return null; }

    function setErr(msg) { var e = $('wkErr'); e.textContent = msg || ''; e.classList.toggle('show', !!msg); if (msg) { var b = $('wkBody'); b.scrollTop = 0; } }

    // ---------- items & totals
    function activeItems() { return S.type === 'package' ? q('input[name="package_items[]"]:checked').map(function (c) { return c.value; }) : (S.type ? [S.type] : []); }
    function nights() { var a = val('check_in'), b = val('check_out'); if (!a || !b) return 0; var d = Math.round((new Date(b) - new Date(a)) / 86400000); return d > 0 ? d : 0; }
    function priceFor(k) {
        if (k === 'house') { var h = find(DATA.houses, val('house_id')); return h ? h.price * nights() : 0; }
        if (k === 'tour') { var t = find(DATA.tours, val('tour_id')); return t ? t.price : 0; }
        var f = find(DATA.foods, val('food_id')); if (!f) return 0;
        var qn = parseInt(val('food_quantity'), 10); if (!(qn >= 1)) qn = 0;
        if (f.sizes.length) { var sv = val('size_variant'); var s = f.sizes.filter(function (x) { return String(x.i) === sv; })[0]; return s ? s.price * qn : 0; }
        return f.price * qn;
    }
    function total() { return activeItems().reduce(function (s, k) { return s + priceFor(k); }, 0); }
    function refreshTotal() { $('wkTotal').textContent = peso(total()); }

    function syncSections() {
        var items = activeItems();
        [['house', 'wkSecHouse'], ['tour', 'wkSecTour'], ['food', 'wkSecFood']].forEach(function (p) {
            var on = items.indexOf(p[0]) !== -1, sec = $(p[1]);
            sec.classList.toggle('wk-hide', !on);
            q('input,select,textarea', sec).forEach(function (el) { el.disabled = !on; });
        });
        $('wkPkgItems').classList.toggle('wk-hide', S.type !== 'package');
        q('input[name="package_items[]"]').forEach(function (c) { c.disabled = S.type !== 'package'; });
        q('.wk-type').forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-type') === S.type); });
        $('wkType').value = S.type;
        if (window.wkSyncAddrRef) window.wkSyncAddrRef();
        refreshTotal();
    }
    q('.wk-type').forEach(function (t) { t.addEventListener('click', function () { S.type = t.getAttribute('data-type'); syncSections(); setErr(''); }); });
    q('input[name="package_items[]"]').forEach(function (c) { c.addEventListener('change', syncSections); });
    ['house_id', 'check_in', 'check_out', 'tour_id', 'food_id', 'size_variant'].forEach(function (n) { var el = form.elements[n]; if (el) el.addEventListener('change', refreshTotal); });
    $('wkQty').addEventListener('input', refreshTotal); $('wkQty').addEventListener('change', refreshTotal);

    $('wkHouse').addEventListener('change', function () { var h = find(DATA.houses, this.value); $('wkHouseCap').textContent = h ? '(max ' + h.capacity + ')' : ''; if (h) $('wkPax').max = h.capacity; });
    $('wkTour').addEventListener('change', function () { var t = find(DATA.tours, this.value); $('wkTourCap').textContent = t ? '(max ' + t.max + ')' : ''; if (t) form.elements['tour_guests'].max = t.max; });
    // ---------- house stay window (package with a house): guides staff before "Create Booking".
    // Display/guidance only — WalkInBookingService + AvailabilityService::packageWindowError stay the final check.
    function wkMonthLbl(d) { return ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][parseInt(d.slice(5, 7), 10) - 1] + ' ' + parseInt(d.slice(8, 10), 10) + ', ' + d.slice(0, 4); }
    function wkTimeLbl(t) { var h = parseInt(t.slice(0, 2), 10), m = t.slice(3, 5); return (h % 12 || 12) + ':' + m + ' ' + (h >= 12 ? 'PM' : 'AM'); }
    function wkWindow() {
        if (S.type !== 'package' || activeItems().indexOf('house') === -1) return null;
        var a = val('check_in'), b = val('check_out'), t = val('check_in_time');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(a) || !/^\d{4}-\d{2}-\d{2}$/.test(b) || b < a || !/^\d{2}:\d{2}/.test(t)) return null;
        t = t.slice(0, 5);
        return { a: a, b: b, t: t, start: a + ' ' + t, end: b + ' ' + t, label: wkMonthLbl(a) + ' ' + wkTimeLbl(t) + ' to ' + wkMonthLbl(b) + ' ' + wkTimeLbl(t) };
    }
    function wkWindowMsg(label, d, t) {
        var w = wkWindow(); if (!w || !d || !t) return '';
        var dt = d + ' ' + t.slice(0, 5);
        return (dt < w.start || dt > w.end) ? label + ' must be during the house stay: ' + w.label + '.' : '';
    }
    function wkSyncWindow() {
        var w = wkWindow();
        q('.wk-stay-note').forEach(function (n) {
            n.classList.toggle('wk-hide', !w);
            if (w) n.innerHTML = '<i class="fas fa-calendar-check"></i> <strong>Selected house stay:</strong> ' + w.label + '.<br>The ' + n.getAttribute('data-for') + ' schedule must be within this stay.';
        });
        [['wkTourDate', 'wkTourTime', 'Tour'], ['wkFoodDate', 'wkFoodTime', 'Food']].forEach(function (p) {
            var d = $(p[0]), t = $(p[1]), warn = t.parentNode.querySelector('.wk-stay-warn');
            if (w) { d.min = w.a > '<?php echo $wk_today; ?>' ? w.a : '<?php echo $wk_today; ?>'; d.max = w.b; }
            else { d.min = '<?php echo $wk_today; ?>'; d.removeAttribute('max'); }
            // first day: not before check-in time; last day: not after check-out time (same as check-in time)
            t.removeAttribute('min'); t.removeAttribute('max');
            if (w && d.value === w.a) t.min = w.t;
            if (w && d.value === w.b) t.max = w.t;
            var msg = wkWindowMsg(p[2], d.value, t.value);
            if (msg && !warn) { warn = document.createElement('small'); warn.className = 'wk-stay-warn'; t.parentNode.appendChild(warn); }
            if (warn) { warn.textContent = msg; warn.classList.toggle('wk-hide', !msg); }
        });
    }
    ['check_in', 'check_out', 'check_in_time', 'tour_date', 'tour_time', 'food_date', 'food_time'].forEach(function (n) { var el = form.elements[n]; if (el) { el.addEventListener('change', wkSyncWindow); el.addEventListener('input', wkSyncWindow); } });
    q('input[name="package_items[]"]').forEach(function (c) { c.addEventListener('change', wkSyncWindow); });
    q('.wk-type').forEach(function (t) { t.addEventListener('click', wkSyncWindow); });
    function wkSyncOut() { $('wkOutTime').value = $('wkInTime').value; }
    $('wkInTime').addEventListener('input', wkSyncOut); $('wkInTime').addEventListener('change', wkSyncOut);
    $('wkIn').addEventListener('change', function () { if (this.value) { var o = $('wkOut'); o.min = this.value; if (o.value && o.value <= this.value) o.value = ''; } });
    $('wkFood').addEventListener('change', function () {
        var f = find(DATA.foods, this.value), w = $('wkSizeWrap'), s = $('wkSize');
        if (f && f.sizes.length) { s.innerHTML = '<option value="">— choose size —</option>' + f.sizes.map(function (x) { return '<option value="' + x.i + '">' + esc(x.size) + ' · ' + peso(x.price) + '</option>'; }).join(''); w.classList.remove('wk-hide'); s.disabled = false; }
        else { s.innerHTML = ''; w.classList.add('wk-hide'); s.disabled = true; }
        refreshTotal();
    });
    // Delivery: with a house in the package the booked house/unit is the location (the server fills it in)
    function wkSyncAddr() {
        var del = $('wkFulfil').value === 'delivery', hasHouse = activeItems().indexOf('house') !== -1;
        $('wkAddrWrap').classList.toggle('wk-hide', !del || hasHouse);
        $('wkAddrAuto').classList.toggle('wk-hide', !(del && hasHouse));
    }
    window.wkSyncAddrRef = wkSyncAddr;
    $('wkFulfil').addEventListener('change', wkSyncAddr);
    q('input[name="package_items[]"]').forEach(function (c) { c.addEventListener('change', wkSyncAddr); });
    q('input[name="payment_option"]').forEach(function (r) {
        r.addEventListener('change', function () {
            q('#wkPay label').forEach(function (l) { l.classList.toggle('on', l.querySelector('input').checked); });
            $('wkGcashWrap').classList.toggle('wk-hide', r.value !== 'gcash' || !r.checked);
        });
    });

    // ---------- availability calendars (House stay · Tour/Boat · Food)
    // GUIDANCE ONLY. Every state (booked / blocked / outside the house stay) comes from
    // booking-management.php?walkin_availability=1, which asks AvailabilityService — the same calls create()
    // makes. No availability rule lives here, and create() still re-checks everything inside its transaction.
    var WK_TODAY = '<?php echo $wk_today; ?>';
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    function ymAdd(ym, n) { var y = parseInt(ym.slice(0, 4), 10), m = parseInt(ym.slice(5, 7), 10) - 1 + n; y += Math.floor(m / 12); m = ((m % 12) + 12) % 12; return y + '-' + ('0' + (m + 1)).slice(-2); }
    var YM_NOW = WK_TODAY.slice(0, 7), YM_MAX = '9999-12';   // no booking horizon: only the last representable month ends the calendar
    function setHidden(el, v) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
    var MSG_LOAD_FAIL = 'Availability could not be loaded. You can still pick a date; the system checks again when you create the booking.';

    function makeCal(cfg) {
        var box = cfg.box, st = { ym: '', view: null, cache: {}, req: 0, rangeReq: 0, data: null, loading: false, maxOut: '', rkey: '', pend: 0, keepYm: '' };
        var dow = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map(function (d) { return '<span>' + d + '</span>'; }).join('');
        box.innerHTML = '<div class="wk-cal-hint wk-hide"></div><div class="wk-cal-main">' +
            '<div class="wk-cal-head"><button type="button" class="wk-cal-nav" data-nav="-1" aria-label="Previous month">&lsaquo;</button><strong class="wk-cal-title"></strong><button type="button" class="wk-cal-nav" data-nav="1" aria-label="Next month">&rsaquo;</button></div>' +
            '<div class="wk-cal-dow" aria-hidden="true">' + dow + '</div><div class="wk-cal-grid"></div>' +
            '<div class="wk-cal-legend"><span><i class="l-free"></i>Available</span><span><i class="l-booked"></i>Unavailable</span><span><i class="l-blocked"></i>Blocked</span><span><i class="l-sel"></i>Selected</span><span><i class="l-today"></i>Today</span></div></div>' +
            '<div class="wk-cal-msg" role="status" aria-live="polite"></div>';
        var hint = box.querySelector('.wk-cal-hint'), main = box.querySelector('.wk-cal-main'), title = box.querySelector('.wk-cal-title'),
            grid = box.querySelector('.wk-cal-grid'), msg = box.querySelector('.wk-cal-msg'), prev = box.querySelector('[data-nav="-1"]'), next = box.querySelector('[data-nav="1"]');

        function say(t, info) { msg.textContent = t || ''; msg.classList.toggle('info', !!info); }
        function getJson(url) { return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }); }
        function stateOf(ds) { if (ds < WK_TODAY) return 'past'; return (st.data && st.data.days && st.data.days[ds]) || ''; }
        function startYm() {
            var c = cfg.ctx(), v = cfg.field.value, ym = v ? v.slice(0, 7) : (c.from ? c.from.slice(0, 7) : (st.keepYm || YM_NOW));   // chosen date > house-stay start > the month already being browsed
            return ym < YM_NOW ? YM_NOW : (ym > YM_MAX ? YM_MAX : ym);
        }

        function cls(ds, code, s) {
            if (cfg.range) {
                if (ds === s.a || ds === s.b) return 'sel';
                if (s.a && s.b && ds > s.a && ds < s.b) return 'range';
                if (s.a && !s.b && ds > s.a && code !== 'past') {            // choosing the check-out: only the stay limit matters
                    if (st.maxOut && ds > st.maxOut) return 'over';
                    return '';
                }
            } else if (ds === s.a) return 'sel';
            return code;
        }
        function render() {
            var c = cfg.ctx();
            hint.classList.toggle('wk-hide', !c.hint); main.classList.toggle('wk-hide', !!c.hint);
            if (c.hint) { hint.textContent = c.hint; return; }
            if (!st.ym) st.ym = startYm();
            var ym = st.ym, y = parseInt(ym.slice(0, 4), 10), m = parseInt(ym.slice(5, 7), 10);
            title.textContent = MONTHS[m - 1] + ' ' + y;
            prev.disabled = ym <= YM_NOW; next.disabled = ym >= YM_MAX;
            box.classList.toggle('loading', st.loading);
            var s = cfg.range ? { a: cfg.field.value, b: cfg.out.value } : { a: cfg.field.value };
            var first = new Date(y, m - 1, 1).getDay(), n = new Date(y, m, 0).getDate(), h = '';
            for (var i = 0; i < first; i++) h += '<span class="wk-d pad" aria-hidden="true"></span>';
            for (var d = 1; d <= n; d++) {
                var ds = ym + '-' + ('0' + d).slice(-2), code = stateOf(ds), k = cls(ds, code, s);
                var dis = (k === 'past' || k === 'booked' || k === 'blocked' || k === 'outside' || k === 'over');
                var lbl = wkMonthLbl(ds) + ' — ' + (k === 'sel' ? 'selected' : k === 'range' ? 'in your stay' : k === 'booked' ? 'unavailable' : k === 'blocked' ? 'blocked' : k === 'past' ? 'past date' : k === 'outside' ? 'outside the house stay' : k === 'over' ? 'beyond the available stay' : 'available');
                h += '<button type="button" class="wk-d ' + k + (ds === WK_TODAY ? ' today' : '') + '" data-d="' + ds + '" aria-disabled="' + (dis ? 'true' : 'false') + '" aria-label="' + lbl + '"' + (k === 'sel' ? ' aria-pressed="true"' : '') + '>' + d + '</button>';
            }
            grid.innerHTML = h;
        }

        function afterLoad() {
            if (cfg.range || !st.data) return;
            var v = cfg.field.value;
            if (v && v.slice(0, 7) === st.data.ym && st.data.days[v]) {      // the chosen date is no longer selectable (resource/window changed, or booked meanwhile)
                var code = st.data.days[v];
                setHidden(cfg.field, '');
                say('Your selected ' + cfg.noun + ' date is no longer available (' + (code === 'outside' ? 'it is outside the house stay' : code === 'past' ? 'it has passed' : code === 'blocked' ? 'blocked' : 'already booked') + '). Please choose another date.');
                render();
            }
        }
        function load(force) {
            var c = cfg.ctx();
            if (c.hint) { st.data = null; st.loading = false; render(); return; }
            if (!st.ym) st.ym = startYm();
            var key = c.key + '|' + st.ym, hit = st.cache[key];
            if (!force && hit && Date.now() - hit.t < 60000) { st.data = hit.data; st.loading = false; render(); afterLoad(); return; }
            var my = ++st.req; st.loading = true; st.data = null; render();
            function fail(e) { if (my !== st.req) return; st.loading = false; st.data = null; say(e || MSG_LOAD_FAIL); render(); }
            getJson('booking-management.php?walkin_availability=1&' + c.qs + '&ym=' + encodeURIComponent(st.ym))
                .then(function (j) {
                    if (my !== st.req) return;                               // a newer request superseded this one
                    if (!j || !j.ok) { fail(j && j.error ? j.error : null); return; }
                    st.loading = false; st.data = j; st.cache[key] = { t: Date.now(), data: j };
                    render(); afterLoad();
                })
                .catch(function () { fail(null); });
        }

        // ---- House: longest valid stay for a chosen check-in (server: houseConflict binary search)
        function rangeCall(ci) {
            var t = (val('check_in_time') || '14:00').slice(0, 5);
            return getJson('booking-management.php?walkin_availability=1&mode=range&id=' + encodeURIComponent(val('house_id')) + '&check_in=' + encodeURIComponent(ci) + '&time=' + encodeURIComponent(t))
                .catch(function () { say(MSG_LOAD_FAIL); return { ok: true, max_out: '' }; });
        }
        function startStay(ds) {
            var my = ++st.rangeReq; st.pend++; say('Checking availability…', true);
            rangeCall(ds).then(function (j) {
                st.pend = Math.max(0, st.pend - 1);
                if (my !== st.rangeReq) return;
                if (!j || !j.ok) { say((j && j.message) || cfg.deny('booked')); return; }
                if (cfg.out.value) setHidden(cfg.out, '');
                st.maxOut = j.max_out || ''; st.rkey = cfg.ctx().key + '|' + ds;
                setHidden(cfg.field, ds);
                say(st.maxOut ? 'Now choose a check-out date (latest: ' + wkMonthLbl(st.maxOut) + ').' : 'Now choose a check-out date.', true);
                render();
            });
        }
        function revalidate(force) {
            var a = cfg.field.value, b = cfg.out.value, c = cfg.ctx();
            if (!a || c.hint) { st.maxOut = ''; return; }
            var rk = c.key + '|' + a;
            if (!force && rk === st.rkey) return;
            var my = ++st.rangeReq;
            rangeCall(a).then(function (j) {
                if (my !== st.rangeReq) return;
                st.rkey = rk;
                if (!j || !j.ok) {
                    if (b) setHidden(cfg.out, '');
                    setHidden(cfg.field, ''); st.maxOut = ''; st.rkey = '';
                    say('The selected check-in date is no longer available for this unit and time. Please choose another date.');
                } else {
                    st.maxOut = j.max_out || '';
                    if (b && st.maxOut && b > st.maxOut) { setHidden(cfg.out, ''); say('Your selected stay overlaps an unavailable period. Choose a new check-out date.'); }
                }
                render();
            });
        }

        function pick(ds) {
            if (st.loading || st.pend > 0) { say('Checking availability…', true); return; }      // ignore clicks while a lookup is in flight
            var code = stateOf(ds);
            if (!cfg.range) {
                if (code) { say(cfg.deny(code)); return; }
                say(''); setHidden(cfg.field, ds); return;
            }
            var a = cfg.field.value, b = cfg.out.value;
            if (a && !b && ds > a) {                                          // second click: check-out
                if (st.maxOut && ds > st.maxOut) { say('Your selected stay overlaps an unavailable period.'); return; }
                say(''); setHidden(cfg.out, ds); return;
            }
            if (code) { say(cfg.deny(code)); return; }                          // otherwise: a (new) check-in
            startStay(ds);
        }
        grid.addEventListener('click', function (e) { var b = e.target.closest('.wk-d[data-d]'); if (b) pick(b.getAttribute('data-d')); });
        box.addEventListener('click', function (e) {
            var nv = e.target.closest('[data-nav]'); if (!nv || nv.disabled) return;
            var n = ymAdd(st.ym, parseInt(nv.getAttribute('data-nav'), 10));
            if (n < YM_NOW || n > YM_MAX) return;
            st.ym = n; say(''); load(true);          // paging months always asks the server again
        });

        return {
            // re-read context (resource / time / house window); reload only what changed
            sync: function (force) {
                var c = cfg.ctx();
                if (c.noRes) { if (cfg.field.value) setHidden(cfg.field, ''); if (cfg.out && cfg.out.value) setHidden(cfg.out, ''); st.maxOut = ''; st.rkey = ''; }
                if (c.view !== st.view) { st.view = c.view; st.keepYm = st.ym; st.ym = ''; st.maxOut = ''; st.rkey = ''; say(''); }
                if (force) { st.cache = {}; st.rkey = ''; }
                load(false);
                if (cfg.range) revalidate(!!force);
            },
            render: render,
            say: say,
            focus: function () { try { box.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (x) { box.scrollIntoView(); } },
            reset: function () { st.req++; st.rangeReq++; st.pend = 0; st.cache = {}; st.data = null; st.ym = ''; st.view = null; st.keepYm = ''; st.maxOut = ''; st.rkey = ''; st.loading = false; say(''); render(); }
        };
    }

    function stayCtx(kind, noun) {
        var id = val(kind + '_id');
        if (!id) return { noRes: true, view: 'none', key: 'none', hint: 'Choose a ' + noun + ' first to see its available dates.' };
        var w = wkWindow();
        if (S.type === 'package' && activeItems().indexOf('house') !== -1 && !w) return { view: kind + id + '|nowin', key: kind + id + '|nowin', hint: 'Select the house stay first. The ' + kind + ' date must fall within it.' };
        var wq = w ? '&win_in=' + encodeURIComponent(w.a) + '&win_time=' + encodeURIComponent(w.t) + '&win_out=' + encodeURIComponent(w.b) : '';
        var v = kind + id + '|' + (w ? w.a + w.b + w.t : '');
        return { view: v, key: v, qs: 'type=' + kind + '&id=' + encodeURIComponent(id) + wq, from: w ? (w.a > WK_TODAY ? w.a : WK_TODAY) : '' };
    }
    function outsideMsg() { var w = wkWindow(); return w ? 'That date is outside the house stay (' + w.label + ').' : 'That date is outside the house stay.'; }
    var cals = {
        house: makeCal({ box: $('wkCalHouse'), field: $('wkIn'), out: $('wkOut'), range: true, noun: 'check-in',
            ctx: function () {
                var id = val('house_id');
                if (!id) return { noRes: true, view: 'none', key: 'none', hint: 'Choose a house first to see its available dates.' };
                var t = (val('check_in_time') || '14:00').slice(0, 5);
                return { view: 'house' + id, key: 'house' + id + '|' + t, qs: 'type=house&id=' + encodeURIComponent(id) + '&time=' + encodeURIComponent(t) };
            },
            deny: function (c) { return c === 'past' ? 'That date has passed. Choose a future date.' : 'That date is unavailable for this unit.'; } }),
        tour: makeCal({ box: $('wkCalTour'), field: $('wkTourDate'), range: false, noun: 'tour',
            ctx: function () { return stayCtx('tour', 'boat'); },
            deny: function (c) { return c === 'past' ? 'That date has passed. Choose a future date.' : c === 'outside' ? outsideMsg() : c === 'blocked' ? 'This date is blocked for this boat.' : 'This boat is already booked on this date.'; } }),
        food: makeCal({ box: $('wkCalFood'), field: $('wkFoodDate'), range: false, noun: 'food',
            ctx: function () { return stayCtx('food', 'food package'); },
            deny: function (c) { return c === 'past' ? 'That date has passed. Choose a future date.' : c === 'outside' ? outsideMsg() : 'That date is unavailable for this food item.'; } })
    };
    function syncBtns() {
        [['wkIn', 'wkInBtn'], ['wkOut', 'wkOutBtn'], ['wkTourDate', 'wkTourDateBtn'], ['wkFoodDate', 'wkFoodDateBtn']].forEach(function (p) {
            var v = $(p[0]).value, b = $(p[1]);
            b.textContent = v ? wkMonthLbl(v) : b.getAttribute('data-empty'); b.classList.toggle('empty', !v);
        });
    }
    function calsRefresh(force) {
        var items = activeItems();
        ['house', 'tour', 'food'].forEach(function (k) { if (items.indexOf(k) !== -1) cals[k].sync(force); });
    }
    function windowCalsRefresh() { var items = activeItems(); ['tour', 'food'].forEach(function (k) { if (items.indexOf(k) !== -1) cals[k].sync(false); }); }
    $('wkHouse').addEventListener('change', function () { cals.house.sync(false); windowCalsRefresh(); });
    $('wkTour').addEventListener('change', function () { cals.tour.sync(false); });
    $('wkFood').addEventListener('change', function () { cals.food.sync(false); });
    var wkTimeTimer = null;
    function onInTime() { clearTimeout(wkTimeTimer); wkTimeTimer = setTimeout(function () { if (activeItems().indexOf('house') !== -1) cals.house.sync(false); windowCalsRefresh(); }, 300); }
    $('wkInTime').addEventListener('input', onInTime); $('wkInTime').addEventListener('change', onInTime);
    ['wkIn', 'wkOut'].forEach(function (id) { $(id).addEventListener('change', function () { syncBtns(); cals.house.render(); windowCalsRefresh(); }); });
    $('wkTourDate').addEventListener('change', function () { syncBtns(); cals.tour.render(); });
    $('wkFoodDate').addEventListener('change', function () { syncBtns(); cals.food.render(); });
    q('.wk-type').forEach(function (t) { t.addEventListener('click', function () { calsRefresh(false); }); });
    q('input[name="package_items[]"]').forEach(function (c) { c.addEventListener('change', function () { calsRefresh(false); }); });
    $('wkInBtn').addEventListener('click', function () {
        if (val('check_out')) setHidden($('wkOut'), '');
        if (val('check_in')) setHidden($('wkIn'), '');                    // start over: the next click is a new check-in
        cals.house.say(val('house_id') ? 'Choose an available check-in date.' : '', true); cals.house.focus();
    });
    $('wkOutBtn').addEventListener('click', function () {
        if (!val('check_in')) { cals.house.say('Choose an available check-in date.', true); cals.house.focus(); return; }
        if (val('check_out')) setHidden($('wkOut'), '');
        cals.house.say('Choose a check-out date.', true); cals.house.focus();
    });
    $('wkTourDateBtn').addEventListener('click', function () { cals.tour.focus(); });
    $('wkFoodDateBtn').addEventListener('click', function () { cals.food.focus(); });
    function calsReset() { ['wkIn', 'wkOut', 'wkTourDate', 'wkFoodDate'].forEach(function (id) { $(id).value = ''; }); ['house', 'tour', 'food'].forEach(function (k) { cals[k].reset(); }); syncBtns(); }
    syncBtns();

    // ---------- guest step
    window.wkMode = function (m) {
        S.mode = m; $('wkGuestMode').value = m;
        $('wkModeNew').classList.toggle('on', m === 'new'); $('wkModeSearch').classList.toggle('on', m === 'existing');
        $('wkNew').classList.toggle('wk-hide', m !== 'new'); $('wkExisting').classList.toggle('wk-hide', m !== 'existing');
        q('#wkNew input, #wkNew select').forEach(function (el) { el.disabled = (m !== 'new'); });
        setErr('');
    };
    var timer = null, seq = 0;
    $('wkSearch').addEventListener('input', function () {
        var v = this.value.trim(); clearTimeout(timer);
        if (v.length < 2) { $('wkResults').classList.add('wk-hide'); return; }
        timer = setTimeout(function () {
            var my = ++seq;
            fetch('booking-management.php?walkin_guest_search=1&q=' + encodeURIComponent(v), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (my !== seq) return;
                    var box = $('wkResults'), list = j.results || [];
                    box.classList.remove('wk-hide');
                    box.innerHTML = list.length ? list.map(function (g) {
                        return '<div class="wk-result" data-id="' + g.id + '" data-name="' + esc(g.name) + '" data-contact="' + esc(g.contact || '') + '"><div><b>' + esc(g.name) + '</b><br><span>' + esc(g.contact || 'no number') + (g.email ? ' · ' + esc(g.email) : '') + '</span></div><span class="wk-tag ' + (g.source === 'walk_in' ? 'walk' : '') + '">' + (g.source === 'walk_in' ? 'Walk-in' : 'Online') + '</span></div>';
                    }).join('') : '<div class="wk-result" style="cursor:default;color:#64748b;">No guest found. Use “New walk-in guest”.</div>';
                })
                .catch(function () { $('wkResults').classList.remove('wk-hide'); $('wkResults').innerHTML = '<div class="wk-result" style="cursor:default;color:#b91c1c;">Search failed. Please try again.</div>'; });
        }, 250);
    });
    $('wkResults').addEventListener('click', function (e) {
        var row = e.target.closest('.wk-result[data-id]'); if (!row) return;
        S.chosen = { id: row.getAttribute('data-id'), name: row.getAttribute('data-name'), contact: row.getAttribute('data-contact') };
        $('wkGuestId').value = S.chosen.id; $('wkResults').classList.add('wk-hide');
        var c = $('wkChosen'); c.classList.remove('wk-hide');
        c.innerHTML = '<div><b>' + esc(S.chosen.name) + '</b><br><span style="color:#64748b;font-size:12px;">' + esc(S.chosen.contact || '') + '</span></div><button type="button" class="wk-btn ghost" style="padding:6px 12px;" onclick="wkClearGuest()">Change</button>';
    });
    window.wkClearGuest = function () { S.chosen = null; $('wkGuestId').value = ''; $('wkChosen').classList.add('wk-hide'); $('wkSearch').focus(); };

    // ---------- navigation / validation
    function showStep(n) {
        S.step = n;
        q('[data-step]').forEach(function (el) { el.classList.toggle('wk-hide', String(el.getAttribute('data-step')) !== String(n)); });
        q('.wk-step', $('wkSteps')).forEach(function (el, i) { var k = i + 1; el.classList.toggle('active', k === n); el.classList.toggle('done', n === 'done' || k < n); });
        var back = $('wkBack'), next = $('wkNext');
        if (n === 'done') { back.textContent = 'Create another'; next.textContent = 'Done'; next.className = 'wk-btn ok'; next.disabled = false; return; }
        back.textContent = n === 1 ? 'Cancel' : 'Back';
        next.textContent = n === 3 ? 'Create Booking' : 'Next'; next.className = 'wk-btn ' + (n === 3 ? 'ok' : 'primary'); next.disabled = false;
        setErr('');
        if (n === 2) calsRefresh(true);          // fresh data every time the booking step is shown
    }
    function validPhone(v) { var d = String(v).replace(/[^0-9]/g, ''); if (d.indexOf('63') === 0 && d.length === 12) d = d.slice(2); if (d.charAt(0) === '0') d = d.slice(1); return /^9[0-9]{9}$/.test(d); }
    function validate(n) {
        if (n === 1) {
            if (S.mode === 'existing') return $('wkGuestId').value ? '' : 'Search and select a guest, or switch to “New walk-in guest”.';
            var nm = val('wg_name');
            if (!nm) return 'Guest full name is required.';
            if (/[0-9]/.test(nm) || !/^[A-Za-zÀ-ÿñÑ\s\-'.]+$/.test(nm) || nm.length < 2) return 'Guest name can only contain letters, spaces, hyphens, apostrophes and periods.';
            if (!val('wg_contact')) return 'Contact number is required.';
            if (!validPhone(val('wg_contact'))) return 'Enter a valid PH mobile number (e.g. 09123456789).';
            if (val('wg_emergency_number') && !validPhone(val('wg_emergency_number'))) return 'Emergency contact number is not a valid PH mobile number.';
            return '';
        }
        if (n === 2) {
            if (!S.type) return 'Choose a booking type.';
            var items = activeItems();
            if (!items.length) return 'Choose at least one item for the package.';
            for (var i = 0; i < items.length; i++) {
                var k = items[i];
                if (k === 'house') {
                    var h = find(DATA.houses, val('house_id')); if (!h) return 'Choose a house.';
                    if (!val('check_in') || !val('check_out')) return 'Select check-in and check-out dates.';
                    if (val('check_in') < '<?php echo $wk_today; ?>') return 'Selected date is no longer available. Please choose a future date.';
                    if (nights() < 1) return 'Check-out must be after check-in.';
                    var px = parseInt(val('guests'), 10); if (!px || px < 1) return 'Enter the number of guests.'; if (px > h.capacity) return h.name + ' fits up to ' + h.capacity + ' guests.';
                } else if (k === 'tour') {
                    var t = find(DATA.tours, val('tour_id')); if (!t) return 'Choose a boat/tour.';
                    if (!val('tour_date')) return 'Select the tour date.'; if (val('tour_date') < '<?php echo $wk_today; ?>') return 'Selected date is no longer available. Please choose a future date.';
                    if (!val('tour_time')) return 'Select the tour time.';
                    var wmT = wkWindowMsg('Tour', val('tour_date'), val('tour_time')); if (wmT) return wmT;
                    var tg = parseInt(val('tour_guests'), 10); if (!tg || tg < 1) return 'Enter the number of guests.'; if (tg > t.max) return t.name + ' allows up to ' + t.max + ' guests.';
                } else {
                    var f = find(DATA.foods, val('food_id')); if (!f) return 'Choose a food package.';
                    if (f.sizes.length && val('size_variant') === '') return 'Choose a size.';
                    if (!val('food_date')) return 'Select the food date.'; if (val('food_date') < '<?php echo $wk_today; ?>') return 'Selected date is no longer available. Please choose a future date.';
                    if (!val('food_time')) return 'Select the food time.';
                    var wmF = wkWindowMsg('Food', val('food_date'), val('food_time')); if (wmF) return wmF;
                    if (!/^[1-9][0-9]{0,2}$/.test(val('food_quantity')) || parseInt(val('food_quantity'), 10) > <?php echo (int)PaymentService::FOOD_MAX_QUANTITY; ?>) return 'Food quantity must be a whole number from 1 to <?php echo (int)PaymentService::FOOD_MAX_QUANTITY; ?>.';
                    if (val('fulfillment') === 'delivery' && S.type === 'package' && activeItems().indexOf('house') === -1) return 'Delivery requires a selected house because the order will be delivered to your booked unit. Choose Pickup or add the house.';
                }
            }
            return '';
        }
        if (n === 3) {
            var pay = (form.querySelector('input[name="payment_option"]:checked') || {}).value;
            if (pay === 'gcash' && !/^[A-Za-z0-9\- ]{6,30}$/.test(val('gcash_reference'))) return 'Enter the GCash reference number (6–30 letters/numbers).';
        }
        return '';
    }
    function buildSummary() {
        var rows = [], g = S.mode === 'existing' && S.chosen ? S.chosen.name : val('wg_name');
        rows.push(['Guest', esc(g) + (S.mode === 'new' ? ' <span class="wk-tag walk">New walk-in</span>' : '')]);
        activeItems().forEach(function (k) {
            if (k === 'house') { var h = find(DATA.houses, val('house_id')); rows.push(['House', esc(h.name) + ' · ' + val('check_in') + ' → ' + val('check_out') + ' (' + nights() + ' night' + (nights() > 1 ? 's' : '') + ')']); }
            if (k === 'tour') { var t = find(DATA.tours, val('tour_id')); rows.push(['Tour', esc(t.name) + ' · ' + val('tour_date') + ' ' + val('tour_time')]); }
            if (k === 'food') { var f = find(DATA.foods, val('food_id')); rows.push(['Food', esc(f.name) + ' × ' + esc(val('food_quantity')) + ' · ' + val('food_date') + ' ' + val('food_time')]); }
        });
        rows.push(['Booking type', S.type === 'package' ? 'Package (' + activeItems().length + ' items)' : S.type.charAt(0).toUpperCase() + S.type.slice(1)]);
        rows.push(['<strong>Total</strong>', '<strong>' + peso(total()) + '</strong>']);
        rows.push(['Reservation fee', peso(Math.min(FEE, total()))]);
        $('wkSummary').innerHTML = rows.map(function (r) { return '<div><span>' + r[0] + '</span><span style="text-align:right;">' + r[1] + '</span></div>'; }).join('');
    }

    window.wkGoBack = function () {
        if (S.busy) return;
        if (S.step === 'done') { wkReset(); return; }
        if (S.step === 1) { wkClose(); return; }
        showStep(S.step - 1);
    };
    window.wkGoNext = function () {
        if (S.busy) return;
        if (S.step === 'done') { location.reload(); return; }
        var e = validate(S.step); if (e) { setErr(e); return; }
        if (S.step === 1) { showStep(2); syncSections(); return; }
        if (S.step === 2) { buildSummary(); showStep(3); return; }
        submit();
    };

    function submit() {
        var btn = $('wkNext');
        S.busy = true; btn.disabled = true; btn.textContent = 'Creating…'; setErr('');
        var fd = new FormData(form);
        fetch('booking-management.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var j; try { j = JSON.parse(t); } catch (x) { throw new Error('Unexpected server response. Please check Booking Management before retrying.'); }
                if (!j.ok) throw new Error(j.error || 'Could not create the booking.');
                $('wkRef').textContent = j.reference;
                $('wkDoneMsg').innerHTML = esc(j.guest) + ' · ' + peso(j.total) + '<br>' + esc(j.payment_message || '');
                S.done = true; showStep('done');
            })
            .catch(function (err) { setErr(err.message || 'Something went wrong.'); btn.disabled = false; btn.textContent = 'Create Booking'; calsRefresh(true); })
            .then(function () { S.busy = false; });
    }

    function newNonce() { var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
    function wkReset() {
        form.reset(); calsReset(); wkSyncOut(); S.type = ''; S.chosen = null; S.done = false; $('wkGuestId').value = ''; $('wkChosen').classList.add('wk-hide'); $('wkResults').classList.add('wk-hide');
        $('wkNonce').value = newNonce(); $('wkSizeWrap').classList.add('wk-hide'); $('wkAddrWrap').classList.add('wk-hide'); $('wkAddrAuto').classList.add('wk-hide'); $('wkGcashWrap').classList.add('wk-hide');
        q('#wkPay label').forEach(function (l, i) { l.classList.toggle('on', i === 0); });
        wkMode('new'); syncSections(); wkSyncWindow(); showStep(1);
    }
    window.wkOpen = function () { wkReset(); $('walkinModal').classList.add('show'); $('walkinModal').setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; };
    window.wkClose = function () {
        if (S.busy) return;
        $('walkinModal').classList.remove('show'); $('walkinModal').setAttribute('aria-hidden', 'true'); document.body.style.overflow = '';
        if (S.done) location.reload();
    };
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && $('walkinModal').classList.contains('show')) wkClose(); });
    wkReset();
    if (/[?&]new_walkin=1(&|$)/.test(location.search)) wkOpen();   // dashboard quick action
})();
</script>
