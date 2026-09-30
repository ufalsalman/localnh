# Deployment: local HTTP and public HTTPS

localnh does not require Cloudflare, a tunnel, a domain, or TLS for local use. Choose a topology below. Application PHP stays the same; ports, certificates, and proxy settings belong to deployment configuration.

## Choose an access mode

| Mode | Connection reaching localnh | Proxy trust |
| --- | --- | --- |
| Local archive | HTTP on localhost | Empty |
| Direct public HTTPS | TLS handled by localnh's web server | Empty |
| Host proxy/tunnel with HTTPS origin | TLS from proxy to localnh | Empty |
| Reverse proxy with HTTP origin | HTTP from a dedicated trusted proxy | Exact immediate proxy IPs |

The stock Compose file maps host **8080** to container HTTP **8080**. A native PHP development server example also uses **8080**, without Docker port mapping. The stock Docker mapping listens on all host interfaces. For local-only use, change it to `127.0.0.1:8080:8080`. LAN HTTP is unencrypted, including passwords. The PHP development server is for local development, not a public production server.

## How HTTPS affects login

Cookies are HttpOnly and SameSite=Lax. Secure is selected per request. Direct TLS sets the web server's HTTPS indicator; HTTP does not. The application does not infer security from the requested hostname or blindly accept forwarding headers. A browser-to-proxy HTTPS connection alone does not tell an HTTP origin its scheme.

Use separate local/public hostnames, for example `localhost` and `archive.example.com`. Cookies are scoped by hostname, not port. HTTP and HTTPS on the same hostname can conflict with an existing Secure cookie. Do not enable the removed global `LOCALNH_COOKIE_SECURE` setting. Sessions persist in the dedicated session volume; keep `LOCALNH_SESSION_DIR` consistent for PHP and CLI. Upgrading from legacy cookies requires login again. Session revocation does not provide CSRF protection.

## Host proxy or tunnel with an HTTPS origin

This recipe preserves local HTTP and keeps any tunnel process on the host. It adds a private HTTPS listener to Caddy without modifying application PHP. It is useful when Docker NAT makes a host proxy and other traffic share one peer IP. No tunnel-specific service is bundled or required.

Examples below use local HTTP port 2239 and HTTPS origin port 2240, intentionally different from the stock HTTP port 8080. You may retain 8080 for HTTP instead. Commands run from your deployment's application directory. Replace `archive.example.com` with your public hostname. Ports 2239 and 2240 are examples: check availability first. Keep deployment files outside the source and Docker build context. Do not copy an existing installation's CA or private keys.

### 1. Prepare a deployment Caddyfile

Create the external directory first:

```sh
mkdir -p ../localnh-deployment
```

Create `../localnh-deployment/Caddyfile` with the following complete configuration:

```caddyfile
(localnh_app) {
    root * /var/www/html
    route {
        # Capture before FastCGI rewrites the header; PHP validates proxy trust.
        vars localnh_forwarded_proto {http.request.header.X-Forwarded-Proto}
        rewrite * /index.php
        php_fastcgi 127.0.0.1:9000 {
            env LOCALNH_FORWARDED_PROTO {http.vars.localnh_forwarded_proto}
        }
    }
}

:8080 {
    import localnh_app
}

https://localhost:8443, https://archive.example.com:8443 {
    tls internal
    import localnh_app
}
```

Keep the front-controller route: do not add a general file server or expose all PHP scripts. The HTTPS site accepts localhost for origin TLS and the public Host header forwarded by the proxy. `tls internal` creates a private CA, not a publicly trusted browser certificate. Browsers access the public proxy's trusted certificate; the proxy validates the separate origin certificate using this private CA.

### 2. Adapt your existing Compose service

Keep your existing image/build, container name, restart policy and data mounts. **Replace** the existing ports block with the following; do not blindly append a Compose override, because merged port lists may retain an old public binding:

```yaml
    ports:
      - "127.0.0.1:2239:8080"
      - "127.0.0.1:2240:8443"
    environment:
      LOCALNH_TRUSTED_PROXIES: ""
```

If LAN access is intentional, retain the appropriate HTTP bind address instead. Add these entries to the existing volumes list, retaining config, cache database, cache and snapshot mounts. Add the sessions entry only if it is not already there:

```yaml
      - ./sessions:/var/lib/localnh/sessions
      - ../localnh-deployment/Caddyfile:/var/www/html/Caddyfile:ro
      - ../localnh-deployment/caddy-data:/root/.local/share/caddy
```

These data paths match the project's current image, which runs Caddy as root. For another image/user, determine its actual Caddy data directory first. Create the Caddyfile before starting, and create `caddy-data` with private permissions:

```sh
mkdir -p ../localnh-deployment/caddy-data
chmod 700 ../localnh-deployment/caddy-data
docker compose config --quiet
docker compose up -d --build
```

Use `--no-build` instead of `--build` only when your Compose image explicitly references the already-built release you intend to run. Do not delete Caddy data on upgrades: this preserves the CA and certificates across recreation. Do not change ownership of the application's collection data to solve CA access issues.

### 3. Give the host proxy the public CA certificate

After Caddy has started and generated certificates:

```sh
mkdir -p "$HOME/.config/localnh"
docker compose cp localnh:/root/.local/share/caddy/pki/authorities/local/root.crt "$HOME/.config/localnh/origin-ca.crt"
chmod 644 "$HOME/.config/localnh/origin-ca.crt"
curl --noproxy '*' --cacert "$HOME/.config/localnh/origin-ca.crt" \
  -H 'Host: archive.example.com' https://localhost:2240/admin
```

The command must validate TLS without `-k`. The proxy service user must be able to traverse the host directories and read the public certificate. Copy only `root.crt`, never the CA private key. Do not put CA private data into the image, repository, webroot, or collection exports. If you intentionally rotate the CA, update the proxy's trusted CA file and reload its configuration, then retest.

### 4. Configure your chosen host proxy

Use origin **`https://localhost:2240`**, enable certificate verification, and trust the public CA file from the previous step. Send TLS server name `localhost` and preserve the public HTTP Host header. Restrict public access to HTTPS, redirecting public HTTP at the proxy. A TLS connection to the origin causes Secure cookies even if an external client used HTTP, so public HTTP must not serve the login flow.

If the proxy uses IPv6 localhost exclusively while only IPv4 is published, use `https://127.0.0.1:2240` with TLS server name explicitly set to `localhost`. A proxy on another machine cannot use this loopback mapping: provision a reachable restricted origin and matching certificate instead. A containerized proxy also has its own localhost; use an appropriate Docker network topology.

### Optional: Cloudflare Tunnel managed through the dashboard

Keep your existing host service. Edit the published application's origin route:

| Dashboard field | Value |
| --- | --- |
| Service URL | `https://localhost:2240` |
| Origin Server Name | `localhost` |
| CA Pool | Absolute host path, e.g. `/home/YOUR_USER/.config/localnh/origin-ca.crt` |
| Disable TLS certificate verification / No TLS Verify | OFF |
| HTTP Host Header | Leave unchanged unless your deployment requires an override |

CA Pool is a path on the machine running cloudflared, not an upload field. Use an actual absolute path, not `$HOME` or `~` in the dashboard. Save the route and confirm the running connector receives it. Restarting the service cannot correct a saved `http://` origin URL. No additional cloudflared service is needed. For locally managed tunnels, configure the equivalent origin/TLS settings in that tunnel's own configuration instead of the dashboard.

Official field definitions: [Cloudflare origin parameters](https://developers.cloudflare.com/tunnel/advanced/origin-parameters/).

## Direct public HTTPS without a tunnel

Caddy can terminate browser HTTPS directly using your real domain and a publicly trusted certificate. Provision DNS, public listeners and certificate issuance for your environment. Adapt the same `localnh_app` route above into your HTTPS site, persist Caddy data, and retain the independent local HTTP listener if needed. Do not use the private `tls internal` certificate for general public browsers. Leave `LOCALNH_TRUSTED_PROXIES` empty when requests arrive over direct TLS. See [Caddy TLS configuration](https://caddyserver.com/docs/caddyfile/directives/tls).

## Reverse proxy with an HTTP origin

This alternative works with any correctly configured reverse proxy. Terminate public TLS there and make it **overwrite** `X-Forwarded-Proto` with the actual external request scheme; do not pass a client's arbitrary value through. Set `LOCALNH_TRUSTED_PROXIES` to the exact immediate peer IP seen by PHP, e.g.:

```yaml
    environment:
      LOCALNH_TRUSTED_PROXIES: "172.30.50.2"
```

That IP is illustrative, not a universal Docker address. Use a stable dedicated proxy address and prevent unrelated clients from using the trusted path. Multiple exact IPv4/IPv6 addresses may be comma-separated; CIDRs and hostnames are unsupported. Keep `REMOTE_ADDR` as the immediate peer, not a rewritten external client address. Recreate the app container after environment changes.

Do not trust a shared Docker gateway or loopback merely to make cookies Secure. If the proxy cannot be distinguished from direct clients, choose the HTTPS-origin recipe or isolate the proxy on a dedicated network. With HTTP origins and empty proxy trust, forwarded HTTPS is ignored and cookies are not Secure.

## Verify before accepting a deployment

1. Open the local HTTP address and the public HTTPS hostname in a browser. Log in
independently, reload and check authenticated navigation on both.
2. In browser cookie tools, verify HttpOnly and SameSite=Lax on both; Secure only
on the HTTPS hostname. Do not share cookie values or credentials in reports.
3. Log out and confirm authenticated navigation stops on that session; test that
the other hostname/session can remain signed in.
4. Restart/recreate the container and check that valid sessions persist and the
origin still validates against the same CA. Do not remove persistent volumes.
5. Verify the public HTTP URL redirects to HTTPS. Check origin connectivity and
actual public access separately: a successful local curl does not test a tunnel.

Historical auth, scheme and browser regressions ran in a separate test deployment; this repository does not ship `tests/` or a CI test runner. Use the validation guidance in [DEVELOPMENT.md](DEVELOPMENT.md) and verify your final public route. Keep any local tests and fixtures out of deployed instances and release images.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Client sent an HTTP request to an HTTPS server | Origin must be **https://** on 2240; changing only the port is insufficient. |
| Certificate authority error / origin 502 | Correct CA Pool absolute path, service-user readability, persistent CA, and TLS server name localhost. Keep verification enabled. |
| Origin connection refused | Listener/container health, host port mapping, IPv4 vs IPv6, and whether proxy runs on the host or another network. |
| HTTPS site login cookie is not Secure | HTTP origin without correct trusted peer; use verified HTTPS origin or fix dedicated proxy trust. |
| Local login conflicts with HTTPS | Use different hostnames, not merely different ports; inspect existing browser cookies. |
| Authentication storage unavailable (503) | Session mount exists, is writable by PHP and uses the same configured path as CLI. |
| Source Caddyfile differs from active behavior | Inspect deployment bind mounts: an external Caddyfile may override the source file at runtime. |

Inspect only relevant route/status logs; avoid printing tunnel tokens, credentials, or full process command lines when collecting diagnostics.

## Upgrade and rollback

Before changes, record the running image ID/tag, save the current source and Compose/deployment configuration outside the build context, and note which files are newly added. Retain the previous image under a local tag. Back up collection data consistently if the change affects it; a source backup is not a database backup. Prepare exact restore commands for that installation before restarting anything.

To roll back this topology, restore the saved source/config, explicitly select the saved image and recreate with `--no-build`; do not accidentally rebuild the new source. If the public route was changed, restore its former origin URL and TLS settings too. Preserve collection/config/cache/snapshot data and avoid `docker compose down -v`. Keep the unused CA/session directories until recovery is verified; restored legacy authentication can require login again.

An installation-specific rollback script is not shipped here because its paths, previous image and prior routing state must come from that installation's backup. Review application changes before copying: exclude tests, fixtures, local reports, private configuration, certificate keys and deployment-specific port assignments.


## POST and CSRF compatibility

Administrative deletion, public archive toggling, logout, browser preference toggles, login, and archiving now require POST and a CSRF token from the rendered form. Old mutation GET links return 405; missing gallery GET shows an archive confirmation form. Invalid tokens return 403. Use the current UI or update automation to retain its own cookies, retrieve a fresh form token, and submit it with the POST body. Reload forms after login/logout or anonymous token expiration (two hours). Do not disable the guard.

Anonymous cookies are HttpOnly, SameSite=Lax and host-only; HTTPS uses the __Host-localnh_form name with Secure, and local HTTP uses localnh_form. The signing key is generated lazily in the private session SQLite database (csrf_key table) and persists with the existing session volume. Login clears the pre-login cookie and uses the authenticated session context. No manual migration, new environment variable, certificate or tunnel change is required. Protect the entire session database, including this key, and exclude it from collection exports. If storage cannot be accessed, requests fail closed. A rollback may leave the extra table in place; the previous application ignores it.

Do not cache login/settings/archive-confirmation pages or authenticated pages at a proxy/CDN; preserve the application's private/no-store response headers. CSRF controls do not replace authorization, rate limits, input validation or secure transport.


For external storage and the requirement to stop writers before snapshot export/import, see [STORAGE.md](STORAGE.md). Setting LOCALNH_DATA_DIR does not migrate an existing collection.

## External data deployment and acceptance

Existing legacy mounts continue to work. For new external storage or a verified migration, follow [STORAGE.md](STORAGE.md) before changing Compose; merely setting an environment variable does not copy data. Keep source/build context separate from the data root. Set `LOCALNH_DATA_DIR: /var/lib/localnh` and replace the legacy config/database/cache/snapshot/session mounts with one host-data mount at `/var/lib/localnh`. The image's default session path is already `/var/lib/localnh/sessions`. If selecting another root, explicitly clear `LOCALNH_SESSION_DIR` to follow that root or persist its override separately. Retain your own ports, TLS certificate/Caddy mounts, and proxy settings.

Stop the application and all CLI/archive/import jobs for the verified offline copy. Retain the previous image and Compose plus the old root. Start with the new mapping, inspect `docker compose exec localnh ./localnh storage paths`, and confirm the expected collection count, login, representative galleries/images, CLI statistics, and HTTP denial of configuration/database/session paths. Test a reversible change and restart/recreate persistence on a disposable instance first. Migration is independent of Cloudflare: localhost HTTP and properly configured HTTPS reverse proxies continue to use the earlier deployment rules in this guide.

Before new collection/configuration writes, the previous root can restore the baseline. After writes, first copy the latest root into a new verified legacy recovery directory and point the previous Compose mounts there; using the stale original root would discard newer changes. Snapshot interruption recovery is a separate procedure described in STORAGE.md. Do not remove backups automatically. Fault-injection tests exercise deterministic process exits and simulated I/O failures; they do not certify physical disk failure, power-loss durability, live migration, or protection against a writer that ignores the required maintenance window.

## Login attempt limits

Login works on local HTTP and public HTTPS with the same limits. Each immediate peer can have five failed or in-flight attempts during a 60-second window. Successful verification refunds that peer's slot; all admitted attempts count toward an instance-wide budget of 60 per 60 seconds. Denied requests return HTTP 429 with Retry-After and do not extend the window. Existing sessions and public reading/export remain available. Invalid CSRF requests are rejected before admission.

The peer is REMOTE_ADDR, never X-Forwarded-For or CF-Connecting-IP. IPv4-mapped IPv6 addresses share the IPv4 bucket. Users behind one NAT/proxy/tunnel may share a bucket, including local and public access when Docker presents the same peer. LOCALNH_TRUSTED_PROXIES controls HTTPS scheme only; it does not enable forwarded client identity. No localhost exemption or Cloudflare dependency is introduced. A sustained attacker can consume available login slots; these limits reduce password-verification throughput, not guarantee login availability under attack. The fixed windows also allow a burst around a window boundary.

Counters live in login-limits.sqlite inside LOCALNH_SESSION_DIR, alongside but separate from sessions.sqlite. Keep this directory persistent and private. Limits survive restart/recreate, are not included in collection snapshots, and expired counters are cleaned on the next admission. At most 120 unexpired peer buckets plus the global bucket can remain across a global-window boundary. Storage failure returns 503 rather than bypassing the limit. No password, username, or raw IP is written to this database; peer hashes are pseudonymous, not anonymized identifiers.

If the owner needs immediate recovery, run the following on the host (replace the container name for a custom deployment). This clears all login counters but preserves credentials and sessions. It does not stop ongoing abusive traffic, so restrict that traffic first if necessary. Never delete the whole session directory to reset a login limit.

```bash
docker exec --user www-data localnh php /var/www/html/login_limits.php reset
```

For native PHP deployment, run `php login_limits.php reset` as the application user with the same storage environment. The reset is CLI-only and is not an HTTP endpoint. Rolling back this code leaves an unused private counter database; the previous application can continue using its original sessions without a migration.

## Public exports and thumbnail capacity

Public exports remain available without login. Web jobs have one export slot and two thumbnail-conversion slots per data root. Busy requests return 503 with Retry-After: 3 instead of waiting in an application queue. Separate slots mean an export does not consume thumbnail capacity. These are host-local advisory locks, not distributed limits across separate containers or machines. Run native PHP and its workers under the same application user.

Web exports have a 120-second generation deadline, 768 MiB child address-space limit and 512 MiB output-file limit. ZIP/CBZ store already-compressed images without recompressing them. PDF/HTML still use the existing exporters; large collections may exceed the web limits and receive a clear 503. No page-count cutoff is imposed. For larger PDF/HTML exports, the existing `script/py/pdf.py` and `script/py/html.py` tools can be invoked locally with the configured storage environment and an output path; those CLI tools do not inherit the web supervisor limits. These limits are conservative starting defaults, not a promise that every gallery fits.

Thumbnail generation has a 15-second deadline, 512 MiB child address-space limit, 8 MiB output limit, one libvips thread and a 32 MiB source-file cap (413 when exceeded). Existing 320/640 widths and cover/fit modes remain supported. GET uses a private ephemeral cache limited to 64 MiB and 512 derivatives; a content fingerprint prevents stale reuse after same-size/same-time edits. Checking the fingerprint still reads the source file. HEAD and matching If-None-Match requests do not convert images; HEAD may omit Content-Length. Cold concurrent misses for one key return a retry response while its producer works.

The private directory is `/tmp/localnh-jobs-<data-root-hash>` (or the native PHP temporary directory). It contains fixed lock files, at most one 512 MiB export workspace, two 8 MiB thumbnail workspaces, and the bounded derivative cache. It is outside the collection and snapshots; Docker recreation discards it. Cache eviction uses oldest file modification time, not a persistent access log. Publication is atomic. Normal failures remove partial output; after a hard crash, the next owner of a slot removes its previous partial workspace. Do not remove lock files while jobs are running, because that would break mutual exclusion.

Child resource limits and deadlines protect generation; they are not a limit on download bandwidth, total HTTP connections, or every kind of memory used by the host. A disconnected browser may leave generation running until its bounded completion/deadline. The export slot remains held while its response is streamed, so a slow downloader can delay another export; upstream connection/time limits are a separate deployment concern. Cache/slot exhaustion never changes archive permissions, session state or stored galleries.

## Archive admission and upstream limits

Web and CLI archive commands share one nonblocking lock at `cache/archive.lock`. Web/CLI deletion also respects it; a conflicting web request returns 503/Retry-After: 3 and CLI exits 75. Public archive still requires the existing option and a valid POST/CSRF confirmation; merely viewing a missing gallery never downloads it. Run native web and CLI writers as the same application user. The lock belongs to the data filesystem, so all cooperating processes must use the same data root. Never delete the lock file while a writer runs. Offline import/migration/repair still requires stopping every writer.

Archive work has a 900-second wall deadline, 768 MiB child address-space ceiling, 32 MiB per image response/output file, 2 MiB metadata response, 512 MiB total downloaded response bytes, 512 MiB converted workspace, and 1–1000 pages. A 30-second per-request timeout remains. These are per-job safety ceilings, not a guarantee every gallery below them succeeds. Missing/failed pages now fail the archive rather than committing a misleading complete page list. CLI fetch now stores a completed gallery automatically; an existing record is skipped after locking, while `--fix` explicitly refreshes metadata. Existing image files are preserved and validated, not overwritten; corrupt old files require deliberate offline repair rather than destructive automatic cleanup.

Work happens in private `cache/.archive-work`, outside `cache/images` and snapshots. Only completed images are published; the database commits before the UI redirects. Failure/disconnect cleanup removes only the workspace, never a gallery directory. A crash during publication can leave complete unreferenced images; they are safe to reuse on retry and are not automatically deleted. This is not a fully transactional filesystem/database migration or a replacement for backups. The supervisor retains the lock, kills its child group on timeout/handled termination, and uses Linux parent-death signaling to prevent an unsupervised downloader after the supervisor is killed. The next admitted job cleans a stale workspace after a hard crash. Archive support therefore requires Linux, as does the supplied Docker deployment.

An upstream HTTP 429 is separate from local login limits. The downloader records the upstream Retry-After (seconds or HTTP date), defaults to 60 seconds when absent/invalid, and prevents repeated web/CLI fetches until that time. No automatic retry loop or alternate identity is used. `cache/archive-retry.json` stores only the cooldown timestamp. A rejection after streaming starts is displayed in the progress page; a later attempt during cooldown receives HTTP 429 with Retry-After before work starts. Existing galleries, reading, exports and deletion do not require another upstream request. Check API credentials or provider quota if rate limits persist; do not assume replacing application code removes a provider limit.

## Collection summaries and favorites batches

Statistics and the shared autocomplete/directory tag index use two fixed private ephemeral cache files, outside snapshots. Each entry expires after 15 seconds. Archive, web/CLI deletion and successful snapshot import publish a new collection revision to invalidate entries immediately; SQLite header/file identity checks also detect ordinary database changes. Tools that write outside these paths, especially externally managed WAL writes or image-only changes, may remain stale until the 15-second expiry. Invalidation is best-effort and never converts a successfully committed collection update into an error if the revision file cannot be written.

A cache miss admits one builder per summary kind; simultaneous misses receive 503/Retry-After: 3. Each builder uses the existing supervisor with a 15-second deadline, 512 MiB address-space limit and 8 MiB output/cache-entry limit. The tag index allows at most 50,000 distinct category/term pairs. Over-limit or changing collections receive a retry response rather than an unbounded in-request scan. Stats and tags together use at most 16 MiB of cached output plus two 8 MiB temporary outputs, in addition to the previously documented thumbnail/export budgets. No cache file is created per search query.

Autocomplete accepts GET/HEAD, requires at least two bytes to search, accepts at most 128 query bytes, and still returns at most ten suggestions in the existing category order. The favorites metadata API accepts JSON POST only, at most 8 KiB and 200 raw IDs per request; IDs are numeric strings up to 20 digits or nonnegative integers. Empty lists are valid. Oversized requests return 413, invalid inputs 400, unsupported content types 415, and unsupported methods 405. The favorites page batches longer lists sequentially and retains local favorites unchanged if any batch fails; it does not classify incomplete results as orphaned galleries.
