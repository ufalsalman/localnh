from storage import data_root, data_path, image_path, gallery_path
#!/usr/bin/env python3
"""
localnh Standalone Single-File HTML Exporter
Embeds metadata, thumbnails, and all page WebP images as Base64 Data URIs into an autonomous offline HTML file.
Uses exact CSS and markup from localnh gallery and reader.
"""

import sys
import os
import sqlite3
import json
import base64
import re
from datetime import datetime

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
ROOT_DIR = os.path.dirname(os.path.dirname(SCRIPT_DIR))
DB_PATH = str(data_path('cache.sqlite'))
CSS_PATH = os.path.join(ROOT_DIR, 'css', 'style.css')

HTML_TEMPLATE = """<!DOCTYPE html>
<html>
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>__TITLE__ - __SITENAME__</title>
    <style>
        :root {
            --bg-color: #111;
            --text-color: #fff;
            --btn-bg: #fff;
            --btn-text: #111;
            --btn-hover-bg: #ddd;
            --btn-hover-border: #ddd;
            --input-bg: #111;
            --input-text: #fff;
            --input-border: #fff;
            --dots-color: #666;
            --cover-blur: 0px;
        }
        __EMBEDDED_CSS__
        
        /* View Switching & Offline adjustments */
        #view-reader { display: none; }
        .preview-grid a { cursor: pointer; }
        .cover a { cursor: pointer; }
        #viewer { user-select: none; -webkit-user-select: none; }
    </style>
</head>
<body>
    <main>
        <!-- GALLERY VIEW -->
        <div id="view-gallery">
            <div class='topbar'>
                <h1><a href='javascript:void(0)'>__SITENAME__</a></h1>
            </div>
            <div class='container'>
                <div class='cover'><a href='javascript:openReader(1)'><img src='__COVER_DATA__' alt='cover'></a></div>
                <div class='info'>
                    <h2>__TITLE__</h2>
                    <p><span class='copyable-id ui-text'>#__ID__</span></p>
                    __TAGS_HTML__
                    <p><b class='ui-text'>language</b>&nbsp;&nbsp;&nbsp;<span class='tag-item'>__LANGUAGE__</span></p>
                    <p><b class='ui-text'>pages</b>&nbsp;&nbsp;&nbsp;<span class='tag-item static'>__PAGE_COUNT__</span></p>
                    <p><b class='ui-text'>uploaded</b>&nbsp;&nbsp;&nbsp;<span class='tag-item static'>__UPLOAD_DATE__</span></p>
                    <p><b class='ui-text'>archived</b>&nbsp;&nbsp;&nbsp;<span class='tag-item static'>__ARCHIVED_DATE__</span></p>
                    <div class='action-btn-group wrap'>
                        <button class='action-btn' onclick='openReader(1)'>read</button>
                    </div>
                </div>
            </div>
            <div id='preview-container'>
                <div id='preview-grid' class='preview-grid'>
                    __PREVIEW_GRID_HTML__
                </div>
            </div>
        </div>

        <!-- READER VIEW -->
        <div id="view-reader">
            <div class='topbar reader-topbar'>
                <div class='reader-sitename'><a href='javascript:showGallery()'><b>__SITENAME__</b></a></div>
                <div class='reader-title'><a href='javascript:showGallery()'>__TITLE__</a></div>
            </div>
            <div id='viewer' onclick='handleViewerClick(event)'>
                <img id='manga-page' src='' alt='loading page...'>
            </div>
            <div class='controls'>
                <button id='prev' class='action-btn' onclick='prevPage()'>prev</button>
                <span id='page-indicator' class='ui-text'>1 of __PAGE_COUNT__</span>
                <button id='next' class='action-btn' onclick='nextPage()'>next</button>
            </div>
        </div>
    </main>

    <script>
        const pages = __PAGES_DATA_JSON__;
        const totalPages = pages.length;
        const galleryTitle = __GALLERY_TITLE_JSON__;
        const siteName = __SITENAME_JSON__;
        let currentPage = 1;

        function showGallery() {
            document.getElementById('view-gallery').style.display = 'block';
            document.getElementById('view-reader').style.display = 'none';
            document.title = `${galleryTitle} - ${siteName}`;
            history.replaceState(null, '', ' ');
            window.scrollTo(0, 0);
        }

        function openReader(pageNum) {
            pageNum = parseInt(pageNum, 10);
            if (isNaN(pageNum) || pageNum < 1) pageNum = 1;
            if (pageNum > totalPages) pageNum = totalPages;

            currentPage = pageNum;
            document.getElementById('view-gallery').style.display = 'none';
            document.getElementById('view-reader').style.display = 'block';
            updateReaderPage();
            window.location.hash = pageNum;
            window.scrollTo(0, 0);
        }

        function updateReaderPage() {
            const img = document.getElementById('manga-page');
            img.src = pages[currentPage - 1];
            document.getElementById('page-indicator').textContent = `${currentPage} of ${totalPages}`;
            document.title = `${galleryTitle} - page ${currentPage} - ${siteName}`;
            
            // Preload adjacent next page
            if (currentPage < totalPages) {
                const nextImg = new Image();
                nextImg.src = pages[currentPage];
            }
        }

        function prevPage() {
            if (currentPage > 1) {
                openReader(currentPage - 1);
            } else {
                showGallery();
            }
        }

        function nextPage() {
            if (currentPage < totalPages) {
                openReader(currentPage + 1);
            }
        }

        function handleViewerClick(e) {
            const rect = e.currentTarget.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            if (clickX < rect.width / 2) {
                prevPage();
            } else {
                nextPage();
            }
        }

        // Keyboard Navigation
        window.addEventListener('keydown', function(e) {
            const readerActive = (document.getElementById('view-reader').style.display === 'block');
            if (!readerActive) return;

            if (e.key === 'ArrowLeft' || e.key === 'a' || e.key === 'A') {
                prevPage();
            } else if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D' || e.key === ' ') {
                nextPage();
            } else if (e.key === 'Escape' || e.key === 'Backspace') {
                showGallery();
            }
        });

        // Hash routing on load
        window.addEventListener('DOMContentLoaded', () => {
            const hash = window.location.hash.replace('#', '');
            const pageNum = parseInt(hash, 10);
            if (!isNaN(pageNum) && pageNum >= 1 && pageNum <= totalPages) {
                openReader(pageNum);
            }
        });

        window.addEventListener('hashchange', () => {
            const hash = window.location.hash.replace('#', '');
            const pageNum = parseInt(hash, 10);
            if (!isNaN(pageNum) && pageNum >= 1 && pageNum <= totalPages) {
                openReader(pageNum);
            } else if (!hash) {
                showGallery();
            }
        });
    </script>
</body>
</html>
"""

def file_to_base64_uri(file_path):
    if not os.path.exists(file_path):
        return ""
    with open(file_path, "rb") as f:
        data = f.read()
    b64 = base64.b64encode(data).decode('utf-8')
    return f"data:image/webp;base64,{b64}"

def sanitize_filename(name):
    clean = re.sub(r'[\\/*?:"<>|]', "", name)
    return clean[:120].strip()

def get_site_name():
    title_path = str(data_path('config/title'))
    if os.path.exists(title_path):
        with open(title_path, 'r', encoding='utf-8') as f:
            c = f.read().strip()
            if c:
                return c
    return "localnh"

def get_css_content():
    if os.path.exists(CSS_PATH):
        with open(CSS_PATH, 'r', encoding='utf-8') as f:
            return f.read()
    return ""

def escape_html(text):
    if not text:
        return ""
    return str(text).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace('"', "&quot;").replace("'", "&#039;")

def script_json(value):
    """Encode data for a script element, including hostile closing tags."""
    return json.dumps(value).replace("&", "\\u0026").replace("<", "\\u003c").replace(">", "\\u003e")

from collection_reads import collection_reader

@collection_reader
def export_gallery(gallery_id, output_path=None):
    if not os.path.exists(DB_PATH):
        print(f"Error: Database not found at {DB_PATH}", file=sys.stderr)
        sys.exit(1)

    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()

    cursor.execute("SELECT * FROM galleries WHERE id = ?", (str(gallery_id),))
    row = cursor.fetchone()
    conn.close()

    if not row:
        print(f"Error: Gallery {gallery_id} not found in database.", file=sys.stderr)
        sys.exit(1)

    title = row['title'] or f"Gallery {gallery_id}"
    language = row['language'] or "unknown"
    page_count = row['page_count'] or 0
    upload_date_ts = row['upload_date']
    created_at_ts = row['created_at']

    upload_date = datetime.fromtimestamp(upload_date_ts).strftime('%Y-%m-%d') if upload_date_ts else "unknown"
    archived_date = datetime.fromtimestamp(created_at_ts).strftime('%Y-%m-%d') if created_at_ts else "unknown"

    print(f"Packaging gallery #{gallery_id}: {title}")
    print(f"Total pages: {page_count}")

    # Process Pages
    pages_paths = json.loads(row['pages_paths']) if row['pages_paths'] else []
    if not pages_paths and page_count > 0:
        # Fallback to scanning cache directory
        img_dir = str(gallery_path(gallery_id))
        if os.path.exists(img_dir):
            for i in range(1, page_count + 1):
                pages_paths.append(f"cache/images/{gallery_id}/{i}.webp")

    pages_b64 = []
    preview_grid_html_parts = []

    for idx, rel_path in enumerate(pages_paths, start=1):
        full_path = str(image_path(rel_path))
        print(f"\rEmbedding page {idx}/{len(pages_paths)}...", end="", flush=True)
        b64_uri = file_to_base64_uri(full_path)
        if not b64_uri:
            print(f"\nWarning: Page image missing at {full_path}", file=sys.stderr)
            b64_uri = "data:image/webp;base64,"
        pages_b64.append(b64_uri)
        preview_grid_html_parts.append(
            f'<a href="javascript:openReader({idx})">'
            f'<img src="{b64_uri}" alt="page {idx}" loading="lazy">'
            f'</a>'
        )

    print("\nFinished embedding pages.")

    # Process Cover
    cover_rel_path = row['cover_path'] or (pages_paths[0] if pages_paths else "")
    cover_full_path = str(image_path(cover_rel_path))
    cover_b64 = file_to_base64_uri(cover_full_path) if os.path.exists(cover_full_path) else (pages_b64[0] if pages_b64 else "")

    # Process Tags
    tags_data = {}
    if row['tags']:
        try:
            tags_data = json.loads(row['tags'])
            if isinstance(tags_data, list):
                tags_data = {'tags': tags_data}
        except Exception:
            pass

    tags_html_parts = []
    categories = ['parodies', 'characters', 'tags', 'artists', 'groups']
    
    for cat in categories:
        items = tags_data.get(cat, [])
        if items:
            pills = "".join([f'<span class="tag-item">{escape_html(item)}</span>' for item in items])
            tags_html_parts.append(f'<p><b class="ui-text">{cat}</b>&nbsp;&nbsp;&nbsp;{pills}</p>')

    tags_html = "\n".join(tags_html_parts)

    site_name = get_site_name()
    css_content = get_css_content()

    # Substitute only original template tokens, never tokens contained in user text.
    replacements = {
        '__EMBEDDED_CSS__': css_content,
        '__TITLE__': escape_html(title),
        '__ID__': escape_html(gallery_id),
        '__SITENAME__': escape_html(site_name),
        '__COVER_DATA__': cover_b64,
        '__TAGS_HTML__': tags_html,
        '__LANGUAGE__': escape_html(language),
        '__PAGE_COUNT__': str(len(pages_b64)),
        '__UPLOAD_DATE__': escape_html(upload_date),
        '__ARCHIVED_DATE__': escape_html(archived_date),
        '__PREVIEW_GRID_HTML__': "\n".join(preview_grid_html_parts),
        '__PAGES_DATA_JSON__': script_json(pages_b64),
        '__GALLERY_TITLE_JSON__': script_json(title),
        '__SITENAME_JSON__': script_json(site_name),
    }
    html_content = re.sub(r'__[A-Z_]+__', lambda match: replacements.get(match.group(), match.group()), HTML_TEMPLATE)

    if not output_path:
        sanitized_title = sanitize_filename(title)
        output_filename = f"{gallery_id} - {sanitized_title}.html"
        output_path = os.path.join(os.getcwd(), output_filename)

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(html_content)

    file_size_mb = os.path.getsize(output_path) / (1024 * 1024)
    print(f"Export successful!")
    print(f"Output: {output_path}")
    print(f"File size: {file_size_mb:.2f} MB")
    return output_path

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("localnh Standalone HTML Exporter")
        print("Usage: python3 html.py <gallery_id> <output_file.html>")
        sys.exit(1)

    target_id = sys.argv[1].strip()
    out_file = sys.argv[2].strip() if len(sys.argv) > 2 else None
    try: export_gallery(target_id, out_file)
    except BlockingIOError:
        print("Collection images are in use. Retry shortly.", file=sys.stderr)
        sys.exit(75)
