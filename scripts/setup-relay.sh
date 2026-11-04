#!/usr/bin/env bash
# Sets up the terminal relay (web terminal and SSH gateway) on the panel server.
# Safe to run again: it keeps an existing secret and only changes what is missing.
#
#   sudo scripts/setup-relay.sh                       # defaults below
#   sudo scripts/setup-relay.sh --ssh-port 2222       # also turn on SSH access (PuTTY / ssh)
#   sudo scripts/setup-relay.sh --api-env /srv/panel/api/.env --api-url http://127.0.0.1:9000
#   scripts/setup-relay.sh --check                    # only report what is set up
#
# Steps: 1. one shared RELAY_SECRET in the API's .env and /etc/dockermgr/relay.env (0600),
# never printed; 2. the dockermgr-relay binary (built with Go 1.26+, or taken from
# agent/dist); 3. the systemd service; 4. an nginx snippet for /relay/terminal/;
# 5. the API's config cache; 6. a health check.
set -euo pipefail

ROOT="${SETUP_ROOT:-}" # tests point this at a scratch directory
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_ENV="$REPO/api/.env"
API_URL="http://127.0.0.1:8000"
LISTEN="127.0.0.1:8090"
SSH_PORT=""
CHECK=0
SKIP_SYSTEMD=0
SKIP_NGINX=0
SKIP_BUILD=0

usage() { sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }
while [ $# -gt 0 ]; do
  case "$1" in
    --api-env) API_ENV="$2"; shift 2 ;;
    --api-url) API_URL="$2"; shift 2 ;;
    --listen) LISTEN="$2"; shift 2 ;;
    --ssh-port) SSH_PORT="$2"; shift 2 ;;
    --check) CHECK=1; shift ;;
    --no-systemd) SKIP_SYSTEMD=1; shift ;;
    --no-nginx) SKIP_NGINX=1; shift ;;
    --no-build) SKIP_BUILD=1; shift ;;
    -h|--help) usage 0 ;;
    *) echo "Unknown option: $1" >&2; usage 1 ;;
  esac
done

ETC="$ROOT/etc/dockermgr"
RELAY_ENV="$ETC/relay.env"
BIN="$ROOT/usr/local/bin/dockermgr-relay"
UNIT="$ROOT/etc/systemd/system/dockermgr-relay.service"
NGINX_SNIPPET="$ROOT/etc/nginx/snippets/dockermgr-relay.conf"
NGINX_HTTP="$ROOT/etc/nginx/conf.d/dockermgr-relay-upstream.conf"
PORT="${LISTEN##*:}"

ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
todo() { printf '  \033[33m•\033[0m %s\n' "$*"; }
step() { printf '\n%s\n' "$*"; }

# Reads KEY's value from an env file (last one wins), without printing it.
env_get() { [ -f "$2" ] && grep -E "^$1=" "$2" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }
# Sets KEY=value in an env file, replacing an existing line or appending one.
env_set() {
  local key="$1" val="$2" file="$3" tmp
  touch "$file"
  tmp="$(mktemp)"
  grep -vE "^$key=" "$file" > "$tmp" || true
  printf '%s=%s\n' "$key" "$val" >> "$tmp"
  cat "$tmp" > "$file"
  rm -f "$tmp"
}

if [ "$CHECK" = 1 ]; then
  step "Terminal relay status"
  [ -n "$(env_get RELAY_SECRET "$API_ENV")" ] && ok "RELAY_SECRET set in $API_ENV" || todo "RELAY_SECRET missing in $API_ENV"
  [ -n "$(env_get RELAY_SECRET "$RELAY_ENV")" ] && ok "RELAY_SECRET set in $RELAY_ENV" || todo "RELAY_SECRET missing in $RELAY_ENV"
  if [ -n "$(env_get RELAY_SECRET "$API_ENV")" ] && [ "$(env_get RELAY_SECRET "$API_ENV")" != "$(env_get RELAY_SECRET "$RELAY_ENV")" ]; then todo "the two RELAY_SECRET values differ"; fi
  [ -x "$BIN" ] && ok "relay binary at $BIN" || todo "relay binary missing ($BIN)"
  [ -f "$UNIT" ] && ok "systemd unit installed" || todo "systemd unit missing"
  [ -f "$NGINX_SNIPPET" ] && ok "nginx snippet at $NGINX_SNIPPET" || todo "nginx snippet missing"
  if command -v curl >/dev/null && curl -fsS -m 3 "http://127.0.0.1:$PORT/relay/healthz" >/dev/null 2>&1; then ok "relay answering on port $PORT"; else todo "relay not answering on port $PORT"; fi
  exit 0
fi

if [ -z "$ROOT" ] && [ "$(id -u)" != 0 ]; then
  echo "Run as root (sudo), or use --check to only look." >&2
  exit 1
fi
[ -f "$API_ENV" ] || { echo "API settings not found at $API_ENV (use --api-env)." >&2; exit 1; }

step "1. Shared secret"
mkdir -p "$ETC"
chmod 700 "$ETC"
SECRET="$(env_get RELAY_SECRET "$API_ENV")"
[ -n "$SECRET" ] || SECRET="$(env_get RELAY_SECRET "$RELAY_ENV")"
if [ "${#SECRET}" -lt 16 ]; then
  SECRET="$(openssl rand -hex 32 2>/dev/null || head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  ok "generated a new RELAY_SECRET (not shown)"
else
  ok "kept the existing RELAY_SECRET (not shown)"
fi
env_set RELAY_SECRET "$SECRET" "$API_ENV"
touch "$RELAY_ENV"
chmod 600 "$RELAY_ENV"
env_set RELAY_SECRET "$SECRET" "$RELAY_ENV"
env_set RELAY_LISTEN "$LISTEN" "$RELAY_ENV"
env_set RELAY_API_URL "$API_URL" "$RELAY_ENV"
env_set RELAY_HEALTH_URL "http://127.0.0.1:$PORT/relay/healthz" "$API_ENV"
if [ -n "$SSH_PORT" ]; then
  env_set RELAY_SSH_LISTEN ":$SSH_PORT" "$RELAY_ENV"
  env_set SSH_GATEWAY_ENABLED true "$API_ENV"
  env_set SSH_GATEWAY_PORT "$SSH_PORT" "$API_ENV"
  ok "SSH gateway on port $SSH_PORT (open it in the firewall)"
fi
unset SECRET
ok "wrote $RELAY_ENV (0600) and updated $API_ENV"

step "2. Relay binary"
if [ "$SKIP_BUILD" = 1 ]; then
  todo "skipped (--no-build)"
else
  case "$(uname -m)" in x86_64|amd64) ARCH=amd64 ;; aarch64|arm64) ARCH=arm64 ;; *) echo "Unsupported CPU $(uname -m)" >&2; exit 1 ;; esac
  SRC="$REPO/agent/dist/dockermgr-relay-linux-$ARCH"
  if command -v go >/dev/null; then
    (cd "$REPO/agent" && CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -X main.version=$(cat "$REPO/VERSION")" -o "dist/dockermgr-relay-linux-$ARCH" ./cmd/relay)
    ok "built with $(go version | awk '{print $3}')"
  elif [ -f "$SRC" ]; then
    ok "using the prebuilt $SRC"
  else
    echo "No Go toolchain (1.26+) and no prebuilt $SRC. Install Go or copy the binary there." >&2
    exit 1
  fi
  mkdir -p "$(dirname "$BIN")"
  install -m 755 "$SRC" "$BIN"
  ok "installed $BIN"
fi

step "3. Service"
mkdir -p "$(dirname "$UNIT")"
cp "$REPO/docs/deploy/dockermgr-relay.service" "$UNIT"
if [ "$SKIP_SYSTEMD" = 1 ] || ! command -v systemctl >/dev/null; then
  todo "unit written to $UNIT; start it with: systemctl enable --now dockermgr-relay"
else
  systemctl daemon-reload
  systemctl enable dockermgr-relay >/dev/null 2>&1
  systemctl restart dockermgr-relay
  ok "dockermgr-relay enabled and (re)started"
fi

step "4. nginx"
mkdir -p "$(dirname "$NGINX_SNIPPET")" "$(dirname "$NGINX_HTTP")"
cat > "$NGINX_HTTP" <<EOF
# Written by scripts/setup-relay.sh (http context).
upstream dmp_relay { server 127.0.0.1:$PORT; }
map \$http_upgrade \$dmp_relay_upgrade { default upgrade; '' close; }
EOF
cat > "$NGINX_SNIPPET" <<'EOF'
# Written by scripts/setup-relay.sh: include inside the panel's server { } block.
location /relay/terminal/ {
    proxy_pass http://dmp_relay;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $dmp_relay_upgrade;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 3600s;
}
location /internal/ { return 404; }
EOF
ok "wrote $NGINX_HTTP and $NGINX_SNIPPET"
SITES="$ROOT/etc/nginx/sites-enabled $ROOT/etc/nginx/conf.d"
# shellcheck disable=SC2086
if grep -rqs "snippets/dockermgr-relay.conf" $SITES; then
  ok "the panel's server block already includes it"
else
  todo "add this line inside the panel's server { } block, then reload nginx:"
  echo "        include snippets/dockermgr-relay.conf;"
fi
if [ "$SKIP_NGINX" = 0 ] && command -v nginx >/dev/null; then
  if nginx -t >/dev/null 2>&1; then systemctl reload nginx 2>/dev/null && ok "nginx reloaded" || todo "reload nginx yourself"; else todo "nginx -t reports a problem; fix it, then reload"; fi
fi

step "5. API settings"
if [ -z "$ROOT" ] && [ -f "$REPO/api/artisan" ]; then
  owner="$(stat -c %U "$REPO/api/storage" 2>/dev/null || echo root)"
  if (cd "$REPO/api" && sudo -u "$owner" php artisan config:cache >/dev/null 2>&1); then ok "config cache rebuilt"; else todo "run: php artisan config:cache (as the web user)"; fi
else
  todo "run: php artisan config:cache"
fi

step "6. Check"
if [ -z "$ROOT" ] && command -v curl >/dev/null; then
  sleep 1
  if curl -fsS -m 3 "http://127.0.0.1:$PORT/relay/healthz" >/dev/null; then ok "relay is answering on port $PORT"; else todo "relay is not answering yet: journalctl -u dockermgr-relay -n 50"; fi
fi
printf '\nDone. Open a container terminal in the panel to try it.\n'
