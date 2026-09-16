<?php
require_once __DIR__ . '/storage.php';

function localnh_job_error(int $status, string $message): never {
    header_remove('ETag');
    header('Cache-Control: no-store');
    if ($status === 503) header('Retry-After: 3');
    http_response_code($status);
    exit($message);
}

// Ephemeral, private and outside collection/snapshots. Isolated by data root.
function localnh_job_root(): string {
    $root = sys_get_temp_dir() . '/localnh-jobs-' . substr(hash('sha256', localnh_data_root()), 0, 24);
    if (is_link($root)) throw new RuntimeException('Linked job directory');
    if (!is_dir($root) && !@mkdir($root, 0700) && !is_dir($root)) throw new RuntimeException('Job directory unavailable');
    if (fileowner($root) !== posix_geteuid() || (fileperms($root) & 0077)) throw new RuntimeException('Unsafe job directory');
    return $root;
}

function localnh_job_lock(string $name, int $slots = 1) {
    $root = localnh_job_root();
    for ($i = 0; $i < $slots; $i++) {
        $path = "$root/$name-$i.lock";
        if (is_link($path)) throw new RuntimeException('Linked lock');
        $handle = fopen($path, 'c');
        if (!$handle) throw new RuntimeException('Lock unavailable');
        if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
        fclose($handle);
    }
    return false;
}

// Supervisor owns the process group, wall deadline, and child resource limits.
// File descriptors avoid pipe backpressure and unbounded PHP output buffers.
function localnh_job_run(array $command, string $output, bool $thumbnail = false, ?string $input = null, array $locks = []): bool {
    $spec = ['command' => $command, 'seconds' => $thumbnail ? 15 : 120,
        'memory' => $thumbnail ? 536870912 : 805306368,
        'bytes' => $thumbnail ? 8388608 : 536870912,
        'output' => $thumbnail ? $output : null, 'input' => $input];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    // Supervisor retains the same flock descriptions if PHP is killed mid-job.
    // A new request cannot reuse its workspace until the child group is reaped.
    foreach ($locks as $i => $lock) $descriptors[3 + $i] = $lock;
    $spec['lock_fds'] = array_map(fn($i) => 3 + $i, array_keys($locks));
    // Encode before spawning so serialization failure cannot strand a child.
    $payload = json_encode($spec, JSON_THROW_ON_ERROR);
    $process = proc_open(['/usr/bin/python3', __DIR__ . '/script/py/resource_worker.py'], $descriptors, $pipes);
    if (!is_resource($process)) return false;
    $code = -1;
    try {
        $offset = 0;
        while ($offset < strlen($payload)) {
            $written = fwrite($pipes[0], substr($payload, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Job input pipe closed');
            $offset += $written;
        }
        fclose($pipes[0]);
        $code = proc_close($process);
    } finally {
        if (is_resource($pipes[0])) fclose($pipes[0]);
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    clearstatcache(true, $output);
    return $code === 0 && is_file($output) && filesize($output) > 0 && filesize($output) <= $spec['bytes'];
}

// Called with the cache metadata lock held. Oldest derivatives are evicted first.
function localnh_thumbnail_prune(string $root, int $incoming): void {
    $files = glob($root . '/*.webp') ?: [];
    usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
    $total = array_sum(array_map('filesize', $files));
    $count = count($files);
    foreach ($files as $file) {
        if ($total + $incoming <= 67108864 && $count < 512) break;
        $size = filesize($file);
        if (!unlink($file)) throw new RuntimeException('Cache eviction failed');
        $total -= $size; $count--;
    }
}
