#!/usr/bin/env python3
"""Offline storage copy. Never switches deployment or overwrites a destination.

Stop ALL writers first. --offline is an acknowledgement, not a writer lock.
Source must contain config, cache.sqlite, cache/images, snapshot and sessions;
assemble separate legacy mounts into that layout before using this tool.
Run with permission to preserve the application's ownership (root in Docker).
An interrupted copy leaves a hidden staging directory for inspection/recovery.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import stat
import tempfile

PARTS = ('config', 'cache.sqlite', 'cache', 'snapshot', 'sessions')


def sqlite_file(path):
    with path.open('rb') as stream:
        return stream.read(16) == b'SQLite format 3\x00'


def database(path):
    connection = sqlite3.connect(path.as_uri() + '?mode=ro', uri=True)
    if connection.execute('PRAGMA integrity_check').fetchall() != [('ok',)]:
        connection.close()
        raise ValueError('SQLite integrity check failed')
    return connection


def digest(path):
    h = hashlib.sha256()
    if sqlite_file(path):
        with database(path) as connection:
            for line in connection.iterdump():
                h.update(line.encode() + b'\n')
        connection.close()
    else:
        with path.open('rb') as stream:
            for block in iter(lambda: stream.read(1024 * 1024), b''):
                h.update(block)
    return h.hexdigest()


def inventory(root):
    result = {}
    def visit(path):
        info = path.lstat()
        if not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)):
            raise ValueError('Links and special files are unsupported')
        relative = path.relative_to(root).as_posix()
        if path.is_file() and path.name.endswith(('-wal', '-shm', '-journal')):
            base = path.with_name(path.name.rsplit('-', 1)[0])
            if base.is_file() and not base.is_symlink() and sqlite_file(base):
                return  # SQLite backup reads committed state, not raw sidecars.
        result[relative] = [stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid,
                            'dir' if path.is_dir() else digest(path)]
        if path.is_dir():
            for child in sorted(path.iterdir()):
                visit(child)
    for part in PARTS:
        visit(root / part)
    return result


def validate(root):
    for part in ('config', 'cache/images', 'snapshot', 'sessions'):
        if not (root / part).is_dir():
            raise ValueError('Missing required storage directory: ' + part)
    with database(root / 'cache.sqlite') as connection:
        ids = [row[0] for row in connection.execute('SELECT id FROM galleries ORDER BY id')]
        for cover, pages in connection.execute('SELECT cover_path, pages_paths FROM galleries'):
            for value in ([cover] if cover else []) + json.loads(pages or '[]'):
                if not isinstance(value, str) or not value.startswith('cache/images/'):
                    raise ValueError('Invalid database image reference')
                pieces = value.split('/')
                if any(not p or p.startswith('.') for p in pieces) or '\\' in value:
                    raise ValueError('Unsafe database image reference')
                if not (root / value).is_file():
                    raise ValueError('A database image reference is missing')
    connection.close()
    return ids


def migrate(source, destination, layout):
    source = Path(source).absolute()
    destination = Path(destination).absolute()
    # Reject aliases rather than silently selecting another directory.
    if source.resolve() != source or destination.resolve() != destination:
        raise ValueError('Use canonical paths without symlink aliases')
    if source == destination or source in destination.parents or destination in source.parents:
        raise ValueError('Source and destination must be disjoint')
    if destination.exists():
        raise ValueError('Destination must not exist; never merge or overwrite')
    application = Path(__file__).resolve().parents[2]
    if layout == 'external' and (destination == application or application in destination.parents or destination in application.parents):
        raise ValueError('External destination must be outside the application')
    before = inventory(source)
    ids = validate(source)
    needed = sum((source / name).stat().st_size for name, meta in before.items() if meta[3] != 'dir')
    if shutil.disk_usage(destination.parent).free < needed * 2 + 256 * 1024 * 1024:
        raise ValueError('Insufficient free space: require two copy sizes plus 256 MiB reserve')
    staging = Path(tempfile.mkdtemp(prefix='.' + destination.name + '-staging-', dir=destination.parent))
    # On failure retain staging, never activate it or print secret file contents.
    try:
        for name, meta in before.items():
            src, dst = source / name, staging / name
            if meta[3] == 'dir':
                dst.mkdir()
            elif sqlite_file(src):
                with database(src) as old, sqlite3.connect(dst) as new:
                    old.backup(new)
                old.close()
                new.close()
            else:
                shutil.copyfile(src, dst)
        # Apply metadata after creating children, allowing restrictive directories.
        for name, meta in reversed(list(before.items())):
            dst = staging / name
            if os.geteuid() == 0:
                os.chown(dst, meta[1], meta[2])
            os.chmod(dst, meta[0])
        if inventory(staging) != before or inventory(source) != before or validate(staging) != ids:
            raise ValueError('Verification failed or source changed during copy')
        if layout == 'external':
            (staging / '.localnh-storage.json').write_text('{"version":1}\n')
        # Private report contains hashes/paths/ownership, never config contents.
        report = staging / '.localnh-migration.json'
        report.write_text(json.dumps({'version': 1, 'source': str(source), 'layout': layout,
                                      'gallery_count': len(ids), 'inventory': before}, indent=2) + '\n')
        report.chmod(0o600)
        info = source.stat()
        if os.geteuid() == 0:
            os.chown(staging, info.st_uid, info.st_gid)
        staging.chmod(stat.S_IMODE(info.st_mode))
        # Check again; operator must exclusively own the destination namespace.
        if destination.exists():
            raise ValueError('Destination appeared during copy')
        staging.rename(destination)
        print(json.dumps({'result': 'PASS', 'galleries': len(ids), 'entries': len(before),
                          'destination': str(destination), 'layout': layout}))
    except Exception:
        print('Copy not activated. Inspect retained staging: ' + str(staging))
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True)
    parser.add_argument('--destination', required=True)
    parser.add_argument('--layout', choices=('external', 'legacy'), required=True)
    parser.add_argument('--offline', action='store_true', required=True)
    args = parser.parse_args()
    migrate(args.source, args.destination, args.layout)
