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

Automated auth, scheme and browser regression scripts are in `tests/` for development only. Keep them and fixtures out of deployed instances and release images. Testing synthetic credentials does not replace checking your final public route.

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
