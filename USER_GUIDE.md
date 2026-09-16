# localnh User Guide

This guide covers how to browse, read, search, archive, export, and manage your personal library in localnh.

---

## Table of Contents

1. [Browsing and Navigation](#browsing-and-navigation)
2. [Searching and Metadata Filtering](#searching-and-metadata-filtering)
3. [Archiving New Galleries](#archiving-new-galleries)
4. [Manga Reader Controls](#manga-reader-controls)
5. [Favorites and Reading Tracker](#favorites-and-reading-tracker)
6. [Data Backup and Restore](#data-backup-and-restore)
7. [Downloading and Exporting](#downloading-and-exporting)
8. [Display Settings](#display-settings)

---

## Browsing and Navigation

- **Home Page (`/`)**: Displays all locally archived galleries in a responsive thumbnail grid.
- **Sorting**: Use the sort dropdown above the grid to arrange entries:
  - `newest - archived`: Sorted by the date the gallery was saved to localnh (default).
  - `oldest - archived`: Oldest local additions first.
  - `newest - uploaded`: Sorted by original release date on nhentai.
  - `oldest - uploaded`: Oldest original releases first.
*Note: Selecting either `newest - archived` or `newest - uploaded` on the homepage automatically saves your preferred sorting order to browser cookies, which is restored when returning to the home catalogue.*
- **Directory (`/directory`)**: Browse and discover entries categorized by tags, artists, characters, parodies, and circles.
- **Random (`/random`)**: Click "random" in the footer menu to immediately jump to a random locally archived gallery.
- **Library Stats (`/stats`)**: View total archived gallery count, disk storage space used and latest activity date by the local cache.
- **RSS Feed (`/rss`)**: Subscribe to the XML feed using your favorite RSS reader to receive notifications when new galleries are archived.

---

## Searching and Metadata Filtering

### Free-Text Search
Type keywords into the top search bar. As you type (minimum 2 characters), autocomplete suggestions will appear for matching tags, artists, circles, parodies, characters, and languages. Click any suggestion to insert it into your search query.

### Exact Metadata Filters
Clicking metadata badges in a gallery overview or directory item opens a dedicated exact-match filter page:

- `/?a=artist_name` - Filter by exact artist.
- `/?t=tag_name` - Filter by exact tag.
- `/?char=character_name` - Filter by exact character.
- `/?p=parody_name` - Filter by exact parody or series.
- `/?c=circle_name` - Filter by exact circle or publisher group.
- `/?l=language_name` - Filter by language.

Click **reset search** to return to the full catalogue, or click **nhentai** next to it to immediately run the same search on the official nhentai website in a new tab.

---

## Archiving New Galleries

You can archive galleries to your local storage via the web interface or terminal if you have the direct access to the server.

### Web Interface
1. Enter a gallery ID (e.g., `123456`) or a full nhentai gallery URL (e.g., `https://nhentai.net/g/123456/`) into the search bar.
2. Press `Enter` or click `go`.
3. If the gallery is not yet saved locally:
   - If public archiving is enabled (`config/public: on`) or you are logged in as admin, click **archive** on the confirmation page. localnh will then begin downloading metadata and converting all pages to WebP in real time with a live progress bar.
   - Once completed, you will be redirected to the gallery overview page.

### Command Line Interface (CLI)
From the repository root:
```bash
./localnh <gallery_id>
```

---

## Manga Reader Controls

Clicking any thumbnail or the "read" button opens the distraction-free reader interface.

### Keyboard Shortcuts
- `Arrow Right` / `Arrow Left`: Turn to next/previous page (automatically adapts to reading direction mode).

### Mouse and Touch Controls
- **Tap Left Side**: Turn page (Previous in LTR mode / Next in RTL mode).
- **Tap Right Side**: Turn page (Next in LTR mode / Previous in RTL mode).
- **Auto-Centering**: The page view automatically scrolls to center the active image on your screen upon every page turn.

### Direct Page Navigation
You can jump directly to any page number by appending the page to the URL: `/g/<gallery_id>/<page_number>` (e.g., `/g/123456/5`).

---

## Favorites and Reading Tracker

localnh includes personal bookmarking and reading statistics that run entirely inside your browser without sending personal data to the server.

- **Favorite Button**: Click "favorite" on any gallery page to bookmark it. View your collection on the `/favorites` page. If you unfavorite a gallery that has recorded reading counts, a confirmation prompt will appear to prevent accidental loss of your reading count.
- **Cum Counter**: Click "cum" to record a reading completion session. The button dynamically tracks your total count (e.g., `cummed 3x`).
- **Orphaned Bookmarks (`/favorites/orphan`)**: If a gallery is deleted from the server but still exists in your browser bookmarks, localnh flags it as an "orphan" and lets you view the cached metadata or re-fetch it.

---

## Data Backup and Restore

Because favorites and reading history are stored on your local browser device (`localStorage`), you can export and import your session data across different browsers or devices at `/user`.

### Creating a Backup
1. Go to `/user` from the footer menu.
2. Click **backup**.
3. A JSON backup file containing your profile name, favorites list, and reading history will be downloaded to your device.

### Restoring a Backup
1. Go to `/user`.
2. Click **restore**, select your previously exported `.json` backup file from your device.
3. Your profile name, display preferences, and bookmarks will be merged and restored immediately.

---

## Downloading and Exporting

You can export galleries for offline reading in third-party comic apps:

1. On any gallery page, click the **download** button (or navigate to `/download/<gallery_id>`).
2. Choose your preferred format:
   - **ZIP**: Standard compressed archive containing all WebP images.
   - **CBZ**: Comic Book ZIP archive compatible with comic and manga reader applications (e.g., Tachiyomi, Kuro Reader, CDisplayEx).
   - **PDF**: Single consolidated PDF document rendered with Pillow.
   - **HTML**: Standalone, single-file offline web viewer. All metadata, thumbnails, and full-resolution WebP images are embedded as Base64 data URIs. It can be opened directly from any mobile or desktop file manager without requiring a server or internet connection.

---

## Display Settings

Customize your viewing experience at `/settings`:

- **dark mode**: Toggle between dark background (`#111`) and light background (`#fff`).
- **dynamic color on gallery page**: Dynamically tints the gallery page background using the dominant color of the cover thumbnail.
- **reader page order**:
  - `ltr`: Standard Western left-to-right reading order.
  - `rtl`: Traditional Japanese manga right-to-left reading order (tapping left goes forward).
- **blur cover images**: Blurs all cover thumbnails on the home grid and gallery pages for privacy when browsing in public places.


## Login through local and public addresses

A local installation can use HTTP on localhost without a domain or tunnel. Public installations should provide HTTPS. You can sign in through localhost and a different public hostname independently; ports alone do not separate cookies. Existing sessions need a new login after the server-side session upgrade. Logout revokes the current browser session; other devices remain signed in.

For deployment configuration and troubleshooting (including an HTTP request sent to an HTTPS port), see [DEPLOYMENT.md](DEPLOYMENT.md).


## Form expiration and preferences

Login, settings, logout, and archive confirmation forms require cookies and a valid form token. If a form reports an invalid CSRF token, reload the page and try again; anonymous forms expire after two hours, and signing in changes the form context. Opening an old delete/toggle/logout URL no longer performs the action. Use the buttons in the current interface. Selecting the home sort dropdown saves the preference in your browser; visiting a shared sort URL only changes that view.


For external storage and the requirement to stop writers before snapshot export/import, see [STORAGE.md](STORAGE.md). Setting LOCALNH_DATA_DIR does not migrate an existing collection.

## Repair an incomplete archive

If an archive was interrupted or a page file was removed, run `./localnh repair <gallery_id>`. The command checks the saved metadata and repairs only missing pages. Existing files are preserved; use `--overwrite` only when you intentionally want to download them again. The CLI user needs read/write access to `cache/images`, including gallery subdirectories created by Docker.
