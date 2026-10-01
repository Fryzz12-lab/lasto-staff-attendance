<?php
/**
 * Shared date filter presets (range + single-day).
 */

declare(strict_types=1);

/**
 * Render calendar shortcut chips for a date filter form.
 *
 * @param 'range'|'single' $mode
 * @param array{
 *   from_name?: string,
 *   to_name?: string,
 *   date_name?: string,
 *   from?: string,
 *   to?: string,
 *   date?: string,
 *   class?: string
 * } $opts
 */
function render_date_presets(string $mode = 'range', array $opts = []): void
{
    $mode = in_array($mode, ['single', 'leave'], true) ? $mode : 'range';
    $fromName = (string) ($opts['from_name'] ?? 'from');
    $toName = (string) ($opts['to_name'] ?? 'to');
    $dateName = (string) ($opts['date_name'] ?? 'date');
    $wrapClass = trim('date-presets ' . (string) ($opts['class'] ?? ''));
    $today = today_str();

    if ($mode === 'single') {
        $presets = [
            'today' => __('preset_today'),
            'yesterday' => __('preset_yesterday'),
            'day_before' => __('preset_day_before'),
        ];
    } elseif ($mode === 'leave') {
        $presets = [
            'today' => __('preset_today'),
            'this_week' => __('preset_this_week'),
            'next_week' => __('preset_next_week'),
            'this_month' => __('preset_this_month'),
            'next_month' => __('preset_next_month'),
            'past30' => __('preset_past30'),
        ];
    } else {
        $presets = [
            'today' => __('preset_today'),
            'yesterday' => __('preset_yesterday'),
            'this_week' => __('preset_this_week'),
            'this_month' => __('preset_this_month'),
            'last_month' => __('preset_last_month'),
        ];
    }

    $active = date_preset_active_key(
        $mode,
        $today,
        (string) ($opts['from'] ?? ''),
        (string) ($opts['to'] ?? ''),
        (string) ($opts['date'] ?? '')
    );
    ?>
    <div
        class="<?= e($wrapClass) ?>"
        data-date-presets="<?= e($mode) ?>"
        data-from-name="<?= e($fromName) ?>"
        data-to-name="<?= e($toName) ?>"
        data-date-name="<?= e($dateName) ?>"
        data-today="<?= e($today) ?>"
        role="group"
        aria-label="<?= e(__('date_presets')) ?>"
    >
        <span class="date-presets__label"><?= e(__('date_presets')) ?></span>
        <div class="date-presets__chips">
            <?php foreach ($presets as $key => $label): ?>
                <button
                    type="button"
                    class="date-preset<?= $active === $key ? ' is-active' : '' ?>"
                    data-preset="<?= e($key) ?>"
                    aria-pressed="<?= $active === $key ? 'true' : 'false' ?>"
                ><?= e($label) ?></button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/**
 * @return string|null Active preset key
 */
function date_preset_active_key(
    string $mode,
    string $today,
    string $from,
    string $to,
    string $date
): ?string {
    try {
        $tz = new DateTimeZone('Asia/Kuala_Lumpur');
        $t = new DateTimeImmutable($today, $tz);
    } catch (Exception $e) {
        return null;
    }

    if ($mode === 'single') {
        if ($date === '') {
            return null;
        }
        if ($date === $t->format('Y-m-d')) {
            return 'today';
        }
        if ($date === $t->modify('-1 day')->format('Y-m-d')) {
            return 'yesterday';
        }
        if ($date === $t->modify('-2 days')->format('Y-m-d')) {
            return 'day_before';
        }
        return null;
    }

    if ($from === '' || $to === '') {
        return null;
    }

    if ($mode === 'leave') {
        $map = [
            'today' => [$t->format('Y-m-d'), $t->format('Y-m-d')],
            'this_week' => [
                $t->modify('monday this week')->format('Y-m-d'),
                $t->modify('sunday this week')->format('Y-m-d'),
            ],
            'next_week' => [
                $t->modify('monday next week')->format('Y-m-d'),
                $t->modify('monday next week')->modify('+6 days')->format('Y-m-d'),
            ],
            'this_month' => [
                $t->modify('first day of this month')->format('Y-m-d'),
                $t->modify('last day of this month')->format('Y-m-d'),
            ],
            'next_month' => [
                $t->modify('first day of next month')->format('Y-m-d'),
                $t->modify('last day of next month')->format('Y-m-d'),
            ],
            'past30' => [
                $t->modify('-29 days')->format('Y-m-d'),
                $t->format('Y-m-d'),
            ],
        ];
    } else {
        $map = [
            'today' => [$t->format('Y-m-d'), $t->format('Y-m-d')],
            'yesterday' => [
                $t->modify('-1 day')->format('Y-m-d'),
                $t->modify('-1 day')->format('Y-m-d'),
            ],
            'this_week' => [$t->modify('monday this week')->format('Y-m-d'), $t->format('Y-m-d')],
            'this_month' => [$t->modify('first day of this month')->format('Y-m-d'), $t->format('Y-m-d')],
            'last_month' => [
                $t->modify('first day of last month')->format('Y-m-d'),
                $t->modify('last day of last month')->format('Y-m-d'),
            ],
        ];
    }

    foreach ($map as $key => [$f, $tt]) {
        if ($from === $f && $to === $tt) {
            return $key;
        }
    }

    return null;
}
