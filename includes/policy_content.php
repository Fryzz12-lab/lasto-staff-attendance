<?php
declare(strict_types=1);
if (!defined('APP_ENV')) { http_response_code(404); exit; }
$policyQuickView = str_contains((string) ($bodyClass ?? ''), 'staff-policy-page');
$policyBulletPoints = static function (string $text): array {
    // Keep the translated legal wording intact while separating rules and service tiers for scanning.
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?;:。；：])\s*/u', $text) ?: [$text])));
};
?>
<h2 class="h4 mb-3"><?= e(__('schedules')) ?></h2>
<div class="card-soft mb-3">
    <div class="card-header"><?= e(__('policy_hours_title')) ?></div>
    <div class="card-body policy-hours-original">
        <p class="fw-semibold"><?= e(format_time(today_str().' '.POLICY_START_TIME)) ?> – <?= e(format_time(today_str().' '.POLICY_END_TIME)) ?></p>
        <p><?= e(__('target_working_time')) ?>: <?= e(format_minutes(POLICY_TARGET_MINUTES)) ?></p>
        <p><?= e(__('policy_weekly_summary')) ?></p>
        <p class="form-hint mb-0"><?= e(__('schedule_auto_hint')) ?></p>
    </div>
    <?php if ($policyQuickView): ?>
    <div class="staff-policy-hours-summary">
        <div class="staff-policy-shift-time">
            <i class="bi bi-clock" aria-hidden="true"></i>
            <strong><?= e(format_time(today_str().' '.POLICY_START_TIME)) ?> &ndash; <?= e(format_time(today_str().' '.POLICY_END_TIME)) ?></strong>
        </div>
        <div class="staff-policy-hours-facts">
            <div><span><?= e(__('daily_target')) ?></span><strong><?= e(format_minutes(POLICY_TARGET_MINUTES)) ?></strong><small><?= e(__('net_working_time')) ?></small></div>
            <div><span><?= e(__('working_days')) ?></span><strong>6</strong><small><?= e(__('policy_weekly_summary')) ?></small></div>
        </div>
        <p class="staff-policy-hours-note"><?= e(!empty($isAdminArea) ? __('schedule_auto_hint') : match (lang_current()) {
            'ms' => 'Semak hari rehat mingguan anda dalam Jadual Saya. Hubungi admin untuk sebarang perubahan.',
            'zh' => '请在“我的排班”中查看每周休息日。如需更改，请联系管理员。',
            default => 'Check My Schedule for your weekly rest day. Speak to the admin about any changes.',
        }) ?></p>
    </div>
    <?php endif; ?>
</div>
<div class="card-soft mb-3">
    <div class="card-header"><?= e(__('policy_breaks_title')) ?></div>
    <div class="card-body p-0">
        <?php if ($policyQuickView): ?>
        <div class="staff-policy-break-cards">
            <?php $mobileBreakTotal = 0; foreach (break_limits() as $type => $rule):
                $dailyMinutes = (int) $rule['suggest_session'] * (int) $rule['max_sessions'];
                $mobileBreakTotal += $dailyMinutes;
            ?>
            <div class="staff-policy-break-card">
                <strong><?= e(break_type_name($type)) ?></strong>
                <dl>
                    <div><dt><?= e(__('policy_per_session')) ?></dt><dd><?= (int) $rule['suggest_session'] ?> <?= e(__('policy_minutes_unit')) ?></dd></div>
                    <div><dt><?= e(__('policy_sessions_day')) ?></dt><dd><?= (int) $rule['max_sessions'] ?> <?= e(__('break_plural')) ?></dd></div>
                    <div><dt><?= e(__('policy_daily_max')) ?></dt><dd><?= $dailyMinutes ?> <?= e(__('policy_minutes_unit')) ?></dd></div>
                </dl>
                <details class="staff-policy-calculation">
                    <summary><?= e(__('policy_daily_max')) ?></summary>
                    <div>
                        <?= (int) $rule['max_sessions'] ?> &times;
                        <?= (int) $rule['suggest_session'] ?> <?= e(__('policy_minutes_unit')) ?>
                        = <strong><?= $dailyMinutes ?> <?= e(__('policy_minutes_unit')) ?></strong>
                    </div>
                </details>
            </div>
            <?php endforeach; ?>
            <div class="staff-policy-break-total"><strong><?= e(__('policy_total_allowance')) ?></strong><span><?= $mobileBreakTotal ?> <?= e(__('policy_minutes_unit')) ?></span></div>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table mb-0 policy-break-table align-middle" style="table-layout: fixed; width: 100%;">
                <colgroup>
                    <col style="width: 22%;">
                    <col style="width: 20%;">
                    <col style="width: 20%;">
                    <col style="width: 38%;">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="ps-3"><?= e(__('type')) ?></th>
                        <th scope="col"><?= e(__('policy_per_session')) ?></th>
                        <th scope="col" class="text-center"><?= e(__('policy_sessions_day')) ?></th>
                        <th scope="col" class="pe-3"><?= e(__('policy_daily_max')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $formatSession = static function (int $mins): string {
                    $curLang = lang_current();
                    if ($mins === 60) {
                        return match ($curLang) {
                            'ms' => '1 Jam',
                            'zh' => '1 小时',
                            default => '1 Hour',
                        };
                    }
                    return match ($curLang) {
                        'ms' => $mins . ' Minit',
                        'zh' => $mins . ' 分钟',
                        default => $mins . ' Minutes',
                    };
                };

                $totalSessions = 0; $totalMinutes = 0;
                foreach (break_limits() as $type => $rule):
                    $minutes = (int)$rule['suggest_session'] * (int)$rule['max_sessions'];
                    $totalSessions += (int)$rule['max_sessions'];
                    $totalMinutes += $minutes;
                    $sessLabel = $formatSession((int)$rule['suggest_session']);
                    $calcDetail = (int)$rule['max_sessions'] . ' × ' . $sessLabel . ' = ' . format_minutes($minutes);
                    if ($minutes >= 60) {
                        $calcDetail .= ' (' . $minutes . ' ' . e(__('policy_minutes_unit')) . ')';
                    }
                ?>
                    <tr>
                        <td class="fw-semibold ps-3"><?= e(break_type_name($type)) ?></td>
                        <td><?= e($sessLabel) ?></td>
                        <td class="text-center fw-medium"><?= (int) $rule['max_sessions'] ?></td>
                        <td class="pe-3"><code><?= e($calcDetail) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="policy-table-tfoot">
                    <tr>
                        <th scope="row" colspan="2" class="fw-bold ps-3"><?= e(__('policy_total_allowance')) ?></th>
                        <td class="text-center fw-bold"><?= (int) $totalSessions ?></td>
                        <td class="pe-3 fw-bold">
                            <span class="policy-total-badge"><?= (int) $totalMinutes ?> <?= e(__('policy_minutes_unit')) ?></span>
                            <span class="text-muted fw-normal ms-1">(<?= e(format_minutes($totalMinutes)) ?>)</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="p-3 border-top">
            <p class="form-hint mb-0"><?= e(__('policy_breaks_summary')) ?></p>
        </div>
    </div>
</div>
<div class="card-soft mb-3">
    <div class="card-header"><?= e(__('policy_conduct_title')) ?></div>
    <div class="card-body policy-conduct-original">
        <p><?= e(__('policy_late_hint')) ?></p>
        <p><?= e(__('policy_phone_hint')) ?></p>
        <p class="form-hint mb-0"><?= e(__('policy_changes_hint')) ?></p>
    </div>
    <?php if ($policyQuickView): ?>
    <div class="staff-policy-conduct">
        <?php
        $conductTitles = match (lang_current()) {
            'ms' => ['Tepati masa', 'Penggunaan telefon', 'Perubahan polisi'],
            'zh' => ['准时上班', '手机使用', '政策变更'],
            default => ['Be on time', 'Phone use', 'Policy changes'],
        };
        foreach ([['bi-clock', 'policy_late_hint'], ['bi-phone', 'policy_phone_hint']] as $index => [$icon, $text]): ?>
        <div class="staff-conduct-rule">
            <span class="staff-conduct-icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></span>
            <div><strong><?= e($conductTitles[$index]) ?></strong><p><?= e(__($text)) ?></p></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<div class="card-soft">
    <div class="card-header"><?= e(__('policy_leave_title')) ?></div>
    <div class="card-body">
        <p class="form-hint policy-leave-paragraph"><?= e(__('policy_leave_intro')) ?></p>
        <?php if ($policyQuickView): ?>
            <?php $policyQuick = require __DIR__ . '/policy_quick.php'; ?>
            <ul class="staff-policy-leave-bullets form-hint">
                <?php foreach ($policyBulletPoints(__('policy_leave_intro')) as $point): ?><li><?= e($point) ?></li><?php endforeach; ?>
            </ul>
            <div class="staff-policy-leave-columns" aria-hidden="true">
                <span><?= e(__('policy_leave_type_column')) ?></span>
                <span><?= e(__('policy_entitlement_column')) ?></span>
                <span><?= e(__('policy_details_column')) ?></span>
            </div>
        <?php endif; ?>
        <?php foreach (['annual', 'holiday', 'medical', 'hospital', 'paternity', 'maternity'] as $leave): ?>
            <details class="policy-disclosure"<?= $policyQuickView ? ' name="staff-leave-policy"' : '' ?>>
                <summary>
                    <span class="fw-semibold"><?= e(__('policy_leave_' . $leave . '_title')) ?></span>
                    <span class="policy-entitlement"><?= e(__('policy_leave_' . $leave . '_amount')) ?></span>
                </summary>
                <div class="policy-disclosure-body">
                    <?php if ($policyQuickView): ?>
                        <div class="policy-quick-details">
                            <?php if ($leave === 'annual' || $leave === 'medical'): ?>
                                <h3><?= e($policyQuick['by_service']) ?></h3>
                                <dl class="policy-quick-tiers">
                                    <?php foreach (['under_2', 'two_to_5', 'five_plus'] as $index => $tier): ?>
                                        <div><dt><?= e($policyQuick[$tier]) ?></dt><dd><?= [
                                            'annual' => [8, 12, 16],
                                            'medical' => [14, 18, 22],
                                        ][$leave][$index] ?> <?= e($policyQuick['days']) ?></dd></div>
                                    <?php endforeach; ?>
                                </dl>
                            <?php elseif ($leave === 'holiday'): ?>
                                <div class="policy-quick-holiday">
                                    <strong><?= e(__('policy_leave_holiday_amount')) ?></strong>
                                    <span><?= e($policyQuick['holiday_compulsory']) ?> + <?= e($policyQuick['holiday_gazetted']) ?></span>
                                </div>
                            <?php endif; ?>
                            <h3><?= e($policyQuick['conditions']) ?></h3>
                            <ul class="policy-quick-notes">
                                <?php foreach ($policyQuick['notes'][$leave] as $note): ?><li><?= e($note) ?></li><?php endforeach; ?>
                            </ul>
                            <details class="policy-legal-details">
                                <summary><span class="policy-legal-view"><?= e($policyQuick['legal']) ?></span><span class="policy-legal-hide"><?= e($policyQuick['legal_hide']) ?></span></summary>
                                <div class="policy-legal-copy">
                                    <p><?= e(__('policy_leave_' . $leave . '_details')) ?></p>
                                    <?php if ($leave === 'holiday'): ?>
                                        <ul>
                                            <?php foreach (['national', 'agong', 'state', 'labour', 'malaysia'] as $holiday): ?>
                                                <li><?= e(__('policy_holiday_' . $holiday)) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </div>
                    <?php else: ?>
                        <p class="mb-0 policy-leave-paragraph"><?= e(__('policy_leave_' . $leave . '_details')) ?></p>
                        <?php if ($leave === 'holiday'): ?>
                            <ul class="mt-2 mb-0">
                                <?php foreach (['national', 'agong', 'state', 'labour', 'malaysia'] as $holiday): ?>
                                    <li><?= e(__('policy_holiday_' . $holiday)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <details class="policy-disclosure"<?= $policyQuickView ? ' name="staff-leave-policy"' : '' ?>>
            <summary><span class="fw-semibold"><?= e(__('policy_internship_title')) ?></span><?php if ($policyQuickView): ?><span class="policy-entitlement"><?= e(__('policy_see_policy')) ?></span><?php endif; ?></summary>
            <div class="policy-disclosure-body">
                <?php if ($policyQuickView): ?>
                    <div class="policy-quick-details">
                        <h3><?= e($policyQuick['conditions']) ?></h3>
                        <ul class="policy-quick-notes">
                            <?php foreach ($policyQuick['notes']['internship'] as $note): ?><li><?= e($note) ?></li><?php endforeach; ?>
                        </ul>
                        <details class="policy-legal-details">
                            <summary><span class="policy-legal-view"><?= e($policyQuick['legal']) ?></span><span class="policy-legal-hide"><?= e($policyQuick['legal_hide']) ?></span></summary>
                            <div class="policy-legal-copy"><p><?= e(__('policy_internship_arrangements')) ?></p></div>
                        </details>
                    </div>
                <?php else: ?>
                    <p class="mb-0 policy-leave-paragraph"><?= e(__('policy_internship_arrangements')) ?></p>
                <?php endif; ?>
            </div>
        </details>
        <p class="form-hint mt-3 mb-0"><a href="https://jtksm.mohr.gov.my/sites/default/files/2023-11/Akta%20Kerja%201955%20%28Akta%20265%29.pdf" target="_blank" rel="noopener noreferrer"><?= e(__('policy_legal_source')) ?></a></p>
    </div>
</div>
