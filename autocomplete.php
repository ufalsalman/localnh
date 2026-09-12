<?php
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD'); http_response_code(405); echo '[]'; exit;
}
$q = $_GET['q'] ?? '';
if (!is_string($q) || strlen($q) > 128) { http_response_code(400); echo '[]'; exit; }
$q = strtolower(trim($q));
if (strlen($q) < 2) { echo '[]'; exit; }
require_once __DIR__ . '/aggregation.php';
$groups = localnh_aggregate('tags');
$results = [];
foreach (['tags' => 'tag', 'artists' => 'artist', 'circles' => 'circle', 'parodies' => 'parody', 'characters' => 'character', 'languages' => 'language'] as $group => $prefix) {
    foreach ($groups[$group] as $value => $count) {
        if (str_contains(strtolower((string)$value), $q)) $results[] = $prefix . ' - ' . $value;
        if (count($results) === 10) break 2;
    }
}
echo json_encode($results);
exit;
