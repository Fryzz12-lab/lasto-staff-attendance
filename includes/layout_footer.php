<?php
/**
 * Layout footer
 */
?>
<?php if (current_user()): ?>
        </main>
        <footer class="app-footer">
            <span>&copy; <?= date('Y') ?> <?= e(company_name()) ?> · <?= e(__('staff_attendance_system')) ?></span>
            <span class="text-muted">v<?= e(APP_VERSION) ?></span>
        </footer>
    </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>

<div id="toastContainer" class="toast-container position-fixed top-0 end-0 p-3"></div>
<div id="loadingOverlay" class="loading-overlay d-none">
    <div class="spinner-border text-light" role="status"></div>
    <div class="mt-2 text-light" data-loading-label><?= e(__('loading')) ?></div>
</div>

<script>
    window.APP_BASE = <?= json_encode(BASE_PATH) ?>;
    window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    window.SAS_USER_ID = <?= json_encode(current_user()['id'] ?? null) ?>;
    window.UNIT_H = <?= json_encode(__('unit_h')) ?>;
    window.UNIT_M = <?= json_encode(__('unit_m')) ?>;
    window.CHRONOS_I18N = {
      badge_new_leave: <?= json_encode(__('badge_new_leave')) ?>,
      badge_new_leave_n: <?= json_encode(__('badge_new_leave_n')) ?>,
      badge_leave_decision: <?= json_encode(__('badge_leave_decision')) ?>,
      badge_incomplete: <?= json_encode(__('badge_incomplete')) ?>,
      push_recovery_progress: <?= json_encode(__('push_recovery_progress')) ?>,
      push_recovery_retained: <?= json_encode(__('push_recovery_retained')) ?>,
      push_recovery_failed: <?= json_encode(__('push_recovery_failed')) ?>,
      push_recovery_cleanup: <?= json_encode(__('push_recovery_cleanup')) ?>,
      push_ui_unsupported: <?= json_encode(__('push_ui_unsupported')) ?>,
      push_ui_blocked: <?= json_encode(__('push_ui_blocked')) ?>,
      push_ui_settings_unavailable: <?= json_encode(__('push_ui_settings_unavailable')) ?>,
      push_ui_unregistered: <?= json_encode(__('push_ui_unregistered')) ?>,
      push_ui_enabled: <?= json_encode(__('push_ui_enabled')) ?>,
      push_ui_disabled: <?= json_encode(__('push_ui_disabled')) ?>,
      push_ui_permission_denied: <?= json_encode(__('push_ui_permission_denied')) ?>,
      push_ui_key_unavailable: <?= json_encode(__('push_ui_key_unavailable')) ?>,
      push_ui_enabled_toast: <?= json_encode(__('push_ui_enabled_toast')) ?>,
      push_ui_enable_failed: <?= json_encode(__('push_ui_enable_failed')) ?>,
      push_ui_disabled_toast: <?= json_encode(__('push_ui_disabled_toast')) ?>,
      push_ui_disable_failed: <?= json_encode(__('push_ui_disable_failed')) ?>,
      push_ui_preference_failed: <?= json_encode(__('push_ui_preference_failed')) ?>,
      push_ui_test_enable_first: <?= json_encode(__('push_ui_test_enable_first')) ?>,
      push_ui_test_sent: <?= json_encode(__('push_ui_test_sent')) ?>,
      push_ui_test_failed: <?= json_encode(__('push_ui_test_failed')) ?>,
      push_ui_sending: <?= json_encode(__('push_ui_sending')) ?>,
      toast_dismiss: <?= json_encode(__('toast_dismiss')) ?>,
      confirm_clock_out: <?= json_encode(__('confirm_clock_out')) ?>,
      confirm_start_break: <?= json_encode(__('confirm_start_break')) ?>,
      confirm_end_break: <?= json_encode(__('confirm_end_break')) ?>
    };
</script>
<script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
<script src="<?= e(asset('vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="<?= e(asset('js/sint-space-bg.js')) ?>"></script>
<script src="<?= e(asset('js/tech-fx.js')) ?>"></script>
<script src="<?= e(asset('js/push_recovery.js')) ?>"></script>
<script src="<?= e(asset('js/attendance_reliability.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/date-filter.js')) ?>"></script>
<script src="<?= e(asset('js/spa-nav.js')) ?>"></script>
</body>
</html>
