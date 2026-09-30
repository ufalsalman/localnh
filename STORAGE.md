# Storage locations

The application supports an external data directory. This is implementation support, not an automatic migration: existing data must not be moved merely by setting an environment variable. Follow a verified migration procedure before changing an existing installation. Migration, rollback and interruption-recovery procedures are documented below. Validate them on your own disposable deployment before changing a running instance.

## Legacy and external modes

With LOCALNH_DATA_DIR unset or empty, collection/config/cache/snapshot paths remain alongside the source. Existing installations keep working. Docker sessions continue to use the existing LOCALNH_SESSION_DIR default `/var/lib/localnh/sessions`.

With LOCALNH_DATA_DIR set, it must be an absolute directory outside and disjoint from the source tree, with initialized metadata and a collection database. Validation rejects relative paths, uninitialized/missing roots, source aliases, and unavailable storage. One current exception is the literal value `0`: PHP treats it as unset and selects legacy storage, whereas Python rejects it. `LOCALNH_SESSION_DIR=0` has the same fallback/rejection mismatch. Use an empty value or a valid absolute path, and verify selection in both PHP and CLI when changing deployment settings. Data child symlinks are unsupported. URLs and database image references remain `cache/images/<id>/<file>`; resolvers map them to disk without changing public URLs.

```text
LOCALNH_DATA_DIR/
  .localnh-storage.json
  config/
  cache.sqlite
  cache/images/
  snapshot/
  sessions/
```

LOCALNH_SESSION_DIR remains an explicit override. If it is empty, sessions use the selected root's sessions directory. In the Docker image this override defaults to `/var/lib/localnh/sessions`; when choosing a different data root, either persist that existing session path separately or explicitly clear the override to place sessions under the new root. PHP, CLI and child processes must receive the same settings.

The application source, config.example templates, CSS/JS/static and Python environment stay in the application directory. Caddy CA/tunnel configuration remains separate. Do not create a source/cache symlink into private storage. Router allowlists still govern which image files are public.

## Explicit initialization of a new empty installation

`./localnh storage init` requires LOCALNH_DATA_DIR. It refuses nonempty destinations and detected legacy data. It never imports a collection or overwrites existing configuration. If initialization fails halfway, inspect the partial destination before removing/retrying it; do not disguise an existing collection as a new installation by adding a marker file manually.

For a new Docker installation, prepare an empty host directory outside the source/build context, then run initialization with the same mount and environment that the application will later use. Example using an already-built image named `localnh:your-release`:

```sh
mkdir -p ../localnh-data
docker run --rm --entrypoint python3 \
  -e LOCALNH_DATA_DIR=/var/lib/localnh \
  -v "$(cd ../localnh-data && pwd):/var/lib/localnh" \
  localnh:your-release /var/www/html/script/py/storage.py init
```

Then adapt Compose: set LOCALNH_DATA_DIR to `/var/lib/localnh`, replace old config/database/cache/snapshot/session mounts with the single data-directory mount, and retain the deployment's ports and external Caddy mounts. Do not append a parent mount while keeping child mounts that hide its contents. Review `docker compose config` before starting. No startup auto-init is performed for external mode.

For native PHP, initialize while running as the intended application account, export LOCALNH_DATA_DIR to both PHP and CLI, and give that account read/write/traverse access to data. Do not use chmod 777. Native web and CLI writers must share compatible ownership. In this Docker image the `localnh` wrapper runs normal CLI commands as www-data when invoked as root, so newly generated files remain usable by PHP; explicit storage initialization is the exception. Directly running a Python script as root bypasses this wrapper and requires you to manage ownership yourself.

```sh
./localnh storage paths
```

This prints selected directories without credentials. It works independently of the current working directory. An empty/missing database after a configuration change is not a signal to initialize: first verify which root and mounts were selected.

## Snapshots require maintenance time

Stop web requests and all CLI/archive/import writers before exporting or importing. `--offline` records your acknowledgement; it does not automatically stop services or acquire a global application lock. SQLite backup protects database consistency, but only stopping writers keeps the database and image files at the same collection state.

For Docker, stop the application service first and run the task in a one-off container with the same volumes/environment:

```sh
docker compose stop localnh
docker compose run --rm --no-deps --entrypoint /var/www/html/localnh localnh snapshot export --offline
```

For import, replace `export` with `import /path/inside/container/index.json`; the snapshot must be mounted/readable inside that container. Start the service again only after success or verified recovery. Native equivalents are `./localnh snapshot export --offline` and `./localnh snapshot import /absolute/path/index.json --offline` with writers stopped.

Exports retain the existing manifest fields and portable cache/images tar layout, use SQLite's backup API and numeric split parts, and never include config, login sessions or the CSRF key. Existing correctly formed bundles are supported. Import rejects unsafe filenames, missing/noncontiguous parts, links, special files, duplicate/traversal archive entries, invalid SQLite databases, and missing referenced images before changing the active collection. Do not lower validation to import a malformed bundle.

Import stages on the cache filesystem, backs up the active DB/images, revokes login sessions, and activates the new collection. SQLite backup into the existing DB preserves compatibility with legacy file mounts. Mount the cache parent rather than cache/images itself; replacing an independently mounted images directory is unsupported. A caught activation error attempts restoration. A process/power interruption can leave an incomplete activation: keep the service stopped and inspect recovery.json before restarting.

Recovery files are retained under `cache/localnh-import-*`, outside the publicly served images prefix. They consume additional disk space and are not automatically deleted after a successful import. Preserve previous.sqlite, previous-images and recovery.json until acceptance. With writers stopped, recovery uses a validated SQLite backup from previous.sqlite into the selected collection DB and restores previous-images as the selected images directory. Because actual paths, filesystem permissions and interruption phases differ, inspect the journal and prepare exact commands before restoring; never blindly merge the old and new image trees. Login sessions revoked by import need a new login even after recovery.

## Operational limits

No live migration or automatic rollback is promised. Before migrating or importing, budget space for the bundle, extracted staging and old collection backup, and stop all writers. Filesystem access errors must be fixed at the configured root, not by falling back to source. Ordinary reads may initialize session/CSRF state but must not create a missing external collection. Removing old data/backups is a separate action after validation.

The code changes do not require Cloudflare or a new proxy topology. Stage 5B preserves existing port/HTTPS behavior. The test migration rehearsal does not authorize a production migration. Acceptance results are specific to the tested deployment; stop writers and keep verified recovery data for each migration.

## Offline migration of an existing collection

`python3 script/py/migrate_storage.py --source /absolute/old-data --destination /absolute/new-data --layout external --offline` creates a verified copy and leaves the source untouched. It does not stop services or change deployment configuration. The destination must not exist; its parent must exist. Both paths must be canonical and disjoint. Stop the web service and every CLI/archive/import writer first; the flag is an acknowledgement, not a lock. Do not run simultaneous migrators into the same destination namespace.

The source must expose `config/`, `cache.sqlite`, `cache/images/`, `snapshot/`, and `sessions/` together. A legacy installation may keep sessions in a separate mount: expose that existing session directory as the source's sessions child in a temporary maintenance container, alongside the other legacy mounts. Do not substitute an empty sessions directory without deciding explicitly to invalidate existing logins. This storage copy includes configuration and sessions; collection snapshot export deliberately does not.

The migrator rejects links/special files, insufficient space (two copy sizes plus 256 MiB reserve), invalid SQLite databases, and missing database-referenced images. It copies into a unique sibling staging directory, uses SQLite backup for detected SQLite files including the session database, and omits their raw journal/WAL/SHM sidecars. It compares all copied file contents (logical SQLite dumps for databases), directory/file ownership and modes, gallery IDs, and a second source inventory before publishing the destination. Metadata preservation requires compatible ownership; use a suitably privileged maintenance container for Docker. The application startup then uses its normal ownership checks. No chmod 777 is needed.

Only a verified external copy receives `.localnh-storage.json`. A private `.localnh-migration.json` records verification hashes, paths and metadata without configuration contents; keep it private with mode 0600. An exception leaves the destination inactive and retains the hidden staging directory for inspection. Do not enable a partial directory by adding a marker. A source-change check can detect differences, but cannot replace stopping writers or guarantee detection of every concurrent write. This is a staged offline copy, not a power-loss durability guarantee or atomic switch of Docker configuration.

After successful copying, set `LOCALNH_DATA_DIR=/var/lib/localnh` and mount the new host root at `/var/lib/localnh`; remove the previous config/database/cache/snapshot/session child mounts. Keep the deployment's own ports, TLS mounts and proxy configuration. Start with the already-tested image, inspect `./localnh storage paths`, then check login, gallery count, images and private HTTP denials. Test changes in a disposable/test deployment before production approval. Retain the old root and Compose/image reference.

## Rollback before and after new writes

Before any collection/configuration changes, restoring the old Compose/image and old mounts returns the baseline collection. HTTP reads can still update session state; a login may need repeating. This shortcut is not appropriate after a newly archived gallery, deletion or setting change.

After new writes, stop all writers again and run the same migrator with the current external root as source, a new unused recovery directory as destination, and `--layout legacy`. It preserves the latest collection, images, configuration and sessions without creating an external-mode marker. Point the old per-directory mounts at this recovery root, clear `LOCALNH_DATA_DIR`, and restart the compatible previous image. Never point directly at the stale pre-migration root. Both roots remain available until verification; do not merge image trees or overwrite the original backup. This procedure assumes the same database/configuration schema (as in 5B/5C); a later schema change needs its own rollback review.

For Docker maintenance, the standalone migrator can be mounted read-only into the already-tested image at `/var/www/html/script/py/migrate_storage.py` and run with `--entrypoint python3`. Mount the source read-only and the destination parent writable outside `/var/www/html`. Check container-visible paths carefully: source/destination arguments refer to maintenance-container paths, whereas Compose bind sources refer to host paths. Keep the maintenance container network disabled when no network operation is required.

## Interrupted snapshot imports (stage 5D)

Keep all writers stopped while inspecting `cache/localnh-import-*/recovery.json`. Journal replacement is atomic against process interruption; this is not a promise of durable storage after power loss. The `prepared` phase is written before the image backup finishes, so never assume its `previous-images` is complete. `backup-complete` confirms that copying finished before session revocation or image replacement. `images-saved` means image replacement has begun and the active DB/images may disagree. `complete` means import finished. `rolled-back` means a caught failure restored the previous collection; `recovery-failed` means restoration also failed and requires manual intervention. Backups remain available even after a restoration attempt.

For `prepared`, the import has not begun activating data; verify the existing active DB/images before restarting. For `backup-complete`, `images-saved`, `complete`, `rolled-back`, or `recovery-failed`, restoration from the retained `previous.sqlite` and `previous-images` is possible after verifying them. Recover immediately as part of the incident; restoring an old import backup after subsequent writes would discard those newer changes. Revoked sessions stay revoked; users must log in again.

The following offline Python procedure demonstrates restoring the chosen backup. Run it with the service stopped and the same mounts as the application, using the application account or preserving ownership afterwards. Replace `STAGE` with the specific import staging directory and `ROOT` with the selected data root, both as visible to that process/container. Legacy Docker ROOT is `/var/www/html`, external Docker ROOT is the configured data root (commonly `/var/lib/localnh`). Do not call ordinary storage helpers to find paths when interruption left `cache/images` absent: they correctly reject incomplete storage. Do not use this procedure for storage-migration staging directories, which have a different format.

```python
# Run in a maintenance process with every web/CLI writer stopped.
from pathlib import Path
import json, shutil, sqlite3

ROOT = Path('/var/lib/localnh').resolve(strict=True)
STAGE = (ROOT / 'cache/localnh-import-REPLACE_WITH_ACTUAL_ID').resolve(strict=True)
assert STAGE.parent == ROOT / 'cache' and STAGE.name.startswith('localnh-import-')
state = json.loads((STAGE / 'recovery.json').read_text())
assert state['database'] == str(ROOT / 'cache.sqlite')
assert state['images'] == str(ROOT / 'cache/images')
assert state['phase'] in {'backup-complete', 'images-saved', 'complete', 'rolled-back', 'recovery-failed'}
backup = STAGE / 'previous-images'
assert backup.is_dir() and not backup.is_symlink()
assert not any(p.is_symlink() for p in backup.rglob('*'))
previous = STAGE / 'previous.sqlite'
assert previous.is_file() and not previous.is_symlink()
with sqlite3.connect(previous.as_uri() + '?mode=ro', uri=True) as src:
    assert src.execute('PRAGMA integrity_check').fetchall() == [('ok',)]
    for cover, pages in src.execute('SELECT cover_path, pages_paths FROM galleries'):
        for logical in ([cover] if cover else []) + json.loads(pages or '[]'):
            parts = logical.split('/')
            assert parts[:2] == ['cache', 'images']
            assert all(p and not p.startswith('.') and '\\' not in p for p in parts)
            assert backup.joinpath(*parts[2:]).is_file()
    # This deliberately restores the old collection. Keep the backup untouched
    # so another attempt is possible if either operation below fails.
    active = ROOT / 'cache/images'
    assert not active.is_symlink()
    if active.exists():
        shutil.rmtree(active)
    shutil.copytree(backup, active)
    with sqlite3.connect(ROOT / 'cache.sqlite') as dst:
        src.backup(dst)
```

If recovery fails, keep writers stopped and correct the reported permission/space problem before repeating it. Do not delete `previous.sqlite`, `previous-images`, or the journal during recovery. Before starting the service, check SQLite integrity, gallery IDs/count, and all referenced image files; confirm that the configured application account can read/write the restored files. After startup, verify login and representative images. Keep the recovery backup until acceptance. A read-only application filesystem is not a supported Docker deployment promise here: the current entrypoint still prepares source directories in legacy mode, and PHP/Caddy also need their own runtime locations.
