"""ZIP/CBZ generation for the bounded web worker; no shell or collection writes."""
import json, sqlite3, sys, zipfile
from storage import data_path, image_path

with sqlite3.connect(data_path('cache.sqlite')) as db:
    row = db.execute('SELECT pages_paths FROM galleries WHERE id=?', (sys.argv[1],)).fetchone()
if not row:
    raise ValueError('Gallery missing')
with zipfile.ZipFile(sys.argv[2], 'w', compression=zipfile.ZIP_STORED, allowZip64=True) as archive:
    for item in json.loads(row[0]):
        path = image_path(item)
        if path.is_file():
            archive.write(path, path.name)
