#!/usr/bin/env python3
"""Check and repair missing cached gallery images."""
from __future__ import annotations
import argparse, io, json, sqlite3, sys
from pathlib import Path
from storage import data_path
from curl_cffi import requests
from PIL import Image


def main():
    p=argparse.ArgumentParser(description='check and repair missing gallery images')
    p.add_argument('gallery_id', help='numeric LocalNH gallery ID')
    p.add_argument('--overwrite', action='store_true', help='replace existing files')
    args=p.parse_args()
    if not args.gallery_id.isdigit(): raise SystemExit('gallery_id must contain only digits')
    db=data_path('cache.sqlite')
    con=sqlite3.connect(db); con.row_factory=sqlite3.Row
    row=con.execute('SELECT id,page_count,pages_paths,raw_api_data,cover_path FROM galleries WHERE id=?',(args.gallery_id,)).fetchone(); con.close()
    if row is None: raise SystemExit(f'gallery #{args.gallery_id} not found')
    try: paths=json.loads(row['pages_paths'] or '[]'); raw=json.loads(row['raw_api_data'] or '{}')
    except json.JSONDecodeError as e: raise SystemExit(f'invalid gallery metadata: {e}')
    api_pages=raw.get('pages',[]) if isinstance(raw,dict) else []
    expected=int(row['page_count'] or len(paths))
    cover_path=row['cover_path']
    # The cover is stored as its own DB row value (cover_path) rather than as a
    # page, so repair must treat it as a first-class missing file, not just pages.
    api_cover=raw.get('cover',{}) if isinstance(raw,dict) else {}
    if not isinstance(api_cover,dict): api_cover={}
    # The CLI user must traverse and write this directory; Docker may create it under a different UID.
    root=data_path('cache/images')/args.gallery_id; root.mkdir(parents=True,exist_ok=True)
    missing=[]
    for i in range(1,expected+1):
        target=root/f'{i}.webp'
        # Permission errors here are operational access problems, not missing pages.
        if args.overwrite or not target.is_file(): missing.append(i)
    cover_missing=(args.overwrite or not (data_path(cover_path).is_file() if cover_path else False)) and bool(api_cover.get('path'))
    if not missing and not cover_missing:
        print(f'gallery #{args.gallery_id}: complete ({expected} pages)'); return 0
    print(f'gallery #{args.gallery_id}: {len(missing) + (1 if cover_missing else 0)} file(s) missing')
    s=requests.Session(); s.impersonate='chrome110'
    for i in missing:
        if i>len(api_pages) or not isinstance(api_pages[i-1],dict) or not api_pages[i-1].get('path'):
            print(f'page {i}: no upstream path',file=sys.stderr); continue
        url='https://i.nhentai.net/'+api_pages[i-1]['path']; tmp=root/f'{i}.webp.part'; target=root/f'{i}.webp'
        try:
            response=s.get(url,timeout=30,doh_url='https://1.1.1.1/dns-query')
            if response.status_code!=200: raise RuntimeError(f'HTTP {response.status_code}')
            with Image.open(io.BytesIO(response.content)) as image:
                if image.mode in ('RGBA','P'): image=image.convert('RGB')
                image.save(tmp,'WEBP',quality=85)
            tmp.replace(target); print(f'page {i}: repaired')
        except Exception as e:
            tmp.unlink(missing_ok=True); print(f'page {i}: failed: {e}',file=sys.stderr)
    if cover_missing:
        # Covers live on the thumbnail host, not the page host, following the
        # same API path recorded in raw_api_data as everything else.
        cover_target=data_path(cover_path); tmp=cover_target.with_suffix('.webp.part')
        try:
            response=s.get('https://t.nhentai.net/'+api_cover['path'],timeout=30,doh_url='https://1.1.1.1/dns-query')
            if response.status_code!=200: raise RuntimeError(f'HTTP {response.status_code}')
            with Image.open(io.BytesIO(response.content)) as image:
                if image.mode in ('RGBA','P'): image=image.convert('RGB')
                image.save(tmp,'WEBP',quality=85)
            tmp.replace(cover_target); print('cover: repaired')
        except Exception as e:
            tmp.unlink(missing_ok=True); print(f'cover: failed: {e}',file=sys.stderr)
    return 0
if __name__=='__main__':
 try: raise SystemExit(main())
 except (OSError,RuntimeError) as e: print(f'error: {e}',file=sys.stderr); raise SystemExit(1)
