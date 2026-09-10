import os
import sys
import json
import shutil
import datetime
import subprocess
import sqlite3
from pathlib import Path

# Resolve the repository root from this file's location:
# __file__ -> script/py/snapshot.py -> script/py -> script -> ROOT
BASE_DIR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
# Optional config file that overrides the default "localnh" domain label used
# in snapshot file names.
CONFIG_DOMAIN = os.path.join(BASE_DIR, 'config', 'domain')
SNAPSHOT_DIR = os.path.join(BASE_DIR, 'snapshot')

def get_domain():
    # The domain label is purely cosmetic — it prefixes the snapshot directory
    # and file names. Defaults to 'localnh' if config/domain is absent/empty.
    if os.path.exists(CONFIG_DOMAIN):
        with open(CONFIG_DOMAIN, 'r') as f:
            domain = f.read().strip()
            if domain:
                return domain
    return 'localnh'

def export_snapshot():
    domain = get_domain()
    timestamp = datetime.datetime.now().strftime('%Y%m%d_%H%M%S')
    # Snapshot bundle name encodes the domain and timestamp for easy sorting.
    base_name = f"{domain}_snapshot_{timestamp}"
    dest_dir = os.path.join(SNAPSHOT_DIR, base_name)
    
    os.makedirs(dest_dir, exist_ok=True)
    print(f"[*] Created snapshot directory: {dest_dir}")
    
    # Copy the SQLite database file with metadata preservation (shutil.copy2).
    db_src = os.path.join(BASE_DIR, 'cache.sqlite')
    db_file = f"{base_name}_db.sqlite"
    db_dest = os.path.join(dest_dir, db_file)
    if os.path.exists(db_src):
        shutil.copy2(db_src, db_dest)
        print(f"[*] Exported database to: {db_file}")
    else:
        print("[!] Warning: cache.sqlite not found.")
        
    cache_dir = os.path.join(BASE_DIR, 'cache', 'images')
    # The prefix string is used by both tar/split (export) and glob (import)
    # to identify all archive parts belonging to this snapshot.
    cache_prefix = f"{base_name}_cache.tar.part"
    cache_dest_prefix = os.path.join(dest_dir, cache_prefix)
    
    if os.path.exists(cache_dir) and os.path.isdir(cache_dir):
        print(f"[*] Compressing and splitting cache/images into 500MB chunks (this may take a while)...")
        # Pipeline: tar streams the cache directory to stdout without writing a
        # temporary file; split cuts the stream into 500 MB numbered chunks
        # (-d = numeric suffixes, -b 500M = chunk size).
        # Running with shell=True and cwd=BASE_DIR ensures the tar paths are
        # relative (cache/images/...) so the archive can be extracted anywhere.
        cmd = f"tar -cf - cache/images | split -d -b 500M - {cache_dest_prefix}"
        process = subprocess.Popen(cmd, shell=True, cwd=BASE_DIR)
        process.wait()
        
        if process.returncode == 0:
            print(f"[*] Cache successfully compressed and split with prefix: {cache_prefix}")
        else:
            print(f"[!] Error compressing cache. Tar/split exited with code {process.returncode}")
    else:
        print("[!] Warning: cache/images directory not found or empty.")
        
    # Write a JSON manifest that records which files belong to this snapshot so
    # import_snapshot can locate them without user guesswork.
    index_file = f"{base_name}_index.json"
    index_dest = os.path.join(dest_dir, index_file)
    index_data = {
        "domain": domain,
        "timestamp": timestamp,
        "base_name": base_name,
        "db_file": db_file,
        "cache_prefix": cache_prefix
    }
    with open(index_dest, 'w') as f:
        json.dump(index_data, f, indent=4)
    print(f"[*] Created manifest: {index_file}")
    print(f"[*] Snapshot export completed successfully!")

def revoke_login_sessions():
    # Separate session storage is never restored with collection snapshots.
    session_dir = os.environ.get('LOCALNH_SESSION_DIR') or os.path.join(BASE_DIR, 'sessions')
    session_db = os.path.join(session_dir, 'sessions.sqlite')
    try:
        Path(session_db).stat()
    except FileNotFoundError:
        return
    # Permission errors abort import; never treat unreadable storage as absent.
    with sqlite3.connect(Path(session_db).resolve().as_uri() + '?mode=rw', uri=True, timeout=5) as sessions:
        if sessions.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='sessions'").fetchone():
            sessions.execute('DELETE FROM sessions')


def import_snapshot(index_path):
    if not os.path.exists(index_path):
        print(f"[!] Error: Index file not found at {index_path}")
        sys.exit(1)
        
    # Resolve the directory that contains the index.json file; all other
    # snapshot files are expected to be in the same directory.
    index_dir = os.path.dirname(os.path.abspath(index_path))
    
    with open(index_path, 'r') as f:
        try:
            index_data = json.load(f)
        except Exception as e:
            print(f"[!] Error reading JSON: {e}")
            sys.exit(1)
            
    db_file = index_data.get('db_file')
    cache_prefix = index_data.get('cache_prefix')
    
    # Both fields are required; abort early with a clear error rather than
    # failing later with a cryptic KeyError.
    if not db_file or not cache_prefix:
        print("[!] Error: Invalid index.json format. Missing db_file or cache_prefix.")
        sys.exit(1)
        
    db_src = os.path.join(index_dir, db_file)
    # Restore the database directly into the repository root, overwriting any
    # existing cache.sqlite (intentional — import replaces the current DB).
    db_dest = os.path.join(BASE_DIR, 'cache.sqlite')
    if os.path.exists(db_src):
        print(f"[*] Restoring database from {db_file}...")
        revoke_login_sessions()
        shutil.copy2(db_src, db_dest)
    else:
        print(f"[!] Warning: Database file {db_file} not found in snapshot directory.")
        
    import glob
    # Glob for all split chunks matching the prefix (e.g. prefix00, prefix01…)
    cache_parts = glob.glob(os.path.join(index_dir, f"{cache_prefix}*"))
    
    if cache_parts:
        print(f"[*] Found {len(cache_parts)} cache archive parts. Recombining and extracting (this may take a while)...")
        # cat concatenates the numbered chunks back into a single stream; tar
        # reads from stdin (-xf -) and extracts into BASE_DIR, restoring the
        # original cache/images directory structure.
        cmd = f"cat {os.path.join(index_dir, cache_prefix)}* | tar -xf -"
        process = subprocess.Popen(cmd, shell=True, cwd=BASE_DIR)
        process.wait()
        
        if process.returncode == 0:
            print(f"[*] Cache successfully restored!")
        else:
            print(f"[!] Error extracting cache. Cat/tar exited with code {process.returncode}")
    else:
        print(f"[!] Warning: No cache archive parts found matching prefix {cache_prefix}")
        
    print(f"[*] Snapshot import completed successfully!")

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage:")
        print("  python snapshot.py export")
        print("  python snapshot.py import <path_to_index.json>")
        sys.exit(1)
        
    action = sys.argv[1]
    
    if action == 'export':
        export_snapshot()
    elif action == 'import':
        if len(sys.argv) < 3:
            print("Error: Missing path to index.json")
            print("Usage: python snapshot.py import <path_to_index.json>")
            sys.exit(1)
        import_snapshot(sys.argv[2])
    else:
        print(f"Error: Unknown action '{action}'")
        sys.exit(1)
