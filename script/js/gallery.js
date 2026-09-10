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
// Keep thumbnail requests below the PHP-FPM worker limit. Native lazy-loading is
// intentionally avoided because images without an intrinsic height can leave the
// whole grid collapsed and never enter the browser's lazy-load area.
const MAX_CONCURRENT_THUMBNAILS = 4;
const THUMBNAIL_RETRY_DELAYS = [600, 1500];
const thumbnailQueue = [];
let activeThumbnailLoads = 0;

function pumpThumbnailQueue() {
    while (activeThumbnailLoads < MAX_CONCURRENT_THUMBNAILS && thumbnailQueue.length > 0) {
        const job = thumbnailQueue.shift();
        const retrySuffix = job.attempt > 0 ? '&retry=' + job.attempt : '';

        activeThumbnailLoads++;
        job.img.onload = function() {
            job.img.onload = null;
            job.img.onerror = null;
            activeThumbnailLoads--;
            pumpThumbnailQueue();
        };
        job.img.onerror = function() {
            job.img.onload = null;
            job.img.onerror = null;
            job.img.removeAttribute('src');
            job.img.removeAttribute('srcset');
            activeThumbnailLoads--;

            if (job.attempt < THUMBNAIL_RETRY_DELAYS.length) {
                const delay = THUMBNAIL_RETRY_DELAYS[job.attempt];
                window.setTimeout(function() {
                    thumbnailQueue.push({
                        img: job.img,
                        base: job.base,
                        attempt: job.attempt + 1
                    });
                    pumpThumbnailQueue();
                }, delay);
            } else {
                job.img.alt = 'page thumbnail failed to load';
            }

            pumpThumbnailQueue();
        };

        job.img.srcset = job.base + '&w=320' + retrySuffix + ' 320w, '
            + job.base + '&w=640' + retrySuffix + ' 640w';
        job.img.src = job.base + '&w=320' + retrySuffix;
    }
}

function queueThumbnail(img, base) {
    thumbnailQueue.push({ img: img, base: base, attempt: 0 });
    pumpThumbnailQueue();
}

function renderNextChunk() {
    // Cap the end index so we never exceed the total page count
    const end = Math.min(displayedCount + CHUNK_SIZE, window.allPages.length);
    for (let i = displayedCount; i < end; i++) {
        const a = document.createElement('a');
        // Link to reader at 1-based page index
        a.href = '/g/' + window.galleryId + '/' + (i + 1);
        
        const img = document.createElement('img');
        const pageNumber = i + 1;
        const thumbnailBase = '/thumbnail?id=' + encodeURIComponent(window.galleryId)
            + '&page=' + pageNumber + '&mode=fit';
        img.sizes = '(max-width: 768px) 50vw, 240px';
        img.decoding = 'async';
        
        a.appendChild(img);
        grid.appendChild(a);
        queueThumbnail(img, thumbnailBase);
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
    const feedbackEl = document.getElementById('gallery-feedback');

    if (!window.galleryId) return;

    let unfavConfirmState = false;
    let unfavTimeout;

    function showFeedback(text, duration = 4000) {
        if (!feedbackEl) return;
        feedbackEl.textContent = text || '';
        clearTimeout(unfavTimeout);
        if (text) {
            unfavTimeout = setTimeout(() => {
                feedbackEl.textContent = '';
                unfavConfirmState = false;
            }, duration);
        }
    }

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

    // Toggle favorite bookmark on/off (with warning confirmation if reading count > 0)
    if (favBtn) {
        favBtn.addEventListener('click', function() {
            let favs = getFavorites();
            const index = favs.findIndex(f => String(f.id) === String(window.galleryId));
            if (index > -1) {
                const item = favs[index];
                const cumCount = parseInt(item.cum_count || 0, 10);
                if (cumCount > 0 && !unfavConfirmState) {
                    unfavConfirmState = true;
                    showFeedback('unfavoriting will reset count, click again to confirm', 4000);
                    return;
                }
                unfavConfirmState = false;
                showFeedback('');
                favs.splice(index, 1);
            } else {
                unfavConfirmState = false;
                showFeedback('');
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
            unfavConfirmState = false;
            showFeedback('');
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

    // Admin delete button with 2-step confirmation pattern
    const deleteBtn = document.getElementById('delete-btn');
    let deleteConfirmState = false;
    let deleteTimeout;

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            unfavConfirmState = false;
            if (!deleteConfirmState) {
                deleteConfirmState = true;
                showFeedback('do you want to delete this gallery? click again to confirm', 4000);
                clearTimeout(deleteTimeout);
                deleteTimeout = setTimeout(() => {
                    deleteConfirmState = false;
                }, 4000);
            } else {
                deleteConfirmState = false;
                clearTimeout(deleteTimeout);
                showFeedback('deleting gallery...');
                // Submit only after the existing second-click confirmation.
                document.getElementById('delete-form').requestSubmit();
            }
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
