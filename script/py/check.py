#!/usr/bin/env python3
"""Read-only completeness check for cached gallery files."""
from __future__ import annotations
import argparse, json, sqlite3, sys
from pathlib import Path
from storage import data_path

def inspect(row):
    root=data_path('cache/images')/row['id']; missing=[]; inaccessible=False
    try: entries={p.name for p in root.iterdir()}
    except PermissionError: return None, True
    except FileNotFoundError: entries=set()
    cover=Path(str(row['cover_path'] or '')).name or 'cover.webp'
    if cover not in entries: missing.append(cover)
    try: paths=json.loads(row['pages_paths'] or '[]')
    except json.JSONDecodeError: paths=[]
    expected=int(row['page_count'] or len(paths))
    for i in range(1,expected+1):
        name=f'{i}.webp'
        if name not in entries: missing.append(name)
    return (expected,missing),inaccessible

def main():
    p=argparse.ArgumentParser(description='read-only cache completeness check')
    p.add_argument('target', help='numeric gallery ID or all')
    a=p.parse_args(); db=data_path('cache.sqlite')
    con=sqlite3.connect(db); con.row_factory=sqlite3.Row
    if a.target=='all': rows=con.execute('SELECT id,page_count,pages_paths,cover_path FROM galleries ORDER BY id').fetchall()
    elif a.target.isdigit(): rows=con.execute('SELECT id,page_count,pages_paths,cover_path FROM galleries WHERE id=?',(a.target,)).fetchall()
    else: raise SystemExit('target must be a numeric gallery id or all')
    if not rows: raise SystemExit('gallery not found')
    complete=broken=blocked=0
    for row in rows:
        result, denied=inspect(row)
        if denied: blocked+=1; print(f"#{row['id']}: inaccessible (check permissions)"); continue
        expected,missing=result
        if missing: broken+=1; print(f"#{row['id']}: incomplete ({len(missing)} missing of {expected} pages): {', '.join(missing[:12])}{' ...' if len(missing)>12 else ''}")
        else: complete+=1; print(f"#{row['id']}: complete ({expected} pages)")
    print(f'finished: {complete} complete, {broken} incomplete, {blocked} inaccessible, {len(rows)} checked')
    return 1 if broken or blocked else 0
if __name__=='__main__':
 try: raise SystemExit(main())
 except OSError as e: print(f'error: {e}',file=sys.stderr); raise SystemExit(1)
