<?php
require_once __DIR__ . '/resource_jobs.php';
function localnh_archive_lock(bool $checkUpstream = false) {
    $path = localnh_data_path('cache/archive.lock');
    $mask = umask(0077);
    try { $lock = fopen($path, 'c'); } finally { umask($mask); }
    if (!$lock) localnh_job_error(503, 'archive storage unavailable.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        localnh_job_error(503, 'an archive or deletion is active. retry shortly.');
    }
    if ($checkUpstream) {
        $retryPath = localnh_data_path('cache/archive-retry.json');
        if (is_file($retryPath)) {
            $retry = json_decode(file_get_contents($retryPath), true);
            if (!is_array($retry) || !isset($retry['until'])) localnh_job_error(503, 'archive retry state unavailable.');
            $seconds = max(0, (int)$retry['until'] - time());
            if ($seconds) {
                header('Retry-After: ' . $seconds);
                localnh_job_error(429, 'upstream is limiting archive requests (http 429). retry in ' . $seconds . ' seconds.');
            }
        }
    }
    return $lock;
}
