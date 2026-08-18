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
  - Full-text search with instant autocomplete suggestions.
  - Quick external links to nhentai search directly from filter headers.
- **Multi-Format Export**
  - Export and download galleries as **ZIP**, **CBZ** (Comic Book Archive), or **PDF** (rendered with Pillow).
- **Local Storage & Privacy**
  - User session, reading count ("cum count"), and favorites stored locally in browser `localStorage`.
  - JSON backup and restore functionality to easily transfer your library across devices without requiring cloud accounts.
- **Built-in Anti-Censorship & DoH**
  - Automatic DNS over HTTPS (DoH) support via Cloudflare (`1.1.1.1`) or custom resolvers to bypass ISP DNS poisoning.
  - Browser TLS fingerprint impersonation (`curl_cffi`) for reliable metadata and media downloads.
- **RSS 2.0 Feed**
  - Full RSS feed available at `/rss` to stay updated with newly archived entries.
- **Powerful CLI Utility**
  - Manage archives, export snapshots, and convert to PDF directly from terminal using `./localnh`.

---

## Requirements

- **PHP 8.1+** with extensions:
  - `pdo_sqlite`
  - `gd`
  - `zip`
- **Python 3.9+** with `venv`
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
   Add your nhentai API token to `config/token`.

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
   ./venv/bin/pip install -r requirements.txt
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

# Export gallery to PDF:
./localnh pdf 123456 /path/to/output.pdf

# Delete a specific gallery and cached images:
./localnh delete 123456

# Purge all galleries and image cache:
./localnh delete purge

# Export a complete portable snapshot (database + chunked tar cache):
./localnh snapshot export

# Import a snapshot:
./localnh snapshot import /path/to/snapshot_index.json
```

---

## Configuration Reference

All settings are stored as plain text files in the `config/` directory:

| File | Default | Description |
| :--- | :--- | :--- |
| `config/token` | *Required* | nhentai API Bearer Token |
| `config/title` | `localnh` | Site name displayed in the top bar and `<title>` |
| `config/domain` | `localhost` | Domain name used for metadata and snapshot manifests |
| `config/credentials` | *Optional* | Admin login (`USER=...` and `PASS=...` with bcrypt hash) |
| `config/public` | `off` | When set to `on`, allows public users to fetch/archive galleries |
| `config/doh` | `https://1.1.1.1/dns-query` | Custom DNS-over-HTTPS resolver URL |
| `config/favicon` | `/static/favicon.png` | Path or URL to the site favicon |
| `config/footer` | *Markdown* | Custom footer text (supports Markdown links `[text](url)`) |

---

## Documentation

- **[User Guide](USER_GUIDE.md)**: Detailed instructions on browsing, searching, keyboard reader controls, bookmarking, backup/restore, and downloading galleries.
- **[Technical Documentation](DOCS.md)**: Advanced architecture details, database schema, routing flow, and API endpoint documentation.

---

## Credits & Acknowledgements

- **API**: Based on the [nhentai API v2](https://nhentai.net/api/v2/docs).
- **Fonts**: [Plus Jakarta Sans](https://fonts.google.com/specimen/Plus+Jakarta+Sans) by Tokotype.
- **Python Libraries**:
  - [`curl_cffi`](https://github.com/lexiforest/curl_cffi) - Browser TLS fingerprinting & DoH client.
  - [`Pillow`](https://python-pillow.org) - Image processing & PDF generation.
- **Development & AI Disclosure**: Developed with the assistance of **Antigravity CLI** powered by **Gemini 3.1 Pro** and **Gemini 3.7 Flash** models.

---

## License

This project is licensed under the [MIT License](LICENSE).

