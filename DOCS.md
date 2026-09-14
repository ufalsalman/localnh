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


## Request mutation boundaries

bootstrap.php validates POST tokens before protected handlers act. Authenticated forms derive a token from the validated login session; anonymous forms use an expiring nonce signed with a key in the private session database. The nonce cookie alone cannot compute the token. Protected pages disable shared caching. Gallery deletion, public archive settings and logout additionally require admin authentication; preferences and pre-login forms do not. A missing gallery GET only offers confirmation, and its POST rechecks the current public/admin archive permission before starting Python. /api/favorites is a read-only POST lookup; downloads use temporary output files. Home sorting, favorites and reading state are browser preferences, not administrative mutations.


For external storage and the requirement to stop writers before snapshot export/import, see [STORAGE.md](STORAGE.md). Setting LOCALNH_DATA_DIR does not migrate an existing collection.

## Shared presentation helpers

`presentation.php` owns the site title, footer HTML, favicon link, theme variables and dominant-image color helpers. The front controller loads it with `require_once`; page templates may require it explicitly without redeclaring functions. RSS uses the same title helper. Loading this module does not open the collection/session database, authenticate a request, emit headers, or render a page. `storage.php` remains responsible for selecting and validating legacy/external data paths, while `bootstrap.php` owns authentication and CSRF.

Missing or blank title configuration falls back to `localnh`; missing/blank footer and favicon produce empty output. Relative favicon paths keep the existing root-relative normalization and HTML attribute escaping. Footer behavior remains the existing Markdown-link conversion followed by line-break conversion; it is not an HTML sanitizer and configured HTML is preserved. Themes retain the current default dark palette, browser blur preference for both anonymous and authenticated users, and optional gallery background override. Stage 6 consolidates these behaviors without changing authorization, routes, storage layout, or the renderer's input policy. Broader input/output security review remains separate work.

Update shared presentation behavior in this module instead of copying functions into individual templates. The presentation parity regression compares the previous and new image with synthetic data across both storage layouts, display configuration variants, theme/blur cookies, and authenticated views. Nondeterministic CSRF token values and RSS generation timestamps are normalized; other response content/status and relevant headers are compared directly.

## Login admission control

login_limits.php reserves a peer/global budget atomically in a dedicated private SQLite database using BEGIN IMMEDIATE. bootstrap.php calls it before credential verification, after the admin form's CSRF guard. Five failed/in-flight requests per peer and 60 total admissions per instance are allowed within independent 60-second fixed windows. A success refunds only its own peer reservation in the same window; it cannot clear concurrent failures or a later window. Rejected requests do not extend cooldown and return 429/Retry-After without sleeping. Successful verification still consumes the global budget. SQLite contention is bounded by a one-second busy timeout and failures use the existing 503 response.

Keys are canonical immediate-peer hashes, not submitted usernames or forwarding headers. Expiry cleanup plus the global admission cap bounds active state to 121 rows across a global-window boundary. No account-wide permanent lockout is used. Session revocation and CSRF logic remain separate. This is rate admission control, not a CPU-concurrency limit or comprehensive denial-of-service protection. See DEPLOYMENT.md for NAT/proxy tradeoffs, persistence and local recovery.

## Bounded web jobs

resource_jobs.php supplies nonblocking fixed slot locks, private ephemeral storage, a bounded oldest-first thumbnail cache, and the child supervisor protocol. export.php handles public GET exports and cheap HEAD metadata, validates format, obtains the export slot, and cleans its fixed workspace in finally. ZIP/CBZ generation moved out of PHP into script/py/web_export.py so CPU/file/address-space limits apply consistently. The existing PDF/HTML CLI implementations are unchanged.

script/py/resource_worker.py starts the requested child without a shell, in its own process group, with hard address-space, file-size and CPU limits. It applies a wall deadline and kills/reaps the process group on completion, timeout or handled termination. Standard streams go to files or /dev/null, avoiding unbounded PHP buffers and pipe deadlocks. PHP receives only exit success/failure and serves output after successful generation. HTTP export failures return 503 and remove partial output; unsupported formats return 400 and unsupported methods 405.

Thumbnail keys include source path, content hash, width, mode and renderer version. Sixteen fixed key-lock stripes suppress overlapping duplicate misses without unbounded lock-file growth, two slots cap generation, and a short metadata lock serializes lookup/publication/eviction. Hits are read while holding that metadata lock so eviction cannot unlink a file between lookup and read. A miss rechecks after key admission. Failed conversion or changed source is not cached. Derivatives are atomically renamed only after quota eviction. Source/image validation still uses the storage helper and the front controller blocks direct HTTP access to the new modules.

## Archive writer lifecycle

archive_jobs.php admits web archive/delete before response output using the same filesystem flock as Python archive_runner.acquire. archive_stream.php starts fetch.py with an inherited descriptor and consumes bounded JSON events with heartbeat/disconnect checks. Event strings are JSON-escaped and the title uses textContent. The confirmation page shares the localnh topbar and lowercase UI, with confirm followed by a white cancel button.

archive_runner.py validates the ID, rechecks existing rows after admission, owns the staging directory, and supervises one downloader process group for up to 900 seconds. The child keeps the inherited lock and Linux PR_SET_PDEATHSIG prevents it outliving a killed supervisor. The supervisor reaps the child before workspace cleanup. fetch.py reads upstream bodies incrementally with per-response and aggregate byte budgets, rejects incomplete page sets, converts inside staging, publishes complete files without replacing existing images, and commits SQLite before done. Web done events carry only the ID; CLI retains the detailed result. Failed publication may leave complete unreferenced files but never asks PHP to recursively delete a gallery. CLI delete participates in the archive lock; broader reader/export/delete consistency remains a separate concurrency concern.

## Collection aggregation and bounded lookup APIs

aggregation.php owns two atomic cache entries keyed by a collection revision plus SQLite header/file identity, with a 15-second fallback TTL. Hits do not spawn a builder or scan the collection. Misses use fixed nonblocking locks and resource_jobs.php to run script/py/aggregate.py with bounded time, memory and output. Publication is rejected if the collection key changes while building. The Python builder reads SQLite in a transaction; filesystem size remains a best-effort view during concurrent file changes. It preserves autocomplete group/insertion order and directory occurrence counts while deduplicating terms with maps rather than repeated linear searches. Directories, hard links and symlinks use apparent-size semantics without following symlink targets.

localnh_collection_changed in storage.php and collection_changed in script/py/storage.py publish a small atomic revision after successful collection writes. Archive, delete and snapshot import invoke the helper; failure to invalidate logs an error and relies on TTL instead of misreporting an already committed write as failed. The revision stays outside the image subtree exported by snapshots.

favorites_api.php validates a bounded JSON body before creating SQL placeholders and deduplicates at most 200 input IDs. favorites.php resolves longer local lists in sequential batches, committing the combined response only when every batch succeeds. autocomplete.php validates query type/length before loading the shared tag index and stops after ten matches. directory.php reuses category counts from the same index. All new PHP/Python source paths remain private behind the front controller.

## Web input and output boundaries

`request_validation.php` validates the scalar query/form fields consumed by the application before opening databases or sessions. Arrays, NUL bytes and values longer than 4096 bytes receive HTTP 400. Endpoint-specific limits (such as autocomplete's 128-byte query and the favorites JSON API's 8 KiB/200-ID limits) remain stricter where applicable. Unknown query keys remain compatible. CSRF token validation stays in the authorization guard and retains HTTP 403. Invalid browser display preferences fall back to existing defaults. This validation does not replace prepared SQL statements, authorization, CSRF or filesystem path checks.

Inline script data uses `localnh_script_json()` to escape HTML parser delimiters and substitute invalid UTF-8. HTML attributes are escaped independently, including Open Graph URLs derived from the request Host. Favorites validate saved IDs before generating links/handlers, and escape quotes in strings interpolated into HTML attributes; invalid saved entries are ignored during rendering without rewriting localStorage. The reader receives the original title as script data rather than an already HTML-escaped title.

Standalone HTML exports escape script JSON and substitute template placeholders in one pass. A title containing a closing script tag or text resembling a template placeholder remains literal display data. Export remains public. Host escaping prevents markup injection but does not make the request Host a trusted canonical origin. Site configuration files remain administrator-controlled: `config/footer` deliberately supports trusted raw HTML and is not an untrusted-user Markdown sanitizer. These output protections do not validate the upstream metadata schema or crawler destinations; those require the crawler audit.
