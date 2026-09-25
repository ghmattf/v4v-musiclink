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

Line numbers below are as of 2026-09-25 (Resolver 1.22.15 / Pro 1.20.21);
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
`fountain_fix_test.php`, `mi_ttl_test.php`, `v4vmusic_url_test.php`,
`fountain_live_test.php` (**live network** - hits fountain.fm, can
flake/timeout; treat as informational and re-run once before concluding),
`missing_params_heading_test.php`, `canonical_guid_test.php`, plus the
browser e2e in `tmp/e2e/` (`gen_page.php`, `router.php` - captured HTML
files in that dir are scratch). Baseline as of 2026-09-25: 10 harnesses,
487 assertions, all green.

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

## O6. Stale "social-preview meta tags" wording in the standard plugin

**Status: Open** (identified 2026-09-25 during the Telegram investigation).

**What:** the standard plugin's `resolve_release_meta()` docblock
(sentence at ~line 652, function at 655) says its data feeds "the
social-preview meta tags below" - but the standard plugin emits no social
meta at all (removed before 1.22.x; confirmed: this docblock is the only
surviving trace). One-line docblock fix. While there, check the same
sentence in the Pro docblock (~line 2155): Pro's social-tag producers
(`output_social_preview_meta()`, `render_meta_tags()`) sit *above*
`resolve_release_meta()` (line 2158), so "below" is directionally stale
there too.

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
