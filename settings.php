<?php
// Page content is served through index.php. Shared presentation is loaded once.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/presentation.php';

$siteName = getSiteName();

// Legacy GET action URLs fail without changing state; forms use POST + CSRF.
if (isset($_GET['logout']) || isset($_POST['logout'])) {
    localnh_require_admin_post();
    localnh_logout();
    header('Location: /', true, 303);
    exit;
}

if (($_GET['toggle'] ?? '') === 'public_archive' || ($_POST['toggle'] ?? '') === 'public_archive') {
    localnh_require_admin_post();
    $paFile = localnh_data_path('config/public');
    $current = file_exists($paFile) ? trim(file_get_contents($paFile)) : 'off';
    if (file_put_contents($paFile, $current === 'on' ? 'off' : 'on', LOCK_EX) === false) {
        http_response_code(500);
        exit('Unable to save public archive setting.');
    }
    header('Location: /settings', true, 303);
    exit;
}

// Browser preferences are available without admin login, but still require
// an intentional POST from this browser's signed form context.
if (isset($_GET['toggle']) || isset($_POST['toggle'])) {
    localnh_require_post();
    $setting = $_POST['toggle'] ?? '';
    if (!is_string($setting) || !in_array($setting, ['dark_mode', 'dynamic_color', 'blur_covers', 'flip_page_order'], true)) {
        http_response_code(400);
        exit('Unknown preference.');
    }
    $default = $setting === 'blur_covers' ? 'off' : 'on';
    $current = $_COOKIE[$setting] ?? $default;
    $new = $setting === 'flip_page_order' ? ($current === 'rtl' ? 'ltr' : 'rtl') : ($current === 'on' ? 'off' : 'on');
    setcookie($setting, $new, time() + 365 * 86400, '/');
    header('Location: /settings', true, 303);
    exit;
}
header('Cache-Control: private, no-store');
$csrfToken = localnh_csrf_token();

// Read current values of each setting for display in the HTML toggles below.
$darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on');
$dynamicColor = (isset($_COOKIE['dynamic_color']) ? $_COOKIE['dynamic_color'] : 'on');
// Validate flip_page_order against known values; treat anything unexpected as default 'ltr'.
$flipPageOrder = (isset($_COOKIE['flip_page_order']) && in_array($_COOKIE['flip_page_order'], ['ltr', 'rtl'])) ? $_COOKIE['flip_page_order'] : 'ltr';
$blurCovers = (isset($_COOKIE['blur_covers']) ? $_COOKIE['blur_covers'] : 'off');
// public_archive is read from the filesystem rather than a cookie so the admin setting
// is shared across all browser sessions (not per-client).
$paFile = localnh_data_path('config/public');
$publicArchive = file_exists($paFile) ? trim(file_get_contents($paFile)) : 'off';

$themeCss = getThemeVars();

// Favicon path normalisation: relative paths are made root-relative.
$faviconHtml = getFaviconHtml();
?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>settings - <?= htmlspecialchars($siteName) ?></title>
    <link rel='stylesheet' href='/css/style.css'>
    <?= $faviconHtml ?>
    <style>
        :root { <?= $themeCss ?> }
    </style>
</head>
<body>
    <main>
    <div class='topbar'>
        <h1><a href='/'><?= htmlspecialchars($siteName) ?></a></h1>
    </div>
    <div class='settings-content'>
        <h2>settings</h2>
        <form method='POST' action='/settings'>
            <input type='hidden' name='toggle' value='dark_mode'>
            <input type='hidden' name='csrf_token' value='<?= $csrfToken ?>'>
            <p class='ui-text'>dark mode <button type='submit'><?= htmlspecialchars($darkMode) ?></button></p>
        </form>
        <form method='POST' action='/settings'>
            <input type='hidden' name='toggle' value='dynamic_color'>
            <input type='hidden' name='csrf_token' value='<?= $csrfToken ?>'>
            <p class='ui-text'>dynamic color on gallery page <button type='submit'><?= htmlspecialchars($dynamicColor) ?></button></p>
        </form>
        <form method='POST' action='/settings'>
            <input type='hidden' name='toggle' value='flip_page_order'>
            <input type='hidden' name='csrf_token' value='<?= $csrfToken ?>'>
            <p class='ui-text'>reader page order <button type='submit'><?= htmlspecialchars($flipPageOrder) ?></button></p>
        </form>
        <form method='POST' action='/settings'>
            <input type='hidden' name='toggle' value='blur_covers'>
            <input type='hidden' name='csrf_token' value='<?= $csrfToken ?>'>
            <p class='ui-text'>blur cover images <button type='submit'><?= htmlspecialchars($blurCovers) ?></button></p>
        </form>
        <?php if ($isLoggedIn): ?>
        <form method='POST' action='/settings'>
            <input type='hidden' name='toggle' value='public_archive'>
            <input type='hidden' name='csrf_token' value='<?= localnh_csrf_token() ?>'>
            <p class='ui-text'>allow archive by public <button type='submit'><?= htmlspecialchars($publicArchive) ?></button></p>
        </form>
        <form method='POST' action='/settings'>
            <input type='hidden' name='logout' value='1'>
            <input type='hidden' name='csrf_token' value='<?= localnh_csrf_token() ?>'>
            <p class='ui-text'>admin logged in as <?= htmlspecialchars($authCredentials['user']) ?> <button type='submit'>logout</button></p>
        </form>
        <?php /* else: ?>
        <p class='ui-text'>for admins <a href='/admin'><button>login</button></a></p>
        <?php */ endif; ?>
    </div>
    </main>
</body>
</html>
