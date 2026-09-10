# localnh Documentation

This document provides a technical overview of localnh, covering its architecture, database design, routing mechanisms, API endpoints, and client-side data management.

For installation topology, TLS origins, proxy trust, and operational rollback, see [DEPLOYMENT.md](DEPLOYMENT.md). Login session behavior is documented in [README.md](README.md#login-sessions).

---

## Architecture Overview

```
localnh/
├── index.php             # Main entry point & front controller router
├── gallery.php           # Gallery overview, metadata display, and recommendation engine
├── reader.php            # Distraction-free manga reader interface
├── directory.php         # Taxonomy index and category browsing
├── favorites.php         # User favorites and orphaned bookmarks manager
├── stats.php             # Library statistics (titles, storage, latest activity)
├── settings.php          # Cookie-based settings and admin configuration
├── user.php              # Client session backup, restore, and user profile management
├── bootstrap.php         # Shared server-side sessions and request scheme validation
├── admin.php             # Admin authentication portal
├── rss.php               # RSS 2.0 feed generator
├── localnh               # Headless CLI utility for management tasks
├── Caddyfile             # Sample web server reverse-proxy configuration
│
├── config.example/       # Template configuration files
│   ├── title             # Site title
│   ├── domain            # Domain name
│   ├── token             # API Bearer token
│   ├── credentials       # Admin username and password hash
│   ├── favicon           # Favicon path
│   ├── footer            # Markdown footer content
│   ├── public            # Public archive permission switch
│   └── doh               # Custom DoH endpoint
│
├── css/
│   └── style.css         # Centralized stylesheet (Dark/Light/Responsive)
│
├── script/
│   ├── js/
│   │   ├── gallery.js    # Gallery preview chunking, favorites & copy actions
│   │   └── reader.js     # Manga reader controls, navigation & RTL support
│   └── py/
│       ├── fetch.py      # Core crawler & image downloader (curl_cffi + Pillow)
│       ├── pdf.py        # PDF compilation utility
│       ├── delete.py     # Gallery & cache deletion tool
│       ├── stats.py      # Terminal library statistics utility
│       └── snapshot.py   # Full library export/import tool
│
├── static/               # Static assets directory
│
├── cache/
│   └── images/           # Converted WebP cover and page storage
│       └── <gallery_id>/ # Individual gallery image folders
│
└── snapshot/             # Target directory for database and cache snapshots
```

---

## Database Schema

localnh uses **SQLite 3** via PHP PDO. The database is initialized automatically in `cache.sqlite` with the following structure:

### Table: `galleries`

| Column | Type | Description |
| :--- | :--- | :--- |
| `id` | `TEXT PRIMARY KEY` | nhentai gallery ID (numeric string) |
| `title` | `TEXT` | Primary English / Latin sanitized title |
| `title_jp` | `TEXT` | Native Japanese title (if available) |
| `language` | `TEXT` | Primary language (`japanese`, `english`, etc.) |
| `scanlator` | `TEXT` | Scanlation group or uploader name |
| `tags` | `TEXT` | JSON object containing categorized tags array |
| `cover_path` | `TEXT` | Relative path to local cover WebP (`cache/images/<id>/cover.webp`) |
| `pages_paths` | `TEXT` | JSON array of relative page paths (`["cache/images/<id>/1.webp", ...]`) |
| `page_count` | `INTEGER` | Total number of pages |
| `upload_date` | `INTEGER` | Unix timestamp of the original upload date on nhentai |
| `created_at` | `INTEGER` | Unix timestamp of when the gallery was archived locally |
| `raw_api_data` | `TEXT` | Full original JSON response from the nhentai API |

#### Example `tags` Column Structure:
```json
{
  "tags": ["big breasts", "sole female", "swimsuit"],
  "artists": ["artist_name"],
  "circles": ["circle_name"],
  "parodies": ["original"],
  "characters": ["character_name"],
  "languages": ["japanese", "translated"]
}
```

---

## Routing & URL Structure

All incoming HTTP requests are processed through `index.php`:

| URL Pattern | Handler | Purpose |
| :--- | :--- | :--- |
| `/` | `index.php` | Main catalogue list, search (`?q=`), and metadata filters (`?a=`, `?t=`, etc.) |
| `/thumbnail?id=<id>&w=<width>` | `thumbnail.php` | On-demand WebP thumbnail (`320` or `640` px) with browser caching; no derivative file is stored |
| `/g/<id>` | `gallery.php` | Gallery detail page and dynamic live download streamer |
| `/g/<id>/<page>` | `reader.php` | Dedicated page viewer |
| `/download/<id>` | `gallery.php` | Download format selector (ZIP / CBZ / PDF / HTML) |
| `/directory` | `directory.php` | Directory listing by category (`?cat=tags`, `?cat=artists`, etc.) |
| `/favorites` | `favorites.php` | Local favorites and bookmark grid |
| `/favorites/orphan` | `favorites.php` | Orphaned favorites detector and recovery tools |
| `/rss` | `rss.php` | RSS 2.0 XML feed of the latest 50 archives |
| `/random` | `index.php` | Redirects to a randomly chosen local gallery |
| `/user` | `user.php` | Local session manager, JSON export/import, profile name |
| `/stats` | `stats.php` | Library statistics |
| `/settings` | `settings.php` | Display preferences (theme, reading order) and admin toggles |
| `/admin` | `admin.php` | Admin authentication login |
| `/api/autocomplete` | `index.php` | Live JSON autocomplete suggestions |
| `/api/favorites` | `index.php` | Batch validation of stored client favorite IDs against the database |

---

## Thumbnail Endpoint

The page-preview grid on `/g/<id>` uses `GET /thumbnail` so it does not load every full-resolution reader image. The endpoint accepts a numeric `id`, an optional reader-style `page` (`0` selects the cover), `w=320|640`, and `mode=cover|fit`. Gallery previews use `mode=fit` to preserve each page's complete aspect ratio without cropping. It generates WebP bytes through libvips, validates that the database path stays under `cache/images`, and uses a private one-day browser cache with ETag revalidation. Generated thumbnails are never written to disk.

---

## API Endpoints

### 1. Autocomplete Suggestions
- **Endpoint**: `GET /api/autocomplete?q=<query>`
- **Response**: `application/json`
```json
[
  "artist - name",
  "tag - name",
  "character - name",
  "parody - name",
  "circle - name",
  "language - english"
]
```

### 2. Batch Favorites Validation
- **Endpoint**: `POST /api/favorites`
- **Request Body**: `{"ids": ["12345", "67890"]}`
- **Response**:
```json
{
  "galleries": {
    "12345": {
      "id": "12345",
      "title": "Gallery Title",
      "cover_path": "/cache/images/12345/cover.webp",
      "exists": true
    }
  }
}
```

---

## Crawler & Scraper Engine (`fetch.py`)

The scraper uses **`curl_cffi`** with Chrome browser impersonation to fetch metadata and images without Cloudflare/TLS blocks:

1. **DNS-over-HTTPS (DoH)**: Resolved via `https://1.1.1.1/dns-query` (configurable in `config/doh`).
2. **Streaming Response**: Real-time progress output formatted as line-delimited JSON (`meta`, `progress`, `done`, `error`) piped into PHP `proc_open` in `gallery.php`.
3. **Format Conversion**: All images are downloaded in memory and normalized into optimized `.webp` format using `Pillow`.

---

## Client-Side Storage & Cookies

### 1. `localStorage` Keys
localnh stores user data on the client device to avoid requiring tracking accounts or server databases for personal data:

| Key | Format | Description |
| :--- | :--- | :--- |
| `localnh_user` | JSON Object | `{"username": "...", "updated_at": "..."}` |
| `localnh_favorites` | JSON Array | Array of bookmark objects containing metadata and `cum_count` |

Users can back up or restore their full session at any time from the `/user` page via JSON snapshots.

### 2. Browser Preference Cookies

| Cookie | Values | Default | Description |
| :--- | :--- | :--- | :--- |
| `dark_mode` | `on` / `off` | `on` | Dark theme mode |
| `dynamic_color` | `on` / `off` | `on` | Gallery background dynamic tinting |
| `flip_page_order` | `ltr` / `rtl` | `ltr` | Reader page order direction |
| `blur_covers` | `on` / `off` | `off` | Privacy thumbnail blurring |
| `home_sort` | `na` / `nu` | `na` | Persistent homepage sorting (`newest - archived` or `newest - uploaded`) |
