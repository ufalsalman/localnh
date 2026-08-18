<?php
/**
 * Admin Authentication Portal
 * Handles login authentication against credentials configured in config/credentials.
 */
session_start();

// Helper to retrieve the custom site name or default to 'localnh'
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

// Helper to generate CSS custom properties for dark/light themes
if (!function_exists('getThemeVars')) {
function getThemeVars($bgColor = null) {
    global $isLoggedIn;
    $darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on') === 'on';
    $blurCovers = (isset($_COOKIE['blur_covers']) && $_COOKIE['blur_covers'] === 'on' && !empty($isLoggedIn)) ? '25px' : '0px';
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

// Load configured admin credentials (USER=... and PASS=... with bcrypt hash)
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

// Generate persistent cookie authentication token signature
$isLoggedIn = false;
$authSecret = md5($validUser . $validHash . 'localnh_secret_salt');

if (isset($_COOKIE['remote_auth']) && $_COOKIE['remote_auth'] === $authSecret) {
    $isLoggedIn = true;
}

// Process login submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    if ($user !== '' && $user === $validUser && password_verify($pass, $validHash)) {
        // Set 30-day authentication cookie
        setcookie('remote_auth', $authSecret, time() + 86400 * 30, '/');
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

// Favicon configuration loader
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
