"""Filesystem contract shared with storage.php. Importing never initializes data."""
import json
import os
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[2]
MARKER = '.localnh-storage.json'


def absolute_external(value):
    path = Path(value)
    if not path.is_absolute() or any(x in ('.', '..') for x in value.split('/')) or '\\' in value or any(ord(c) < 32 for c in value):
        raise ValueError('Storage root must be an absolute path')
    path = path.resolve()
    if path == SOURCE or SOURCE in path.parents or path in SOURCE.parents:
        raise ValueError('Storage root must be outside source')
    return path


def data_root():
    value = os.environ.get('LOCALNH_DATA_DIR', '')
    if not value:
        return SOURCE
    root = absolute_external(value)
    marker = root / MARKER
    if not root.is_dir() or not os.access(root, os.R_OK | os.W_OK):
        raise ValueError('Storage root unavailable')
    if marker.is_symlink() or not marker.is_file():
        raise ValueError('Storage not initialized')
    state = json.loads(marker.read_text())
    if not isinstance(state, dict) or type(state.get('version')) is not int or state.get('version') != 1:
        raise ValueError('Storage not initialized; use explicit storage init or migration')
    if not (root / 'cache.sqlite').is_file() or (root / 'cache.sqlite').is_symlink():
        raise ValueError('Storage database unavailable')
    for relative in ('config', 'cache', 'cache/images', 'snapshot'):
        current = root
        for part in relative.split('/'):
            current = current / part
            if current.is_symlink() or not current.is_dir():
                raise ValueError('Storage directory unavailable or linked')
    return root


def data_path(relative):
    if not isinstance(relative, str) or not relative or relative.startswith('/') or '\\' in relative or '%' in relative or any(ord(c) < 32 for c in relative):
        raise ValueError('Invalid data path')
    parts = relative.split('/')
    if any(not part or part.startswith('.') for part in parts):
        raise ValueError('Invalid data path')
    path = data_root()
    for part in parts:
        path = path / part
        if path.is_symlink():
            raise ValueError('Data symlinks are not supported')
    return path


def image_path(logical):
    if not isinstance(logical, str) or not logical.startswith('cache/images/'):
        raise ValueError('Invalid image path')
    return data_path(logical)


def gallery_path(gallery_id):
    value = str(gallery_id)
    if not value.isascii() or not value.isdecimal():
        raise ValueError('Gallery ID must be numeric')
    return data_path('cache/images/' + value)


def session_dir():
    value = os.environ.get('LOCALNH_SESSION_DIR', '')
    if not value:
        return data_path('sessions')
    if not Path(value).is_absolute() or any(x in ('.', '..') for x in value.split('/')) or '\\' in value or any(ord(c) < 32 for c in value):
        raise ValueError('Session root must be absolute')
    resolved = Path(value).resolve()
    if resolved == SOURCE or resolved in SOURCE.parents:
        raise ValueError('Unsafe session root')
    return absolute_external(value) if os.environ.get('LOCALNH_DATA_DIR') else resolved


def initialize_empty():
    """Explicit first installation only; never adopts/overwrites existing data."""
    import shutil
    import sqlite3
    value = os.environ.get('LOCALNH_DATA_DIR', '')
    if not value:
        raise ValueError('Set LOCALNH_DATA_DIR for explicit initialization')
    root = absolute_external(value)
    if root.exists() and (not root.is_dir() or any(root.iterdir())):
        raise ValueError('Initialization requires an empty destination')
    # Do not accidentally initialize a new collection over a legacy installation.
    for relative in ('cache.sqlite', 'config', 'cache/images', 'snapshot'):
        old = SOURCE / relative
        if old.is_file() or (old.is_dir() and any(p.name != '.gitkeep' for p in old.iterdir())):
            raise ValueError('Legacy data detected; use migration, not init')
    root.mkdir(parents=True, exist_ok=True, mode=0o750)
    for relative in ('config', 'cache/images', 'snapshot', 'sessions'):
        (root / relative).mkdir(parents=True, exist_ok=True, mode=0o750)
    (root / 'sessions').chmod(0o700)
    shutil.copytree(SOURCE / 'config.example', root / 'config', dirs_exist_ok=True)
    with sqlite3.connect(root / 'cache.sqlite') as db:
        db.execute('CREATE TABLE galleries (id TEXT PRIMARY KEY, title TEXT, title_jp TEXT, language TEXT, scanlator TEXT, tags TEXT, cover_path TEXT, pages_paths TEXT, page_count INTEGER, upload_date INTEGER, created_at INTEGER, raw_api_data TEXT)')
    (root / MARKER).write_text(json.dumps({'version': 1}) + '\n')
    print('Initialized empty external storage. No legacy data was moved.')


if __name__ == '__main__':
    import sys
    if sys.argv[1:] == ['init']:
        initialize_empty()
    elif sys.argv[1:] == ['paths']:
        print(json.dumps({'data': str(data_root()), 'sessions': str(session_dir()), 'source': str(SOURCE)}))
    else:
        raise SystemExit('Usage: localnh storage init|paths')
