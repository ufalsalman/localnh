<?php
/**
 * Manga Reader Interface
 * Renders full-screen responsive image viewing with keyboard navigation and RTL/LTR controls.
 */
if (!isset($id) || !isset($db)) die('Invalid access');

// Query gallery data from SQLite database
$stmt = $db->prepare("SELECT * FROM galleries WHERE id = ?");
$stmt->execute([$id]);
$g = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$g) {
    die("Gallery not found locally.");
}

// Decode JSON page paths array and prefix with leading slash for web routing
$pages = json_decode($g['pages_paths'], true) ?: [];
foreach ($pages as &$p) {
    $p = '/' . $p;
}
$pagesJson = json_encode($pages);
$title = htmlspecialchars($g['title']);

// Open Graph metadata for rich link previews (Discord, Telegram, Twitter)
$cover = htmlspecialchars('/' . $g['cover_path']);
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$ogUrl = "{$protocol}://{$host}/g/{$id}/" . (isset($pageParam) ? $pageParam : 1);
$ogImage = "{$protocol}://{$host}{$cover}";

$titleHtml = htmlspecialchars($g['title']);

// Reading direction preference (LTR or RTL manga mode)
$flipPageOrder = (isset($_COOKIE['flip_page_order']) && in_array($_COOKIE['flip_page_order'], ['ltr', 'rtl'])) ? $_COOKIE['flip_page_order'] : 'ltr';

$themeCss = getThemeVars();
?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title><?= $title ?> - page <?= isset($pageParam) ? $pageParam : 1 ?> - <?= htmlspecialchars($siteName) ?></title>
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?= $ogImage ?>">
    <meta property="og:url" content="<?= $ogUrl ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel='stylesheet' href='/css/style.css?v=1787039554'>
    <?= $faviconHtml ?>
    <style>
        :root {
            <?= $themeCss ?>
        }
    </style>
</head>
<body>
    <main>
    <div class='topbar reader-topbar'>
        <div class='reader-sitename'><a href='/'><b><?= htmlspecialchars($siteName) ?></b></a></div>
        <div class='reader-title'><a href='/g/<?= $id ?>'><?= $titleHtml ?></a></div>
    </div>
    <div id='viewer'>
        <img id='manga-page' src='' alt='loading...'>
    </div>
    <div class='controls'>
        <?php if ($flipPageOrder === 'rtl'): ?>
        <button id='next' class='action-btn'>next</button>
        <span id='page-indicator' class='ui-text'></span>
        <button id='prev' class='action-btn'>prev</button>
        <?php else: ?>
        <button id='prev' class='action-btn'>prev</button>
        <span id='page-indicator' class='ui-text'></span>
        <button id='next' class='action-btn'>next</button>
        <?php endif; ?>
    </div>

    <script>
        window.pages = <?= $pagesJson ?>;
        window.galleryId = '<?= $id ?>';
        window.initialPage = <?= isset($pageParam) ? $pageParam : 1 ?>;
        window.galleryTitle = <?= json_encode($title) ?>;
        window.siteName = <?= json_encode($siteName) ?>;
        window.pageOrder = <?= json_encode($flipPageOrder) ?>;
    </script>
    <script src="/script/js/reader.js?v=<?= file_exists(__DIR__ . '/script/js/reader.js') ? filemtime(__DIR__ . '/script/js/reader.js') : time() ?>"></script>
    </main>
</body></html>
