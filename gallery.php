<?php
// This file is included by index.php with $id and $db already set.
// Direct access without those variables is blocked here as a safety guard.
if (!isset($id) || !isset($db)) die('Invalid access');

// --- Delete action ---
// The 'delete' action is only honoured for logged-in users (no CSRF token; relies on login).
// It purges the cached image directory first, then removes the DB row.
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($isLoggedIn)) {
    $cache_dir = __DIR__ . "/cache/images/" . $id;
    if (is_dir($cache_dir)) {
        // glob() returns all files; @ suppresses errors on unlink for missing/locked files.
        $files = glob($cache_dir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) @unlink($file);
            }
        }
        @rmdir($cache_dir); // Remove the now-empty directory.
    }
    
    // Parameterised DELETE to prevent SQL injection.
    $stmt = $db->prepare("DELETE FROM galleries WHERE id = ?");
    $stmt->execute([$id]);
    
    header("Location: /");
    exit;
}

// --- Download handler ---
// Triggered by ?download=<format> where <format> is 'zip', 'cbz', or 'pdf'.
// Generates the archive on the fly and streams it directly to the browser.
if (isset($_GET['download'])) {
    $format = $_GET['download'];
    $stmt = $db->prepare("SELECT * FROM galleries WHERE id = ?");
    $stmt->execute([$id]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$g) die("Not found.");
    
    $pages = json_decode($g['pages_paths'], true) ?: [];
    if (empty($pages)) die("No pages.");

    // Sanitise the title for use as a filename: replace any non-alphanumeric/underscore/dash
    // character with '_' to avoid OS-specific filename restrictions.
    $title = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $g['title']);
    
    if ($format === 'zip' || $format === 'cbz') {
        // Both ZIP and CBZ use the same ZipArchive container; they differ only in file extension
        // and MIME type. CBZ is a comic reader convention for ZIP-packed page images.
        $zip = new ZipArchive();
        // tempnam() creates a unique temporary file; ZipArchive will overwrite it.
        $tmp = tempnam(sys_get_temp_dir(), 'arc');
        if ($zip->open($tmp, ZipArchive::CREATE) !== TRUE) {
            die("Cannot create zip.");
        }
        foreach ($pages as $p) {
            $path = __DIR__ . '/' . ltrim($p, '/');
            if (file_exists($path)) {
                // Store files with just the base name (no subdirectory) for clean extraction.
                $zip->addFile($path, basename($p));
            }
        }
        $zip->close();
        
        // CBZ uses a specialised MIME type recognised by comic reader apps.
        $ctype = $format === 'cbz' ? 'application/vnd.comicbook+zip' : 'application/zip';
        header("Content-Type: $ctype");
        header("Content-Disposition: attachment; filename=\"{$title}.{$format}\"");
        header("Content-Length: " . filesize($tmp));
        readfile($tmp);
        unlink($tmp); // Clean up the temp file after streaming.
        exit;
    } elseif ($format === 'pdf') {
        // PDF generation is delegated to a Python script (script/py/pdf.py) running in a venv.
        // The script receives the gallery ID and a temp output path, then writes the PDF there.
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        $cmd = escapeshellarg(__DIR__ . "/venv/bin/python") . " " . escapeshellarg(__DIR__ . "/script/py/pdf.py") . " " . escapeshellarg($id) . " " . escapeshellarg($tmp);
        exec($cmd, $out, $ret);
        // $ret === 0 indicates success; check file_exists too in case the script exited cleanly
        // but failed to write the file (e.g., permission error).
        if ($ret === 0 && file_exists($tmp)) {
            header("Content-Type: application/pdf");
            header("Content-Disposition: attachment; filename=\"{$title}.pdf\"");
            header("Content-Length: " . filesize($tmp));
            readfile($tmp);
            unlink($tmp);
            exit;
        } else {
            die("PDF generation failed.");
        }
    } elseif ($format === 'html') {
        // Standalone offline HTML generation is delegated to script/py/html.py.
        // It embeds all metadata, thumbnails, and page WebP images as Base64 data URIs.
        $tmp = tempnam(sys_get_temp_dir(), 'html');
        $pythonExec = file_exists(__DIR__ . "/venv/bin/python") ? (__DIR__ . "/venv/bin/python") : "python";
        $cmd = escapeshellarg($pythonExec) . " " . escapeshellarg(__DIR__ . "/script/py/html.py") . " " . escapeshellarg($id) . " " . escapeshellarg($tmp);
        exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($tmp)) {
            header("Content-Type: text/html; charset=UTF-8");
            header("Content-Disposition: attachment; filename=\"{$title}.html\"");
            header("Content-Length: " . filesize($tmp));
            readfile($tmp);
            unlink($tmp);
            exit;
        } else {
            die("HTML generation failed.");
        }
    }
}

// --- Main gallery lookup ---
$stmt = $db->prepare("SELECT * FROM galleries WHERE id = ?");
$stmt->execute([$id]);
$g = $stmt->fetch(PDO::FETCH_ASSOC);

// Gallery is not yet in the local DB — attempt to fetch it from the upstream API.
if (!$g) {
    $themeCss = getThemeVars();
    
    // config/token must exist for the Python fetch script to authenticate with the API.
    $missingToken = !file_exists(__DIR__ . '/config/token');
    // $canArchive may not be set if we somehow reach here without an index.php bootstrap;
    // default to true (permit) in that edge case.
    $archiveDisabled = !(isset($canArchive) ? $canArchive : true);

    // Show a simple error page when archiving isn't possible — no fetch is attempted.
    if ($archiveDisabled || $missingToken) {
        if ($archiveDisabled) {
            $errTitle = 'title not found';
            $errMsg = "the thing you are looking for is not found. you will be redirected to the homepage soon.";
        } else {
            $errTitle = 'token not found';
            // The token file (config/token) must contain a valid API token for fetch.py to work.
            $errMsg = "token file doesn't exist to archive this title. please contact the administrator. you will be redirected to the homepage soon.";
        }
        echo "<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>{$errTitle} - " . htmlspecialchars($siteName) . "</title>
    <link rel='stylesheet' href='/css/style.css'>
    {$faviconHtml}
    <meta http-equiv='refresh' content='5;url=/' />
    <style>:root { {$themeCss} }</style>
</head>
<body>
    <main class='narrow-page'>
    <div class='topbar'>
        <h1><a href='/'>" . htmlspecialchars($siteName) . "</a></h1>
    </div>
    <div class='settings-content'>
        <h2>{$errTitle}</h2>
        <p class='ui-text'>{$errMsg}</p>
    </div>
    </main>
</body>
</html>";
        exit;
    }

    // Render the fetch UI (cover placeholder, progress indicator) before starting the download.
    // The browser will receive and execute <script> tags injected into the still-open response.
    echo "<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>fetch {$id} - " . htmlspecialchars($siteName) . "</title>
    <link rel='stylesheet' href='/css/style.css'>
    {$faviconHtml}
    <style>
        :root {
            {$themeCss}
        }
    </style>
</head>
<body>
    <main class='narrow-page'>
    <div class='topbar'>
        <h1><a href='/'>" . htmlspecialchars($siteName) . "</a></h1>
    </div>
    <div class='container'>
        <div class='cover'><img id='cover-img' src='' alt='loading cover...'></div>
        <div class='info'>
            <h2 id='title-text'>fetching metadata...</h2>
            <p id='progress-text' class='ui-text'>initializing...</p>
            <div class='cancel-btn-group'>
                <a href='/'><button class='action-btn'>cancel</button></a>
                <p class='ui-text warning-text'>do not leave or close the page, it might cancels out the download process.</p>
            </div>
        </div>
    </div>
    </main>
    <script>
        function updateMeta(title, cover, cover_b64) {
            document.getElementById('title-text').innerHTML = title;
            document.title = 'fetch ' + document.getElementById('title-text').innerText + ' - ' + " . json_encode($siteName) . ";
            if (cover_b64) {
                // Use the base64-encoded cover sent inline to avoid a separate HTTP request.
                document.getElementById('cover-img').src = 'data:image/webp;base64,' + cover_b64;
            } else if (cover) {
                document.getElementById('cover-img').src = '/' + cover;
            }
        }
        function updateProgress(current, total) {
            document.getElementById('progress-text').innerText = 'downloaded ' + current + ' of ' + total + ' pages...';
        }
        function done(id) {
            window.location.href = '/g/' + id;
        }
        function showError(msg) {
            if (msg.includes('404')) {
                document.querySelector('.container').innerHTML = `
                    <div class='settings-content'>
                        <h2>title not found</h2>
                        <p class='ui-text'>the thing you are looking for is not found. you will be redirected to the homepage soon.</p>
                    </div>
                `;
                document.title = 'title not found - ' + " . json_encode($siteName) . ";
                setTimeout(() => { window.location.href = '/'; }, 5000);
            } else {
                document.getElementById('progress-text').innerText = 'error: ' + msg;
                document.getElementById('progress-text').classList.add('error-text');
            }
        }
    </script>
";
    // Disable PHP's execution time limit — the fetch can take several minutes for large galleries.
    set_time_limit(0);
    // X-Accel-Buffering: no tells nginx to not buffer the response so <script> tags stream live.
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');
    // Send a padding block to force some proxies and browsers to start rendering the document.
    echo str_repeat(' ', 8192) . "\n";
    ob_flush();
    flush();

    // ignore_user_abort(true) keeps PHP running even if the browser disconnects,
    // allowing the cleanup block below to kill the child process and remove partial downloads.
    ignore_user_abort(true);
    // Launch fetch.py via proc_open so we can stream its stdout line by line.
    // stderr is merged into stdout (2>&1) so errors are also captured.
    $cmd = escapeshellarg(__DIR__ . "/venv/bin/python") . " " . escapeshellarg(__DIR__ . "/script/py/fetch.py") . " " . escapeshellarg($id) . " 2>&1";
    // Descriptor 1 (stdout) is opened as a readable pipe; stdin/stderr are inherited.
    $process = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
    $finalData = null;
    
    if (is_resource($process)) {
        // Read the child process output one line at a time.
        // Each line is a JSON object following a simple protocol:
        //   {"type": "meta",     "title": "...", "cover": "...", "cover_b64": "..."}
        //   {"type": "progress", "current": N, "total": M}
        //   {"type": "done",     "data": {...}}
        //   {"error": "..."}
        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $line = trim($line);
                if (empty($line)) continue;
                $decoded = json_decode($line, true);
                if ($decoded) {
                    if (isset($decoded['error'])) {
                        $err = addslashes($decoded['error']);
                        echo "<script>showError('$err');</script>\n";
                        ob_flush(); flush();
                        die();
                    }
                    if (isset($decoded['type'])) {
                        if ($decoded['type'] === 'meta') {
                            // addslashes escapes the string for safe injection into a JS single-quoted literal.
                            $t = addslashes(htmlspecialchars($decoded['title']));
                            $c = addslashes($decoded['cover']);
                            $b64 = isset($decoded['cover_b64']) ? $decoded['cover_b64'] : '';
                            echo "<script>updateMeta('$t', '$c', '$b64');</script>\n";
                        } elseif ($decoded['type'] === 'progress') {
                            $cur = $decoded['current'];
                            $tot = $decoded['total'];
                            echo "<script>updateProgress($cur, $tot);</script>\n";
                        } elseif ($decoded['type'] === 'done') {
                            // Capture the final data payload for the DB INSERT below.
                            $finalData = $decoded['data'];
                            echo "<script>done('$id');</script>\n";
                        }
                    }
                } else {
                    // Non-JSON line (e.g. a Python traceback) — log it to the browser console.
                    $err = addslashes($line);
                    echo "<script>console.error('$err');</script>\n";
                }
                // Send another padding block after each line to keep the connection alive
                // and force the browser to process newly injected <script> tags.
                echo str_repeat(' ', 8192) . "\n";
                ob_flush();
                flush();
                
                // If the user has navigated away, kill the child process and clean up
                // any partially downloaded images to avoid leaving orphaned cache files.
                if (connection_aborted()) {
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        exec("kill -9 " . escapeshellarg($status['pid']));
                    }
                    fclose($pipes[1]);
                    proc_close($process);
                    $cache_dir = __DIR__ . "/cache/images/" . $id;
                    exec("rm -rf " . escapeshellarg($cache_dir));
                    exit;
                }
            }
        }
        fclose($pipes[1]);
        proc_close($process);
    }

    // Persist the fetched gallery to the local SQLite cache so subsequent page loads
    // are served from disk without hitting the upstream API again.
    if ($finalData) {
        $stmt = $db->prepare("INSERT INTO galleries (id, title, title_jp, language, scanlator, tags, cover_path, pages_paths, page_count, upload_date, created_at, raw_api_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id, 
            $finalData['title'] ?? '', 
            $finalData['title_jp'] ?? '', 
            $finalData['language'] ?? '', 
            $finalData['scanlator'] ?? '', 
            json_encode($finalData['tags'] ?? []),   // Serialise tag arrays to JSON for storage.
            $finalData['cover'] ?? '', 
            json_encode($finalData['pages'] ?? []),  // Serialise page path array to JSON.
            $finalData['page_count'] ?? 0, 
            $finalData['upload_date'] ?? 0,
            time(),                                  // created_at = current Unix timestamp.
            json_encode($finalData['raw_api_data'] ?? [])
        ]);
    }
    echo "</body></html>";
    exit;
}

$cover = htmlspecialchars('/' . $g['cover_path']);
$pagesCount = (int)$g['page_count'];

$titleHtml = htmlspecialchars($g['title']);
$title = htmlspecialchars($g['title']);
$darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on') === 'on';
$dynamicColorSetting = (isset($_COOKIE['dynamic_color']) ? $_COOKIE['dynamic_color'] : 'on') === 'on';
$bgColor = $dynamicColorSetting ? getDominantColor($g['cover_path'], $darkMode) : ($darkMode ? '#111' : '#fff');
$themeCss = getThemeVars($bgColor);

$tagsData = json_decode($g['tags'], true);
if (is_array($tagsData) && isset($tagsData[0]) && is_string($tagsData[0])) {
    $tagsData = ['tags' => $tagsData];
} elseif (!is_array($tagsData)) {
    $tagsData = [];
}

$tagsHtml = "";
$categories = ['artists', 'circles', 'parodies', 'characters', 'tags'];
$catParamMap = [
    'artists'    => 'a',
    'circles'    => 'c',
    'parodies'   => 'p',
    'characters' => 'char',
    'tags'       => 't',
];
foreach ($categories as $cat) {
    if (!empty($tagsData[$cat])) {
        $catLabel = strtolower($cat);
        $paramKey = $catParamMap[$cat] ?? 'q';
        $listArr = [];
        foreach ($tagsData[$cat] as $item) {
            $itemEscaped = htmlspecialchars($item);
            $urlEscaped = urlencode($item);
            $listArr[] = "<a href='/?{$paramKey}={$urlEscaped}' class='tag-item'>{$itemEscaped}</a>";
        }
        $listHtml = implode(" ", $listArr);
        $tagsHtml .= "<p><b class='ui-text'>{$catLabel}</b>&nbsp;&nbsp;&nbsp;{$listHtml}</p>";
    }
}

$pages = json_decode($g['pages_paths'], true) ?: [];
foreach ($pages as &$p) {
    $p = '/' . $p;
}
$pagesJson = json_encode($pages);

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$ogUrl = "{$protocol}://{$host}/g/{$id}";
$ogImage = "{$protocol}://{$host}{$cover}";

if (isset($isDownloadPage) && $isDownloadPage) {
?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>download <?= $title ?> - <?= htmlspecialchars($siteName) ?></title>
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?= $ogImage ?>">
    <meta property="og:url" content="<?= $ogUrl ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel='stylesheet' href='/css/style.css'>
    <?= $faviconHtml ?>
    <style>
        :root {
            <?= $themeCss ?>
        }
    </style>
</head>
<body>
    <main class='narrow-page'>
    <div class='topbar'>
        <h1><a href='/'><?= htmlspecialchars($siteName) ?></a></h1>
    </div>
    <div class='container'>
        <div class='cover'><a href='/g/<?= $id ?>/1'><img src='<?= $cover ?>' alt='cover'></a></div>
        <div class='info'>
            <h2><?= $titleHtml ?></h2>
            <p id='status-text' class='ui-text'></p>
            <div id='options-group' class='action-btn-group wrap'>
                <span class='ui-text download-label'>download as</span>
                <button onclick="startDownload('zip')" class='action-btn'>zip</button>
                <button onclick="startDownload('cbz')" class='action-btn'>cbz</button>
                <button onclick="startDownload('pdf')" class='action-btn'>pdf</button>
                <button onclick="startDownload('html')" class='action-btn'>html</button>
            </div>
            <div class='back-link-wrapper'><a href='/g/<?= $id ?>' class='ui-text'>back to gallery</a></div>
        </div>
    </div>
    <iframe id='download-iframe' class='hidden'></iframe>
    <script>
        function startDownload(fmt) {
            document.getElementById('download-iframe').src = '/download/<?= $id ?>?download=' + fmt;
            document.getElementById('status-text').innerHTML = 'downloading ' + fmt + '...<br>redirecting in 5 seconds...';
            document.getElementById('options-group').style.display = 'none';
            setTimeout(function() {
                window.location.href = '/g/<?= $id ?>';
            }, 5000);
        }
    </script>
    </main>
</body></html>
<?php
} else {
?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title><?= $title ?> - <?= htmlspecialchars($siteName) ?></title>
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?= $ogImage ?>">
    <meta property="og:url" content="<?= $ogUrl ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel='stylesheet' href='/css/style.css'>
    <?= $faviconHtml ?>
    <style>
        :root {
            <?= $themeCss ?>
        }
    </style>
</head>
<body>
    <main>
    <div class='topbar' id='main-topbar'>
        <h1 id='topbar-title'><a href='/'><?= htmlspecialchars($siteName) ?></a></h1>
        <div class='topbar-controls' id='topbar-controls'>
            <div class='search-container'>
                <form method='GET' action='/' id='search-form'>
                    <div class='search-wrapper'>
                        <input type='text' name='q' id='search-input' class='search-input' placeholder='<?= $canArchive ? "search or archive" : "search" ?>' value='' autocomplete='off'>
                        <button type='submit' class='search-btn'>go</button>
                    </div>
                    <div id='autocomplete-results' class='autocomplete-results'></div>
                </form>
            </div>
            <a href='#footer' class='top-menu-link ui-text'>menu</a>
        </div>
    </div>
    <div class='container'>
        <div class='cover'><a href='/g/<?= $id ?>/1'><img src='<?= $cover ?>' alt='cover'></a></div>
        <div class='info'>
            <h2><?= $titleHtml ?></h2>
            <p><span id='copy-id-btn' class='copyable-id ui-text' title='copy id'>#<?= htmlspecialchars($id) ?></span><span id='copy-feedback' class='feedback-text hidden ui-text'>copied!</span></p>
<?php $langText = $g['language'] ? htmlspecialchars($g['language']) : 'unknown'; ?>
            <?= $tagsHtml ?>
<?php
$uploadedDate = $g['upload_date'] ? date('Y-m-d', $g['upload_date']) : 'unknown';
$archivedDate = $g['created_at'] ? date('Y-m-d', $g['created_at']) : 'unknown';
$langUrl = urlencode($langText);
?>
            <p><b class='ui-text'>language</b>&nbsp;&nbsp;&nbsp;<a href='/?l=<?= $langUrl ?>' class='tag-item'><?= htmlspecialchars($langText) ?></a></p>
            <p><b class='ui-text'>pages</b>&nbsp;&nbsp;&nbsp;<span class='tag-item static'><?= $pagesCount ?></span></p>
            <p><b class='ui-text'>uploaded</b>&nbsp;&nbsp;&nbsp;<span class='tag-item static'><?= $uploadedDate ?></span></p>
            <p><b class='ui-text'>archived</b>&nbsp;&nbsp;&nbsp;<span class='tag-item static'><?= $archivedDate ?></span></p>
            <div class='action-btn-group wrap'>
                <a href='/g/<?= $id ?>/1'><button class='action-btn'>read</button></a>
                <button id='fav-btn' class='action-btn'>favorite</button>
                <button id='cum-btn' class='action-btn'>cum</button>
                <a href='/download/<?= $id ?>'><button class='action-btn'>download</button></a>
                <a href='https://nhentai.net/g/<?= $id ?>/' target='_blank' rel='noreferrer'><button class='action-btn'>nhentai</button></a>
                <?php if (!empty($isLoggedIn)): ?>
                <button id='delete-btn' class='action-btn'>delete</button>
                <?php endif; ?>
            </div>
            <p id='gallery-feedback' class='ui-text user-feedback'></p>
        </div>
    </div>
    <div id='preview-container'>
        <div id='preview-grid' class='preview-grid'></div>
        <div id='show-more-wrapper' class='show-more-wrapper hidden'>
            <button id='show-more-btn' class='show-more-btn'>show more</button>
        </div>
    </div>
<?php
$relatedGalleries = [];

$curTags = array_map('strtolower', $tagsData['tags'] ?? []);
$curArtists = array_map('strtolower', $tagsData['artists'] ?? []);
$curCircles = array_map('strtolower', $tagsData['circles'] ?? []);
$curParodies = array_filter(array_map('strtolower', $tagsData['parodies'] ?? []), fn($p) => $p !== 'original');
$curChars = array_map('strtolower', $tagsData['characters'] ?? []);
$curLang = strtolower($g['language'] ?? '');

$allTerms = array_values(array_unique(array_merge($curArtists, $curChars, $curCircles, $curParodies, $curTags)));

$candidates = [];
if (!empty($allTerms)) {
    $whereClauses = [];
    $params = [$id];
    $selectedTerms = array_slice($allTerms, 0, 16);

    foreach ($selectedTerms as $term) {
        $whereClauses[] = "tags LIKE ?";
        $params[] = '%"' . $term . '"%';
    }

    $whereSql = implode(" OR ", $whereClauses);
    $stmt = $db->prepare("SELECT id, title, language, cover_path, tags FROM galleries WHERE id != ? AND ($whereSql) LIMIT 100");
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (count($candidates) < 6) {
    $needed = 6 - count($candidates);
    $existingIds = array_merge([$id], array_column($candidates, 'id'));
    $inClause = implode(',', array_fill(0, count($existingIds), '?'));
    $stmt = $db->prepare("SELECT id, title, language, cover_path, tags FROM galleries WHERE id NOT IN ($inClause) ORDER BY RANDOM() LIMIT $needed");
    $stmt->execute($existingIds);
    $candidates = array_merge($candidates, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

$scored = [];
foreach ($candidates as $cand) {
    $cData = json_decode($cand['tags'], true) ?: [];
    $cTags = array_map('strtolower', $cData['tags'] ?? []);
    $cArtists = array_map('strtolower', $cData['artists'] ?? []);
    $cCircles = array_map('strtolower', $cData['circles'] ?? []);
    $cParodies = array_filter(array_map('strtolower', $cData['parodies'] ?? []), fn($p) => $p !== 'original');
    $cChars = array_map('strtolower', $cData['characters'] ?? []);
    $cLang = strtolower($cand['language'] ?? '');

    $score = 0.0;
    $score += count(array_intersect($curArtists, $cArtists)) * 10.0;
    $score += count(array_intersect($curChars, $cChars)) * 8.0;
    $score += count(array_intersect($curCircles, $cCircles)) * 6.0;
    $score += count(array_intersect($curParodies, $cParodies)) * 6.0;
    $score += count(array_intersect($curTags, $cTags)) * 2.0;

    if ($curLang !== '' && $curLang === $cLang) {
        $score += 1.0;
    }

    $score += (mt_rand(0, 500) / 1000.0);

    $cand['_score'] = $score;
    $scored[] = $cand;
}

usort($scored, function($a, $b) {
    return $b['_score'] <=> $a['_score'];
});

$relatedGalleries = array_slice($scored, 0, 6);
?>
    <?php if (count($relatedGalleries) > 0): ?>
    <div class='related-section'>
        <h3>you might also like...</h3>
        <div class='grid'>
        <?php foreach ($relatedGalleries as $rg): ?>
            <div class='item'>
                <a href='/g/<?= $rg['id'] ?>'>
                    <img src='<?= htmlspecialchars('/' . $rg['cover_path']) ?>' alt='cover'>
                    <div class='item-title'><?= htmlspecialchars($rg['title']) ?></div>
                </a>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class='footer' id='footer'>
        <div class='footer-credits ui-text'>
            <?= $footerHtml ?>
        </div>
        <div class='footer-links'>
            <a href='/directory' class='ui-text'>directory</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/favorites' class='ui-text'>favorites</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/rss' class='ui-text'>rss</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/random' class='ui-text'>random</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/user' class='ui-text'>user</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/stats' class='ui-text'>stats</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/settings' class='ui-text'>settings</a>
        </div>
    </div>
    </main>
    
    <script>
        window.allPages = <?= $pagesJson ?>;
        window.galleryId = '<?= $id ?>';
        window.galleryTitle = <?= json_encode($g['title'] ?: $g['title_jp'] ?: '') ?>;
        window.galleryTaxonomy = <?= json_encode([
            'tags' => $tagsData['tags'] ?? [],
            'artists' => $tagsData['artists'] ?? [],
            'circles' => $tagsData['circles'] ?? [],
            'parodies' => $tagsData['parodies'] ?? [],
            'characters' => $tagsData['characters'] ?? [],
            'language' => $g['language'] ?? ''
        ]) ?>;
    </script>
    <script src="/script/js/gallery.js?v=2"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('search-input');
        const resultsBox = document.getElementById('autocomplete-results');
        const topbar = document.getElementById('main-topbar');
        
        let debounceTimer;
        
        input.addEventListener('focus', function() {
            if (window.innerWidth <= 600) {
                topbar.classList.add('search-active');
            }
        });
        
        input.addEventListener('blur', function() {
            setTimeout(() => {
                resultsBox.style.display = 'none';
                if (window.innerWidth <= 600) {
                    topbar.classList.remove('search-active');
                }
            }, 200);
        });
        
        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const val = input.value;
            const parts = val.split(' ');
            const lastPart = parts[parts.length - 1];
            
            if (lastPart.length < 2) {
                resultsBox.style.display = 'none';
                return;
            }
            
            debounceTimer = setTimeout(() => {
                fetch('/api/autocomplete?q=' + encodeURIComponent(lastPart))
                .then(res => res.json())
                .then(data => {
                    resultsBox.innerHTML = '';
                    if (data.length > 0) {
                        resultsBox.style.display = 'block';
                        data.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'autocomplete-item';
                            div.textContent = item;
                            div.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                parts[parts.length - 1] = item.split(' - ')[1];
                                input.value = parts.join(' ') + ' ';
                                resultsBox.style.display = 'none';
                                input.focus();
                            });
                            resultsBox.appendChild(div);
                        });
                    } else {
                        resultsBox.style.display = 'none';
                    }
                });
            }, 200);
        });
    });
    </script>
</body></html>
<?php } ?>
