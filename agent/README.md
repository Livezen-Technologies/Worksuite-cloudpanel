# Docker Manager Portal agent

Single static Go binary (`CGO_ENABLED=0`, linux/amd64 and linux/arm64) that runs on each
managed server, connects **outbound only** to the panel (`/agent/v1`, see `docs/API.md`
"Agent protocol"), reports stats and Docker inventory, and executes typed, signed,
policy-checked commands against the local Docker Engine API (`/var/run/docker.sock`).
There is no generic remote shell.

## Build and test

```sh
make test                      # go vet ./... && go test ./...
make build VERSION=1.0.0 UPDATE_PUBLIC_KEY=<base64 ed25519 pubkey>
# -> dist/dockermgr-agent-linux-amd64, dist/dockermgr-agent-linux-arm64
```

Only the Go standard library is used.

## Install

The panel prints a one-liner using `packaging/install.sh`:

```sh
curl -fsSL https://panel.example.com/agent/install.sh | sudo sh -s -- \
  --url https://panel.example.com --token <enrolment-token> [--sha256 <hex>]
```

It detects the arch, downloads `<url>/agent/v1/download/dockermgr-agent-linux-<arch>`
(override with `--download-url`), installs `/usr/local/bin/dockermgr-agent`, runs
`enroll`, and installs/enables `packaging/dockermgr-agent.service`.

## Commands

| Command | Purpose |
| --- | --- |
| `agent enroll --url https://panel --token XYZ [--config P] [--hostname H] [--ca-file F] [--state-dir D] [--docker-socket S] [--update-public-key K]` | Collects facts (os from `/etc/os-release`, kernel, docker version, cpu cores, RAM MB, disk GB, arch), calls `POST /agent/v1/enroll`, writes config at mode 0600. |
| `agent run [--config P]` | Heartbeat loop + command execution. |
| `agent rollback [--binary P]` | Restore `<binary>.old` after an update. |
| `agent version` | Print version. |

Config path: `--config`, else `$DOCKERMGR_AGENT_CONFIG`, else `/etc/dockermgr/agent.json`.
Fields: `panel_url, server_id, agent_secret, hmac_key, heartbeat_interval, ca_file,
state_dir (/var/lib/dockermgr), policy_path (/etc/dockermgr/policy.json), docker_socket,
update_public_key`.

## Heartbeat

Every `heartbeat_interval` seconds (default 15) the agent POSTs `/agent/v1/heartbeat` with
`stats` (cpu/ram/disk % from `/proc` and statfs, cumulative non-loopback net rx/tx bytes) and
inventory (containers with health and per-container CPU %/memory MB from a one-shot stats
read, images, networks, volumes). If Docker is unreachable the lists are `null` and
`docker_error` is set (so the panel does not mistake it for "no containers").
On failure it retries with exponential backoff and jitter (1 s doubling, capped at 60 s).
A panel outage never causes the agent to touch containers.

## Command security

A command is executed only if **all** hold:

1. `type` is in the closed list below and `version == 1`;
2. `signature == hex(HMAC-SHA256(hmac_key, id + "." + type + "." + canonical_json(payload) + "." + issued_at))`
   where canonical JSON has keys sorted recursively, no whitespace, number literals kept as
   received, strings without HTML escaping;
3. `issued_at` is at most 10 min old (and at most 2 min in the future);
4. the local policy allows the type;
5. the operation id was never seen before. Ids are written durably to
   `<state_dir>/journal.json` *before* execution; duplicates are ignored silently (no new result
   is reported, so an earlier result is not overwritten).

Rejected commands are reported as `failed` with the reason. Results go to
`POST /agent/v1/commands/{id}/result` (`running` with progress, then `succeeded`/`failed`).

### Local policy (`/etc/dockermgr/policy.json`)

```json
{ "disabled_types": ["backup.run", "agent.update"], "maintenance_mode": false }
```

`"*"` disables everything; `maintenance_mode` refuses `container.create` and `compose.up`.
A malformed policy file fails closed (all commands refused). Reloaded on change.

## Command payloads

| Type | Payload |
| --- | --- |
| `container.start/pause/unpause` | `{container_id}` |
| `container.stop/restart` | `{container_id, timeout?}` |
| `container.remove` | `{container_id, force?, keep_volumes? (default true)}` |
| `container.logs` | `{container_id, tail? (200), timestamps?}` → output `{logs}` |
| `container.prune` | `{until?, labels?{}}` |
| `container.create` | see below → output `{container_id}` |
| `image.pull` | `{image, tag?, registry_auth?{username,password,serveraddress,identitytoken}}` |
| `image.remove` | `{image, force?}` |
| `image.prune` | `{all? (false = dangling only), until?, labels?}` |
| `network.create` | `{name, driver? (bridge/overlay/macvlan/ipvlan), internal?, attachable?, subnet?, gateway?, labels?}` |
| `network.remove` | `{network_id \| name}` (built-in networks refused) |
| `volume.create` | `{name, driver?, driver_opts?, labels?}` |
| `volume.remove` | `{name, force?}` |
| `compose.up` | `{project, compose_yaml, env?{}, pull?}` — written to `<state_dir>/compose/<project>/compose.yaml` (+ `.env`), then `docker compose -p <project> up -d --remove-orphans` |
| `compose.down` | `{project, remove_volumes? (false)}` |
| `compose.restart` | `{project}` |
| `backup.run` | `{repository, paths[] (absolute), tags?, exclude?, env?{RESTIC_*,AWS_*,B2_*,AZURE_*,GOOGLE_*,OS_*,ST_*}, init?}` → `restic --repo=R --json backup ...` |
| `restore.run` | `{repository, snapshot? (latest), target (absolute), include?, env?}` |
| `agent.update` | `{url (https), signature (base64/hex ed25519 over the binary), sha256?, version}` |
| `agent.restart` | `{}` |
| `image.build` | `{repo, ref? (main), dockerfile_path? (Dockerfile), context? (.), build_args?{}, labels?{}, git_auth?{token, username?}, image_tag, push?{registry, username, password}}` → output `{image, image_id, digest, repo_digest?, commit, log[]}` |
| `image.scan` | `{image}` → output `{image, scanner: "trivy", total, counts{critical,high,…}, vulnerabilities[{id, package, installed, fixed?, severity, title?, target}] (≤1000, worst first)}` |
| `container.healthcheck` | `{container_id, port?, path? (/), timeout_s? (60, ≤900), interval_s? (2), log_lines? (50)}` → output `{healthy, method: docker\|http\|running, url?, status?}`; on failure `{healthy:false, reason, logs}` |

### Builds, scans and health checks

- **`image.build`** (run it only on servers with the panel's `builder` role, SRS 2.4): shallow
  `git -c core.hooksPath=/dev/null -c protocol.ext.allow=never clone --depth 1 --single-branch --no-tags --branch <ref> -- <repo> <dir>`
  via exec with fixed arguments (no shell) into a temp dir under `<state_dir>/builds/`, with `GIT_TERMINAL_PROMPT=0`,
  `HOME=<tmp>` and the token passed only through `GIT_CONFIG_*` env (`http.extraHeader: Authorization: Basic …`), never argv.
  Repos must be `https://`; `file://` and absolute paths need `DOCKERMGR_ALLOW_LOCAL_GIT=1` in the agent environment.
  `context`/`dockerfile_path` must stay inside the checkout (symlinks resolved). The context is tarred in Go (no `.git`,
  symlinks stored as links, ≤1 GiB) and posted to Engine `/build` (classic builder, `networkmode=default`; host network,
  extra hosts and privileged entitlements cannot be requested). Log lines are reported as `running` progress (batched per
  second / 25 lines). With `push`, `/images/{name}/push` is called with `X-Registry-Auth` and the manifest digest is returned
  (`repo_digest = name@sha256:…`). The temp dir is always removed.
- **`image.scan`** runs `trivy image --format json --quiet --output <tmp>/report.json <image>` if `trivy` is on `PATH`,
  otherwise fails with `trivy not installed`. Cache: `<state_dir>/trivy-cache`.
- **`container.healthcheck`** polls `GET /containers/{id}/json`: a stopped container fails at once; Docker's own health
  status wins when the image defines one; otherwise, with a `port`, `GET http://<container internal IP>:<port><path>`
  (no proxy, redirects not followed; 2xx/3xx passes); without a port the container must stay up for two polls. On failure
  the last `log_lines` lines of the container log are returned (UHOST-006).

`container.create`:
`{name?, image, registry_auth?, cmd?, entrypoint?, env ({K:V} or ["K=V"]), user?, working_dir?,
ports?[{container_port, host_port?, protocol?, host_ip?}], networks?[{name, aliases?}],
mounts?[{type: volume|bind|tmpfs, source, target, read_only?}], read_only_rootfs?, tmpfs?[],
memory_mb?, cpus?, pids_limit? (1024), restart_policy? (unless-stopped), max_retries?,
healthcheck?{test (string → CMD-SHELL, or list), interval_s, timeout_s, retries, start_period_s, disable},
labels?{} (Traefik labels pass through), cap_add?[], start? (true), pull? (missing|always|never)}`

Secure defaults always applied: `CapDrop: ALL` (only CHOWN, DAC_OVERRIDE, FOWNER, FSETID, KILL,
SETGID, SETUID, NET_BIND_SERVICE may be re-added), `no-new-privileges`, never privileged,
no host network, `root` user refused, pids limit, `init`, rotated json-file logs, `/tmp` tmpfs
when the root is read-only, label `io.dockermgr.managed=true`. Bind mounts are only allowed
under `<state_dir>/data` and `/srv/dockermgr`.

## Updates

`agent.update` downloads (https only, ≤256 MB), checks optional sha256, verifies the ed25519
signature against the key compiled in (`-X main.updatePublicKey=`) or `update_public_key` in
config (no key → updates refused), stages `<bin>.new`, runs `<bin>.new version` as a health
check, keeps the current binary as `<bin>.old`, swaps with an atomic rename, reports success and
re-execs. A marker file counts starts until the first successful heartbeat; after 3 failed starts
the agent rolls back to `<bin>.old` automatically. Containers are never stopped by an agent
restart or update.

## Layout

```
cmd/agent            CLI, heartbeat loop, re-exec, update-health marker
internal/config      config file (0600, atomic write)
internal/docker      Docker Engine API client over the unix socket (net/http)
internal/facts       host facts for enrolment
internal/stats       /proc sampling + Docker inventory
internal/protocol    panel client and wire types
internal/signing     canonical JSON + HMAC-SHA256
internal/executor    validation, journal, policy check, command handlers
internal/policy      local policy file
internal/update      signed, staged, reversible self-update
packaging/           systemd unit and installer
```

## Limits / not yet implemented

- mTLS client certificates (SRS 6.4 / SEC-003): `certificate` from enrolment is stored but no CSR
  is sent and no client cert is presented yet; server TLS is verified against system roots
  (+ optional `ca_file`).
- The binary download endpoint used by `install.sh` (`/agent/v1/download/...`) is not in API.md.
- Auto-rollback only covers a new binary that starts but cannot reach the panel; a binary that
  crashes before `run` begins relies on the pre-swap `version` health check.
- Commands run one at a time in arrival order, so a long `image.build` delays other commands on that server (and a command
  queued behind it for more than 10 min expires). Keep builders separate from app servers.
- Builds use the classic builder (no BuildKit features such as `RUN --mount`); no build cache pruning yet.
