<?php
// Shared filesystem contract; keep behavior aligned with script/py/storage.py.
// URLs/DB records keep cache/images/... regardless of the selected disk root.
function localnh_canonical_path(string $path): string {
    $tail = [];
    while (!file_exists($path) && !is_link($path)) {
        $parent = dirname($path);
        if ($parent === $path) throw new RuntimeException('Invalid storage path');
        array_unshift($tail, basename($path));
        $path = $parent;
    }
    $resolved = realpath($path);
    if ($resolved === false) throw new RuntimeException('Invalid storage path');
    return rtrim($resolved, '/') . ($tail ? '/' . implode('/', $tail) : '');
}

function localnh_data_root(): string {
    static $root = null;
    if ($root !== null) return $root;
    $configured = getenv('LOCALNH_DATA_DIR') ?: '';
    if ($configured === '') return $root = __DIR__;
    if ($configured[0] !== '/' || preg_match('#(?:^|/)\.\.?(/|$)|[\\\\\x00-\x1f]#', $configured)) throw new RuntimeException('Storage root must be an absolute path');
    $candidate = localnh_canonical_path($configured);
    if ($candidate === __DIR__ || str_starts_with($candidate, __DIR__ . '/') || str_starts_with(__DIR__, $candidate . '/')) throw new RuntimeException('Storage root must be outside source');
    if (!is_dir($candidate) || !is_readable($candidate) || !is_writable($candidate)) throw new RuntimeException('Storage root unavailable');
    $marker = $candidate . '/.localnh-storage.json';
    if (is_link($marker)) throw new RuntimeException('Invalid storage marker');
    $state = is_file($marker) ? json_decode(file_get_contents($marker), true) : null;
    if (($state['version'] ?? null) !== 1 || !is_file($candidate . '/cache.sqlite') || is_link($candidate . '/cache.sqlite')) throw new RuntimeException('Storage not initialized; use the explicit storage init or migration workflow');
    foreach (['config', 'cache', 'cache/images', 'snapshot'] as $relative) {
        $current = $candidate;
        foreach (explode('/', $relative) as $part) {
            $current .= '/' . $part;
            if (is_link($current) || !is_dir($current)) throw new RuntimeException('Storage directory unavailable or linked');
        }
    }
    return $root = $candidate;
}

function localnh_data_path(string $relative): string {
    if ($relative === '' || preg_match('#(^/|\\\\|[\x00-\x1f%]|(?:^|/)\.|//)#', $relative)) throw new RuntimeException('Invalid data path');
    $path = localnh_data_root();
    foreach (explode('/', $relative) as $segment) {
        $path .= '/' . $segment;
        if (is_link($path)) throw new RuntimeException('Data symlinks are not supported');
    }
    return $path;
}

function localnh_image_path(mixed $logical): string|false {
    if (!is_string($logical) || !str_starts_with($logical, 'cache/images/')) return false;
    try { return localnh_data_path($logical); }
    catch (RuntimeException $e) { return false; }
}

function localnh_session_dir(): string {
    $path = getenv('LOCALNH_SESSION_DIR') ?: localnh_data_path('sessions');
    if ($path[0] !== '/' || preg_match('#(?:^|/)\.\.?(/|$)|[\\\\\x00-\x1f]#', $path)) throw new RuntimeException('Session root must be absolute');
    $path = localnh_canonical_path($path);
    if ($path === __DIR__ || str_starts_with(__DIR__, $path . '/')) throw new RuntimeException('Unsafe session root');
    if ((getenv('LOCALNH_DATA_DIR') ?: '') !== '' && ($path === __DIR__ || str_starts_with($path, __DIR__ . '/'))) throw new RuntimeException('Sessions must be outside source');
    return $path;
}

try { localnh_data_root(); }
catch (Throwable $e) {
    http_response_code(503);
    header('Cache-Control: no-store');
    exit('Storage unavailable. Check the configured data directory and initialization.');
}

// Cache invalidation is best-effort: it must not turn a committed mutation into
// an apparent failure. Aggregate TTL remains a fallback if the disk is full.
function localnh_collection_changed(): void {
    $tmp = false;
    try {
        $tmp = tempnam(localnh_data_path('cache'), '.aggregation-');
        if ($tmp === false || file_put_contents($tmp, bin2hex(random_bytes(16))) === false
            || !rename($tmp, localnh_data_path('cache/aggregation.version'))) throw new RuntimeException('Revision unavailable');
    } catch (Throwable $e) { error_log('Aggregate invalidation unavailable'); }
    finally { if ($tmp !== false && is_file($tmp)) unlink($tmp); }
}
