<?php
require_once __DIR__ . '/resource_jobs.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    localnh_job_error(405, 'Use GET or HEAD.');
}
$format = $_GET['download'];
$types = ['zip' => 'application/zip', 'cbz' => 'application/vnd.comicbook+zip', 'pdf' => 'application/pdf', 'html' => 'text/html; charset=UTF-8'];
if (!is_string($format) || !isset($types[$format])) localnh_job_error(400, 'Unsupported export format.');
require_once __DIR__ . '/collection_reads.php';
$imagesLock = localnh_collection_read_guard();
$stmt = $db->prepare('SELECT title, pages_paths FROM galleries WHERE id = ?');
$stmt->execute([$id]); $g = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$g) localnh_job_error(404, 'Gallery not found.');
$title = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $g['title'] ?? 'gallery');
header('Cache-Control: no-store');
// HEAD advertises the representation without generating it or inventing a length.
if ($method === 'HEAD') {
    header('Content-Type: ' . $types[$format]);
    header("Content-Disposition: attachment; filename=\"$title.$format\"");
    exit;
}
$lock = false; $tmp = null; $success = false;
try {
    // Hold the export slot and collection read guard for the complete worker lifetime.
    // This prevents deletion/snapshot writers from changing files while the archive is read.
    $lock = localnh_job_lock('export');
    if (!$lock) localnh_job_error(503, 'An export is already running. Please retry shortly.');
    $root = localnh_job_root();
    // Fixed workspace under the exclusive slot also cleans leftovers after a crash.
    $tmp = $root . '/export.part';
    if (is_link($tmp)) throw new RuntimeException('Linked export workspace');
    if (is_file($tmp)) unlink($tmp);
    ignore_user_abort(true);
    set_time_limit(0); // The child supervisor enforces the wall deadline.
    $script = in_array($format, ['zip', 'cbz'], true) ? 'web_export.py' : "$format.py";
    $success = localnh_job_run([__DIR__ . '/venv/bin/python', __DIR__ . '/script/py/' . $script, $id, $tmp], $tmp, false, null, [$lock, $imagesLock]);
    if ($success) {
        header('Content-Type: ' . $types[$format]);
        header("Content-Disposition: attachment; filename=\"$title.$format\"");
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
    }
} catch (Throwable $e) {
    error_log('Export job failed: ' . $e->getMessage());
} finally {
    if ($tmp !== null && is_file($tmp)) unlink($tmp);
    if (is_resource($lock)) fclose($lock);
}
if (!$success) localnh_job_error(503, 'Export could not finish within the server limits. Try ZIP/CBZ or use the local CLI for a larger export.');
exit;
