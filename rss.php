<?php
require_once __DIR__ . "/presentation.php";
/**
 * RSS 2.0 Feed Generator
 * Serves an XML feed of the 50 most recently archived galleries.
 */

// Initialize database connection to local SQLite cache
$db = new PDO('sqlite:' . localnh_data_path('cache.sqlite'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$siteName = getSiteName();

// Build absolute base URL for links and image sources
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host;

// Retrieve the 50 most recently archived galleries
$stmt = $db->query("SELECT * FROM galleries ORDER BY created_at DESC LIMIT 50");
$galleries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output appropriate XML content type header
header("Content-Type: application/rss+xml; charset=utf-8");

echo "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n";
echo "<rss version=\"2.0\">\n";
echo "<channel>\n";
echo "<title>" . htmlspecialchars($siteName) . "</title>\n";
echo "<link>" . htmlspecialchars($baseUrl) . "</link>\n";
echo "<description>" . htmlspecialchars("Latest archives from " . $siteName) . "</description>\n";

// Render each gallery as an RSS item
foreach ($galleries as $g) {
    $id = $g['id'];
    $title = $g['title'] ?: $g['title_jp'];
    $link = $baseUrl . "/g/" . $id;
    $cover = $baseUrl . "/" . $g['cover_path'];
    $pubDate = date('r', $g['created_at']);
    
    // Normalize tags JSON structure
    $tagsData = json_decode($g['tags'], true);
    if (!is_array($tagsData)) {
        $tagsData = [];
    } elseif (isset($tagsData[0]) && is_string($tagsData[0])) {
        $tagsData = ['tags' => $tagsData];
    }
    
    // Build HTML description with cover, metadata breakdown, and direct reading links
    $desc = "<p><b>" . htmlspecialchars($title) . "</b></p>\n";
    $desc .= "<img src=\"" . htmlspecialchars($cover) . "\" />\n";
    
    $categories = ['artists', 'circles', 'parodies', 'characters', 'tags'];
    foreach ($categories as $cat) {
        if (!empty($tagsData[$cat])) {
            $catStr = htmlspecialchars(implode(', ', $tagsData[$cat]));
            $desc .= "<p><b>{$cat}:</b> {$catStr}</p>\n";
        }
    }
    
    $lang = $g['language'] ? htmlspecialchars($g['language']) : 'unknown';
    $desc .= "<p><b>language:</b> {$lang}</p>\n";
    $desc .= "<p><b>pages:</b> " . (int)$g['page_count'] . "</p>\n";
    
    $upDateStr = $g['upload_date'] ? date('Y-m-d', $g['upload_date']) : 'unknown';
    $arDateStr = $g['created_at'] ? date('Y-m-d', $g['created_at']) : 'unknown';
    
    $desc .= "<p><b>uploaded:</b> {$upDateStr}</p>\n";
    $desc .= "<p><b>archived:</b> {$arDateStr}</p>\n";
    
    $desc .= "<p><a href=\"" . htmlspecialchars($baseUrl . '/g/' . rawurlencode($id) . '/1', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\">read</a> | <a href=\"https://nhentai.net/g/" . rawurlencode($id) . "/\">nhentai</a></p>\n";
    
    echo "<item>\n";
    echo "<title>" . htmlspecialchars($title . " - " . $siteName) . "</title>\n";
    echo "<link>" . htmlspecialchars($link) . "</link>\n";
    echo "<guid>" . htmlspecialchars($id) . "</guid>\n";
    echo "<pubDate>" . htmlspecialchars($pubDate) . "</pubDate>\n";
    echo "<description><![CDATA[" . $desc . "]]></description>\n";
    echo "</item>\n";
}

echo "</channel>\n";
echo "</rss>\n";
