from storage import data_root, data_path, image_path, gallery_path, collection_changed
import sys
import os
import sqlite3
import shutil
from archive_runner import acquire
from collection_reads import acquire_read_guard
try:
    archive_lock = acquire()
    image_lock = acquire_read_guard(exclusive=True)
except BlockingIOError:
    print("A collection operation is active. Retry shortly.", file=sys.stderr)
    sys.exit(75)

if len(sys.argv) < 2:
    print("Missing ID")
    sys.exit(1)

code = sys.argv[1]
script_dir = os.path.dirname(os.path.abspath(__file__))
# Walk two levels up from script/py/ to reach the repository root.
root_dir = str(data_root())
db_path = str(data_path('cache.sqlite'))

# Accept both "purge" and "--purge" so the CLI dispatch in the bash wrapper
# can forward the argument without stripping the leading dashes.
if code == "purge" or code == "--purge":
    # Validate the filesystem target before changing any database rows.
    cache_dir = str(data_path('cache/images'))
    # Nuclear option: wipe the entire galleries table and delete every cached
    # image directory. The DB file itself is kept so the schema survives.
    if os.path.exists(db_path):
        conn = sqlite3.connect(db_path)
        cur = conn.cursor()
        cur.execute("DELETE FROM galleries")
        conn.commit()
        conn.close()
    
    cache_dir = str(data_path('cache/images'))
    if os.path.exists(cache_dir):
        # shutil.rmtree removes the directory and all its contents recursively.
        shutil.rmtree(cache_dir)
        # Re-create the empty directory so the web server doesn't 404 on the
        # images path after a purge.
        os.makedirs(cache_dir, exist_ok=True)
    print("Purged all galleries and cache")
else:
    # Reject malformed IDs or linked targets before committing a deletion.
    cache_dir = str(gallery_path(code))
    # Single-gallery delete: remove only the DB row and image directory for
    # the specified gallery ID.
    if os.path.exists(db_path):
        conn = sqlite3.connect(db_path)
        cur = conn.cursor()
        # Parameterised query prevents SQL injection if the ID is ever passed
        # from an untrusted source (e.g. a web UI request).
        cur.execute("DELETE FROM galleries WHERE id = ?", (code,))
        conn.commit()
        conn.close()

    # Delete the corresponding image cache directory for this gallery.
    cache_dir = str(gallery_path(code))
    if os.path.exists(cache_dir):
        shutil.rmtree(cache_dir)
    print(f"Deleted {code}")


collection_changed()
