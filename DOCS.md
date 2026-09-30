# localnh Documentation

This document provides a technical overview of localnh, covering its architecture, database design, routing mechanisms, API endpoints, and client-side data management.

For the complete current module map and development workflow, see [ARCHITECTURE.md](ARCHITECTURE.md) and [DEVELOPMENT.md](DEVELOPMENT.md). Known implementation gaps are tracked in [TASKS.md](TASKS.md).

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
| `title` | `TEXT` | Primary English / Latin title, escaped when rendered |
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
| `/thumbnail?id=<id>&w=<width>` | `thumbnail.php` | On-demand WebP thumbnail (`320` or `640` px) with browser caching and a bounded private temporary derivative cache |
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
| `/api/autocomplete` | `autocomplete.php` via `index.php` | Live JSON autocomplete suggestions |
| `/api/favorites` | `favorites_api.php` via `index.php` | Batch validation of stored client favorite IDs against the database |

---

## Thumbnail Endpoint

The page-preview grid on `/g/<id>` uses `GET /thumbnail` so it does not load every full-resolution reader image. The endpoint accepts a numeric `id`, an optional reader-style `page` (`0` selects the cover), `w=320|640`, and `mode=cover|fit`. Gallery previews use `mode=fit` to preserve each page's complete aspect ratio without cropping. It generates WebP bytes through libvips, validates that the database path stays under `cache/images`, and uses a private one-day browser cache with ETag revalidation. Derivatives are written to a bounded private temporary cache outside collection data and snapshots, capped at 64 MiB and 512 files.

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

Users can back up or restore browser profile/favorites and the dark-mode/dynamic-color preferences from `/user` via JSON. This excludes admin sessions and collection images/database. Current array backups replace favorites on restore; the legacy object restore branch has a known persistence defect (see TASKS.md).

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

script/py/resource_worker.py starts the requested child without a shell, in its own process group, with hard address-space, file-size and CPU limits. It applies a wall deadline and kills/reaps the process group on completion, timeout or handled termination. Standard streams go to files or /dev/null, avoiding unbounded PHP buffers and pipe deadlocks. PHP receives only exit success/failure and serves output after successful generation. HTTP export failures return 503 and remove partial output; unsupported formats return 400 and unsupported methods 405. The download page keeps its hidden-iframe request alive and has no fixed navigation timer; its five-second status timeout only clears the message and cannot cancel a large export.

Thumbnail keys include source path, content hash, width, mode and renderer version. Sixteen fixed key-lock stripes suppress overlapping duplicate misses without unbounded lock-file growth, two slots cap generation, and a short metadata lock serializes lookup/publication/eviction. Hits are read while holding that metadata lock so eviction cannot unlink a file between lookup and read. A miss rechecks after key admission. Failed conversion or changed source is not cached. Derivatives are atomically renamed only after quota eviction. Source/image validation still uses the storage helper and the front controller blocks direct HTTP access to the new modules.

## Archive writer lifecycle

archive_jobs.php admits web archive/delete before response output using the same filesystem flock as Python archive_runner.acquire. archive_stream.php starts fetch.py with an inherited descriptor and consumes bounded JSON events with heartbeat/disconnect checks. Event strings are JSON-escaped and the title uses textContent. The confirmation page shares the localnh topbar and lowercase UI, with confirm followed by a white cancel button.

archive_runner.py validates the ID, rechecks existing rows after admission, owns the staging directory, and supervises one downloader process group for up to 900 seconds. The child keeps the inherited lock and Linux PR_SET_PDEATHSIG prevents it outliving a killed supervisor. The supervisor reaps the child before workspace cleanup. fetch.py reads upstream bodies incrementally with per-response and aggregate byte budgets, rejects incomplete page sets, converts inside staging, publishes complete files without replacing existing images, and commits SQLite before done. Web done events carry only the ID; CLI retains the detailed result. Failed publication may leave complete unreferenced files but never asks PHP to recursively delete a gallery. CLI delete participates in the archive lock and the exclusive collection reader guard; see “Coordinating collection operations” below for the current protocol and its limits.

## Collection aggregation and bounded lookup APIs

aggregation.php owns two atomic cache entries keyed by a collection revision plus SQLite header/file identity, with a 15-second fallback TTL. Hits do not spawn a builder or scan the collection. Misses use fixed nonblocking locks and resource_jobs.php to run script/py/aggregate.py with bounded time, memory and output. Publication is rejected if the collection key changes while building. The Python builder reads SQLite in a transaction; filesystem size remains a best-effort view during concurrent file changes. It preserves autocomplete group/insertion order and directory occurrence counts while deduplicating terms with maps rather than repeated linear searches. Directories, hard links and symlinks use apparent-size semantics without following symlink targets.

localnh_collection_changed in storage.php and collection_changed in script/py/storage.py publish a small atomic revision after successful collection writes. Archive, delete and snapshot import invoke the helper; failure to invalidate logs an error and relies on TTL instead of misreporting an already committed write as failed. The revision stays outside the image subtree exported by snapshots.

favorites_api.php validates a bounded JSON body before creating SQL placeholders and deduplicates at most 200 input IDs. favorites.php resolves longer local lists in sequential batches, committing the combined response only when every batch succeeds. autocomplete.php validates query type/length before loading the shared tag index and stops after ten matches. directory.php reuses category counts from the same index. All new PHP/Python source paths remain private behind the front controller.

## Web input and output boundaries

`request_validation.php` validates the scalar query/form fields consumed by the application before opening databases or sessions. Arrays, NUL bytes and values longer than 4096 bytes receive HTTP 400. Endpoint-specific limits (such as autocomplete's 128-byte query and the favorites JSON API's 8 KiB/200-ID limits) remain stricter where applicable. Unknown query keys remain compatible. CSRF token validation stays in the authorization guard and retains HTTP 403. Invalid browser display preferences fall back to existing defaults. This validation does not replace prepared SQL statements, authorization, CSRF or filesystem path checks.

Inline script data uses `localnh_script_json()` to escape HTML parser delimiters and substitute invalid UTF-8. HTML attributes are escaped independently, including Open Graph URLs derived from the request Host. Favorites validate saved IDs before generating links/handlers, and escape quotes in strings interpolated into HTML attributes; invalid saved entries are ignored during rendering without rewriting localStorage. The reader receives the original title as script data rather than an already HTML-escaped title.

Standalone HTML exports escape script JSON and substitute template placeholders in one pass. A title containing a closing script tag or text resembling a template placeholder remains literal display data. Export remains public. Host escaping prevents markup injection but does not make the request Host a trusted canonical origin. Site configuration files remain administrator-controlled: `config/footer` deliberately supports trusted raw HTML and is not an untrusted-user Markdown sanitizer. These output protections do not validate the upstream metadata schema or crawler destinations; those require the crawler audit.

## Crawler metadata and redirect policy

Before downloading any cover/page, `crawler_validation.py` validates the metadata object, optional matching gallery ID, title/tag/scanlator text, timestamp, media ID, cover and every page path. Display strings are limited to 4096 UTF-8 bytes, tags to 4096 entries, media IDs to 20 digits and pages to 1–1000. Optional absent fields keep existing defaults; malformed present fields fail the archive. Both v2 page paths and legacy `images.pages` formats are supported. Validation does not alter the stored raw API response or existing collection records.

Image paths must be relative paths with ASCII filename segments, without traversal, encoded path segments, backslashes, fragments, whitespace or another authority/scheme. Query strings are retained for signed CDN URLs. Requests use HTTPS on the exact hosts `nhentai.net`, `t.nhentai.net` and `i.nhentai.net`, with no URL credentials or nonstandard ports. Automatic redirects are disabled. At most three redirects are followed, each checked before sending and restricted to the initial hostname and HTTPS port 443. Cross-host/CDN redirects fail explicitly; supporting a future CDN requires a reviewed policy update rather than silently broadening network access. This host policy is not DNS/IP pinning: the operator's configured DNS/DoH resolver and outbound proxy/network remain trusted infrastructure.

Existing byte, memory, timeout, concurrency and 429 cooldown limits remain active. Each response and the session are closed on success/failure. Transport failures return a generic error instead of echoing potentially signed URLs or proxy details. There is no automatic retry of failed archive requests or bypass of upstream rate limits. Fixture tests use fake transports or an explicitly modified disposable loopback server; production code has no option to disable this URL policy.

## Coordinating collection operations

Archive, web/CLI deletion (including purge), and snapshot export/import share the exclusive nonblocking `cache/archive.lock`. Snapshots hold it from entry through validation, staging, backup and activation/cleanup; competing writers fail before creating a snapshot or changing collection files. CLI snapshot contention returns exit 75; web writer contention returns HTTP 503 with Retry-After. `--offline` remains mandatory and does not stop services automatically.

A separate `cache/collection-read.lock` protects long-running image readers. Web exports, thumbnail operations and cold aggregate builders take shared ownership; PDF/HTML/ZIP workers also protect their input reads, including direct PDF/HTML CLI use. Delete/purge and snapshot import need exclusive ownership before touching images or database rows. Readers may overlap each other. Archive of a new gallery retains its separate writer lock and does not block readers of existing images. Locks are nonblocking: a conflicting operation returns a retryable failure rather than waiting indefinitely. PHP passes reader descriptors into the bounded supervisor so terminating the PHP request cannot unlock images while its child continues processing. Web export conservatively retains its reader lock through response delivery.

Both lock files stay in the cache parent, outside the images directory replaced by import/purge. Do not remove lock files while the service is running, and run cooperating CLI commands as the application's filesystem user. This protocol coordinates operations on the same data root; it is not a distributed lock or an atomic filesystem/database transaction. Import still requires stopping web traffic and other CLI activity: ordinary HTML/static-file readers, external tools and login/session writers are not all participants. Failure or process death releases flock ownership, but a interrupted snapshot activation still requires the existing recovery procedure. Deletion interrupted between filesystem and database changes is likewise not made crash-atomic by these locks.

## Subprocess lifecycle

Web jobs and archive use argument arrays rather than shell command strings. `resource_worker.py` applies resource limits and a process group, and on Linux registers SIGKILL as the direct child's parent-death signal with a PPID race check. A killed supervisor therefore cannot leave its direct converter/exporter running without supervision. Explicit lock descriptors are inherited by that child as well as retained by the supervisor. Normal completion, timeout and catchable termination still kill/reap the process group. This is not a sandbox for arbitrary hostile executables: a descendant that deliberately detaches is outside this guarantee, and supervisor SIGKILL does not itself run process-group cleanup. The application launches fixed trusted programs, not user-supplied executables.

PHP serializes the job before spawning, sends the full payload even if a pipe write is partial, and closes/terminates the subprocess on exceptional setup failure. Killing PHP still lets the bounded supervisor finish while retaining its locks. The CLI wrapper uses exec for Python dispatch so native callers receive the utility's exit code and signals directly rather than leaving a shell waiting around it. Container root invocations continue to drop to the application user. Native CLI exports and the local stats/du traversal retain their existing uncapped local behavior; web time/memory/output limits remain separate.

## Dependency maintenance

`requirements.txt` records the direct application dependency versions. `requirements.lock` records the complete runtime dependency set with SHA-256 wheel hashes; `requirements-tools.lock` separately pins pip/setuptools used in the virtualenv. Docker installs both lock files with `--require-hashes --only-binary=:all:` and runs `pip check`. The base PHP image is pinned to a multi-platform image-index digest. These controls prevent silently selecting different Python packages or a different base image on rebuild; they do not make apt repositories immutable or replace security updates.

To update, review upstream releases/advisories, choose explicit versions, refresh all affected wheel hashes from the trusted package release metadata, update the direct requirements and both locks as needed, and review the diff. Update the base digest deliberately after inspecting the official PHP image. Rebuild and audit the resulting image, then run archive, export/thumbnail, authentication, storage and startup tests before deployment. Refresh apt package layers with a deliberate uncached build when checking distribution security updates; a cached apt layer will not update itself. Preserve the previous application image for rollback. Do not treat a clean scanner report as proof that an image is vulnerability-free.

Wheel-only installation fails explicitly when no matching wheel exists for the chosen Python/platform; it does not silently compile an unreviewed source distribution. Linux amd64 with the supplied PHP/Debian image is tested here; other architectures and native Python versions require their own installation/regression check. Hash lists include the release's published wheels, not a claim that every platform has been tested. The application still uses its virtualenv independently of Debian's system Python tooling.

References: [pip secure installs](https://pip.pypa.io/en/stable/topics/secure-installs/), [pip repeatable installs](https://pip.pypa.io/en/stable/topics/repeatable-installs/), and [Docker build best practices](https://docs.docker.com/build/building/best-practices/).

### Docker runtime tooling and Caddy

Docker builds the standard Caddy distribution from `build/caddy/main.go`, with Caddy and its transitive dependencies pinned in `go.mod` and verified against `go.sum`. The Go toolchain image is also pinned by digest. This is a local source build with reviewed dependency updates, not the unchanged upstream release binary. The build uses the standard modules, verifies module checksums, and refuses to rewrite the module lock while compiling. Review all lock changes when updating Caddy, its dependencies or the Go toolchain; test local HTTP, HTTPS origin certificate trust/persistence and reverse proxies afterward. No Cloudflare service is installed or required.

Separate builder stages compile PHP extensions and install the locked Python wheels. The runtime starts from the same PHP/Trixie ABI, installs explicit shared libraries, and receives only the compiled extensions, Python virtualenv and Caddy binary. PHP compilers/development headers and system pip/venv installation packages are not required in the final container. The application virtualenv checks dependencies before its pip/setuptools are uninstalled. Update dependencies by rebuilding the image rather than installing into a running container. Native installations retain their installation tools.

The runtime still needs libvips and its image codecs, SQLite, PHP/Python shared libraries and certificate support. Removing build tools does not prove these packages are free of vulnerabilities. Scan the final image, distinguish installed runtime components from build-time SBOM records, and record unresolved advisories instead of suppressing them. A distro migration is a separate compatibility change; do not copy extensions between different PHP or operating-system ABIs.

`VIPS_BLOCK_UNTRUSTED=1` is set in the container. This blocks libvips loaders marked untrusted, including OpenEXR, while the tested JPEG/PNG/WebP/GIF paths remain available. Keep this setting when changing image formats or the thumbnail command; adding a new format requires an explicit regression and security review. XML output is generated by localnh; it is not an XML upload/parser endpoint. The remaining libxml2 advisory therefore stays a distribution follow-up rather than being hidden as fixed.

Caddy 2.11.4 retains CEL 0.28.1 because CEL 0.29 changes its interpreter API. The reported [CEL struct-tag advisory](https://github.com/advisories/GHSA-gcjh-h69q-9w9g) concerns native type registration and attacker-supplied expressions; the supplied localnh Caddyfile does not use expressions, and this Caddy release does not invoke the affected native-type options. [GO-2026-5932](https://pkg.go.dev/vuln/GO-2026-5932) concerns the unmaintained OpenPGP subpackages, which are absent from the compiled dependency graph. These advisories are recorded rather than suppressed or described as patched; reevaluate them when changing Caddy, plugins, or custom configuration. Distribution libraries can have additional unresolved advisories, so continue checking the final image against current distribution security data.

## Repairing cached pages

`./localnh repair <id>` checks the stored page count, saved upstream page list, and physical files under `cache/images/<id>`. It downloads only missing WebP pages. Add `--overwrite` to deliberately replace existing files. Run it as the same host user that owns the CLI. If the container and host CLI use different users, configure ACLs on `cache/images` so the CLI can traverse directories and read/write files; otherwise permission-denied paths may appear incomplete to filesystem checks.
