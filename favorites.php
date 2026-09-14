<?php
// Page content is served through index.php. Shared presentation is loaded once.
require_once __DIR__ . '/presentation.php';

$siteName = getSiteName();

$themeCss = getThemeVars();

$footerHtml = getFooterHtml();

// Favicon path may be relative or absolute; normalise relative paths by prepending '/'.
$faviconHtml = getFaviconHtml();

// $isOrphanPage is set to true by the router when serving /favorites/orphan.
// It repurposes this same template for the orphaned-favorites view.
$isOrphanPage = !empty($isOrphanPage);

// public archive flag: 'on' means unauthenticated visitors can also trigger archiving.
$paFile = localnh_data_path('config/public');
$publicArchive = file_exists($paFile) ? trim(file_get_contents($paFile)) : 'off';
// $canArchive is exposed to JavaScript (window.canArchive) to show/hide the archive button.
$canArchive = ($publicArchive === 'on' || !empty($isLoggedIn));
?>
<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title><?= $isOrphanPage ? 'orphaned favorites - ' . htmlspecialchars($siteName) : 'favorites - ' . htmlspecialchars($siteName) ?></title>
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
    <div class='topbar' id='main-topbar'>
        <h1 id='topbar-title'><a href='/'><?= htmlspecialchars($siteName) ?></a></h1>
        <div class='topbar-controls'>
            <div class='search-container'>
                <form method='GET' action='/' id='search-form'>
                    <div class='search-wrapper'>
                        <input type='text' name='q' id='search-input' class='search-input' placeholder='search' value='' autocomplete='off'>
                        <button type='submit' class='search-btn'>go</button>
                    </div>
                    <div id='autocomplete-results' class='autocomplete-results'></div>
                </form>
            </div>
            <a href='#footer' class='top-menu-link ui-text'>menu</a>
        </div>
    </div>

<?php if ($isOrphanPage): ?>
    <h2 id='fav-heading'>orphaned favorites</h2>
    <p id='orphan-count-row' class='fav-entry-count ui-text hidden'></p>
    <p class='ui-text'><a href='/favorites' class='reset-search-link'>back to favorites</a></p>
    <div id='orphan-list' class='orphan-list'></div>
    <p id='fav-empty' class='empty-favorites-msg ui-text hidden'>there's nothing here yet.</p>
<?php else: ?>
    <h2 id='fav-heading'>favorites</h2>
    <p id='fav-count-row' class='fav-entry-count ui-text hidden'></p>
    <div class='sort-container hidden' id='fav-sort-container'>
        <label for='fav-sort-select'>sort </label>
        <select name='sort' id='fav-sort-select'>
            <option value='newest'>newest</option>
            <option value='most_cummed'>most cummed</option>
        </select>
    </div>
    <div id='fav-grid' class='grid'></div>
    <p id='fav-empty' class='empty-favorites-msg ui-text hidden'>there's nothing here yet.</p>
<?php endif; ?>

    <div class='footer' id='footer'>
        <div class='footer-credits ui-text'>
            <?= $footerHtml ?>
        </div>
        <div class='footer-links'>
            <a href='/directory' class='ui-text'>directory</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/favorites' class='ui-text'>favorites</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/rss' class='ui-text'>rss</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/random' class='ui-text'>random</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/user' class='ui-text'>user</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/stats' class='ui-text'>stats</a> &nbsp;&nbsp;&nbsp;&nbsp; 
            <a href='/settings' class='ui-text'>settings</a>
        </div>
    </div>
    </main>

    <script>
        // PHP-injected variables made available to JavaScript at page load.
        window.siteName = <?= localnh_script_json($siteName) ?>;
        window.isOrphanPage = <?= localnh_script_json($isOrphanPage) ?>;
        window.canArchive = <?= localnh_script_json($canArchive) ?>;
        // Keys used for localStorage; kept as constants to avoid typos across functions.
        const STORAGE_KEY_USER = 'localnh_user';
        const STORAGE_KEY_FAVS = 'localnh_favorites';
        // Blank SVG placeholder shown while a cover image is unavailable (e.g. after removal).
        const WHITE_PLACEHOLDER = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="250" height="350"><rect width="100%" height="100%" fill="white"/></svg>';

        const favHeading = document.getElementById('fav-heading');
        const favCountRow = document.getElementById('fav-count-row');
        const orphanCountRow = document.getElementById('orphan-count-row');
        const favGrid = document.getElementById('fav-grid');
        const orphanList = document.getElementById('orphan-list');
        const favEmpty = document.getElementById('fav-empty');

        // currentGalleryData: map of gallery ID → server-side existence/metadata,
        // populated by the /api/favorites POST call after page load.
        let currentGalleryData = {};
        // itemsCache: map of gallery ID → display data used for undo/restore operations.
        const itemsCache = {};

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            // textContent escapes markup, but quotes must also be escaped for attributes.
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function getStoredFavorites() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY_FAVS);
                if (!raw) return [];
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    // Current format: array of objects with id, title, tags, added_at, cum_count, etc.
                    return parsed.filter(f => f && typeof f === 'object' && /^[0-9]{1,20}$/.test(String(f.id))).map(f => ({
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
                }
                if (typeof parsed === 'object' && parsed !== null) {
                    // Legacy format: plain object keyed by gallery ID, value was either a string
                    // (the title) or an object. Migrate on-the-fly to the canonical array format.
                    return Object.keys(parsed).filter(id => /^[0-9]{1,20}$/.test(id)).map(id => ({
                        id: String(id),
                        title: typeof parsed[id] === 'string' ? parsed[id] : (parsed[id]?.title || ''),
                        tags: (parsed[id] && Array.isArray(parsed[id].tags)) ? parsed[id].tags : [],
                        artists: (parsed[id] && Array.isArray(parsed[id].artists)) ? parsed[id].artists : [],
                        circles: (parsed[id] && Array.isArray(parsed[id].circles)) ? parsed[id].circles : [],
                        parodies: (parsed[id] && Array.isArray(parsed[id].parodies)) ? parsed[id].parodies : [],
                        characters: (parsed[id] && Array.isArray(parsed[id].characters)) ? parsed[id].characters : [],
                        language: (parsed[id] && parsed[id].language) ? parsed[id].language : '',
                        added_at: (parsed[id] && parsed[id].added_at) ? parsed[id].added_at : Date.now(),
                        cum_count: parseInt((parsed[id] && parsed[id].cum_count) || 0, 10)
                    }));
                }
                return [];
            } catch (e) {
                return [];
            }
        }

        function saveStoredFavorites(favs) {
            try {
                localStorage.setItem(STORAGE_KEY_FAVS, JSON.stringify(favs));
            } catch (e) {}
        }

        function getUserName() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY_USER);
                if (raw) {
                    const u = JSON.parse(raw);
                    if (u && u.username && u.username.trim() !== '') {
                        return u.username.trim();
                    }
                }
            } catch (e) {}
            // Default display name for visitors who have not set a username.
            return 'guest';
        }

        function updateCounts() {
            const favs = getStoredFavorites();

            // Partition favorites into "local" (gallery exists on this server) and "orphaned"
            // (stored in localStorage but no longer found in the local database).
            let localCount = 0;
            let orphanedCount = 0;
            favs.forEach(f => {
                const id = String(f.id);
                if (currentGalleryData[id] && currentGalleryData[id].exists) {
                    localCount++;
                } else {
                    orphanedCount++;
                }
            });

            if (window.isOrphanPage) {
                if (orphanedCount === 0) {
                    if (orphanCountRow) orphanCountRow.classList.add('hidden');
                    if (favEmpty) favEmpty.classList.remove('hidden');
                } else {
                    if (favEmpty) favEmpty.classList.add('hidden');
                    const orphanedLabel = orphanedCount === 1 ? '1 orphaned entry' : orphanedCount + ' orphaned entries';
                    if (orphanCountRow) {
                        orphanCountRow.textContent = orphanedLabel;
                        orphanCountRow.classList.remove('hidden');
                    }
                }
            } else {
                if (localCount === 0 && orphanedCount === 0) {
                    if (favCountRow) favCountRow.classList.add('hidden');
                    if (favEmpty) favEmpty.classList.remove('hidden');
                } else {
                    const localLabel = localCount === 1 ? '1 entry' : localCount + ' entries';
                    if (favCountRow) {
                        // When orphans exist, show a clickable link to /favorites/orphan.
                        if (orphanedCount > 0) {
                            const orphanedLabel = orphanedCount === 1 ? '1 orphaned entry' : orphanedCount + ' orphaned entries';
                            favCountRow.innerHTML = `${localLabel}, plus <a href='/favorites/orphan' class='ui-text'>${orphanedLabel}</a>`;
                        } else {
                            favCountRow.textContent = localLabel;
                        }
                        favCountRow.classList.remove('hidden');
                    }
                }
            }
        }

        function toggleFav(id) {
            id = String(id);
            const itemEl = document.getElementById('fav-item-' + id);
            const btnEl = document.getElementById('fav-btn-' + id);
            const cumResetBtn = document.getElementById('cum-reset-btn-' + id);
            const imgEl = document.getElementById('fav-img-' + id);
            const titleEl = document.getElementById('fav-title-' + id);

            if (!itemEl || !btnEl || !imgEl || !titleEl) return;

            // 'fav-removed' class acts as a soft-delete visual state with undo support.
            const isRemoved = itemEl.classList.contains('fav-removed');

            if (!isRemoved) {
                // Remove from localStorage and update the visual state immediately.
                let favs = getStoredFavorites();
                favs = favs.filter(f => String(f.id) !== id);
                saveStoredFavorites(favs);
                updateCounts();

                itemEl.classList.add('fav-removed');
                if (cumResetBtn) cumResetBtn.classList.add('hidden');
                imgEl.src = WHITE_PLACEHOLDER;
                titleEl.textContent = 'removed';
                btnEl.textContent = 'undo';
            } else {
                // Undo: restore the entry from itemsCache (which was populated during render).
                const cached = itemsCache[id] || { id: id, title: 'gallery ' + id, cover: '', cum_count: 0 };
                let favs = getStoredFavorites();
                
                // Guard against duplicates in case the entry was somehow re-added concurrently.
                const exists = favs.some(f => String(f.id) === id);
                if (!exists) {
                    favs.unshift({
                        id: id,
                        title: cached.title,
                        tags: cached.tags || [],
                        artists: cached.artists || [],
                        circles: cached.circles || [],
                        parodies: cached.parodies || [],
                        characters: cached.characters || [],
                        language: cached.language || '',
                        added_at: Date.now(),
                        cum_count: cached.cum_count || 0
                    });
                    saveStoredFavorites(favs);
                    updateCounts();
                }

                itemEl.classList.remove('fav-removed');
                if (cumResetBtn) cumResetBtn.classList.remove('hidden');
                imgEl.src = cached.cover || WHITE_PLACEHOLDER;
                titleEl.textContent = cached.title;
                btnEl.textContent = 'remove';
            }
        }

        function toggleResetCum(id) {
            id = String(id);
            const btnEl = document.getElementById('cum-reset-btn-' + id);
            if (!btnEl) return;

            // 'cum-is-reset' class acts as a toggled state; the button doubles as an undo control.
            const isReset = btnEl.classList.contains('cum-is-reset');
            let favs = getStoredFavorites();
            const index = favs.findIndex(f => String(f.id) === id);

            if (!isReset) {
                // Store the previous count in itemsCache so it can be restored on undo.
                if (index > -1) {
                    itemsCache[id].prev_cum_count = parseInt(favs[index].cum_count || 0, 10);
                    itemsCache[id].cum_count = 0;
                    favs[index].cum_count = 0;
                    saveStoredFavorites(favs);
                }
                btnEl.classList.add('cum-is-reset');
                btnEl.innerHTML = '<span>undo</span>';
            } else {
                const prevCount = itemsCache[id] ? (itemsCache[id].prev_cum_count || 1) : 1;
                if (index > -1) {
                    favs[index].cum_count = prevCount;
                    itemsCache[id].cum_count = prevCount;
                    saveStoredFavorites(favs);
                }
                btnEl.classList.remove('cum-is-reset');
                btnEl.innerHTML = `<span class="cum-text-normal">cum ${prevCount}x</span><span class="cum-text-hover">reset</span>`;
            }
        }

        function toggleOrphanFav(id) {
            id = String(id);
            const rowEl = document.getElementById('orphan-row-' + id);
            const btnEl = document.getElementById('orphan-btn-' + id);
            const titleEl = document.getElementById('orphan-title-' + id);

            if (!rowEl || !btnEl || !titleEl) return;

            const isRemoved = rowEl.classList.contains('fav-removed');

            if (!isRemoved) {
                let favs = getStoredFavorites();
                favs = favs.filter(f => String(f.id) !== id);
                saveStoredFavorites(favs);
                updateCounts();

                rowEl.classList.add('fav-removed');
                titleEl.textContent = 'removed';
                btnEl.textContent = 'undo';
            } else {
                const cached = itemsCache[id] || { id: id, title: 'gallery ' + id, cum_count: 0 };
                let favs = getStoredFavorites();
                
                const exists = favs.some(f => String(f.id) === id);
                if (!exists) {
                    favs.unshift({
                        id: id,
                        title: cached.title,
                        tags: cached.tags || [],
                        artists: cached.artists || [],
                        circles: cached.circles || [],
                        parodies: cached.parodies || [],
                        characters: cached.characters || [],
                        language: cached.language || '',
                        added_at: Date.now(),
                        cum_count: cached.cum_count || 0
                    });
                    saveStoredFavorites(favs);
                    updateCounts();
                }

                rowEl.classList.remove('fav-removed');
                titleEl.textContent = cached.title;
                btnEl.textContent = 'remove';
            }
        }

        function renderFavoritesGrid() {
            if (window.isOrphanPage || !favGrid) return;

            const sortSelect = document.getElementById('fav-sort-select');
            const sortContainer = document.getElementById('fav-sort-container');

            const favs = getStoredFavorites();
            // Only render galleries that actually exist on this server (confirmed by API).
            const localFavs = favs.filter(f => {
                const id = String(f.id);
                return currentGalleryData[id] && currentGalleryData[id].exists;
            });

            if (localFavs.length === 0) {
                favGrid.innerHTML = '';
                if (sortContainer) sortContainer.classList.add('hidden');
                if (favs.length === 0) favEmpty.classList.remove('hidden');
                return;
            }

            if (sortContainer) sortContainer.classList.remove('hidden');

            const sortMode = sortSelect ? sortSelect.value : 'newest';
            if (sortMode === 'most_cummed') {
                // Primary sort: cum_count descending; secondary: added_at descending (newest first).
                localFavs.sort((a, b) => {
                    const cA = parseInt(a.cum_count || 0, 10);
                    const cB = parseInt(b.cum_count || 0, 10);
                    if (cB !== cA) return cB - cA;
                    return (b.added_at || 0) - (a.added_at || 0);
                });
            } else {
                // Default: newest-added first.
                localFavs.sort((a, b) => (b.added_at || 0) - (a.added_at || 0));
            }
            favGrid.innerHTML = '';
            localFavs.forEach(f => {
                const id = String(f.id);
                const dbInfo = currentGalleryData[id];
                // Prefer server-side title/cover; fall back to what was cached in localStorage.
                const title = (dbInfo && dbInfo.title) ? dbInfo.title : (f.title || ('gallery ' + id));
                const cover = (dbInfo && dbInfo.cover_path) ? dbInfo.cover_path : WHITE_PLACEHOLDER;
                const cumCount = parseInt(f.cum_count || 0, 10);

                // Populate itemsCache so undo/restore operations have data to work with.
                itemsCache[id] = {
                    id: id,
                    title: title,
                    cover: cover,
                    tags: f.tags || [],
                    artists: f.artists || [],
                    circles: f.circles || [],
                    parodies: f.parodies || [],
                    characters: f.characters || [],
                    language: f.language || '',
                    cum_count: cumCount,
                    prev_cum_count: cumCount
                };

                const itemDiv = document.createElement('div');
                itemDiv.className = 'item';
                itemDiv.id = 'fav-item-' + id;

                // Only render the cum-count reset button when there is something to reset.
                let cumBtnHtml = '';
                if (cumCount > 0) {
                    cumBtnHtml = `
                        <button type="button" class="action-btn cum-reset-btn" id="cum-reset-btn-${id}" onclick="toggleResetCum('${id}')">
                            <span class="cum-text-normal">cum ${cumCount}x</span>
                            <span class="cum-text-hover">reset</span>
                        </button>
                    `;
                }

                itemDiv.innerHTML = `
                    <a href="/g/${id}" class="fav-item-link" id="fav-link-${id}">
                        <img src="${escapeHtml(cover)}" alt="cover" class="fav-cover-img" id="fav-img-${id}">
                        <div class="item-title fav-item-title" id="fav-title-${id}">${escapeHtml(title)}</div>
                    </a>
                    <div class="fav-btn-wrapper">
                        <button type="button" class="action-btn" id="fav-btn-${id}" onclick="toggleFav('${id}')">remove</button>
                        ${cumBtnHtml}
                    </div>
                `;

                favGrid.appendChild(itemDiv);
            });
        }

        async function loadFavoritesPage() {
            const username = getUserName();
            if (!window.isOrphanPage) {
                favHeading.textContent = 'favorites of ' + username;
                document.title = 'favorites of ' + username + ' - ' + window.siteName;
            } else {
                document.title = 'orphaned favorites - ' + window.siteName;
            }

            const favs = getStoredFavorites();
            if (!favs || favs.length === 0) {
                if (favGrid) favGrid.innerHTML = '';
                if (orphanList) orphanList.innerHTML = '';
                if (favCountRow) favCountRow.classList.add('hidden');
                if (orphanCountRow) orphanCountRow.classList.add('hidden');
                const sortContainer = document.getElementById('fav-sort-container');
                if (sortContainer) sortContainer.classList.add('hidden');
                favEmpty.classList.remove('hidden');
                return;
            }

            favEmpty.classList.add('hidden');
            const ids = [...new Set(favs.map(f => String(f.id)).filter(id => /^[0-9]{1,20}$/.test(id)))];

            try {
                // Keep large local lists usable without one unbounded request.
                const resolved = {};
                for (let start = 0; start < ids.length; start += 200) {
                    const res = await fetch('/api/favorites', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ids: ids.slice(start, start + 200) })
                    });
                    if (!res.ok) throw new Error('favorites request failed');
                    const json = await res.json();
                    if (!json || !json.galleries) throw new Error('invalid favorites response');
                    Object.assign(resolved, json.galleries);
                }
                currentGalleryData = resolved;
            } catch (e) {
                // Never classify favorites as orphaned from an incomplete response.
                favEmpty.textContent = 'favorites could not be loaded. please reload to retry.';
                favEmpty.classList.remove('hidden');
                return;
            }

            updateCounts();

            if (window.isOrphanPage) {
                orphanList.innerHTML = '';
                // Orphaned favorites are those whose IDs are absent from the server response
                // or where the server explicitly reports exists: false.
                const orphanedFavs = favs.filter(f => {
                    const id = String(f.id);
                    return !currentGalleryData[id] || !currentGalleryData[id].exists;
                });

                if (orphanedFavs.length === 0) {
                    favEmpty.classList.remove('hidden');
                    return;
                }

                orphanedFavs.forEach(f => {
                    const id = String(f.id);
                    const title = f.title || ('gallery ' + id);

                    itemsCache[id] = {
                        id: id,
                        title: title,
                        tags: f.tags || [],
                        artists: f.artists || [],
                        circles: f.circles || [],
                        parodies: f.parodies || [],
                        characters: f.characters || [],
                        language: f.language || '',
                        cum_count: parseInt(f.cum_count || 0, 10)
                    };

                    const rowDiv = document.createElement('div');
                    rowDiv.className = 'orphan-row';
                    rowDiv.id = 'orphan-row-' + id;

                    // Archive button is gated on window.canArchive (set by PHP based on auth/public flag).
                    const archiveBtnHtml = window.canArchive ? `<a href="/g/${id}"><button type="button" class="action-btn">archive</button></a>` : '';
                    const nhentaiBtnHtml = `<a href="https://nhentai.net/g/${id}/" target="_blank" rel="noreferrer"><button type="button" class="action-btn">nhentai</button></a>`;

                    rowDiv.innerHTML = `
                        <div class="orphan-id ui-text">#${escapeHtml(id)}</div>
                        <div class="orphan-title ui-text" id="orphan-title-${id}">${escapeHtml(title)}</div>
                        <div class="orphan-actions">
                            <button type="button" class="action-btn" id="orphan-btn-${id}" onclick="toggleOrphanFav('${id}')">remove</button>
                            ${archiveBtnHtml}
                            ${nhentaiBtnHtml}
                        </div>
                    `;

                    orphanList.appendChild(rowDiv);
                });
            } else {
                renderFavoritesGrid();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const sortSelect = document.getElementById('fav-sort-select');
            if (sortSelect) {
                sortSelect.addEventListener('change', function() {
                    renderFavoritesGrid();
                });
            }

            loadFavoritesPage();

            const input = document.getElementById('search-input');
            const resultsBox = document.getElementById('autocomplete-results');
            const topbar = document.getElementById('main-topbar');
            
            let debounceTimer;
            
            if (input && resultsBox && topbar) {
                // On narrow screens, expand topbar to full-width search mode.
                input.addEventListener('focus', function() {
                    if (window.innerWidth <= 600) {
                        topbar.classList.add('search-active');
                    }
                });
                
                // Delay hiding so clicks on suggestions register before blur fires.
                input.addEventListener('blur', function() {
                    setTimeout(() => {
                        resultsBox.style.display = 'none';
                        if (window.innerWidth <= 600) {
                            topbar.classList.remove('search-active');
                        }
                    }, 200);
                });

                input.addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    const query = this.value.trim();
                    // Require at least 2 characters before querying the autocomplete API.
                    if (query.length < 2) {
                        resultsBox.style.display = 'none';
                        return;
                    }
                    debounceTimer = setTimeout(() => {
                        fetch('/api/autocomplete?q=' + encodeURIComponent(query))
                            .then(res => res.json())
                            .then(data => {
                                resultsBox.innerHTML = '';
                                if (data.length > 0) {
                                    data.forEach(item => {
                                        const div = document.createElement('div');
                                        div.className = 'autocomplete-item';
                                        div.textContent = item;
                                        div.addEventListener('mousedown', function() {
                                            // API returns "category - value"; use the value part as the search term.
                                            const parts = item.split(' - ');
                                            const val = parts.length > 1 ? parts[1] : item;
                                            input.value = val;
                                            document.getElementById('search-form').submit();
                                        });
                                        resultsBox.appendChild(div);
                                    });
                                    resultsBox.style.display = 'block';
                                } else {
                                    resultsBox.style.display = 'none';
                                }
                            });
                    }, 150);
                });
            }
        });
    </script>
</body>
</html>
