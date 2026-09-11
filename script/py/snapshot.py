"""Portable collection snapshots. Callers must stop web/CLI writers first.

--offline is an explicit operator assertion, not an automatic service stop/lock.
No secrets, sessions, or absolute storage paths are included in exported bundles.
"""
import argparse
import datetime
import io
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import tarfile
import tempfile

from storage import data_path, session_dir


def require_offline(offline):
    if not offline:
        raise ValueError('Stop all web/CLI writers, then pass --offline; live snapshots are not supported')


def sqlite_backup(source, destination):
    with sqlite3.connect(Path(source).resolve().as_uri() + '?mode=ro', uri=True) as src:
        if src.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
            raise ValueError('Invalid SQLite database')
        if not src.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='galleries'").fetchone():
            raise ValueError('Snapshot does not contain a collection database')
        with sqlite3.connect(destination) as dst:
            src.backup(dst)


class SplitWriter(io.RawIOBase):
    def __init__(self, folder, prefix, size=500 * 1024 * 1024):
        self.folder, self.prefix, self.limit = folder, prefix, size
        self.index, self.used, self.part = 0, 0, None

    def writable(self):
        return True

    def write(self, data):
        view = memoryview(data)
        while view:
            if self.part is None:
                self.part = (self.folder / f'{self.prefix}{self.index:02d}').open('xb')
            amount = min(len(view), self.limit - self.used)
            self.part.write(view[:amount]); view = view[amount:]; self.used += amount
            if self.used == self.limit:
                self.part.close(); self.part = None; self.index += 1; self.used = 0
        return len(data)

    def close(self):
        if self.part:
            self.part.close()
        super().close()


def export_snapshot(*, offline=False):
    require_offline(offline)
    domain_path = data_path('config/domain')
    domain = domain_path.read_text().strip() if domain_path.exists() else 'localnh'
    domain = re.sub(r'[^A-Za-z0-9_.-]', '_', domain).strip('.') or 'localnh'
    stamp = datetime.datetime.now().strftime('%Y%m%d_%H%M%S_%f')
    base = f'{domain}_snapshot_{stamp}'
    folder = data_path('snapshot') / base
    folder.mkdir(parents=True)
    try:
        db_file, prefix = base + '_db.sqlite', base + '_cache.tar.part'
        sqlite_backup(data_path('cache.sqlite'), folder / db_file)
        cache = data_path('cache/images')
        def safe_member(member):
            if not (member.isdir() or member.isfile()):
                raise ValueError('Cache contains a link or special file')
            validate_member_name(member.name)
            return member
        with SplitWriter(folder, prefix) as out:
            with tarfile.open(fileobj=out, mode='w|', dereference=False) as tar:
                tar.add(cache, arcname='cache/images', filter=safe_member)
        manifest = folder / (base + '_index.json')
        manifest.write_text(json.dumps({'domain': domain, 'timestamp': stamp, 'base_name': base,
                                        'db_file': db_file, 'cache_prefix': prefix}, indent=2))
        print('Snapshot export completed: ' + str(manifest))
        return manifest
    except BaseException:
        shutil.rmtree(folder)
        raise


def revoke_login_sessions():
    path = session_dir() / 'sessions.sqlite'
    if path.is_symlink():
        raise ValueError('Session database cannot be a symlink')
    try:
        path.stat()
    except FileNotFoundError:
        return
    with sqlite3.connect(path.resolve().as_uri() + '?mode=rw', uri=True, timeout=5) as sessions:
        if sessions.execute("SELECT 1 FROM sqlite_master WHERE name='sessions'").fetchone():
            sessions.execute('DELETE FROM sessions')


def bundle_name(value):
    if not isinstance(value, str) or not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]*', value):
        raise ValueError('Unsafe snapshot manifest filename')
    return value


def validate_member_name(name):
    parts = name.rstrip('/').split('/')
    if parts[:2] != ['cache', 'images'] or any(not p or p.startswith('.') or '\\' in p or '%' in p or any(ord(c) < 32 for c in p) for p in parts):
        raise ValueError('Unsafe snapshot member path')
    return parts


def write_recovery_journal(path, phase, database, images):
    """Publish a complete journal record; a process kill cannot truncate it.

    This does not guarantee filesystem durability across power loss.
    backup-complete and later phases mean previous-images finished copying.
    """
    temporary = path.with_suffix('.json.tmp')
    temporary.write_text(json.dumps({'phase': phase, 'database': str(database),
                                     'images': str(images)}, indent=2))
    temporary.replace(path)


def import_snapshot(index_path, *, offline=False):
    require_offline(offline)
    manifest = Path(index_path)
    if manifest.is_symlink():
        raise ValueError('Manifest must not be a symlink')
    info = json.loads(manifest.read_text())
    db_file, prefix = bundle_name(info.get('db_file')), bundle_name(info.get('cache_prefix'))
    bundle = manifest.resolve().parent
    source_db = bundle / db_file
    if not source_db.is_file() or source_db.is_symlink():
        raise ValueError('Snapshot database missing or unsafe')
    parts = []
    for path in bundle.iterdir():
        if path.name.startswith(prefix):
            suffix = path.name[len(prefix):]
            if not re.fullmatch(r'\d{2,}', suffix) or path.is_symlink() or not path.is_file():
                raise ValueError('Invalid snapshot part')
            parts.append((int(suffix), path))
    parts.sort()
    if not parts or [n for n, _ in parts] != list(range(len(parts))):
        raise ValueError('Missing or duplicate snapshot part')
    # Stage and rename images on the cache filesystem, including legacy bind mounts.
    stage = Path(tempfile.mkdtemp(prefix='localnh-import-', dir=data_path('cache')))
    activating = False
    try:
        sqlite_backup(source_db, stage / 'new.sqlite')
        archive = stage / 'incoming.tar'
        with archive.open('xb') as out:
            for _, part in parts:
                with part.open('rb') as stream:
                    shutil.copyfileobj(stream, out)
        seen = set()
        with tarfile.open(archive, 'r:') as tar:
            for member in tar:
                relative = '/'.join(validate_member_name(member.name))
                if relative in seen or not (member.isfile() or member.isdir()):
                    raise ValueError('Duplicate, linked or special snapshot member')
                seen.add(relative)
                target = stage / 'new' / relative
                if member.isdir():
                    target.mkdir(parents=True, exist_ok=True, mode=0o750)
                else:
                    target.parent.mkdir(parents=True, exist_ok=True, mode=0o750)
                    with tar.extractfile(member) as src, target.open('xb') as dst:
                        shutil.copyfileobj(src, dst)
                    target.chmod(0o640)
        archive.unlink()
        new_images = stage / 'new/cache/images'
        if not new_images.is_dir():
            raise ValueError('Snapshot has no cache/images directory')
        # Validate every stored image reference before activating the collection.
        with sqlite3.connect(stage / 'new.sqlite') as db:
            for cover, pages in db.execute('SELECT cover_path,pages_paths FROM galleries'):
                paths = json.loads(pages or '[]')
                if not isinstance(paths, list):
                    raise ValueError('Invalid snapshot page list')
                for logical in ([cover] if cover else []) + paths:
                    if not isinstance(logical, str):
                        raise ValueError('Invalid snapshot image reference')
                    validate_member_name(logical)
                    if not (stage / 'new' / logical).is_file():
                        raise ValueError('Snapshot references a missing image')
        destination = data_path('cache.sqlite')
        active_images = data_path('cache/images')
        sqlite_backup(destination, stage / 'previous.sqlite')
        journal = stage / 'recovery.json'
        write_recovery_journal(journal, 'prepared', destination, active_images)
        if os.path.ismount(active_images):
            raise ValueError('Mount the cache parent, not cache/images itself, for snapshot replacement')
        # Copy before activation: overlay filesystems can reject directory renames
        # with EXDEV even on a single mount. A failed backup cannot alter live data.
        shutil.copytree(active_images, stage / 'previous-images', symlinks=True)
        write_recovery_journal(journal, 'backup-complete', destination, active_images)
        revoke_login_sessions()
        activating = True
        try:
            shutil.rmtree(active_images)
            write_recovery_journal(journal, 'images-saved', destination, active_images)
            new_images.rename(active_images)
            # Backup into the existing database works with legacy file bind mounts.
            sqlite_backup(stage / 'new.sqlite', destination)
        except BaseException:
            try:
                if active_images.exists():
                    shutil.rmtree(active_images)
                # Retain the backup so a second recovery attempt remains possible
                # if restoring either the images or the database fails.
                shutil.copytree(stage / 'previous-images', active_images, symlinks=True)
                sqlite_backup(stage / 'previous.sqlite', destination)
                write_recovery_journal(journal, 'rolled-back', destination, active_images)
            except BaseException:
                write_recovery_journal(journal, 'recovery-failed', destination, active_images)
                raise
            raise
        write_recovery_journal(journal, 'complete', destination, active_images)
        print('Snapshot import completed. Recovery data retained at: ' + str(stage))
        return stage
    except BaseException:
        if not activating:
            shutil.rmtree(stage)
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['export', 'import'])
    parser.add_argument('index', nargs='?')
    parser.add_argument('--offline', action='store_true', help='Confirm all web/CLI writers have been stopped')
    args = parser.parse_args()
    if args.action == 'export':
        export_snapshot(offline=args.offline)
    elif args.index:
        import_snapshot(args.index, offline=args.offline)
    else:
        parser.error('import requires the manifest path')
