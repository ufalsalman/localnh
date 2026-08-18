/**
 * Gallery Preview & Action Controller
 * Handles thumbnail pagination chunks, local favorites bookmarking, reading counters, and clipboard actions.
 */

const grid = document.getElementById('preview-grid');
const showMoreWrapper = document.getElementById('show-more-wrapper');
const showMoreBtn = document.getElementById('show-more-btn');
let displayedCount = 0;
// Render pages in batches to avoid freezing the browser when a gallery has hundreds of images
const CHUNK_SIZE = 30;

function renderNextChunk() {
    // Cap the end index so we never exceed the total page count
    const end = Math.min(displayedCount + CHUNK_SIZE, window.allPages.length);
    for (let i = displayedCount; i < end; i++) {
        const a = document.createElement('a');
        // Link to reader at 1-based page index
        a.href = '/g/' + window.galleryId + '/' + (i + 1);
        
        const img = document.createElement('img');
        img.src = window.allPages[i];
        // Native lazy-loading defers off-screen image fetches
        img.loading = 'lazy';
        
        a.appendChild(img);
        grid.appendChild(a);
    }
    displayedCount = end;
    
    // Toggle "show more" button visibility based on remaining items
    if (displayedCount < window.allPages.length) {
        showMoreWrapper.classList.remove('hidden');
    } else {
        showMoreWrapper.classList.add('hidden');
    }
}

// Initial chunk render
if (window.allPages && window.allPages.length > 0) {
    renderNextChunk();
}
if (showMoreBtn) {
    showMoreBtn.addEventListener('click', renderNextChunk);
}

/**
 * Local Favorites & Cum Counter Manager
 * Synchronizes user bookmarks and read/cum counts locally via browser localStorage.
 */
(function() {
    const STORAGE_KEY_FAVS = 'localnh_favorites';
    const favBtn = document.getElementById('fav-btn');
    const cumBtn = document.getElementById('cum-btn');

    if (!window.galleryId) return;

    // Retrieve and parse favorites array, supporting migration from legacy key-value format
    function getFavorites() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY_FAVS);
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) return parsed;
            if (typeof parsed === 'object' && parsed !== null) {
                return Object.keys(parsed).map(id => ({
                    id: String(id),
                    title: typeof parsed[id] === 'string' ? parsed[id] : (parsed[id].title || ''),
                    added_at: Date.now(),
                    cum_count: 0
                }));
            }
            return [];
        } catch (e) {
            return [];
        }
    }

    function saveFavorites(favs) {
        try {
            localStorage.setItem(STORAGE_KEY_FAVS, JSON.stringify(favs));
        } catch (e) {}
    }

    // Refresh UI button labels based on stored favorite state
    function updateButtons() {
        const favs = getFavorites();
        const item = favs.find(f => String(f.id) === String(window.galleryId));
        if (favBtn) {
            favBtn.textContent = item ? 'favorited' : 'favorite';
        }
        if (cumBtn) {
            const count = item ? parseInt(item.cum_count || 0, 10) : 0;
            cumBtn.textContent = count > 0 ? ('cummed ' + count + 'x') : 'cum';
        }
    }

    // Toggle favorite bookmark on/off
    if (favBtn) {
        favBtn.addEventListener('click', function() {
            let favs = getFavorites();
            const index = favs.findIndex(f => String(f.id) === String(window.galleryId));
            if (index > -1) {
                favs.splice(index, 1);
            } else {
                const tax = window.galleryTaxonomy || {};
                favs.unshift({
                    id: String(window.galleryId),
                    title: window.galleryTitle || '',
                    tags: Array.isArray(tax.tags) ? tax.tags : [],
                    artists: Array.isArray(tax.artists) ? tax.artists : [],
                    circles: Array.isArray(tax.circles) ? tax.circles : [],
                    parodies: Array.isArray(tax.parodies) ? tax.parodies : [],
                    characters: Array.isArray(tax.characters) ? tax.characters : [],
                    language: tax.language || '',
                    added_at: Date.now(),
                    cum_count: 0
                });
            }
            saveFavorites(favs);
            updateButtons();
        });
    }

    // Increment cum reading counter
    if (cumBtn) {
        cumBtn.addEventListener('click', function() {
            let favs = getFavorites();
            const index = favs.findIndex(f => String(f.id) === String(window.galleryId));
            if (index > -1) {
                const currentCount = parseInt(favs[index].cum_count || 0, 10);
                favs[index].cum_count = currentCount + 1;
            } else {
                const tax = window.galleryTaxonomy || {};
                favs.unshift({
                    id: String(window.galleryId),
                    title: window.galleryTitle || '',
                    tags: Array.isArray(tax.tags) ? tax.tags : [],
                    artists: Array.isArray(tax.artists) ? tax.artists : [],
                    circles: Array.isArray(tax.circles) ? tax.circles : [],
                    parodies: Array.isArray(tax.parodies) ? tax.parodies : [],
                    characters: Array.isArray(tax.characters) ? tax.characters : [],
                    language: tax.language || '',
                    added_at: Date.now(),
                    cum_count: 1
                });
            }
            saveFavorites(favs);
            updateButtons();
        });
    }

    updateButtons();
})();

/**
 * Copy Gallery ID Helper
 * Copies gallery ID to clipboard with standard navigator.clipboard and execCommand fallback.
 */
(function() {
    const copyBtn = document.getElementById('copy-id-btn');
    const copyFeedback = document.getElementById('copy-feedback');
    if (!copyBtn || !window.galleryId) return;

    let copyTimer;
    copyBtn.addEventListener('click', function() {
        const rawId = String(window.galleryId);
        const onCopied = () => {
            if (copyFeedback) {
                copyFeedback.textContent = 'copied!';
                copyFeedback.classList.remove('hidden');
                clearTimeout(copyTimer);
                copyTimer = setTimeout(() => {
                    copyFeedback.classList.add('hidden');
                }, 1500);
            }
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(rawId).then(onCopied).catch(() => {
                const tempInput = document.createElement('input');
                tempInput.value = rawId;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                onCopied();
            });
        } else {
            const tempInput = document.createElement('input');
            tempInput.value = rawId;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            onCopied();
        }
    });
})();

