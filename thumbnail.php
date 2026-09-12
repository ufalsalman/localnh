<?php
/**
 * On-demand WebP thumbnails for compact gallery grids.
 *
 * Derivatives use a bounded private ephemeral cache, outside collection data.
 */

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$galleryId = $_GET['id'] ?? '';
$pageRaw = $_GET['page'] ?? '0';
$widthRaw = $_GET['w'] ?? '320';
$mode = $_GET['mode'] ?? 'cover';

$allowedWidths = ['320', '640'];
$allowedModes = ['cover', 'fit'];

// Strict input validation bounds resource use and keeps thumbnail URLs canonical.
if (
    !is_string($galleryId) ||
    !is_string($pageRaw) ||
    !is_string($widthRaw) ||
    !is_string($mode) ||
    !preg_match('/^\d+$/D', $galleryId) ||
    !preg_match('/^\d+$/D', $pageRaw) ||
    !in_array($widthRaw, $allowedWidths, true) ||
    !in_array($mode, $allowedModes, true)
) {
    header('Cache-Control: no-store');
    http_response_code(400);
    exit;
}

$page = (int)$pageRaw;
$width = (int)$widthRaw;

// Filesystem paths always come from SQLite, never from request parameters.
$stmt = $db->prepare('SELECT cover_path, pages_paths FROM galleries WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $galleryId]);
$gallery = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gallery) {
    header('Cache-Control: no-store');
    http_response_code(404);
    exit;
}

if ($page === 0) {
    $storedPath = $gallery['cover_path'] ?? '';
} else {
    // Page 0 is the cover; positive page numbers follow the reader's 1-based URLs.
    $pagePaths = json_decode($gallery['pages_paths'] ?? '[]', true);
    $storedPath = is_array($pagePaths) ? ($pagePaths[$page - 1] ?? '') : '';
}

$cacheRoot = realpath(localnh_data_path('cache/images'));
$sourcePath = is_string($storedPath) && $storedPath !== ''
    ? localnh_image_path($storedPath)
    : false;

// A malformed database row or symlink must not expose files outside cache/images.
if (
    $cacheRoot === false ||
    $sourcePath === false ||
    !str_starts_with($sourcePath, $cacheRoot . DIRECTORY_SEPARATOR) ||
    !is_file($sourcePath)
) {
    header('Cache-Control: no-store');
    http_response_code(404);
    exit;
}

$vipsBinary = '/usr/bin/vips';
if (!is_executable($vipsBinary)) {
    error_log('Thumbnail generation failed: /usr/bin/vips is unavailable');
    header('Cache-Control: no-store');
    http_response_code(503);
    exit;
}

require_once __DIR__ . '/resource_jobs.php';
if (filesize($sourcePath) > 33554432) localnh_job_error(413, 'Thumbnail source exceeds 32 MiB.');
// Content fingerprint also invalidates same-size edits within one timestamp tick.
$fingerprint = hash_file('sha256', $sourcePath);
if ($fingerprint === false) localnh_job_error(503, 'Thumbnail source unavailable.');
$key = hash('sha256', 'thumb-v2:' . $sourcePath . ':' . $fingerprint . ':' . $width . ':' . $mode);
$etag = '"' . $key . '"';
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=86400, must-revalidate');
if (in_array($etag, array_map('trim', explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '')), true)) {
    http_response_code(304); exit;
}
// A cold HEAD never starts a converter; Content-Length may be omitted for HEAD.
if ($requestMethod === 'HEAD') {
    header('Content-Type: image/webp'); header('X-Content-Type-Options: nosniff'); exit;
}

$command = [
    $vipsBinary,
    'thumbnail_source',
    '[descriptor=0]',
    // "strip" is supported by both the Debian libvips 8.14 container and
    // newer releases, and removes metadata that compact thumbnails do not need.
    '.webp[Q=78,strip=true]',
    (string)$width,
];

if ($mode === 'cover') {
    $command[] = '--height';
    $command[] = (string)$width;
    $command[] = '--crop';
    $command[] = 'centre';
}
$command[] = '--size';
$command[] = 'down';

$slot = $keyLock = $metadata = false;
$tmp = null; $output = false;
try {
    $root = localnh_job_root();
    $cached = "$root/$key.webp";
    $metadata = localnh_job_lock('cache');
    if (!$metadata) localnh_job_error(503, 'Thumbnail cache busy. Please retry.');
    if (is_file($cached) && !is_link($cached)) $output = file_get_contents($cached);
    fclose($metadata); $metadata = false;
    if ($output === false) {
        // Fixed striped locks bound lock-file count and suppress duplicate misses.
        $keyLock = localnh_job_lock('key-' . (hexdec(substr($key, 0, 2)) % 16));
        if (!$keyLock) localnh_job_error(503, 'Thumbnail is being generated. Please retry.');
        // A producer may have published between our first read and key admission.
        $metadata = localnh_job_lock('cache');
        if (!$metadata) throw new RuntimeException('Cache lookup busy');
        if (is_file($cached) && !is_link($cached)) $output = file_get_contents($cached);
        fclose($metadata); $metadata = false;
        if ($output === false) {
            $slot = localnh_job_lock('thumbnail', 2);
            if (!$slot) localnh_job_error(503, 'Thumbnail workers busy. Please retry.');
            $tmp = substr(stream_get_meta_data($slot)['uri'], 0, -5) . '.part';
            if (is_link($tmp)) throw new RuntimeException('Linked thumbnail workspace');
            if (is_file($tmp)) unlink($tmp);
            ignore_user_abort(true); set_time_limit(0);
            if (!localnh_job_run($command, $tmp, true, $sourcePath, [$slot, $keyLock])) throw new RuntimeException('Thumbnail conversion exceeded limits or failed');
            if (hash_file('sha256', $sourcePath) !== $fingerprint) throw new RuntimeException('Source changed during conversion');
            $metadata = localnh_job_lock('cache');
            if (!$metadata) throw new RuntimeException('Cache publication busy');
            localnh_thumbnail_prune($root, filesize($tmp));
            if (!rename($tmp, $cached)) throw new RuntimeException('Cache publication failed');
            $output = file_get_contents($cached);
        }
    }
} catch (Throwable $e) {
    error_log('Thumbnail job failed: ' . $e->getMessage());
} finally {
    if ($tmp !== null && is_file($tmp)) unlink($tmp);
    foreach ([$metadata, $slot, $keyLock] as $handle) if (is_resource($handle)) fclose($handle);
}
if ($output === false || $output === '') localnh_job_error(503, 'Thumbnail unavailable. Please retry.');
header('Content-Type: image/webp');
header('Content-Length: ' . strlen($output));
header('X-Content-Type-Options: nosniff');
echo $output;
exit;
