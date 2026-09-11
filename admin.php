<?php
/**
 * Admin Authentication Portal
 * Handles login authentication against credentials configured in config/credentials.
 */
require_once __DIR__ . '/bootstrap.php';

// Helper to retrieve the custom site name or default to 'localnh'
if (!function_exists('getSiteName')) {
function getSiteName() {
    $path = localnh_data_path('config/title');
    if (file_exists($path)) {
        $content = trim(file_get_contents($path));
        if ($content !== '') return $content;
    }
    return 'localnh';
}
}
$siteName = getSiteName();

// Helper to generate CSS custom properties for dark/light themes
if (!function_exists('getThemeVars')) {
function getThemeVars($bgColor = null) {
    global $isLoggedIn;
    $darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on') === 'on';
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

$themeCss = getThemeVars();

// Process login submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    localnh_require_post();
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    if (is_string($user) && is_string($pass) && localnh_login($user, $pass)) {
        header('Location: /settings');
        exit;
    } else {
        $error = 'invalid credentials';
    }
}

// Already logged in -> redirect to settings
if ($isLoggedIn) {
    header('Location: /settings');
    exit;
}

// Generate before HTML so the anonymous form cookie can be sent.
header('Cache-Control: private, no-store');
$csrfToken = localnh_csrf_token();

// Favicon configuration loader
$faviconFile = localnh_data_path('config/favicon');
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
    <title>login - <?= htmlspecialchars($siteName) ?></title>
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
        <?php if ($error): ?><p class='ui-text error-text'><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method='POST'>
            <input type='hidden' name='action' value='login'>
            <input type='hidden' name='csrf_token' value='<?= $csrfToken ?>'>
            <div class='remote-field-sm'>
                <input type='text' name='user' class='remote-input' placeholder='username' required>
            </div>
            <div class='remote-field'>
                <input type='password' name='pass' class='remote-input' placeholder='password' required>
            </div>
            <button type='submit' class='action-btn login-btn'>login</button>
        </form>
    </div>
    </main>
</body>
</html>
