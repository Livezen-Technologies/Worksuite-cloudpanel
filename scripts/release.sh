#!/usr/bin/env bash
# Bumps the release everywhere and dates the changelog.
#   scripts/release.sh patch|minor|major   (or an exact x.y.z)
# Updates VERSION and web/package.json, and turns "## [Unreleased]" in
# CHANGELOG.md into "## [x.y.z] — YYYY-MM-DD" with a fresh empty Unreleased
# section above it. Write the changelog entries under Unreleased first.
set -euo pipefail
cd "$(dirname "$0")/.."

cur=$(tr -d '[:space:]' < VERSION)
IFS=. read -r ma mi pa <<< "$cur"
case "${1:-}" in
  major) next="$((ma + 1)).0.0" ;;
  minor) next="$ma.$((mi + 1)).0" ;;
  patch) next="$ma.$mi.$((pa + 1))" ;;
  [0-9]*.[0-9]*.[0-9]*) next="$1" ;;
  *) echo "usage: $0 patch|minor|major|x.y.z (now $cur)" >&2; exit 2 ;;
esac

grep -q '^## \[Unreleased\]' CHANGELOG.md || { echo "CHANGELOG.md has no '## [Unreleased]' section" >&2; exit 1; }
body=$(awk '/^## \[Unreleased\]/{f=1;next} /^## \[/{f=0} f' CHANGELOG.md | grep -v '^[[:space:]]*$' || true)
[ -n "$body" ] || { echo "Nothing under [Unreleased]: add the changelog entries first" >&2; exit 1; }

today=$(date -u +%F)
echo "$next" > VERSION
python3 - "$next" "$today" <<'PY'
import json, re, sys
v, day = sys.argv[1], sys.argv[2]
p = 'web/package.json'; s = open(p).read()
s = re.sub(r'"version": "[^"]+"', f'"version": "{v}"', s, count=1); open(p, 'w').write(s)
p = 'CHANGELOG.md'; s = open(p).read()
s = s.replace('## [Unreleased]', f'## [Unreleased]\n\n## [{v}] — {day}', 1); open(p, 'w').write(s)
PY
echo "Released $cur -> $next ($today)"
