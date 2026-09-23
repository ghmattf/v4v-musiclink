# Changelog

All notable changes to the V4V MusicLink plugins are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versions follow [Semantic Versioning](https://semver.org/) and are tracked independently per plugin.

## Resolver Pro (v4v-musiclink-pro) — [1.20.5] - 2026-09-23

### Added
- **New admin setting "Release header"** (Settings → V4V MusicLink Pro): site-wide checkbox controlling whether the release header (title, artist, artwork) shows above the music app buttons. Default: shown (unchanged behaviour). Option name `v4v_musiclink_pro_show_header`, isolated from the standard plugin's option.
- **New optional shortcode attribute `header="true|false"`**: overrides the admin setting per placement, e.g. `[musiclink_pro album="…" header="false"]` hides the header for that instance. Accepted values: `true`/`false`, `1`/`0`, `yes`/`no`, `on`/`off` (case-insensitive); unrecognized or absent values fall back to the admin setting.
- Plugin docblock documents the new attribute.

## Resolver (v4v-musiclink) — [1.22.2] - 2026-09-23

### Added
- **New admin setting "Release header"** (Settings → V4V MusicLink): site-wide checkbox controlling whether the release header (title, artist, artwork) shows above the music app buttons. Default: shown (unchanged behaviour).
- **New optional shortcode attribute `header="true|false"`**: overrides the admin setting per placement, e.g. `[musiclink album="…" header="false"]` hides the header for that instance. Accepted values: `true`/`false`, `1`/`0`, `yes`/`no`, `on`/`off` (case-insensitive); unrecognized or absent values fall back to the admin setting.
- The "Missing parameter(s)" usage message now includes a `header` example.

## Resolver (v4v-musiclink) — [1.22.1] - 2026-09-23

### Changed
- Removed the temporary Fountain `debug_log()` helper and all its call sites (no more `wp-content/v4v-musiclink-debug.log` writes).
- Removed a stale orphaned docblock referencing a search-box UI that no longer exists in this version.

## Resolver Pro (v4v-musiclink-pro) — [1.20.4] - 2026-09-23

### Fixed
- **Settings now save correctly**: three form fields posted the standard plugin's option names, which were not whitelisted in the Pro option group, so WordPress silently dropped them on save:
  - Per-app enable/icon fields now post `v4v_musiclink_pro_apps[…]`
  - Fountain next-action field now posts `v4v_musiclink_pro_fountain_next_action`
  - Social preview mode radios now post `v4v_musiclink_pro_social_preview_mode`
- `shortcode_atts()` filter name corrected from `'musiclink'` to `'musiclink_pro'`.

### Changed
- Namespaced all front-end CSS classes and the modal element ID with a `-pro` infix (`v4v-musiclink` → `v4v-musiclink-pro`, `v4v-musiclink-fountain-modal` → `v4v-musiclink-pro-fountain-modal`), so both plugins' shortcodes can appear on the same page without CSS selector or duplicate-ID collisions. **Breaks any custom CSS targeting Pro front-end classes.**
- Removed the temporary Fountain `debug_log()` helper and all its call sites.
- Moved a misplaced docblock onto `render_log_tab_content()`; updated the header comment to reference the View Log / Reports tabs.

## Earlier releases

Pre-changelog releases: Resolver 1.22.0 and earlier, Pro 1.20.3 and earlier. See git tags and the `archive/` subdirectories for those versions.
