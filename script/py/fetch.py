from storage import data_root, data_path, image_path, gallery_path, collection_changed
import sys
import os
import json
import time
import io
import shutil
import sqlite3
from pathlib import Path
from archive_runner import acquire, defer_upstream
from crawler_validation import metadata as validate_metadata, request_url
from urllib.parse import urljoin

MAX_IMAGE = 32 * 1024 * 1024
MAX_TOTAL = 512 * 1024 * 1024

class DownloadBudget:
    def __init__(self): self.used = 0
    def get(self, session, url, *, metadata=False, **kwargs):
        cap = 2 * 1024 * 1024 if metadata else MAX_IMAGE
        host = request_url(url)
        for hop in range(4):
            # Check each hop before sending; credentials never follow another origin.
            request_url(url, host)
            response = session.get(url, stream=True, allow_redirects=False, **kwargs)
            try:
                if response.status_code == 429:
                    retry = defer_upstream(response.headers.get('Retry-After'))
                    raise ValueError(f'upstream is limiting archive requests (http 429). retry in {retry} seconds.')
                if response.status_code in (301,302,303,307,308):
                    location = response.headers.get('Location')
                    if hop == 3 or not location or len(location) > 8192 or any(c.isspace() or ord(c)<32 for c in location):
                        raise ValueError('Invalid or excessive upstream redirect')
                    url = urljoin(url, location)
                    request_url(url, host)
                    continue
                body = bytearray()
                for chunk in response.iter_content(chunk_size=65536):
                    self.used += len(chunk)
                    if len(body) + len(chunk) > cap or self.used > MAX_TOTAL:
                        raise ValueError("Archive download byte limit exceeded")
                    body.extend(chunk)
                return response.status_code, bytes(body)
            finally: response.close()

# curl_cffi provides browser-impersonation capabilities via libcurl; required
# to bypass TLS fingerprint checks that block standard Python HTTP clients.
from curl_cffi import requests
from PIL import Image

def get_api_key():
    # Resolve paths relative to this script's location so the project can be
    # run from any working directory without hardcoded paths.
    script_dir = os.path.dirname(os.path.abspath(__file__))
    root_dir = str(data_root())
    # API token is stored as a plain-text file at config/token; absent file
    # means anonymous access (empty Bearer token is sent).
    token_path = str(data_path('config/token'))
    if os.path.exists(token_path):
        with open(token_path, "r") as f:
            return f.read().strip()
    return ""

def get_doh_url():
    # Read a custom DNS-over-HTTPS resolver URL from config/doh.
    # DoH is used because standard UDP DNS may be blocked or poisoned in some
    # network environments (e.g. ISPs that censor the target domain).
    script_dir = os.path.dirname(os.path.abspath(__file__))
    root_dir = str(data_root())
    doh_path = str(data_path('config/doh'))
    if os.path.exists(doh_path):
        with open(doh_path, "r") as f:
            val = f.read().strip()
            if val:
                return val
    # Fall back to Cloudflare's public DoH resolver if no config file exists.
    return "https://1.1.1.1/dns-query"

# Module-level constants built at import time so every request reuses them.
API_KEY = get_api_key()
DOH_URL = get_doh_url()
HEADERS = {
    # Bearer token is empty string when no API key is configured; the server
    # may still accept the request but with lower rate limits.
    "Authorization": f"Bearer {API_KEY}",
    "Accept": "application/json, text/plain, */*",
    # Mimic a real browser User-Agent to reduce the chance of bot detection.
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36"
}

def out(obj):
    # Archive progress uses newline-delimited JSON for the PHP/CLI caller.
    # Flush each event so progress is visible before the worker exits.
    if "error" in obj and isinstance(obj["error"], str): obj["error"] = obj["error"].lower()
    print(json.dumps(obj))
    sys.stdout.flush()

def fetch_data(code):
    # Anchor all file paths to the repository root, not the current directory.
    script_dir = os.path.dirname(os.path.abspath(__file__))
    root_dir = str(data_root())
    # Each gallery gets its own subdirectory under cache/images/<id>/.
    cache_dir = str(data_path("cache") / ".archive-work")
    budget = DownloadBudget()
    os.makedirs(cache_dir, exist_ok=True)
    # Ensure the web server process (which may run as a different user) can
    # read the cached files by granting permissions for the runtime owner.
    os.chmod(cache_dir, 0o750)

    api_url = f"https://nhentai.net/api/v2/galleries/{code}"

    s = None
    try:
        # Use a persistent curl_cffi Session so TLS and HTTP/2 state is reused
        # across the multiple requests made for cover + every page image.
        s = requests.Session()
        # "chrome110" makes curl_cffi replicate Chrome 110's TLS fingerprint,
        # JA3 hash, and HTTP/2 settings — the main bypass mechanism.
        s.impersonate = "chrome110"

        status, body = budget.get(s, api_url, metadata=True, headers=HEADERS, timeout=30, doh_url=DOH_URL)
        if status != 200:
            out({"error": f"HTTP {status}"})
            return False

        data = json.loads(body)
        pages = validate_metadata(data, code)
        title_data = data.get("title", {})
        # Prefer the English title; fall back to the "pretty" (romanised)
        # title if English is absent.
        title_en = title_data.get("english") or title_data.get("pretty") or ""
        title_jp = title_data.get("japanese") or ""
        upload_date = data.get("upload_date", 0)

        # The API returns all metadata as a flat list of tag objects, each
        # carrying a "type" field. We fan them out into typed buckets here.
        tags_raw = data.get("tags", [])
        tags_dict = {
            "tags": [], "artists": [], "circles": [], "parodies": [], "characters": [], "languages": []
        }
        for t in tags_raw:
            t_type = t.get("type")
            t_name = t.get("name")
            if not t_name: continue
            if t_type == "tag": tags_dict["tags"].append(t_name)
            elif t_type == "artist": tags_dict["artists"].append(t_name)
            # "group" in the API corresponds to the "circle" concept (doujin circle/publisher).
            elif t_type == "group": tags_dict["circles"].append(t_name)
            elif t_type == "parody": tags_dict["parodies"].append(t_name)
            elif t_type == "character": tags_dict["characters"].append(t_name)
            elif t_type == "language": tags_dict["languages"].append(t_name)

        # Strip the synthetic "translated" entry; pick the first real language
        # tag (e.g. "english", "japanese") as the display language.
        langs = [l for l in tags_dict["languages"] if l != "translated"]
        main_lang = langs[0] if langs else ("translated" if "translated" in tags_dict["languages"] else "")


        cover_path_api = data.get("cover", {}).get("path")
        local_cover_path = str(Path(cache_dir) / "cover.webp")
        # Use a relative path for the cover so URLs work regardless of where
        # the server is mounted.
        local_cover_rel = f"cache/images/{code}/cover.webp"

        if cover_path_api and not os.path.exists(local_cover_path):
            img_url = f"https://t.nhentai.net/{cover_path_api}"
            c_status, c_body = budget.get(s, img_url, timeout=30, doh_url=DOH_URL)
            if c_status == 200:
                with Image.open(io.BytesIO(c_body)) as img:
                    # RGBA and palette-mode images cannot be saved as WebP in
                    # lossy mode without first converting to RGB.
                    if img.mode in ("RGBA", "P"):
                        img = img.convert("RGB")
                    # Normalise all covers to WebP at quality 85 for a consistent
                    # format and smaller file size than JPEG/PNG.
                    img.save(local_cover_path, "WEBP", quality=85)
            else:
                # Primary cover URL failed; fall back to the legacy CDN path
                # constructed from media_id and the per-image type character.
                media_id = data.get("media_id")
                if media_id:
                    # The API encodes image format as a single character:
                    # 'j' = jpg, 'p' = png, 'w' = webp, 'g' = gif.
                    ext = data.get("images", {}).get("cover", {}).get("t", "j")
                    ext_map = {'j': 'jpg', 'p': 'png', 'w': 'webp', 'g': 'gif'}
                    img_url = f"https://t.nhentai.net/galleries/{media_id}/cover.{ext_map.get(ext, 'jpg')}"
                    c_status, c_body = budget.get(s, img_url, timeout=30, doh_url=DOH_URL)
                    if c_status == 200:
                        with Image.open(io.BytesIO(c_body)) as img:
                            if img.mode in ("RGBA", "P"):
                                img = img.convert("RGB")
                            img.save(local_cover_path, "WEBP", quality=85)

        import base64
        cover_b64 = ""
        if os.path.exists(local_cover_path) and os.path.getsize(local_cover_path) <= 262144:
            # Encode the locally cached cover as base64 so the Node.js parent
            # can embed it directly in the "meta" event payload without a
            # separate HTTP round-trip to the server.
            with open(local_cover_path, "rb") as f:
                cover_b64 = base64.b64encode(f.read()).decode("utf-8")

        total_pages = len(pages)
        if not 1 <= total_pages <= 1000: raise ValueError("Archive requires 1 to 1000 pages")
        # Emit the "meta" event first so the UI can display the title and cover
        # immediately while the (potentially long) page downloads proceed.
        out({
            "type": "meta",
            "title": title_en if title_en else title_jp,
            "cover": local_cover_rel if os.path.exists(local_cover_path) else "",
            "cover_b64": cover_b64,
            "total": total_pages
        })

        local_pages = []
        converted_bytes = sum(p.stat().st_size for p in Path(cache_dir).iterdir() if p.is_file())
        for i, page in enumerate(pages):
            page_path_api = page.get("path")
            if not page_path_api: raise ValueError("Page path missing")

            # All downloaded pages are converted to WebP and stored by 1-based
            # page number, regardless of their original format.
            local_page_path = str(Path(cache_dir) / f"{i+1}.webp")
            local_page_rel = f"cache/images/{code}/{i+1}.webp"

            # Workspace files belong only to this admitted worker.
            if not os.path.exists(local_page_path):
                p_url = f"https://i.nhentai.net/{page_path_api}"
                p_status, p_body = budget.get(s, p_url, timeout=30, doh_url=DOH_URL)
                if p_status == 200:
                    try:
                        with Image.open(io.BytesIO(p_body)) as img:
                            if img.mode in ("RGBA", "P"):
                                img = img.convert("RGB")
                            img.save(local_page_path, "WEBP", quality=85)
                    except Exception as e:
                        # Fail before publication; no incomplete gallery is committed.
                        raise ValueError("Page conversion failed") from e
                else:
                    raise ValueError(f"Page download failed: HTTP {p_status}")
                # Polite delay between image requests to avoid rate-limiting.
                time.sleep(0.5)
            converted_bytes += os.path.getsize(local_page_path)
            if converted_bytes > MAX_TOTAL:
                raise ValueError("Converted archive exceeds 512 MiB")
            local_pages.append(local_page_rel)
            # Stream a "progress" event after every page so the caller can
            # update a progress bar in real time.
            out({"type": "progress", "current": i+1, "total": total_pages})

        final_data = {
            "type": "done",
            "data": {
                "id": code,
                "title": title_en,
                "title_jp": title_jp,
                "language": main_lang,
                "scanlator": data.get("scanlator", ""),
                "upload_date": upload_date,
                "tags": tags_dict,
                "cover": local_cover_rel if os.path.exists(local_cover_path) else None,
                "pages": local_pages,
                "page_count": len(local_pages),
                # Store the full raw API response so the DB can be reconstructed
                # from the cache without re-fetching if the DB is lost.
                "raw_api_data": data
            }
        }

        # Publish complete files without replacing any existing gallery file.
        # A crash can leave complete unreferenced files; retry safely reuses them.
        target = gallery_path(code)
        target.mkdir(exist_ok=True)
        for staged in Path(cache_dir).iterdir():
            dest = target / staged.name
            if dest.is_symlink(): raise ValueError("Linked gallery image")
            if dest.exists():
                # Existing data is never deleted by failure cleanup.
                with Image.open(dest) as image: image.verify()
            else: os.link(staged, dest)
        # Commit before emitting done. Web and CLI now share this persistence path.
        import sqlite3
        db_path = str(data_path('cache.sqlite'))
        conn = sqlite3.connect(db_path)
        c = conn.cursor()

        d = final_data["data"]
        db_code = str(d["id"])
        created_at = int(time.time())
        # Guard against clock skew: if the server's upload_date is in the
        # future relative to now, set created_at one day after upload_date.
        if created_at < upload_date:
            created_at = upload_date + 86400

        # Determine whether to UPDATE an existing row or INSERT a new one.
        c.execute("SELECT id FROM galleries WHERE id = ?", (db_code,))
        if c.fetchone():
            c.execute("""
                UPDATE galleries SET
                title = ?, title_jp = ?, language = ?, scanlator = ?,
                tags = ?, cover_path = ?, pages_paths = ?, page_count = ?,
                upload_date = ?, raw_api_data = ?
                WHERE id = ?
            """, (d["title"], d["title_jp"], d["language"], d["scanlator"], json.dumps(d["tags"]),
                  d["cover"], json.dumps(d["pages"]), d["page_count"], d["upload_date"], json.dumps(d["raw_api_data"]), db_code))
        else:
            c.execute("""
                INSERT INTO galleries (id, title, title_jp, language, scanlator, tags, cover_path, pages_paths, page_count, upload_date, created_at, raw_api_data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (db_code, d["title"], d["title_jp"], d["language"], d["scanlator"], json.dumps(d["tags"]),
                  d["cover"], json.dumps(d["pages"]), d["page_count"], d["upload_date"], created_at, json.dumps(d["raw_api_data"])))

        conn.commit()
        conn.close()
        collection_changed()
        out({"type": "info", "message": f"DB and cache for {db_code} have been stored."})

        # "done" is the terminal event; the caller knows the stream is complete
        # when it sees this type.
        out({"type": "done", "data": {"id": code}} if os.environ.get("LOCALNH_ARCHIVE_WEB") == "1" else final_data)
        return True
    except (ValueError, requests.errors.RequestsError) as e:
        # Transport errors may include signed URLs or proxy details; never echo them.
        message = str(e) if isinstance(e, ValueError) and not isinstance(e, (json.JSONDecodeError, requests.errors.RequestsError)) else "upstream request or metadata decoding failed."
        out({"error": message})
        return False
    except Exception:
        out({"error": "archive failed while processing upstream data."})
        return False
    finally:
        if s is not None: s.close()

if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if len(args) != 1:
        out({"error": "Supply one numeric gallery ID"}); sys.exit(1)
    code = args[0]
    if "--archive-worker" in sys.argv:
        # Only the supervisor's inherited lock admits a worker.
        acquire(int(os.environ["LOCALNH_ARCHIVE_FD"]))
        sys.exit(0 if fetch_data(code) else 1)
    from archive_runner import run
    sys.exit(run(code, "--fix" in sys.argv, 3 if "--web" in sys.argv else None))
