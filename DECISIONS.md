# Durable decisions

Reconstructed 2026-09-30 from current source/configuration, Git through `5ff92cc` (application changes through `fdb9e8b`), existing guides, ignored `plans/done/` security proposals, and accessible project-specific Codex/Antigravity history. Code is authoritative. “Confirmed” means rationale explicitly supported by comments, documentation or historical discussion, not a fresh runtime test. Constraints describe the current implementation; they are not evidence that every alternative was historically considered. Unknown alternatives remain unknown.

## 1. PHP pages, Python processing, SQLite and plain browser scripts

- **Decision:** Retain the small, framework-free split: PHP routing/UI, Python downloading/conversion/maintenance, SQLite metadata and browser-local personal state.
- **Rationale — confirmed:** The early Antigravity `nhentai_library_plan.md` explicitly separated PHP and a Python `curl_cffi` fetcher, with SQLite to avoid repeated metadata fetches. README describes simplicity/privacy; the implemented code follows this split.
- **Constraints:** PHP and Python share schema, storage paths and lock protocols. Python scripts can have import-time side effects. No Node application or frontend build is present.
- **Alternatives:** Early proposed filenames/routes and automatic archive-on-GET were superseded. No framework comparison was recovered. The old plan's proposed reader preloading is not implemented in the online reader.

## 2. Reusable source stays separate from personal deployments

- **Decision:** Ship templates and stock HTTP port 8080, with private data and site-specific TLS/tunnel settings supplied at deployment.
- **Rationale — confirmed:** Antigravity history requested a publishable source copy with example configuration and MIT licensing, excluding setup-specific fetch helpers. Later Codex history explicitly retained stock ports when synchronizing reviewed changes back to the repo.
- **Constraints:** Docker copies source into the image; mounted Caddy configuration can override it. Updating host source alone does not update stock containers. A historical pubnix experiment required direct-PHP/subpath patches because server rewrites were unavailable; those patches are not supported by this checkout's routing policy.
- **Historical boundary:** Personal deployment paths, direct-PHP demo adaptations, and unrelated cartridge/reader projects describe other targets, not this checkout's architecture.

## 3. One public entry point with an asset allowlist

- **Decision:** Route every request through `index.php`, then allow only validated public asset directories/types.
- **Rationale — confirmed:** Security review and `73e14d5` addressed private database/config/source exposure. One policy covers Caddy and the development router, including decoded paths and resolved symlink targets.
- **Constraints:** PHP handles static images too, adding overhead and dependence on bootstrap/storage. Private files must never be placed in allowed asset locations.
- **Rejected:** The initial security plan's extension/path denylists were incomplete and inconsistent across servers. A generic `file_server` or execute-any-PHP fallback would reopen that boundary. Direct static serving was deferred, not implemented by external storage support.

## 4. Revocable sessions, per-request TLS handling and intentional mutations

- **Decision:** Use random server-validated sessions, hashed tokens, 30-day absolute expiry, observed credential-change revocation, and a separate private session database. Mutations use POST and session-bound or signed-anonymous CSRF tokens.
- **Rationale — confirmed:** `4af187b`/`2ce2be4` and Codex security history required real logout/revocation while preserving local HTTP and simultaneous public HTTPS. Anonymous archive/settings/login forms also need an intentional browser context.
- **Constraints:** `Secure` follows direct HTTPS or an exact trusted immediate proxy IP; no Host-based trust, broad network trust or forwarded client identity. Shared NAT/proxy users can share login-limit buckets. Snapshots exclude sessions; imports revoke them.
- **Rejected:** Deterministic MD5 cookies, GET mutations, the experimental global `LOCALNH_COOKIE_SECURE` switch, mandatory HTTPS for localhost, and trusting a shared Docker gateway merely to make cookies Secure. A host tunnel is optional, not an application dependency.

## 5. External storage is explicit and backward compatible

- **Decision:** Support an initialized external root while preserving legacy paths and logical `cache/images/...` references. Keep PHP/Python resolvers aligned. Storage changes never automatically migrate data.
- **Rationale — confirmed:** `9099d1e`, STORAGE and staged Codex work separate path support, offline verified copying and deployment activation so a bad mount cannot silently create an empty replacement collection.
- **Constraints:** Absolute disjoint roots, marker/schema/directories, compatible ownership, no data-child symlinks. The current PHP/Python parsing mismatch for the literal environment value `0` is an implementation defect documented in TASKS, not an intentional exception to this decision. Migration copies config/sessions as well as collection data; portable snapshots do not. Rollback after new writes requires a verified copy of the latest data.
- **Rejected:** Auto-initializing unknown existing roots, source symlinks into private data, and restoring stale pre-migration data after new writes.

## 6. Offline snapshots and recoverable activation

- **Decision:** Use SQLite backup plus validated chunked tar images; stage imports, retain previous DB/images and a recovery journal, revoke sessions before activation.
- **Rationale — confirmed:** `snapshot.py` comments and STORAGE explain that SQLite backup into the existing DB supports legacy file bind mounts; copying image backups avoids overlay-filesystem `EXDEV` failures from directory renames. Recovery copies retain backups for another attempt if restoration fails.
- **Constraints:** All writers must be stopped. `--offline` acknowledges this, it does not enforce the whole maintenance window. Mount the cache parent, not `cache/images` alone. Process-interruption recovery is not a power-loss durability guarantee.
- **Rejected:** Raw live SQLite copying, blindly extracting tar entries, replacing a bound DB inode, and automatically removing recovery backups. No live atomic collection swap is promised.

## 7. Separate writer and reader locks; bound public work

- **Decision:** Keep `archive.lock` for writers/snapshots and `collection-read.lock` for long image readers versus destructive operations. Use nonblocking admission, per-job resource limits, fixed process arguments and inherited lock descriptors.
- **Rationale — confirmed:** `1c0bf37`/`4f3f995` and code comments allow new archives without blocking existing readers while preventing deletion/import during exports. Supervisors keep locks across PHP death and kill/reap child groups; Linux parent-death signaling prevents an unsupervised direct child after supervisor death.
- **Constraints:** Advisory local protocols, not distributed locks or filesystem/DB transactions. Ordinary static reads and later-added repair do not participate fully. Native PDF/HTML exports intentionally lack web job ceilings. Slow response delivery can occupy an export slot.
- **Superseded behavior:** Earlier unbounded web work and shell-string invocation were replaced. **Current constraint:** Releasing locks before children finish defeats the implemented protection; this is an implication of the protocol, not a separately recovered historical decision. Workers are trusted fixed programs, not an arbitrary-code sandbox.

## 8. Thumbnail backpressure and memory headroom are deliberate

- **Decision:** Gallery JS admits one thumbnail request at a time with bounded retries; thumbnail/aggregate workers have 512 MiB address-space limits.
- **Rationale — confirmed:** `3e7a76b` and September Codex history report libvips thread creation failures at 256 MiB and repeated 503s during burst loading. JS comments also explain why native lazy loading was avoided for an initially collapsed preview grid.
- **Constraints:** Server conversion slots remain two; the collection reader lock is shared, so an old chat explanation that it exclusively serialized all readers is inaccurate. The source proves the behavior, while history supplies the observed symptoms. Do not lower memory or raise browser concurrency without regression evidence.
- **Rejected/superseded:** The 256 MiB limit and four simultaneous browser preview requests were replaced. This is a measured compatibility workaround, not proof that every image fits.

## 9. Pin dependencies without breaking runtime ABI

- **Decision:** Hash-lock Python wheels/tooling; pin PHP/Go base images; build standard Caddy from reviewed Go locks; copy compiled artifacts into a compatible runtime; block untrusted libvips loaders.
- **Rationale — confirmed:** `0fa2ce9`, DOCS and dependency-review history aimed to make dependency selection reviewable and remove unused runtime tooling. CEL remains 0.28.1 with Caddy 2.11.4 because the next CEL API was incompatible in that review.
- **Constraints:** Apt repositories remain mutable; rebuilt images need current auditing. Historical advisory/exposure assessments are dated evidence, not a current clean bill of health. Python wheel availability is platform-specific.
- **Rejected:** Force-swapping libxml2 shared libraries across SONAME/ABI changes, blindly updating CEL past Caddy compatibility, and treating loader mitigation or a clean scanner result as proof of no vulnerabilities.

## 10. Keep download page alive; keep personal data browser-local

- **Decision:** Download in a hidden iframe and leave the selector open; its five-second timer clears only status. Profile/favorites/counters remain browser-local, with a read-only metadata lookup API and JSON transfer.
- **Rationale — confirmed for download:** `fdb9e8b` and Codex history identify automatic navigation as a cause of interrupted large exports; browser attachment completion is not reliably observable. **Confirmed for storage:** README's privacy/account-free intent and `user.php`/favorites implementation. No cloud-sync alternative evaluation was recovered.
- **Constraints:** Browser data is scoped to the origin; JSON transfer is not an archive backup or authenticated session restore. Current restore/export defects are recorded in TASKS. Do not reintroduce a timed gallery redirect as completion detection.

## Historical evidence boundaries

The inspected Codex security conversation was titled “Review security fix plan” (session `01a086be-dda2-77d3-af01-8115467f6f04`); relevant history spans September 9–28. Antigravity's original “localnh” history (conversation `42f40f6d-ddcc-4bc0-9c6b-d1ee5be06b2d`) and its `nhentai_library_plan.md`, `plan.md` and `learning_proposal.md` supplied early design intent and publication context. These identifiers aid retrieval when that local history is available; none is required to work on the repository.

Obsolete names, insecure old behavior, speculative designs, private values and reported regression counts are not current contracts. The historical separate `localnh-test` deployment and its tests were absent during this review. `plans/done/` is ignored local material and may not exist in another clone; the durable conclusions are recorded above with tracked code/commit references.
