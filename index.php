<?php
// Bootstrap the SQLite database connection using a file-based cache in the project root.
// PDO is used for its unified API and prepared-statement support across DB drivers.
$db = new PDO('sqlite:' . __DIR__ . '/cache.sqlite');
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

// --- Authentication setup ---
// Credentials live in config/credentials, one KEY=VALUE pair per line.
// Expected format:
//   USER=<username>
//   PASS=<password hash>
// If the file is absent, $validUser stays empty and auth is effectively disabled.
$credsFile = __DIR__ . '/config/credentials';
$validUser = '';
$validHash = '';
if (file_exists($credsFile)) {
    // FILE_IGNORE_NEW_LINES strips trailing \r\n; FILE_SKIP_EMPTY_LINES skips blank lines.
    $lines = file($credsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // strpos(...) === 0 confirms the line starts with the prefix (avoids partial matches).
        if (strpos($line, 'USER=') === 0) $validUser = substr($line, 5);
        if (strpos($line, 'PASS=') === 0) $validHash = substr($line, 5);
    }
}
$isLoggedIn = false;
if ($validUser !== '') {
    // Cookie-based auth: the browser holds an md5 token derived from credentials + a fixed salt.
    // md5 is intentionally lightweight here — this is a local/private archive, not a public service.
    // The salt 'localnh_secret_salt' prevents trivial rainbow-table attacks on the stored cookie value.
    $authSecret = md5($validUser . $validHash . 'localnh_secret_salt');
    if (isset($_COOKIE['remote_auth']) && $_COOKIE['remote_auth'] === $authSecret) {
        $isLoggedIn = true;
    }
}

// --- Favicon config ---
// config/favicon may contain a relative path (e.g. "img/icon.png") or an absolute/external URL.
// Relative paths are normalised to root-relative so they work correctly from any sub-path.
$faviconFile = __DIR__ . '/config/favicon';
$faviconHtml = '';
if (file_exists($faviconFile)) {
    $faviconPath = trim(file_get_contents($faviconFile));
    if ($faviconPath !== '') {
        // Prepend '/' only when the path is not already absolute (starts with '/') or a full URL.
        if (strpos($faviconPath, 'http') !== 0 && strpos($faviconPath, '/') !== 0) {
            $faviconPath = '/' . $faviconPath;
        }
        $faviconHtml = "<link rel='icon' href='" . htmlspecialchars($faviconPath) . "'>";
    }
}

// --- Public archive toggle ---
// config/public contains the literal string 'on' to allow unauthenticated users to trigger
// the fetch/archive workflow. When the file is absent or holds any other value, archiving is login-gated.
$paFile = __DIR__ . '/config/public';
$publicArchiveEnabled = (file_exists($paFile) && trim(file_get_contents($paFile)) === 'on');
// $canArchive is true for logged-in users OR when public archiving is enabled site-wide.
$canArchive = ($isLoggedIn || $publicArchiveEnabled);

/*
 * getDominantColor()
 *
 * Samples a representative pixel from an image by downscaling it to 1×1 via
 * imagecopyresampled(), which effectively computes a weighted-average colour
 * without iterating every pixel — fast even for large images.
 *
 * The hue is then re-mapped to an HSL value whose lightness targets the
 * current theme (dark ≈ 12 %, light ≈ 88 %) while retaining the original hue.
 *
 * @param string $filePath   Relative path from __DIR__ to the image file.
 * @param bool   $isDarkMode When true, targets a very dark background (L≈0.12);
 *                           when false, a very light background (L≈0.88).
 * @return string HSL CSS string (e.g. "hsl(220, 40%, 12%)") or a safe fallback hex.
 */
function getDominantColor($filePath, $isDarkMode = true) {
    $fullPath = __DIR__ . '/' . ltrim($filePath, '/');
    // Return a plain dark/light fallback when the file is missing or unreadable.
    $defaultBg = $isDarkMode ? '#111' : '#fff';
    if (!file_exists($fullPath)) return $defaultBg;
    // @ suppresses warnings for corrupt/truncated files; $img stays false on failure.
    $mime = @mime_content_type($fullPath);
    $img = false;
    if ($mime === 'image/webp') $img = @imagecreatefromwebp($fullPath);
    elseif ($mime === 'image/jpeg') $img = @imagecreatefromjpeg($fullPath);
    elseif ($mime === 'image/png') $img = @imagecreatefrompng($fullPath);
    if (!$img) return $defaultBg;
    
    // Downsample the entire image to a single pixel; the colour is the visual average.
    $tmp = imagecreatetruecolor(1, 1);
    imagecopyresampled($tmp, $img, 0, 0, 0, 0, 1, 1, imagesx($img), imagesy($img));
    // imagecolorat returns a packed 32-bit integer: 0x00RRGGBB.
    $rgb = imagecolorat($tmp, 0, 0);
    imagedestroy($tmp);
    imagedestroy($img);
    
    // Unpack each channel via bit-shifting and normalise to [0.0, 1.0] for HSL maths.
    $r = (($rgb >> 16) & 0xFF) / 255;
    $g = (($rgb >> 8) & 0xFF) / 255;
    $b = ($rgb & 0xFF) / 255;
    
    // Standard RGB → HSL conversion.
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $h = 0; $s = 0; $l = ($max + $min) / 2;
    if ($max != $min) {
        $d = $max - $min;
        // Saturation formula differs based on whether lightness is above or below 0.5.
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max == $r) $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
        elseif ($max == $g) $h = ($b - $r) / $d + 2;
        else $h = ($r - $g) / $d + 4;
        $h /= 6; // Normalise hue to [0.0, 1.0].
    }
    // Override lightness to match the theme; keep the source image's hue.
    // A minimum saturation of 0.20 prevents washed-out greys from producing dull backgrounds.
    if ($isDarkMode) {
        $targetL = 0.12;
        $targetS = max($s, 0.20);
    } else {
        $targetL = 0.88;
        $targetS = max($s, 0.20);
    }
    return 'hsl(' . round($h * 360) . ', ' . round($targetS * 100) . '%, ' . round($targetL * 100) . '%)';
}

// getSiteName(): reads the display name from config/title; falls back to 'localnh'.
function getSiteName() {
    $path = __DIR__ . '/config/title';
    if (file_exists($path)) {
        $content = trim(file_get_contents($path));
        if ($content !== '') return $content;
    }
    return 'localnh';
}
$siteName = getSiteName();

/*
 * getFooterHtml()
 * Reads config/footer, applies a minimal Markdown link conversion
 * ([text](url) → <a href="url">text</a>), then nl2br for line breaks.
 * Only link syntax is processed — other Markdown is left as plain text.
 */
function getFooterHtml() {
    $path = __DIR__ . '/config/footer';
    if (file_exists($path)) {
        $md = trim(file_get_contents($path));
        if ($md !== '') {
            $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $md);
            return nl2br($html);
        }
    }
    return '';
}
$footerHtml = getFooterHtml();

/*
 * getThemeVars()
 * Generates CSS custom-property declarations driven by two cookies:
 *   - 'dark_mode'   : 'on' (default) or 'off'
 *   - 'blur_covers' : 'on' only honoured for authenticated users (privacy feature)
 *
 * An optional $bgColor override lets gallery pages inject a dominant-colour
 * background without re-computing the full palette here.
 * Returns a string of CSS variable assignments for insertion inside :root { }.
 */
function getThemeVars($bgColor = null) {
    global $isLoggedIn;
    // Default dark mode to 'on' when the cookie is absent (first-time visitors).
    $darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on') === 'on';
    // Cover blur is a privacy/NSFW-safety feature — only active for authenticated users.
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

// --- Front-controller routing ---
// index.php acts as a single entry point via .htaccess / nginx rewrite rules.
// parse_url strips the query string before regex matching so parameters don't leak into path checks.
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Route: /g/<id> — individual gallery detail page.
if (preg_match('#^/g/(\d+)/?$#', $path, $matches)) {
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
} elseif ($path === '/api/favorites' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // $_POST is not populated for application/json requests; read the raw body manually.
    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true);
    $ids = $inputData['ids'] ?? [];
    
    // Return an empty object (not an array) so JS can treat it as a keyed map: {}.
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['galleries' => new stdClass()]);
        exit;
    }
    
    // Sanitise each ID: cast to string and strip whitespace to prevent passing
    // nulls or nested arrays into the SQL query.
    $cleanIds = [];
    foreach ($ids as $id) {
        $idStr = trim((string)$id);
        if ($idStr !== '') {
            $cleanIds[] = $idStr;
        }
    }
    
    if (empty($cleanIds)) {
        echo json_encode(['galleries' => new stdClass()]);
        exit;
    }
    
    // Build a parameterised IN clause: one '?' placeholder per ID to prevent SQL injection.
    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    $stmt = $db->prepare("SELECT id, title, title_jp, cover_path FROM galleries WHERE id IN ($placeholders)");
    $stmt->execute($cleanIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Key results by gallery ID for O(1) lookups on the client side.
    $results = [];
    foreach ($rows as $r) {
        $results[$r['id']] = [
            'id' => $r['id'],
            // Prefer the English title; fall back to the Japanese title, then empty string.
            'title' => $r['title'] ?: $r['title_jp'] ?: '',
            'cover_path' => '/' . $r['cover_path'],
            'exists' => true
        ];
    }
    
    // Cast to stdClass so json_encode produces {} for an empty set, not [].
    echo json_encode(['galleries' => (object)$results]);
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
    header('Content-Type: application/json');
    $q = strtolower(trim($_GET['q'] ?? ''));
    // Enforce a 2-character minimum to avoid a full table scan on every keystroke.
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    
    // Full table scan: tags are stored as JSON strings, so a simple LIKE search is used.
    // For small local archives this is acceptable; a dedicated index table would help at scale.
    $stmt = $db->query("SELECT language, tags FROM galleries");
    $allTags = [];
    $allArtists = [];
    $allCircles = [];
    $allParodies = [];
    $allChars = [];
    $allLangs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Deduplicate language values collected from the top-level column.
        if ($row['language'] && !in_array($row['language'], $allLangs)) $allLangs[] = $row['language'];
        // Decode the JSON tags blob and fan out each category into its own deduplicated array.
        $t = json_decode($row['tags'], true);
        if ($t) {
            if (!empty($t['tags'])) foreach ($t['tags'] as $x) if (!in_array($x, $allTags)) $allTags[] = $x;
            if (!empty($t['artists'])) foreach ($t['artists'] as $x) if (!in_array($x, $allArtists)) $allArtists[] = $x;
            if (!empty($t['circles'])) foreach ($t['circles'] as $x) if (!in_array($x, $allCircles)) $allCircles[] = $x;
            if (!empty($t['parodies'])) foreach ($t['parodies'] as $x) if (!in_array($x, $allParodies)) $allParodies[] = $x;
            if (!empty($t['characters'])) foreach ($t['characters'] as $x) if (!in_array($x, $allChars)) $allChars[] = $x;
        }
    }
    
    // Prefix each suggestion with its category so the client can display grouped results.
    $results = [];
    foreach ($allTags as $x) if (strpos(strtolower($x), $q) !== false) $results[] = "tag - " . $x;
    foreach ($allArtists as $x) if (strpos(strtolower($x), $q) !== false) $results[] = "artist - " . $x;
    foreach ($allCircles as $x) if (strpos(strtolower($x), $q) !== false) $results[] = "circle - " . $x;
    foreach ($allParodies as $x) if (strpos(strtolower($x), $q) !== false) $results[] = "parody - " . $x;
    foreach ($allChars as $x) if (strpos(strtolower($x), $q) !== false) $results[] = "character - " . $x;
    foreach ($allLangs as $x) if (strpos(strtolower($x), $q) !== false) $results[] = "language - " . $x;
    
    // Cap at 10 to keep the dropdown manageable.
    echo json_encode(array_slice($results, 0, 10));
    exit;
// Static file serving: map URL paths to physical files under __DIR__.
// This lets the built-in PHP dev server (or any rewrite-all setup) serve CSS/JS/images.
} elseif ($path !== '/' && $path !== '/index.php') {
    $filePath = __DIR__ . $path;
    if (file_exists($filePath) && is_file($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        // Explicit MIME map is preferred over mime_content_type() for consistent charset headers.
        $mimeMap = [
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'webp' => 'image/webp',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'json' => 'application/json; charset=utf-8',
            'ico'  => 'image/x-icon',
        ];
        // Fall back to mime_content_type() for unlisted extensions, then a generic binary type.
        $mime = $mimeMap[$ext] ?? (mime_content_type($filePath) ?: 'application/octet-stream');
        header("Content-Type: $mime");
        readfile($filePath);
        exit;
    }
    // No matching file or directory — return a plain 404 response.
    http_response_code(404);
    echo "Not Found";
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

// Sort order driven by the 'sort' GET parameter.
// 'na' (newest archived / default) shows the most recently downloaded gallery first.
$sort = $_GET['sort'] ?? 'na';
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
            <select name='sort' id='sort-select' onchange='document.getElementById("sort-form").submit()'>
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
