# Outstanding items & findings

The single running list of open work for the V4V MusicLink plugins, so any
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

---

## O3. View Log timestamps are shifted (`current_time` vs `get_date_from_gmt`)

**Status: On hold** (user deferred 2026-09-25).

**What:** `log_view()` stores `current_time( 'mysql' )` - site-**local**
time (Pro ~line 324) - but the View Log table prints
`get_date_from_gmt( $row->logged_at, 'Y-m-d H:i:s' )` (~line 1000), which
treats that string as GMT and converts it to local again. On any site not
at UTC the View Log shows times shifted by the site's GMT offset. Reports
are already consistent (`get_report_date_bounds()` ~line 716 computes
bounds with `wp_timezone()` = local, compared against local-stored rows),
so this is display-only.

**Plan sketch:** minimal fix is the View Log cell - print `logged_at`
directly (it is already site-local) instead of `get_date_from_gmt()`. Do
**not** switch storage to GMT: that would misread every existing row and
desync the report bounds. Also check View Log header/notes for wording
about displayed timezones. Probe first if site timezone != UTC (phafe.com
is currently UTC, which hides the bug).

---

## O4. Promote the harness suite from `tmp/` to a tracked `tests/` directory

**Status: On hold** (user deferred 2026-09-25).

**What:** the whole regression suite lives in gitignored `tmp/`, so none of
it is versioned: `test_log_scope.php`, `test_app_launch.php` (needs
`tmp/wpstub/` for the dbDelta stub, loaded via `ABSPATH = __DIR__ .
'/wpstub/'`), `test_log_limits.php`, `test_search_pagination.php`,
`test_stored_links.php` (its own in-memory `$wpdb` double, written 2026-09-26),
`fountain_fix_test.php`, `mi_ttl_test.php`, `v4vmusic_url_test.php`,
`fountain_live_test.php` (**live network** - hits fountain.fm, can
flake/timeout; treat as informational and re-run once before concluding),
`missing_params_heading_test.php`, `canonical_guid_test.php`,
`test_fountain_path.php`, plus the two live Fountain probes
`fountain_candidate_test.php` / `fountain_case_test.php` (no assertion
counter) and the browser e2e in `tmp/e2e/` (`gen_page.php`, `router.php` -
captured HTML files in that dir are scratch). Baseline as of 2026-10-05
(Resolver 1.22.24 / Pro 1.20.30): 14 files - the 11 offline harnesses are
green (962 counted assertions) and `tmp/e2e/gen_page.php` self-checks pass;
`fountain_live_test` is **20/20** again (O11 fixed), and the two live
Fountain probes still print nothing per O9. The stale homepage-only port
`tmp/fountain_probe.php` was retired the same day (its first-candidate
logic no longer matched the plugin; `probe_sweep` / `probe_candidates` /
`probe_window` / `probe_autodetect_trace` / `probe_woman` are the live
diagnostics now).

**Plan sketch:** create tracked `tests/`; `git mv` the ten harnesses plus
`wpstub/` (same directory depth as `tmp/`, so their
`dirname( __DIR__ ) . '/...'` plugin includes keep working); move the two
runnable e2e scripts too but leave captured HTML in `tmp/`; document the
run command (`./php/php tests/<file>.php`, toolchain installed by
`php/install.sh`) in CONTRIBUTING.md or README; mark `fountain_live_test`
as network-dependent; add `tests/` to the AGENTS.md project scope and
update its release-flow step. Remove this item when shipped.

---

## O5. Settings readout: last Fountain attempt

**Status: On hold** (user deferred 2026-09-25).

**What:** Fountain "next-action" self-heal state is invisible to admins.
Today there is only the in-memory `last_fountain_status` property (Pro
~line 186, standard ~line 116), the `fountain_autodetect_throttle(_pro)`
transient (Pro ~line 2757), and the stored `..._fountain_next_action`
option. If the background self-heal keeps failing, nobody can see that it
ran, when, or with what result.

**Plan sketch:** persist an attempt record - option
`v4v_musiclink(_pro)_fountain_last_attempt` = timestamp + HTTP status -
whenever `fountain_autodetect_next_actions()` runs (standard ~line 1030,
Pro ~line 2756); render a read-only line next to the "next-action" field
on each settings page (standard ~line 472, Pro ~line 1508): "Last attempt:
2026-09-25 12:00 - status 404" or "never attempted". Both plugins. A
manual "Test now" button would be a scope extension - only if the user
asks.

---

## O7. Fountain self-heal is slow to recover (or never fires) - diagnosis

**Status: On hold** (user deferred 2026-10-05 - diagnosis kept, nothing
picked; the review notes below are current as of that date).

**What:** why, once self-heal is required, the button takes far too long
to start working again. Causes found this turn in `fountain_resolve()` /
`fountain_autodetect_next_actions()` (standard `fountain_resolve()` 1847,
`fountain_try_resolve()` 1914, `fountain_autodetect_next_actions()` 2049,
`fountain_scan_chunks_for_actions()` 2138; Pro 3569 / 3636 / 3771 / 3860),
roughly ranked:

1. Positive cache keyed on the hash - see O6 (also makes *whether*
   self-heal runs depend on which guid renders first).
2. The 15-minute throttle is armed **before** the attempt, unconditionally
   (standard 2050-2054, Pro 3771-3776), so any outcome -
   no candidates, unreachable seed page, aborted crawl - still costs a
   full 15 minutes of no effort, and consecutive failures stack.
3. The crawl runs synchronously inside the visitor's page render: 2 seed
   pages x 10s timeout + up to 20 delta chunks x 8s (plus up to 20
   fallback chunks x 8s when the delta is empty - the two-phase gate
   shipped with 1.22.24), plus the stale POST (8s) and retry (8s) ~ the
   theoretical worst is still ~6 minutes in one request, but a routine
   self-heal measured **~10s** on 2026-10-05 (the pre-gate always-walk was
   34s measured / ~5.5 min worst). If a
   proxy/PHP/client timeout kills it before `update_option()`, the work
   is discarded while the already-committed throttle transient blocks the
   next 15 minutes.
4. ~~`update_option()` only runs `if ( $result )`, so a *fresh* hash whose
   readable payload lacks this guid is never persisted - indistinguishable
   from "still stale" - producing a detect/fail loop.~~ **Fixed 2026-10-05
   (Resolver 1.22.24 / Pro 1.20.30):** a candidate that answers with a
   readable hits payload is now persisted even when our guid is absent.
5. ~~Deterministic first-candidate short-circuit: if the first
   near-"search" hex isn't the right action, detection returns the same
   wrong value forever until Fountain's chunk order changes.~~ **Fixed
   2026-10-05 (Resolver 1.22.24 / Pro 1.20.30):** detection returns every
   candidate (`fountain_autodetect_next_actions()`) and `fountain_resolve()`
   probes them in order - this was O11, released today.
6. Demand-driven only - no cron. Healing needs a render with a Fountain
   button that also gets a Music Index search text - the attempt is skipped
   entirely otherwise (standard `render()` 1747-1750; MI "not found" is
   negative-cached for 1 hour, `FAIL_CACHE_TTL` std 108), so on a
   quiet site attempts are naturally minutes-to-hours apart.
7. Unverified: site page caching may keep serving plain/stale HTML past
   the plugin's own heal.
8. **2026-10-05 observation (not a regression - baseline behaves the same):**
   `last_fountain_hits_parsed` is reset at the top of `fountain_try_resolve()`,
   so an answer served from the negative cache leaves it false. A release
   Fountain does not index therefore stops the crawl on the render that
   actually POSTed (the new "stored id is live, give up early" branch) but
   re-enters self-heal once that miss is being served from cache - i.e. the
   crawl can still repeat every 15 minutes for such releases. Making the
   negative cache remember *whether* it was parsed (`''` vs a marker) would
   close it; only worth it if the crawl cost ever matters.

**2026-10-05 review (Fountain working again - `fountain_live_test.php`
20/20 on the 1.22.24 / 1.20.30 release):** with causes 4 and 5 fixed and
stored links (1.22.17 / 1.20.23) covering every release that has rendered
once, this item now only concerns the *first* resolution after a redeploy.
Still live and unchanged: 1 (= O6), 2, 3 (typical cost only - see above),
6, 7, 8. Cause 3's timeout-discards-the-work hazard remains the sharpest
one, because the throttle is already committed by then (cause 2). No fix
picked yet - see the triage question below.

**Plan sketch (options identified, none picked):** ~~validate-on-200 and
persist regardless of our guid (1+4)~~ done 2026-10-05;
~~return a candidate list tried in order until one yields a readable hits
payload (5)~~ done 2026-10-05 (that was O11); arm the throttle
after a completed attempt with a 30-60s backoff on failure, keeping 15
minutes only after a conclusive scan (2); time-box the crawl and run it
via `fastcgi_finish_request()`/`shutdown` so a page timeout can't eat the
attempt (3); record every attempt - timestamp, stage reached, status,
candidate - which doubles as the O5 settings readout (6, diagnosis); a
cron-driven heal so recovery doesn't depend on traffic (6); positive
revalidation policy (7, shared with O6's open question).

**Mitigated (not fixed) by the stored-links work:** Resolver 1.22.17 /
Pro 1.20.23 make an already-resolved release immune to everything above -
its button URL now comes from the database, not from the hash, a
transient, or the self-heal - so the observed "homepage for 30+ minutes"
symptom can no longer recur for releases that have rendered once since
the upgrade. This item still matters for the *first* resolution after a
redeploy, which no stored row covers.

**Blocked on:** the user confirming which symptom is observed - plain
`fountain.fm` fallback vs a deep link Fountain rejects.

---

## O9. The live Fountain probes hardcode a rotated `next-action` token, so they now 404

**Status: On hold** (user deferred 2026-10-05 - fixture work not picked;
the plan below is current as of that date).

**What:** `tmp/fountain_candidate_test.php` and `tmp/fountain_case_test.php`
both POST to `https://fountain.fm/search` with a **hardcoded** `next-action`
header - `$SID` = `605af9c39c662ad04c72e52279d6db26f12973e250`
(`fountain_case_test.php` line 13) and a fixed candidate list passed to
`resolve_with()` (`fountain_candidate_test.php`). On the 2026-09-26 release
run both returned HTTP 404 with body `Server action not found.` on *every*
request (re-run once, identical), i.e. Fountain has rotated the action hash
those fixtures were captured against.

**This is a stale fixture, not a plugin bug.** `tmp/fountain_live_test.php`
autodetects the current next-action from the `/search-page` chunks exactly as
the shipped `fountain_autodetect_next_actions()` does, and it passed 20/20
against the same endpoint in the same run - so the production search path is
fine. Neither failing probe loads plugin code for its request either.

**Re-verified 2026-10-05 (Fountain working again - `fountain_live_test.php`
20/20 on the 1.22.24 / 1.20.30 release):** every hardcoded-token file still
404s on every POST, `Server action not found.` -
`fountain_case_test.php` (all 4 requests; its deliberate bogus-token
assertion at line 80 still passes as a 404),
`fountain_candidate_test.php` (both entries of the fixed list: `605af9c3…`
and `40654cc3…`), and a **third** live file this item did not know about:
`fountain_extract_verify.php` (`$SEARCH_ID` line 14, curl POST to
`/search` - 404 on both header variants it tests). The working ids that day
were `60f00dce…`, `60f643a9…`, `40089971…`, `60944205…` - rotation
continued, the fixtures did not. `fountain_search_matrix.php` and
`fountain_shape_matrix.php` also carry the old hex but make no network
calls (offline parsing fixtures) - they need no change.

**Plan sketch:** make the three live probes detect rather than embed - reuse
the chunk scan `fountain_live_test.php` already has (or call the plugin's
`fountain_autodetect_next_actions()` and feed one candidate from its list
into the `next-action` header - since 1.22.24 / 1.20.30 it returns a list,
and the plugin itself probes rather than trusts it);
keep the bogus-token request `fountain_case_test.php` already makes as the
deliberate stale-hash 404 assertion. Fold "the token must be detected, not
captured" into O4's `tests/` move so the fixtures cannot rot silently again.

---

## O10. Fountain now publishes an official API - the plugin still uses the internal `/search` server action

**Status: On hold** (user deferred 2026-09-26, after the unauthenticated
probe below; no code changes made, re-open only on request).

**2026-10-05 note:** nothing is blocked on O10 - the O11 fix shipped
through the internal server-action path instead (probe every detected
candidate instead of trusting the first, Resolver 1.22.24 / Pro 1.20.30),
so the official API stays a "delete this whole redeploy-breaks-us class"
option rather than a dependency. The one remaining unknown is unchanged:
whether a real key returns album/track hits carrying `_guid` (one
authenticated call).

**What:** while reviewing the project against Fountain's Terms of Use
(`https://fountain.fm/terms-of-use`, last updated 2026-09-16) it turned out
Fountain now ships a documented public API that did not exist when this
plugin was written:

- `POST https://api.fountain.fm/v1/search/content` with
  `Authorization: Bearer $FOUNTAIN_API_KEY`, body `{"query":..., "types":["SHOW","EPISODE"]}`
  (docs: `https://fountain.fm/docs/endpoints/search`, markdown via
  `https://fountain.fm/llms.txt`; key/FAQ: `/faq/api-keys`; MCP at
  `https://api.fountain.fm/v1/mcp`).
- The 200 payload is `hits[]` carrying `_id`, `_type`, `_guid` and
  `info.title`/`subtitle`/`publisher` - i.e. **the same fields
  `extract_fountain_hit()` already parses** (standard ~line 1834, Pro ~3548).

The plugin instead POSTs to the **undocumented internal Next.js server
action** `https://fountain.fm/search` with a detected `next-action` hash,
`Origin`/`Referer` of fountain.fm and a spoofed Chrome 151 User-Agent
(`fountain_try_resolve()` standard ~1596, Pro ~3310), plus a self-heal crawl
of 2 seed pages and up to ~40 `/_next/static/*.js` chunks to rediscover that
hash (`fountain_autodetect_next_actions()` standard ~1714, Pro ~3428).

**Compliance read (not legal advice):** no clause of the ToS is plainly
breached - §10 bans "disrupt[ing] or exploit[ing] the platform" but says
nothing about automated access, `robots.txt` is `Allow: /`, the plugin is
unauthenticated (so §11's account/agent authorisation is not engaged), holds
no account to suspend (§3/§14), and request volume is low, cached (30-day
positive / 1-hour negative transients) and throttled (15-minute self-heal).
The honest description is **"not prohibited, but now off the sanctioned
path"**: the spoofed browser UA and the reliance on a reverse-engineered
build hash are the two soft spots (§10's undefined "exploit"; a UA-based
block later would be inconvenient to answer honestly).

**Probe results (2026-09-26, unauthenticated):**
- `POST https://api.fountain.fm/v1/search/content` with no key **and** with a
  bogus `Bearer` both return `403 {"ok":false,"error":{"code":"FORBIDDEN",
  "message":"Not authorized."}}` - a key is required; the two cases are
  indistinguishable. Cloudflare in front, `access-control-allow-origin: *`,
  **no rate-limit headers** on the 403.
- Keys: created from `fountain.fm/studio/projects`, prefix `fountain_`, sent
  as `Authorization: Bearer <key>`, base `https://api.fountain.fm/v1`.
- Cost/volume: FAQ `/faq/api-keys` - *"API and MCP calls are unlimited on
  every plan, so there is no metering on the calls themselves"*; pricing page
  - *"Drafts, searches, and API and MCP calls are free"* (no credits burnt).
  Plans are Lite $25/mo, Pro $50/mo, Custom - so a project sits behind a
  podcaster/artist account, not a free listener account (unconfirmed).
- Scope: `POST /search/content` body is only `query` / `types` / `parent` -
  **no project parameter**, and the docs describe it as "Search across
  Fountain: content, transcripts, and Nostr profiles", so the index read is
  global rather than limited to the caller's connected shows (needs one real
  call to confirm).
- `types` accepts `SHOW|EPISODE|ARTIST|ALBUM|TRACK|CLIP` - i.e. albums and
  tracks, exactly this plugin's domain.
- `ContentHit` = `_id` (`{type}:id:{id}`), `_type` (uppercase), `_guid`
  (**optional** - "Podcast GUID, when the content originates from an RSS
  feed"), `info.title`/`subtitle`/`publisher`/`audio`/`video`/`duration`,
  `links`.
- OAuth alternative for agent flows: `authorization_code` + PKCE (S256),
  public client (`token_endpoint_auth_methods: ["none"]`), endpoints
  `/v1/oauth/authorize` and `/v1/oauth/token`, protected resource `/v1/mcp`.

**Fit with the existing code (from reading, not yet executed):**
- `fountain_path_segment()` (std ~1877) already maps uppercase
  `TRACK`/`ALBUM`/`EPISODE`/`SHOW` - compatible with the official `_type`.
- `extract_fountain_hit()` matches on `_guid` (std ~1834) - but the official
  `_guid` is optional, so a hit without one would never match; needs a
  fallback (match on `info.title`/`subtitle`, or `_id` shape) plus a probe to
  see whether album/track hits actually carry `_guid`.
- The URL is built as `https://fountain.fm/{segment}/{_id}` (std ~1468-1471),
  but official `_id` is `episode:id:abc123` - the `{type}:id:` prefix must be
  stripped before use.

**Plan sketch:**
- ~~Probe the official endpoint~~ done 2026-09-26 (above); the remaining
  unknowns - does a real key return album/track hits with `_guid`, and does
  search return content the caller does not host - need one authenticated
  call, i.e. an actual Fountain project + API key.
- If usable: add an optional "Fountain API key" setting (server-side only,
  never printed into page markup), call the official endpoint from
  `fountain_resolve()`, and map `hits[]` into the same `_guid`/`_id`/`_type`
  shape so `extract_fountain_hit()` and `fountain_path_segment()` stay as-is.
- Keep the current server-action path as an automatic fallback when no key
  is configured, so existing installs don't regress; this also gives a
  second answer to O7 (self-heal churn disappears for keyed installs).
- Both plugins, unchanged per-plugin keyspaces. Remove this item when the
  API path ships (or when the user declines it).

---

## O13. "Flush Music Index data" action on the Cache tab

**Status: On hold** (user deferred 2026-10-03 - declined as part of the
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

**Status: Planned** (user requested 2026-10-04; they asked for the design
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

**Status: On hold** (user deferred 2026-10-05 - scope and precedence
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

## O19. Standard version: shortcode generator with Music Index search (block-editor sidebar)

**Status: Planned** (user picked this 2026-10-05 with the search +
cache-write requirements; placement and header-control decisions added
the same day - see Decisions below; implement only on request).

**What:** a builder in the RESOLVER (standard) plugin that assembles
`[musiclink album="…" track="…" header="…"]` for copy-paste, fed by a
Music Index search box: the admin types **title text**, results come back
from `https://api.musicindex.org/v1/search` (guids, resolved to display
text), and picking a hit supplies the shortcode's guid attributes.
**Decided 2026-10-05: picking a result also populates the cached links** -
the chosen release is upserted into `{prefix}v4v_musiclink_cache` so the
Cache tab shows it immediately (titles/artist/art stamped;
`fountain_url` stays `''`, which by existing cache-row semantics makes the
first render run the Fountain search - unchanged behaviour). The user
named two possible homes - page/post edit or the plugin's Settings screen
- placement is open decision 1.

**Why:** the attribute contract has sharp edges hand-typing trips over:
standard ignores **all** attributes if any name is unexpected (the
`$unexpected` whitelist std **1636**), `guard_guid()` silently drops values
over 256 bytes (std **1625**), and a missing `album` renders the usage
message instead of the release (std `render()` 1646+). The GUIDs already
sit in the Cache tab (`Album GUID` column header std **1172**, row fields
`album_guid`/`track_guid`) but only for releases resolved at least once,
and must be copied across by hand; searching by title removes both the
GUID hunt and the chicken-and-egg.

**Search - port from Pro, admin-side:** standard already has
`MI_ROOT`/`MI_API_BASE` (std **101-102**) and `mi_api_get()` (std **2676**)
but no search machinery at all. The Pro pieces to port: `mi_search_hits()`
(pro **3354-3416** - cursor batches of `/search?q=&limit=`, per-query
transient, dedupe, relevance order preserved), `ajax_search()` (pro
**3468+**; its docblock records that *"search results only contain guids,
not display text"*, so each hit is resolved via `mi_get_track()`/
`mi_get_feed()` into `album_guid`/`track_guid` + title/subtitle),
`search_hit_key()` (pro **3324**), the `SEARCH_*` constants (pro **217-221**;
standard has none), the nonce (pro **2582**), and the box UI pattern from
`render_search_ui()` (pro **3289**). Differences for the generator:
- **Admin-only endpoint** - `wp_ajax_v4v_musiclink_search` with **no
  `nopriv` twin**, gated by `current_user_can( 'manage_options' )` +
  nonce. Pro's relay needs a per-IP rate limit precisely because its
  nonces print on public pages; the generator's never do.
- **No front-end search mode** - README:22 stays true; standard's
  visitor-facing behaviour remains the usage message. This search exists
  only in the admin builder.

**Cache write - reuse, don't reinvent:** `mi_store_mi_fields()` std
**2411-2437** is the existing `INSERT … ON DUPLICATE KEY UPDATE` upsert
keyed by `cache_row_hash()`, stamping titles/artist/art + `mi_updated_at`
and invalidating `row_memo`; the chosen hit's detail payload feeds it
directly (kind `track` or `feed`). No Fountain work happens at choice time.

**Sites (standard only, per request):**
- Builder home (per decision 1): block-editor sidebar registered from
  `enqueue_block_editor_assets` - no precedent in this plugin (zero
  editor code today): `registerPlugin` + `PluginSidebar` (+ More-menu
  item) in a small admin-only script; enqueue gate = block editor only.
- Optional extra: per-row "copy shortcode" action on the Cache tab
  (std ~1172 row), which already renders both GUIDs.
- No settings-screen box, no classic-editor Quicktags/media button
  (decision 1) - classic-editor posts simply have no builder UI.
- Clipboard pattern to reuse: Pro's share button
  (`v4v-musiclink-pro-share-btn`, pro **2908-2923** -
  `navigator.clipboard.writeText()` with a transient "copied" label);
  standard has no clipboard code yet.
- The builder must emit only whitelisted attribute names (std 1636/1637)
  and will gain `fountain` (O18) once that ships.

**Decisions (2026-10-05):**
1. Placement: **block-editor sidebar**. The builder lives in the
   Gutenberg post editor: `enqueue_block_editor_assets` registers a
   small admin-only script (`registerPlugin` + `PluginSidebar`, plus the
   More-menu item), no settings-screen box and no classic-editor UI; the
   optional per-row Cache-tab "copy shortcode" action stays an extra
   only if later requested. Nothing loads on the front end.
2. Header control: a **3-state select - "Site setting" (default),
   "Always Show", "Always Hide"** - where Site setting OMITS the
   attribute entirely (render()'s existing absent/unrecognized fallback
   = the admin "Release header" setting), Always Show emits
   `header="true"`, Always Hide emits `header="false"`. No change to the
   shortcode attribute contract. The `fountain` attribute (O18) joins
   the builder when that ships.

**Plan sketch:** block-editor sidebar - a title-text search input
(debounced; query via an admin-only endpoint - REST route or
admin-ajax, `manage_options` + nonce - proxying `mi_search_hits()`,
each guid-only hit resolved server-side to display text), a results list
whose chosen row fills the builder's guid fields **and upserts the cache
row via `mi_store_mi_fields()`**, the 3-state header select, a
live-assembled `[musiclink …]` preview, a copy button ported from the
Pro share pattern (`navigator.clipboard.writeText()` + transient
"copied" label) and/or insertion as a `core/shortcode` block at the
caret, client-side hints for the 256-byte cap and unknown attribute
names; optionally a "copy shortcode" action on Cache-tab rows reusing
the GUID cells already in the row; harness assertions that AJAX/REST
rejects unauthenticated/cross-site calls, only whitelisted attribute
names can appear in the emitted string (Site setting must omit `header`
rather than emit a sentinel value), and a chosen result lands as a cache
row with `fountain_url = ''`; README usage line updated. The mechanics
were explained to the user 2026-10-05 (registration, search flow,
selection + cache write, insert-vs-copy, security model).

---

## O21. Standard version: remove automatic Fountain deep-link discovery (Pro keeps it)

**Status: Planned** (user picked 2026-10-05 - "plan to remove the ability
to automatically find the fountain deep from the standard version";
implement only on request).

**What:** the STANDARD plugin stops resolving Fountain deep links itself.
It becomes Pro-only; standard never issues HTTP to fountain.fm again.

**Remove (std only):**
- `fountain_resolve()` (std 1794), `fountain_try_resolve()` (std 1861 -
  the `wp_remote_post` to `fountain.fm/search`), the constant fallback
  `V4V_MUSICLINK_FOUNTAIN_NEXT_ACTION` (std 1796),
  `fountain_autodetect_next_actions()` (std 1997 - 15-min throttle +
  chunk crawl + self-heal `update_option` writes), and
  `fountain_scan_chunks_for_actions()` (std 2086).
- `fountain_negative_cache_ttl()` (std 1950) and its fountain negative
  transients; `fountain_path_segment()` (std 2179 - its only caller is
  the resolve path). The FAIL/SOFT cache TTL constants stay: Music Index
  misses still use them.
- `store_fountain_link()` (std 825) and its single call site (std 1700 -
  the write-on-hit in `render()`); `fountain_calls()` expectations flip
  to always-empty for std.
- The settings field "Fountain next-action header" (std 617-635) and
  every read/write of the `v4v_musiclink_fountain_next_action` option.
  Stale option value: leave orphaned (harmless) unless a `delete_option`
  cleanup is wanted on upgrade - minor open point.

**Keep (std):**
- `get_cached_fountain_url()` read path - a cached deep link keeps
  rendering (manual Cache-tab entries, rows written by earlier versions).
- `render()`'s O15 quoted-search fallback and the empty-text
  `fountain.fm/discover` default; the confirm-modal + launch logging;
  Cache-tab `fountain_url` editing (manual is now the ONLY way standard
  gets a deep link); all MI machinery; the whole Pro plugin unchanged.

**Resulting standard behaviour:** cached deep link if one exists on the
row, otherwise the Fountain search fallback for the release - one button,
zero background crawls, no fragile unofficial-Server-Action dependency in
the free tier. `fountain_resolve()` shape in `render()`: cached branch
stays, the else-branch loses resolve/store and falls straight through to
the O15 fallback.

**Interactions:** O18's `fountain=` attribute becomes standard's only
programmatic deep-link source; O15/O9/O10 unaffected for Pro.

**Docs:** standard plugin header Fountain section (std ~19-28, ~55-63),
settings help text, README standard section + Target-apps wording
(mark auto-resolution as Pro-only), CHANGELOG "Removed" entry at release.

**Implementation steps (ordered):**
1. `render()` std: collapse the Fountain block to
   `cached ? cached : O15-search-fallback`. Keep the
   `$fountain_search_text` computation (track title, else album title,
   else `''` - the `''` case keeps the discover default), delete the
   `fountain_resolve()` call, the hit branch and the
   `store_fountain_link()` call; rewrite the comment above the block
   (currently references `FOUNTAIN_NEXT_ACTION`).
2. Delete the discovery machinery: `fountain_resolve` (1794),
   `fountain_try_resolve` (1861), `fountain_negative_cache_ttl` (1950),
   `fountain_autodetect_next_actions` (1997),
   `fountain_scan_chunks_for_actions` (2086), `extract_fountain_hit`
   (2136), `fountain_path_segment` (2179) - roughly contiguous std
   1794-2200 - plus `store_fountain_link()` (825), the
   `$last_fountain_status` property (169) and its docblock (~165).
   KEEP `get_cached_fountain_url()` (801) and the FAIL/SOFT TTL
   constants (Music Index still uses them).
3. Settings: delete the whole "Fountain next-action header" `<tr>`
   (~614-638) AND its `register_setting()` entry + sanitize callback
   (~227-231). The stale `v4v_musiclink_fountain_next_action` option
   value stays orphaned in the DB - nothing reads it, and post-O17 there
   is no migration machinery to hook a `delete_option` into (not worth
   inventing one for a dead key).
4. Standard file header: drop the a)-d) next-action capture
   instructions + self-heal paragraph (~13-28) and the "Falls back to ...
   self-heal" paragraph (~50-63); same for any remaining docblock that
   describes background crawling.
5. Grep audit, expect zero remaining hits in std outside deliberately
   kept comments: `fountain_resolve|fountain_try_resolve|autodetect|
   extract_fountain_hit|path_segment|store_fountain_link|next-action|
   last_fountain_status|FOUNTAIN_NEXT_ACTION`.
6. Harness rework (below), full verification, README edits.

**Harness rework (verified against the current files):**
- `tmp/test_stored_links.php` (both-class loop): introduce
  `$can_resolve = 'V4V_Music_Link_Resolver_Pro' === $class`.
  - Pass for std unchanged: **S1** (cached link, no HTTP expected),
    **S3** (search fallback + never stored - already the O15 shape),
    **S5** (`$warm_resolve()` is a no-op for std; it already asserts no
    fountain HTTP + no write), all A (admin-post edit/delete - manual
    editing stays), B/H/M/R sections.
  - Branch on `$can_resolve`: **S2** - pro keeps its upsert assertions;
    std asserts blank link renders the O15 search URL, `fountain_calls()`
    empty, and ZERO link-half `ON DUPLICATE KEY UPDATE` statements (vs
    pro's `1 === count($link_upserts)`). **S4** - pro keeps re-search +
    fill-in; std asserts the row stays blank + fallback rendered + no
    write. **S6** - pro keeps `$deep` on the track render; std expects
    the search fallback instead (the album-row manual link NOT being
    reused already passes for std).
- `tmp/fountain_fix_test.php`: its `$classes` loop reflectively invokes
  `fountain_try_resolve`/`fountain_autodetect_next_actions`/
  `fountain_resolve` on BOTH classes (line 154-165) - std entries must
  go (methods deleted). Optionally precede the Pro loop with two std
  checks that `method_exists(..., 'fountain_resolve')` is false, so the
  removal itself is pinned. Expect ~156 -> ~80 assertions.
- `tmp/fountain_live_test.php`: drop the 2 `V4V_Music_Link_Resolver`
  rows from `$cases` (117-121) - standard has nothing to resolve. Pro's
  2 rows keep their 10 checks each (20 -> 10 expected PASS lines).
- `tmp/test_app_launch.php`: verified unaffected (fountain app is
  `enabled => false` in its fixture).
- `tmp/probe_woman.php`: diagnostic (not in the 14-file harness loop)
  that calls the standard class's resolve - annotate its header as
  Pro-only/obsolete; not a gate.
- e2e (`gen_page.php`) instantiates the PRO class only - unchanged.
  mi_ttl / search_pagination / canonical_guid / v4vmusic_url / log_* /
  missing_params: no std-fountain coupling (confirm by running).

**Verification:** `./php/php -l` both files; all 14 harnesses (expect
movement: stored_links net −/+, fix_test ~80, live 10 - record actuals);
`./php/php tmp/e2e/gen_page.php`; grep audit from step 5.

**Docs (README now, CHANGELOG at release):** standard plugin header +
settings help already handled in steps 3-4; README - Target-apps Fountain
row must split: auto-resolution/self-heal = Pro only, standard = cached or
manual link else the quoted search fallback; CHANGELOG "Removed" entry
for Resolver, untouched Pro entry.

---

## Closed decisions (context only - not work items; do not re-open or re-propose)

- **O20 live-harness 404s** (closed 2026-10-05): duplicate of O9 - the
`fountain_candidate_test`/`fountain_case_test` 404s are stale hardcoded
`next-action` fixtures against fountain.fm (the originally recorded
`api.musicindex.org` host was wrong); O9's detect-don't-embed plan is
the work. Re-open only to triage that plan.
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
