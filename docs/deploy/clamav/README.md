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

## Not covered yet

- Real-time scanning on write (AV-2) and encrypting the quarantine store.
- Files readable only by their owner may be skipped if clamd cannot open them; check
  `docker logs clamav` for "Access denied" after the first scan.

# Brute-force protection (AV-8)

Handled by CrowdSec (`../crowdsec`), which already bans IPs and feeds the firewall. Add the SSH and
WordPress collections and the SSH log to its acquisition:

- `COLLECTIONS` in `../crowdsec/docker-compose.yml`: add `crowdsecurity/sshd crowdsecurity/wordpress`.
- `../crowdsec/acquis.yaml`: the second document reads `/var/log/auth.log`.
