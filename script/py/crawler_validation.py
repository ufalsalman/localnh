"""Untrusted metadata/URL boundary. No network or storage side effects."""
import re
from urllib.parse import urlsplit

HOSTS = {'nhentai.net', 't.nhentai.net', 'i.nhentai.net'}

def text(value, field, limit=4096):
    if not isinstance(value, str) or len(value.encode('utf-8')) > limit or any(ord(c) < 32 and c not in '\t\n\r' for c in value):
        raise ValueError('Invalid upstream ' + field)
    return value

def image_path(value):
    text(value, 'image path', 4096)
    parts = urlsplit(value)
    if parts.scheme or parts.netloc or parts.fragment or value.startswith('/') or '\\' in value or any(c.isspace() for c in value):
        raise ValueError('Invalid upstream image path')
    segments = parts.path.split('/')
    if not segments or any(not re.fullmatch(r'[A-Za-z0-9_.~-]+', p) or p in ('.', '..') for p in segments):
        raise ValueError('Invalid upstream image path')
    return value

def request_url(url, host=None):
    if not isinstance(url, str) or len(url) > 8192 or '\\' in url or any(c.isspace() or ord(c) < 32 for c in url):
        raise ValueError('Blocked upstream URL')
    try:
        p = urlsplit(url)
        if p.scheme != 'https' or p.hostname not in HOSTS or (host and p.hostname != host) or p.username is not None or p.password is not None or p.port not in (None,443) or p.fragment:
            raise ValueError('Blocked upstream URL')
        image_path(p.path.lstrip('/') + ('?' + p.query if p.query else ''))
        if p.path.startswith('//'):raise ValueError('Blocked upstream URL')
    except ValueError:
        raise ValueError('Blocked upstream URL') from None
    return p.hostname

def metadata(data, code):
    if not isinstance(data, dict):raise ValueError('Invalid upstream metadata object')
    if 'id' in data and (type(data['id']) not in (int,str) or not str(data['id']).isascii() or not str(data['id']).isdigit() or int(data['id']) != int(code)):
        raise ValueError('Upstream gallery ID mismatch')
    title = data.get('title')
    if not isinstance(title, dict):raise ValueError('Invalid upstream title')
    for key in ('english','pretty','japanese'):
        if key in title:text(title[key], 'title')
    text(data.get('scanlator',''), 'scanlator')
    stamp = data.get('upload_date',0)
    if type(stamp) is not int or not 0 <= stamp <= 253402214399:raise ValueError('Invalid upstream upload date')
    tags = data.get('tags',[])
    if not isinstance(tags,list) or len(tags)>4096:raise ValueError('Invalid upstream tags')
    for tag in tags:
        if not isinstance(tag,dict):raise ValueError('Invalid upstream tag')
        text(tag.get('type'), 'tag type',64);text(tag.get('name'), 'tag name',4096)
    cover=data.get('cover',{})
    if not isinstance(cover,dict):raise ValueError('Invalid upstream cover')
    if cover.get('path') is not None:image_path(cover['path'])
    images=data.get('images',{})
    if not isinstance(images,dict):raise ValueError('Invalid upstream images')
    media=data.get('media_id')
    if media is not None and (type(media) not in (str,int) or not re.fullmatch(r'[0-9]{1,20}',str(media))):raise ValueError('Invalid upstream media ID')
    ext={'j':'jpg','p':'png','w':'webp','g':'gif'}
    legacy_cover=images.get('cover',{})
    if not isinstance(legacy_cover,dict) or legacy_cover.get('t','j') not in ext:raise ValueError('Invalid upstream cover type')
    pages=data.get('pages',[])
    if not isinstance(pages,list):raise ValueError('Invalid upstream pages')
    if not pages:
        legacy=images.get('pages',[])
        if not isinstance(legacy,list) or not 1 <= len(legacy) <= 1000 or media is None:raise ValueError('Archive requires 1 to 1000 pages')
        pages=[]
        for i,p in enumerate(legacy):
            if not isinstance(p,dict) or p.get('t') not in ext:raise ValueError('Invalid upstream page type')
            pages.append({'path':f'galleries/{media}/{i+1}.{ext[p["t"]]}'})
    if not 1 <= len(pages) <= 1000:raise ValueError('Archive requires 1 to 1000 pages')
    for page in pages:
        if not isinstance(page,dict):raise ValueError('Invalid upstream page')
        image_path(page.get('path'))
    # Preserve raw response separately; normalized page list is used only for downloading.
    return pages
