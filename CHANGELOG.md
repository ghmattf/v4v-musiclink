# Changelog

All notable changes to the V4V MusicLink plugins are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versions follow [Semantic Versioning](https://semver.org/) and are tracked independently per plugin.

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
