# localnh

A lightweight, self-hosted personal archiver, reader, and gallery manager for [nhentai](https://nhentai.net).

Built with simplicity, speed, and privacy in mind. Written in vanilla PHP, SQLite, pure JavaScript, and Python.

---

## Features

- **Distraction-Free Reader**
  - Instant image viewing with keyboard navigation (Arrow keys / Tap left-right).
  - RTL (Right-to-Left / Manga style) and LTR (Left-to-Right) reading direction toggles.
  - Automatic viewport centering on page turns.
- **Minimalist Theme & Dark Mode**
  - Dark mode by default with light mode toggle.
  - Minimalist, distraction-free aesthetic with Plus Jakarta Sans typography.
- **Targeted Metadata & Exact Filtering**
  - Dedicated pages with exact matching for:
    - **Artists** (`/?a=artist_name`)
    - **Tags** (`/?t=tag_name`)
    - **Characters** (`/?char=character_name`)
    - **Parodies** (`/?p=parody_name`)
    - **Circles/Groups** (`/?c=circle_name`)
    - **Languages** (`/?l=language_name`)
  - Keyword search across titles, language, and tags, with autocomplete suggestions.
  - Quick external links to nhentai search directly from filter headers.
- **Multi-Format Export**
  - Export and download galleries as **ZIP**, **CBZ** (Comic Book Archive), **PDF** (rendered with Pillow), or standalone **HTML** (self-contained offline web reader with embedded WebP Base64).
- **Local Storage & Privacy**
  - Browser profile, reading count ("cum count"), and favorites stored in `localStorage`; admin login sessions are validated on the server.
  - JSON backup and restore transfers browser profile/favorites and theme preferences without cloud accounts. Use CLI snapshots to back up the archived database and images.
- **Built-in Anti-Censorship & DoH**
  - Automatic DNS over HTTPS (DoH) support via Cloudflare (`1.1.1.1`) or custom resolvers to bypass ISP DNS poisoning.
  - Browser TLS fingerprint impersonation (`curl_cffi`) for reliable metadata and media downloads.
- **RSS 2.0 Feed**
  - RSS feed of the latest 50 archived entries available at `/rss`.
- **Powerful CLI Utility**
  - Manage archives, export snapshots, convert to PDF, and generate standalone HTML directly from terminal using `./localnh`.

---

## Requirements

- **Linux** for the archive and web-job supervisors (also provided by Docker).
- **PHP 8.1+** with extensions/features:
  - `pdo_sqlite`
  - `gd`
  - `zip`
  - `fileinfo`, `posix`, and enabled `proc_open`
  - GD WebP support and SQLite JSON functions
- **Python 3.10+** with `venv` and compatible wheels for the locked dependencies.
- **libvips tools** at `/usr/bin/vips` for thumbnails; web supervision also uses `/usr/bin/python3`.
- **Web Server** (any of the following):
  - [Caddy](https://caddyserver.com) (Recommended)
  - Nginx + PHP-FPM
  - Apache + PHP
  - PHP Built-in development server

---

## Quick Start

### Option A: Using Docker Compose (Recommended)

1. Clone the repository:
   ```bash
   git clone https://tangled.org/ufal.my.id/localnh.git
   cd localnh
   ```

2. Initialize configuration and database file:
   ```bash
   cp -r config.example config
   touch cache.sqlite
   ```
Add your nhentai API token to `config/token`. Configure `config/credentials` with your admin username and password hash as shown under native setup; the example credentials are placeholders and public archiving defaults to off.

3. Start the container:
   ```bash
   docker compose up -d
   ```
Open `http://localhost:8080` in your browser.

4. Using CLI via Docker:
   ```bash
   docker compose exec localnh ./localnh <gallery_id>
   ```

---

### Option B: Native Setup (Bare-metal / VPS)

1. Clone the repository:
   ```bash
   git clone https://tangled.org/ufal.my.id/localnh.git
   cd localnh
   ```

2. Set up Python Virtual Environment:
   ```bash
   python3 -m venv venv
   ./venv/bin/pip install --require-hashes --only-binary=:all: -r requirements-tools.lock
   ./venv/bin/pip install --require-hashes --only-binary=:all: -r requirements.lock
   ./venv/bin/pip check
   ```

3. Initialize Configuration:
   ```bash
   cp -r config.example config
   ```
Configure your credentials and nhentai API token:
   - **`config/token`**: Paste your nhentai API bearer token.
   - **`config/credentials`**: Set your admin username and bcrypt password hash:
     ```bash
     # Generate bcrypt hash:
     php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT) . PHP_EOL;"
     ```
     Edit `config/credentials`:
     ```ini
     USER=admin
     PASS=$2y$10$YOUR_BCRYPT_HASH_HERE
     ```

4. Start the Web Server:
   - **Using Caddy**:
     The supplied Caddyfile targets the Docker layout (`/var/www/html` and PHP-FPM at `127.0.0.1:9000`). For native use, adapt the root/FPM listener and start PHP-FPM first, preserving routing through `index.php`, then run:
     ```bash
     caddy run
     ```
   - **Using PHP Built-in Server (Development)**:
     ```bash
     php -S 127.0.0.1:8080 index.php
     ```
Open `http://localhost:8080` in your browser.

---

## CLI Usage (`./localnh`)

`localnh` comes with a command-line interface for headless management:

```bash
# Fetch and archive a gallery by ID:
./localnh 123456

# Display library statistics (titles, space used, latest activity):
./localnh stats

# Export gallery to PDF:
./localnh pdf 123456 /path/to/output.pdf

# Export gallery to standalone offline HTML:
./localnh html 123456 /path/to/output.html

# Delete a specific gallery and cached images:
./localnh delete 123456

# Purge all galleries and image cache:
./localnh delete purge

# Stop all web/CLI writers before snapshot export or import (see STORAGE.md).
# Export a complete portable snapshot (database + chunked tar cache):
./localnh snapshot export --offline

# Import a snapshot:
./localnh snapshot import /path/to/snapshot_index.json --offline
```

---

## Configuration Reference

Site settings are stored as plain text files in the selected data root's `config/` directory. Storage/session paths and proxy trust use environment variables; see [Storage Guide](STORAGE.md) and [Deployment Guide](DEPLOYMENT.md).

| File | Default | Description |
| :--- | :--- | :--- |
| `config/token` | *Required* | nhentai API Bearer Token |
| `config/title` | `localnh` | Site name displayed in the top bar and `<title>` |
| `config/domain` | `localhost` | Snapshot filename/manifest label; RSS and Open Graph URLs use the request host/scheme |
| `config/credentials` | *Optional* | Admin login (`USER=...` and `PASS=...` with bcrypt hash) |
| `config/public` | `off` | When set to `on`, allows public users to fetch/archive galleries |
| `config/doh` | `https://1.1.1.1/dns-query` | Custom DNS-over-HTTPS resolver URL |
| `config/favicon` | `/static/favicon.png` | Path or URL to the site favicon; supply this image yourself or clear the setting |
| `config/footer` | *Markdown* | Custom footer text (supports Markdown links `[text](url)`) |

---

## Security and deployment

### HTTP file access policy

The supplied Caddy configuration routes every request through `index.php`. Only that PHP file is a public entry point; use `/admin`, `/settings`, and `/thumbnail` rather than requesting their `.php` files directly. Other web servers must also route **all** requests through `index.php` without serving existing files or executing other PHP scripts first. For local development, keep the router argument in `php -S 127.0.0.1:8080 index.php`.

Static files are restricted to CSS in `/css/`, browser JavaScript in `/script/js/`, and images (`webp`, `png`, `jpg`, `jpeg`, `gif`, `svg`, `ico`) in `/cache/images/` and `/static/`. Place custom local favicons in `/static/`; external favicon URLs still work. Hidden paths, ambiguous paths, unknown file types, and symlinks resolving outside their permitted directory are rejected. Do not place private content in these public asset directories.

This initial security change also sends static images through PHP. It favors one consistent access policy; direct static serving can be reintroduced after private data is moved outside a dedicated public web root. Authentication and form protection are described below.

### Docker build context

Private configuration (`config/`, `.env*`), cache, snapshots, runtime/session storage, SQLite databases and their sidecar files are excluded from the image. Local tests, plans, personal files, and backup directories are excluded as well. `config.example/` remains in the image so the entrypoint can initialize an empty configuration directory. Existing mounted configuration is not overwritten. The Dockerfile creates empty cache, snapshot, and configuration directories; these do not require copying host data into the image.

Keep real credentials and application data in the runtime mounts documented above. Build exclusions prevent inclusion in new images; they do not remove private data from images built previously. Arbitrarily named secret/backup files outside the excluded locations still need to be kept out of the build context.

### Login sessions

Login now issues a random, server-validated session lasting at most 30 days. Existing browsers must log in once after upgrading. Logout revokes that browser's session; other devices remain signed in. The server stores token hashes only. Changes to `config/credentials` revoke existing sessions when next observed, including changes that preserve the file timestamp. Missing or invalid credentials never grant access. Restoring a prior file after an observed change does not restore old sessions. Changes that occur and are undone entirely between requests cannot be detected; revoke sessions explicitly when restoring configuration.

Docker Compose persists sessions separately at `./sessions`, mounted outside the web root at `/var/lib/localnh/sessions`. For a manual PHP installation, sessions are stored in `sessions/` alongside the application; set `LOCALNH_SESSION_DIR` to an absolute private directory to override it. Use the same directory setting for PHP and CLI snapshot operations, and ensure the PHP service can write there. Do not publish, export, or restore session storage as collection data. Snapshot import revokes sessions before replacing the collection database; exports contain only collection data. If session storage is unavailable, HTTP requests fail with 503 instead of granting administrative access.

Cookies use `HttpOnly` and `SameSite=Lax`. HTTP local deployment works without TLS, a domain, or a proxy. Cookie `Secure` is decided per request, so the same instance can support HTTP localhost and a public HTTPS hostname simultaneously. Direct HTTPS is detected from the web server, including a host tunnel connecting to a separate HTTPS origin. That deployment can leave proxy trust empty; see [the HTTPS origin recipe](DEPLOYMENT.md#host-proxy-or-tunnel-with-an-https-origin). For HTTPS terminated by a reverse proxy with an HTTP connection to localnh, configure `LOCALNH_TRUSTED_PROXIES` with the exact immediate proxy IP(s), comma-separated, as seen by the application. The default empty value trusts no forwarding headers. IPv4 and IPv6 addresses are supported; CIDRs and hostnames are intentionally not accepted.

The trusted proxy must overwrite `X-Forwarded-Proto` with the actual client scheme, not pass through a client-supplied value. An HTTPS value from any other peer is ignored. Do not trust a Docker gateway or loopback address shared by both the proxy and untrusted traffic. Use an HTTPS origin instead, or put the proxy on a dedicated network with a stable, distinct address first. Preserve the immediate peer in `REMOTE_ADDR`; if custom web-server configuration rewrites it to the original client IP, this trust check cannot identify the proxy correctly. Public HTTPS deployment needs this configuration verified before use; an unconfigured proxy is treated as HTTP.

For example, if an isolated proxy has the fixed address `172.30.50.2`, set:

```yaml
environment:
  LOCALNH_TRUSTED_PROXIES: "172.30.50.2"
```

That address is only an example, not a subnet/address to copy blindly. Keep the value empty for local-only installs. Recreate the container after changing it. The previous experimental `LOCALNH_COOKIE_SECURE` switch is no longer used: a global switch cannot describe mixed HTTP/HTTPS access. HTTPS-only installations should enforce redirects at their public proxy, while local HTTP access may stay available separately.

Use separate hostnames for mixed access (such as `localhost` locally and your public HTTPS domain). Browsers scope cookies by hostname, not port; HTTP and HTTPS on the *same* hostname can conflict with an existing Secure cookie. No special-case trust is granted based on a client-controlled Host value, and the implementation does not rely on browser exceptions for Secure on localhost. HTTP itself does not encrypt credentials or cookies. Session revocation and CSRF checks protect different parts of the request flow; both are enforced for administrative mutations.


### Forms and mutations

Gallery deletion, public archive toggling, and logout require an authenticated POST with a session-bound CSRF token. Existing GET action links return 405 without performing the action; invalid or missing tokens return 403. Reload a stale form after signing in again. The gallery delete button retains its two-click confirmation. Successful changes redirect with 303. Session-specific pages are not shared-cacheable.

Login, browser preference toggles, and archiving also require POST and CSRF checks. Anonymous forms use a signed nonce cookie that expires after two hours; login switches to session-bound tokens. Keep cookies enabled and reload an expired form. Viewing a missing gallery shows confirmation instead of downloading. Public archiving remains available when explicitly enabled. The home sort dropdown saves its browser-only preference on user interaction; opening a sort URL does not save it. Favorites/localStorage and read-only metadata/download endpoints retain their existing behavior.

---

## Documentation

- **[Agent Guide](AGENTS.md)**: Reading order, coding constraints, validation, and links to architecture, development, task state, and decision records.

- **[Storage Guide](STORAGE.md)**: Legacy/external paths, initialization, permissions, offline snapshots, verified migration and rollback before/after writes.

- **[Deployment Guide](DEPLOYMENT.md)**: Local HTTP, public HTTPS, mixed access, optional host tunnels, certificate trust, verification, and rollback.

- **[User Guide](USER_GUIDE.md)**: Detailed instructions on browsing, searching, keyboard reader controls, bookmarking, backup/restore, and downloading galleries.
- **[Technical Documentation](DOCS.md)**: Advanced architecture details, database schema, routing flow, and API endpoint documentation.

---

## Repairing incomplete galleries

The CLI can check one archived gallery and fetch only missing page files:

```bash
./localnh repair <gallery_id>
./localnh repair <gallery_id> --overwrite
```

`repair` uses the saved gallery metadata and does not replace existing pages unless `--overwrite` is specified. The command must be able to read and write `cache/images`; if the web container creates files under another UID or group, grant the host CLI user an ACL on that directory before running repair or personal export scripts.

## Credits & Acknowledgements

- **API**: Based on the [nhentai API v2](https://nhentai.net/api/v2/docs).
- **Fonts**: [Plus Jakarta Sans](https://fonts.google.com/specimen/Plus+Jakarta+Sans) by Tokotype.
- **Python Libraries**:
  - [`curl_cffi`](https://github.com/lexiforest/curl_cffi) - Browser TLS fingerprinting & DoH client.
  - [`Pillow`](https://python-pillow.org) - Image processing & PDF generation.
- **Development & AI Disclosure**: Developed with the assistance of **Antigravity CLI** powered by **Gemini 3.1 Pro** and **Gemini 3.7 Flash** models. Subsequent code review, security fixes, and maintenance were assisted by **ChatGPT Desktop** using **GPT-5.6 Sol** and **GPT-6 Astra**.

---

## Login protection

Login attempts have short peer and instance-wide limits. If you receive HTTP 429, wait for Retry-After before retrying. Localhost login and anonymous exports remain supported. See [deployment guidance](DEPLOYMENT.md#login-attempt-limits) for shared-proxy behavior and owner recovery.

## License

This project is licensed under the [MIT License](LICENSE).
