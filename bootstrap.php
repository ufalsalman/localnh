<?php
// Shared authentication state. Presentation helpers stay in their existing files.
const LOCALNH_SESSION_TTL = 86400 * 30;

function localnh_auth_unavailable(): never {
    http_response_code(503);
    header('Cache-Control: no-store');
    exit('Authentication storage unavailable. Please try again later.');
}

function localnh_credentials(): ?array {
    $path = __DIR__ . '/config/credentials';
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

function localnh_login(string $user, string $password): bool {
    global $authDb, $authCredentials;
    if ($authCredentials === null || $user !== $authCredentials['user']
        || !password_verify($password, $authCredentials['hash'])) return false;
    try {
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
    $sessionDir = getenv('LOCALNH_SESSION_DIR') ?: __DIR__ . '/sessions';
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
