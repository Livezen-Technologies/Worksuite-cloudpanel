# Runbook: finishing Infrastructure on the live servers

What is left of Infrastructure is operations on the production servers, not code. Do the
steps in order, one site at a time, and stop at the first surprise. Every step says how to
check it worked and how to undo it.

| Server | Role |
|---|---|
| `51.89.7.73` | the panel (`/opt/worksuite-cloudpanel`, branch `main`) and the seven live sites |
| `51.89.192.43` | old copies and the verified backups of the four sites that are down |

Read **Warnings** at the end of `docs/status-and-remaining-work.md` first. Both of them have
already cost a customer data or downtime.

---

## 0. Before anything: deploy `main` and take a snapshot

```bash
cd /opt/worksuite-cloudpanel && sudo git pull --ff-only origin main
```

```bash
cd /opt/worksuite-cloudpanel/api && sudo php artisan migrate --force && sudo php artisan config:cache && sudo php artisan route:cache
```

```bash
cd /opt/worksuite-cloudpanel/web && sudo npm ci && sudo npm run build
```

```bash
sudo systemctl restart wscp-queue wscp-schedule php8.4-fpm && sudo systemctl reload nginx
```

Then run the 200-check loop from **Verification** in the status doc. All eight must answer
200 before you go on. If one does not, fix that first; do not start a restore on a server
that is already unhealthy.

Take a fresh backup of everything on `51.89.7.73` (Operations → Backups → run each policy
now) so every step below has a way back.

---

## 1. Restore the four sites that are down

Sites: `shopexpress.lk` (highest traffic, ~9,900 hits a day), `diygeek.cc`,
`softkeymart.com`, `livezenbiz.com`. Do them in that order.

### 1a. Check the site is really down and not trading somewhere else

This is the step that lost `seliknaent` 18 sales. For each domain:

```bash
dig +short A shopexpress.lk
```

```bash
curl -sI https://shopexpress.lk/ | head -5
```

- If DNS points at a server that answers with the real site, **the site is live somewhere**.
  Do not restore from a backup; migrate from that live copy instead (Migrations, with a
  fresh backup of the live copy, then a DNS cut-over).
- Only if nothing answers, or only an error page answers, carry on.

On `51.89.192.43`, check whether the old copy's database is still being written to:

```bash
sudo docker ps --format '{{.Names}}\t{{.Status}}' | grep -i shopexpress
```

If a database container is running there, compare the newest order/post timestamp with the
backup's date. Newer rows mean the old copy is still live: stop and dump it, and use that
dump instead of the backup.

### 1b. Restore through the panel

1. Servers → add `51.89.192.43` if it is not there yet, and install the agent.
2. Operations → Backups → Snapshots: find the newest verified snapshot of the site and run a
   **restore test** first. The site must start in the sandbox and the database must have
   rows.
3. Restore → **side by side** (into `/var/lib/dockermgr/restores/<id>`), not "replace", onto
   `51.89.7.73`.
4. Create the app from the restored files and database. Before starting anything with
   `docker compose`, run `docker compose --env-file .env.secrets config`, and fix any
   "variable is not set" warning first. A plain `up -d` once recreated `dazzlingtours` with
   blank database passwords.
5. Check it on the temporary platform address (`<app>.<platform domain>`): home page,
   log-in, one order or contact form.
6. Only then point DNS at `51.89.7.73`. Keep the old A record written down so you can put it
   back.

**Done when** the domain answers 200 with real content from `51.89.7.73`, and the uptime
check for it is green for an hour.

### 1c. Retire the old copies

Once all four have run cleanly for **7 days**:

```bash
sudo docker stop <old containers on 51.89.192.43>
```

Leave them stopped (not removed) for another 7 days. Then remove the containers, keep the
last backup of each, and record the date in the status doc.

---

## 2. Bring the seven live sites under the panel

Each site is routed by a hand-written Traefik file (`giantforests.yml`, …), so firewall rules,
the WAF, public pages and uptime checks do not reach it. The panel needs `CERT_DRIVER=traefik`
in `api/.env` for this (check with `grep CERT_DRIVER /opt/worksuite-cloudpanel/api/.env`).

Order: the quietest site first (`arduino.lk` or `thingerbits.com`), `dazzlingtours.com` and
`seliknaent` last.

1. Servers → `51.89.7.73` → **Routes** → **Scan the server**.
2. Check what the panel read for the site: its hosts, and the container URL it sends them to
   (for example `http://giantforests:80`). Files with only shared settings (`security.yml`)
   show as "not adoptable"; that is expected, so leave them.
3. **Adopt** the site's file. The panel adds its own route, renames the old file to
   `<name>.adopted`, and checks every host over HTTPS. If a host fails, the old file is put
   back and the panel's route removed automatically.
4. Check: the 200 loop, and Domains now lists the site's hosts. Each host gets a new
   certificate from the panel's resolver, which can take a minute.
5. Wait an hour, then do the next site.

**Undo by hand**, if needed:

```bash
cd /etc/dockermgr/traefik/dynamic && sudo mv giantforests.yml.adopted giantforests.yml
```

Then delete the adopted domains in the panel.

**Done when** all seven are adopted, the Firewall page's rules show on their routers
(Traefik dashboard, or `dmp-domains.yml`), and the brute-force source seen on
`dazzlingtours.com/wp-login.php` can be blocked from Firewall → IP lists.

---

## 3. Close CloudPanel's port 8433

`8433:8443/tcp` is published to the whole internet on a self-signed certificate. First find
who uses it:

```bash
sudo docker ps --format '{{.Names}}\t{{.Ports}}' | grep 8433
```

Then do one of these:

- **Nobody needs it from outside:** publish it on localhost only. Change the port line in its
  compose file to `127.0.0.1:8433:8443`, then
  `docker compose --env-file .env.secrets up -d` (see the warning above), and reach it with
  `ssh -L 8433:127.0.0.1:8433 <server>`.
- **It is needed from a few places:** keep the port and allow only those addresses with UFW.
  Note that Docker-published ports bypass UFW's normal rules, so the rule must go in the
  `DOCKER-USER` chain:

```bash
sudo iptables -I DOCKER-USER -p tcp --dport 8443 ! -s <your IP> -j DROP
```

**Check from outside the server:**

```bash
nc -zv -w 5 51.89.7.73 8433
```

It must fail (closed or filtered).

---

## 4. Put `dazzlingtours` back to 1 GB

Its memory was raised to 2 GB on a wrong diagnosis; the outage was a database connection
failure. After step 2 has adopted it and it has run cleanly for a day:

1. Set its memory limit back to 1 GB, the same as the other six.
2. Watch it for 24 hours: memory, restarts and the uptime check.

If it hits the limit again, look at what is using the memory before raising it.

---

## When it is all done

1. Tick the items in section 3 of `docs/status-and-remaining-work.md`.
2. Set Infrastructure to 100%.
3. Run the full 200 loop one last time.
