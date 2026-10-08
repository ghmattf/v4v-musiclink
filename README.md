# V4V MusicLink

An alternative to pod.link for music indexed by musicindex.org, providing direct links to the artist/album/song for Podcasting 2.0 music player apps such as fountain.fm, v4vmusic.com, stablekraft.app and podtunes.app.

The tool is a WordPress plugin provided in two versions. Both versions can run on the same WordPress instance without conflict.

## V4V Music Link Resolver (standard)

`v4v-musiclink/`

- Accepts `album` and (optionally) `track` GUID parameters within the shortcode, plus an optional `header="true|false"` attribute that shows or hides the release header for that one placement.
- Queries musicindex.org for album, track, artist, and art information and displays it on a page, with a button for each music player that takes the user directly to the album/track within that player.
- Admin settings cover the release header, the Music apps (enable/disable, front-end order, icon overrides, a per-app icon background colour or none, and an optional random order), the Music Index refresh interval, and a **Cache** tab that lists the cached release rows (date-only timestamps, tick/cross art status) and lets you edit a row's artwork URL or Fountain.fm link (deep links are admin-supplied here - this version never resolves them), launch the link, delete rows, or clear the Fountain links.

Usage:

```
[musiclink album="{album-guid}"]
[musiclink album="{album-guid}" track="{track-guid}"]
[musiclink album="{album-guid}" track="{track-guid}" header="false"]
```

A bare `[musiclink]` (or one with unexpected attributes) shows a usage message instead of guessing at intent. `header="true"` / `header="false"` overrides the site-wide "Release header" setting for that instance. There is no URL-parameter or front-end search UI mode in this version.

### Block editor (standard only)

For Gutenberg posts and pages, insert the **Music Link** block (administrators only). Its settings pane holds everything the shortcode accepts:

- **Search Music Index** - a **Search** button opens the search area on demand; type a title and press Search (or Enter), then pick a result: the area collapses again and the album/track GUIDs are filled in, with the release cached (it appears on the Cache tab immediately, before it has ever been viewed on the front end). Escape or Close discards the area without changing the block; the search only runs when you submit, never as you type.
- **Release details** - the album, track, and artist text for the current selection, read-only from the cache row (same cells the Cache tab shows); never editable here. Pairs not cached yet show em dashes plus a note.
- **Fountain.fm link** - reads and writes the same `fountain_url` cell as the Cache tab's "Fountain.fm link" field, so every shortcode of that release shares one link. The value must be an `https` URL on fountain.fm (or empty for the search-fallback button); there is no `fountain=` attribute.
- **Release header** - a three-state select: *Site setting* omits the attribute entirely (the site-wide setting applies), *Always Show* / *Always Hide* emit `header="true"` / `header="false"`.
- **Release GUIDs** - the raw GUID fields, for pasting by hand.

Once a release is selected, the block canvas shows it **exactly as the public page will**: a live preview served by the block's own `GET /render` route, which runs the real shortcode against the cache row - release header, track/album titles, artist, artwork, and the buttons in admin order, styled with the plugin's own front-end CSS. Editor-only adjustments keep the preview at page-preview scale: the album art renders as a 120px thumbnail (the public 200px size is too heavy for the edit page), the attribution footer and modal markup that follow the button list are omitted, and the buttons themselves are inert; an unknown GUID previews the same "Album/Song not found" message the front end would show.

The block stores the assembled `[musiclink ...]` string as its content, so published pages run through the same shortcode path as hand-typed ones. Nothing in this feature appears on the front end - the search, the preview, and the link field are editor-only, behind `manage_options`.

## V4V Music Link Resolver Pro

`v4v-musiclink-pro/`

The same functionality as the standard version, plus:

- Can accept the album/track GUIDs in the URL string if no parameters were added to the shortcode:
  ```
  https://example.com/musiclink?album={album-guid}&track={track-guid}
  ```
- If no parameters are provided in either the shortcode or the URL, a search page is displayed where the user can search musicindex.org and select the album/track to display the regular page.
- Usage of this plugin version is logged: one row when a visitor picks a result on the search page (the click that generates the shareable URL), plus one row per app-button launch (same date/artist/album/track plus the app that opened). Page renders - URL-driven or hardcoded into a shortcode attribute, shared links included - are never logged, and the raw `album`/`track` guids are never stored, only the titles Music Index returns. Both log beacons are rate-limited per client IP (30/minute per endpoint by default, configurable in the settings), and the log keeps only its newest rows (100,000 by default, also configurable).
- The admin settings page adds a tab to display the logs (with a **Clear log** button), another for running reports - totals by artist/album/track (opens) or by music app (launches), with date-range and artist filters - and a **Cache** tab that lists the cached release rows (with their date-only Music Index and Fountain.fm timestamps, and a tick/cross art status per row) and lets you edit a row's artwork URL or Fountain.fm link, launch the link, delete rows, or clear the Fountain links.
- Extras: optional Open Graph / Twitter Card social previews, a share button, and canonical URL fixes for guid'd URLs.

Shortcode: `[musiclink_pro]` (accepts the same `album`/`track`/`header` attributes as the standard version).

## Target apps

| App | Link style |
|---|---|
| StableKraft.app | Formulaic GUID-based URL, no lookup required |
| PodTunes.app | Formulaic GUID-based URL, no lookup required |
| v4vmusic.com | Search by title (resolved from Music Index) — no public album/track pages exist |
| Fountain.fm | **Pro:** resolved via their unofficial Server Action endpoint, with automatic self-healing of the `next-action` fingerprint. **Standard:** performs no Fountain discovery - the button uses a link stored on the cache row (set on the Cache tab) when one exists. Either way, when no link exists the button lands on `https://fountain.fm/search?q="…"` pre-filled with the release title (exact-phrase search), or on `fountain.fm/discover` when no title is known |

Under **Settings → V4V MusicLink** (standard) / **Settings → V4V MusicLink Pro** (Pro) each app can be individually disabled, given a custom icon URL, given an **icon background colour** (or left transparent via "None" — useful for transparent PNGs; the colour sits behind the 40px icon on the front end and the table preview updates live), and positioned with the **Order** controls. A **Random order** tickbox there additionally shuffles the rendered buttons into a new random order on every page load — refreshes and cached pages included — while the order saved in settings itself never changes (and a visitor without JavaScript simply sees the configured order).

## Data source

Music metadata (titles, artist, artwork) comes from the [MusicIndex.org](https://musicindex.org) API (`https://api.musicindex.org/v1`). No API credentials are needed. Every album/track pair is stored as one row in the plugin's own `{prefix}v4v_musiclink[_pro]_cache` table, which also holds that pair's resolved Fountain.fm link. Cached metadata is served immediately and refreshed in the background once it is older than 12 hours (configurable, 1–720 hours; refresh only - rows are never evicted, so stale titles keep serving through a Music Index outage). A pair that has never been seen is fetched inline on first render, and failed lookups are remembered separately in WordPress transients (1 hour for definitive misses, 30 seconds for unreliable ones).

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for the versioning/build workflow and [CHANGELOG.md](CHANGELOG.md) for release history.

### PHP toolchain

Linting and testing use a **portable PHP CLI kept in `php/`** — there is no system PHP dependency and `php` is generally **not** on `PATH`:

```bash
./php/install.sh   # first run / after a fresh clone (binary is gitignored)
./php/php -l v4v-musiclink/v4v-musiclink.php
```

Run the whole regression suite with `for f in tmp/test_*.php tmp/*_test.php; do ./php/php "$f"; done` — see the [Regression tests section of CONTRIBUTING.md](CONTRIBUTING.md#regression-tests).

If you are an agent or new contributor: **before you can lint or run any PHP, run `./php/install.sh` once**, then always invoke `./php/php`. See the [PHP runtime section of CONTRIBUTING.md](CONTRIBUTING.md#php-runtime-required-for-linting-and-tests) for details.
