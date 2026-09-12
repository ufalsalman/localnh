<?php
// $themeCss is returned by getThemeVars() (injected by the router bootstrap).
// It contains a block of CSS custom-property declarations for the active color scheme.
$themeCss = getThemeVars();

// Read the category filter from the URL, e.g. /directory?cat=tags
$cat = is_string($_GET['cat'] ?? '') ? ($_GET['cat'] ?? '') : '';
// Read sort preference; default is by occurrence count (descending).
$sort = is_string($_GET['sort'] ?? 'counts') ? ($_GET['sort'] ?? 'counts') : 'counts';

require_once __DIR__ . '/aggregation.php';
header('Cache-Control: no-store');
$counts = [];
if (is_string($cat) && in_array($cat, ['tags', 'parodies', 'characters', 'artists', 'circles'], true)) {
    $counts = localnh_aggregate('tags')[$cat];
}
// Convert the associative counts map into an indexed array suitable for usort().
$items = [];
foreach ($counts as $name => $count) {
    $items[] = ['name' => $name, 'count' => $count];
}

// Sort alphabetically (case-insensitive), or by descending count with an
// alphabetical tie-breaker when two terms share the same count.
if ($sort === 'alpha') {
    usort($items, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
} else {
    usort($items, function($a, $b) {
        if ($a['count'] === $b['count']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $b['count'] - $a['count'];
    });
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title><?= $cat ? 'directory of ' . htmlspecialchars($cat) . ' - ' . htmlspecialchars($siteName) : 'directory - ' . htmlspecialchars($siteName) ?></title>
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
                        <input type='text' name='q' id='search-input' class='search-input' placeholder='<?= $canArchive ? "search or archive" : "search" ?>' autocomplete='off'>
                        <button type='submit' class='search-btn'>go</button>
                    </div>
                    <div id='autocomplete-results' class='autocomplete-results'></div>
                </form>
            </div>
            <a href='#footer' class='top-menu-link ui-text'>menu</a>
        </div>
    </div>

    <h2>directory</h2>
    
    <div class='dir-filters'>
        <form method='GET' action='/directory' id='dir-form'>
            <div class='dir-filter-group'>
                <select name='cat' id='cat-select' onchange='document.getElementById("dir-form").submit()'>
                    <option value='' <?= $cat === '' ? 'selected' : '' ?> class='hidden-option'>choose</option>
                    <option value='tags' <?= $cat === 'tags' ? 'selected' : '' ?>>tags</option>
                    <option value='parodies' <?= $cat === 'parodies' ? 'selected' : '' ?>>parodies</option>
                    <option value='characters' <?= $cat === 'characters' ? 'selected' : '' ?>>characters</option>
                    <option value='artists' <?= $cat === 'artists' ? 'selected' : '' ?>>artist</option>
                    <option value='circles' <?= $cat === 'circles' ? 'selected' : '' ?>>circle</option>
                </select>
            </div>
            
            <div class='dir-filter-group'>
                <select name='sort' id='sort-select' onchange='document.getElementById("dir-form").submit()'>
                    <option value='counts' <?= $sort === 'counts' ? 'selected' : '' ?>>counts</option>
                    <option value='alpha' <?= $sort === 'alpha' ? 'selected' : '' ?>>alphabetical</option>
                </select>
            </div>
        </form>
    </div>

    <?php if ($cat !== '' && count($items) > 0): ?>
        <?php
        // Map each category name to its corresponding gallery search query parameter.
        // These short params are used on the main gallery index (e.g. /?t=tag_name).
        $dirParamMap = [
            'tags'       => 't',
            'artists'    => 'a',
            'circles'    => 'c',
            'parodies'   => 'p',
            'characters' => 'char',
        ];
        // Fall back to generic 'q' if the category is somehow not in the map.
        $targetParam = $dirParamMap[$cat] ?? 'q';
        ?>
        <div class='dir-columns'>
            <?php foreach ($items as $item): ?>
                <div class='dir-item'>
                    <!-- Each term links to a filtered gallery search using the appropriate param -->
                    <a href='/?<?= $targetParam ?>=<?= urlencode($item['name']) ?>' class='dir-name'><?= htmlspecialchars($item['name']) ?></a>
                    <span class='dir-count'><?= htmlspecialchars($item['count']) ?></span>
                </div>
            <?php endforeach; ?>
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
    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('search-input');
        const resultsBox = document.getElementById('autocomplete-results');
        const topbar = document.getElementById('main-topbar');
        
        // Debounce timer handle — restarted on each keystroke to avoid flooding the API.
        let debounceTimer;
        
        // On narrow screens (≤600 px) expand the topbar into a full-width search bar.
        input.addEventListener('focus', function() {
            if (window.innerWidth <= 600) {
                topbar.classList.add('search-active');
            }
        });
        
        // Delay hiding the dropdown 200 ms so a mousedown on a suggestion registers before blur fires.
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
            // The search box supports space-separated tokens; autocomplete acts only on
            // the last token so previously typed terms are not disrupted.
            const parts = val.split(' ');
            const lastPart = parts[parts.length - 1];
            
            // Don't query the API until the current token is at least 2 characters.
            if (lastPart.length < 2) {
                resultsBox.style.display = 'none';
                return;
            }
            
            // Wait 200 ms after the last keystroke before hitting the autocomplete endpoint.
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
                                // Prevent the input from losing focus before we update its value.
                                e.preventDefault();
                                // API returns "category - value"; extract just the tag value part.
                                parts[parts.length - 1] = item.split(' - ')[1];
                                // Re-join previous tokens and append a trailing space
                                // so the user can immediately type the next search term.
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
