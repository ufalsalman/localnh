<?php
require_once __DIR__ . '/resource_jobs.php';

function localnh_aggregation_key(): string {
    $file = localnh_data_path('cache.sqlite');
    clearstatcache();
    $version = localnh_data_path('cache/aggregation.version');
    $revision = is_file($version) ? file_get_contents($version, false, null, 0, 128) : '';
    // Header change counter covers ordinary SQLite commits; the writer revision
    // also covers WAL commits. TTL catches external tools that omit invalidation.
    $header = file_get_contents($file, false, null, 0, 100);
    $info = stat($file);
    return hash('sha256', 'aggregate-v1:' . $revision . ':' . $header . ':' . $info['ino'] . ':' . $info['size']);
}

function localnh_aggregate(string $kind): array {
    if (!in_array($kind, ['stats', 'tags'], true)) throw new InvalidArgumentException('Unknown aggregate');
    $lock = false; $tmp = null;
    try {
        $root = localnh_job_root();
        $cache = "$root/aggregate-$kind.json";
        $key = localnh_aggregation_key();
        $load = static function() use ($cache, $key) {
            if (!is_file($cache) || is_link($cache) || filesize($cache) > 8388608) return null;
            $entry = json_decode(file_get_contents($cache), true);
            return is_array($entry) && ($entry['key'] ?? '') === $key
                && ($entry['expires'] ?? 0) > time() && is_array($entry['data'] ?? null) ? $entry['data'] : null;
        };
        $data = $load();
        if ($data !== null) return $data;
        $lock = localnh_job_lock('aggregate-' . $kind);
        if (!$lock) localnh_job_error(503, 'collection summary is being refreshed. please retry.');
        $data = $load();
        if ($data !== null) return $data;
        $tmp = "$root/aggregate-$kind.part";
        if (is_link($tmp)) throw new RuntimeException('Linked aggregate workspace');
        if (!localnh_job_run([__DIR__ . '/venv/bin/python', __DIR__ . '/script/py/aggregate.py', $kind], $tmp, true, null, [$lock])) throw new RuntimeException('Aggregate builder failed or exceeded limits');
        $data = json_decode(file_get_contents($tmp), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || localnh_aggregation_key() !== $key) throw new RuntimeException('Collection changed during aggregation');
        $entry = json_encode(['key' => $key, 'expires' => time() + 15, 'data' => $data], JSON_THROW_ON_ERROR);
        if (strlen($entry) > 8388608) throw new RuntimeException('Aggregate exceeds cache limit');
        if (file_put_contents($tmp, $entry) === false || !rename($tmp, $cache)) throw new RuntimeException('Aggregate publication failed');
        return $data;
    } catch (Throwable $e) {
        error_log('Collection aggregation failed: ' . $e->getMessage());

    } finally {
        if ($tmp !== null && is_file($tmp)) unlink($tmp);
        if (is_resource($lock)) fclose($lock);
    }
    localnh_job_error(503, 'collection summary unavailable. please retry.');
}
