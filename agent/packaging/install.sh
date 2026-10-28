#!/bin/sh
# Docker Manager Portal agent installer.
#
#   curl -fsSL https://panel.example.com/agent/install.sh | sudo sh -s -- \
#     --url https://panel.example.com --token XYZ
#
# Options:
#   --url URL            panel base URL (required)
#   --token TOKEN        single-use enrolment token (required)
#   --download-url URL   binary base URL (default: $URL/agent/v1/download);
#                        the file fetched is <base>/dockermgr-agent-linux-<arch>
#   --sha256 HEX         expected sha256 of the binary (recommended)
#   --version VER        agent version to fetch (default: latest)
set -eu

URL="" TOKEN="" DL="${DOCKERMGR_AGENT_DOWNLOAD_URL:-}" SHA="" VER="latest"
BIN=/usr/local/bin/dockermgr-agent
UNIT=/etc/systemd/system/dockermgr-agent.service
CONF=/etc/dockermgr/agent.json

die() { echo "install: $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --url) URL="$2"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --download-url) DL="$2"; shift 2 ;;
    --sha256) SHA="$2"; shift 2 ;;
    --version) VER="$2"; shift 2 ;;
    *) die "unknown option $1" ;;
  esac
done

[ -n "$URL" ] && [ -n "$TOKEN" ] || die "--url and --token are required"
case "$URL" in https://*) ;; *) die "panel URL must be https" ;; esac
[ "$(id -u)" -eq 0 ] || die "must run as root"
command -v docker >/dev/null 2>&1 || die "Docker Engine 24+ is required"
command -v systemctl >/dev/null 2>&1 || die "systemd is required"

case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) die "unsupported architecture $(uname -m)" ;;
esac

[ -n "$DL" ] || DL="${URL%/}/agent/v1/download"
SRC="${DL%/}/dockermgr-agent-linux-${ARCH}?version=${VER}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Downloading agent ($ARCH)..."
if command -v curl >/dev/null 2>&1; then
  curl -fsSL --proto '=https' --tlsv1.2 -o "$TMP/agent" "$SRC"
elif command -v wget >/dev/null 2>&1; then
  wget -q -O "$TMP/agent" "$SRC"
else
  die "curl or wget is required"
fi

if [ -n "$SHA" ]; then
  echo "$SHA  $TMP/agent" | sha256sum -c - >/dev/null || die "checksum mismatch"
fi
chmod 0755 "$TMP/agent"
"$TMP/agent" version >/dev/null || die "downloaded binary does not run on this host"

install -m 0755 "$TMP/agent" "$BIN"
install -d -m 0700 /etc/dockermgr /var/lib/dockermgr

if [ ! -f "$CONF" ]; then
  echo "Enrolling with $URL..."
  "$BIN" enroll --url "$URL" --token "$TOKEN" --config "$CONF"
else
  echo "Existing enrolment found at $CONF; skipping enrol."
fi

cat > "$UNIT" <<'UNIT'
[Unit]
Description=Docker Manager Portal agent
Documentation=https://github.com/livezen-technologies/cloudpanel
After=network-online.target docker.service
Wants=network-online.target
# Do not bind to docker.service: restarting Docker must not stop the agent,
# and restarting the agent never touches running containers.

[Service]
Type=simple
ExecStart=/usr/local/bin/dockermgr-agent run --config /etc/dockermgr/agent.json
Restart=always
RestartSec=5
# Only the agent process is signalled; containers belong to dockerd.
KillMode=mixed
TimeoutStopSec=30
Environment=DOCKERMGR_AGENT_CONFIG=/etc/dockermgr/agent.json
StateDirectory=dockermgr
StateDirectoryMode=0700
ConfigurationDirectory=dockermgr
ConfigurationDirectoryMode=0700
UMask=0077
NoNewPrivileges=yes
ProtectHome=read-only
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now dockermgr-agent.service
echo "Docker Manager agent installed and running."
