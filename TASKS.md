# Project state

Evidence reviewed 2026-09-30 at `fdb9e8b`. Items below describe the source checkout, not the health or deployed version of a particular instance. No TODO/FIXME/XXX/HACK markers were found in the project-authored source. Archived plans and past conversations were reconciled with later code; they are not automatically open work.

### In progress

- No active unfinished implementation is established by the current working tree or latest commit. Documentation reconstruction is complete in this change. Historical test/deployment checkpoints are not evidence that work is still running.

### Planned

- **Dependency follow-up, not a scheduled upgrade:** DOCS's dependency-maintenance section records unresolved distribution/libxml2 advisories and Caddy/CEL compatibility constraints. Reassess against a freshly built image before upgrading. Historical reports are not a current vulnerability scan; replacing libxml2 across ABIs was rejected as an unsafe shortcut. No other committed feature roadmap is established.

### Known issues

- **Repair bypasses collection coordination and crawler safeguards.** `script/py/repair.py` has no writer/reader locks, shared URL/metadata validation, byte/resource supervisor or shared 429 cooldown. It hardcodes Cloudflare DoH, uses saved `raw_api_data.pages` only (no legacy `images.pages` fallback), repairs numbered pages but not covers, and returns 0 even after individual page failures. Stop competing activity and run `check` afterward. Do not describe it as equivalent to the hardened fetch path.
- **Browser backup restore has gaps.** In `user.php`, current favorite arrays replace the stored list rather than merge. The legacy object branch builds `cleanFavs` but never saves it. Only dark mode and dynamic color preferences are backed up/restored; blur, reading direction and home sort are absent. These are code-confirmed limitations, not completed fixes.
- **HTML export omits current circle metadata.** `script/py/html.py` renders the `groups` category, while the crawler stores `circles`. Its reader is an independent LTR implementation, not the complete online reader with preferences. Embedded CSS still imports Google Fonts; images/readability work offline with fallback fonts.
- **Exports/checks do not guarantee image completeness.** PDF and ZIP workers skip missing pages; HTML emits an empty data URI with a warning. `check.py` compares directory names/expected numbered files (plus cover), not image decoding, hashes or a consistent locked snapshot. It cannot certify image integrity.
- **URL metadata differs from auth scheme handling.** `gallery.php`, `reader.php`, `rss.php` construct absolute links from Host/direct `HTTPS`, ignoring `config/domain` and the trusted-proxy scheme helper. An HTTP-origin HTTPS proxy can therefore yield HTTP metadata URLs despite Secure auth cookies. Search's numeric redirect also remains limited to 1–6 digits in `index.php`.
- **Packaging/docs validation gaps:** no tracked tests or CI, and no general native-platform compatibility matrix. `config.example/favicon` points at `/static/favicon.png`, but only `.gitkeep` is shipped in `static/`; provide an image or clear the setting. Do not assume the default favicon exists.
- **Crash/operational limits remain:** filesystem changes and SQLite commits are not atomic together; import recovery needs downtime and retained backups. Static readers and repair do not share the full lock protocol. Slow export delivery holds the sole export slot. See ARCHITECTURE/STORAGE/DEPLOYMENT for the implemented boundaries; these are not claims of newly reproduced incidents.

### Completed

| Evidence | Implemented outcome |
| --- | --- |
| `73e14d5`, `f4f7d59` | Single HTTP entry point with public-asset allowlist; private/runtime data excluded from new Docker builds |
| `4af187b`, `2ce2be4` | Revocable server sessions, mixed HTTP/HTTPS cookie handling, POST/CSRF mutations |
| `9099d1e`, `38d7365` | External storage contract, verified offline copy/recovery, shared presentation helpers |
| `fa8dae4`, `3a098f6`, `1a6ad69` | Login/resource limits, bounded lookup APIs, input/output and upstream validation |
| `1c0bf37`, `4f3f995` | Snapshot/writer/reader coordination, supervised child lifecycle and CLI signal forwarding |
| `0fa2ce9` | Python/hash/base-image locks, Caddy source build, separate runtime stages and libvips loader mitigation |
| `3e7a76b` | Browser thumbnail concurrency 1 and worker address-space limit 512 MiB |
| `661de85` | CLI `check` and `repair` added, with limitations listed above |
| `fdb9e8b` | Download page stays open during export; five-second timer only clears status |
| This documentation change | Agent entry point, architecture/workflow/state/decision records; stale existing documentation corrected without application changes |

Historical Android/cartridge/static-reader experiments belong to separate projects. They are not planned localnh features merely because their conversations began in a localnh workspace.
