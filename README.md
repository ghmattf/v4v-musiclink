# V4V MusicLink

An alternative to pod.link for music indexed by musicindex.org, providing direct links to the artist/album/song for Podcasting 2.0 music player apps such as fountain.fm, v4vmusic.com, stablekraft.app and podtunes.app.

The tool is a WordPress plugin provided in two versions. Both versions can run on the same WordPress instance without conflict.

## V4V Music Link Resolver (standard)

`v4v-musiclink/`

- Accepts `album` and (optionally) `track` GUID parameters within the shortcode.
- Queries musicindex.org for album, track, artist, and art information and displays it on a page, with a button for each music player that takes the user directly to the album/track within that player.
- Admin settings allow the admin to customise some display options.

Usage:

```
[musiclink album="{album-guid}"]
[musiclink album="{album-guid}" track="{track-guid}"]
```

A bare `[musiclink]` (or one with unexpected attributes) shows a usage message instead of guessing at intent. There is no URL-parameter or search UI mode in this version.

## V4V Music Link Resolver Pro

`v4v-musiclink-pro/`

The same functionality as the standard version, plus:

- Can accept the album/track GUIDs in the URL string if no parameters were added to the shortcode:
  ```
  https://example.com/musiclink?album={album-guid}&track={track-guid}
  ```
- If no parameters are provided in either the shortcode or the URL, a search page is displayed where the user can search musicindex.org and select the album/track to display the regular page.
- Usage of this plugin version is logged: one row when a visitor picks a result on the search page (the click that generates the shareable URL), plus one row per app-button launch (same date/artist/album/track plus the app that opened). Page renders - URL-driven or hardcoded into a shortcode attribute, shared links included - are never logged, and the raw `album`/`track` guids are never stored, only the titles Music Index returns. Both log beacons are rate-limited per client IP (30/minute per endpoint by default, configurable in the settings), and the log keeps only its newest rows (100,000 by default, also configurable).
- The admin settings page adds a tab to display the logs (with a **Clear log** button), and another for running reports - totals by artist/album/track (opens) or by music app (launches), with date-range and artist filters.
- Extras: optional Open Graph / Twitter Card social previews, a share button, and canonical URL fixes for guid'd URLs.

Shortcode: `[musiclink_pro]` (accepts the same `album`/`track` attributes as the standard version).

## Target apps

| App | Link style |
|---|---|
| StableKraft.app | Formulaic GUID-based URL, no lookup required |
| PodTunes.app | Formulaic GUID-based URL, no lookup required |
| v4vmusic.com | Search by title (resolved from Music Index) — no public album/track pages exist |
| Fountain.fm | Resolved via their unofficial Server Action endpoint, with automatic self-healing of the `next-action` fingerprint; falls back to a plain fountain.fm link on failure |

Each app can be individually disabled and/or given a custom icon URL under **Settings → V4V MusicLink** (standard) / **Settings → V4V MusicLink Pro** (Pro).

## Data source

Music metadata (titles, artist, artwork) comes from the [MusicIndex.org](https://musicindex.org) API (`https://api.musicindex.org/v1`). No API credentials are needed. Results are cached in WordPress transients (12 hours for successes, 1 hour for failures).

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for the versioning/build workflow and [CHANGELOG.md](CHANGELOG.md) for release history.

### PHP toolchain

Linting and testing use a **portable PHP CLI kept in `php/`** — there is no system PHP dependency and `php` is generally **not** on `PATH`:

```bash
./php/install.sh   # first run / after a fresh clone (binary is gitignored)
./php/php -l v4v-musiclink/v4v-musiclink.php
```

If you are an agent or new contributor: **before you can lint or run any PHP, run `./php/install.sh` once**, then always invoke `./php/php`. See the [PHP runtime section of CONTRIBUTING.md](CONTRIBUTING.md#php-runtime-required-for-linting-and-tests) for details.
