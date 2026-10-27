#!/usr/bin/env bash
# Builds e2e/postgres:16 FROM scratch out of the host's PostgreSQL 16 binaries (Docker Hub
# rate-limits pulls). Needs /usr/lib/postgresql/16 on the host. Usage: e2e/pg/build.sh
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
PGROOT=/usr/lib/postgresql/16
[ -x "$PGROOT/bin/postgres" ] || { echo "host PostgreSQL 16 not found at $PGROOT"; exit 2; }
CTX=$(mktemp -d); trap 'rm -rf "$CTX"' EXIT
mkdir -p "$CTX/rootfs"/{tmp,etc,usr/share} "$CTX/owned"/{var/run/postgresql,var/lib/postgresql/data}
cp -a "$PGROOT" --parents "$CTX/rootfs/" 2>/dev/null || { mkdir -p "$CTX/rootfs/usr/lib/postgresql"; cp -a "$PGROOT" "$CTX/rootfs/usr/lib/postgresql/"; }
cp -a /usr/share/postgresql "$CTX/rootfs/usr/share/"
# initdb popen()s through /bin/sh.
mkdir -p "$CTX/rootfs/bin"; cp -L "$(readlink -f /bin/sh)" "$CTX/rootfs/bin/sh"
for f in "$PGROOT"/bin/* "$PGROOT"/lib/*.so /bin/sh; do
  ldd "$f" 2>/dev/null | awk '/=>/ {print $3} /^\s*\/lib/ {print $1}' ; done | sort -u | while read -r lib; do
  [ -f "$lib" ] && cp -L --parents "$lib" "$CTX/rootfs/"; done
# The dynamic loader itself.
for ld in /lib64/ld-linux-x86-64.so.2 /lib/ld-linux-aarch64.so.1; do [ -e "$ld" ] && cp -L --parents "$ld" "$CTX/rootfs/"; done
printf 'root:x:0:0:root:/root:/nonexistent\npostgres:x:999:999:postgres:/var/lib/postgresql:/nonexistent\n' > "$CTX/rootfs/etc/passwd"
printf 'root:x:0:\npostgres:x:999:\n' > "$CTX/rootfs/etc/group"
chmod 1777 "$CTX/rootfs/tmp"; chmod 700 "$CTX/owned/var/lib/postgresql/data"
( cd "$HERE" && CGO_ENABLED=0 go build -o "$CTX/rootfs/pg-entry" . )
cat > "$CTX/Dockerfile" <<'DOCKER'
FROM scratch
COPY rootfs/ /
COPY --chown=999:999 owned/ /
ENV PATH=/usr/lib/postgresql/16/bin PGDATA=/var/lib/postgresql/data LANG=C
USER 999:999
EXPOSE 5432
ENTRYPOINT ["/pg-entry"]
DOCKER
docker build -q -t e2e/postgres:16 "$CTX" >/dev/null
echo "built e2e/postgres:16"
