# Working on WorkSuite CloudPanel

## Versioning and changelog (every change)

Every pull request that changes behaviour bumps the release and records it:

1. Add entries under `## [Unreleased]` in `CHANGELOG.md` (Keep a Changelog
   headings: Added, Changed, Fixed, Security, Removed). Say what changed for
   the person using the panel, and link the PR number.
2. Run `scripts/release.sh patch|minor|major`:
   - `patch` — fixes only,
   - `minor` — new features, backwards compatible,
   - `major` — breaking changes (API, agent protocol, configuration).
   It updates `VERSION` and `web/package.json` and dates the section.
3. `VERSION` is the single source of the release number. The API reports it
   (`config('platform.version')`, `GET /api/v1/auth/config`) and the agent
   build reads it (`agent/Makefile`). `api/tests/Feature/VersionTest.php`
   fails if they disagree or the changelog has no section for the version.

Docs-only and test-only changes may skip the bump but still get a changelog
line under Unreleased if they matter to a reader.
