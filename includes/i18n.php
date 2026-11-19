<?php
/**
 * Simple i18n — English / Chinese / Malay
 * Page content defaults to English; UI labels can switch.
 */

declare(strict_types=1);

function lang_current(): string
{
    $allowed = ['en', 'zh', 'ms'];
    if (!empty($_GET['lang']) && in_array($_GET['lang'], $allowed, true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
    if (!empty($_SESSION['lang']) && in_array($_SESSION['lang'], $allowed, true)) {
        return $_SESSION['lang'];
    }
    return get_setting('default_language', 'en') ?? 'en';
}

function __(string $key, array $replace = []): string
{
    return translate_for_language($key, lang_current(), $replace);
}

function translate_for_language(string $key, string $lang, array $replace = []): string
{
    static $dicts = [];
    if (!in_array($lang, ['en', 'ms', 'zh'], true)) $lang = 'en';
    if (!isset($dicts[$lang])) {
        $file = __DIR__ . '/../assets/lang/' . $lang . '.php';
        $dict = is_file($file) ? require $file : [];
        if ($lang !== 'en') {
            $en = require __DIR__ . '/../assets/lang/en.php';
            $dict = array_merge($en, $dict);
        }
        $dicts[$lang] = $dict;
    }
    $text = $dicts[$lang][$key] ?? $key;
    $tokens = [];
    foreach ($replace as $k => $v) {
        $tokens[':' . $k] = (string) $v;
    }
    return strtr($text, $tokens);
}

function lang_switcher(): string
{
    $current = lang_current();
    $langs = [
        'en' => 'English',
        'zh' => '中文',
        'ms' => 'Bahasa Melayu',
    ];

    // Build from current request path + query (without lang), avoid double-encoding
    $req = $_SERVER['REQUEST_URI'] ?? '/';
    $parts = parse_url($req);
    $path = $parts['path'] ?? '/';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    unset($query['lang']);

    $html = '<div class="lang-switcher dropdown" data-chrono-lang="' . e($current) . '">';
    $html .= '<button class="btn btn-sm btn-outline-secondary dropdown-toggle lang-switcher__btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-translate me-1 opacity-75"></i>' . e($langs[$current]) . '</button>';
    $html .= '<ul class="dropdown-menu dropdown-menu-end">';
    foreach ($langs as $code => $label) {
        $q = $query;
        $q['lang'] = $code;
        $href = $path . '?' . http_build_query($q);
        $active = $code === $current ? ' active' : '';
        $html .= '<li><a class="dropdown-item' . $active . '" data-spa-lang="' . e($code) . '" href="' . e($href) . '">' . e($label) . '</a></li>';
    }
    $html .= '</ul></div>';
    return $html;
}
