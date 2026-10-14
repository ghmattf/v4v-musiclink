# Outstanding items & findings

The single running list of open work for the V4V Music Link Resolver (standard) plugin, so any
future session can pick up exactly where the last one stopped.

**How this list is maintained** is codified in `AGENTS.md`
("Outstanding items (running list)"). Short version: anything discussed but
not implemented in the same turn gets added here the same turn, with enough
context to continue without re-deriving it; an item leaves the list once it
is **implemented and released** (`CHANGELOG.md` + git history then carry it)
or **explicitly declined**. Implemented history is never here - it is in
`CHANGELOG.md`.

**Status key:** **Planned** = user picked it, plan below, implement only on
request. **On hold** = user deferred. **Open** = identified, awaiting triage.

Line numbers below predate Resolver 1.22.25 / Pro 1.20.31 (2026-10-05)
unless a note says otherwise; they will drift - re-locate by symbol name.

**Repo split (2026-10-11):** this repository now hosts the standard plugin only; Pro-side items live in `ghmattf/v4v-musiclink-pro`. Closed decisions are kept intact in both repositories for context.
---

## O13. "Flush Music Index data" action on the Cache tab

**Status: On hold** (user deferred 2026-10-03 - declined as part of the Applies to both plugins; also tracked in the sibling repository (`ghmattf/v4v-musiclink` for standard / `ghmattf/v4v-musiclink-pro` for Pro) since the 2026-10-11 repo split.
unified cache-table work of that day, listed at the user's request;
implement only if re-picked).

**What:** a control on the Cache tab to purge only the Music Index half
of rows - blank `image_url`, `album_title`, `track_title`, `artist` and
set `mi_updated_at = NULL` - while keeping `fountain_url` +
`fountain_updated_at`, because under the shipped cache model (Resolver
1.22.21 / Pro 1.20.27) the MI half is disposable cache and the link half
is data. Rows repopulate their MI half on the next view (the blocking
fill used for a pair with no cached MI data).

**Plan sketch:** a fourth scope beside the existing Clear controls
(std/Pro Cache tab), `admin_post` handler modelled on
`handle_clear_cache()`: `check_admin_referer()` ->
`current_user_can( 'manage_options' )` -> one scoped `UPDATE ... WHERE`
(never `TRUNCATE`); confirmation wording distinguishes it from
"Clear all" (which still deletes whole rows, links included); notice
reports the affected row count. Both plugins, full rename applied.
---

## O14. No matching cache row → ignore transient TTLs and refresh live

**Status: Planned** (user requested 2026-10-04; they asked for the design Applies to both plugins; also tracked in the sibling repository (`ghmattf/v4v-musiclink` for standard / `ghmattf/v4v-musiclink-pro` for Pro) since the 2026-10-11 repo split.
answer first - implement only on their go-ahead. Ordering is now settled:
O11 shipped first, on 2026-10-05, so O14 may follow whenever they pick it).

**What:** when `cache_get_row()` returns nothing for the pair, stop
consulting the transient caches and go straight to the live lookup, so a
Clear on the Cache tab means "every release re-resolves now" rather than
"delete the rows and hope".

**Why (the user's hit, 2026-10-04):** Clear removes rows only - the
transients outlive it - so after clearing every row on the site the page
still rendered a deep Fountain link from the 30-day hit transient, and
the tab showed no row for it. Same class of gap for MI: a remembered miss
stops the fetch for up to `FAIL_CACHE_TTL`.

**Sites (std; pro is the mirror at 1373 / 3606 / 3924 / 3947 / 3975 /
3996 / 4028 / 4139):**
- MI: `mi_get_feed()` 2199, `mi_get_track()` 2222 -> `mi_fetch_and_record()`
  2253 -> `mi_fetch_feed()` 2274 / `mi_fetch_track()` 2306. Skip
  `mi_take_legacy_positive()` (2416) and the `get_transient( $negative_key )`
  check (2285 / 2316) when the caller had no row.
- Fountain: `get_cached_fountain_url()` 869 -> `fountain_try_resolve()`
  1884. Skip the `fountain_id_v5_*` read (positive AND, if the strict
  version is chosen, the negative) when there is no row.
- Background refreshes of rows that DO exist (`queue_mi_refresh()` /
  `run_queued_mi_refresh()`) keep honouring negatives - that is not the
  no-row case.

**Open decision:** are remembered *failures* ignored too? TTLs are
`FAIL_CACHE_TTL` = 1 h and `SOFT_FAIL_CACHE_TTL` = 30 s
(`negative_cache_ttl()` 2596, `fountain_negative_cache_ttl()` 1970).
Recommendation put to the user: ignore positives only, keep honouring
failures - otherwise an outage turns every view of every row-less release
into blocking API calls plus a Fountain POST per view, on the unofficial
endpoint we are already careful with.

**Ordering warning (resolved 2026-10-05):** this originally said to do
O11 first, because ignoring the 30-day Fountain hit while self-heal was
broken would have turned the user's one working deep link into
`fountain.fm/discover` with nothing able to re-resolve it. O11 shipped as
Resolver 1.22.24 / Pro 1.20.30, so that hazard is gone and O14 no longer
has to wait on anything.

**Plan sketch:** thread a "no row" flag from `mi_get_*()` /
`get_cached_fountain_url()` into the fetch/resolve helpers; both plugins
(identical after `_pro` normalization); extend `tmp/test_stored_links.php`
with: no row + remembered MI miss => live fetch runs; no row + cached
Fountain hit => POST attempted; row present => both still honoured.
---

## O18. New shortcode parameter accepting a direct fountain.fm link (both versions) - attribute only, never a URL query param

**Status: On hold** (user deferred 2026-10-05 - scope and precedence Applies to both plugins; also tracked in the sibling repository (`ghmattf/v4v-musiclink` for standard / `ghmattf/v4v-musiclink-pro` for Pro) since the 2026-10-11 repo split.
decisions from that day are retained below).

**What:** a `fountain` attribute on BOTH shortcodes (`[musiclink]` and
`[musiclink_pro]`) taking a pre-known direct link, e.g.
`[musiclink album="..." track="..." fountain="https://fountain.fm/<segment>/<id>"]`,
used as-is for that placement - no Server-Action resolve, no self-heal, no
Cache-tab edit needed. **Decided 2026-10-05: the attribute OVERRIDES the
cache row** for that placement; precedence is attribute -> cache row ->
`fountain_resolve()`. (The original request said Pro only; scope widened to
both versions 2026-10-05.)

**Where (both; pro is the mirror):**
- Attribute intake: `shortcode_atts( array( 'album', 'track', 'header' ) )`
  std **1637-1641** / pro **3121-3125** - unknown keys are silently dropped
  by `shortcode_atts`, so adding one is cheap, **but standard also has the
  `$unexpected` whitelist at std 1636**: an attribute name not in its
  `array( 'album', 'track', 'header' )` causes ALL attributes to be
  ignored, so `fountain` must be added to both lists in std or the feature
  silently never applies there.
- Fountain block that consumes it: std **1727-1766** (`$fountain_url`
  default 1728, cache-row hit 1737-1740, resolve 1750, `store_fountain_link()`
  1764) / pro **3208-3248** (default 3210, resolve 3232, store 3246) -
  the attribute check goes in front of the cache-row branch per the
  precedence decision above.
- Buttons render through `esc_url( $link['url'] )` std **2751** / pro **4483**.
- Existing "give me a link by hand" mechanism is the Cache-tab inline
  `fountain_url` edit (`handle_update_cache_row`) - global per release;
  the new attribute is per placement and outranks it (decided).

**Why not a URL query param (the user's abuse concern):** Pro is the only
surface that reads the query string for content (`get_guid_arg()` 612-615,
`$_GET['album']`/`$_GET['track']` feeding 3130-3135 for the shareable
/musiclink page). If a `fountain` URL followed that pattern, **any
visitor** could publish `?fountain=https://evil.example/phish` and the page
would emit that as the button's href - reflected link injection, and with a
full-page cache an attacker's URL can be frozen into cached HTML for every
later visitor. The shortcode-attribute path is author-gated (post content
needs `edit_posts`+), which is the same trust level already trusted with
`album`/`track`/`header`. Recommendation baked into this item: **attribute
only; no `$_GET` twin.** Standard can never grow this surface by accident -
it has no URL-parameter mode at all (README:22) - so the rule binds Pro.
If a URL-driven flavor is ever demanded, it needs
the validation below plus an explicit sign-off that any visitor may set it.

**Validation requirements (attribute value):** parse as URL; scheme must be
`https`; host exactly `fountain.fm` or `*.fountain.fm` - the same trust rule
the chunk scan already uses (std 2087-2090 / pro mirror); reject anything
else (`javascript:`, `http:`, look-alike hosts, userinfo tricks like
`fountain.fm@evil.tld`) by falling back to the normal cache-row/resolve
path rather than rendering it; keep the 256-byte length cap pattern
(`guard_guid()` std 1625 / pro 605-607) for consistency; output stays on
the existing `esc_url()` line (std 2751 / pro 4483).

**Open decisions:**
1. Persistence: should a valid attribute value be written back via
   `store_fountain_link()` (making one placement's override global) or stay
   placement-local (recommended - a per-post attribute silently editing the
   shared cache row would surprise the Cache tab).
2. Does the confirm-first modal need to know about it? Today `isFallback`
   matches only `discover`/`search?`/bare homepage, so a validated deep
   link correctly does not intercept - confirm no change needed.

**Plan sketch:** add `'fountain' => ''` to `shortcode_atts` in both
plugins (std 1637 **and the std 1636 `$unexpected` whitelist** / pro 3121);
new `fountain_direct_url_or_reject()` sanitizer per the rules above,
mirrored; feed it into both Fountain blocks **ahead of the cache-row branch**
(decided precedence: attribute -> cache row -> resolve); leave
`fountain_resolve()` / self-heal / stored-links untouched for placements
that don't supply it; unit-test the sanitizer as a host/scheme/length
matrix plus render assertions per plugin (valid attr -> button href, attr
outranks a conflicting cache row, invalid attr -> falls back, attribute
never reaches `store_fountain_link()` unless open decision 1 says so);
README usage sections for both versions gain the attribute.
---

## O26. WordPress.org Plugin Directory submission readiness - standard plugin

**Status: Open** (blockers 1-4 implemented and released as Resolver
1.22.31 on 2026-10-12; readme validator CLEAN 2026-10-14 with the
user's username `phafe` - what remains: Plugin Check run (user, in any
WP admin), the human submission itself, assets upload after approval,
plus keeping the Music Index permission evidence on hand).

**Progress 2026-10-14 (pre-submission gates):**
- **Official readme validator: CLEAN** (HTTP POST of `wp_plugin/readme.txt`
  to wordpress.org/plugins/developers/readme-validator/): **0 errors,
  0 warnings** after the user supplied their username - `Contributors:
  phafe` (2026-10-14). Only 3 informational notes remain, all optional:
  no `== Upgrade Notice ==`, no `== Screenshots ==`, no donate link.
  An earlier run (2026-10-13, placeholder `ghmattf`) warned that the
  contributor did not exist; that warning is gone with `phafe`.
- **Directory assets generated** (ImageMagick, at `tmp/wporg-assets/`,
  gitignored scratch - copy somewhere durable or commit on request):
  `banner-1544x500.png` + `banner-772x250.png` (dark gradient, music
  note, title + app list) and `icon-256x256.png` + `icon-128x128.png`
  (rounded-square gradient + note). These upload to the SVN `assets/`
  dir (or the dev dashboard "Assets" tab) AFTER approval - not in the zip.
- **Plugin Check (official plugin) not yet run** - needs a WordPress
  install; none available locally (no docker/node for wp-env or
  Playground CLI). User options: run Plugin Check in any WP admin
  (install the "Plugin Check" plugin, upload the zip there), or use
  https://wordpress.net/playground with the zip. Zip staged at
  `tmp/v4v-musiclink-1.22.31.zip` (v4v-musiclink.php + readme.txt only).

**What:** compliance audit of standard 1.22.30 against the wp.org plugin
guidelines, the common-issues list, and the submission process (sources
checked 2026-10-11: developer.wordpress.org `detailed-plugin-guidelines`,
`common-issues`, `planning-submitting-and-maintaining-plugins`). The code
itself is in good shape - the earlier security review holds (nonces,
`manage_options` caps, prepared SQL, ABSPATH guard :89, escaping, no
eval/minified code, WP HTTP API only, consistent `v4v-musiclink` text
domain on all 152 refs, no telemetry/beacons in standard, no license
gates). The gaps were packaging, one guideline violation, and docs -
all four code/docs blockers below are fixed in 1.22.31.

**Blockers (a reviewer would reject on these) - ALL RESOLVED in 1.22.31:**
1. **No `readme.txt` anywhere** - repo has only root `README.md`. wp.org
   requires `readme.txt` in the plugin root that passes the readme
   validator, with headers: `=== V4V Music Link Resolver ===`,
   Contributors, Tags, Requires at least, Tested up to, Requires PHP,
   `Stable tag: 1.22.30`, License, License URI, and a <=150-char short
   description, plus Description/Installation/FAQ/Screenshots/Changelog
   sections. Root README.md is a solid base to adapt.
   **RESOLVED 1.22.31**: `wp_plugin/readme.txt` written (Stable tag
   1.22.31, Tested up to 7.1.3 per the user's own test, external-
   services disclosure incl. the Music Index permission note). The
   `Contributors:` header reads `phafe` (user's wordpress.org username,
   supplied 2026-10-14; accepted by the readme validator).
2. **No GPL declaration** - plugin header (:1-79) has no `License:` /
   `License URI:` and no LICENSE file ships in `wp_plugin/`.
   Guideline 1; a known rejection reason ("No GPL-compatible license
   declared"). Declare `GPLv2 or later`.
   **RESOLVED 1.22.31**: header now declares `GPLv2 or later` +
   License URI; readme.txt repeats it. No separate LICENSE file added
   (the header + readme declaration is the wp.org norm).
3. **Guideline 10 violation - unconditional credit links on public
   pages**: `render_html()` prints "Generated by
   [phafe.com]" + "Powered by [musicindex.org]" on every shortcode
   render (editor preview only hides it via `block_editor_css()`).
   External links/credits on the public site need explicit user
   permission - make it an admin opt-in setting defaulting OFF, or
   remove it.
   **RESOLVED 1.22.31**: new `v4v_musiclink_show_credits` setting
   (register_settings + Settings tab row "Credit links"), default OFF;
   `render_html()` only prints the footer when ticked. Pins C1-C5 in
   `test_stored_links` (195 -> 200).
4. **Missing plugin-header fields** in :1-79: `Text Domain:
   v4v-musiclink` (required for translate.wordpress.org pickup; slug and
   domain must match), `Requires at least:`, `Tested up to:` (latest
   stable at submission), `Requires PHP:` - code uses no modern syntax
   (no `??`, no arrow fns, no typed props), so `7.4` is a safe
   declaration; `Plugin URI`/`Author URI` recommended.
   **RESOLVED 1.22.31**: header now carries Text Domain, Requires at
   least 5.8, Tested up to 7.1.3 (user-verified), Requires PHP 7.4,
   Plugin URI (GitHub repo), Author URI (phafe.com).

**Submission process steps (no code):**
- Pick the directory slug = `v4v-musiclink` (fixed at first submission;
  it must equal the text domain or all 152 refs need rewriting).
- Build the zip by hand (bump-version.sh makes git commits, not zips):
  include `wp_plugin/v4v-musiclink.php` + new readme.txt, **exclude
  `wp_plugin/archive/`** (gitignored historical backups - wp.org
  flags "Included Unneeded Folders") and any `.git`.
- Run the official **Plugin Check** plugin + the readme validator before
  submitting (the two automated gates); 14-business-day review, then SVN
  access (trunk + tags/x.y.z, Stable tag must match trunk readme).
- Nice-to-have: banner/icon assets + screenshots for the directory page.

**Documentation (review risk if missing - "Undocumented 3rd party"):**
- readme must document external services: server-side GETs to
  `api.musicindex.org` (UA includes `home_url()`, :2764 - disclose;
  fired only on cache-miss renders + admin search/block REST), favicon
  images loaded by visitor browsers from the four app domains
  (`favicon_url()` :2154), and outbound links to those apps. State that
  standard does no tracking/telemetry/update phone-home (beacons are
  Pro-only), and ideally link musicindex.org terms + a short privacy
  note (guidelines 6/7).
- **Music Index has NO published license or terms of use** (probed
  2026-10-11: homepage HTML has zero license/terms/copyright/about
  links; `/terms`, `/license`, `/legal`, `/about`, `robots.txt` all
  404; `developer.musicindex.org` is a bare API console with
  `/terms`+`/docs` 404; no indexed ToU; no public source repo found).
  Guideline 2 says developers must comply with third-party API terms
  and that unvalidatable terms are a blocker. **UPDATE 2026-10-12: the
  user reports the operator (Sir HeyCitizen) has granted permission for
  the plugin to use the API (including caching), but no published ToU
  or written permission record is available yet** - the readme.txt now
  discloses the API, the user-agent's home_url inclusion, and states
  that use is permitted by the operator with terms still pending. Keep
  the operator's permission record (screenshot/nostr DM/etc.) on hand
  and update the readme.txt "External services" section once ToU/API
  terms are published; until then the disclosure is the best available
  mitigation and submission risk is judged low (open, no-credential
  API, operator permission granted).

**Non-blocking code improvements (pick as desired):**
- Front-end shuffle `<script>` inline in shortcode output (:3231-3249)
  -> fold into `inline_js()` (already delivered via
  `wp_add_inline_script` :1366); admin-page inline scripts at :558 and
  :1190 -> `admin_enqueue_scripts` (wp.org "Use wp enqueue" guidance;
  cosmetic).
- `enqueue_assets()` :1359 prints CSS/JS on every front end even with no
  shortcode -> could gate on singular-post content containing the
  shortcode/block (trade-off: dynamic `do_shortcode()` callers would
  lose styling - weigh carefully).
- Block is client-side-registered only (no `register_block_type`/
  block.json): non-admin editors get the "block unavailable" recovery UI
  on posts containing it (enqueue gated `manage_options` :1382); front
  end is unaffected (unknown-block fallback renders the inner shortcode,
  verified live). Server-side registration would fix editor UX and
  match block-editor standards.
- i18n polish: no `_n()` anywhere (the "Missing parameter(s)" pattern);
  JS UI strings ride inline handles without `wp_set_script_translations`.
- Stale file-header comment :77-78 claims icons load "via a favicon
  service" but `favicon_url()` :2154 reads each app's own domain
  directly (Google proxy removed - see :2146 comment). Fix the docblock.
- No `uninstall.php`: options + cache table persist after plugin delete
  (harmless, tidy-up candidate).
- Splitting the 3276-line monolith is optional and NOT recommended -
  single-file layout is a deliberate project convention (bump-version.sh
  assumes one file per plugin).

---

## Closed decisions (context only - not work items; do not re-open or re-propose)

- **Standard-plugin security review** (2026-10-06): no critical or
  high-severity findings across every surface (shortcode intake, output
  escaping, admin-post nonces/caps, REST gate, prepared SQL, fixed-host
  outbound HTTP with `redirection => 0`, no dangerous sinks). Its four
  low findings are resolved below or shipped: stored-URL fail-closed fix
  released as Resolver 1.22.30 / Pro 1.20.34 (see CHANGELOG).
- **Pro-plugin security review** (2026-10-06): full read of
  `v4v-musiclink-pro.php` at 1.20.34 - every surface verified clean except
  one low availability finding (the unbudgeted visitor-driven resolve
  path), fixed as Pro 1.20.35 (the per-IP `resolve` budget, formerly
  recorded as O25 - shipped 2026-10-11). Checked: shortcode/`$_GET`
  intake (`sanitize_text_field` + length-only `guard_guid`), output
  escaping everywhere (render HTML, search UI, social meta, document
  title, Log/Reports/Cache tables, settings fields, `textContent`-only JS
  DOM building - `innerHTML` used solely to clear), all four admin-post
  handlers nonce + `manage_options`, all three AJAX endpoints nonce-gated
  (search 30/min, beacons per-IP budgets; public-page nonces by design and
  documented), no REST routes, every `$wpdb` call prepared or static with
  whitelisted columns, outbound HTTP fixed-host with `redirection => 0`
  (MI API; fountain.fm seeds/chunks exact-host + HTTPS at ~3816-3829, 15-min
  crawl throttle; next-action tokens hex-only or `sanitize_text_field`'d, so
  no header injection), no dangerous sinks (no eval/unserialize/extract/
  file/shell), canonical/og rebuild from sanitized args only, Reports dates
  via `createFromFormat('Y-m-d')`. Pro's artwork edit branch shares the
  declined http:// art rule (see below); its admin post handlers mirror
  standard's.
- **O20 live-harness 404s** (closed 2026-10-05): duplicate of O9 - the
  `fountain_candidate_test`/`fountain_case_test` 404s are stale hardcoded
  `next-action` fixtures against fountain.fm (the originally recorded
  `api.musicindex.org` host was wrong); O9's detect-don't-embed plan is
  the work. Re-open only to triage that plan.
- **http:// artwork accepted - known issue, no current fix, closed**
  (security review finding 2, decided 2026-10-06): tightening
  `handle_update_cache_row()`'s art branch to https-only was declined -
  upstream artwork URLs can be http-only and the site cannot force https.
  Accepted impacts: strict browsers may auto-upgrade or block the art
  `<img>` (worst case: missing artwork), and an on-path attacker on the
  http hop could substitute image bytes; no script execution or session
  leakage is possible from an image, and `esc_url()` still governs what
  can be stored. Do not re-propose the https-only art rule.
- **No rate limit on standard's REST search - known issue, closed**
  (security review finding 3, decided 2026-10-06): `rest_search()` and
  its siblings are `manage_options` + nonce gated with deliberately no
  per-IP limiter (documented in the `register_rest_routes()` docblock);
  worst case is an admin generating bounded upstream MI load (3 batches
  of 100 per query, 8 hits resolved per page). Revisit only if the routes
  ever become non-admin or public, where Pro's per-IP relay limiter would
  then apply.
- **Block builder `clean_value()` strips dangerous characters from GUIDs -
  known issue, closed** (security review finding 4, decided 2026-10-06):
  the block UI deletes `"'<>&` from album/track GUID fields (defence in
  depth for the saved shortcode attribute; the server-side
  `sanitize_text_field()` + `guard_guid()` remain the authority), which
  can mangle a guid containing e.g. an apostrophe. No plugin fix: GUIDs
  should not use dangerous characters in the first place, and such guids
  should be corrected on the Music Index feed itself. Do not re-propose a
  client-side relaxation without a feed-side fix.
- **Fountain self-heal stays inline** (security review finding #5):
  declined 2026-09-24 - no refactor of the inline self-heal.
- **Telegram stale preview** (investigated 2026-09-25): phafe.com serves
  fresh, correct og: tags; the stale image is Telegram's own per-URL cache
  (no purge API). No plugin change; `?tg=1` renders a fresh variant.
- **Production "not logging launches" report** (2026-09-24): false alarm -
  logging worked; no code change resulted.
- **Double-escape Info item**: closed as part of Pro 1.20.20 - the
  canonical helper returns an unescaped URL and every consumer escapes
  exactly once (see CHANGELOG 1.20.20).
- **Security review findings #1-#4, #6-#8**: all implemented and released
  through 1.22.14 / 1.20.20 (see CHANGELOG).
- **Fountain official-API migration + spoofed-UA transparency fix**
  (offered as O10 options 2026-09-26): declined that day - user chose to
  stop after the unauthenticated probe. O10 carries the recorded probe data;
  re-propose only on request.
- **O11 fix approach** (settled 2026-10-05): probe every detected
  `next-action` candidate rather than tighten the near-"search" heuristic,
  and skip O10's official API (Step 0) because O10 needs a paid key and is
  On hold - shipped as Resolver 1.22.24 / Pro 1.20.30 with a two-phase
  chunk walk (search-only delta in full, homepage's first 20 only when the
  delta is empty) so the crawl keeps its pre-fix cost. Re-propose neither
  the heuristic-only variant nor a full-window walk.
- **O6 guid-keyed Fountain positive cache** (planned 2026-09-26): removed
  from the work list 2026-10-05 on user instruction; re-propose only on
  request (full plan preserved in git history).
