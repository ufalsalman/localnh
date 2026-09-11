<?php
// Page content is served through index.php. Shared presentation is loaded once.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/presentation.php';

$siteName = getSiteName();

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
$faviconHtml = getFaviconHtml();
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
