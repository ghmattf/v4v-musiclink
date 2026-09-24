# Changelog

All notable changes to the V4V MusicLink plugins are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versions follow [Semantic Versioning](https://semver.org/) and are tracked independently per plugin.

## Resolver Pro (v4v-musiclink-pro) — [1.20.19] - 2026-09-24

### Changed
- **The log now records deliberate actions only - search-result opens and app launches - and never page renders**: renders used to be logged whenever a guid arrived in the URL, so refreshes, feed readers, previews and crawler hits all grew the table while saying little about actual usage - any request could add rows without bound. Page renders are no longer logged from any source (URL query string *or* hardcoded shortcode attribute); instead an "open" is logged at the moment a visitor **picks a result on the search page** - the click that generates the shareable URL - via a new `v4v_musiclink_pro_log_open` beacon (`wp_ajax` + `wp_ajax_nopriv`, `navigator.sendBeacon()` with a `fetch(…, {keepalive:true})` fallback, fired before the navigation). The render that follows, every later view of that URL, and shared links opened cold all deliberately log nothing, so each `app = ''` row now corresponds to someone actively choosing a release. As with launches, only guids travel to the server and titles are resolved from Music Index before anything is written (400 without an album guid, 404 when it no longer resolves), so the client never decides what lands in the log. The beacon reuses the existing `logNonce`/`v4v_musiclink_pro_log_app` nonce action, so already-cached pages keep verifying after upgrade. The reports keep their shape: the three per-release reports count opens (legacy view rows included) via `app = ''`, "Total by Music App" counts launches.

### Security
- **Both log-write beacons are rate-limited per client, and log growth is now bounded**: each beacon endpoint accepts at most **30 requests per minute per IP** by default before answering `429` - with no row written - and the check runs *before* any Music Index lookup, so a flood is never relayed upstream. Each endpoint has its own bucket, so launch floods can't starve search-result opens or vice versa, and the window semantics match the search limiter from 1.20.15: keyed on `REMOTE_ADDR` only (never the spoofable `X-Forwarded-For`), allowed requests reset the 60-second window, rejected requests never advance the counter, so a shared/NAT address can't be locked out permanently. Both budgets are admin-configurable (Settings → V4V MusicLink Pro; 1-600/min, blank/zero falls back to 30, and values written outside the settings UI are re-clamped so logging or the flood guard can't be silently disabled). The table itself is pruned back to its newest **100,000 rows** by default (also admin-configurable; 1,000-1,000,000), checked at most once per hour after a row is written - the size lookup walks the primary key past the cap, so paying it on every insert would be waste; the table may sit modestly above the cap between hourly checks but never grows without bound.

## Resolver Pro (v4v-musiclink-pro) — [1.20.18] - 2026-09-24

### Fixed
- **Fountain.fm buttons link directly to the track again (and one blip can no longer erase them for an hour)**: Fountain's 2026 restructure broke the unofficial search integration in two independent ways. (1) `searchContent` became a *page-bound* action: the plugin's POST to `https://fountain.fm/` - unchanged since the integration was written - now returns an empty `application/json {}` instead of results, while the byte-identical POST to `https://fountain.fm/search` returns the full hits payload (verified live: same `next-action` id, same body, only the path differs), so `fountain_try_resolve()` now targets `/search` with all other headers unchanged. (2) The action's registration id moved off the homepage into chunks only the `/search` page references - all 44 homepage chunks were verified to contain *zero* viable candidates - so `fountain_autodetect_next_action()`'s background self-heal could never find a fresh value after a redeploy; it now seeds `/search` first and scans the search-only chunk delta before the homepage's historic first 20 (the homepage remains as fallback coverage, the 15-minute throttle unchanged). Miss caching now follows the same policy as the Music Index fix in 1.20.16: only **definitive** answers (`404` for a stale next-action - whose retry uses a different cache key and is therefore never blocked - or a readable hits payload without our guid) keep `FAIL_CACHE_TTL`; transport failures, `5xx`, redirects and unreadable `200` bodies are cached for **30 seconds** (`SOFT_FAIL_CACHE_TTL`), so a single network blip can no longer pin the button to the plain fountain.fm link for a full hour. `extract_fountain_hit()` also refuses to treat an unparseable body as a trustworthy "no" any more. The settings page and plugin-header DevTools instructions now point at the POST to `fountain.fm/search` when capturing a next-action value by hand.

## Resolver (v4v-musiclink) — [1.22.12] - 2026-09-24

### Fixed
- **Fountain.fm buttons link directly to the track again (and one blip can no longer erase them for an hour)**: Fountain's 2026 restructure broke the unofficial search integration in two independent ways. (1) `searchContent` became a *page-bound* action: the plugin's POST to `https://fountain.fm/` - unchanged since the integration was written - now returns an empty `application/json {}` instead of results, while the byte-identical POST to `https://fountain.fm/search` returns the full hits payload (verified live: same `next-action` id, same body, only the path differs), so `fountain_try_resolve()` now targets `/search` with all other headers unchanged. (2) The action's registration id moved off the homepage into chunks only the `/search` page references - all 44 homepage chunks were verified to contain *zero* viable candidates - so `fountain_autodetect_next_action()`'s background self-heal could never find a fresh value after a redeploy; it now seeds `/search` first and scans the search-only chunk delta before the homepage's historic first 20 (the homepage remains as fallback coverage, the 15-minute throttle unchanged). Miss caching now follows the same policy as the Music Index fix in 1.22.10: only **definitive** answers (`404` for a stale next-action - whose retry uses a different cache key and is therefore never blocked - or a readable hits payload without our guid) keep `FAIL_CACHE_TTL`; transport failures, `5xx`, redirects and unreadable `200` bodies are cached for **30 seconds** (`SOFT_FAIL_CACHE_TTL`), so a single network blip can no longer pin the button to the plain fountain.fm link for a full hour. `extract_fountain_hit()` also refuses to treat an unparseable body as a trustworthy "no" any more. The settings page and plugin-header DevTools instructions now point at the POST to `fountain.fm/search` when capturing a next-action value by hand.

## Resolver Pro (v4v-musiclink-pro) — [1.20.17] - 2026-09-24

### Changed
- **v4vmusic.com button now uses guid lookup instead of title search**: v4vmusic.com added public URL-based resolution, so the button now points straight at `https://v4vmusic.com/lookup/{album}` (or `https://v4vmusic.com/lookup/{album}/{track}` when a track guid is present), built purely from the shortcode's guids exactly like the Stablekraft and PodTunes buttons. The button therefore no longer depends on Music Index at all: the previous implementation linked a title search (`/search?q=…&type=songs|albums`) and was *silently omitted* whenever title resolution didn't yield the right field - a release whose track lookup missed but album resolved lost the button entirely - and it now renders whenever the release itself renders.

## Resolver (v4v-musiclink) — [1.22.11] - 2026-09-24

### Changed
- **v4vmusic.com button now uses guid lookup instead of title search**: v4vmusic.com added public URL-based resolution, so the button now points straight at `https://v4vmusic.com/lookup/{album}` (or `https://v4vmusic.com/lookup/{album}/{track}` when a track guid is present), built purely from the shortcode's guids exactly like the Stablekraft and PodTunes buttons. The button therefore no longer depends on Music Index at all: the previous implementation linked a title search (`/search?q=…&type=songs|albums`) and was *silently omitted* whenever title resolution didn't yield the right field - a release whose track lookup missed but album resolved lost the button entirely - and it now renders whenever the release itself renders.

## Resolver Pro (v4v-musiclink-pro) — [1.20.16] - 2026-09-24

### Fixed
- **An API hiccup no longer masquerades as "Album/Song not found" for an hour**: `mi_api_get()` used to collapse every failure - timeout, DNS error, 5xx, an SSRF-blocked redirect, an undecodable body *and* a true `404` - into one `null`, and both callers then recorded that `null` as a definitive "guid doesn't exist" for `FAIL_CACHE_TTL` (1 hour). One blip therefore poisoned the track and feed transients together, and every render for the next hour answered "Album/Song not found" straight from cache without a single network retry - even after Music Index recovered, and independently of the Pro keyspace, which is why one plugin could keep showing a stale success while the other showed a false failure. The status of each request is now recorded, and only a **definitive** miss (`404`, or a `200` whose payload simply lacks the title) earns the full hour; everything unconfirmed is negative-cached for **30 seconds** (`SOFT_FAIL_CACHE_TTL`) - enough to spare a struggling API a retry storm, little enough to self-heal almost immediately. An undecodable `200` is also demoted to unconfirmed, so a transient proxy error can never be remembered as a missing guid.

## Resolver (v4v-musiclink) — [1.22.10] - 2026-09-24

### Fixed
- **An API hiccup no longer masquerades as "Album/Song not found" for an hour**: `mi_api_get()` used to collapse every failure - timeout, DNS error, 5xx, an SSRF-blocked redirect, an undecodable body *and* a true `404` - into one `null`, and both callers then recorded that `null` as a definitive "guid doesn't exist" for `FAIL_CACHE_TTL` (1 hour). One blip therefore poisoned the track and feed transients together, and every render for the next hour answered "Album/Song not found" straight from cache without a single network retry - even after Music Index recovered, and independently of the Pro keyspace, which is why one plugin could keep showing a stale success while the other showed a false failure. The status of each request is now recorded, and only a **definitive** miss (`404`, or a `200` whose payload simply lacks the title) earns the full hour; everything unconfirmed is negative-cached for **30 seconds** (`SOFT_FAIL_CACHE_TTL`) - enough to spare a struggling API a retry storm, little enough to self-heal almost immediately. An undecodable `200` is also demoted to unconfirmed, so a transient proxy error can never be remembered as a missing guid.

## Resolver Pro (v4v-musiclink-pro) — [1.20.15] - 2026-09-24

### Security
- **Search endpoint is rate-limited per client**: `ajax_search` now allows at most 30 requests per minute per IP before answering `429`, closing an amplification vector - the endpoint's nonce is printed on every page carrying the shortcode, so it only proved a request came from *a* page, and nothing previously bounded how many upstream Music Index batches (plus one detail lookup per result) a single client could trigger. The budget is a sliding window held in a transient keyed on `REMOTE_ADDR` only (never `X-Forwarded-For`, which a client can spoof to mint a fresh bucket): each allowed request resets the 60-second counter, while requests over the cap are rejected *without* advancing it, so hammering never extends the block - a quiet client gets its full budget back 60 s after its last allowed request and a shared/NAT address can never be locked out permanently. The guard runs after the empty-query early return, so no-op polls never spend budget, and the UI already surfaces a non-200 as "Search failed", so no script change was needed.
- **Fountain script discovery requires HTTPS**: `fountain_autodetect_next_action()` now rejects any `src` whose scheme is not `https` (previously the scheme was never checked - only the exact-host comparison). A cleartext `http://` response, modifiable in transit, can no longer supply a `next-action` value that would be persisted to the options table via `update_option()`.

## Resolver (v4v-musiclink) — [1.22.9] - 2026-09-24

### Security
- **Fountain script discovery requires HTTPS**: `fountain_autodetect_next_action()` now rejects any `src` whose scheme is not `https` (previously the scheme was never checked - only the exact-host comparison). A cleartext `http://` response, modifiable in transit, can no longer supply a `next-action` value that would be persisted to the options table via `update_option()`.

## Resolver Pro (v4v-musiclink-pro) — [1.20.14] - 2026-09-23

### Added
- **App button launches are logged**: every click that actually takes the visitor to the app now writes its own row - the same date/artist/album/track a view carries, plus the app that opened, in a new `app` column on `{wp_prefix}v4v_musiclink_pro_log` (added in place by dbDelta, schema version 1 → 2; pre-existing rows default to `app = ''` and so stay views). The page fires a `navigator.sendBeacon()` beacon (falling back to `fetch(…, {keepalive:true})`) the instant the anchor is followed, and Fountain's "unable to generate a link" modal counts **only on OK** - Cancel, Escape and a backdrop click navigate nowhere, so they log nothing; a already-resolved Fountain URL logs on the click itself. The beacon carries the guids (read off `data-album`/`data-track` on the button container, which is what makes shortcode-embedded releases work - they have no query string to read) and the app slug; **titles are resolved server-side before anything is written**, so the client never decides what lands in the log, only registry slugs are accepted (400 otherwise, 404 for guids that no longer resolve), and it is guarded by its own `logNonce`, separate from the search nonce. Launches are logged from every placement, including releases hardcoded into a shortcode attribute - a click is a deliberate action, unlike a render.
- **New report: "Total by Music App"** - launches grouped by app under a "Total Launches" column, with the same artist filter and date-range presets as the existing reports. Slugs are displayed through the registry label, falling back to the slug itself so a row for an app that has since been retired still renders.

### Changed
- **View Log shows both kinds of row**: a new **App** column reads `—` for a page view and the app's name for a launch, and the count line now says "log entries" rather than "logged views" since it lists both. The **Clear log** button clears both kinds.
- **The artist/album/track reports keep counting views only** (`app = ''`): a launch repeats the artist/album/track of the click behind it, so including both would double numbers that have always meant "logged views". The scope of each is now explicit - those three reports = views, Music App = launches.
- **The button container emits `data-album`/`data-track`** (the beacon needs the guids). On a shareable page they were already in the URL; on a shortcode-embedded release they now appear in the page source too, where previously they existed only in the post editor.
- The empty-row `colspan` in the reports table is now computed from the columns actually shown (it was a hardcoded 4, which over-spans the 2-column Artist report).

## Resolver Pro (v4v-musiclink-pro) — [1.20.13] - 2026-09-23

### Added
- **A "Clear log" button on the View Log tab**: the view log could only ever grow - the only way to empty `{wp_prefix}v4v_musiclink_pro_log` was to drop the table by hand. The button now empties it through an `admin-post.php` action guarded by a nonce (so a crafted link or a bare `<img>` hit cannot wipe it) plus a `manage_options` capability check, asks for confirmation via `confirm()` first, and returns to the tab with a "View log cleared." notice. It only appears while the log actually has rows. Rows are deleted with `DELETE` rather than `TRUNCATE` so it also works on restricted WordPress database users.

### Changed
- **Releases hardcoded into a shortcode attribute are no longer logged as views**: `log_view()` fired on *every* successful render, including placements where the guid came from `[musiclink_pro album="…"]` in post content - so a single embedded button wrote a row on each render of that post (page views, feeds, previews and crawler hits alike) and the reports/View Log counted those as usage of the shareable `/musiclink?album=…&track=…` link. Only URL-driven views are logged now; whether a guid came from the URL is decided *before* the two sources are merged, since afterwards the value no longer says where it came from (when both are present the shortcode attribute supplies the render, and the view is skipped). What a row contains is unchanged - date plus the three titles Music Index returns. The raw `album`/`track` guids have never been stored, and no raw request params are written to any log anywhere in this plugin; that guarantee is now stated in the plugin header, the README, and asserted by the test suite.

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
