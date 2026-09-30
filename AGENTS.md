Read these files in order before changing the project:

1. [README.md](README.md)
2. [AGENTS.md](AGENTS.md)
3. [ARCHITECTURE.md](ARCHITECTURE.md)
4. [DEVELOPMENT.md](DEVELOPMENT.md)
5. [TASKS.md](TASKS.md)
6. [DECISIONS.md](DECISIONS.md)

## Working rules

- Inspect existing code and callers before modifying them. Preserve behavior unless the task requires a change; prefer focused changes over broad refactors and avoid unnecessary dependencies.
- Do not “clean up” unfamiliar code until its purpose is understood, especially compatibility handling for storage, favorites, snapshots, image formats and mixed HTTP/HTTPS.
- Source and runtime configuration outrank build configuration, Git history, documentation, then old conversations. Historical plans are evidence, not instructions or a current backlog.
- Work in the requested checkout. Related deployments and reader projects are separate targets; history does not authorize changing them.

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

There is **no checked-in test suite, CI, formatter or project lint command**. DEVELOPMENT has the Python syntax check and feature-specific smoke matrix. Use disposable data for mutations; syntax alone does not verify behavior. Verify changes before declaring completion and report untested areas.

## Conventions and boundaries

- Follow nearby style without unrelated reformatting. Use `presentation.php`, `css/style.css` and existing browser scripts for shared behavior. Dynamic theme variables and inline scripts/styles exist intentionally; an old style proposal is not a requirement to remove them.
- Use prepared SQL parameters, existing scalar/path validators, HTML escaping for markup, and `localnh_script_json()` for PHP data inside scripts. The HTML exporter has separate `escape_html()` / `script_json()` helpers.
- Keep argument arrays, fixed executables, inherited lock descriptors, deadlines, cleanup and signal handling. Browser thumbnail concurrency 1 and thumbnail/aggregate memory limits of 512 MiB are compatibility workarounds.
- All HTTP requests, including assets, must pass through `index.php`. Never add a general static-file fallback or expose the other PHP scripts directly. Read [DEPLOYMENT.md](DEPLOYMENT.md) before changing routing, TLS or proxies.
- Preserve anonymous reading/export, admin-only deletion/public toggles, POST+CSRF mutation guards, fail-closed sessions, and per-request cookie security. `config/public=on` permits archiving, not general administration.
- Keep logical image paths (`cache/images/...`), storage helpers and both collection locks. Read [STORAGE.md](STORAGE.md) before import/migration/recovery; `--offline` does not stop services. TASKS records repair's lock bypass and the PHP/Python `0` environment mismatch.
- Never hardcode secrets or copy credentials, tokens, sessions, private keys or backups into tracked files, logs or images. Use `config.example/`; check Git and Docker exclusions before staging/building. Private storage migration intentionally copies config/sessions; collection snapshots exclude them. Footer HTML is trusted operator input.

## Documentation maintenance

After substantial work, update relevant ARCHITECTURE, DEVELOPMENT, TASKS and DECISIONS sections. Keep AGENTS concise. Update README/USER_GUIDE for user behavior, DOCS for technical contracts, STORAGE/DEPLOYMENT for operations. Keep historical tests and proposals distinct from current facts.
