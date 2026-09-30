# Architecture

Reviewed 2026-09-30 at `5ff92cc`; the latest application change is `fdb9e8b` (2026-09-28). This is an implementation map; [DOCS.md](DOCS.md) retains the schema/API/security detail, [STORAGE.md](STORAGE.md) the recovery procedures, and [DECISIONS.md](DECISIONS.md) the reasons behind the boundaries.

## Components and boundaries

localnh is a self-hosted archive and reader. PHP renders pages and queries SQLite; Python downloads and processes images and performs collection maintenance. Plain JavaScript handles reader navigation, gallery previews, local favorites and profile backup. There is no framework, Composer/npm manifest, application server in Node, background queue service, or account-sync backend.

| Location | Responsibility |
| --- | --- |
| `index.php` | Front controller, schema creation, public assets, catalogue/search/sort, random redirect |
| `bootstrap.php`, `login_limits.php`, `request_validation.php` | Auth/session database, form tokens, cookie scheme, login budgets, request shape checks |
| `presentation.php` | Title/footer/favicon, theme variables, cover-derived color, script-safe JSON |
| `gallery.php`, `archive_stream.php` | Detail/recommendations, archive confirmation and progress, deletion, download selection |
| `reader.php`, `script/js/reader.js` | Page list and 1-based reader URLs, LTR/RTL keyboard/tap navigation |
| `favorites.php`, `favorites_api.php`, `user.php` | Browser favorites/profile, batched metadata lookup and JSON backup/restore |
| `settings.php`, `admin.php`, `rss.php` | Browser/admin settings, login, latest 50 archives as RSS |
| `directory.php`, `stats.php`, `autocomplete.php`, `aggregation.php` | Shared taxonomy/statistics cache and bounded lookups |
| `thumbnail.php`, `export.php`, `resource_jobs.php` | Validated image inputs, private job/cache slots, bounded Python/libvips work |
| `storage.php`, `script/py/storage.py` | Shared filesystem contract and explicit external initialization |
| `archive_jobs.php`, `collection_reads.php`; Python `archive_runner.py`, `collection_reads.py`, `resource_worker.py` | Admission, locks, worker lifetimes and resource limits |
| `script/py/fetch.py`, `crawler_validation.py` | Upstream validation, download, WebP conversion and database publication |
| Python `pdf.py`, `html.py`, `web_export.py` | PDF, standalone HTML and ZIP/CBZ generation |
| Python `snapshot.py`, `migrate_storage.py`, `delete.py`, `check.py`, `repair.py`, `stats.py` | Offline collection operations and CLI maintenance |
| `css/`, `script/js/`, `static/` | Authored browser assets; the default favicon file is not shipped |
| `build/caddy/` | Standard Caddy entry point plus Go dependency locks; required Docker build input |

## Request and archive flow

1. Caddy rewrites every request to `index.php`; the PHP development server must use the same router. Input validation runs before storage/database work. The selected `cache.sqlite` is opened and `galleries` is created if absent, then session and presentation bootstrap run. Even public asset requests traverse this bootstrap.
2. Routes include `/g/<id>`, `/g/<id>/<page>`, `/download/<id>`, `/directory`, `/favorites[/orphan]`, `/user`, `/settings`, `/admin`, `/stats`, `/rss`, `/random`, `/thumbnail`, `/api/autocomplete`, `/api/favorites`. Page templates depend on variables supplied by the front controller; they are not standalone public endpoints.
3. The asset branch permits CSS under `/css/`, JS under `/script/js/`, and known image extensions under `/static/` and `/cache/images/`. It checks decoded paths and resolved locations; it does not serve arbitrary existing files.
4. Catalogue pagination is 30 rows. Free-text search ANDs space-separated `LIKE` terms over title/language/tag JSON; there is no SQLite FTS index. Exact category filters use `json_each()` and case-insensitive equality (language uses its column). Only the first supplied category in the code's precedence order applies. Numeric/search-URL redirects currently recognize only 1–6 digit IDs, although archive validation supports up to 20.
5. A missing gallery GET offers confirmation when archiving is permitted and the token file exists. POST validates CSRF and current admin/public permission, then takes the archive writer lock before output. PHP streams HTML script events from newline-delimited Python JSON; this is not SSE or a queued job API.
6. `archive_runner.py` rechecks existence/cooldown, clears its private staging directory, and supervises `fetch.py`. The crawler validates metadata, accepts current `pages` or the legacy `images.pages` representation, and allows HTTPS requests only to the three configured upstream hosts with same-host redirects. It converts pages to WebP quality 85, publishes complete files using hard links without overwriting existing images, commits SQLite, invalidates aggregates, then emits `done`. Existing entries normally short-circuit; CLI `--fix` refreshes metadata while preserving existing images. Failure removes staging, not gallery directories.

Gallery recommendations are local weighted metadata overlap with random fallback/jitter, not a remote recommendation service. Full reader images come from stored logical paths. The browser reader changes `img.src` and replaces the current URL; it does not preload adjacent pages. The separate HTML export reader does preload the next page and has its own controls.

## Persistence and data flow

`galleries` is the only collection table: text ID/title/title_jp/language/scanlator, JSON text `tags`, `pages_paths`, `raw_api_data`, logical `cover_path`, and integer page_count/upload_date/created_at. The typed tags object uses `tags`, `artists`, `circles`, `parodies`, `characters`, `languages`; upstream `group` maps to `circles`. Raw metadata is retained for later use. Titles are stored as data and escaped at output, not sanitized on storage. There is no schema migration framework; the schema is repeated in PHP and Python initialization paths.

| State | Location / lifetime |
| --- | --- |
| Collection/configuration | Selected data root: `cache.sqlite`, `cache/images/<id>/cover.webp`, numbered pages, `config/`, `snapshot/` |
| Sessions and form signing key | `sessions.sqlite` in the selected session directory; token hashes, expiry, credential fingerprint, CSRF key |
| Login budgets | Separate `login-limits.sqlite` alongside sessions; survives restart |
| Collection coordination | `cache/archive.lock`, `cache/collection-read.lock`, `cache/archive-retry.json`, `cache/aggregation.version` |
| Derived thumbnails, aggregates, web exports | Private OS temp directory `localnh-jobs-<data-root-hash>`; disposable, not collection data |
| Profile/favorites/counters | Browser `localStorage`: `localnh_user`, `localnh_favorites`; not server-synced |
| Display preferences | Cookies for theme, dynamic color, blur, reading direction, home sort |

An empty/unset `LOCALNH_DATA_DIR` uses legacy source-relative data. A configured root must be absolute, disjoint from source, initialized with `.localnh-storage.json` version 1, and contain the expected directories/database. External validation rejects invalid roots, with one known parsing exception: PHP treats the literal string `0` as unset, while Python rejects it. The same mismatch affects `LOCALNH_SESSION_DIR`; see TASKS. PHP/Python resolvers are intended to agree. Logical database paths and public URLs remain unchanged between layouts.

`LOCALNH_SESSION_DIR` overrides the session path; otherwise it follows the data root. Docker explicitly defaults it to `/var/lib/localnh/sessions`. Collection snapshots contain only a SQLite backup plus chunked image tar data and a JSON manifest; import revokes sessions. The offline storage migrator instead copies configuration, collection, snapshots **and sessions**, verifies hashes/logical database dumps and ownership, and never switches deployment itself.

The browser favorites API sends IDs to the server to resolve archived metadata; profile names and counters are stored locally. Browser JSON backups contain profile, favorites and two theme preferences, not image/database data or admin sessions. See TASKS for restore limitations.

## Concurrency and bounded work

| Operation | Current coordination / bounds |
| --- | --- |
| Archive (web/CLI) | Exclusive nonblocking `archive.lock`; 900 s, 768 MiB address space, 1–1000 pages, 2 MiB metadata, 32 MiB per image, 512 MiB downloaded and converted totals |
| Delete/purge; snapshot import | Writer lock followed by exclusive `collection-read.lock` |
| Snapshot export | Writer lock; still requires stopped web/CLI activity |
| Thumbnail | Shared collection guard; two conversion slots, 15 s, 512 MiB, 8 MiB output, 32 MiB input, libvips concurrency 1 |
| Web export | Shared collection guard and one export slot; 120 s, 768 MiB, 512 MiB output; locks retained through response delivery |
| Cold stats/tag aggregate | Shared collection guard, one slot per kind; 15 s, 512 MiB, 8 MiB output; cache TTL 15 s |
| Native PDF/HTML and ZIP worker | Shared collection guard; direct CLI use lacks the web supervisor limits |

Slots and locks are advisory and nonblocking. They return retryable failures rather than queueing indefinitely. Thumbnail cache is capped at 64 MiB / 512 derivatives and keyed by source content and rendering options. `HEAD`/matching ETag avoids conversion. The gallery browser queues one preview at a time, retries twice, and renders batches of 30.

Supervisors use fixed argument arrays, process groups, resource limits and Linux parent-death signals. Lock descriptors reach supervisors and children so a killed PHP request does not prematurely free their inputs/workspace. This is not a sandbox for arbitrary executables. Temp job limits are local to that temp filesystem; they are not distributed quotas.

## Dependencies, deployment and compromises

Docker builds pinned Caddy 2.11.4 with Go 1.27.1, compiles PHP extensions in a PHP 8.3/Trixie builder, and installs hash-locked Python wheels (`curl_cffi`, Pillow and transitive dependencies). The final compatible runtime omits compilers and venv pip/setuptools. Caddy runs in the foreground and PHP-FPM as a daemon in the same container; source is copied into the image, while Compose mounts only persistent data. It publishes HTTP 8080 by default. HTTPS/tunnels are separate operator configuration, not mandatory services.

External connections are the nhentai metadata/image hosts, the operator's DoH resolver (Cloudflare by default), Google Fonts from CSS, and optionally external favicon/footer URLs. Reading archived images requires no upstream API call; “local storage” does not mean the browser never makes external font requests. `config/domain` names snapshot output; RSS/Open Graph use the incoming Host and direct HTTPS indicator, not that file or the auth proxy-scheme helper.

The flat include architecture shares globals and has duplicated page markup/inline scripts. SQLite plus filesystem publication is not one transaction: crashes can leave unreferenced images or interrupted deletion/import. Static image reads and some CLI tools do not participate in collection reader locks; snapshots/migrations still require maintenance downtime. PDF holds decoded images in memory; HTML embeds Base64 and can be large. Footer configuration is trusted HTML with simple link/newline conversion. `repair.py` is a separate, less-protected maintenance path; known gaps and other verified limitations are in [TASKS.md](TASKS.md).
