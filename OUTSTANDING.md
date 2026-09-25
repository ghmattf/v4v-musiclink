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

Line numbers below are as of 2026-09-25 (Resolver 1.22.14 / Pro 1.20.20);
they will drift - re-locate by symbol name.

---

## P1. Favicon: contact the app's own domain, not Google

**Status: Planned** - picked 2026-09-25 ("Privacy URL", part 1). Do not
implement until the user gives the go-ahead.

**What:** `favicon_url()` returns
`https://www.google.com/s2/favicons?sz=128&domain=...` in both plugins
(Pro `favicon_url()` ~line 2074, standard ~line 639). It is the *only*
place either plugin mentions Google (verified: the `google.com/s2` literal
exists exactly once per plugin), and every consumer flows through it:
`get_app_icon()` in both plugins, which feeds (a) the visitor-facing app
buttons in `render_html()` when the admin has not set an `icon_url`
override, and (b) the settings pages' default-icon previews. So today every
page view makes the visitor's browser contact Google (IP + page origin
leak to a third party).

**Why:** user directive - avoid Google contact where possible; use the
music app's domain for favicons.

**Plan:**
1. In both plugins, change `favicon_url( $domain )` to return
   `https://<domain>/favicon.ico`. `$domain` comes from the hardcoded
   `app_registry()` (stablekraft.app, podtunes.app, v4vmusic.com,
   fountain.fm) - never user input.
2. Before coding, `curl -sI https://<domain>/favicon.ico` for all four
   domains. If one does not serve a favicon: do **not** fall back to
   Google; decide with the user between (a) leave it and document the
   existing per-app `icon_url` override as the fix, or (b) hide the icon
   `<img>` on error (`onerror` handler in both `render_html()` - a markup
   change). Default plan is the plain swap; the fallback question only
   arises if a domain 404s.
3. Update the `favicon_url()` docblock ("avoids storing/reproducing any
   app's logo assets ourselves" - still true, now also "no third-party
   contact") and skim settings help text for favicon wording.
4. Tests: extend a harness (e.g. `tmp/v4vmusic_url_test.php` or
   `tmp/canonical_guid_test.php`) asserting a rendered widget's icon `src`
   is `https://stablekraft.app/favicon.ico` etc., and that rendered output
   contains no `google.com/s2`, for both plugins. Note:
   `tmp/e2e/page.html` is old *captured* output still containing Google
   URLs - scratch, regenerate or ignore. No PHP harness asserts Google
   URLs today.
5. Release: `./bump-version.sh both` (both plugins change), CHANGELOG
   entries, commit + tags per the AGENTS.md release flow, then remove this
   item.

**Not affected:** `site_logo_or_favicon_url()` (og:image fallback) uses the
site's own logo / `get_site_icon_url()`; admin-set `icon_url` overrides are
stored values, not computed - no data migration.

---

## P2. Admin setting: turn interaction logging on/off

**Status: Planned** - picked 2026-09-25 ("Privacy URL", part 2). Do not
implement until the user gives the go-ahead.

**What:** a new Pro settings checkbox, **enabled by default**, that stops
all interaction logging (search-result opens + app launches) when off.
Pro only - the standard plugin has no logging at all.

**Plan:**
1. Option `v4v_musiclink_pro_log_enabled`, stored `'1'`/`'0'`, read via a
   `logging_enabled()` helper returning
   `(bool) get_option( 'v4v_musiclink_pro_log_enabled', 1 )` - absent
   option == enabled, so fresh installs and every existing site keep
   logging until the admin opts out.
2. Register it next to the other log settings in `register_settings()`
   (`v4v_musiclink_pro_log_app_rate` ~line 1031) with a
   `sanitize_log_enabled()`-style callback, matching the existing
   `sanitize_log_rate()` (~1060) / `sanitize_log_max_rows()` (~1079)
   pattern.
3. Honor it at three points:
   - `ajax_log_open()` (~line 397): after the nonce check, if disabled ->
     `wp_send_json_success()` immediately: no rate-limit budget consumed,
     no Music Index lookup, no write. Success (not error) because the
     client is fire-and-forget `sendBeacon` and a disabled feature is not
     a failure.
   - `ajax_log_app()` (~line 435): same early return.
   - `log_view()` (~line 312): guard on the first line as the write-path
     choke point, so any future caller is covered too.
4. Settings UI: checkbox in the log-settings block beside the rate/row-cap
   fields (settings HTML ~lines 1488-1555), label along the lines of
   "Log interactions (search-result opens and app launches)", help text:
   on by default; stores artist/album/track/app/timestamp only (never IP);
   turning it off stops new rows (existing rows remain until Clear log or
   pruning); the rate and row-cap fields only apply while logging is on.
5. Tests: extend a tmp log harness (`tmp/test_log_limits.php` or
   `tmp/test_log_scope.php`): option `'0'` -> both beacons return success
   with 0 inserts and 0 Music Index calls; option `'1'` and option absent
   -> logging behaves exactly as today (all existing assertions must pass
   untouched, since they never set the option -> default on).
6. Release: Pro only (`./bump-version.sh pro`), or combine with P1 in a
   single `both` release if implemented in the same session. Remove this
   item when shipped.

---

## O3. View Log timestamps are shifted (`current_time` vs `get_date_from_gmt`)

**Status: On hold** (user deferred 2026-09-25).

**What:** `log_view()` stores `current_time( 'mysql' )` - site-**local**
time (Pro ~line 317) - but the View Log table prints
`get_date_from_gmt( $row->logged_at, 'Y-m-d H:i:s' )` (~line 963), which
treats that string as GMT and converts it to local again. On any site not
at UTC the View Log shows times shifted by the site's GMT offset. Reports
are already consistent (`get_report_date_bounds()` ~line 679 computes
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
'/wpstub/'`), `test_log_limits.php`, `fountain_fix_test.php`,
`mi_ttl_test.php`, `v4vmusic_url_test.php`, `fountain_live_test.php`
(**live network** - hits fountain.fm, can flake/timeout; treat as
informational and re-run once before concluding),
`missing_params_heading_test.php`, `canonical_guid_test.php`, plus the
browser e2e in `tmp/e2e/` (`gen_page.php`, `router.php` - captured HTML
files in that dir are scratch). Baseline as of 2026-09-25: 9 harnesses,
418 assertions, all green.

**Plan sketch:** create tracked `tests/`; `git mv` the nine harnesses plus
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
transient (Pro ~line 2683), and the stored `..._fountain_next_action`
option. If the background self-heal keeps failing, nobody can see that it
ran, when, or with what result.

**Plan sketch:** persist an attempt record - option
`v4v_musiclink(_pro)_fountain_last_attempt` = timestamp + HTTP status -
whenever `fountain_autodetect_next_action()` runs (standard ~line 1038,
Pro ~line 2713); render a read-only line next to the "next-action" field
on each settings page (standard ~line 472, Pro equivalent): "Last attempt:
2026-09-25 12:00 - status 404" or "never attempted". Both plugins. A
manual "Test now" button would be a scope extension - only if the user
asks.

---

## O6. Stale "social-preview meta tags" wording in the standard plugin

**Status: Open** (identified 2026-09-25 during the Telegram investigation).

**What:** the standard plugin's `resolve_release_meta()` docblock
(~lines 643-648) says its data feeds "the social-preview meta tags below" -
but the standard plugin emits no social meta at all (removed before
1.22.x; confirmed: its former "social-preview meta tags" docblock at line
646 is the only surviving trace). One-line docblock fix. While there, check
the same sentence in the Pro docblock: Pro's social-tag producers
(`output_social_preview_meta()`, `render_meta_tags()`) now sit *above*
`resolve_release_meta()`, so "below" is directionally stale there too.

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
