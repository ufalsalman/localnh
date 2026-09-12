<?php
require_once __DIR__ . "/storage.php";
// Open the collection database in the selected legacy or external data root.
// PDO is used for its unified API and prepared-statement support across DB drivers.
$db = new PDO('sqlite:' . localnh_data_path('cache.sqlite'));
// Throw exceptions on query errors instead of silently returning false.
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Create the galleries table only when it does not already exist (idempotent bootstrap).
// tags and pages_paths are stored as JSON strings because SQLite lacks native array types.
// raw_api_data preserves the original API response for future re-parsing without re-fetching.
$db->exec("CREATE TABLE IF NOT EXISTS galleries (
    id TEXT PRIMARY KEY,
    title TEXT,
    title_jp TEXT,
    language TEXT,
    scanlator TEXT,
    tags TEXT,
    cover_path TEXT,
    pages_paths TEXT,
    page_count INTEGER,
    upload_date INTEGER,
    created_at INTEGER,
    raw_api_data TEXT
)
");

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/presentation.php';

// --- Favicon config ---
// config/favicon may contain a relative path (e.g. "img/icon.png") or an absolute/external URL.
// Relative paths are normalised to root-relative so they work correctly from any sub-path.
$faviconHtml = getFaviconHtml();

// --- Public archive toggle ---
// config/public contains the literal string 'on' to allow unauthenticated users to trigger
// the fetch/archive workflow. When the file is absent or holds any other value, archiving is login-gated.
$paFile = localnh_data_path('config/public');
$publicArchiveEnabled = (file_exists($paFile) && trim(file_get_contents($paFile)) === 'on');
// $canArchive is true for logged-in users OR when public archiving is enabled site-wide.
$canArchive = ($isLoggedIn || $publicArchiveEnabled);

$siteName = getSiteName();
$footerHtml = getFooterHtml();

// --- Front-controller routing ---
// Caddy and the documented PHP development server route all requests here.
// parse_url strips the query string before regex matching so parameters don't leak into path checks.
$requestUri = $_SERVER['REQUEST_URI'];
$rawPath = parse_url($requestUri, PHP_URL_PATH);
$path = is_string($rawPath) ? rawurldecode($rawPath) : '';
// Reject ambiguous paths before routing: hidden/dot segments, backslashes,
// repeated slashes, control bytes, and remaining (double-encoded) escapes.
if ($path === '' || $path[0] !== '/'
    || preg_match('#(?:^|/)\\.|//|[\\\\\\x00-\\x1f\\x7f%]#', $path)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

// Route: /thumbnail — generates a small WebP response without persisting a second image.
if ($path === '/thumbnail' || $path === '/thumbnail/') {
    require 'thumbnail.php';
    exit;
// Route: /g/<id> — individual gallery detail page.
} elseif (preg_match('#^/g/(\d+)/?$#', $path, $matches)) {
    $id = $matches[1];
    require 'gallery.php';
    exit;
// Route: /directory — tag/artist directory browser.
} elseif ($path === '/directory' || $path === '/directory/') {
    require 'directory.php';
    exit;
// Route: /rss — RSS feed of recently archived galleries.
} elseif ($path === '/rss' || $path === '/rss/') {
    require 'rss.php';
    exit;
// Route: /settings — user-facing preference page.
} elseif ($path === '/settings' || $path === '/settings/') {
    require 'settings.php';
    exit;
// Route: /admin — administrative panel (login guard enforced inside admin.php).
} elseif ($path === '/admin' || $path === '/admin/') {
    require 'admin.php';
    exit;
// Route: /user — login / logout page.
} elseif ($path === '/user' || $path === '/user/') {
    require 'user.php';
    exit;
// Route: /stats — library statistics page.
} elseif ($path === '/stats' || $path === '/stats/') {
    require 'stats.php';
    exit;
// Route: /favorites/orphan — favorites whose gallery data no longer exists locally.
} elseif ($path === '/favorites/orphan' || $path === '/favorites/orphan/') {
    $isOrphanPage = true;
    require 'favorites.php';
    exit;
// Route: /favorites — regular saved-favorites list.
} elseif ($path === '/favorites' || $path === '/favorites/') {
    $isOrphanPage = false;
    require 'favorites.php';
    exit;
// Route: POST /api/favorites — JSON API called by client-side favorites.js to resolve
// gallery metadata (title, cover) for a batch of locally-stored IDs.
} elseif ($path === '/api/favorites') {
    require __DIR__ . '/favorites_api.php';
    exit;

// Route: /g/<id>/<page> — gallery reader at a specific page number.
} elseif (preg_match('#^/g/(\d+)/(\d+)/?$#', $path, $matches)) {
    $id = $matches[1];
    $pageParam = (int)$matches[2];
    require 'reader.php';
    exit;
// Route: /download/<id> — download page (reuses gallery.php with $isDownloadPage flag).
} elseif (preg_match('#^/download/(\d+)/?$#', $path, $matches)) {
    $id = $matches[1];
    $isDownloadPage = true;
    require 'gallery.php';
    exit;

// Route: /random — picks a random gallery from the DB and redirects to it.
// ORDER BY RANDOM() is acceptable here because the local archive is typically small.
} elseif ($path === '/random' || $path === '/random/') {
    $stmt = $db->query("SELECT id FROM galleries ORDER BY RANDOM() LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        header("Location: /g/" . $row['id']);
        exit;
    } else {
        // No galleries archived yet; redirect home gracefully.
        header("Location: /");
        exit;
    }
// Route: GET /api/autocomplete?q=<query> — returns up to 10 matching tag/artist suggestions.
} elseif ($path === '/api/autocomplete') {
    require __DIR__ . '/autocomplete.php';
    exit;
// Static files are public only inside these directories, with known types.
} elseif ($path !== '/' && $path !== '/index.php') {
    $imageTypes = [
        'webp' => 'image/webp', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    ];
    $publicAssets = [
        '/css/' => ['css' => 'text/css; charset=utf-8'],
        '/script/js/' => ['js' => 'application/javascript; charset=utf-8'],
        '/cache/images/' => $imageTypes,
        '/static/' => $imageTypes,
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    foreach ($publicAssets as $prefix => $types) {
        if (!str_starts_with($path, $prefix) || !isset($types[$ext])) continue;
        $assetRoot = __DIR__;
        $candidate = __DIR__ . $path;
        if ($prefix === '/cache/images/') {
            $assetRoot = localnh_data_root();
            $candidate = localnh_image_path(ltrim($path, '/'));
        }
        $filePath = $candidate !== false ? realpath($candidate) : false;
        // Compare with the literal allowed directory under the application root.
        // A symlink to a private file or another directory must not bypass this.
        if ($filePath !== false && str_starts_with($filePath, $assetRoot . $prefix)
            && !preg_match('#(?:^|/)\\.#', substr($filePath, strlen($assetRoot)))
            && isset($types[strtolower(pathinfo($filePath, PATHINFO_EXTENSION))])
            && is_file($filePath) && is_readable($filePath)) {
            header('Content-Type: ' . $types[strtolower(pathinfo($filePath, PATHINFO_EXTENSION))]);
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($filePath));
            if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile($filePath);
            exit;
        }
    }
    http_response_code(404);
    echo 'Not Found';
    exit;
}

// --- Homepage: gallery grid ---

$perPage = 30; // Number of gallery thumbnails shown per page.
// Clamp page to at least 1; intval() safely handles missing or non-numeric values.
$page = max(1, intval($_GET['page'] ?? 1));

// Accept the free-text search query from the 'q' parameter.
$q = trim($_GET['q'] ?? '');
// Accept filter parameters via short aliases (a/t/c/p/char/l) or full-word forms.
// Short aliases are used in tag-click links; long forms allow bookmarked URLs to be human-readable.
$filterArtist = trim($_GET['a'] ?? $_GET['artist'] ?? '');
$filterTag = trim($_GET['t'] ?? $_GET['tag'] ?? '');
$filterCircle = trim($_GET['c'] ?? $_GET['circ'] ?? $_GET['circle'] ?? '');
$filterParody = trim($_GET['p'] ?? $_GET['parody'] ?? '');
$filterChar = trim($_GET['char'] ?? $_GET['ch'] ?? $_GET['character'] ?? '');
$filterLang = trim($_GET['l'] ?? $_GET['lang'] ?? $_GET['language'] ?? '');

// Normalise whichever filter was provided into a single ($filterType, $filterValue) pair
// so the rest of the query-building code only needs to handle one variable.
$filterType = '';
$filterValue = '';
$filterLabel = '';

if ($filterArtist !== '') {
    $filterType = 'a';
    $filterValue = $filterArtist;
    $filterLabel = 'artist: ' . $filterArtist;
} elseif ($filterTag !== '') {
    $filterType = 't';
    $filterValue = $filterTag;
    $filterLabel = 'tag: ' . $filterTag;
} elseif ($filterChar !== '') {
    $filterType = 'char';
    $filterValue = $filterChar;
    $filterLabel = 'character: ' . $filterChar;
} elseif ($filterParody !== '') {
    $filterType = 'p';
    $filterValue = $filterParody;
    $filterLabel = 'parody: ' . $filterParody;
} elseif ($filterCircle !== '') {
    $filterType = 'c';
    $filterValue = $filterCircle;
    $filterLabel = 'circle: ' . $filterCircle;
} elseif ($filterLang !== '') {
    $filterType = 'l';
    $filterValue = $filterLang;
    $filterLabel = 'language: ' . $filterLang;
} elseif ($q !== '') {
    $filterLabel = 'search: ' . $q;
}

// Build a matching nhentai.net search URL shown on the results page so users
// can find more entries upstream from the same query.
$nhentaiUrl = '';
if ($filterType !== '' || $q !== '') {
    $nhentaiQuery = '';
    if ($filterType === 'a') {
        $nhentaiQuery = 'artist:"' . $filterValue . '"';
    } elseif ($filterType === 't') {
        $nhentaiQuery = 'tag:"' . $filterValue . '"';
    } elseif ($filterType === 'char') {
        $nhentaiQuery = 'character:"' . $filterValue . '"';
    } elseif ($filterType === 'p') {
        $nhentaiQuery = 'parody:"' . $filterValue . '"';
    } elseif ($filterType === 'c') {
        // nhentai uses the term 'group' for what this app calls 'circle'.
        $nhentaiQuery = 'group:"' . $filterValue . '"';
    } elseif ($filterType === 'l') {
        $nhentaiQuery = 'language:"' . $filterValue . '"';
    } elseif ($q !== '') {
        $nhentaiQuery = $q;
    }
    $nhentaiUrl = 'https://nhentai.net/search/?q=' . urlencode($nhentaiQuery);
}

// Smart redirect: if the query looks like a bare numeric nhentai ID or a full nhentai URL,
// jump straight to the gallery page rather than showing search results.
if ($q !== '' && preg_match('#(?:nhentai\.net/g/|^)(\d{1,6})(?:/|$)#i', $q, $matches)) {
    header("Location: /g/{$matches[1]}");
    exit;
}

// --- Build the SQL WHERE clause for the listing query ---
// Start with a tautology (always true) so additional AND conditions can be safely appended.
$where = "1=1";
$params = [];

if ($filterType !== '') {
    // Tag-category filters use SQLite's json_each() virtual table to query inside the JSON blob.
    // This avoids false-positives from a plain LIKE search (e.g. "elf" matching "myself").
    if ($filterType === 'a') {
        $where = "EXISTS (SELECT 1 FROM json_each(galleries.tags, '$.artists') WHERE lower(value) = lower(:filterVal))";
        $params[':filterVal'] = $filterValue;
    } elseif ($filterType === 't') {
        $where = "EXISTS (SELECT 1 FROM json_each(galleries.tags, '$.tags') WHERE lower(value) = lower(:filterVal))";
        $params[':filterVal'] = $filterValue;
    } elseif ($filterType === 'char') {
        $where = "EXISTS (SELECT 1 FROM json_each(galleries.tags, '$.characters') WHERE lower(value) = lower(:filterVal))";
        $params[':filterVal'] = $filterValue;
    } elseif ($filterType === 'p') {
        $where = "EXISTS (SELECT 1 FROM json_each(galleries.tags, '$.parodies') WHERE lower(value) = lower(:filterVal))";
        $params[':filterVal'] = $filterValue;
    } elseif ($filterType === 'c') {
        $where = "EXISTS (SELECT 1 FROM json_each(galleries.tags, '$.circles') WHERE lower(value) = lower(:filterVal))";
        $params[':filterVal'] = $filterValue;
    } elseif ($filterType === 'l') {
        // Language is a top-level column, not inside the JSON blob, so a simple comparison works.
        $where = "lower(language) = lower(:filterVal)";
        $params[':filterVal'] = $filterValue;
    }
} elseif ($q !== '') {
    // Free-text search: split on spaces and require ALL terms to match (AND logic).
    // Searches across title, language, and the raw JSON tags string.
    // Each term is assigned a numbered parameter name to satisfy PDO named-parameter syntax.
    $terms = explode(' ', $q);
    $whereParts = [];
    foreach ($terms as $i => $term) {
        if (trim($term) === '') continue;
        $whereParts[] = "(title LIKE :q$i OR language LIKE :q$i OR tags LIKE :q$i)";
        $params[":q$i"] = "%$term%";
    }
    if (!empty($whereParts)) {
        $where = implode(' AND ', $whereParts);
    }
}

// Count query: fetch only the total row count for pagination — avoids loading full rows twice.
$stmtCount = $db->prepare("SELECT COUNT(*) FROM galleries WHERE $where");
foreach ($params as $k => $v) $stmtCount->bindValue($k, $v);
$stmtCount->execute();
$totalItems = $stmtCount->fetchColumn();

// Clamp the requested page within valid bounds now that the total is known.
$totalPages = max(1, ceil($totalItems / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Determine homepage status (pure index view without search terms or filters)
$isHomePage = ($filterType === '' && $q === '');
$savedHomeSort = (isset($_COOKIE['home_sort']) && in_array($_COOKIE['home_sort'], ['na', 'nu'])) ? $_COOKIE['home_sort'] : 'na';

if ($isHomePage) {
    if (isset($_GET['sort'])) {
        $sort = $_GET['sort'];
        // GET selects the view only. The dropdown saves this browser's
        // preference on user interaction, not when following an external URL.

    } else {
        $sort = $savedHomeSort;
    }
} else {
    $sort = $_GET['sort'] ?? 'na';
}

$orderBy = "created_at DESC";
switch ($sort) {
    case 'oa': $orderBy = "created_at ASC"; break;   // oldest archived first
    case 'nu': $orderBy = "upload_date DESC"; break;  // newest by original upload date
    case 'ou': $orderBy = "upload_date ASC"; break;   // oldest by original upload date
}

// Main listing query: fetch one page of galleries.
// LIMIT and OFFSET are bound as PDO::PARAM_INT to prevent type-coercion issues with SQLite.
$stmt = $db->prepare("SELECT * FROM galleries WHERE $where ORDER BY $orderBy LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$galleries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Pagination HTML ---
// Renders a compact windowed page list (current ± 2) with first/last shortcuts
// and ellipsis gaps — a standard pattern for large result sets.
$paginationHtml = '';
if ($totalPages > 1) {
    $paginationHtml .= '<div class="pagination-container"><div class="pagination">';

    // Helper closure: preserves all existing GET params while replacing only 'page'.
    $getPageUrl = function($p) {
        $params = $_GET;
        $params['page'] = $p;
        return '?' . http_build_query($params);
    };

    if ($page > 1) {
        $paginationHtml .= '<a href="' . $getPageUrl($page - 1) . '" class="page-link">prev</a>';
    }

    // Window of page links centred on the current page (at most 5 links wide).
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    // If the window doesn't start at 1, show a link to page 1 and an ellipsis gap.
    if ($start > 1) {
        $paginationHtml .= '<a href="' . $getPageUrl(1) . '" class="page-link">1</a>';
        if ($start > 2) $paginationHtml .= '<span class="page-dots">…</span>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = ($i == $page) ? ' active' : '';
        $paginationHtml .= '<a href="' . $getPageUrl($i) . '" class="page-link' . $active . '">' . $i . '</a>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $paginationHtml .= '<span class="page-dots">…</span>';
        $paginationHtml .= '<a href="' . $getPageUrl($totalPages) . '" class="page-link">' . $totalPages . '</a>';
    }

    if ($page < $totalPages) {
        $paginationHtml .= '<a href="' . $getPageUrl($page + 1) . '" class="page-link">next</a>';
    }

    $paginationHtml .= '</div></div>';
}


$themeCss = getThemeVars();

?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title><?= $filterLabel !== '' ? htmlspecialchars($filterLabel) . ' - ' . htmlspecialchars($siteName) : htmlspecialchars($siteName) ?></title>
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
    <div class='topbar' id='main-topbar'>
        <h1 id='topbar-title'><a href='/'><?= htmlspecialchars($siteName) ?></a></h1>
        <div class='topbar-controls' id='topbar-controls'>
            <div class='search-container'>
                <form method='GET' action='/' id='search-form'>
                    <div class='search-wrapper'>
                        <input type='text' name='q' id='search-input' class='search-input' placeholder='<?= $canArchive ? "search or archive" : "search" ?>' value='<?= htmlspecialchars($q ?? '') ?>' autocomplete='off'>
                        <button type='submit' class='search-btn'>go</button>
                    </div>
                    <div id='autocomplete-results' class='autocomplete-results'></div>
                </form>
            </div>
            <a href='#footer' class='top-menu-link ui-text'>menu</a>
        </div>
    </div>
    <?php if ($filterLabel !== ''): ?>
        <h2><?= htmlspecialchars($filterLabel) ?></h2>
        <p class='ui-text'><a href='/' class='reset-search-link'>reset search</a> &nbsp;&nbsp;&nbsp;&nbsp; <a href='<?= htmlspecialchars($nhentaiUrl) ?>' target='_blank' rel='noreferrer' class='reset-search-link'>nhentai</a></p>
        <p class='search-entry-count ui-text'><?= (int)$totalItems ?> <?= (int)$totalItems === 1 ? 'entry' : 'entries' ?></p>
    <?php endif; ?>
    <div class='sort-container'>
        <form method='GET' action='/' id='sort-form'>
            <?php if ($q !== ''): ?>
                <input type='hidden' name='q' value='<?= htmlspecialchars($q) ?>'>
            <?php endif; ?>
            <?php if ($filterType !== ''): ?>
                <input type='hidden' name='<?= htmlspecialchars($filterType) ?>' value='<?= htmlspecialchars($filterValue) ?>'>
            <?php endif; ?>
            <label for='sort-select'>sort </label>
            <select name='sort' id='sort-select' data-remember='<?= $isHomePage ? "yes" : "no" ?>' onchange='if (this.dataset.remember === "yes" &amp;&amp; ["na", "nu"].includes(this.value)) document.cookie = "home_sort=" + this.value + "; Max-Age=31536000; Path=/; SameSite=Lax"; this.form.submit()' >
                <option value='na' <?= $sort === 'na' ? 'selected' : '' ?>>newest - archived</option>
                <option value='oa' <?= $sort === 'oa' ? 'selected' : '' ?>>oldest - archived</option>
                <option value='nu' <?= $sort === 'nu' ? 'selected' : '' ?>>newest - uploaded</option>
                <option value='ou' <?= $sort === 'ou' ? 'selected' : '' ?>>oldest - uploaded</option>
            </select>
        </form>
    </div>
    <div class='grid'>
    <?php foreach ($galleries as $g): ?>
        <?php
        $cover = htmlspecialchars('/' . $g['cover_path']);
        $titleHtml = htmlspecialchars($g['title']);
        $id = $g['id'];
        ?>
        <div class='item'>
            <a href='/g/<?= $id ?>'>
                <img src='<?= $cover ?>' alt='cover'>
                <div class='item-title'><?= $titleHtml ?></div>
            </a>
        </div>
    <?php endforeach; ?>
    </div>
    <?= $paginationHtml ?>
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
        
        let debounceTimer;
        
        // On mobile (≤600 px), expand the topbar when the search field is focused
        // so the input has more horizontal room.
        input.addEventListener('focus', function() {
            if (window.innerWidth <= 600) {
                topbar.classList.add('search-active');
            }
        });
        
        // Delay hiding the dropdown 200 ms to allow mousedown on an item to fire first;
        // otherwise the blur event would close the list before the click registers.
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
            // Autocomplete operates on the last space-delimited word so users can
            // incrementally build multi-word searches (e.g. "big breasts el<suggest>").
            const parts = val.split(' ');
            const lastPart = parts[parts.length - 1];
            
            // Don't fire the API until at least 2 characters are typed (matches the server-side guard).
            if (lastPart.length < 2) {
                resultsBox.style.display = 'none';
                return;
            }
            
            // Debounce: wait 200 ms of inactivity before making the fetch call.
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
                                // preventDefault stops the input's blur from firing before
                                // we can replace the last word with the chosen suggestion.
                                e.preventDefault();
                                // Strip the "category - " prefix ("tag - big breasts" → "big breasts").
                                parts[parts.length - 1] = item.split(' - ')[1];
                                // Append a trailing space so the user can continue typing a new word.
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
