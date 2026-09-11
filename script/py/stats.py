from storage import data_root, data_path, image_path, gallery_path
import os
import sys
import sqlite3
import time
import datetime
import subprocess

# Resolve repository root directory from this script's location
script_dir = os.path.dirname(os.path.abspath(__file__))
root_dir = str(data_root())
db_path = str(data_path('cache.sqlite'))
cache_dir = str(data_path('cache'))

# Query database for total gallery count and latest archive timestamp
titles_count = 0
latest_ts = 0
if os.path.exists(db_path):
    try:
        conn = sqlite3.connect(db_path)
        cur = conn.cursor()
        cur.execute("SELECT COUNT(*), MAX(created_at) FROM galleries")
        row = cur.fetchone()
        if row:
            titles_count = row[0] or 0
            latest_ts = row[1] or 0
        conn.close()
    except Exception as e:
        print(f"Error querying database: {e}", file=sys.stderr)

# Calculate total disk space used by cache/ and cache.sqlite
total_bytes = 0
if os.path.exists(cache_dir):
    try:
        # Fast disk usage lookup via du on Linux/Unix systems
        res = subprocess.run(["du", "-sb", cache_dir], capture_output=True, text=True, check=False)
        if res.returncode == 0 and res.stdout:
            total_bytes = int(res.stdout.split()[0])
    except Exception:
        # Portable fallback via os.walk
        for root, dirs, files in os.walk(cache_dir):
            for f in files:
                fp = os.path.join(root, f)
                if os.path.exists(fp):
                    total_bytes += os.path.getsize(fp)

if os.path.exists(db_path):
    total_bytes += os.path.getsize(db_path)

def format_bytes(b):
    units = ["B", "KB", "MB", "GB", "TB"]
    idx = 0
    val = float(b)
    while val >= 1024.0 and idx < len(units) - 1:
        val /= 1024.0
        idx += 1
    return f"{val:.2f} {units[idx]}"

# Format latest archive activity timestamp in user local timezone: YYYY/MM/DD HH:mm:ss (UTC+X)
if latest_ts:
    dt = datetime.datetime.fromtimestamp(latest_ts).astimezone()
    offset = dt.utcoffset()
    if offset is not None:
        total_seconds = int(offset.total_seconds())
        sign = "+" if total_seconds >= 0 else "-"
        total_seconds = abs(total_seconds)
        hours = total_seconds // 3600
        mins = (total_seconds % 3600) // 60
        tz_str = f"UTC{sign}{hours}:{mins:02d}" if mins else f"UTC{sign}{hours}"
    else:
        tz_str = "UTC"
    activity_str = dt.strftime(f"%Y/%m/%d %H:%M:%S ({tz_str})")
else:
    activity_str = "-"

print(f"Titles archived : {titles_count}")
print(f"Space used      : {format_bytes(total_bytes)}")
print(f"Latest activity : {activity_str}")
