# Project instructions

## Project scope

The project consists of:

- `v4v-musiclink/v4v-musiclink.php` — standard plugin (current release)
- `v4v-musiclink-pro/v4v-musiclink-pro.php` — Pro plugin (current release)
- `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `OUTSTANDING.md`
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

## Outstanding items (running list)

`OUTSTANDING.md` (repo root) is the single running list of open items and findings
for this project. Maintain it as follows:

- Any finding, observation, request, or idea that is discussed but **not implemented
  in the same turn** must be added to `OUTSTANDING.md` before that turn ends, with
  enough context to continue without re-deriving it: what, where (file + symbol),
  why, a plan sketch, and a status.
- Status vocabulary: `Planned` (user picked it — implement only on request),
  `On hold` (user deferred), `Open` (identified, awaiting triage).
- Remove an item from the list once it is **implemented and released**
  (`CHANGELOG.md` + git history carry it from then on) or **explicitly declined**.
  Never leave decided items on the list, and never implement an item the user has
  not picked.
- Update the item's status in the same turn as any work on it, so the list always
  matches reality.
- The "Closed decisions" section of `OUTSTANDING.md` may hold one-line context for
  declined/concluded items so they are not re-proposed; it is not a work list.

## Release flow

Applies to code releases only (never for docs-only changes):

1. Lint both plugin files: `./php/php -l <file>` (use the project PHP from
   `php/install.sh`; no system PHP).
2. Run the regression harnesses: `./php/php tmp/<harness>.php` for each
   `tmp/test_*.php` / `tmp/*_test.php` (14 files; `fountain_live_test`,
   `fountain_candidate_test` and `fountain_case_test` are live-network and
   can flake — re-run a flaky one once before concluding). Until
   `OUTSTANDING.md` item O4 lands, they live in gitignored `tmp/`.
3. `./bump-version.sh standard|pro|both` — bumps the plugin header and the script
   cache-buster, refreshes the backup copies under `archive/` (gitignored, never
   committed), and **commits the bumped plugin file(s) itself** — only those
   paths, with subject `Bump Resolver x.y.z` / `Bump Pro x.y.z` /
   `Bump Resolver a.b.c / Pro d.e.f`.
4. Add `CHANGELOG.md` entries at the top, Keep-a-Changelog style, dated today:
   `## Resolver (v4v-musiclink) — [x.y.z] - YYYY-MM-DD` /
   `## Resolver Pro (v4v-musiclink-pro) — [x.y.z] - YYYY-MM-DD`.
5. Commit `CHANGELOG.md` (plus `OUTSTANDING.md` if statuses changed): subject
   `Resolver x.y.z: …` / `Pro x.y.z: …`, or a combined
   `Resolver a.b.c / Pro d.e.f: …`. Step 3 already committed the plugin files;
   `tmp/` and `archive/` stay uncommitted. Never `git add -A` here.
6. Annotated, `v`-prefixed tags: `git tag -a vx.y.z -m "Resolver x.y.z"` (or
   `"Pro x.y.z"`). Nothing is pushed (no remote configured).
7. Update `OUTSTANDING.md` statuses in the same turn; remove any item just shipped.
