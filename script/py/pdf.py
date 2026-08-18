import sys, os
from PIL import Image
import sqlite3
import json

try:
    # argv[1]: gallery numeric ID  argv[2]: absolute output path for the PDF.
    id = sys.argv[1]
    out_path = sys.argv[2]
    script_dir = os.path.dirname(os.path.abspath(__file__))
    # Walk two levels up (script/py -> script -> repo root) to reach the DB.
    root_dir = os.path.dirname(os.path.dirname(script_dir))
    
    db_path = os.path.join(root_dir, "cache.sqlite")
    conn = sqlite3.connect(db_path)
    cur = conn.cursor()
    # pages_paths is stored as a JSON array of relative file paths, e.g.
    # ["cache/images/123/1.webp", "cache/images/123/2.webp", ...].
    cur.execute("SELECT pages_paths FROM galleries WHERE id = ?", (id,))
    row = cur.fetchone()
    conn.close()
    
    # Exit with error code 1 if the gallery ID is not in the DB.
    if not row: sys.exit(1)
    
    pages = json.loads(row[0])
    imgs = []
    for p in pages:
        # Resolve relative paths against the repository root.
        full_path = os.path.join(root_dir, p)
        if os.path.exists(full_path):
            # PDF export requires RGB mode; WebP images were already converted
            # during download, but this is a safeguard for any edge cases.
            img = Image.open(full_path).convert('RGB')
            imgs.append(img)
            
    if imgs:
        # Pillow's PDF writer uses the first image as the document start and
        # appends the rest as additional pages via append_images.
        imgs[0].save(out_path, format="PDF", save_all=True, append_images=imgs[1:])
        sys.exit(0)
    else:
        # No images found on disk (cache missing); signal failure to the caller.
        sys.exit(1)
except Exception as e:
    print(e)
    sys.exit(1)

