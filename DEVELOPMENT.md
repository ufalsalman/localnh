# Development

## Prerequisites and setup

Use Linux for the full application: `archive_runner.py` and `resource_worker.py` call Linux `prctl`, use POSIX process groups/resource limits, and the lock helpers use `fcntl`. Docker is the packaged baseline (PHP 8.3 FPM on Debian Trixie). Native PHP requires 8.1+ syntax, PDO SQLite with SQLite JSON functions, GD with WebP support, Fileinfo, POSIX, and enabled `proc_open`. The image also installs PHP ZIP, although current ZIP/CBZ generation uses Python `zipfile`.

Python 3.10+ and `venv` are needed for the current locked packages; compatible wheels must exist for the selected interpreter/platform. The Dockerfile uses Debian's `python3`. Native thumbnails require **`/usr/bin/vips`** (libvips tools), and web supervision requires **`/usr/bin/python3`**. Web Python application workers require **`venv/bin/python` inside the source directory**; the CLI's system-`python` fallback does not apply to web jobs. Bash is needed for `./localnh`. Node is optional for JS syntax checks, not application runtime.

For a fresh native checkout, from its root:

```sh
python3 -m venv venv
./venv/bin/pip install --require-hashes --only-binary=:all: -r requirements-tools.lock
./venv/bin/pip install --require-hashes --only-binary=:all: -r requirements.lock
./venv/bin/pip check
cp -r config.example config
```

Do not copy templates over an existing configuration. Supply `config/token` for archiving and set `USER`/a password hash in `config/credentials` for admin login, following README. Do not put real passwords in committed commands or logs. The example credentials are placeholders. No `.env` loader is implemented.

PHP and Python must agree on `LOCALNH_DATA_DIR` / `LOCALNH_SESSION_DIR` and share compatible ownership. Legacy mode needs writable `cache/`, `snapshot/`, configuration for public-toggle changes, and the collection database and its parent. PHP creates the collection table on first request; the archive runner also creates it. External mode must be explicitly initialized/migrated first; see STORAGE. Do not repair permission failures with `chmod 777` or fall back to another data root.

| Environment | Current meaning |
| --- | --- |
| `LOCALNH_DATA_DIR` | Empty/unset selects source-relative storage; otherwise use an initialized absolute external root. |
| `LOCALNH_SESSION_DIR` | Empty/unset selects `<data-root>/sessions`; Docker sets `/var/lib/localnh/sessions`. |
| `LOCALNH_TRUSTED_PROXIES` | Empty trusts no forwarded scheme; otherwise a comma-separated list of exact immediate proxy IPs. |
| `VIPS_BLOCK_UNTRUSTED` | Docker sets `1` to disable libvips loaders marked untrusted; retain it in native thumbnail services. |
| `LOCALNH_CONTAINER` | Docker sets `1` so root CLI invocations drop to `www-data` (except storage initialization). |

These are process environment settings, not files loaded from `.env`. Use empty/unset rather than `0` for default paths: PHP and Python disagree on that literal value (TASKS). Compare the service and CLI environments before maintenance. Internal worker flags and inherited lock descriptors are managed by the supervisors, not deployment settings.

## Run and build

```sh
# Local native development, from the source root:
php -S 127.0.0.1:8080 index.php
```

The router argument is essential. The checked-in Caddyfile assumes `/var/www/html` and an already-running FPM listener at `127.0.0.1:9000`; `caddy run` alone is not a complete native setup. Adapt those paths/listeners while preserving all-request routing. The built-in server does not reproduce FPM concurrency or production TLS.

For a fresh stock Docker installation, follow README's configuration copy and `touch cache.sqlite` first. That file bind mount must be a file, not an automatically created directory.

```sh
docker compose config --quiet
docker compose build localnh
docker compose up -d
# After code/dependency changes in the intended deployment:
docker compose up -d --build
docker compose logs --tail=100 localnh
```

Stock Compose uses the fixed container name `localnh` and host port 8080 on all interfaces. For a disposable test deployment, use a separate checkout/data and an edited Compose configuration with a distinct container name and unused loopback port; a project-name change alone will not resolve the explicit container-name/port collision. Existing source edits are not automatically visible inside a running stock container: source is built into the image.

Docker's entrypoint does not execute arbitrary trailing arguments. For one-off image inspection override `--entrypoint`; for a running intended service use `docker compose exec localnh ...`. Do not install packages into the production venv: Docker intentionally removes pip/setuptools. Dependency changes go through reviewed lock updates and image rebuilds; see DOCS.

## CLI and maintenance

`./localnh` locates scripts relative to itself and uses its venv, otherwise `python`. Container root calls re-execute as `www-data`, except explicit `storage init`. Help currently exits **1**, even when displayed successfully.

```sh
./localnh --help
./localnh storage paths
./localnh stats
./localnh check <gallery_id>
./localnh check all
./localnh <gallery_id>
./localnh fetch <gallery_id>
./localnh pdf <gallery_id> /absolute/output.pdf
./localnh html <gallery_id> /absolute/output.html
./localnh repair <gallery_id>
./localnh repair <gallery_id> --overwrite
./localnh delete <gallery_id>
./localnh delete purge
```

Angle-bracket arguments are placeholders. Fetch/repair/delete change collection data; do not use them as smoke checks on someone's collection. `check` checks filenames rather than image integrity, and fails for an empty collection. Repair is offline maintenance with limitations described in TASKS; check again afterward instead of trusting its exit code. `./localnh fetch <gallery_id> --fix` refreshes an existing record but preserves existing image files; it is distinct from `repair --overwrite`.

For a new empty external installation only, set `LOCALNH_DATA_DIR` to an empty absolute directory outside source, then run `./localnh storage init`. It refuses detected legacy data. Use `./localnh storage paths` to verify selection before any mutation. Detailed migration commands and recovery checkpoints belong in [STORAGE.md](STORAGE.md).

Snapshots require **all web/CLI writers stopped**, including repair. `--offline` does not stop them:

```sh
./localnh snapshot export --offline
./localnh snapshot import /absolute/path/to/index.json --offline
```

Use STORAGE's one-off container procedure when the service is stopped. A host path is not automatically visible in the container. Import needs space for staging and previous data, revokes sessions, and retains recovery backups. Never delete those backups or collection lock files automatically.

## Checks and test status

No test suite, test runner configuration, CI workflow, formatter, Makefile, npm or Composer commands are checked in. Historical regression counts came from a separate test deployment, which was not available at this documentation review. Do not report those runs as tests of a new change.

These syntax/configuration checks use the actual source layout and do not start the app or touch its data:

```sh
for file in ./*.php; do php -l "$file" || exit; done
python3 - <<'PY'
import ast
from pathlib import Path
for path in Path('script/py').glob('*.py'):
    ast.parse(path.read_text(), filename=str(path))
print('Python syntax OK')
PY
node --check script/js/gallery.js
node --check script/js/reader.js
bash -n localnh
sh -n docker-entrypoint.sh
docker compose config --quiet
caddy validate --config Caddyfile --adapter caddyfile
git diff --check
```

Native Caddy validation checks the installed Caddy version; it does not prove the pinned Docker binary builds. AST parsing avoids importing maintenance scripts that perform work at import time. `node --check` covers the external JS files only; inline PHP-rendered scripts need rendered-page/browser checks. No automatic formatting command is configured; follow nearby style.

Choose relevant behavior checks on disposable initialized data:

| Changed area | Minimum useful smoke coverage |
| --- | --- |
| Templates, search, reader | Home/filter/search/sort, missing gallery confirmation, gallery/reader first and last pages, LTR/RTL, dark/light/blur, browser console |
| Favorites/user | Current array and legacy object data, counts/undo, >200 IDs, API failure without false orphans, backup/restore; keep known defects explicit |
| Router/auth | Assets work; config/database/source/session URLs denied; HTTP login/logout, expired/changed credentials, valid/invalid CSRF and legacy GET actions; verify HTTPS cookies when applicable |
| Jobs/crawler | Cold/warm/HEAD/ETag thumbnails, busy slots, all four export formats, archive success/failure/429, invalid metadata/redirects and cleanup; use controlled fixtures for upstream failure cases |
| Storage/locks | Legacy and external paths, shared PHP/CLI identity, competing readers/writers, offline snapshot round trip and interrupted import recovery, restart persistence |
| Packaging/dependencies | Build and inspect new image with no private mounts, verify imports/codecs/FPM environment, local HTTP and intended TLS topology |

For documentation-only work, verify paths, links, commands, constants and diff scope; a live archive or production deployment is unnecessary.

## Debugging and release constraints

- `storage paths` distinguishes a wrong root/mount from missing data. It prints paths, not credentials. A 503 can indicate storage/auth failure, busy locks, a missing vips executable, or a worker exceeding limits.
- PHP logs contain job-failure summaries; supervisors suppress child stderr. Reproduce converters on a disposable fixture when deeper diagnostics are needed. Web PDF/HTML limits differ from native CLI limits.
- Inspect browser Network/Console for failed thumbnails and favorites batches. The five-second download status timer only clears text; it is not completion detection. Keep the page/iframe open for large exports.
- Native thumbnail processes should retain `VIPS_BLOCK_UNTRUSTED=1` as in Docker. Verify the service environment, rather than assuming an interactive shell's variables reach FPM.
- For deployments, preserve the previous image, data and exact runtime Compose/Caddy settings. TLS CA persistence, proxy trust, rollback before/after new writes and public verification are in DEPLOYMENT/STORAGE. Do not replace deployment-specific ports/mounts with source defaults.
