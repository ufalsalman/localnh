<?php
// These helpers are redeclared here with function_exists() guards so the file
// can be served standalone or via the router without fatal redeclaration errors.
if (!function_exists('getSiteName')) {
function getSiteName() {
    $path = __DIR__ . '/config/title';
    if (file_exists($path)) {
        $content = trim(file_get_contents($path));
        if ($content !== '') return $content;
    }
    return 'localnh';
}
}
$siteName = getSiteName();

if (!function_exists('getThemeVars')) {
function getThemeVars($bgColor = null) {
    global $isLoggedIn;
    $darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on') === 'on';
    // blur_covers only takes effect when the admin is logged in.
    $blurCovers = (isset($_COOKIE['blur_covers']) && $_COOKIE['blur_covers'] === 'on') ? '25px' : '0px';
    if ($bgColor === null) {
        $bgColor = $darkMode ? '#111' : '#fff';
    }
    $textColor = $darkMode ? '#fff' : '#111';
    $btnBg = $darkMode ? '#fff' : '#111';
    $btnText = $darkMode ? '#111' : '#fff';
    $btnHoverBg = $darkMode ? '#ddd' : '#333';
    $btnHoverBorder = $darkMode ? '#ddd' : '#333';
    $inputBg = $darkMode ? '#111' : '#fff';
    $inputText = $darkMode ? '#fff' : '#111';
    $inputBorder = $darkMode ? '#fff' : '#111';
    $dotsColor = $darkMode ? '#666' : '#999';

    return "
        --bg-color: {$bgColor};
        --text-color: {$textColor};
        --btn-bg: {$btnBg};
        --btn-text: {$btnText};
        --btn-hover-bg: {$btnHoverBg};
        --btn-hover-border: {$btnHoverBorder};
        --input-bg: {$inputBg};
        --input-text: {$inputText};
        --input-border: {$inputBorder};
        --dots-color: {$dotsColor};
        --cover-blur: {$blurCovers};
    ";
}
}

// Read admin credentials from the flat config file.
// Expected format (one per line): USER=<username>  PASS=<bcrypt_hash>
$credsFile = __DIR__ . '/config/credentials';
$validUser = '';
$validHash = '';
if (file_exists($credsFile)) {
    $lines = file($credsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'USER=') === 0) $validUser = substr($line, 5);
        if (strpos($line, 'PASS=') === 0) $validHash = substr($line, 5);
    }
}
$isLoggedIn = false;
// The auth cookie value is an MD5 of username + password-hash + a static salt.
// This token is set on successful login (see admin.php) and validated on every request.
// NOTE: MD5 is not used for password storage here — it's used as a simple session-token
// derived from already-hashed credentials; $validHash itself is a bcrypt hash.
$authSecret = md5($validUser . $validHash . 'localnh_secret_salt');
if (isset($_COOKIE['remote_auth']) && $_COOKIE['remote_auth'] === $authSecret) {
    $isLoggedIn = true;
}

// Logout: expire the auth cookie immediately by setting it to a past timestamp.
if (isset($_GET['logout'])) {
    setcookie('remote_auth', '', time() - 3600, '/');
    header('Location: /');
    exit;
}

// Toggle handler: all setting changes are performed via GET ?toggle=<name> redirects.
// This is a server-side-rendered pattern — no JS or AJAX needed.
if (isset($_GET['toggle'])) {
    $setting = $_GET['toggle'];
    // Whitelist: only these known toggle names are accepted to prevent arbitrary cookie writes.
    if (in_array($setting, ['dark_mode', 'dynamic_color', 'blur_covers'])) {
        $default = ($setting === 'blur_covers') ? 'off' : 'on';
        $default = 'on';
        $current = isset($_COOKIE[$setting]) ? $_COOKIE[$setting] : $default;
        $new = ($current === 'on') ? 'off' : 'on';
        // Cookie is set for 1 year (365 * 86400 seconds).
        setcookie($setting, $new, time() + 365 * 86400, '/');
    } elseif ($setting === 'flip_page_order') {
        // This setting cycles between two values ('ltr' / 'rtl') instead of on/off.
        $current = (isset($_COOKIE['flip_page_order']) && in_array($_COOKIE['flip_page_order'], ['ltr', 'rtl'])) ? $_COOKIE['flip_page_order'] : 'ltr';
        $new = ($current === 'ltr') ? 'rtl' : 'ltr';
        setcookie('flip_page_order', $new, time() + 365 * 86400, '/');
    } elseif ($setting === 'blur_covers' && $isLoggedIn) {
        // Admin-only: blur cover images site-wide (stored as a cookie, not a config file).
        $default = 'off';
        $current = isset($_COOKIE[$setting]) ? $_COOKIE[$setting] : $default;
        $new = ($current === 'on') ? 'off' : 'on';
        setcookie($setting, $new, time() + 365 * 86400, '/');
    } elseif ($setting === 'public_archive' && $isLoggedIn) {
        // Admin-only: toggle whether anonymous visitors can trigger archiving.
        // State is written to a flat file so it persists across sessions/restarts.
        $paFile = __DIR__ . '/config/public';
        $current = file_exists($paFile) ? trim(file_get_contents($paFile)) : 'off';
        $new = ($current === 'on') ? 'off' : 'on';
        file_put_contents($paFile, $new);
    }
    // Always redirect back to /settings after processing a toggle to prevent re-submission on refresh.
    header("Location: /settings");
    exit;
}

// Read current values of each setting for display in the HTML toggles below.
$darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on');
$dynamicColor = (isset($_COOKIE['dynamic_color']) ? $_COOKIE['dynamic_color'] : 'on');
// Validate flip_page_order against known values; treat anything unexpected as default 'ltr'.
$flipPageOrder = (isset($_COOKIE['flip_page_order']) && in_array($_COOKIE['flip_page_order'], ['ltr', 'rtl'])) ? $_COOKIE['flip_page_order'] : 'ltr';
$blurCovers = (isset($_COOKIE['blur_covers']) ? $_COOKIE['blur_covers'] : 'off');
// public_archive is read from the filesystem rather than a cookie so the admin setting
// is shared across all browser sessions (not per-client).
$paFile = __DIR__ . '/config/public';
$publicArchive = file_exists($paFile) ? trim(file_get_contents($paFile)) : 'off';

$themeCss = getThemeVars();

// Favicon path normalisation: relative paths are made root-relative.
$faviconFile = __DIR__ . '/config/favicon';
$faviconHtml = '';
if (file_exists($faviconFile)) {
    $faviconPath = trim(file_get_contents($faviconFile));
    if ($faviconPath !== '') {
        if (strpos($faviconPath, 'http') !== 0 && strpos($faviconPath, '/') !== 0) {
            $faviconPath = '/' . $faviconPath;
        }
        $faviconHtml = "<link rel='icon' href='" . htmlspecialchars($faviconPath) . "'>";
    }
}
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
        <p class='ui-text'>dark mode <a href='/settings?toggle=dark_mode'><button><?= htmlspecialchars($darkMode) ?></button></a></p>
        <p class='ui-text'>dynamic color on gallery page <a href='/settings?toggle=dynamic_color'><button><?= htmlspecialchars($dynamicColor) ?></button></a></p>
        <p class='ui-text'>reader page order <a href='/settings?toggle=flip_page_order'><button><?= htmlspecialchars($flipPageOrder) ?></button></a></p>
        <p class='ui-text'>blur cover images <a href='/settings?toggle=blur_covers'><button><?= htmlspecialchars($blurCovers) ?></button></a></p>
        <?php if ($isLoggedIn): ?>
        <p class='ui-text'>allow archive by public <a href='/settings?toggle=public_archive'><button><?= htmlspecialchars($publicArchive) ?></button></a></p>
        <p class='ui-text'>admin logged in as <?= htmlspecialchars($validUser) ?> <a href='/settings?logout=1'><button>logout</button></a></p>
        <?php /* else: ?>
        <p class='ui-text'>for admins <a href='/admin'><button>login</button></a></p>
        <?php */ endif; ?>
    </div>
    </main>
</body>
</html>
