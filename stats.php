<?php
/**
 * Stats Page
 * Displays total archived titles count, total storage space used, and latest archived activity.
 */

$themeCss = getThemeVars();

// Count total archived galleries
$stmt = $db->query("SELECT COUNT(*) FROM galleries");
$titlesArchived = number_format((int)$stmt->fetchColumn());

// Get latest activity timestamp
$latestStmt = $db->query("SELECT MAX(created_at) FROM galleries");
$latestTimestamp = (int)$latestStmt->fetchColumn();

// Calculate total disk space used by cache and database
function getDirSize($dir) {
    if (!is_dir($dir)) return 0;
    if (function_exists('shell_exec')) {
        $out = @shell_exec('du -sb ' . escapeshellarg($dir) . ' 2>/dev/null');
        if ($out) {
            $parts = explode("\t", trim($out));
            if (!empty($parts[0]) && is_numeric($parts[0])) {
                return (int)$parts[0];
            }
        }
    }
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

$totalBytes = getDirSize(localnh_data_path('cache'));
$dbFile = localnh_data_path('cache.sqlite');
if (file_exists($dbFile)) {
    $totalBytes += filesize($dbFile);
}
$spaceUsed = formatBytes($totalBytes);
?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>stats - <?= htmlspecialchars($siteName) ?></title>
    <link rel='stylesheet' href='/css/style.css'>
    <?= $faviconHtml ?>
    <style>
        :root { <?= $themeCss ?> }
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
    <div class='settings-content'>
        <h2>stats</h2>
        <p><b class='ui-text'>titles archived</b>&nbsp;&nbsp;&nbsp;<?= htmlspecialchars($titlesArchived) ?></p>
        <p><b class='ui-text'>space used</b>&nbsp;&nbsp;&nbsp;<?= htmlspecialchars($spaceUsed) ?></p>
        <p><b class='ui-text'>latest activity</b>&nbsp;&nbsp;&nbsp;<span id='latest-activity' data-timestamp='<?= $latestTimestamp ?>'><?= $latestTimestamp ? gmdate('Y/m/d H:i:s (\\U\\T\\C)', $latestTimestamp) : '-' ?></span></p>
    </div>
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
    document.addEventListener('DOMContentLoaded', function() {
        // Format latest activity date to user local timezone: YYYY/MM/DD HH:mm:ss (UTC+X)
        const latestEl = document.getElementById('latest-activity');
        if (latestEl && latestEl.dataset.timestamp) {
            const ts = parseInt(latestEl.dataset.timestamp, 10);
            if (ts > 0) {
                const d = new Date(ts * 1000);
                const pad = n => String(n).padStart(2, '0');
                const year = d.getFullYear();
                const month = pad(d.getMonth() + 1);
                const day = pad(d.getDate());
                const hours = pad(d.getHours());
                const mins = pad(d.getMinutes());
                const secs = pad(d.getSeconds());

                const offsetMin = -d.getTimezoneOffset();
                const sign = offsetMin >= 0 ? '+' : '-';
                const absOffset = Math.abs(offsetMin);
                const offsetHours = Math.floor(absOffset / 60);
                const offsetRemMin = absOffset % 60;
                const tzStr = offsetRemMin > 0 
                    ? `UTC${sign}${offsetHours}:${pad(offsetRemMin)}`
                    : `UTC${sign}${offsetHours}`;

                latestEl.textContent = `${year}/${month}/${day} ${hours}:${mins}:${secs} (${tzStr})`;
            }
        }

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
</body>
</html>
