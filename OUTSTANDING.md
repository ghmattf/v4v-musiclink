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

Line numbers below are as of 2026-10-01 (Resolver 1.22.20 / Pro 1.20.26);
they will drift - re-locate by symbol name.

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
captured HTML files in that dir are scratch). Baseline as of 2026-10-04
(Resolver 1.22.21 / Pro 1.20.27): 14 files - the 11 offline harnesses are
green (848 counted assertions) and `tmp/e2e/gen_page.php` self-checks pass;
`fountain_live_test` is 0/20 until O11 is fixed, and the two live Fountain
probes still print nothing per O9.

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
whenever `fountain_autodetect_next_action()` runs (standard ~line 1030,
Pro ~line 2756); render a read-only line next to the "next-action" field
on each settings page (standard ~line 472, Pro ~line 1508): "Last attempt:
2026-09-25 12:00 - status 404" or "never attempted". Both plugins. A
manual "Test now" button would be a scope extension - only if the user
asks.

---

## O6. Fountain positive cache is keyed on the `next-action` hash, so a redeploy busts every saved link

**Status: Planned** (user picked it 2026-09-26; implement only on request).

**What:** `fountain_try_resolve()` caches a successful resolution for 30
days under `'fountain_id_v5_' . md5( $guid . '|' . $next_action )`
(standard `v4v-musiclink/v4v-musiclink.php` key ~line 916,
`set_transient()` ~line 976; Pro `v4v-musiclink-pro/v4v-musiclink-pro.php`
key ~line 2642, `set_transient()` ~line 2704, prefix
`fountain_id_pro_v5_`). The cached value is the hit (`id` + `_type`),
which depends only on the guid - but because the hash is part of the
key, a Fountain redeploy changes the key and invalidates every saved
success. Each already-resolved release then falls back to a POST with
the now-stale hash, gets a 404, and renders the plain `fountain.fm`
link until self-heal succeeds. Second-order effect: whether self-heal
runs at all becomes luck of the draw - a cache hit under the old hash
returns early from `fountain_resolve()` (standard ~line 880) and never
reaches the self-heal branch, while a miss under the same old hash
triggers the full crawl.

**Plan sketch:**
- Key positives on the guid alone - `fountain_id_v5_<md5(guid)>` /
  `fountain_id_pro_v5_<md5(guid)>` - and keep storing `id` + `_type`,
  rebuilding the URL each render through `fountain_path_segment()`
  (standard ~line 792) rather than caching the raw URL, so a future
  path-prefix change is still picked up from code. The hash becomes what
  it really is: a one-time-per-release discovery credential, needed only
  for releases never seen before.
- Legacy migration: on a guid-key miss, probe the legacy
  `md5( guid . '|' . configured-hash )` entry and re-store it under the
  guid key. During the stale window the option still holds the old
  hash, so pre-redeploy entries stay reachable and can be rescued
  instead of being left to age out.
- Split the negatives by what they mean - today the hash in the key
  accidentally supplies the scoping, and that must survive explicitly
  once the positive key drops it: a `404` means *the hash is stale* and
  says nothing about the guid -> hash-scoped, short TTL, never recorded
  against the guid; a `200` whose readable hits payload lacks our guid
  means *Fountain doesn't have this release* -> guid-scoped, keep
  `FAIL_CACHE_TTL` (1 hour).
- Both plugins, unchanged per-plugin keyspaces.

**Open question:** if Fountain's *deep URLs* themselves break on a
redeploy (rather than just the search hash), a guid-keyed positive would
be trusted for 30 days with no revalidation and would need a
"re-verify positives older than N days" rule. Resolve by confirming the
observed symptom: plain `fountain.fm` fallback (this fix is complete on
its own) vs a deep link Fountain rejects (revalidation policy needed).

**Not covered:** this does not speed up the *first* resolution of a new
release after a redeploy - that is O7.

---

## O7. Fountain self-heal is slow to recover (or never fires) - diagnosis

**Status: Open** (identified 2026-09-26, awaiting triage).

**What:** why, once self-heal is required, the button takes far too long
to start working again. Causes found this turn in `fountain_resolve()` /
`fountain_autodetect_next_action()` (standard lines 874-902, 1029-1125;
Pro lines 2600-2630, 2755-2848), roughly ranked:

1. Positive cache keyed on the hash - see O6 (also makes *whether*
   self-heal runs depend on which guid renders first).
2. The 15-minute throttle is armed **before** the attempt, unconditionally
   (standard ~line 1031-1034, Pro ~line 2756-2760), so any outcome -
   no candidates, unreachable seed page, aborted crawl - still costs a
   full 15 minutes of no effort, and consecutive failures stack.
3. The crawl runs synchronously inside the visitor's page render: 2 seed
   pages x 10s timeout + up to 40 chunks x 8s, plus the stale POST (8s)
   and retry (8s) ~ worst case ~5.5 minutes in one request. If a
   proxy/PHP/client timeout kills it before `update_option()`, the work
   is discarded while the already-committed throttle transient blocks the
   next 15 minutes.
4. `update_option()` only runs `if ( $result )` (standard ~line 893), so
   a *fresh* hash whose readable payload lacks this guid is never
   persisted - indistinguishable from "still stale" - producing a
   detect/fail loop.
5. Deterministic first-candidate short-circuit (standard ~line 1119):
   if the first near-"search" hex isn't the right action, detection
   returns the same wrong value forever until Fountain's chunk order
   changes.
6. Demand-driven only - no cron. Healing needs a render with a Fountain
   button that also passes the Music Index lookup (standard ~line 720
   returns early; MI "not found" is negative-cached for 1 hour), so on a
   quiet site attempts are naturally minutes-to-hours apart.
7. Unverified: site page caching may keep serving plain/stale HTML past
   the plugin's own heal.

**Plan sketch (options identified, none picked):** validate-on-200 and
persist regardless of our guid (1+4); return a candidate list tried in
order until one yields a readable hits payload (5); arm the throttle
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

## O9. The two live Fountain probes hardcode a rotated `next-action` token, so they now 404

**Status: Open** (identified 2026-09-26, awaiting triage).

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
the shipped `fountain_autodetect_next_action()` does, and it passed 20/20
against the same endpoint in the same run - so the production search path is
fine. Neither failing probe loads plugin code for its request either.

**Plan sketch:** make the two probes detect rather than embed - reuse the
chunk scan `fountain_live_test.php` already has (or call the plugin's
`fountain_autodetect_next_action()` and feed its result into `next-action`);
keep the bogus-token request `fountain_case_test.php` already makes as the
deliberate stale-hash 404 assertion. Fold "the token must be detected, not
captured" into O4's `tests/` move so the fixtures cannot rot silently again.

---

## O10. Fountain now publishes an official API - the plugin still uses the internal `/search` server action

**Status: On hold** (user deferred 2026-09-26, after the unauthenticated
probe below; no code changes made, re-open only on request).

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
hash (`fountain_autodetect_next_action()` standard ~1714, Pro ~3428).

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

## O11. Fountain's latest redeploy reordered the `next-action` ids, so self-heal now persists the WRONG one and every Fountain button falls back

**Status: Open** (identified 2026-10-01, awaiting triage).

**Symptom.** `tmp/fountain_live_test.php` went from 20/20 green (2026-09-26)
to **0/20**, reproduced on two consecutive runs (so not a network flake), for
BOTH classes: `fountain_resolve()` returns no hit and the auto-detected
`next-action` is never persisted (`... persisted via update_option(...)` ->
`none`). The offline suite still looks healthy - 708 offline assertions pass,
the 20 live ones do not - because those stub the HTTP layer, so this is
invisible to the release gate. On the front end every Fountain button
therefore renders the fallback link (the `https://fountain.fm/discover` link
this turn's release switches to), which is the user-visible symptom.

**Root cause (proven live 2026-10-01; repro script
`/tmp/opencode/diag_scan.py`).** Fountain redeployed, and in the shared chunk
`/_next/static/immutable/chunks/2fv5aivvlt_ba.js` the near-"search" hex ids
came back in a new order:

| candidate, in file order | `POST /search` with `["See Through",[]]` |
|---|---|
| `608818e35378be12b7e6c169407aa64d4cbffc6079` **(what the plugin picks)** | 200, 97 B, `{"content":[],"profiles":[]}` - no `hits` |
| `6028cb7b546a83379b6f77849aa2391bd7481bbddb` | 200, ~16-25 KB, `1:{"hits":[...]}` carrying the right `_guid`s (track `cbdaeaa0…`, album `d5c805fb…`) and `_type` TRACK/ALBUM |
| `4080494aa4b8eb5305e760d79786d38df872619dc8` | 200, 84 B, no `hits` |

`fountain_autodetect_next_action()` (std ~1719 / Pro ~3433) deliberately
returns the FIRST near-"search" hex and short-circuits, on the documented
assumption that "the searchContent registration is declared before
searchProfiles, so the first candidate is the right action" (docblock std
~1701-1704). The redeploy broke that ordering assumption: self-heal keeps
rediscovering the wrong action id, the retry produces no `hits` payload, so
`fountain_resolve()` (std ~1564 / Pro ~3278) never calls `update_option()`
and the state never recovers - the 15-minute throttle just repeats the same
wrong answer.

Everything else still works, verified live: the seed/delta scan order is
intact (search-page delta = 3 chunks, scan total 23, and scan #1 is already
the chunk holding all three ids), the endpoint/headers/body shape are valid,
and `extract_fountain_hit()` parses the good candidate's response fine.

**Plan sketch (pick one, or combine):**
- **Probe candidates instead of trusting the first**: let autodetect return
  ALL near-"search" candidates for a chunk and have `fountain_resolve()`
  try them in order, persisting the first that yields a parseable `hits`
  payload. Immune to any future reordering; costs one extra POST per
  redeploy (<= ~4 candidates in the observed chunk).
- **Or tighten selection**: accept a hex only when it sits next to the
  `searchContent` identifier rather than merely within 300 chars of the
  word "search" (the same chunk also carries `createServerReference` /
  `registerServerReference`, verified live). Cheaper, but repeats if the
  layout moves again.
- Either way `tmp/fountain_fix_test.php` (104 assertions encoding
  "first candidate wins" plus the D1-D4 scan-order matrix) and
  `tmp/fountain_probe.php` (still homepage-only, stale vs the plugin's
  /search-first order) need updating, then re-run
  `tmp/fountain_live_test.php` expecting 20/20.
- Until something is picked: buttons keep rendering the discover fallback,
  which is the mitigation this turn ships.

**Re-confirmed 2026-10-04** (release run for Resolver 1.22.21 / Pro 1.20.27):
`tmp/fountain_live_test.php` is still 0/20 on two runs; `fountain_resolve()`
returns NULL after a full autodetect+POST cycle (~6s), and the plugin's scan
now picks `6089300ccac831e642ad43ebadccbc79cc5798cebe` — a rotated id, same
chunk, same "first candidate wins" failure. The two probe fixtures (O9)
still 404 with `Server action not found.`, and a bare
`POST https://fountain.fm/search` with no/unknown action now answers 405 or
404 rather than 200. The 11 offline harnesses are green; this remains the
one live breakage, still untriaged as a fix.

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

## Closed decisions (context only - not work items; do not re-open or re-propose)

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
