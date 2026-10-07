<?php
/**
 * includes/staff-edit-lock.php
 *
 * UI-only helper for the admin management pages (house / tour / activities / food).
 * For STAFF it turns the "edit" modal into an availability-only form:
 * every field except the availability control is locked and a notice is shown.
 *
 * The real enforcement is server-side (each page's staff handler only writes the
 * availability column); this just stops staff from typing into fields that would
 * be silently ignored.
 */
if (($_SESSION['role'] ?? '') !== 'staff') return;
?>
<style>
    form.staff-locked .staff-lock-notice{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px;line-height:1.45}
    form.staff-locked .staff-lock-notice i{margin-right:6px}
    form.staff-locked input:disabled,form.staff-locked select:disabled,form.staff-locked textarea:disabled{background:#f1f5f9;color:#64748b;cursor:not-allowed}
    form.staff-locked .staff-hide,form.staff-locked .remove-image-box,form.staff-locked input[type=file],form.staff-locked .file-upload-hint,
    form.staff-locked .size-row button,form.staff-locked [onclick*="addSizeRow"],form.staff-locked [onclick*="removeSizeRow"]{display:none!important}
</style>
<script>
(function () {
    var ALLOW = ['status', 'is_available'];
    document.querySelectorAll('form').forEach(function (form) {
        var submit = form.querySelector('button[type="submit"][name^="edit_"]');
        if (!submit) return;
        form.classList.add('staff-locked');
        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (el.type === 'hidden' || ALLOW.indexOf(el.name) !== -1) return;
            el.disabled = true;
        });
        var note = document.createElement('div');
        note.className = 'staff-lock-notice';
        note.innerHTML = '<i class="fas fa-lock"></i>Staff can update availability only. Name, pricing, images and details are managed by an administrator.';
        form.insertBefore(note, form.firstChild);
        submit.innerHTML = '<i class="fas fa-save"></i> Update Availability';
    });
})();
</script>
