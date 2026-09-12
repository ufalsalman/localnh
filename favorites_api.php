<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
function localnh_favorites_error(int $code, string $message): never {
    http_response_code($code); echo json_encode(['error' => $message]); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); localnh_favorites_error(405, 'use post');
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') localnh_favorites_error(415, 'use application/json');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) localnh_favorites_error(413, 'request exceeds 8 kib');
$raw = file_get_contents('php://input', false, null, 0, 8193);
if ($raw === false || strlen($raw) > 8192) localnh_favorites_error(413, 'request exceeds 8 kib');
try { $input = json_decode($raw, false, 4, JSON_THROW_ON_ERROR); }
catch (JsonException $e) { localnh_favorites_error(400, 'invalid json'); }
if (!is_object($input) || !isset($input->ids) || !is_array($input->ids)) localnh_favorites_error(400, 'ids must be a list');
if (count($input->ids) > 200) localnh_favorites_error(413, 'at most 200 ids per request');
$ids = [];
foreach ($input->ids as $value) {
    if (!(is_string($value) || is_int($value))) localnh_favorites_error(400, 'invalid gallery id');
    $value = trim((string)$value);
    if (!preg_match('/^[0-9]{1,20}$/D', $value)) localnh_favorites_error(400, 'invalid gallery id');
    $ids[$value] = $value;
}
$result = [];
if ($ids) {
    $stmt = $db->prepare('SELECT id,title,title_jp,cover_path FROM galleries WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute(array_values($ids));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['id']] = ['id' => $row['id'], 'title' => $row['title'] ?: $row['title_jp'] ?: '', 'cover_path' => '/' . $row['cover_path'], 'exists' => true];
    }
}
echo json_encode(['galleries' => (object)$result]);
exit;
