<?php
require_once __DIR__ . "/storage.php";
require_once __DIR__ . '/login_limits.php';
// Shared authentication state; presentation helpers live in presentation.php.
const LOCALNH_SESSION_TTL = 86400 * 30;

function localnh_auth_unavailable(): never {
    http_response_code(503);
    header('Cache-Control: no-store');
    exit('Authentication storage unavailable. Please try again later.');
}

function localnh_credentials(): ?array {
    $path = localnh_data_path('config/credentials');
    clearstatcache(true, $path);
    $text = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
    if ($text === false) return null;
    $fields = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (str_starts_with($line, 'USER=') || str_starts_with($line, 'PASS=')) {
            [$key, $value] = explode('=', $line, 2);
            if (isset($fields[$key])) return null;
            $fields[$key] = $value;
        }
    }
    if (($fields['USER'] ?? '') === '' || ($fields['PASS'] ?? '') === '') return null;
    if (password_get_info($fields['PASS'])['algoName'] === 'unknown') return null;
    return ['user' => $fields['USER'], 'hash' => $fields['PASS'],
            'fingerprint' => hash('sha256', $text)];
}

function localnh_request_is_https(): bool {
    // HTTPS is set by the web server for direct TLS, never from a Host header.
    // This also covers a host tunnel using a verified HTTPS origin: no proxy
    // IP trust is needed. HTTP origins require the explicit peer checks below.
    // Deployment recipes and NAT limitations are documented in DEPLOYMENT.md.
    if (in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true)) return true;
    $peer = $_SERVER['REMOTE_ADDR'] ?? '';
    // Caddy captures the original header before its FastCGI proxy rewrites it.
    $forwarded = $_SERVER['LOCALNH_FORWARDED_PROTO']
        ?? ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if (!is_string($peer) || !filter_var($peer, FILTER_VALIDATE_IP)
        || !is_string($forwarded) || strtolower(trim($forwarded)) !== 'https') return false;
    // Trust only explicitly configured immediate proxy IPs, not X-Forwarded-For,
    // arbitrary private networks, hostnames, or comma-separated scheme chains.
    foreach (explode(',', getenv('LOCALNH_TRUSTED_PROXIES') ?: '') as $trusted) {
        $trusted = trim($trusted);
        if (filter_var($trusted, FILTER_VALIDATE_IP) && inet_pton($trusted) === inet_pton($peer)) {
            return true;
        }
    }
    return false;
}

function localnh_cookie_options(int $expires): array {
    return ['expires' => $expires, 'path' => '/', 'httponly' => true,
            'secure' => localnh_request_is_https(), 'samesite' => 'Lax'];
}

function localnh_cookie_token(): string {
    $token = $_COOKIE['remote_auth'] ?? '';
    return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) ? $token : '';
}

// Authenticated mutations use a separate, one-way token derived from the
// current session secret. It reveals neither the login cookie nor its DB hash.
// No schema migration is needed; a new login invalidates the previous token.
// Anonymous forms use a signed nonce in an HttpOnly, host-only cookie. The
// signing key stays in private session storage; a planted cookie alone cannot
// produce a valid form token. HTTPS uses the browser-enforced __Host- prefix.
function localnh_form_cookie_name(): string {
    return localnh_request_is_https() ? '__Host-localnh_form' : 'localnh_form';
}

function localnh_form_nonce(): string {
    $nonce = $_COOKIE[localnh_form_cookie_name()] ?? '';
    if (!is_string($nonce) || !preg_match('/\A([0-9]{10})\.[a-f0-9]{64}\z/', $nonce, $m)) return '';
    return (int)$m[1] <= time() && (int)$m[1] > time() - 7200 ? $nonce : '';
}

function localnh_csrf_token(bool $create = true): string {
    global $isLoggedIn, $authDb;
    if ($isLoggedIn) return hash_hmac('sha256', 'localnh-csrf-v1', localnh_cookie_token());
    $nonce = localnh_form_nonce();
    if ($nonce === '' && !$create) return '';
    if ($nonce === '') {
        $nonce = time() . '.' . bin2hex(random_bytes(32));
        setcookie(localnh_form_cookie_name(), $nonce, localnh_cookie_options(time() + 7200));
        $_COOKIE[localnh_form_cookie_name()] = $nonce;
    }
    try {
        $authDb->exec('CREATE TABLE IF NOT EXISTS csrf_key (id INTEGER PRIMARY KEY CHECK (id = 1), secret TEXT NOT NULL)');
        $stmt = $authDb->prepare('INSERT OR IGNORE INTO csrf_key (id, secret) VALUES (1, ?)');
        $stmt->execute([bin2hex(random_bytes(32))]);
        $key = $authDb->query('SELECT secret FROM csrf_key WHERE id = 1')->fetchColumn();
        return hash_hmac('sha256', 'anonymous-form-v1|' . $nonce, $key);
    } catch (Throwable $e) {
        localnh_auth_unavailable();
    }
}

function localnh_require_post(bool $admin = false): void {
    global $isLoggedIn;
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('This action requires POST.');
    }
    if ($admin && !$isLoggedIn) {
        http_response_code(403);
        exit('Login required.');
    }
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = localnh_csrf_token(false);
    if ($expected === '' || !is_string($submitted) || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('Invalid CSRF token. Reload the page and try again.');
    }
}

function localnh_require_admin_post(): void {
    localnh_require_post(true);
}

function localnh_login(string $user, string $password): bool {
    global $authDb, $authCredentials;
    try {
        $limits = localnh_login_limits_db();
        $reservation = localnh_login_reserve($limits, localnh_login_peer($_SERVER['REMOTE_ADDR'] ?? ''), time());
        if ($reservation['retry'] > 0) {
            http_response_code(429);
            header('Cache-Control: no-store');
            header('Retry-After: ' . $reservation['retry']);
            exit('Too many login attempts. Try again in ' . $reservation['retry'] . ' seconds.');
        }
        if ($authCredentials === null || $user !== $authCredentials['user']
            || !password_verify($password, $authCredentials['hash'])) return false;
        localnh_login_refund($limits, $reservation);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $authDb->beginTransaction();
        // A new login rotates the current browser's session only.
        $delete = $authDb->prepare('DELETE FROM sessions WHERE token_hash = ? OR expires_at <= ?');
        $delete->execute([hash('sha256', localnh_cookie_token()), $now]);
        $stmt = $authDb->prepare('INSERT INTO sessions (token_hash, created_at, expires_at, fingerprint) VALUES (?, ?, ?, ?)');
        $stmt->execute([hash('sha256', $token), $now, $now + LOCALNH_SESSION_TTL, $authCredentials['fingerprint']]);
        $authDb->commit();
        setcookie('remote_auth', $token, localnh_cookie_options($now + LOCALNH_SESSION_TTL));
        // Rotate the pre-login context; old anonymous forms need a reload.
        setcookie(localnh_form_cookie_name(), '', localnh_cookie_options(time() - 3600));
        return true;
    } catch (Throwable $e) {
        if ($authDb->inTransaction()) $authDb->rollBack();
        localnh_auth_unavailable();
    }
}

function localnh_logout(): void {
    global $authDb;
    try {
        $stmt = $authDb->prepare('DELETE FROM sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', localnh_cookie_token())]);
        setcookie('remote_auth', '', localnh_cookie_options(time() - 3600));
    } catch (Throwable $e) {
        localnh_auth_unavailable();
    }
}

$authCredentials = localnh_credentials();
$isLoggedIn = false;
try {
    $sessionDir = localnh_session_dir();
    $oldMask = umask(0077);
    try {
        if (!is_dir($sessionDir) && !@mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
            throw new RuntimeException('Session directory unavailable');
        }
        $authDb = new PDO('sqlite:' . $sessionDir . '/sessions.sqlite');
    } finally {
        umask($oldMask);
    }
    $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $authDb->exec('PRAGMA busy_timeout = 5000');
    $authDb->exec('CREATE TABLE IF NOT EXISTS sessions (
        token_hash TEXT PRIMARY KEY, created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL, fingerprint TEXT NOT NULL)');
    $authDb->exec('CREATE TABLE IF NOT EXISTS auth_state (id INTEGER PRIMARY KEY CHECK (id = 1), fingerprint TEXT NOT NULL)');
    // Persist the observed credential state: an observed change/removal revokes
    // all sessions permanently, even if an older credential file is restored.
    $authDb->beginTransaction();
    $fingerprint = $authCredentials['fingerprint'] ?? '';
    $previous = $authDb->query('SELECT fingerprint FROM auth_state WHERE id = 1')->fetchColumn();
    if ($previous !== $fingerprint) {
        $authDb->exec('DELETE FROM sessions');
        $stmt = $authDb->prepare('INSERT OR REPLACE INTO auth_state (id, fingerprint) VALUES (1, ?)');
        $stmt->execute([$fingerprint]);
    }
    $token = localnh_cookie_token();
    if ($authCredentials !== null && $token !== '') {
        $stmt = $authDb->prepare('SELECT expires_at, fingerprint FROM sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isLoggedIn = $row && (int)$row['expires_at'] > time()
            && hash_equals($fingerprint, $row['fingerprint']);
    }
    $authDb->commit();
} catch (Throwable $e) {
    if (isset($authDb) && $authDb->inTransaction()) $authDb->rollBack();
    localnh_auth_unavailable();
}

// Session-specific forms must not be reused by a shared HTTP cache.
if ($isLoggedIn) header('Cache-Control: private, no-store');
