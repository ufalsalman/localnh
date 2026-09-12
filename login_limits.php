<?php
require_once __DIR__ . '/storage.php';

// Shared across FPM workers and restarts, separate from sessions and snapshots.
// Only the immediate peer is used: forwarding headers are not client identity.
function localnh_login_peer(string $peer): string {
    $packed = @inet_pton($peer);
    if ($packed === false) return 'unknown';
    if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $packed = substr($packed, 12);
    return hash('sha256', $packed);
}

function localnh_login_limits_db(): PDO {
    $mask = umask(0077);
    try {
        $db = new PDO('sqlite:' . localnh_session_dir() . '/login-limits.sqlite');
    } finally { umask($mask); }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 1000');
    $db->exec('CREATE TABLE IF NOT EXISTS limits (bucket TEXT PRIMARY KEY, started INTEGER NOT NULL, attempts INTEGER NOT NULL)');
    return $db;
}

// Reserve before password hashing. Denials do not extend the 60-second window.
// Global budget counts all admissions; successful logins refund their peer slot.
function localnh_login_reserve(PDO $db, string $peer, int $now): array {
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('DELETE FROM limits WHERE started <= ? OR started > ?')->execute([$now - 60, $now]);
        $buckets = ['global' => 60, 'peer:' . $peer => 5];
        $rows = [];
        $retry = 0;
        foreach ($buckets as $key => $limit) {
            $stmt = $db->prepare('SELECT started, attempts FROM limits WHERE bucket = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $rows[$key] = $row;
            if ($row && (int)$row['attempts'] >= $limit) $retry = max($retry, (int)$row['started'] + 60 - $now);
        }
        if (!$retry) {
            foreach ($buckets as $key => $limit) {
                $db->prepare('INSERT INTO limits VALUES (?, ?, 1) ON CONFLICT(bucket) DO UPDATE SET attempts = attempts + 1')->execute([$key, $now]);
            }
        }
        $db->exec('COMMIT');
        return ['retry' => $retry, 'peer' => 'peer:' . $peer, 'started' => $rows['peer:' . $peer]['started'] ?? $now];
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

function localnh_login_refund(PDO $db, array $reservation): void {
    // An old success must never refund a new window's reservation.
    $db->prepare('UPDATE limits SET attempts = MAX(0, attempts - 1) WHERE bucket = ? AND started = ?')->execute([$reservation['peer'], $reservation['started']]);
}

// Host-only recovery; never expose a reset action through HTTP.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (($argv[1] ?? '') !== 'reset' || count($argv) !== 2) {
        fwrite(STDERR, "Usage: php login_limits.php reset\n");
        exit(2);
    }
    localnh_login_limits_db()->exec('DELETE FROM limits');
    echo "Login limits reset; credentials and existing sessions unchanged.\n";
}
