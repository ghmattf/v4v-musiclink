# Contributing / Build instructions

## Version control

This project is tracked with git. All changes must be committed; releases are marked with git tags.

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
./bump-version.sh          # bump both plugins, update versions, refresh archive copies
./bump-version.sh standard # bump the standard plugin only
./bump-version.sh pro      # bump the Pro plugin only
```

The script increments the patch version in both places, then refreshes the archive copy (below).

## Archive copies

Once a PHP file has been updated, a copy must be placed in the plugin's `archive/` subdirectory, with the version number appended to the filename. Example: version 1.22.1 of `v4v-musiclink.php` must have a copy named `v4v-musiclink-v1.22.1.php` created in the `v4v-musiclink/archive/` subdirectory.

```
v4v-musiclink/archive/v4v-musiclink-v1.22.1.php
v4v-musiclink-pro/archive/v4v-musiclink-pro-v1.20.4.php
```

`bump-version.sh` creates these copies automatically.

> **Note:** git tags are the authoritative record of any past release; the `archive/` copies are kept as a plain-file backup per the original build instructions. Retrieve any past version with:
> ```bash
> git show v1.22.1:v4v-musiclink/v4v-musiclink.php
> ```

## Release checklist

1. Make and test your changes.
2. Lint: `php -l v4v-musiclink/v4v-musiclink.php && php -l v4v-musiclink-pro/v4v-musiclink-pro.php`
3. Bump version(s): `./bump-version.sh` (or `standard` / `pro`).
4. Add a `CHANGELOG.md` entry for the new version(s).
5. Commit: `git add -A && git commit -m "Release vX.Y.Z"`
6. Tag each released plugin version: `git tag -a vX.Y.Z -m "…"` and `git push --tags`.

## Naming conventions (why they matter)

- The two plugins must be able to run on the same WordPress instance without conflict:
  - Separate classes, shortcodes (`[musiclink]` vs `[musiclink_pro]`), option names (`v4v_musiclink_*` vs `v4v_musiclink_pro_*`), asset handles, cache keys, and admin page slugs.
  - Pro front-end CSS classes/IDs carry a `-pro` infix (`v4v-musiclink-pro`, `v4v-musiclink-pro-fountain-modal`) so both shortcodes can appear on one page without selector or duplicate-ID collisions.
  - **Form field `name` attributes must match their `register_setting()` option names** — a mismatch is silently dropped by WordPress on save (this caused a real bug in v1.20.3).
