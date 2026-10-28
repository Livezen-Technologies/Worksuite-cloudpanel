# Traefik ingress with ACME (CERT_DRIVER=traefik)

1. On the ingress server: `docker network create proxy; install -d -m 700 /etc/dockermgr/traefik/{dynamic,secrets}; install -m 600 /dev/null /etc/dockermgr/traefik/acme.json`.
2. Edit the ACME e-mail in `traefik.yml`, then `docker compose up -d`.
3. Panel `.env`: `CERT_DRIVER=traefik`, optionally `TRAEFIK_INGRESS_SERVERS=<server-id,...>` (default: servers tagged
   `ingress`, else each app's own server). The agent's `proxy_config_dir` / `acme_json` config keys default to
   `/etc/dockermgr/traefik/dynamic` and `/etc/dockermgr/traefik/acme.json`.

Flow: a domain whose DNS verifies gets a router in `dmp-domains.yml` (`proxy.config`) with `certResolver: le`
(HTTP-01) or `le-dns-<provider>` (DNS-01, wildcards as `main: example.com, sans: ["*.example.com"]`). Traefik
obtains the certificate; `php artisan proxy:sync-certs` (every 6 h, and right after issuing) runs `proxy.certs`,
which returns only domain, SANs, issuer and notBefore/notAfter from `acme.json` — never keys — and the panel
marks the domain `active` with the real expiry. `php artisan proxy:push` re-pushes changed config every 10 min.
