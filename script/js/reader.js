/**
 * Manga Reader Controller
 * Handles image rendering, keyboard/touch navigation, RTL manga reading mode, and dynamic URL updates.
 */

// Normalize initial page index from URL parameter (1-based to 0-based)
let currentIndex = (window.initialPage && window.initialPage >= 1 && window.initialPage <= window.pages.length) ? window.initialPage - 1 : 0;
const img = document.getElementById('manga-page');
const indicator = document.getElementById('page-indicator');

// Automatically keep the reader viewer centered on the screen upon image load or page change
function centerViewer() {
    document.getElementById('viewer').scrollIntoView({ behavior: 'instant', block: 'center' });
}

if (img) {
    img.onload = centerViewer;
}

// Update the displayed image, counter, document title, and push history state without reloading
function update() {
    img.src = window.pages[currentIndex];
    indicator.innerText = (currentIndex + 1) + ' of ' + window.pages.length;
    document.title = window.galleryTitle + ' - page ' + (currentIndex + 1) + ' - ' + window.siteName;
    history.replaceState(null, '', '/g/' + window.galleryId + '/' + (currentIndex + 1));
    centerViewer();
}

function next() {
    if (currentIndex < window.pages.length - 1) {
        currentIndex++;
        update();
    }
}

function prev() {
    if (currentIndex > 0) {
        currentIndex--;
        update();
    }
}

const nextBtn = document.getElementById('next');
const prevBtn = document.getElementById('prev');

if (nextBtn) nextBtn.onclick = next;
if (prevBtn) prevBtn.onclick = prev;

// Helper to read client-side cookie values
function getCookie(name) {
    const value = '; ' + document.cookie;
    const parts = value.split('; ' + name + '=');
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
}

// Check whether RTL (Right-to-Left manga order) mode is active
function isRtlMode() {
    if (typeof window.pageOrder === 'string') {
        return window.pageOrder === 'rtl';
    }
    return (getCookie('flip_page_order') === 'rtl');
}

// Handle tap/click navigation: splits viewer into left and right interactive click zones
function handleTap(e) {
    const isRtl = isRtlMode();
    const target = img || document.getElementById('viewer');
    if (!target) return;
    const rect = target.getBoundingClientRect();
    const isLeft = (e.clientX - rect.left < rect.width / 2);

    if (isRtl) {
        // In RTL mode, clicking left advances forward, clicking right moves back
        if (isLeft) {
            next();
        } else {
            prev();
        }
    } else {
        // In LTR mode, clicking left moves back, clicking right advances forward
        if (isLeft) {
            prev();
        } else {
            next();
        }
    }
}

if (img) {
    img.onclick = handleTap;
}

const viewer = document.getElementById('viewer');
if (viewer) {
    viewer.onclick = (e) => {
        if (e.target !== img) {
            handleTap(e);
        }
    };
}

// Keyboard arrow navigation supporting both LTR and RTL reading direction
window.addEventListener('keydown', (e) => {
    const isRtl = isRtlMode();
    if (isRtl) {
        if (e.key === 'ArrowLeft') next();
        if (e.key === 'ArrowRight') prev();
    } else {
        if (e.key === 'ArrowRight') next();
        if (e.key === 'ArrowLeft') prev();
    }
});

// Render the initial page on load
if (window.pages && window.pages.length > 0) {
    update();
}

