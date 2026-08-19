<?php
// Functions are declared inside function_exists() guards to allow this file to be
// included by the router even when another page has already defined these helpers.
if (!function_exists('getSiteName')) {
function getSiteName() {
    // Site title is stored in a flat text file so it can be edited without touching code.
    $path = __DIR__ . '/config/title';
    if (file_exists($path)) {
        $content = trim(file_get_contents($path));
        if ($content !== '') return $content;
    }
    return 'localnh';
}
}
$siteName = getSiteName();

if (!function_exists('getThemeVars')) {
function getThemeVars($bgColor = null) {
    global $isLoggedIn;
    // dark_mode cookie defaults to 'on'; the site is dark-themed by default.
    $darkMode = (isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] : 'on') === 'on';
    // Cover blur is a logged-in-only feature; the cookie is ignored for unauthenticated users.
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

    // Returns a CSS custom-property block ready to embed inside :root { ... }.
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
}

$themeCss = getThemeVars();

// Favicon path stored in config/favicon may be a relative path, absolute URL, or root-relative.
$faviconFile = __DIR__ . '/config/favicon';
$faviconHtml = '';
if (file_exists($faviconFile)) {
    $faviconPath = trim(file_get_contents($faviconFile));
    if ($faviconPath !== '') {
        // Prepend '/' only for paths that are neither absolute URLs nor already root-relative.
        if (strpos($faviconPath, 'http') !== 0 && strpos($faviconPath, '/') !== 0) {
            $faviconPath = '/' . $faviconPath;
        }
        $faviconHtml = "<link rel='icon' href='" . htmlspecialchars($faviconPath) . "'>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>user - <?= htmlspecialchars($siteName) ?></title>
    <link rel='stylesheet' href='/css/style.css'>
    <?= $faviconHtml ?>
    <style>
        :root {
            <?= $themeCss ?>
        }
    </style>
</head>
<body>
    <main>
    <div class='topbar'>
        <h1><a href='/'><?= htmlspecialchars($siteName) ?></a></h1>
    </div>
    
    <div class='settings-content'>
        <h2>user</h2>
        
        <form id='user-form' class='user-form' onsubmit='event.preventDefault(); saveUsername();'>
            <div class='remote-field'>
                <input type='text' id='username-input' class='remote-input' placeholder='username' autocomplete='off'>
            </div>
            <div class='user-save-row'>
                <button type='submit' id='save-btn' class='action-btn'>save</button>
                <span id='save-feedback' class='ui-text feedback-text'></span>
            </div>
        </form>

        <div class='user-action-group'>
            <button type='button' id='reset-favs-btn' class='action-btn'>reset favorites</button>
            <span id='reset-feedback' class='ui-text feedback-text'></span>
        </div>

        <div class='user-action-group'>
            <button type='button' id='backup-btn' class='action-btn'>backup</button>
            <button type='button' id='restore-btn' class='action-btn'>restore</button>
            <input type='file' id='restore-file-input' class='hidden' accept='.json'>
            <span id='action-feedback' class='ui-text feedback-text'></span>
        </div>

        <p class='ui-text user-desc'>
            backup your session as a file if you want to keep your favorites.<br>
            this website doesn't have online account feature.<br>
            keep your user file safe.<br>
            restoring user file with a new one will replace the current session.<br>
            please keep that in mind.
        </p>
    </div>
    </main>

    <script>
        window.siteName = <?= json_encode($siteName) ?>;
        // localStorage keys shared across all pages; must stay in sync with favorites.php.
        const STORAGE_KEY_USER = 'localnh_user';
        const STORAGE_KEY_FAVS = 'localnh_favorites';

        const usernameInput = document.getElementById('username-input');
        const saveFeedback = document.getElementById('save-feedback');
        const resetFeedback = document.getElementById('reset-feedback');
        const actionFeedback = document.getElementById('action-feedback');
        const backupBtn = document.getElementById('backup-btn');
        const restoreBtn = document.getElementById('restore-btn');
        const resetFavsBtn = document.getElementById('reset-favs-btn');
        const fileInput = document.getElementById('restore-file-input');

        // Minimal cookie getter: splits the document.cookie string to find a specific key.
        function getCookie(name) {
            const value = '; ' + document.cookie;
            const parts = value.split('; ' + name + '=');
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }

        // Sets a cookie with a 365-day expiry (configurable) and site-wide path.
        function setCookie(name, value, days = 365) {
            const d = new Date();
            d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
            document.cookie = name + '=' + value + ';expires=' + d.toUTCString() + ';path=/';
        }

        let saveTimer;
        // Shows a status message then clears it after 3 seconds to avoid stale feedback.
        function showSaveFeedback(text) {
            saveFeedback.textContent = text;
            clearTimeout(saveTimer);
            if (text) {
                saveTimer = setTimeout(() => {
                    saveFeedback.textContent = '';
                }, 3000);
            }
        }

        let resetTimer;
        function showResetFeedback(text) {
            resetFeedback.textContent = text;
            clearTimeout(resetTimer);
            if (text) {
                resetTimer = setTimeout(() => {
                    resetFeedback.textContent = '';
                }, 4000);
            }
        }

        let actionTimer;
        function showActionFeedback(text) {
            actionFeedback.textContent = text;
            clearTimeout(actionTimer);
            if (text) {
                actionTimer = setTimeout(() => {
                    actionFeedback.textContent = '';
                }, 3000);
            }
        }

        function loadUser() {
            try {
                const saved = localStorage.getItem(STORAGE_KEY_USER);
                if (saved) {
                    const userData = JSON.parse(saved);
                    usernameInput.value = userData.username || '';
                } else {
                    usernameInput.value = '';
                }
            } catch (e) {
                usernameInput.value = '';
            }
        }

        function saveUsername() {
            const username = usernameInput.value.trim();
            try {
                // Merge into any existing user object to preserve other stored fields (e.g. updated_at).
                const existing = JSON.parse(localStorage.getItem(STORAGE_KEY_USER) || '{}');
                existing.username = username;
                existing.updated_at = new Date().toISOString();
                localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(existing));
                showSaveFeedback('saved!');
            } catch (e) {
                showSaveFeedback('failed to save');
            }
        }

        backupBtn.addEventListener('click', () => {
            try {
                const user = JSON.parse(localStorage.getItem(STORAGE_KEY_USER) || '{}');
                const favsRaw = JSON.parse(localStorage.getItem(STORAGE_KEY_FAVS) || '{}');
                
                const username = user.username || usernameInput.value.trim() || '';
                
                // Normalize favorites to an array regardless of how they are stored internally.
                let favList = [];
                if (Array.isArray(favsRaw)) {
                    favList = favsRaw;
                } else if (typeof favsRaw === 'object' && favsRaw !== null) {
                    // Legacy object-keyed format: convert to the canonical array form.
                    favList = Object.keys(favsRaw).map(id => {
                        const item = favsRaw[id];
                        return {
                            id: String(id),
                            title: typeof item === 'string' ? item : (item.title || '')
                        };
                    });
                }

                const darkModeSetting = getCookie('dark_mode') || 'on';
                const dynamicColorSetting = getCookie('dynamic_color') || 'on';

                // Backup payload schema v1.0: version, sitename, username, settings, favorites.
                // 'sitename' is the validation key used during restore (see fileInput handler).
                const payload = {
                    version: '1.0',
                    sitename: window.siteName || 'localnh',
                    username: username,
                    saved_at: new Date().toISOString(),
                    settings: {
                        dark_mode: darkModeSetting,
                        dynamic_color: dynamicColorSetting
                    },
                    favorites: favList,
                };

                // Trigger a browser file download by creating a temporary <a> element.
                const dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(JSON.stringify(payload, null, 2));
                const downloadAnchor = document.createElement('a');
                // Sanitise site name and username for a safe filename (letters, digits, _ and - only).
                const cleanSite = (window.siteName || 'localnh').toLowerCase().replace(/[^a-z0-9_-]/g, '_');
                const cleanUser = username ? username.toLowerCase().replace(/[^a-z0-9_-]/g, '_') : 'user';
                const dateStr = new Date().toISOString().slice(0, 10);
                
                downloadAnchor.setAttribute('href', dataStr);
                downloadAnchor.setAttribute('download', `${cleanSite}_${cleanUser}_${dateStr}.json`);
                document.body.appendChild(downloadAnchor);
                downloadAnchor.click();
                downloadAnchor.remove();

                showActionFeedback('backup created!');
            } catch (e) {
                showActionFeedback('failed to create backup');
            }
        });

        // Delegate the file picker click to the hidden <input type="file"> element.
        restoreBtn.addEventListener('click', () => {
            fileInput.click();
        });

        fileInput.addEventListener('change', (event) => {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = JSON.parse(e.target.result);
                    // Basic validation: the file must have a 'sitename' key (v1.0 format)
                    // or an 'app' key (potential future format); reject anything unrecognised.
                    if (!data.sitename && !data.app) {
                        throw new Error('invalid backup file');
                    }

                    if (data.username !== undefined) {
                        const userObj = { username: data.username, updated_at: data.saved_at || new Date().toISOString() };
                        localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(userObj));
                        usernameInput.value = data.username;
                    }

                    // Restore user preference cookies from the backup's settings block.
                    if (data.settings && typeof data.settings === 'object') {
                        if (data.settings.dark_mode) {
                            setCookie('dark_mode', data.settings.dark_mode);
                        }
                        if (data.settings.dynamic_color) {
                            setCookie('dynamic_color', data.settings.dynamic_color);
                        }
                    }

                    if (Array.isArray(data.favorites)) {
                        // Current array format: strip any entries that lack an id, then normalise fields.
                        const cleanFavs = data.favorites.filter(f => f && f.id).map(f => ({
                            id: String(f.id),
                            title: f.title || '',
                            tags: Array.isArray(f.tags) ? f.tags : [],
                            artists: Array.isArray(f.artists) ? f.artists : [],
                            circles: Array.isArray(f.circles) ? f.circles : [],
                            parodies: Array.isArray(f.parodies) ? f.parodies : [],
                            characters: Array.isArray(f.characters) ? f.characters : [],
                            language: f.language || '',
                            added_at: f.added_at || Date.now(),
                            cum_count: parseInt(f.cum_count || 0, 10)
                        }));
                        localStorage.setItem(STORAGE_KEY_FAVS, JSON.stringify(cleanFavs));
                    } else if (data.favorites && typeof data.favorites === 'object') {
                        // Legacy object-keyed format — migrate to array on restore.
                        const cleanFavs = Object.keys(data.favorites).map(id => {
                            const item = data.favorites[id];
                            return {
                                id: String(id),
                                title: typeof item === 'string' ? item : (item.title || ''),
                                tags: (item && Array.isArray(item.tags)) ? item.tags : [],
                                artists: (item && Array.isArray(item.artists)) ? item.artists : [],
                                circles: (item && Array.isArray(item.circles)) ? item.circles : [],
                                parodies: (item && Array.isArray(item.parodies)) ? item.parodies : [],
                                characters: (item && Array.isArray(item.characters)) ? item.characters : [],
                                language: (item && item.language) ? item.language : '',
                                added_at: (item && item.added_at) ? item.added_at : Date.now(),
                                cum_count: parseInt((item && item.cum_count) || 0, 10)
                            };
                        });
                    }

                    showActionFeedback('backup restored!');
                } catch (err) {
                    showActionFeedback('error restoring backup');
                }
                // Reset the file input so the same file can be re-selected if needed.
                fileInput.value = '';
            };
            reader.readAsText(file);
        });

        // Two-step confirmation pattern: first click shows a prompt, second click executes.
        // A 4-second timeout cancels the pending confirmation so it doesn't linger forever.
        let resetFavsConfirmState = false;
        let resetFavsTimeout;

        resetFavsBtn.addEventListener('click', () => {
            if (!resetFavsConfirmState) {
                resetFavsConfirmState = true;
                showResetFeedback('do you want to reset favorites?');
                clearTimeout(resetFavsTimeout);
                resetFavsTimeout = setTimeout(() => {
                    resetFavsConfirmState = false;
                }, 4000);
            } else {
                resetFavsConfirmState = false;
                clearTimeout(resetFavsTimeout);
                localStorage.removeItem(STORAGE_KEY_FAVS);
                showResetFeedback('favorites cleared!');
            }
        });



        loadUser();
    </script>
</body>
</html>
