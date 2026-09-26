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

Line numbers below are as of 2026-09-26 (Resolver 1.22.17 / Pro 1.20.23);
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
captured HTML files in that dir are scratch). Baseline as of 2026-09-26:
14 files, 655 counted assertions, all green.

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
