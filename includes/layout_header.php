<?php
/**
 * Layout header
 * Expected vars: $pageTitle, $activeMenu (optional), $bodyClass (optional)
 */

declare(strict_types=1);

$user = current_user();
$pageTitle = $pageTitle ?? __('app_name');
$bodyClass = $bodyClass ?? '';
$activeMenu = $activeMenu ?? '';
$isAdminArea = $isAdminArea ?? ($user && $user['role'] === 'admin' && str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/'));

$ogTitle = $ogTitle ?? ($pageTitle . ' · ' . company_name());
$ogDescription = $ogDescription ?? (company_name() . ' · ' . __('staff_attendance_system') . ' — Employee attendance, break tracking, and workforce management.');
$ogImage = $ogImage ?? absolute_url('/assets/img/logo.png');
$ogUrl = $ogUrl ?? current_url();
?>
<!DOCTYPE html>
<html lang="<?= e(html_lang()) ?>" data-unit-h="<?= e(__('unit_h')) ?>" data-unit-m="<?= e(__('unit_m')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · <?= e(company_name()) ?></title>
    <meta name="description" content="<?= e($ogDescription) ?>">

    <!-- Progressive Web App / Mobile Standalone -->
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= e(company_name()) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('img/icon-192.png')) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset('img/icon-192.png')) ?>">
    <link rel="apple-touch-icon" sizes="192x192" href="<?= e(asset('img/icon-192.png')) ?>">
    <link rel="apple-touch-icon" sizes="512x512" href="<?= e(asset('img/icon-512.png')) ?>">

    <!-- Open Graph / WhatsApp / Facebook / LinkedIn -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(company_name()) ?>">
    <meta property="og:url" content="<?= e($ogUrl) ?>">
    <meta property="og:title" content="<?= e($ogTitle) ?>">
    <meta property="og:description" content="<?= e($ogDescription) ?>">
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta property="og:image:width" content="1024">
    <meta property="og:image:height" content="1024">
    <meta property="og:image:alt" content="<?= e(company_name()) ?>">

    <!-- Twitter / X Cards -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= e($ogTitle) ?>">
    <meta name="twitter:description" content="<?= e($ogDescription) ?>">
    <meta name="twitter:image" content="<?= e($ogImage) ?>">

    <meta name="theme-color" content="#06090f" id="metaThemeColor">
    <script>
      (function () {
        try {
          var t = localStorage.getItem('chrono_theme') || 'night';
          if (t !== 'day' && t !== 'night') t = 'night';
          document.documentElement.setAttribute('data-theme', t);
          var g = localStorage.getItem('chrono_globe') || 'on';
          if (g !== 'off') g = 'on';
          document.documentElement.setAttribute('data-globe', g);
        } catch (e) {
          document.documentElement.setAttribute('data-theme', 'night');
          document.documentElement.setAttribute('data-globe', 'on');
        }
      })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?= e(asset('img/logo-dark.png')) ?>">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="app-body <?= !$user ? 'auth-page' : '' ?> <?= $user && !$isAdminArea ? 'staff-area' : '' ?> <?= e($bodyClass) ?>">
<div id="chronoAmbient" aria-hidden="true"></div>
<div id="chronoProgressBar" class="chrono-progress-bar" aria-hidden="true"></div>
<?php if ($user): ?>
<div class="app-wrapper">
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <img src="<?= e(asset('img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-dark">
                <img src="<?= e(asset(is_file(dirname(__DIR__) . '/assets/img/logo-light.png') ? 'img/logo-light.png' : 'img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-light">
            </div>
            <div>
                <div class="brand-name"><?= e(company_name()) ?></div>
                <div class="brand-sub"><?= e(__('staff_attendance_system')) ?></div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php require __DIR__ . '/sidebar.php'; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-chip">
                <div class="user-avatar">
                    <?php if (!empty($user['profile_photo'])): ?>
                        <img src="<?= e(UPLOAD_URL . '/' . $user['profile_photo']) ?>" alt="">
                    <?php else: ?>
                        <span><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="user-name"><?= e($user['full_name']) ?></div>
                    <div class="user-role"><?= e(ucfirst($user['role'])) ?></div>
                </div>
            </div>
            <div class="sidebar-mobile-controls d-lg-none">
                <div class="sidebar-mobile-controls__grid">
                    <button type="button" class="btn btn-sm globe-toggle"
                            title="<?= e(__('globe_toggle')) ?>" aria-label="<?= e(__('globe_toggle')) ?>"
                            data-label-on="<?= e(__('globe_on')) ?>" data-label-off="<?= e(__('globe_off')) ?>">
                        <i class="bi bi-globe2 globe-toggle__icon"></i>
                        <span class="globe-toggle__text"></span>
                    </button>
                    <button type="button" class="btn btn-sm theme-toggle"
                            title="<?= e(__('theme_toggle')) ?>" aria-label="<?= e(__('theme_toggle')) ?>"
                            data-label-night="<?= e(__('theme_night')) ?>" data-label-day="<?= e(__('theme_day')) ?>">
                        <i class="bi bi-moon-stars theme-toggle__icon-night"></i>
                        <i class="bi bi-sun theme-toggle__icon-day"></i>
                        <span class="theme-toggle__text"></span>
                    </button>
                </div>
                <div class="sidebar-mobile-controls__lang">
                    <?= lang_switcher() ?>
                </div>
                <a href="<?= e(url('/logout.php')) ?>" class="btn btn-sm sidebar-logout-btn">
                    <i class="bi bi-box-arrow-right"></i>
                    <span><?= e(__('logout')) ?></span>
                </a>
            </div>
        </div>
    </aside>
    <div class="sidebar-backdrop d-lg-none" id="sidebarBackdrop"></div>
    <div class="app-main">
        <header class="app-topbar">
            <button type="button" class="btn btn-light btn-sm sidebar-toggle d-lg-none" id="sidebarToggle" aria-label="Menu">
                <i class="bi bi-list"></i>
            </button>
            <div class="topbar-title d-lg-none"><?= e($pageTitle) ?></div>
            <div class="topbar-information<?= !empty($isAdminArea) ? ' topbar-information--admin' : '' ?>">
                <?php if (!empty($isAdminArea)): ?>
                <div class="topbar-ticker d-none d-lg-flex" id="chronoTicker" data-feed="<?= e(url('/api/live_feed.php')) ?>" aria-live="polite">
                    <span class="topbar-ticker__label"><?= e(__('ticker_label')) ?></span>
                    <div class="topbar-ticker__viewport">
                        <div class="topbar-ticker__track" id="chronoTickerTrack">
                            <span class="topbar-ticker__item"><?= e(__('ticker_idle')) ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="topbar-chrono d-none d-md-flex">
                    <div class="chrono-status" title="<?= e(__('system_online')) ?>">
                        <span class="chrono-status__dot"></span>
                        <?= e(__('system_online')) ?>
                    </div>
                    <div class="chrono-clock" id="chronoClock" data-server-now="<?= e(now()->format('c')) ?>">
                        <span data-h>--</span>:<span data-m>--</span>:<span data-s>--</span>
                    </div>
                </div>
            </div>
            <div class="topbar-actions d-none d-lg-flex">
                <button type="button" class="btn btn-sm btn-outline-secondary globe-toggle" id="globeToggle"
                        title="<?= e(__('globe_toggle')) ?>" aria-label="<?= e(__('globe_toggle')) ?>"
                        data-label-on="<?= e(__('globe_on')) ?>" data-label-off="<?= e(__('globe_off')) ?>">
                    <i class="bi bi-globe2 globe-toggle__icon"></i>
                    <span class="globe-toggle__text"></span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary theme-toggle" id="themeToggle"
                        title="<?= e(__('theme_toggle')) ?>" aria-label="<?= e(__('theme_toggle')) ?>"
                        data-label-night="<?= e(__('theme_night')) ?>" data-label-day="<?= e(__('theme_day')) ?>">
                    <i class="bi bi-moon-stars theme-toggle__icon-night"></i>
                    <i class="bi bi-sun theme-toggle__icon-day"></i>
                    <span class="theme-toggle__text"></span>
                </button>
                <?= lang_switcher() ?>
                <a href="<?= e(url('/logout.php')) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-right"></i> <?= e(__('logout')) ?>
                </a>
            </div>
            <button type="button" class="btn btn-link topbar-mobile-avatar d-lg-none ms-auto text-decoration-none" id="mobileUserBtn" aria-label="Menu">
                <div class="user-avatar-sm">
                    <?php if (!empty($user['profile_photo'])): ?>
                        <img src="<?= e(UPLOAD_URL . '/' . $user['profile_photo']) ?>" alt="">
                    <?php else: ?>
                        <span><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
                    <?php endif; ?>
                </div>
            </button>
        </header>
        <main class="app-content">
            <?php foreach (flash_get() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
<?php else: ?>
<div class="auth-shell">
    <div class="auth-top">
        <button type="button" class="btn btn-sm btn-outline-secondary globe-toggle" id="globeToggle"
                title="<?= e(__('globe_toggle')) ?>" aria-label="<?= e(__('globe_toggle')) ?>"
                data-label-on="<?= e(__('globe_on')) ?>" data-label-off="<?= e(__('globe_off')) ?>">
            <i class="bi bi-globe2 globe-toggle__icon"></i>
            <span class="globe-toggle__text"></span>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary theme-toggle" id="themeToggle"
                title="<?= e(__('theme_toggle')) ?>" aria-label="<?= e(__('theme_toggle')) ?>"
                data-label-night="<?= e(__('theme_night')) ?>" data-label-day="<?= e(__('theme_day')) ?>">
            <i class="bi bi-moon-stars theme-toggle__icon-night"></i>
            <i class="bi bi-sun theme-toggle__icon-day"></i>
            <span class="theme-toggle__text"></span>
        </button>
        <?= lang_switcher() ?>
    </div>
<?php endif; ?>
