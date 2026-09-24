# Project instructions

## Project scope

The project consists of:

- `v4v-musiclink/v4v-musiclink.php` — standard plugin (current release)
- `v4v-musiclink-pro/v4v-musiclink-pro.php` — Pro plugin (current release)
- `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`
- `bump-version.sh`, `php/install.sh`

## Out of scope: `archive/` directories

Every `archive/` directory (e.g. `v4v-musiclink/archive/`, `v4v-musiclink-pro/archive/`)
holds historical backup copies written automatically by `bump-version.sh`. They exist
for backup/history only and are **not** part of the project.

- Never read, review, audit, lint, diff, summarize, or otherwise analyze files under
  any `archive/` directory.
- Filter them out of glob/grep searches and directory listings before reporting, and
  do not count their contents toward findings (their contents are deliberately stale
  and often contain already-patched issues).
- Never edit or delete them; `bump-version.sh` maintains them.
- `opencode.jsonc` additionally hard-denies `read`/`edit` on `*/archive/*` and shell
  commands referencing archives. A permission error there is expected behaviour, not
  a bug — do not work around it (no `cat`/`sed`/`cp` via shell).

## Also not part of the project

- `tmp/` — gitignored local scratch (test harnesses, probes, downloaded archives).
- `php/php` and `php/*.tar.gz` — gitignored downloaded toolchain; `php/install.sh`
  is the tracked source of truth.
- `.env*` — never read or report on these.
