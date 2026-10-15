# Project instructions

## Project scope

The project consists of:

- `wp_plugin/v4v_musiclinker.php` — the standard WordPress plugin (current release;
  renamed from `v4v-musiclink.php` at 1.0.0)
- `tests/` — tracked regression suite (`test_*.php` / `*_test.php` harnesses,
  `wpstub/`)
- `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `OUTSTANDING.md`
- `bump-version.sh`, `php/install.sh`

The **Pro** plugin lives in the sibling repository
[`ghmattf/v4v-musiclink-pro`](https://github.com/ghmattf/v4v-musiclink-pro) (split
2026-10-11; before that both plugins shared one repository). The two plugins
must be able to run on the same WordPress instance without conflict — separate
class names, shortcodes (`[v4v_musiclinker]` vs `[musiclink_pro]`; standard also
keeps `[musiclink]` as a deprecated alias for pre-1.0.0 content), option names,
cache tables, asset handles, and REST namespaces. Keep that in mind when
naming anything new.

## Out of scope: `archive/` directories

The `wp_plugin/archive/` directory holds historical backup copies
written automatically by `bump-version.sh`. It exists for backup/history only
and is **not** part of the project.

- Never read, review, audit, lint, diff, summarize, or otherwise analyze files
  under `archive/`.
- Filter them out of glob/grep searches and directory listings before reporting,
  and do not count their contents toward findings (their contents are
  deliberately stale and often contain already-patched issues).
- Never edit or delete them; `bump-version.sh` maintains them.
- `opencode.jsonc` additionally hard-denies `read`/`edit` on `*/archive/*` and
  shell commands referencing archives. A permission error there is expected
  behaviour, not a bug — do not work around it (no `cat`/`sed`/`cp` via shell).

## Also not part of the project

- `tmp/` — gitignored local scratch (live probes, captured HTML/logs,
  downloaded archives); the harness suite lives in tracked `tests/`.
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
  Never leave decided items on the list, and never implement an item the user
  has not picked.
- Update the item's status in the same turn as any work on it, so the list always
  matches reality.
- The "Closed decisions" section of `OUTSTANDING.md` may hold one-line context for
  declined/concluded items so they are not re-proposed; it is not a work list.
- Items that concern **both** plugins are tracked in BOTH repositories (each with
  a cross-reference); implement the standard half here only.

## Release flow

Applies to code releases only (never for docs-only changes):

1. Lint the plugin file: `./php/php -l wp_plugin/v4v_musiclinker.php` (use the
   project PHP from `php/install.sh`; no system PHP).
2. Run the regression harnesses: `./php/php tests/<harness>.php` for each
   `tests/test_*.php` / `tests/*_test.php` (8 files).
3. `./bump-version.sh` — bumps the plugin header and the script cache-buster,
   refreshes the backup copies under `wp_plugin/archive/` (gitignored,
   never committed), and **commits the bumped plugin file itself** — only that
   path, with subject `Bump MusicLinker x.y.z`.
4. Add a `CHANGELOG.md` entry at the top, Keep-a-Changelog style, dated today:
   `## V4V MusicLinker (v4v-musiclinker) — [x.y.z] - YYYY-MM-DD`.
5. Commit `CHANGELOG.md` (plus `OUTSTANDING.md` if statuses changed): subject
   `MusicLinker x.y.z: …`. Step 3 already committed the plugin file; `tmp/` and
   `archive/` stay uncommitted. Never `git add -A` here.
6. Annotated, `v`-prefixed tags: `git tag -a vx.y.z -m "V4V MusicLinker x.y.z"`. Then
   push to GitHub: `git push && git push origin vx.y.z`.
7. Update `OUTSTANDING.md` statuses in the same turn; remove any item just shipped.
