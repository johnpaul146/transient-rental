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
    .wk-hide { display: none !important; }
    @media (max-width: 600px) { .wk-row { grid-template-columns: 1fr; } .wk-types { grid-template-columns: repeat(2, 1fr); } #walkinModal .modal-content { width: 100%; max-height: 100vh; height: 100vh; border-radius: 0; } .wk-head, .wk-body, .wk-foot { padding-left: 14px; padding-right: 14px; } .wk-steps { padding: 10px 14px 0; } .wk-btn { flex: 1; } }
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
                            <div class="wk-field"><label>Check-in <span class="req">*</span></label><input type="date" name="check_in" id="wkIn" min="<?php echo $wk_today; ?>"></div>
                            <div class="wk-field"><label>Check-out <span class="req">*</span></label><input type="date" name="check_out" id="wkOut" min="<?php echo $wk_today; ?>"></div>
                        </div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Check-in time</label><input type="time" name="check_in_time" value="14:00"></div>
                            <div class="wk-field"><label>Check-out time</label><input type="time" name="check_out_time" value="12:00"></div>
                        </div>
                        <div class="wk-field"><label>Number of guests <span class="req">*</span> <small id="wkHouseCap"></small></label><input type="number" name="guests" id="wkPax" min="1" value="1"></div>
                        <div class="wk-field"><label>Guest names <small>(optional · one per line · must match the number of guests)</small></label><textarea name="guest_names" rows="2"></textarea></div>
                    </div>

                    <div class="wk-sec wk-hide" id="wkSecTour"><h4><i class="fas fa-ship"></i> Tour / Boat</h4>
                        <div class="wk-field"><label>Boat / tour <span class="req">*</span></label><select name="tour_id" id="wkTour"></select></div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Tour date <span class="req">*</span></label><input type="date" name="tour_date" min="<?php echo $wk_today; ?>"></div>
                            <div class="wk-field"><label>Time <span class="req">*</span></label><input type="time" name="tour_time" value="08:00"></div>
                        </div>
                        <div class="wk-field"><label>Number of guests <span class="req">*</span> <small id="wkTourCap"></small></label><input type="number" name="tour_guests" min="1" value="1"></div>
                    </div>

                    <div class="wk-sec wk-hide" id="wkSecFood"><h4><i class="fas fa-utensils"></i> Food</h4>
                        <div class="wk-field"><label>Food package <span class="req">*</span></label><select name="food_id" id="wkFood"></select></div>
                        <div class="wk-field wk-hide" id="wkSizeWrap"><label>Size <span class="req">*</span></label><select name="size_variant" id="wkSize"></select></div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Date <span class="req">*</span></label><input type="date" name="food_date" min="<?php echo $wk_today; ?>"></div>
                            <div class="wk-field"><label>Time <span class="req">*</span></label><input type="time" name="food_time" value="12:00"></div>
                        </div>
                        <div class="wk-row">
                            <div class="wk-field"><label>Pickup or delivery</label><select name="fulfillment" id="wkFulfil"><option value="pickup">Pickup</option><option value="delivery">Delivery</option></select></div>
                            <div class="wk-field wk-hide" id="wkAddrWrap"><label>Delivery address <span class="req">*</span></label><input type="text" name="delivery_address" maxlength="500"></div>
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
        if (f.sizes.length) { var sv = val('size_variant'); var s = f.sizes.filter(function (x) { return String(x.i) === sv; })[0]; return s ? s.price : 0; }
        return f.price;
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
        refreshTotal();
    }
    q('.wk-type').forEach(function (t) { t.addEventListener('click', function () { S.type = t.getAttribute('data-type'); syncSections(); setErr(''); }); });
    q('input[name="package_items[]"]').forEach(function (c) { c.addEventListener('change', syncSections); });
    ['house_id', 'check_in', 'check_out', 'tour_id', 'food_id', 'size_variant'].forEach(function (n) { var el = form.elements[n]; if (el) el.addEventListener('change', refreshTotal); });

    $('wkHouse').addEventListener('change', function () { var h = find(DATA.houses, this.value); $('wkHouseCap').textContent = h ? '(max ' + h.capacity + ')' : ''; if (h) $('wkPax').max = h.capacity; });
    $('wkTour').addEventListener('change', function () { var t = find(DATA.tours, this.value); $('wkTourCap').textContent = t ? '(max ' + t.max + ')' : ''; if (t) form.elements['tour_guests'].max = t.max; });
    $('wkIn').addEventListener('change', function () { if (this.value) { var o = $('wkOut'); o.min = this.value; if (o.value && o.value <= this.value) o.value = ''; } });
    $('wkFood').addEventListener('change', function () {
        var f = find(DATA.foods, this.value), w = $('wkSizeWrap'), s = $('wkSize');
        if (f && f.sizes.length) { s.innerHTML = '<option value="">— choose size —</option>' + f.sizes.map(function (x) { return '<option value="' + x.i + '">' + esc(x.size) + ' · ' + peso(x.price) + '</option>'; }).join(''); w.classList.remove('wk-hide'); s.disabled = false; }
        else { s.innerHTML = ''; w.classList.add('wk-hide'); s.disabled = true; }
        refreshTotal();
    });
    $('wkFulfil').addEventListener('change', function () { $('wkAddrWrap').classList.toggle('wk-hide', this.value !== 'delivery'); });
    q('input[name="payment_option"]').forEach(function (r) {
        r.addEventListener('change', function () {
            q('#wkPay label').forEach(function (l) { l.classList.toggle('on', l.querySelector('input').checked); });
            $('wkGcashWrap').classList.toggle('wk-hide', r.value !== 'gcash' || !r.checked);
        });
    });

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
                    var tg = parseInt(val('tour_guests'), 10); if (!tg || tg < 1) return 'Enter the number of guests.'; if (tg > t.max) return t.name + ' allows up to ' + t.max + ' guests.';
                } else {
                    var f = find(DATA.foods, val('food_id')); if (!f) return 'Choose a food package.';
                    if (f.sizes.length && val('size_variant') === '') return 'Choose a size.';
                    if (!val('food_date')) return 'Select the food date.'; if (val('food_date') < '<?php echo $wk_today; ?>') return 'Selected date is no longer available. Please choose a future date.';
                    if (!val('food_time')) return 'Select the food time.';
                    if (val('fulfillment') === 'delivery' && !val('delivery_address')) return 'Delivery address is required for delivery.';
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
            if (k === 'food') { var f = find(DATA.foods, val('food_id')); rows.push(['Food', esc(f.name) + ' · ' + val('food_date') + ' ' + val('food_time')]); }
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
            .catch(function (err) { setErr(err.message || 'Something went wrong.'); btn.disabled = false; btn.textContent = 'Create Booking'; })
            .then(function () { S.busy = false; });
    }

    function newNonce() { var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
    function wkReset() {
        form.reset(); S.type = ''; S.chosen = null; S.done = false; $('wkGuestId').value = ''; $('wkChosen').classList.add('wk-hide'); $('wkResults').classList.add('wk-hide');
        $('wkNonce').value = newNonce(); $('wkSizeWrap').classList.add('wk-hide'); $('wkAddrWrap').classList.add('wk-hide'); $('wkGcashWrap').classList.add('wk-hide');
        q('#wkPay label').forEach(function (l, i) { l.classList.toggle('on', i === 0); });
        wkMode('new'); syncSections(); showStep(1);
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
