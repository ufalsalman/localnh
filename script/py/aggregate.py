"""Bounded, read-only aggregate builder, invoked by the web job supervisor."""
import json, os, sqlite3, stat, sys
from storage import data_path

with sqlite3.connect(data_path('cache.sqlite').as_uri() + '?mode=ro', uri=True) as db:
    db.execute('BEGIN')
    if sys.argv[1] == 'stats':
        count, latest = db.execute('SELECT COUNT(*), MAX(created_at) FROM galleries').fetchone()
        total = 0
        seen = set()
        # Match apparent-size du semantics, including directories/hard-link dedup.
        # Do not follow symlinks or launch an unbounded external du process.
        root = data_path('cache')
        def add(path):
            global total
            info = path.lstat()
            identity = (info.st_dev, info.st_ino)
            if identity not in seen:
                seen.add(identity); total += info.st_size
        add(root)
        for directory, dirs, files in os.walk(root, followlinks=False):
            from pathlib import Path
            for name in dirs + files:
                try: add(Path(directory) / name)
                except FileNotFoundError: pass
        total += data_path('cache.sqlite').stat().st_size
        result = {'count': count, 'latest': latest or 0, 'bytes': total}
    elif sys.argv[1] == 'tags':
        result = {key: {} for key in ['tags', 'artists', 'circles', 'parodies', 'characters', 'languages']}
        unique = 0
        def add(group, value):
            global unique
            if not isinstance(value, str): return
            if value not in result[group]:
                unique += 1
                if unique > 50000: raise ValueError('Too many aggregate terms')
            result[group][value] = result[group].get(value, 0) + 1
        for language, raw in db.execute('SELECT language,tags FROM galleries'):
            if language: add('languages', language)
            try: tags = json.loads(raw or '{}')
            except (ValueError, TypeError): continue
            if not isinstance(tags, dict): continue
            for group in ['tags', 'artists', 'circles', 'parodies', 'characters']:
                values = tags.get(group, [])
                if isinstance(values, list):
                    for value in values: add(group, value)
    else:
        raise ValueError('Unknown aggregate')
    print(json.dumps(result, ensure_ascii=False))
