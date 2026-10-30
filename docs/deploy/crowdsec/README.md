# CrowdSec on the ingress (FW-001 enforcement)

The panel's firewall rules are enforced in two layers:

| Rule | Enforced by |
| --- | --- |
| Rate limit (block, throttle) | Traefik's native `rateLimit` middleware: 429 at once |
| Block | also a CrowdSec **ban** once the limit is exceeded: 403 for `CROWDSEC_BAN_MINUTES` |
| Challenge, reCAPTCHA | a CrowdSec **captcha** decision; the bouncer serves the captcha page |
| Country policy | a CrowdSec scenario on GeoIP (`evt.Enriched.IsoCode`): ban or captcha |
| Challenge-protected path | a CrowdSec scenario that asks for a captcha |
| Log | an alert only, no decision |

`php artisan firewall:sync-events` (every 5 minutes) reads CrowdSec's alerts and fills the Events tab.

Without CrowdSec (`CROWDSEC_ENABLED=false`, the default) only rate limits are enforced and the Firewall
screen says so.

## Install (each ingress server)

1. Pick a bouncer key: `openssl rand -hex 32`.
2. `install -d -m 755 /etc/dockermgr/crowdsec` then, in this folder:
   `CROWDSEC_BOUNCER_KEY=<key> docker compose up -d`.
3. Traefik needs the bouncer plugin in its **static** config; it is already declared in
   `../traefik/traefik.yml` (`experimental.plugins.bouncer`, plugin v1.8.1). Put the plugin's captcha page
   next to Traefik's compose file, where `../traefik/docker-compose.yml` mounts it as `/captcha.html`:

   ```
   curl -fsSLo captcha.html https://raw.githubusercontent.com/maxlerebourg/crowdsec-bouncer-traefik-plugin/v1.8.1/captcha.html
   ```

   Then restart Traefik.
4. Panel `.env`:

   ```
   CROWDSEC_ENABLED=true
   CROWDSEC_BOUNCER_KEY=<key>
   # optional: CROWDSEC_LAPI_HOST=crowdsec:8080, CROWDSEC_BAN_MINUTES=240,
   # CROWDSEC_TRUSTED_PROXIES=<CIDRs of a CDN in front of Traefik, if any>
   ```

   then `php artisan config:cache && php artisan proxy:push`.
5. Captcha keys: Settings → Firewall (Turnstile, reCAPTCHA or hCaptcha site and secret key). Without
   them the bouncer **bans** where it would have asked for a captcha, so add the keys before using
   challenge rules, country challenges or protected paths.

## Verify on staging before production

The scenarios read fields set by CrowdSec's `crowdsecurity/traefik-logs` parser (`target_fqdn`,
`http_path`, `http_verb`) and GeoIP enrichment. Check them against your real log lines:

```
docker exec crowdsec cscli explain --file /var/log/traefik/access.log --type traefik | head -60
docker exec crowdsec cscli scenarios list | grep dmp/
docker exec crowdsec cscli decisions list
```

Then trip a rule from a test IP (for example six POSTs to `/wp-login.php` when the login rule allows
five a minute) and confirm the 403 or captcha page, and the event in the panel.

**Do not lock yourself out:** before enabling, allow-list your office or VPN addresses with a CrowdSec
whitelist parser, for example `/etc/dockermgr/crowdsec/parsers/s02-enrich/dmp-whitelist.yaml`:

```yaml
name: dmp/whitelist
description: "Never act on these addresses"
whitelist:
  reason: "office and VPN"
  ip: ["203.0.113.10"]
  cidr: ["198.51.100.0/24"]
```

then `docker kill --signal SIGHUP crowdsec`. A ban already placed is removed with
`docker exec crowdsec cscli decisions delete --ip <ip>`.

The agent writes only `scenarios/dmp-*.yaml` and `profiles.yaml`, checks the result with
`crowdsec -t` and keeps the previous files if CrowdSec rejects them, then reloads CrowdSec (SIGHUP).
