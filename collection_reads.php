<?php
require_once __DIR__ . '/resource_jobs.php';
// Image readers share this lock; delete/import require exclusive ownership.
// Archive's writer lock is separate so new galleries do not block thumbnails.
function localnh_collection_read_guard(bool $exclusive = false) {
    $mask = umask(0077);
    try { $lock = fopen(localnh_data_path('cache/collection-read.lock'), 'c'); }
    finally { umask($mask); }
    if (!$lock) localnh_job_error(503, 'collection lock unavailable.');
    if (!flock($lock, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
        fclose($lock);
        localnh_job_error(503, 'collection images are in use. retry shortly.');
    }
    return $lock;
}
