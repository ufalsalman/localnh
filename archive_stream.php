<?php
// $archiveLock was admitted before output; Python inherits it across PHP death.
set_time_limit(0);
ignore_user_abort(true);
$process = proc_open([__DIR__ . '/venv/bin/python', __DIR__ . '/script/py/fetch.py', $id, '--web'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w'], 3 => $archiveLock], $pipes);
$done = false; $error = false;
function localnh_archive_event(string $function, array $args): void {
    $encoded = array_map(fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), $args);
    echo '<script>' . $function . '(' . implode(',', $encoded) . ');</script>' . "\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}
try {
    if (!is_resource($process)) throw new RuntimeException('Unable to start archive.');
    stream_set_blocking($pipes[1], false);
    $buffer = ''; $start = microtime(true); $lastHeartbeat = 0;
    while (!feof($pipes[1])) {
        if (microtime(true) - $start > 905) throw new RuntimeException('Archive deadline exceeded.');
        $read = [$pipes[1]]; $write = $except = null;
        if (stream_select($read, $write, $except, 1)) {
            $buffer .= fread($pipes[1], 65536);
            if (strlen($buffer) > 4194304) throw new RuntimeException('Archive event exceeds limit.');
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline); $buffer = substr($buffer, $newline + 1);
                $event = json_decode($line, true);
                if (!is_array($event)) continue;
                if (isset($event['error'])) {
                    $error = true; localnh_archive_event('showError', [strtolower((string)$event['error'])]);
                } elseif (($event['type'] ?? '') === 'meta') {
                    localnh_archive_event('updateMeta', [(string)($event['title'] ?? ''), '', (string)($event['cover_b64'] ?? '')]);
                } elseif (($event['type'] ?? '') === 'progress') {
                    localnh_archive_event('updateProgress', [(int)$event['current'], (int)$event['total']]);
                } elseif (($event['type'] ?? '') === 'done') $done = true;
            }
        }
        if (microtime(true) - $lastHeartbeat > 2) {
            echo '<!-- archive heartbeat -->' . str_repeat(' ', 8192) . "\n";
            if (ob_get_level() > 0) ob_flush(); flush();
            $lastHeartbeat = microtime(true);
        }
        if (connection_aborted()) throw new RuntimeException('Client disconnected.');
    }
} catch (Throwable $e) {
    $error = true;
    if (is_resource($process)) proc_terminate($process);
    if (!connection_aborted()) localnh_archive_event('showError', [strtolower($e->getMessage())]);
} finally {
    if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
    $exitCode = is_resource($process) ? proc_close($process) : -1;
    fclose($archiveLock);
}
if (!$error && $done && $exitCode === 0) localnh_archive_event('done', [$id]);
elseif (!$error) localnh_archive_event('showError', ['archive failed; retry after checking the server limits.']);
