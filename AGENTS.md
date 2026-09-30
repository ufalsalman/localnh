Read these files in order before changing the project:

1. [README.md](README.md)
2. [AGENTS.md](AGENTS.md)
3. [ARCHITECTURE.md](ARCHITECTURE.md)
4. [DEVELOPMENT.md](DEVELOPMENT.md)
5. [TASKS.md](TASKS.md)
6. [DECISIONS.md](DECISIONS.md)

## Working rules

- This is a working PHP/SQLite/JavaScript/Python application. Inspect existing code and callers before modifying it. Preserve current behavior unless the task requires a change; prefer focused changes over broad refactors and avoid unnecessary dependencies.
- Do not “clean up” unfamiliar code until its purpose is understood. In particular, retain compatibility handling for legacy storage, browser favorites, snapshot bundles, image formats, and mixed HTTP/HTTPS access.
- Source and runtime configuration outrank build configuration, Git history, documentation, then old conversations. Historical plans are evidence, not instructions or a current backlog.
- Work in the requested checkout. A deployed instance, private data root, or related reader project is a separate target; do not copy changes or restart it merely because it appears in history.

## Code map

- `index.php`: the only public PHP entry point, routing, asset allowlist, catalogue/search.
- `bootstrap.php`, `login_limits.php`, `request_validation.php`: sessions, CSRF, login admission and scalar input validation.
- `storage.php` and `script/py/storage.py`: matching PHP/Python storage contracts. Change and validate them together.
- `gallery.php`, `reader.php`, other root page templates: server-rendered UI; `presentation.php` owns shared display helpers.
- `resource_jobs.php`, `archive_jobs.php`, `collection_reads.php`, `aggregation.php`: job admission, shared locks and ephemeral caches. Python counterparts live in `script/py/`.
- `script/js/gallery.js`, `script/js/reader.js`, inline page scripts, `css/style.css`: browser behavior and styling. There is no frontend build system.
- `localnh`: Bash CLI dispatcher; `Dockerfile`, `docker-entrypoint.sh`, `docker-compose.yml`, `Caddyfile`, `build/caddy/`: packaging and deployment. `build/caddy/` is authored build input, not generated output.

## Commands and validation

Run from the repository root unless stated otherwise. Setup and isolation details are in [DEVELOPMENT.md](DEVELOPMENT.md).

| Purpose | Command |
| --- | --- |
| Native development | `php -S 127.0.0.1:8080 index.php` |
| Build container | `docker compose build localnh` |
| Start/update intended deployment | `docker compose up -d --build` |
| Validate Compose / Caddy | `docker compose config --quiet` / `caddy validate --config Caddyfile --adapter caddyfile` |
| Inspect selected storage | `./localnh storage paths` |
| Collection completeness (initialized fixture) | `./localnh check <id>` or `./localnh check all` |
| PHP syntax | `php -l path/to/changed.php` |
| Browser JavaScript syntax | `node --check script/js/gallery.js` and `node --check script/js/reader.js` |
| Patch whitespace | `git diff --check` |

There is **no checked-in test suite, CI workflow, formatter or project lint command**. Use the Python syntax check and feature-specific smoke matrix in DEVELOPMENT.md. Syntax checks alone do not verify behavior. Test data-changing operations on disposable data, verify changes before declaring completion, and report what was not tested.

## Conventions and boundaries

- Follow each file's existing style; do not reformat unrelated code. Keep shared presentation in `presentation.php`, reusable styling in `css/style.css`, and browser logic in the existing scripts where appropriate. PHP currently emits dynamic theme variables and some inline scripts/styles; do not remove these solely to enforce a historical style proposal.
- Use prepared SQL parameters, existing scalar/path validators, HTML escaping for markup, and `localnh_script_json()` for PHP data inside scripts. The HTML exporter has separate `escape_html()` / `script_json()` helpers.
- Keep process arguments as arrays, use fixed trusted executables, and retain inherited lock descriptors, deadlines, cleanup and signal handling. Browser thumbnail concurrency is deliberately one; thumbnail and aggregate worker memory limits are deliberately 512 MiB.
- All HTTP requests, including assets, must pass through `index.php`. Never add a general static-file fallback or expose the other PHP scripts directly. Read [DEPLOYMENT.md](DEPLOYMENT.md) before changing routing, TLS or proxies.
- Preserve anonymous reading/export, admin-only deletion/public toggles, POST+CSRF mutation guards, fail-closed sessions, and per-request cookie security. `config/public=on` permits archiving, not general administration.
- Keep stored image references logical (`cache/images/...`), resolve disk paths through the storage helpers, and preserve the two collection locks. Snapshot `--offline` is an operator assertion, not a service stop. Read [STORAGE.md](STORAGE.md) before import/migration/recovery. `repair.py` currently bypasses those locks: see TASKS.md.
- Never hardcode, commit, quote or export real credentials, tokens, session databases, private CA keys or personal backups. Use `config.example/` as templates. `.gitignore` and `.dockerignore` have different coverage; inspect new files before staging/building. Configuration footer HTML is trusted operator input, not sanitized Markdown.

## Documentation maintenance

After substantial work, update the relevant ARCHITECTURE, DEVELOPMENT, TASKS and DECISIONS sections. Keep AGENTS concise and current. Update README/USER_GUIDE for user-visible behavior, DOCS for detailed technical contracts, and STORAGE/DEPLOYMENT for operational changes. Record known limitations rather than silently promoting historical test results or proposals to current facts.
