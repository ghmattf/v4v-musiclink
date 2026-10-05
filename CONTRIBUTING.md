# Contributing / Build instructions

## PHP runtime (required for linting and tests)

**There is no system PHP dependency in this project, and you should not assume `php` is on `PATH`.** A portable, self-contained static PHP CLI lives in `php/`:

```bash
./php/php -v                                # check it works
./php/php -l v4v-musiclink/v4v-musiclink.php
./php/install.sh                            # install or repair it (idempotent)
```

- Always invoke it as **`./php/php`** (relative to the project root) — a bare `php` will usually fail with *command not found*.
- The binary (`php/php`) and its tarball (`php/*.tar.gz`) are **gitignored download artifacts**, so a fresh clone starts without them. Run **`./php/install.sh`** first; it extracts from the local tarball if present, otherwise downloads the pinned version (PHP 8.5.8) for your OS/arch, and no-ops if PHP is already working.
- Nothing else needs installing: no Composer, no `vendor/`, no Node. The plugins are plain PHP files loaded straight by WordPress.

## Regression tests

The suite is a set of standalone harnesses in `tmp/` (gitignored local scratch until the tracked `tests/` directory lands - tracked in `OUTSTANDING.md` as O4). Run them all from the project root after any change, and always before a release:

```bash
for f in tmp/test_*.php tmp/*_test.php; do ./php/php "$f"; done
```

- Each harness prints `N passed, 0 failed` and exits non-zero on any failure; expect `0 failed` from every file.
- `tmp/fountain_live_test.php`, `tmp/fountain_candidate_test.php` and `tmp/fountain_case_test.php` hit live endpoints and can flake - re-run a failing one once before concluding it is a real regression.
- `./php/php tmp/e2e/gen_page.php` is an extra smoke check: it regenerates `tmp/e2e/*.html` and verifies the rendered page and script stack.

## Version control

This project is tracked with git. All changes must be committed; releases are marked with git tags. The running list of open items and findings is `OUTSTANDING.md` at the repository root: anything discussed but not implemented the same turn goes there that same turn with enough context to continue, and leaves the list only once it is implemented **and** released (or explicitly declined).

## Version numbers

- Each build must be incremented by `0.0.1` (patch bump) and the version number updated in the PHP file.
- The version appears in **two places** in each plugin file — keep both in sync:
  1. The plugin header: ` * Version: X.Y.Z`
  2. The script cache-buster: `wp_register_script( …, 'X.Y.Z', true );`
- The two plugin versions are versioned independently:

  | Plugin | File |
  |---|---|
  | V4V Music Link Resolver | `v4v-musiclink/v4v-musiclink.php` |
  | V4V Music Link Resolver Pro | `v4v-musiclink-pro/v4v-musiclink-pro.php` |

### Automated: `./bump-version.sh`

```bash
./bump-version.sh          # bump both plugins, update versions, refresh backup copies, commit them
./bump-version.sh standard # bump the standard plugin only
./bump-version.sh pro      # bump the Pro plugin only
```

The script increments the patch version in both places, refreshes the archive copy (below), then commits **only the plugin file(s) it just changed** with a `Bump Resolver x.y.z` / `Bump Pro x.y.z` subject — anything else you have staged or edited is left alone, and the archive copies are gitignored so they never ride along. If the directory is not a git work tree it skips the commit and says so.

## Archive copies

Once a PHP file has been updated, a copy must be placed in the plugin's `archive/` subdirectory, with the version number appended to the filename. Example: version 1.22.1 of `v4v-musiclink.php` must have a copy named `v4v-musiclink-v1.22.1.php` created in the `v4v-musiclink/archive/` subdirectory.

```
v4v-musiclink/archive/v4v-musiclink-v1.22.1.php
v4v-musiclink-pro/archive/v4v-musiclink-pro-v1.20.4.php
```

`bump-version.sh` creates these copies automatically.

> **Note:** git tags are the authoritative record of any past release; the `archive/` copies are kept as a plain-file backup per the original build instructions. They are **out of scope for review** — exclude `archive/` from linting, searches, audits, and reports (agents: see `AGENTS.md`, which OpenCode enforces via `opencode.jsonc`). Retrieve any past version with:
> ```bash
> git show v1.22.1:v4v-musiclink/v4v-musiclink.php
> ```

## Release checklist

1. Make and test your changes.
2. Lint (uses the project's portable PHP — see [PHP runtime](#php-runtime-required-for-linting-and-tests)):

   ```bash
   ./php/install.sh   # only if ./php/php is missing
   ./php/php -l v4v-musiclink/v4v-musiclink.php && ./php/php -l v4v-musiclink-pro/v4v-musiclink-pro.php
   ```
3. Run the regression harnesses - see [Regression tests](#regression-tests)
   (all `tmp/test_*.php` / `tmp/*_test.php` files must report `0 failed`).
4. Bump version(s): `./bump-version.sh` (or `standard` / `pro`) — this commits the
   bumped plugin file(s) itself, subject `Bump Resolver x.y.z` / `Bump Pro x.y.z`.
5. Add a `CHANGELOG.md` entry for the new version(s).
6. Commit the docs: `git add CHANGELOG.md && git commit -m "Resolver x.y.z: …"` —
   plus `OUTSTANDING.md` if any statuses changed. Never `git add -A` (it would
   sweep in unrelated edits and local scratch).
7. Tag each released plugin version: `git tag -a vX.Y.Z -m "…"` — local only,
   no remote is configured.

## Naming conventions (why they matter)

- The two plugins must be able to run on the same WordPress instance without conflict:
  - Separate classes, shortcodes (`[musiclink]` vs `[musiclink_pro]`), option names (`v4v_musiclink_*` vs `v4v_musiclink_pro_*`), asset handles, cache keys, and admin page slugs.
  - Pro front-end CSS classes/IDs carry a `-pro` infix (`v4v-musiclink-pro`, `v4v-musiclink-pro-fountain-modal`) so both shortcodes can appear on one page without selector or duplicate-ID collisions.
  - **Form field `name` attributes must match their `register_setting()` option names** — a mismatch is silently dropped by WordPress on save (this caused a real bug in v1.20.3).
