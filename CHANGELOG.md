# Changelog

All notable changes to the V4V MusicLink plugins are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versions follow [Semantic Versioning](https://semver.org/) and are tracked independently per plugin.

## Resolver Pro (v4v-musiclink-pro) — [1.20.12] - 2026-09-23

### Added
- **Search results are paginated instead of stopping at 8**: the search box fetched Music Index's default batch, resolved the first 8 hits (`SEARCH_RESULT_LIMIT`) and discarded the rest, so only the top 8 were ever reachable - `q=love` alone has 885 unique results. Results now come 8 per page behind a «‹ Previous · Page N · Results X–Y · Next ›» control (the control hides when there is only one page). Ordering is exactly Music Index's relevance order - nothing is re-sorted, grouped or type-filtered.

### Changed
- **Search hits are accumulated per query behind a transient, and deeper pages chain Music Index's cursor**: `/v1/search` caps `limit` at 100 and exposes no offset or total (`page`/`offset`/`per_page` are silently ignored), so results beyond the first batch are reached by chaining opaque `pagination.cursor` values. The accumulated list only ever *appends*, so a hit keeps a stable index for the cache's lifetime - page boundaries never shift, navigating backwards costs no HTTP call, and detail lookups stay cached. Each request chains at most `SEARCH_MAX_BATCHES` (3) batches, and the cursor is kept so the next request continues from where it stopped. Because the endpoint never reports a total, the UI shows the page and its item range rather than "X of Y".
- **Duplicates are now removed across batches**: Music Index repeats hits (`q=love` returns 1358 rows for 885 unique; 56 rows for 30 unique on `q=Kurtisdrums`), so de-duplication is applied while accumulating - otherwise the same release would appear on two different pages. Keys are identity-based (`entity_type|entity_id|parent album guid`), never positional, so they can't disturb page boundaries. A track hit with no parent `feed_guid` is unresolvable and is dropped at the same point.
- **A no-match query can no longer chain forever**: Music Index returns **zero rows with `has_more: true`** for a query that matches nothing (verified live), so an empty batch now terminates the chain regardless of what the response claims; a cursor that comes back unchanged does the same. Cached 1 hour under `mi_search_hits_v2_`.

## Resolver Pro (v4v-musiclink-pro) — [1.20.11] - 2026-09-23

### Fixed
- **Fountain.fm button 404'd whenever Fountain had filed the release as a podcast**: the URL path was hardcoded to `/track/` or `/album/` purely from whether the shortcode carried a `track=` guid, but Fountain serves the *same* opaque id under four different prefixes (`/track/`, `/album/`, `/episode/`, `/show/`) according to how **it** has categorised the release - so any release Fountain ingested as a podcast rendered its client-side "Oops! That page can't be found" screen (always HTTP 200, so the status code cannot detect it). Fountain's own search response already carries the answer as a `_type` field on every hit, and that field was being parsed then thrown away. The extractor now returns the type alongside the id, and a new `fountain_path_segment()` maps `TRACK→track`, `ALBUM→album`, `EPISODE→episode`, `SHOW→show`, falling back to the old guid-based guess only when Fountain omits a recognised type. No extra HTTP request is made. Example: `?album=69c634ad…&track=016b464c…` now emits `/episode/3xnUxg38mNApJVgv4hXa` and `/show/trSnfDZ8VBGzsh6hjoS4` instead of the 404ing `/track/…` and `/album/…`.
- **Fountain resolution cache key bumped `fountain_id_pro_v4_` → `v5_`**: the cached value is now the whole hit (`id` + `type`) rather than the bare id, so entries written before this fix - which carry no type - are re-resolved once each rather than being served for their remaining 30-day lifetime.

## Resolver (v4v-musiclink) — [1.22.8] - 2026-09-23

### Fixed
- **Fountain.fm button 404'd whenever Fountain had filed the release as a podcast**: the URL path was hardcoded to `/track/` or `/album/` purely from whether the shortcode carried a `track=` guid, but Fountain serves the *same* opaque id under four different prefixes (`/track/`, `/album/`, `/episode/`, `/show/`) according to how **it** has categorised the release - so any release Fountain ingested as a podcast rendered its client-side "Oops! That page can't be found" screen (always HTTP 200, so the status code cannot detect it). Fountain's own search response already carries the answer as a `_type` field on every hit, and that field was being parsed then thrown away. The extractor now returns the type alongside the id, and a new `fountain_path_segment()` maps `TRACK→track`, `ALBUM→album`, `EPISODE→episode`, `SHOW→show`, falling back to the old guid-based guess only when Fountain omits a recognised type. No extra HTTP request is made. Example: `?album=69c634ad…&track=016b464c…` now emits `/episode/3xnUxg38mNApJVgv4hXa` and `/show/trSnfDZ8VBGzsh6hjoS4` instead of the 404ing `/track/…` and `/album/…`.
- **Fountain resolution cache key bumped `fountain_id_v4_` → `v5_`**: the cached value is now the whole hit (`id` + `type`) rather than the bare id, so entries written before this fix - which carry no type - are re-resolved once each rather than being served for their remaining 30-day lifetime.

## Resolver Pro (v4v-musiclink-pro) — [1.20.10] - 2026-09-23

### Security
- **Fountain script discovery now checks the exact host**: `fountain_autodetect_next_action()` accepted any script URL that merely *contained* the substrings `fountain.fm` and `_next`, so a page listing e.g. `https://evil.tld/fountain.fm/_next/x.js` would have made the server fetch an attacker-chosen URL. Sources are now normalised first (protocol-relative `//host/…`, root-relative `/…`) and only allowed when the parsed host is exactly `fountain.fm` or a subdomain of it - verified with 12 test cases covering the apex, subdomains, both relative forms, and the four substring/suffix/query bypass tricks.
- **No redirect hops on outbound requests (SSRF hardening)**: all four `wp_remote_*` calls (Music Index API, Fountain search POST, Fountain homepage, Fountain script chunks) now use `redirection => 0` instead of WordPress's default 5, so an open redirect on a remote host can no longer bounce a server-side request to arbitrary - including internal - addresses. Checked against the live hosts first: none of them issue redirects on these paths today. If one ever starts, Fountain degrades gracefully to its plain link and Music Index lookups fail soft into the existing "Album/Song not found" path.

### Changed
- **Canonical URL is now rebuilt from the path plus the sanitised `album`/`track` args only**: `fix_canonical_url()` and the output-buffer rewrite previously echoed the raw `REQUEST_URI` (`add_query_arg( null, null )`), handing every other - attacker-supplied - query arg to filter consumers that don't all escape on output. Both producers of the canonical tag now share one new `musiclink_canonical_url()` helper, so they also can't disagree with each other. *Behaviour change:* `…/musiclink/?album=X&utm_source=Y` now canonicalises to `…/musiclink?album=X` (junk args are dropped).

## Resolver (v4v-musiclink) — [1.22.7] - 2026-09-23

### Security
- **Fountain script discovery now checks the exact host**: `fountain_autodetect_next_action()` accepted any script URL that merely *contained* the substrings `fountain.fm` and `_next`, so a page listing e.g. `https://evil.tld/fountain.fm/_next/x.js` would have made the server fetch an attacker-chosen URL. Sources are now normalised first (protocol-relative `//host/…`, root-relative `/…`) and only allowed when the parsed host is exactly `fountain.fm` or a subdomain of it - verified with 12 test cases covering the apex, subdomains, both relative forms, and the four substring/suffix/query bypass tricks.
- **No redirect hops on outbound requests (SSRF hardening)**: all four `wp_remote_*` calls (Music Index API, Fountain search POST, Fountain homepage, Fountain script chunks) now use `redirection => 0` instead of WordPress's default 5, so an open redirect on a remote host can no longer bounce a server-side request to arbitrary - including internal - addresses. Checked against the live hosts first: none of them issue redirects on these paths today. If one ever starts, Fountain degrades gracefully to its plain link and Music Index lookups fail soft into the existing "Album/Song not found" path.

## Resolver Pro (v4v-musiclink-pro) — [1.20.9] - 2026-09-23

### Security
- **Stored XSS via the document title**: `override_document_title()` returned the release title (external Music Index API data) verbatim from the `pre_get_document_title` filter, and WordPress core echoes that value unescaped in `<title>` - a catalog entry containing `</title><script>…</script>` would close the element early and inject markup into the page. Affects the default "On (this plugin only)" mode; "Entire Site" mode already re-emitted the tag escaped via its output-buffer rewrite. The value is now passed through `esc_html()`.
- **Backreference injection in three `preg_replace()` replacement strings**: the canonical-tag rewrite (whose URL comes from the request's query string), the `<title>` rewrite, and the meta-tag rewriter all interpolated dynamic data into a `preg_replace()` replacement, where `$0` / `$1` / `$2` are expanded as backreferences. A crafted `?x=$2` URL corrupted/broke out of the rewritten canonical tag, and a release title containing `$0` spliced the raw old `<title>`/`<meta>` element back into the page. All three now use `preg_replace_callback()`, which does not parse `$` / `\`; output for benign inputs is byte-identical.

## Resolver Pro (v4v-musiclink-pro) — [1.20.8] - 2026-09-23

### Changed
- **Footer credit is now fully linked**: in "Generated by V4V MusicLink. Powered by MusicIndex.org", **MusicIndex.org hyperlinks to https://musicindex.org**, matching the standard plugin. The translatable string `'Powered by MusicIndex.org'` was split into `'Powered by'` plus a static `<a>` holding the brand name, so the brand is never translated; the existing `.v4v-musiclink-pro-footer a` rule already colours and underlines it, so no CSS change.

### Fixed
- **Footer "V4V MusicLink" link pointed at the visitor's own site**: it built `home_url( remove_query_arg( array( 'album', 'track' ) ) )` — the current page with its query args stripped — instead of the product page. It now links to https://phafe.com/musiclink, identical to the standard plugin. (Behaviour change: Pro no longer links back to the viewer's own musiclink page from the footer.)

## Resolver (v4v-musiclink) — [1.22.6] - 2026-09-23

### Changed
- **Footer credit is now fully linked**: in "Generated by V4V MusicLink. Powered by MusicIndex.org", **MusicIndex.org hyperlinks to https://musicindex.org** (the "V4V MusicLink" part already linked to https://phafe.com/musiclink). The translatable string `'Powered by MusicIndex.org'` was split into `'Powered by'` plus a static `<a>` holding the brand name, so the brand is never translated; the existing `.v4v-musiclink-footer a` rule already colours and underlines it, so no CSS change.

## Resolver (v4v-musiclink) — [1.22.5] - 2026-09-23

### Added
- **Parameter list on the "Missing parameter(s)" message** (front end): a `params:` section now appears above the `Usage:` examples, listing `album="<album-guid>"`, `track="<track-guid>" (optional)` and `header="<true/false>" (optional)`. Each line uses the same `<pre>` markup as the examples, so it picks up the existing `.v4v-musiclink-missing-params` chip styling with no CSS change. The list also shows with the "Album/Song not found" reason, which shares this message and its Usage block.

## Resolver Pro (v4v-musiclink-pro) — [1.20.7] - 2026-09-23

### Added
- **Version number on the settings page** (Settings → V4V MusicLink Pro): the heading now reads "V4V MusicLink Pro Settings  Version 1.20.7", in small `description` styling next to the title, and appears above the tab bar so it shows on the Settings / View Log / Reports tabs alike. The version is read from the plugin file header at render time via `get_file_data()`, so `bump-version.sh`'s two maintained spots remain the only copies.

### Fixed
- **Settings page heading mislabelled**: it read "V4V MusicLink Settings" (the standard plugin's title) instead of "V4V MusicLink Pro Settings". The sidebar menu title was already correct, so menu and page header now match.

## Resolver (v4v-musiclink) — [1.22.4] - 2026-09-23

### Added
- **Version number on the settings page** (Settings → V4V MusicLink): the heading now reads "V4V MusicLink Settings  Version 1.22.4", in small `description` styling next to the title. The version is read from the plugin file header at render time via `get_file_data()`, so `bump-version.sh`'s two maintained spots remain the only copies — the displayed number can never drift out of sync.

## Resolver Pro (v4v-musiclink-pro) — [1.20.6] - 2026-09-23

### Added
- **Admin-configurable Music apps order** (Settings → V4V MusicLink Pro): a new "Order" column in the Music apps table with ↑/↓ buttons moves each app up or down, and the `[musiclink_pro]` shortcode lists its buttons in that order. Positions are stored per app in the existing option (`v4v_musiclink_pro_apps[slug][order]`) and normalised to unique `0..n-1` on save, so re-saving cannot drift or collide. Options saved before this release carry no order yet and keep the current front-end order — no migration needed.

### Changed
- The **"Release header" row now appears above the "Music apps" row** on the settings page.
- The "Release header" **checkbox is now a Show/Hide drop-down**; the option name and stored values are unchanged (`v4v_musiclink_pro_show_header`), so existing settings keep working. Its label dropped the leading "Show", which is now the drop-down's first option.
- The Music apps description mentions the new Order column, and the file docblock documents both changes.

## Resolver (v4v-musiclink) — [1.22.3] - 2026-09-23

### Added
- **Admin-configurable Music apps order** (Settings → V4V MusicLink): a new "Order" column in the Music apps table with ↑/↓ buttons moves each app up or down, and the `[musiclink]` shortcode lists its buttons in that order. Positions are stored per app in the existing option (`v4v_musiclink_apps[slug][order]`) and normalised to unique `0..n-1` on save, so re-saving cannot drift or collide. Options saved before this release carry no order yet and keep the current front-end order — no migration needed.

### Changed
- The **"Release header" row now appears above the "Music apps" row** on the settings page.
- The "Release header" **checkbox is now a Show/Hide drop-down**; the option name and stored values are unchanged (`v4v_musiclink_show_header`), so existing settings keep working. Its label dropped the leading "Show", which is now the drop-down's first option.
- The Music apps description mentions the new Order column, and the file docblock documents both changes.

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
