# ClamAV for antivirus scans (SRS 4.25)

What runs where:

- **ClamAV container** on each server with app volumes: clamd plus freshclam (signatures refresh
  several times a day) and the panel's YARA rules in `dmp-webshells.yar`.
- **Agent** (`av.scan`) runs `clamdscan` on one volume, hashes each detection through the volume
  (never following links out of it) and drops files the organisation marked "not a threat".
  `av.quarantine` moves a file out of the volume into `<state_dir>/quarantine/<threat id>` with no
  permissions at all; `av.restore` puts it back with its old permissions; `av.delete` removes it.
- **Panel**: threats appear on the Antivirus page; with `ANTIVIRUS_AUTO_QUARANTINE=true` (the
  default) every new detection is quarantined at once. `php artisan antivirus:scan-all` runs every
  Sunday at 02:00 and can be run by hand; a single volume can be scanned from the page.

## Install (each server)

1. In this folder: `docker compose up -d`. The first start downloads the signature database
   (a few minutes; clamd is ready when `docker exec clamav clamdscan --version` answers).
2. Nothing to configure in the panel; the agent uses the container named `clamav`
   (`clamav_container` in the agent config to change it).

## Verify

```
printf 'X5O!P%%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' \
  | docker run --rm -i -v <volume>:/v alpine sh -c 'cat > /v/eicar.txt'
```

then scan that volume from the Antivirus page: `eicar.txt` should appear as
`Eicar-Signature` and move to quarantine.

## Real-time scanning (AV-2)

Platform staff switch it on per server under Antivirus → Server protection. The panel then starts
`clamonacc` in this container with the server's volumes as its watch list, and every 5 minutes reads its
detections from `/var/log/clamav/clamonacc.log`, which turn into threats like those from a full scan.
clamonacc needs fanotify, so the container needs two changes:

1. In `docker-compose.yml`, under `clamav:`, add:

   ```yaml
       cap_add: [SYS_ADMIN]          # fanotify
       security_opt: [apparmor:unconfined]
   ```

2. Copy the image's `clamd.conf` out once
   (`docker cp clamav:/etc/clamav/clamd.conf ./clamd.conf`). Add these lines and mount it at
   `/etc/clamav/clamd.conf:ro`:

   ```
   OnAccessPrevention no          # report, never block writes; the panel quarantines
   OnAccessExcludeUname clamav    # never scan clamd's own reads
   OnAccessMaxFileSize 25M
   ```

The settings are applied again every hour, so a rebooted server watches its volumes again without anyone
logging in.

## Quarantine encryption (AV-5)

The agent encrypts quarantined files itself, so there is nothing to set up. The key is
`<agent state>/quarantine.key`. Back it up with the agent's state if quarantined files must survive a
rebuild of the server.

## Not covered yet

- Files readable only by their owner may be skipped if clamd cannot open them; check
  `docker logs clamav` for "Access denied" after the first scan.

# Brute-force protection (AV-8)

Handled by CrowdSec (`../crowdsec`), which already bans IPs and feeds the firewall. Add the SSH and
WordPress collections and the SSH log to its acquisition:

- `COLLECTIONS` in `../crowdsec/docker-compose.yml`: add `crowdsecurity/sshd crowdsecurity/wordpress`.
- `../crowdsec/acquis.yaml`: the second document reads `/var/log/auth.log`.
