# Contributing / Build instructions

## PHP runtime (required for linting and tests)

**There is no system PHP dependency in this project, and you should not assume `php` is on `PATH`.** A portable, self-contained static PHP CLI lives in `php/`:

```bash
./php/php -v                                # check it works
./php/php -l wp_plugin/v4v_musiclinker.php
./php/install.sh                            # install or repair it (idempotent)
```

- Always invoke it as **`./php/php`** (relative to the project root) — a bare `php` will usually fail with *command not found*.
- The binary (`php/php`) and its tarball (`php/*.tar.gz`) are **gitignored download artifacts**, so a fresh clone starts without them. Run **`./php/install.sh`** first; it extracts from the local tarball if present, otherwise downloads the pinned version (PHP 8.5.8) for your OS/arch, and no-ops if PHP is already working.
- Nothing else needs installing: no Composer, no `vendor/`, no Node. The plugin is a plain PHP file loaded straight by WordPress.

## Regression tests

The suite is a set of standalone harnesses in the tracked `tests/` directory (plus `tests/wpstub/` WordPress stubs). Run them all from the project root after any change, and always before a release:

```bash
for f in tests/test_*.php tests/*_test.php; do ./php/php "$f"; done
```

- Each harness prints `N passed, 0 failed` and exits non-zero on any failure; expect `0 failed` from every file.
- All harnesses are fully offline; nothing in this suite hits the network.
- Anything under `tmp/` (scratch) is gitignored and not part of the suite.
- The Pro plugin's harnesses (including the live Fountain network probes) live in the sibling repository `ghmattf/v4v-musiclink-pro`.

## Version control

This project is tracked with git and hosted at [github.com/ghmattf/v4v-musiclink](https://github.com/ghmattf/v4v-musiclink). All changes must be committed; releases are marked with git tags and pushed with each release. The running list of open items and findings is `OUTSTANDING.md` at the repository root: anything discussed but not implemented the same turn goes there that same turn with enough context to continue, and leaves the list only once it is implemented **and** released (or explicitly declined).

## Version numbers

- Each build must be incremented by `0.0.1` (patch bump) and the version number updated in the PHP file.
- The version appears in **two places** in the plugin file — keep both in sync:
  1. The plugin header: ` * Version: X.Y.Z`
  2. The script cache-buster: `wp_register_script( …, 'X.Y.Z', true );`
  The third place, `wp_plugin/readme.txt`'s `Stable tag: X.Y.Z`, is NOT touched
  by `bump-version.sh` — update it by hand for every release (wp.org rejects a
  Stable tag that does not match the released version).
- Plugin: V4V MusicLinker — `wp_plugin/v4v_musiclinker.php` (renamed from
  `v4v-musiclink.php` at 1.0.0; tags before `v1.0.0` still use the old path).
  The Pro plugin is versioned independently in the sibling repository
  `ghmattf/v4v-musiclink-pro`.

### Automated: `./bump-version.sh`

```bash
./bump-version.sh   # bump the plugin, update both version strings, refresh the backup copy, commit it
```

The script increments the patch version in both places, refreshes the archive copy (below), then commits **only the plugin file it just changed** with a `Bump MusicLinker x.y.z` subject — anything else you have staged or edited is left alone, and the archive copy is gitignored so it never rides along. If the directory is not a git work tree it skips the commit and says so.

## Archive copies

Once a PHP file has been updated, a copy must be placed in the plugin's `archive/` subdirectory, with the version number appended to the filename. Example: version 1.0.1 of `v4v_musiclinker.php` must have a copy named `v4v_musiclinker-v1.0.1.php` created in the `wp_plugin/archive/` subdirectory (pre-1.0.0 backups keep the old `v4v-musiclink-v*` naming). `bump-version.sh` creates these copies automatically.

> **Note:** git tags are the authoritative record of any past release; the `archive/` copies are kept as a plain-file backup per the original build instructions. They are **out of scope for review** — exclude `archive/` from linting, searches, audits, and reports (agents: see `AGENTS.md`, which OpenCode enforces via `opencode.jsonc`). Retrieve any past version with:
> ```bash
> git show v1.0.1:wp_plugin/v4v_musiclinker.php
> ```

## Release checklist

1. Make and test your changes.
2. Lint (uses the project's portable PHP — see [PHP runtime](#php-runtime-required-for-linting-and-tests)):

   ```bash
   ./php/install.sh   # only if ./php/php is missing
   ./php/php -l wp_plugin/v4v_musiclinker.php
   ```
3. Run the regression harnesses — see [Regression tests](#regression-tests) (all `tests/test_*.php` / `tests/*_test.php` files must report `0 failed`).
4. Bump the version: `./bump-version.sh` — this commits the bumped plugin file itself, subject `Bump MusicLinker x.y.z`.
5. Add a `CHANGELOG.md` entry for the new version.
6. Commit the docs: `git add CHANGELOG.md && git commit -m "MusicLinker x.y.z: …"` — plus `OUTSTANDING.md` if any statuses changed. Never `git add -A` (it would sweep in unrelated edits and local scratch).
7. Tag the release: `git tag -a vX.Y.Z -m "V4V MusicLinker x.y.z"` — then push with `git push && git push origin vX.Y.Z`.

## Naming conventions (why they matter)

The Pro plugin (`ghmattf/v4v-musiclink-pro`) may be installed on the same WordPress instance as this plugin:

- Separate classes, shortcodes (`[v4v_musiclinker]` vs `[musiclink_pro]`; this plugin also keeps `[musiclink]` as a deprecated alias for pre-1.0.0 content), option names (`v4v_musiclink_*` vs `v4v_musiclink_pro_*`), asset handles, cache keys, and admin page slugs.
- Pro front-end CSS classes/IDs carry a `-pro` infix (`v4v-musiclink-pro`, `v4v-musiclink-pro-fountain-modal`) so both shortcodes can appear on one page without selector or duplicate-ID collisions.
- This plugin's REST namespace (`v4v-musiclink/v1`) must stay distinct from anything Pro adds later.
