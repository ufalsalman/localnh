<?php
// Shared presentation only: no database connection, session creation, headers,
// authentication decisions, or page rendering occurs when this file is loaded.
require_once __DIR__ . '/storage.php';

// Trim display configuration consistently; empty/missing files return ''.
// Callers choose their own fallback. Credentials and authorization settings
// deliberately remain outside this presentation helper.
function localnh_presentation_text(string $name): string {
    $path = localnh_data_path('config/' . $name);
    return file_exists($path) ? trim(file_get_contents($path)) : '';
}

function getSiteName() {
    $title = localnh_presentation_text('title');
    return $title !== '' ? $title : 'localnh';
}

// Preserve the existing footer renderer: link conversion followed by nl2br.
// This is not a Markdown sanitizer; configured HTML is not escaped here.
function getFooterHtml() {
    $md = localnh_presentation_text('footer');
    if ($md === '') return '';
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $md);
    return nl2br($html);
}

// Preserve existing normalization and escaping for configured favicon paths.
function getFaviconHtml() {
    $path = localnh_presentation_text('favicon');
    if ($path === '') return '';
    if (strpos($path, 'http') !== 0 && strpos($path, '/') !== 0) {
        $path = '/' . $path;
    }
    return "<link rel='icon' href='" . htmlspecialchars($path) . "'>";
}

// The optional gallery background overrides only the palette background.
function getThemeVars($bgColor = null) {
    // Default dark mode to 'on' when the cookie is absent (first-time visitors).
    $darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on') === 'on';
    // Preserve the browser preference for both anonymous and logged-in users.
    $blurCovers = (isset($_COOKIE['blur_covers']) && $_COOKIE['blur_covers'] === 'on') ? '25px' : '0px';
    if ($bgColor === null) {
        $bgColor = $darkMode ? '#111' : '#fff';
    }
    $textColor = $darkMode ? '#fff' : '#111';
    $btnBg = $darkMode ? '#fff' : '#111';
    $btnText = $darkMode ? '#111' : '#fff';
    $btnHoverBg = $darkMode ? '#ddd' : '#333';
    $btnHoverBorder = $darkMode ? '#ddd' : '#333';
    $inputBg = $darkMode ? '#111' : '#fff';
    $inputText = $darkMode ? '#fff' : '#111';
    $inputBorder = $darkMode ? '#fff' : '#111';
    $dotsColor = $darkMode ? '#666' : '#999';

    return "
        --bg-color: {$bgColor};
        --text-color: {$textColor};
        --btn-bg: {$btnBg};
        --btn-text: {$btnText};
        --btn-hover-bg: {$btnHoverBg};
        --btn-hover-border: {$btnHoverBorder};
        --input-bg: {$inputBg};
        --input-text: {$inputText};
        --input-border: {$inputBorder};
        --dots-color: {$dotsColor};
        --cover-blur: {$blurCovers};
    ";
}

// Sample a logical cache/images path through the storage resolver. Missing or
// unreadable images retain the existing dark/light fallback palette.
function getDominantColor($filePath, $isDarkMode = true) {
    $fullPath = localnh_image_path($filePath);
    // Return a plain dark/light fallback when the file is missing or unreadable.
    $defaultBg = $isDarkMode ? '#111' : '#fff';
    if ($fullPath === false || !is_file($fullPath)) return $defaultBg;
    // @ suppresses warnings for corrupt/truncated files; $img stays false on failure.
    $mime = @mime_content_type($fullPath);
    $img = false;
    if ($mime === 'image/webp') $img = @imagecreatefromwebp($fullPath);
    elseif ($mime === 'image/jpeg') $img = @imagecreatefromjpeg($fullPath);
    elseif ($mime === 'image/png') $img = @imagecreatefrompng($fullPath);
    if (!$img) return $defaultBg;
    
    // Downsample the entire image to a single pixel; the colour is the visual average.
    $tmp = imagecreatetruecolor(1, 1);
    imagecopyresampled($tmp, $img, 0, 0, 0, 0, 1, 1, imagesx($img), imagesy($img));
    // imagecolorat returns a packed 32-bit integer: 0x00RRGGBB.
    $rgb = imagecolorat($tmp, 0, 0);
    imagedestroy($tmp);
    imagedestroy($img);
    
    // Unpack each channel via bit-shifting and normalise to [0.0, 1.0] for HSL maths.
    $r = (($rgb >> 16) & 0xFF) / 255;
    $g = (($rgb >> 8) & 0xFF) / 255;
    $b = ($rgb & 0xFF) / 255;
    
    // Standard RGB → HSL conversion.
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $h = 0; $s = 0; $l = ($max + $min) / 2;
    if ($max != $min) {
        $d = $max - $min;
        // Saturation formula differs based on whether lightness is above or below 0.5.
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max == $r) $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
        elseif ($max == $g) $h = ($b - $r) / $d + 2;
        else $h = ($r - $g) / $d + 4;
        $h /= 6; // Normalise hue to [0.0, 1.0].
    }
    // Override lightness to match the theme; keep the source image's hue.
    // A minimum saturation of 0.20 prevents washed-out greys from producing dull backgrounds.
    if ($isDarkMode) {
        $targetL = 0.12;
        $targetS = max($s, 0.20);
    } else {
        $targetL = 0.88;
        $targetS = max($s, 0.20);
    }
    return 'hsl(' . round($h * 360) . ', ' . round($targetS * 100) . '%, ' . round($targetL * 100) . '%)';
}
