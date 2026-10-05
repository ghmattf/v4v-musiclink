<?php
/**
 * Plugin Name: V4V Music Link Resolver Pro
 * Description: PRO EDITION - isolated copy of V4V Music Link Resolver with entirely separate settings/options/cache/database storage from the standard edition, safe to run alongside it on the same site without conflicts. Uses the [musiclink_pro] shortcode (not [musiclink]). Provides guid-based cross-platform music links (Stablekraft, PodTunes.app, v4vmusic.com, Fountain) based on ?album={guid}&track={guid} in the page URL. With no guid supplied, shows a Music Index search box instead.
 * Version: 1.20.30
 * Author: Matt Finlay
 *
 * USAGE:
 * 1. Install and activate this plugin. No API credentials needed for
 *    Stablekraft, PodTunes.app, or Music Index (title/artwork lookups).
 *
 *    Fountain.fm direct links are OPTIONAL and need one extra bit of
 *    config, since Fountain has no public/stable API - only an internal,
 *    undocumented endpoint their own web app uses:
 *      a) Open https://fountain.fm in a browser, dev tools open,
 *         Network tab, Fetch/XHR filter on.
 *      b) Search for anything in Fountain's own search box.
 *      c) Find the POST request to "fountain.fm/search", open its Headers, copy
 *         the "next-action" header value (a long hex string).
 *      d) Paste it into Settings > V4V MusicLink in wp-admin.
 *    This value is a build fingerprint that changes whenever Fountain
 *    redeploys - if it goes stale, the plugin now tries to self-heal
 *    automatically (see below) before falling back to a plain link, so
 *    manual re-capture is a fallback rather than a requirement. Nothing
 *    breaks if this is left unset from the start either - Fountain just
 *    always falls back to fountain.fm/discover until a value (manual or
 *    auto-detected) is available.
 * 2. Create a WordPress Page with the slug "musiclink" (Pages > Add New).
 *    Set the page content to just the shortcode: [musiclink_pro]
 * 3. Link to it as:
 *    https://phafe.com/musiclink                       (shows a search box)
 *    https://phafe.com/musiclink?album={album-guid}
 *    https://phafe.com/musiclink?album={album-guid}&track={track-guid}
 *
 *    Or hardcode a specific release inline in a post/page instead:
 *    [musiclink_pro album="{album-guid}"]
 *    [musiclink_pro album="{album-guid}" track="{track-guid}"]
 *    [musiclink_pro album="{album-guid}" header="false"]
 *
 * NOTES ON EACH TARGET APP (see project reference doc for full details):
 * - Stablekraft: fully formulaic guid-based URL, no lookup required.
 * - PodTunes.app: fully formulaic guid-based URL, no lookup required.
 * - v4vmusic.com: fully formulaic guid-based URL (/lookup/{album}, or
 *   /lookup/{album}/{track} when a track guid is present), no lookup
 *   required - so the button renders even if Music Index titles were
 *   only partially resolved.
 * - Fountain: no reliable public guid-based or search-page link exists
 *   (both confirmed to 404 by direct testing). Their only resolution
 *   mechanism is the unofficial Server Action endpoint described above.
 *   If a resolution attempt fails (most often because the configured
 *   "next-action" has gone stale after a Fountain redeploy), the plugin
 *   automatically tries to self-heal: it crawls fountain.fm's own script
 *   files looking for a fresh next-action value (best-effort heuristic,
 *   throttled to once per 15 minutes), retries once with it, and - if
 *   that succeeds - saves it so future requests use it directly without
 *   repeating the crawl. Falls back to fountain.fm/discover if all of
 *   that fails too.
 *
 * SOCIAL PREVIEWS: when the musiclink page (or any page containing the
 * [musiclink_pro] shortcode) is shared on social media, this plugin can add
 * Open Graph / Twitter Card meta tags so the shared link shows a rich
 * preview - the release's own art and "Track from Album" / artist as
 * title/description when a valid guid is in the URL, or the site's logo
 * (or favicon) and this page's own WordPress title otherwise.
 *
 * This is controlled by a setting under Settings > V4V MusicLink:
 *   - Off: no preview tags added or changed by this plugin at all.
 *   - On (this plugin only) [default]: adds its own tags via wp_head.
 *     If an SEO plugin also adds tags site-wide, this one page may get
 *     two competing sets of tags.
 *   - Entire Site: rewrites whatever tags your theme/SEO plugin already
 *     produces, on the musiclink page only, instead of adding new ones -
 *     avoids duplicate tags and leaves every other page's previews
 *     (e.g. from an SEO plugin's own site-wide social feature) untouched.
 *
 * PER-APP CONTROLS: also under Settings > V4V MusicLink, each of the four
 * apps (Stablekraft, PodTunes.app, v4vmusic.com, Fountain) can be
 * individually disabled (hides its button entirely) and/or given a custom
 * icon URL overriding the default live favicon.
 * The same table's "Order" column (arrow buttons) sets the order in
 * which those buttons are listed on the front end.
 *
 * HEADER CONTROLS: whether the release header (title, artist, artwork)
 * appears above the buttons defaults to the "Release header" Show/Hide
 * drop-down under Settings > V4V MusicLink Pro (default: Show). The optional
 * header="true" / header="false" shortcode attribute overrides that
 * setting per placement - useful when one page wants the bare button
 * list while the site default is the opposite. Unrecognized header
 * values fall back to the admin setting.
 *
 * VIEW LOGGING: two kinds of row go into the dedicated table
 * `{wp_prefix}v4v_musiclink_pro_log` - created automatically on first load
 * after this version is installed, and altered in place on upgrade (the
 * `app` column arrived with app-launch logging; schema version 1 -> 2):
 *
 *   - OPENS: a visitor picking a result on the search page - the click
 *     that generates the shareable URL. One row per pick, sent as a
 *     beacon from the search results; the render that follows (and every
 *     later view of that URL, shared-link opens included) logs nothing.
 *   - LAUNCHES: a click on an app button that actually navigates. One row
 *     carrying the same date/artist/album/track plus the `app` slug,
 *     from every placement (a click is a deliberate action), and only
 *     when the click goes through - cancelling Fountain's modal dialog
 *     logs nothing.
 *
 * Page renders are never logged, from any source (URL query string or
 * hardcoded shortcode attribute): a render can be triggered by refreshes,
 * feeds, previews and crawlers alike, so it says little about deliberate
 * usage while letting any request grow the table without bound.
 *
 * Both beacon endpoints are rate limited per client IP (30 requests per
 * minute per endpoint by default, configurable under Settings): over-limit
 * beacons are answered 429 and write nothing, and the check runs before
 * any Music Index lookup, so a flood is never relayed upstream. Budgets
 * are keyed on REMOTE_ADDR only - see is_rate_limited() for the window
 * semantics.
 *
 * The table is pruned back to a configured maximum (100,000 rows by
 * default, configurable under Settings) - checked at most once per hour
 * after a row is written, so retention stays bounded without paying a
 * size query on every insert.
 *
 * The columns hold titles resolved from Music Index; the album/track
 * guids that drove a row are never stored, and nothing is written
 * anywhere else (no error log, no debug file). Rows are logged
 * unconditionally, not deduplicated per visitor/session. Read them and
 * run the reports - "Total by Artist/Album/Track" counts opens,
 * "Total by Music App" counts launches - from the View Log and Reports
 * tabs under Settings > V4V MusicLink Pro, which can also clear the
 * table.
 *
 * NOT FOUND HANDLING: if a guid is validly formatted but Music Index has no
 * matching release, the page falls back to the search view (same as no
 * guid at all) with an "Album/Song not found" message, rather than showing
 * broken/empty app buttons.
 *
 * CACHING: everything a page view needs for one release lives on a single
 * cache-table row keyed on album/track guid - the Music Index metadata
 * (titles, artist, artwork) and the resolved Fountain.fm link. Stale
 * metadata keeps serving while a background refresh runs after the page
 * has been sent (plain PHP on the shutdown hook - no cron, no queue),
 * so a Music Index outage can't blank an already-seen release. When
 * that refresh is triggered is admin-configurable (Settings >
 * V4V MusicLink Pro, "Music Index refresh", default 12 hours), and only
 * front-end page renders may trigger it - never the search box, the log
 * beacons, or the og: preview. Fountain.fm links are permanent: they
 * never go stale and are never auto-refreshed (blank a link on the
 * Cache tab to force a re-search). Failure throttles (not-found /
 * outage) stay in short-lived transients.
 *
 * Icons are pulled live from each app's own favicon via a favicon service
 * (no logo assets are stored or reproduced by this plugin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class V4V_Music_Link_Resolver_Pro {

	const MI_ROOT        = 'https://api.musicindex.org';
	const MI_API_BASE    = 'https://api.musicindex.org/v1';
	// Default hours between background refreshes of cached Music Index
	// metadata, before the admin "Music Index refresh" setting (clamped
	// 1..720 by mi_refresh_interval()). Stale rows keep serving; this only
	// decides when a background pull is queued.
	const MI_REFRESH_DEFAULT_HOURS = 12;
	const FAIL_CACHE_TTL  = HOUR_IN_SECONDS;
	// Negative cache for failures that do NOT prove the guid is missing
	// (timeout, DNS error, 5xx, redirect, undecodable body). Long enough to
	// stop hammering a struggling API, short enough that an outage stops
	// masquerading as "Album/Song not found" almost immediately - see
	// negative_cache_ttl().
	const SOFT_FAIL_CACHE_TTL = 30;
	// Namespaces the negative (miss) transients for Music Index lookups,
	// so a changed miss-policy shape never lets an old remembered failure
	// be served. Positives live in the cache table now, not transients.
	const CACHE_SCHEMA_VERSION = 4;

	/**
	 * Cache table schema generation. Bump when the CREATE TABLE changes so
	 * existing installs run dbDelta() again (see maybe_create_cache_table()).
	 *
	 * 1 = one row per release holding Music Index metadata (titles, artist,
	 *     art, mi_updated_at) plus the Fountain link (fountain_url,
	 *     fountain_updated_at), keyed on lookup_hash. Supersedes the old
	 *     stored-links table (whose migration code has been removed - no
	 *     installation left could need it).
	 */
	const CACHE_TABLE_VERSION = 1;

	/**
	 * The link column each app stores its resolved URL in, keyed by app
	 * slug. Rows are shared across apps (one per release), so another app
	 * joining later means: its own column in CREATE TABLE plus a version
	 * bump, one entry in this map, and one field in
	 * the tab - not another row.
	 */
	const LINK_COLUMNS = array( 'fountain' => 'fountain_url' );

	/**
	 * The columns the Cache tab's edit controls may write, keyed by the
	 * edit form's link_field value. LINK_COLUMNS stays fountain-only -
	 * it also drives the Clear scope dropdown and app_display_label() -
	 * while the artwork control joins here so it can rewrite or blank
	 * image_url. Validation differs per field: a link must be an https
	 * URL on its own app's domain, artwork only has to be http(s).
	 */
	const CACHE_EDIT_COLUMNS = array(
		'fountain' => 'fountain_url',
		'art'      => 'image_url',
	);

	// Search pagination. Music Index caps `limit` at 100 and pages the rest
	// behind opaque cursors, so a batch is at most SEARCH_BATCH_LIMIT rows and
	// deeper results are reached by chaining. SEARCH_MAX_BATCHES bounds how
	// many upstream calls a single AJAX request may trigger.
	const SEARCH_PER_PAGE        = 8;
	const SEARCH_BATCH_LIMIT     = 100;
	const SEARCH_MAX_BATCHES     = 3;
	const SEARCH_CACHE_SCHEMA    = 2;
	const SEARCH_CACHE_TTL       = HOUR_IN_SECONDS;

	/**
	 * HTTP status of the most recent mi_api_get() call (0 = transport
	 * failure or an undecodable body). Lets mi_get_feed()/mi_get_track()
	 * distinguish a definitive 404 from an unreliable failure when they
	 * decide how long to remember a miss - see negative_cache_ttl().
	 */
	private $last_mi_status = 0;

	/**
	 * HTTP status of the most recent fountain_try_resolve() call (0 =
	 * transport failure, redirect, or a 200 body we couldn't read as a
	 * hits payload). Lets fountain_negative_cache_ttl() separate a
	 * definitive Fountain answer from an unreliable failure - the same
	 * policy negative_cache_ttl() applies to the Music Index API.
	 */
	private $last_fountain_status = 0;

	/**
	 * Whether the last extract_fountain_hit() actually parsed a hits
	 * array (an empty one included = a trustworthy "Fountain has no
	 * results for this"). When false after a 200, the body was not the
	 * shape we understand, so the miss is unconfirmed.
	 */
	private $last_fountain_hits_parsed = false;

	/**
	 * Whether a stale cache row may queue a background Music Index
	 * refresh in this request. Default-deny: only render() turns it on,
	 * so the search box, the log beacons and the og: preview can never
	 * trigger a refresh (they may still fill an absent row).
	 */
	private $mi_refresh_allowed = false;

	/**
	 * Rows queued for a background Music Index pull this request, as
	 * hash => array( 'kind' => 'feed'|'track', 'album' => ..., 'track' => ... ).
	 * Drained by run_queued_mi_refresh() on the shutdown hook.
	 */
	private $mi_refresh_pending = array();

	/**
	 * Memoized cache-row reads for this request: lookup_hash => row object
	 * (or false when no row exists), so one render makes at most one query
	 * per album/track pair no matter how often the row is consulted.
	 */
	private $row_memo = array();

	public function __construct() {
		add_shortcode( 'musiclink_pro', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_v4v_musiclink_pro_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_v4v_musiclink_pro_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_v4v_musiclink_pro_log_open', array( $this, 'ajax_log_open' ) );
		add_action( 'wp_ajax_nopriv_v4v_musiclink_pro_log_open', array( $this, 'ajax_log_open' ) );
		add_action( 'wp_ajax_v4v_musiclink_pro_log_app', array( $this, 'ajax_log_app' ) );
		add_action( 'wp_ajax_nopriv_v4v_musiclink_pro_log_app', array( $this, 'ajax_log_app' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_v4v_musiclink_pro_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_v4v_musiclink_pro_update_cache_row', array( $this, 'handle_update_cache_row' ) );
		add_action( 'admin_post_v4v_musiclink_pro_delete_cache_row', array( $this, 'handle_delete_cache_row' ) );
		add_action( 'admin_post_v4v_musiclink_pro_clear_cache', array( $this, 'handle_clear_cache' ) );
		// Background Music Index refreshes, after the page has gone out
		// (priority 10 lets wp_ob_end_flush_all at priority 1 flush first).
		add_action( 'shutdown', array( $this, 'run_queued_mi_refresh' ) );

		$mode = $this->get_social_preview_mode();
		if ( 'off' === $mode ) {
			// Nothing added - musiclink page uses whatever the theme/SEO
			// plugin normally produces, completely untouched.
		} elseif ( 'plugin_only' === $mode ) {
			add_action( 'wp_head', array( $this, 'output_social_preview_meta' ) );
			add_filter( 'get_canonical_url', array( $this, 'fix_canonical_url' ), 10, 2 );
			add_action( 'template_redirect', array( $this, 'maybe_buffer_output_for_canonical_fix' ) );
			add_filter( 'pre_get_document_title', array( $this, 'override_document_title' ), PHP_INT_MAX );
		} elseif ( 'entire_site' === $mode ) {
			add_filter( 'get_canonical_url', array( $this, 'fix_canonical_url' ), 10, 2 );
			add_action( 'template_redirect', array( $this, 'maybe_buffer_output_for_site_wide_fix' ) );
			add_filter( 'pre_get_document_title', array( $this, 'override_document_title' ), PHP_INT_MAX );
		}

		$this->maybe_create_log_table();
		$this->maybe_create_cache_table();
	}

	/**
	 * Overrides the actual <title> tag (not just og:title/twitter:title) to
	 * match the resolved release, since some link-preview clients read the
	 * plain document title instead of Open Graph tags - confirmed via a
	 * real-world screenshot showing a client displaying the page's default
	 * WordPress title ("Page Name - Site Name") instead of the release
	 * title, even though og:title was already correct. Only overrides when
	 * a guid is present and actually resolves - the bare search page keeps
	 * WordPress's normal title untouched.
	 */
	public function override_document_title( $title ) {
		global $post;
		if ( ! $post instanceof WP_Post || ! has_shortcode( $post->post_content, 'musiclink_pro' ) ) {
			return $title;
		}
		$album = $this->get_guid_arg( 'album' );
		if ( ! $album ) {
			return $title; // Bare search page - leave WordPress's normal title alone.
		}
		$fields = $this->compute_preview_fields( $post );

		// esc_html(): core's _wp_render_title_tag() echoes the
		// pre_get_document_title value verbatim, and this title comes from
		// the external Music Index API - a "</title>" in a release title
		// would otherwise close the element early and inject markup into
		// the page (stored XSS via a catalog entry).
		return $fields['title'] ? esc_html( $fields['title'] ) : $title;
	}

	/**
	 * Creates the view-log table if it doesn't exist yet. Checked on every
	 * load (via a cheap option flag, not a real DB query in the common
	 * case) rather than relying solely on register_activation_hook, since
	 * updates to this plugin are typically deployed by overwriting the
	 * file in place without a deactivate/reactivate cycle - which means a
	 * normal activation hook would never fire for this change.
	 */
	private function maybe_create_log_table() {
		// '1' = rows are views only; '2' adds the app column (+ index) that
		// launch rows need, so an install last written before app-launch
		// logging runs dbDelta once more and is altered in place.
		if ( '2' === get_option( 'v4v_musiclink_pro_log_table_version' ) ) {
			return;
		}
		self::create_log_table();
		update_option( 'v4v_musiclink_pro_log_table_version', '2' );
	}

	public static function create_log_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'v4v_musiclink_pro_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			logged_at DATETIME NOT NULL,
			artist VARCHAR(255) NOT NULL DEFAULT '',
			album VARCHAR(255) NOT NULL DEFAULT '',
			track VARCHAR(255) NOT NULL DEFAULT '',
			app VARCHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY app (app)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Logs one row: date, artist, album, track (blank when the pick or
	 * launch carried no track) plus the music app that was launched -
	 * blank for a search-result open, one of app_registry()'s slugs for a
	 * button launch. Only titles resolved from Music Index are stored; the
	 * album/track guids that drove the row never are. Called only from the
	 * two beacon handlers (ajax_log_open, ajax_log_app) - page renders
	 * never log, from any source - and logged on every qualifying row,
	 * not deduplicated per visitor/session. The admin logging switch
	 * (logging_enabled(), default on) is checked here as the write-path
	 * choke point, and earlier still in both beacon handlers so a
	 * disabled switch spends no rate-limit budget and no upstream
	 * resolution. After the insert, at most once per hour, the table is
	 * pruned back to log_max_rows() newest rows so retention stays bounded.
	 *
	 * @param string $artist      Resolved artist title.
	 * @param string $album_title Resolved album title.
	 * @param string $track_title Resolved track title, or ''.
	 * @param string $app         Launched app slug, or '' for an open.
	 */
	private function log_view( $artist, $album_title, $track_title, $app = '' ) {
		if ( ! $this->logging_enabled() ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'v4v_musiclink_pro_log',
			array(
				'logged_at' => current_time( 'mysql' ),
				'artist'    => $artist,
				'album'     => $album_title,
				'track'     => $track_title,
				'app'       => $app,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		// Retention backstop. The size check walks the primary key past
		// the cap, so it is throttled to at most one attempt per hour:
		// the table may overshoot the cap between checks, but never for
		// long under sustained traffic, and the common case (a handful of
		// rows a day) pays nothing.
		if ( ! get_transient( 'v4v_log_prune' ) ) {
			$this->prune_log();
			set_transient( 'v4v_log_prune', 1, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Keeps the log at its configured maximum by deleting every row at or
	 * below the id of the first row that falls outside the newest
	 * log_max_rows() entries (id is AUTO_INCREMENT, so id order is
	 * insertion order). When the table is at or under the cap the OFFSET
	 * lookup returns nothing and no DELETE is issued. Runs at most hourly,
	 * from log_view() - see the throttle there.
	 */
	private function prune_log() {
		global $wpdb;
		$table = $wpdb->prefix . 'v4v_musiclink_pro_log';
		$cutoff = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
				$this->log_max_rows()
			)
		);

		if ( ! $cutoff ) {
			return; // At or under the cap - nothing to prune.
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
	}

	/**
	 * Per-endpoint log-write budget for is_rate_limited(): requests per
	 * minute one client IP may have accepted for the given bucket
	 * ('log_app' or 'log_open'), read from the matching admin setting and
	 * re-clamped here so a value written outside the settings UI can't
	 * disable logging (0/blank -> default 30) or exceed 600/min.
	 *
	 * @param string $bucket Rate-limiter bucket; doubles as the option-name suffix.
	 * @return int Allowed requests per minute.
	 */
	private function log_rate_max( $bucket ) {
		return $this->sanitize_log_rate( get_option( 'v4v_musiclink_pro_' . $bucket . '_rate', 30 ) );
	}

	/**
	 * Configured row cap for the log: the newest this many rows are kept
	 * (see prune_log()). Same defensive re-clamping as log_rate_max(),
	 * with 0/blank -> default 100,000 and a 1,000..1,000,000 range.
	 *
	 * @return int Maximum rows kept.
	 */
	private function log_max_rows() {
		return $this->sanitize_log_max_rows( get_option( 'v4v_musiclink_pro_log_max_rows', 100000 ) );
	}

	/**
	 * Whether interaction logging is on. Admin switch stored as '1'/'0'
	 * (settings checkbox, default on): the stored value is only ever '0'
	 * when the admin has explicitly opted out, so an absent option - every
	 * site that never opened the settings - keeps logging exactly as
	 * before. Read in both beacon handlers before any budget or upstream
	 * work, and again in log_view() as the write-path choke point.
	 *
	 * @return bool True when the log beacons may record events.
	 */
	private function logging_enabled() {
		return (bool) get_option( 'v4v_musiclink_pro_log_enabled', 1 );
	}

	/**
	 * AJAX beacon fired when a visitor picks a result on the search page -
	 * the click that generates the shareable URL. This is the only thing
	 * that logs an "open": the render it navigates to (and any later view
	 * of that URL, shared links included) deliberately logs nothing. The
	 * page sends only the guids; the titles are resolved server-side
	 * before anything is written, so the client never decides what lands
	 * in the log, and the guids have to resolve to a real release or
	 * nothing is recorded.
	 */
	public function ajax_log_open() {
		// Same nonce action as the launch beacon - one "log write" token
		// family, so already-cached pages keep verifying after upgrade.
		check_ajax_referer( 'v4v_musiclink_pro_log_app', 'nonce' );

		// Admin logging switch off: acknowledge and do nothing - no
		// rate-limit budget spent, no Music Index lookup, no row. The
		// client is fire-and-forget sendBeacon, and a deliberately
		// disabled feature is not a failure, so this answers success.
		if ( ! $this->logging_enabled() ) {
			wp_send_json_success();
		}

		// Bound the write path before it spends any upstream work - see
		// is_rate_limited() for the window semantics.
		if ( $this->is_rate_limited( 'log_open', $this->log_rate_max( 'log_open' ), MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( null, 429 );
		}

		$album = $this->guard_guid( isset( $_POST['album'] ) ? sanitize_text_field( wp_unslash( $_POST['album'] ) ) : '' );
		$track = $this->guard_guid( isset( $_POST['track'] ) ? sanitize_text_field( wp_unslash( $_POST['track'] ) ) : '' );

		if ( ! $album ) {
			wp_send_json_error( null, 400 );
		}

		$has_track = ! empty( $track );
		$release   = $this->resolve_release_meta( $album, $track, $has_track );

		if ( ! $release['album_title'] && ! $release['track_title'] ) {
			wp_send_json_error( null, 404 );
		}

		$this->log_view( $release['artist'], $release['album_title'], $release['track_title'] );
		wp_send_json_success();
	}

	/**
	 * AJAX beacon fired the instant an app button actually launches. The
	 * page sends the release's guids (read off the DOM, so it works with
	 * or without a query string) and the app slug; the titles are resolved
	 * server-side before anything is written, so the client never decides
	 * what lands in the log. The slug has to be one of the registry's, and
	 * the guids have to resolve to a real release, otherwise nothing is
	 * recorded.
	 */
	public function ajax_log_app() {
		check_ajax_referer( 'v4v_musiclink_pro_log_app', 'nonce' );

		// Admin logging switch off: acknowledge and do nothing - no
		// rate-limit budget spent, no Music Index lookup, no row. The
		// client is fire-and-forget sendBeacon, and a deliberately
		// disabled feature is not a failure, so this answers success.
		if ( ! $this->logging_enabled() ) {
			wp_send_json_success();
		}

		// Bound the write path before it spends any upstream work - see
		// is_rate_limited() for the window semantics. Own budget, so
		// launch floods can't starve search-result opens or vice versa.
		if ( $this->is_rate_limited( 'log_app', $this->log_rate_max( 'log_app' ), MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( null, 429 );
		}

		$album    = $this->guard_guid( isset( $_POST['album'] ) ? sanitize_text_field( wp_unslash( $_POST['album'] ) ) : '' );
		$track    = $this->guard_guid( isset( $_POST['track'] ) ? sanitize_text_field( wp_unslash( $_POST['track'] ) ) : '' );
		$app      = isset( $_POST['app'] ) ? sanitize_key( wp_unslash( $_POST['app'] ) ) : '';
		$registry = $this->app_registry();

		if ( ! $album || ! isset( $registry[ $app ] ) ) {
			wp_send_json_error( null, 400 );
		}

		$has_track = ! empty( $track );
		$release   = $this->resolve_release_meta( $album, $track, $has_track );

		if ( ! $release['album_title'] && ! $release['track_title'] ) {
			wp_send_json_error( null, 404 );
		}

		$this->log_view( $release['artist'], $release['album_title'], $release['track_title'], $app );
		wp_send_json_success( array( 'app' => $app ) );
	}

	/**
	 * SiteSEO (and some other SEO plugins) generate their own canonical
	 * tag independently of WordPress core's get_canonical_url filter above,
	 * so that filter alone isn't enough to fix it for every plugin. This is
	 * a plugin-agnostic fallback: buffer the whole page's output and
	 * directly correct the canonical tag's href in the final rendered HTML,
	 * regardless of which plugin (if any) produced it.
	 */
	public function maybe_buffer_output_for_canonical_fix() {
		global $post;
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'musiclink_pro' ) && ! empty( $_GET['album'] ) ) {
			ob_start( array( $this, 'rewrite_canonical_in_html' ) );
		}
	}

	/**
	 * Length-only guard for guids at intake (#3): anything over 256 bytes
	 * is dropped and behaves exactly like an absent guid, before it can
	 * reach an API lookup, an outbound app URL, the canonical/og tags or
	 * the log. Deliberately NO charset whitelist - Music Index ids are
	 * free-form (e.g. track_01-they're-right!) - so only size is bounded.
	 */
	private function guard_guid( $guid ) {
		return strlen( $guid ) <= 256 ? $guid : '';
	}

	/**
	 * The single read path for guid query args: unslash, sanitise, guard.
	 */
	private function get_guid_arg( $key ) {
		$raw = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';
		return $this->guard_guid( sanitize_text_field( $raw ) );
	}

	/**
	 * This page's canonical URL: the request path plus the sanitised
	 * album/track args, and nothing else.
	 *
	 * The query string IS this page's content, but echoing the raw
	 * REQUEST_URI (add_query_arg( null, null )) into the get_canonical_url
	 * filter or the buffer rewrite hands consumers every other -
	 * attacker-supplied - query arg, and not all of them escape on output.
	 * Rebuilding also drops junk args, so every producer of this page's URL
	 * - the get_canonical_url filter, the buffer rewrite, og:url in both
	 * social-preview modes, and the Share button - agrees on one URL.
	 *
	 * Returned UNESCAPED: consumers escape once at their own output (core's
	 * rel_canonical() applies esc_url, this plugin's output sites apply
	 * esc_url). Pre-escaping here made the escaping count depend on which
	 * consumer received the value - the get_canonical_url filter result
	 * used to be esc_url'd a second time downstream.
	 */
	private function musiclink_canonical_url() {
		$album = $this->get_guid_arg( 'album' );
		$track = $this->get_guid_arg( 'track' );

		$args = array();
		if ( $album ) {
			$args['album'] = $album;
		}
		if ( $track ) {
			$args['track'] = $track;
		}

		$path = explode( '?', add_query_arg( null, null ), 2 )[0];

		return home_url( add_query_arg( $args, $path ) );
	}

	public function rewrite_canonical_in_html( $html ) {
		$correct_url = $this->musiclink_canonical_url();

		// Callback rather than a replacement string: the URL is built from
		// the request's query string, and "$" / "\" in a replacement are
		// expanded as backreferences - "?x=$2" would splice the captured
		// tag tail into the middle of the href and corrupt the markup.
		return preg_replace_callback(
			'/(<link[^>]+rel=["\']canonical["\'][^>]+href=["\'])[^"\']*(["\'][^>]*>)/i',
			function ( $matches ) use ( $correct_url ) {
				return $matches[1] . esc_url( $correct_url ) . $matches[2];
			},
			$html,
			1
		);
	}

	/**
	 * "Entire Site" mode: buffers this page's full output so the existing
	 * og:/twitter: tags (from an SEO plugin or theme) can be corrected in
	 * place, rather than adding a second competing set. Every other page
	 * on the site is completely untouched - only pages containing the
	 * [musiclink_pro] shortcode are buffered at all.
	 */
	public function maybe_buffer_output_for_site_wide_fix() {
		global $post;
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'musiclink_pro' ) ) {
			ob_start( array( $this, 'rewrite_all_meta_in_html' ) );
		}
	}

	public function rewrite_all_meta_in_html( $html ) {
		global $post;
		$fields      = $this->compute_preview_fields( $post );
		$current_url = $this->musiclink_canonical_url();

		$html = $this->rewrite_canonical_in_html( $html );

		// Also rewrite the actual <title> tag, not just og:title - some
		// link-preview clients read the plain document title instead of
		// Open Graph tags (confirmed via a real screenshot). Only touches
		// it when a guid is present and resolved; the bare search page
		// keeps its normal WordPress title.
		$album = $this->get_guid_arg( 'album' );
		if ( $album && $fields['title'] ) {
			// Callback so a "$0" / "$1" in a release title is never expanded
			// as a backreference (that would re-insert the raw old <title>
			// element into the replacement, nesting/corrupting the head).
			$replacement = '<title>' . esc_html( $fields['title'] ) . '</title>';
			$html        = preg_replace_callback(
				'/<title>.*?<\/title>/s',
				function () use ( $replacement ) {
					return $replacement;
				},
				$html,
				1
			);
		}

		$html = $this->set_or_insert_meta( $html, 'property', 'og:title', $fields['title'] );
		$html = $this->set_or_insert_meta( $html, 'property', 'og:description', $fields['description'] );
		$html = $this->set_or_insert_meta( $html, 'property', 'og:type', $fields['type'] );
		$html = $this->set_or_insert_meta( $html, 'property', 'og:url', $current_url, true );
		if ( $fields['site_name'] ) {
			$html = $this->set_or_insert_meta( $html, 'property', 'og:site_name', $fields['site_name'] );
		}
		if ( $fields['image'] ) {
			$html = $this->set_or_insert_meta( $html, 'property', 'og:image', $fields['image'], true );
			$html = $this->set_or_insert_meta( $html, 'name', 'twitter:card', 'summary_large_image' );
			$html = $this->set_or_insert_meta( $html, 'name', 'twitter:image', $fields['image'], true );
		} else {
			$html = $this->set_or_insert_meta( $html, 'name', 'twitter:card', 'summary' );
		}
		$html = $this->set_or_insert_meta( $html, 'name', 'twitter:title', $fields['title'] );
		$html = $this->set_or_insert_meta( $html, 'name', 'twitter:description', $fields['description'] );

		return $html;
	}

	/**
	 * Replaces an existing <meta property/name="X" content="..."> tag's
	 * content value in place if that tag already exists in the HTML
	 * (regardless of which plugin produced it), or inserts a new one right
	 * before </head> if it doesn't exist yet. $is_url controls whether the
	 * value is escaped as a URL or as plain attribute text.
	 */
	private function set_or_insert_meta( $html, $attr, $attr_value, $content, $is_url = false ) {
		if ( '' === $content ) {
			return $html;
		}
		$escaped_content = $is_url ? esc_url( $content ) : esc_attr( $content );
		$pattern = '/<meta[^>]+' . preg_quote( $attr, '/' ) . '=["\']' . preg_quote( $attr_value, '/' ) . '["\'][^>]*>/i';

		if ( preg_match( $pattern, $html ) ) {
			$new_tag = '<meta ' . $attr . '="' . esc_attr( $attr_value ) . '" content="' . $escaped_content . '" />';

			// Callback form: a "$0" / "$1" inside the escaped content must
			// not be expanded as a backreference - that would re-insert the
			// raw matched <meta> tag inside the attribute value and break
			// out of the quote.
			return preg_replace_callback(
				$pattern,
				function () use ( $new_tag ) {
					return $new_tag;
				},
				$html,
				1
			);
		}

		$new_tag = '<meta ' . $attr . '="' . esc_attr( $attr_value ) . '" content="' . $escaped_content . '" />' . "\n";
		return str_ireplace( '</head>', $new_tag . '</head>', $html );
	}

	/**
	 * The musiclink page's query string IS its content (?album=&track=),
	 * unlike most pages where a canonical URL should strip query args.
	 * Without this, WordPress/SEO plugins declare every guid'd URL as
	 * canonically the same bare page - which some platforms (confirmed:
	 * Telegram) use as their preview-cache key, making every release show
	 * whichever preview was cached first instead of its own.
	 */
	public function fix_canonical_url( $canonical_url, $post ) {
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'musiclink_pro' ) && ! empty( $_GET['album'] ) ) {
			return $this->musiclink_canonical_url();
		}
		return $canonical_url;
	}

	public function register_settings_page() {
		add_options_page(
			__( 'V4V MusicLink Pro', 'v4v-musiclink-pro' ),
			__( 'V4V MusicLink Pro', 'v4v-musiclink-pro' ),
			'manage_options',
			'v4v-musiclink-pro',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Converts a date-range selection into [start, end] MySQL datetime
	 * strings (or [null, null] for "all time"), computed in WordPress's
	 * configured timezone so "Today" etc. match what the site owner
	 * actually means by "today" rather than the DB server's own timezone.
	 */
	private function get_report_date_bounds( $range, $custom_start, $custom_end ) {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		switch ( $range ) {
			case 'today':
				$start = $now->setTime( 0, 0, 0 );
				$end   = $start->modify( '+1 day' );
				break;
			case 'this_month':
				// Calendar month, not a rolling 30 days: the 1st at
				// midnight up to (but not including) the 1st of the next.
				$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end   = $start->modify( '+1 month' );
				break;
			case 'last_month':
				$start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
				$end   = $start->modify( '+1 month' );
				break;
			case '7days':
				$start = $now->modify( '-6 days' )->setTime( 0, 0, 0 );
				$end   = $now->modify( '+1 day' )->setTime( 0, 0, 0 );
				break;
			case '30days':
				$start = $now->modify( '-29 days' )->setTime( 0, 0, 0 );
				$end   = $now->modify( '+1 day' )->setTime( 0, 0, 0 );
				break;
			case '90days':
				$start = $now->modify( '-89 days' )->setTime( 0, 0, 0 );
				$end   = $now->modify( '+1 day' )->setTime( 0, 0, 0 );
				break;
			case 'custom':
				$start = $custom_start ? DateTimeImmutable::createFromFormat( 'Y-m-d', $custom_start, $tz ) : false;
				$end   = $custom_end ? DateTimeImmutable::createFromFormat( 'Y-m-d', $custom_end, $tz ) : false;
				$start = $start ? $start->setTime( 0, 0, 0 ) : null;
				$end   = $end ? $end->setTime( 0, 0, 0 )->modify( '+1 day' ) : null;
				break;
			case 'all':
			default:
				return array( null, null );
		}

		return array(
			$start ? $start->format( 'Y-m-d H:i:s' ) : null,
			$end ? $end->format( 'Y-m-d H:i:s' ) : null,
		);
	}

	/**
	 * Reports tab: consolidated totals from the log table, grouped by
	 * artist, album, or track, with an artist text filter and a date-range
	 * filter (presets or a custom start/end range). Uses a plain GET form
	 * so results are bookmarkable/shareable and the page works with a
	 * normal reload, no JS required for the core functionality.
	 */
	private function render_reports_tab_content() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'v4v_musiclink_pro_log';

		$report_type   = isset( $_GET['report_type'] ) ? sanitize_text_field( wp_unslash( $_GET['report_type'] ) ) : 'artist';
		$artist_filter = isset( $_GET['filter_artist'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_artist'] ) ) : '';
		$date_range    = isset( $_GET['date_range'] ) ? sanitize_text_field( wp_unslash( $_GET['date_range'] ) ) : '30days';
		$custom_start  = isset( $_GET['date_start'] ) ? sanitize_text_field( wp_unslash( $_GET['date_start'] ) ) : '';
		$custom_end    = isset( $_GET['date_end'] ) ? sanitize_text_field( wp_unslash( $_GET['date_end'] ) ) : '';

		if ( ! in_array( $report_type, array( 'artist', 'album', 'track', 'app' ), true ) ) {
			$report_type = 'artist';
		}
		if ( ! in_array( $date_range, array( 'today', 'this_month', 'last_month', '7days', '30days', '90days', 'all', 'custom' ), true ) ) {
			$date_range = '30days';
		}

		list( $range_start, $range_end ) = $this->get_report_date_bounds( $date_range, $custom_start, $custom_end );
		?>
		<form method="get">
			<input type="hidden" name="page" value="v4v-musiclink-pro" />
			<input type="hidden" name="tab" value="reports" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="v4v-report-type"><?php esc_html_e( 'Report', 'v4v-musiclink-pro' ); ?></label></th>
					<td>
						<select name="report_type" id="v4v-report-type">
							<option value="artist" <?php selected( $report_type, 'artist' ); ?>><?php esc_html_e( 'Total by Artist', 'v4v-musiclink-pro' ); ?></option>
							<option value="album" <?php selected( $report_type, 'album' ); ?>><?php esc_html_e( 'Total by Album', 'v4v-musiclink-pro' ); ?></option>
							<option value="track" <?php selected( $report_type, 'track' ); ?>><?php esc_html_e( 'Total by Track', 'v4v-musiclink-pro' ); ?></option>
							<option value="app" <?php selected( $report_type, 'app' ); ?>><?php esc_html_e( 'Total by Music App', 'v4v-musiclink-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="v4v-filter-artist"><?php esc_html_e( 'Artist filter', 'v4v-musiclink-pro' ); ?></label></th>
					<td>
						<input
							type="text"
							id="v4v-filter-artist"
							name="filter_artist"
							value="<?php echo esc_attr( $artist_filter ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Leave blank for all artists', 'v4v-musiclink-pro' ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="v4v-report-date-range"><?php esc_html_e( 'Date range', 'v4v-musiclink-pro' ); ?></label></th>
					<td>
						<select name="date_range" id="v4v-report-date-range">
							<option value="today" <?php selected( $date_range, 'today' ); ?>><?php esc_html_e( 'Today', 'v4v-musiclink-pro' ); ?></option>
							<option value="this_month" <?php selected( $date_range, 'this_month' ); ?>><?php esc_html_e( 'This month', 'v4v-musiclink-pro' ); ?></option>
							<option value="last_month" <?php selected( $date_range, 'last_month' ); ?>><?php esc_html_e( 'Last month', 'v4v-musiclink-pro' ); ?></option>
							<option value="7days" <?php selected( $date_range, '7days' ); ?>><?php esc_html_e( 'Last 7 days', 'v4v-musiclink-pro' ); ?></option>
							<option value="30days" <?php selected( $date_range, '30days' ); ?>><?php esc_html_e( 'Last 30 days', 'v4v-musiclink-pro' ); ?></option>
							<option value="90days" <?php selected( $date_range, '90days' ); ?>><?php esc_html_e( 'Last 90 days', 'v4v-musiclink-pro' ); ?></option>
							<option value="all" <?php selected( $date_range, 'all' ); ?>><?php esc_html_e( 'All time', 'v4v-musiclink-pro' ); ?></option>
							<option value="custom" <?php selected( $date_range, 'custom' ); ?>><?php esc_html_e( 'Custom', 'v4v-musiclink-pro' ); ?></option>
						</select>
						<span id="v4v-report-custom-dates" style="margin-left:0.75em; <?php echo 'custom' === $date_range ? '' : 'display:none;'; ?>">
							<label><?php esc_html_e( 'From', 'v4v-musiclink-pro' ); ?>
								<input type="date" name="date_start" value="<?php echo esc_attr( $custom_start ); ?>" />
							</label>
							<label style="margin-left:0.5em;"><?php esc_html_e( 'To', 'v4v-musiclink-pro' ); ?>
								<input type="date" name="date_end" value="<?php echo esc_attr( $custom_end ); ?>" />
							</label>
						</span>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Generate Report', 'v4v-musiclink-pro' ), 'primary', '', false ); ?>
		</form>
		<script>
		(function () {
			var select = document.getElementById( 'v4v-report-date-range' );
			var customSpan = document.getElementById( 'v4v-report-custom-dates' );
			if ( select && customSpan ) {
				select.addEventListener( 'change', function () {
					customSpan.style.display = ( 'custom' === select.value ) ? 'inline' : 'none';
				} );
			}
		})();
		</script>
		<?php

		$where  = array( '1=1' );
		$params = array();

		if ( 'track' === $report_type ) {
			$where[] = "track != ''";
		}
		if ( 'app' === $report_type ) {
			// Launch rows only - a blank app means a search-result open.
			$where[] = "app != ''";
		} else {
			// Keep launch rows out of the three open reports: a launch
			// repeats the artist/album/track of the open behind it, so
			// counting both would double numbers these reports have always
			// presented per release. (Blank-app rows written before opens
			// replaced URL views still count here, as they always did.)
			$where[] = "app = ''";
		}
		if ( $artist_filter ) {
			$where[]  = 'artist LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $artist_filter ) . '%';
		}
		if ( $range_start ) {
			$where[]  = 'logged_at >= %s';
			$params[] = $range_start;
		}
		if ( $range_end ) {
			$where[]  = 'logged_at < %s';
			$params[] = $range_end;
		}
		$where_sql = implode( ' AND ', $where );

		if ( 'artist' === $report_type ) {
			$sql = "SELECT artist AS label, COUNT(*) AS total FROM {$table_name} WHERE {$where_sql} GROUP BY artist ORDER BY total DESC";
		} elseif ( 'album' === $report_type ) {
			$sql = "SELECT album AS label, artist, COUNT(*) AS total FROM {$table_name} WHERE {$where_sql} GROUP BY album, artist ORDER BY total DESC";
		} elseif ( 'app' === $report_type ) {
			$sql = "SELECT app AS label, COUNT(*) AS total FROM {$table_name} WHERE {$where_sql} GROUP BY app ORDER BY total DESC";
		} else {
			$sql = "SELECT track AS label, album, artist, COUNT(*) AS total FROM {$table_name} WHERE {$where_sql} GROUP BY track, album, artist ORDER BY total DESC";
		}

		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared );

		// Label + supporting columns + Total (2, or 3/4 with extras).
		$colspan = 2 + ( 'album' === $report_type ? 1 : 0 ) + ( 'track' === $report_type ? 2 : 0 );
		?>
		<table class="widefat striped" style="margin-top:1.5em;">
			<thead>
				<tr>
					<?php if ( 'artist' === $report_type ) : ?>
						<th><?php esc_html_e( 'Artist', 'v4v-musiclink-pro' ); ?></th>
					<?php elseif ( 'album' === $report_type ) : ?>
						<th><?php esc_html_e( 'Album', 'v4v-musiclink-pro' ); ?></th>
						<th><?php esc_html_e( 'Artist', 'v4v-musiclink-pro' ); ?></th>
					<?php elseif ( 'app' === $report_type ) : ?>
						<th><?php esc_html_e( 'Music App', 'v4v-musiclink-pro' ); ?></th>
					<?php else : ?>
						<th><?php esc_html_e( 'Track', 'v4v-musiclink-pro' ); ?></th>
						<th><?php esc_html_e( 'Album', 'v4v-musiclink-pro' ); ?></th>
						<th><?php esc_html_e( 'Artist', 'v4v-musiclink-pro' ); ?></th>
					<?php endif; ?>
					<th>
						<?php
						if ( 'app' === $report_type ) {
							esc_html_e( 'Total Launches', 'v4v-musiclink-pro' );
						} else {
							esc_html_e( 'Total Views', 'v4v-musiclink-pro' );
						}
						?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="<?php echo (int) $colspan; ?>"><?php esc_html_e( 'No matching data.', 'v4v-musiclink-pro' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( 'app' === $report_type ? $this->app_display_label( $row->label ) : ( $row->label ? $row->label : '—' ) ); ?></td>
							<?php if ( 'track' === $report_type ) : ?>
								<td><?php echo esc_html( $row->album ? $row->album : '—' ); ?></td>
							<?php endif; ?>
							<?php if ( in_array( $report_type, array( 'album', 'track' ), true ) ) : ?>
								<td><?php echo esc_html( $row->artist ? $row->artist : '—' ); ?></td>
							<?php endif; ?>
							<td><?php echo esc_html( $row->total ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders the log tab's table content only (used inside the tabbed
	 * settings page) - most recent 100 entries, newest first.
	 */
	private function render_log_tab_content() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'v4v_musiclink_pro_log';
		$rows       = $wpdb->get_results( "SELECT logged_at, artist, album, track, app FROM {$table_name} ORDER BY id DESC LIMIT 100" );
		$total      = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		// admin-post.php action for emptying the table, nonce-bound so a
		// crafted link (or an <img> hit) cannot wipe the log.
		$clear_url = wp_nonce_url(
			add_query_arg( array( 'action' => 'v4v_musiclink_pro_clear_log' ), admin_url( 'admin-post.php' ) ),
			'v4v_musiclink_pro_clear_log'
		);

		$just_cleared = isset( $_GET['cleared'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cleared'] ) );
		?>
		<?php if ( $just_cleared ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'View log cleared.', 'v4v-musiclink-pro' ); ?></p>
			</div>
		<?php endif; ?>
		<p>
			<?php
			printf(
				/* translators: %d: total number of log entries (search-result opens and app launches) */
				esc_html__( 'Showing the most recent 100 of %d total log entries.', 'v4v-musiclink-pro' ),
				(int) $total
			);
			?>
		</p>
		<?php if ( (int) $total > 0 ) : ?>
			<p>
				<a
					class="button button-secondary"
					href="<?php echo esc_url( $clear_url ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Clear the entire view log? This cannot be undone.', 'v4v-musiclink-pro' ) ); ?>');"
				>
					<?php esc_html_e( 'Clear log', 'v4v-musiclink-pro' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Artist', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Album', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Track', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'App', 'v4v-musiclink-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No views logged yet.', 'v4v-musiclink-pro' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row->logged_at, 'Y-m-d H:i:s' ) ); ?></td>
							<td><?php echo esc_html( $row->artist ); ?></td>
							<td><?php echo esc_html( $row->album ); ?></td>
							<td><?php echo esc_html( $row->track ? $row->track : '—' ); ?></td>
							<?php /* Blank app = a search-result open; a slug = the button that launched. */ ?>
							<td><?php echo esc_html( $row->app ? $this->app_display_label( $row->app ) : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * admin-post.php handler behind the "Clear log" button: empties the
	 * whole view table and returns to the tab with a confirmation notice.
	 * Nonce and capability are both checked before anything is written, so
	 * neither a forged link nor a logged-in non-admin can wipe it.
	 */
	public function handle_clear_log() {
		check_admin_referer( 'v4v_musiclink_pro_clear_log' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the view log.', 'v4v-musiclink-pro' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'v4v_musiclink_pro_log';

		// DELETE rather than TRUNCATE: needs only the DELETE privilege, so
		// it also works on restricted WordPress database users.
		$wpdb->query( "DELETE FROM {$table_name}" );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'v4v-musiclink-pro',
					'tab'     => 'log',
					'cleared' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/* ------------------------------------------------------------------------
	 * Cache table - Music Index metadata and the Fountain link, one row per
	 * release
	 *
	 * The row's identity is the album/track pair via lookup_hash, and it
	 * carries BOTH halves of what a render needs:
	 *
	 * - Music Index half (album_title, track_title, artist, image_url,
	 *   mi_updated_at): goes stale after the admin-set interval
	 *   (mi_refresh_interval(), default 12h) and is refreshed in the
	 *   BACKGROUND while the stale row keeps serving (stale-while-
	 *   revalidate). Rows are never evicted, at any age, at any setting;
	 *   a Music Index outage therefore cannot turn an already-seen
	 *   release into "Album/Song not found".
	 * - Fountain half (fountain_url, fountain_updated_at): permanent.
	 *   A row with a non-empty link is used verbatim - no search, no
	 *   write-back - so a link corrected by hand can never be silently
	 *   reverted; a missing row, or one whose link was blanked, runs the
	 *   normal search and then upserts the result.
	 *
	 * Refreshes are queued only from render() - never from the search box,
	 * the log beacons or the og: preview (all default-deny under
	 * $mi_refresh_allowed) - and run after the response is flushed, on the
	 * shutdown hook: no cron, no queue option. Absent rows may still be
	 * filled synchronously from any context (same API traffic as the old
	 * transient fill), because search and the beacons need titles.
	 * --------------------------------------------------------------------- */

	/**
	 * Creates the cache table if it doesn't exist yet. Checked on every
	 * load behind a cheap option flag rather than relying solely on
	 * register_activation_hook, since updates to this plugin are typically
	 * deployed by overwriting the file in place without a
	 * deactivate/reactivate cycle - which means a normal activation hook
	 * would never fire for this change. Same pattern as the log table.
	 *
	 * The migrations that used to run here first (the v1 stored-links
	 * fixup, then the copy of the old table into this one) have been
	 * removed: only an installation predating 1.20.23 could have needed
	 * them, and because every step was gated on columns/tables actually
	 * being present, a fresh install never ran them either.
	 *
	 * Each step is individually re-runnable, so a run interrupted half-way
	 * resumes on the next load instead of failing.
	 */
	private function maybe_create_cache_table() {
		if ( self::CACHE_TABLE_VERSION === (int) get_option( 'v4v_musiclink_pro_cache_table_version' ) ) {
			return;
		}

		self::create_cache_table();

		update_option( 'v4v_musiclink_pro_cache_table_version', self::CACHE_TABLE_VERSION );
	}

	private static function create_cache_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'v4v_musiclink_pro_cache';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lookup_hash CHAR(40) NOT NULL DEFAULT '',
			album_guid VARCHAR(256) NOT NULL DEFAULT '',
			track_guid VARCHAR(256) NOT NULL DEFAULT '',
			album_title VARCHAR(255) NOT NULL DEFAULT '',
			track_title VARCHAR(255) NOT NULL DEFAULT '',
			artist VARCHAR(255) NOT NULL DEFAULT '',
			image_url TEXT NOT NULL,
			fountain_url TEXT NOT NULL,
			mi_updated_at DATETIME NULL,
			fountain_updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lookup_hash (lookup_hash)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function cache_table() {
		global $wpdb;

		return $wpdb->prefix . 'v4v_musiclink_pro_cache';
	}

	/**
	 * The identity of a cache row: sha1( album_guid|track_guid ).
	 *
	 * The guids themselves are deliberately NOT part of any index.
	 * Music Index ids are free-form and guard_guid() allows up to 256
	 * bytes, so a composite unique index over two utf8mb4 columns can
	 * exceed InnoDB's index byte limit on older servers - and a failed
	 * CREATE TABLE would take the whole feature down with it. A fixed
	 * 40-byte hash keeps the unique key (and with it the "one row per
	 * release" rule) cheap and length-independent, while the raw guids
	 * stay in plain columns for display and filtering. Apps are not part
	 * of the identity at all: they share the row.
	 */
	private function cache_row_hash( $album, $track ) {
		return sha1( $album . '|' . $track );
	}

	/**
	 * The cache row for an exact album/track pair, memoized per request
	 * (false when no row exists), so the Music Index lookups and the
	 * Fountain link read make at most one query per pair per render.
	 * Writers drop their row from the memo so a later read in the same
	 * request re-queries instead of serving what was just superseded.
	 */
	private function cache_get_row( $album, $track ) {
		global $wpdb;
		$table = $this->cache_table();
		$hash  = $this->cache_row_hash( $album, $track );

		if ( array_key_exists( $hash, $this->row_memo ) ) {
			return $this->row_memo[ $hash ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE lookup_hash = %s LIMIT 1",
				$hash
			)
		);

		$this->row_memo[ $hash ] = $row ? $row : false;

		return $this->row_memo[ $hash ];
	}

	/**
	 * The cached Fountain link for an exact album/track pair, or '' when
	 * there is no row OR the row's link was blanked (an admin blanking
	 * the field is the documented way to force a fresh search).
	 * Returning '' for both cases means the caller only ever needs one
	 * branch: '' -> search.
	 */
	private function get_cached_fountain_url( $album, $track ) {
		$row = $this->cache_get_row( $album, $track );

		return ( $row && is_string( $row->fountain_url ) ) ? $row->fountain_url : '';
	}

	/**
	 * Persists a freshly resolved Fountain link plus the guids/titles it
	 * was resolved for. Only ever called from the path that had no usable
	 * cached link, so a link already in use cannot be overwritten here.
	 *
	 * Link-scoped on purpose: guids/titles only apply on insert, and the
	 * ON DUPLICATE KEY UPDATE touches fountain_url/fountain_updated_at
	 * ONLY - never the Music Index half (mi_updated_at), which this
	 * function does not own. ON DUPLICATE KEY rather than
	 * SELECT-then-INSERT: two concurrent first renders of the same
	 * release would otherwise race into a duplicate-key failure on the
	 * unique lookup_hash.
	 *
	 * fountain_updated_at stores site-local time (current_time('mysql')),
	 * and the admin table prints it raw - converting it again with
	 * get_date_from_gmt() would shift it by the site's GMT offset (the
	 * display half of O3).
	 */
	private function store_fountain_link( $album, $track, $url, $album_title, $track_title, $artist ) {
		global $wpdb;
		$table = $this->cache_table();
		$hash  = $this->cache_row_hash( $album, $track );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(lookup_hash, album_guid, track_guid, album_title, track_title, artist, image_url, fountain_url, fountain_updated_at)
				VALUES (%s, %s, %s, %s, %s, %s, '', %s, %s)
				ON DUPLICATE KEY UPDATE
					fountain_url = VALUES(fountain_url),
					fountain_updated_at = VALUES(fountain_updated_at)",
				$hash,
				$album,
				$track,
				$album_title,
				$track_title,
				$artist,
				$url,
				current_time( 'mysql' )
			)
		);

		unset( $this->row_memo[ $hash ] );
	}

	/**
	 * Whether an admin-supplied stored link is acceptable: always https,
	 * and on the app's own domain when we know it (fountain.fm for
	 * Fountain, including subdomains - matching the trust rule used for
	 * Fountain's script discovery). An unknown/future app gets the https
	 * check only, since there is no registry domain to compare against.
	 */
	private function is_valid_stored_url( $url, $app ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$registry = $this->app_registry();
		if ( ! isset( $registry[ $app ]['domain'] ) ) {
			return true;
		}

		$host   = strtolower( $parts['host'] );
		$domain = strtolower( $registry[ $app ]['domain'] );

		return $host === $domain || '.' . $domain === substr( $host, -1 * ( strlen( $domain ) + 1 ) );
	}

	/**
	 * The fields the Cache tab filters on, in display order.
	 *
	 * One list serves both the filter form and the URL that carries those
	 * filters across pagination and handler redirects, so the two cannot
	 * drift apart: the form writes `sl_` + key, the URL reads those same
	 * keys back.
	 */
	private function cache_filters() {
		return array(
			// Display order: the release, then its link, then when it was
			// last written - the table follows the same order. The art
			// URL and the two timestamps are deliberately not filterable.
			'artist'       => __( 'Artist', 'v4v-musiclink-pro' ),
			'album_title'  => __( 'Album title', 'v4v-musiclink-pro' ),
			'track_title'  => __( 'Track title', 'v4v-musiclink-pro' ),
			'album_guid'   => __( 'Album GUID', 'v4v-musiclink-pro' ),
			'track_guid'   => __( 'Track GUID', 'v4v-musiclink-pro' ),
			// One key per app link column (LINK_COLUMNS), labelled for
			// the app that owns one today.
			'fountain_url' => __( 'Fountain.fm link', 'v4v-musiclink-pro' ),
		);
	}

	/**
	 * URL of the Cache tab, carrying any active filters so pagination
	 * keeps the admin's current view.
	 */
	private function cache_admin_url( $extra = array() ) {
		$args = array(
			'page' => 'v4v-musiclink-pro',
			'tab'  => 'cache',
		);

		foreach ( array_keys( $this->cache_filters() ) as $field ) {
			$key = 'sl_' . $field;
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Where an admin-post handler lands after writing: back on the tab
	 * they came from, with its filters intact (the referer carries them),
	 * falling back to the plain tab URL when there is no referer to reuse.
	 */
	private function redirect_to_cache_tab( $extra = array() ) {
		$base = wp_get_referer();
		if ( ! $base || false === strpos( $base, 'page=v4v-musiclink-pro' ) ) {
			$base = $this->cache_admin_url();
		}

		wp_safe_redirect( add_query_arg( $extra, $base ) );
		exit;
	}

	/**
	 * Contents of the "Cache" tab: a per-field filter row, the matching
	 * rows (Music Index metadata + Fountain link, with inline link
	 * editing and per-row removal), and a clear control scoped either to
	 * the whole table or to one app. Every write goes through the
	 * admin-post.php handlers below; this view only reads and renders.
	 */
	private function render_cache_tab_content() {
		global $wpdb;

		$table    = $this->cache_table();
		$per_page = 50;

		// Field => column label, in display order. The keys are literals
		// from cache_filters() - never request data - so they are
		// safe to interpolate into SQL.
		$filter_fields = $this->cache_filters();

		$filters = array();
		foreach ( array_keys( $filter_fields ) as $field ) {
			$raw               = isset( $_GET[ 'sl_' . $field ] ) ? wp_unslash( $_GET[ 'sl_' . $field ] ) : '';
			$filters[ $field ] = sanitize_text_field( $raw );
		}

		$where  = array();
		$params = array();
		foreach ( $filters as $field => $value ) {
			if ( '' === $value ) {
				continue;
			}

			// Every filterable field is a literal column name from the map
			// above; only its value ever reaches the query, and that goes
			// through prepare().
			$where[]  = $field . ' LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $value ) . '%';
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where_sql}", $params ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$paged = isset( $_GET['sl_paged'] ) ? max( 1, (int) $_GET['sl_paged'] ) : 1;
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged = min( $paged, $pages );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, album_guid, track_guid, album_title, track_title, artist, image_url, fountain_url, mi_updated_at, fountain_updated_at
				FROM {$table}{$where_sql}
				ORDER BY fountain_updated_at DESC, id DESC
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) )
			)
		);

		// Apps that own a link column - there is no app column to read
		// them from, since rows are shared across apps (one per release).
		$link_apps = array_keys( self::LINK_COLUMNS );

		$plain_tab_url = admin_url( 'options-general.php?page=v4v-musiclink-pro&tab=cache' );
		?>
		<?php if ( isset( $_GET['sl_links_cleared'], $_GET['sl_cleared_app'] ) ) : ?>
			<?php // Per-app clear: the link column was blanked, the rows stay. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php printf(
					esc_html__( '%1$s link cleared on %2$s row(s).', 'v4v-musiclink-pro' ),
					esc_html( $this->app_display_label( sanitize_text_field( wp_unslash( $_GET['sl_cleared_app'] ) ) ) ),
					esc_html( sanitize_text_field( wp_unslash( $_GET['sl_links_cleared'] ) ) )
				); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_cleared'] ) ) : ?>
			<?php // Clear all: the rows themselves are gone. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php printf( esc_html__( '%s cache row(s) removed.', 'v4v-musiclink-pro' ), esc_html( sanitize_text_field( wp_unslash( $_GET['sl_cleared'] ) ) ) ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Fountain.fm link updated.', 'v4v-musiclink-pro' ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_art_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Artwork URL updated.', 'v4v-musiclink-pro' ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Cache row removed. It will be created again if the release is viewed again.', 'v4v-musiclink-pro' ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'That link was not saved: it must be an https URL on this app\'s own domain.', 'v4v-musiclink-pro' ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_art_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'That artwork URL was not saved: it must be an http(s) URL, or blank to remove the artwork.', 'v4v-musiclink-pro' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'One row per release: cached Music Index metadata (titles, artist, artwork) plus the resolved Fountain.fm link. Artwork and the Fountain.fm link are edited here per row; titles and artist refresh in the background on the interval set under Settings ("Music Index refresh") while stale rows keep serving.', 'v4v-musiclink-pro' ); ?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin:1em 0;">
			<input type="hidden" name="page" value="v4v-musiclink-pro" />
			<input type="hidden" name="tab" value="cache" />
			<?php foreach ( $filter_fields as $field => $label ) : ?>
				<label style="flex:1 1 130px; min-width:110px;">
					<span style="display:block;"><?php echo esc_html( $label ); ?></span>
					<input type="text" name="sl_<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $filters[ $field ] ); ?>" style="width:100%;" />
				</label>
			<?php endforeach; ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'v4v-musiclink-pro' ); ?></button>
			<a class="button" href="<?php echo esc_url( $plain_tab_url ); ?>"><?php esc_html_e( 'Clear filters', 'v4v-musiclink-pro' ); ?></a>
		</form>

		<?php if ( $total > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 1em;" onsubmit="var label = this.scope.options[ this.scope.selectedIndex ].text; return confirm( 'all' === this.scope.value ? '<?php echo esc_js( __( 'Clear all cached rows? Every row is removed - hand-edited links included - and each release re-resolves when it is viewed again.', 'v4v-musiclink-pro' ) ); ?>' : label + ' <?php echo esc_js( __( 'The rows stay, but those links are blanked and re-resolve when their releases are viewed again.', 'v4v-musiclink-pro' ) ); ?>' );">
				<input type="hidden" name="action" value="v4v_musiclink_pro_clear_cache" />
				<?php wp_nonce_field( 'v4v_musiclink_pro_clear_cache' ); ?>
				<label for="v4v-sl-clear-scope"><?php esc_html_e( 'Clear cache:', 'v4v-musiclink-pro' ); ?></label>
				<select name="scope" id="v4v-sl-clear-scope">
					<option value="all"><?php esc_html_e( 'Clear all cached rows', 'v4v-musiclink-pro' ); ?></option>
					<?php foreach ( $link_apps as $app_slug ) : ?>
						<option value="<?php echo esc_attr( $app_slug ); ?>"><?php printf( esc_html__( 'Clear %s links', 'v4v-musiclink-pro' ), esc_html( $this->app_display_label( $app_slug ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Clear', 'v4v-musiclink-pro' ); ?></button>
			</form>
		<?php endif; ?>

		<p>
			<?php printf( esc_html__( '%s cached row(s).', 'v4v-musiclink-pro' ), esc_html( number_format_i18n( $total ) ) ); ?>
			<?php if ( $pages > 1 ) : ?>
				<?php if ( $paged > 1 ) : ?>
					<a class="button button-small" href="<?php echo esc_url( $this->cache_admin_url( array( 'sl_paged' => $paged - 1 ) ) ); ?>">&laquo;</a>
				<?php endif; ?>
				<span class="paging-input"><?php echo esc_html( number_format_i18n( $paged ) ); ?> of <?php echo esc_html( number_format_i18n( $pages ) ); ?></span>
				<?php if ( $paged < $pages ) : ?>
					<a class="button button-small" href="<?php echo esc_url( $this->cache_admin_url( array( 'sl_paged' => $paged + 1 ) ) ); ?>">&raquo;</a>
				<?php endif; ?>
			<?php endif; ?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Metadata Updated', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Artist', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Album title', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Track title', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Album GUID', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Track GUID', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Art', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Fountain.fm link', 'v4v-musiclink-pro' ); ?></th>
					<th><?php esc_html_e( 'Fountain updated', 'v4v-musiclink-pro' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'v4v-musiclink-pro' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No cached rows match.', 'v4v-musiclink-pro' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $art_label = trim( $row->artist . ' - ' . ( $row->album_title ? $row->album_title : (string) $row->track_title ) ); ?>
						<?php // Timestamps are stored as MySQL datetimes; only the date part is useful here. ?>
						<tr>
							<td><?php echo esc_html( $row->mi_updated_at ? substr( $row->mi_updated_at, 0, 10 ) : '—' ); ?></td>
							<td><?php echo esc_html( $row->artist ); ?></td>
							<td><?php echo esc_html( $row->album_title ); ?></td>
							<td><?php echo esc_html( $row->track_title ? $row->track_title : '—' ); ?></td>
							<td class="code"><?php echo esc_html( $row->album_guid ); ?></td>
							<td class="code"><?php echo esc_html( $row->track_guid ? $row->track_guid : '—' ); ?></td>
							<td>
								<?php // Tick or cross says whether an artwork URL is stored;
								// clicking opens the modal below to rewrite or blank it. ?>
								<button type="button" class="button button-small v4v-sl-art-toggle"
									data-id="<?php echo (int) $row->id; ?>"
									data-url="<?php echo esc_attr( (string) $row->image_url ); ?>"
									data-label="<?php echo esc_attr( $art_label ); ?>"
									title="<?php echo esc_attr( $row->image_url ? __( 'Edit artwork URL', 'v4v-musiclink-pro' ) : __( 'Add an artwork URL', 'v4v-musiclink-pro' ) ); ?>">
									<?php if ( $row->image_url ) : ?>
										<span style="color:#00a32a; font-size:16px; line-height:1;" aria-hidden="true">&#10003;</span>
										<span class="screen-reader-text"><?php esc_html_e( 'Artwork URL stored - edit it', 'v4v-musiclink-pro' ); ?></span>
									<?php else : ?>
										<span style="color:#d63638; font-size:16px; line-height:1;" aria-hidden="true">&#10007;</span>
										<span class="screen-reader-text"><?php esc_html_e( 'No artwork URL stored - add one', 'v4v-musiclink-pro' ); ?></span>
									<?php endif; ?>
								</button>
							</td>
							<td style="white-space:nowrap;">
								<button type="button" class="button button-small v4v-sl-edit-toggle" data-id="<?php echo (int) $row->id; ?>"><?php esc_html_e( 'Edit', 'v4v-musiclink-pro' ); ?></button>
								<?php if ( $row->fountain_url ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $row->fountain_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Launch', 'v4v-musiclink-pro' ); ?></a>
								<?php else : ?>
									<span style="color:#d63638; font-size:16px; line-height:1;" aria-hidden="true">&#10007;</span>
									<span class="screen-reader-text"><?php esc_html_e( 'No Fountain.fm link stored', 'v4v-musiclink-pro' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( substr( (string) $row->fountain_updated_at, 0, 10 ) ); ?></td>
							<td style="white-space:nowrap;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="v4v_musiclink_pro_delete_cache_row" />
									<input type="hidden" name="link_id" value="<?php echo (int) $row->id; ?>" />
									<?php wp_nonce_field( 'v4v_musiclink_pro_delete_cache_row' ); ?>
									<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Remove this cached row? The release re-resolves the next time it is viewed.', 'v4v-musiclink-pro' ) ); ?>');"><?php esc_html_e( 'Remove', 'v4v-musiclink-pro' ); ?></button>
								</form>
							</td>
						</tr>
						<tr id="v4v-sl-edit-<?php echo (int) $row->id; ?>" hidden>
							<td colspan="10">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:0.5em 0;">
									<input type="hidden" name="action" value="v4v_musiclink_pro_update_cache_row" />
									<input type="hidden" name="link_id" value="<?php echo (int) $row->id; ?>" />
									<?php // Which column is being edited; whitelisted against CACHE_EDIT_COLUMNS. ?>
									<input type="hidden" name="link_field" value="fountain" />
									<?php wp_nonce_field( 'v4v_musiclink_pro_update_cache_row' ); ?>
									<label for="v4v-sl-url-<?php echo (int) $row->id; ?>"><?php esc_html_e( 'Fountain.fm link', 'v4v-musiclink-pro' ); ?></label>
									<input type="url" class="regular-text code" id="v4v-sl-url-<?php echo (int) $row->id; ?>" name="url" value="<?php echo esc_attr( $row->fountain_url ); ?>" style="flex:1 1 320px;" />
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'v4v-musiclink-pro' ); ?></button>
									<span class="description"><?php esc_html_e( 'Leave blank to clear it: the next page view re-runs the Fountain search and stores the result again. Titles and artist are read-only - the next refresh overwrites them.', 'v4v-musiclink-pro' ); ?></span>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		// Artwork editor: one shared dialog, filled from the row's art
		// control. The wrapper is display:none inline rather than hidden,
		// so the flex layout below is never fighting the [hidden] rule.
		?>
		<div id="v4v-sl-art-modal" style="position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,.55); display:none; align-items:center; justify-content:center;">
			<div style="background:#fff; padding:1em 1.25em 1.25em; max-width:560px; width:92%; box-shadow:0 3px 14px rgba(0,0,0,.3); border-radius:4px;">
				<h2 style="margin-top:0;" id="v4v-sl-art-title"></h2>
				<p id="v4v-sl-art-preview" style="margin:.5em 0 1em;">
					<img id="v4v-sl-art-img" alt="" style="max-width:100%; max-height:320px; display:block;" />
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="v4v_musiclink_pro_update_cache_row" />
					<input type="hidden" name="link_id" id="v4v-sl-art-id" value="" />
					<input type="hidden" name="link_field" value="art" />
					<?php wp_nonce_field( 'v4v_musiclink_pro_update_cache_row' ); ?>
					<label for="v4v-sl-art-url"><?php esc_html_e( 'Artwork URL', 'v4v-musiclink-pro' ); ?></label>
					<input type="url" class="regular-text code" id="v4v-sl-art-url" name="url" value="" style="width:100%; box-sizing:border-box;" />
					<p class="description"><?php esc_html_e( 'Leave blank to remove the artwork. The next Music Index refresh fills it in again unless the row is still within its refresh interval.', 'v4v-musiclink-pro' ); ?></p>
					<p style="margin-bottom:0; display:flex; gap:8px;">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Update', 'v4v-musiclink-pro' ); ?></button>
						<button type="button" class="button v4v-sl-art-close"><?php esc_html_e( 'Cancel', 'v4v-musiclink-pro' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<script>
		document.querySelectorAll( '.v4v-sl-edit-toggle' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var row = document.getElementById( 'v4v-sl-edit-' + button.getAttribute( 'data-id' ) );
				if ( row ) { row.hidden = ! row.hidden; }
			} );
		} );
		( function () {
			var modal = document.getElementById( 'v4v-sl-art-modal' );
			if ( ! modal ) { return; }
			var close = function () { modal.style.display = 'none'; };
			document.querySelectorAll( '.v4v-sl-art-toggle' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var url   = button.getAttribute( 'data-url' ) || '';
					var image = document.getElementById( 'v4v-sl-art-img' );
					document.getElementById( 'v4v-sl-art-id' ).value    = button.getAttribute( 'data-id' ) || '';
					document.getElementById( 'v4v-sl-art-title' ).textContent = button.getAttribute( 'data-label' ) || '';
					document.getElementById( 'v4v-sl-art-url' ).value   = url;
					document.getElementById( 'v4v-sl-art-preview' ).style.display = url ? '' : 'none';
					image.src = url;
					modal.style.display = 'flex';
					document.getElementById( 'v4v-sl-art-url' ).focus();
				} );
			} );
			modal.querySelectorAll( '.v4v-sl-art-close' ).forEach( function ( button ) {
				button.addEventListener( 'click', close );
			} );
			modal.addEventListener( 'click', function ( event ) {
				if ( event.target === modal ) { close(); }
			} );
			document.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && 'flex' === modal.style.display ) { close(); }
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * admin-post.php handler behind both Cache-tab edit controls: the
	 * Fountain link (or a blank, forcing a re-search) must be an https
	 * URL on the app's own domain; the artwork URL only has to be
	 * http(s), or blank to drop the art. Each edit stamps its own half's
	 * timestamp - fountain_updated_at for the link, mi_updated_at for
	 * artwork, which also defers the next background pull - and leaves
	 * the other half alone.
	 */
	public function handle_update_cache_row() {
		check_admin_referer( 'v4v_musiclink_pro_update_cache_row' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit cache rows.', 'v4v-musiclink-pro' ) );
		}

		global $wpdb;
		$table = $this->cache_table();
		$id    = isset( $_POST['link_id'] ) ? (int) $_POST['link_id'] : 0;
		$url   = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '';

		// Which column the form was editing: whitelisted against
		// CACHE_EDIT_COLUMNS, so a field name can never reach SQL. The
		// app slug (fountain) is what the domain check below keys off;
		// artwork is not domain-bound and gets the looser check instead.
		$field  = isset( $_POST['link_field'] ) ? sanitize_key( wp_unslash( $_POST['link_field'] ) ) : '';
		$column = isset( self::CACHE_EDIT_COLUMNS[ $field ] ) ? self::CACHE_EDIT_COLUMNS[ $field ] : '';
		$is_art = 'art' === $field;

		$exists = ( $id && '' !== $column )
			? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) )
			: null;

		$ok_url = $is_art
			? ( '' === $url || 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) )
			: ( '' === $url || $this->is_valid_stored_url( $url, $field ) );

		// The edit's own timestamp: the link half for a link edit, the
		// metadata half for artwork (which also defers the next pull).
		$stamp = $is_art ? 'mi_updated_at' : 'fountain_updated_at';

		$valid = $id && null !== $exists && '' !== $column && $ok_url;
		if ( $valid ) {
			$wpdb->update(
				$table,
				array(
					$column => $url,
					$stamp  => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		$result = $valid
			? ( $is_art ? 'sl_art_saved' : 'sl_saved' )
			: ( $is_art ? 'sl_art_error' : 'sl_error' );

		$this->redirect_to_cache_tab( array( $result => '1' ) );
	}

	/**
	 * admin-post.php handler behind each row's Remove button: deletes the
	 * one row (never the table), so the release simply re-resolves the
	 * next time it is viewed.
	 */
	public function handle_delete_cache_row() {
		check_admin_referer( 'v4v_musiclink_pro_delete_cache_row' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to remove cache rows.', 'v4v-musiclink-pro' ) );
		}

		$id = isset( $_POST['link_id'] ) ? (int) $_POST['link_id'] : 0;
		if ( $id ) {
			global $wpdb;
			$wpdb->delete( $this->cache_table(), array( 'id' => $id ), array( '%d' ) );
		}

		$this->redirect_to_cache_tab( array( 'sl_deleted' => '1' ) );
	}

	/**
	 * admin-post.php handler behind the Clear control: empties the whole
	 * table, or - for a single app - blanks only the link column and
	 * leaves every other field of those rows in place (a cleared row
	 * shows the empty-link cross until it re-resolves on its next
	 * view). Returns to the tab with a count either way. The
	 * DELETE for Clear all rather than TRUNCATE: it needs only the DELETE
	 * privilege, so it also works on restricted WordPress database users
	 * (same reasoning as the view log's clear handler above).
	 */
	public function handle_clear_cache() {
		check_admin_referer( 'v4v_musiclink_pro_clear_cache' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache.', 'v4v-musiclink-pro' ) );
		}

		global $wpdb;
		$table = $this->cache_table();
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';

		if ( 'all' === $scope ) {
			// Purge the rows outright - the only bulk way to drop entries
			// (including their guids, titles and any hand-edited link).
			$cleared = (int) $wpdb->query( "DELETE FROM {$table}" );

			$this->redirect_to_cache_tab( array( 'sl_cleared' => (string) $cleared ) );
		} elseif ( isset( self::LINK_COLUMNS[ $scope ] ) ) {
			// Blank that app's link column only: guids, titles, artist,
			// art and the MI timestamp survive on the shared row, and
			// fountain_updated_at records the clear the same way a hand
			// edit does.
			$column  = self::LINK_COLUMNS[ $scope ];
			$cleared = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET {$column} = '', fountain_updated_at = %s WHERE {$column} <> ''",
					current_time( 'mysql' )
				)
			);

			$this->redirect_to_cache_tab( array(
				'sl_links_cleared' => (string) $cleared,
				'sl_cleared_app'   => $scope,
			) );
		} else {
			$this->redirect_to_cache_tab( array( 'sl_cleared' => '0' ) );
		}
	}

	public function register_settings() {
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_fountain_next_action', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_social_preview_mode', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_social_preview_mode' ),
			'default'           => 'plugin_only',
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_show_header', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_random_app_order', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_apps', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_apps_settings' ),
			'default'           => array(),
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_log_app_rate', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_log_rate' ),
			'default'           => 30,
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_log_open_rate', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_log_rate' ),
			'default'           => 30,
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_log_max_rows', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_log_max_rows' ),
			'default'           => 100000,
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_log_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_mi_refresh_hours', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_mi_refresh_hours' ),
			'default'           => self::MI_REFRESH_DEFAULT_HOURS,
		) );
	}

	/**
	 * Admin "Music Index refresh" hours: absint, then 0/empty/non-numeric
	 * fall back to the default (12), then clamp to 1..720 (1 hour ..
	 * 30 days).
	 *
	 * @param mixed $value Raw option value from the settings form.
	 * @return int Hours between background MI refreshes.
	 */
	public function sanitize_mi_refresh_hours( $value ) {
		$hours = absint( $value );
		if ( ! $hours ) {
			$hours = self::MI_REFRESH_DEFAULT_HOURS;
		}

		return min( max( $hours, 1 ), 720 );
	}

	/**
	 * Sanitize callback for both log-write rate settings: accepted
	 * requests per minute, per client IP, for one beacon endpoint.
	 * Blank/zero/garbage falls back to the 30/min default rather than 0 -
	 * a 0 saved here would silently turn every log beacon into a 429 and
	 * quietly stop all logging - and anything above 600/min (10 per
	 * second) is clamped, so the setting can tune the budget but can't
	 * remove the flood guard entirely.
	 *
	 * @param mixed $value Raw option value from the settings form.
	 * @return int Allowed requests per minute, 1..600.
	 */
	public function sanitize_log_rate( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			return 30;
		}
		return min( $value, 600 );
	}

	/**
	 * Sanitize callback for the log row cap: the newest this many rows
	 * are kept (see prune_log()). Blank/zero/garbage falls back to the
	 * 100,000 default, and the value is clamped to 1,000..1,000,000 -
	 * below a thousand the log is useless and prune churns on nearly
	 * every insert, above a million the reports scan badly (the reason
	 * the cap exists at all).
	 *
	 * @param mixed $value Raw option value from the settings form.
	 * @return int Maximum rows kept, 1000..1000000.
	 */
	public function sanitize_log_max_rows( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			return 100000;
		}
		return max( 1000, min( $value, 1000000 ) );
	}

	/**
	 * Static metadata for each target app - single source of truth for
	 * slug/label/domain used both when building links and rendering the
	 * per-app enable/disable + icon-override settings.
	 */
	private function app_registry() {
		return array(
			'stablekraft' => array( 'label' => 'StableKraft.app', 'domain' => 'stablekraft.app' ),
			'podtunes'    => array( 'label' => 'PodTunes.app', 'domain' => 'podtunes.app' ),
			'v4vmusic'    => array( 'label' => 'v4vmusic.com', 'domain' => 'v4vmusic.com' ),
			'fountain'    => array( 'label' => 'Fountain.fm', 'domain' => 'fountain.fm' ),
		);
	}

	/**
	 * Display name for a stored app slug. Falls back to the slug itself so
	 * a row written before an app was removed from the registry still
	 * renders as something readable instead of a blank cell.
	 */
	private function app_display_label( $slug ) {
		$registry = $this->app_registry();
		return isset( $registry[ $slug ] ) ? $registry[ $slug ]['label'] : (string) $slug;
	}

	private function get_app_config( $slug ) {
		$all      = get_option( 'v4v_musiclink_pro_apps', array() );
		$defaults = array( 'enabled' => true, 'icon_url' => '' );
		return isset( $all[ $slug ] ) && is_array( $all[ $slug ] )
			? wp_parse_args( $all[ $slug ], $defaults )
			: $defaults;
	}

	private function is_app_enabled( $slug ) {
		return (bool) $this->get_app_config( $slug )['enabled'];
	}

	/**
	 * The icon actually used for an app's button: the admin-configured
	 * override if one is set, otherwise the live favicon fallback.
	 */
	private function get_app_icon( $slug, $domain ) {
		$config = $this->get_app_config( $slug );
		return ! empty( $config['icon_url'] ) ? $config['icon_url'] : $this->favicon_url( $domain );
	}

	public function sanitize_apps_settings( $value ) {
		$slugs  = array_keys( $this->app_registry() );
		$orders = array();
		$clean  = array();

		foreach ( $slugs as $slug ) {
			$row = isset( $value[ $slug ] ) && is_array( $value[ $slug ] ) ? $value[ $slug ] : array();
			if ( isset( $row['order'] ) && '' !== $row['order'] ) {
				$orders[ $slug ] = $row['order'];
			}
			$clean[ $slug ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'icon_url' => ! empty( $row['icon_url'] ) ? esc_url_raw( trim( $row['icon_url'] ) ) : '',
			);
		}

		// Positions are always stored as unique 0..n-1 values, so a saved
		// order survives being re-saved without drifting or colliding.
		foreach ( self::sort_slugs_by_order( $slugs, $orders ) as $position => $slug ) {
			$clean[ $slug ]['order'] = $position;
		}

		return $clean;
	}

	/**
	 * App slugs sorted by saved position. A slug with no saved position
	 * (never configured) keeps its registry slot, and exact ties keep the
	 * registry order too - usort() is only stable from PHP 8.0.
	 */
	private static function sort_slugs_by_order( $slugs, $orders ) {
		$registry_order = array_flip( $slugs );
		$sorted         = $slugs;

		usort( $sorted, function ( $a, $b ) use ( $registry_order, $orders ) {
			$a_position = isset( $orders[ $a ] ) ? (int) $orders[ $a ] : $registry_order[ $a ];
			$b_position = isset( $orders[ $b ] ) ? (int) $orders[ $b ] : $registry_order[ $b ];

			if ( $a_position === $b_position ) {
				return $registry_order[ $a ] - $registry_order[ $b ];
			}

			return $a_position - $b_position;
		} );

		return $sorted;
	}

	/**
	 * The app registry in the admin-configured button order. With no order
	 * saved yet, this matches app_registry()'s hard-coded order exactly.
	 */
	private function ordered_app_registry() {
		$registry = $this->app_registry();
		$all      = get_option( 'v4v_musiclink_pro_apps', array() );
		$orders   = array();

		foreach ( array_keys( $registry ) as $slug ) {
			if ( isset( $all[ $slug ]['order'] ) && '' !== $all[ $slug ]['order'] ) {
				$orders[ $slug ] = $all[ $slug ]['order'];
			}
		}

		$ordered = array();
		foreach ( self::sort_slugs_by_order( array_keys( $registry ), $orders ) as $slug ) {
			$ordered[ $slug ] = $registry[ $slug ];
		}

		return $ordered;
	}

	/**
	 * Reorders the assembled shortcode link list to match the admin-
	 * configured button order. Apps whose link couldn't be built simply
	 * stay missing; any link outside the registry keeps its old slot.
	 */
	private function sort_links_by_app_order( $links ) {
		$sorted = array();

		foreach ( array_keys( $this->ordered_app_registry() ) as $slug ) {
			if ( isset( $links[ $slug ] ) ) {
				$sorted[ $slug ] = $links[ $slug ];
				unset( $links[ $slug ] );
			}
		}

		return $sorted + $links;
	}

	public function sanitize_social_preview_mode( $value ) {
		return in_array( $value, array( 'off', 'plugin_only', 'entire_site' ), true ) ? $value : 'plugin_only';
	}

	private function get_social_preview_mode() {
		return get_option( 'v4v_musiclink_pro_social_preview_mode', 'plugin_only' );
	}

	/**
	 * This plugin's version, read live from the file header above so the
	 * "Version:" line remains the only copy bump-version.sh has to update.
	 */
	private function plugin_version() {
		$version = get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' );

		return isset( $version['Version'] ) ? $version['Version'] : '';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $active_tab, array( 'settings', 'log', 'reports', 'cache' ), true ) ) {
			$active_tab = 'settings';
		}
		$mode = $this->get_social_preview_mode();
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'V4V MusicLink Pro Settings', 'v4v-musiclink-pro' ); ?>
				<span class="description">
					<?php
					printf(
						/* translators: %s: this plugin's version number. */
						esc_html__( 'Version %s', 'v4v-musiclink-pro' ),
						esc_html( $this->plugin_version() )
					);
					?>
				</span>
			</h1>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=v4v-musiclink-pro&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'v4v-musiclink-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=v4v-musiclink-pro&tab=log' ) ); ?>" class="nav-tab <?php echo 'log' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'View Log', 'v4v-musiclink-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=v4v-musiclink-pro&tab=reports' ) ); ?>" class="nav-tab <?php echo 'reports' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Reports', 'v4v-musiclink-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=v4v-musiclink-pro&tab=cache' ) ); ?>" class="nav-tab <?php echo 'cache' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Cache', 'v4v-musiclink-pro' ); ?>
				</a>
			</h2>

			<?php if ( 'log' === $active_tab ) : ?>
				<?php $this->render_log_tab_content(); ?>
			<?php elseif ( 'reports' === $active_tab ) : ?>
				<?php $this->render_reports_tab_content(); ?>
			<?php elseif ( 'cache' === $active_tab ) : ?>
				<?php $this->render_cache_tab_content(); ?>
			<?php else : ?>
				<form method="post" action="options.php">
				<?php settings_fields( 'v4v_musiclink_pro' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Social media link previews', 'v4v-musiclink-pro' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block; margin-bottom:0.75em;">
									<input type="radio" name="v4v_musiclink_pro_social_preview_mode" value="off" <?php checked( $mode, 'off' ); ?> />
									<strong><?php esc_html_e( 'Off', 'v4v-musiclink-pro' ); ?></strong>
									<p class="description" style="margin-left:1.5em;">
										<?php esc_html_e( 'This plugin will not add or change any link-preview (Open Graph/Twitter Card) tags at all. The musiclink page will show whatever preview your theme or SEO plugin normally produces for it (which may be no preview, or a generic one) - nothing else on your site is touched, but you also lose release art/title previews for music links.', 'v4v-musiclink-pro' ); ?>
									</p>
								</label>
								<label style="display:block; margin-bottom:0.75em;">
									<input type="radio" name="v4v_musiclink_pro_social_preview_mode" value="plugin_only" <?php checked( $mode, 'plugin_only' ); ?> />
									<strong><?php esc_html_e( 'On (this plugin only)', 'v4v-musiclink-pro' ); ?></strong>
									<p class="description" style="margin-left:1.5em;">
										<?php esc_html_e( 'This plugin adds its own preview tags (release art, title, artist) to the musiclink page only. If you also have an SEO plugin generating its own preview tags site-wide (e.g. a "Social Networks" feature), you may end up with two competing sets of tags on this one page specifically, which can cause an inconsistent or wrong preview depending on which tag a given platform reads first. Every other page on your site is completely unaffected either way.', 'v4v-musiclink-pro' ); ?>
									</p>
								</label>
								<label style="display:block;">
									<input type="radio" name="v4v_musiclink_pro_social_preview_mode" value="entire_site" <?php checked( $mode, 'entire_site' ); ?> />
									<strong><?php esc_html_e( 'Entire Site', 'v4v-musiclink-pro' ); ?></strong>
									<p class="description" style="margin-left:1.5em;">
										<?php esc_html_e( 'Instead of adding new tags, this plugin corrects the existing preview tags your theme or SEO plugin already produces - but only on the musiclink page. This avoids duplicate/conflicting tags and lets your SEO plugin keep generating previews normally on every other page of your site (recommended if you use an SEO plugin\'s own social-preview feature site-wide, e.g. SiteSEO\'s "Social Networks" feature).', 'v4v-musiclink-pro' ); ?>
									</p>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Release header', 'v4v-musiclink-pro' ); ?>
						</th>
						<td>
							<?php $show_header_value = get_option( 'v4v_musiclink_pro_show_header', true ) ? '1' : '0'; ?>
							<select id="v4v_musiclink_pro_show_header" name="v4v_musiclink_pro_show_header">
								<option value="1" <?php selected( $show_header_value, '1' ); ?>><?php esc_html_e( 'Show', 'v4v-musiclink-pro' ); ?></option>
								<option value="0" <?php selected( $show_header_value, '0' ); ?>><?php esc_html_e( 'Hide', 'v4v-musiclink-pro' ); ?></option>
							</select>
							<label for="v4v_musiclink_pro_show_header"><?php esc_html_e( ' the release title, artist and artwork above the music app buttons', 'v4v-musiclink-pro' ); ?></label>
							<p class="description">
								<?php esc_html_e( 'This is the site-wide default for every [musiclink_pro] shortcode. It can be overridden per placement with the optional header attribute: header="false" hides the header for that instance, header="true" shows it, regardless of this setting.', 'v4v-musiclink-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Music apps', 'v4v-musiclink-pro' ); ?></th>
						<td>
							<table class="widefat" style="max-width:760px;">
								<thead>
									<tr>
										<th style="width:100px;"><?php esc_html_e( 'Order', 'v4v-musiclink-pro' ); ?></th>
										<th style="width:60px;"><?php esc_html_e( 'Active', 'v4v-musiclink-pro' ); ?></th>
										<th><?php esc_html_e( 'App', 'v4v-musiclink-pro' ); ?></th>
										<th style="width:60px;"><?php esc_html_e( 'Icon', 'v4v-musiclink-pro' ); ?></th>
										<th><?php esc_html_e( 'Icon override URL', 'v4v-musiclink-pro' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php $position = 0; ?>
									<?php foreach ( $this->ordered_app_registry() as $slug => $app ) : ?>
										<?php
										$config        = $this->get_app_config( $slug );
										$preview        = $this->get_app_icon( $slug, $app['domain'] );
										$default_icon   = $this->favicon_url( $app['domain'] );
										$field_name     = 'v4v_musiclink_pro_apps[' . $slug . ']';
										?>
										<tr class="v4v-app-row">
											<td style="width:100px; white-space:nowrap;">
												<input
													type="hidden"
													class="v4v-app-order-input"
													name="<?php echo esc_attr( $field_name ); ?>[order]"
													value="<?php echo esc_attr( $position ); ?>"
												/>
												<span class="v4v-app-order-position"><?php echo esc_html( $position + 1 ); ?></span>
												<button type="button" class="button button-small v4v-app-move-up" title="<?php echo esc_attr( sprintf( __( 'Move %s up', 'v4v-musiclink-pro' ), $app['label'] ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s up', 'v4v-musiclink-pro' ), $app['label'] ) ); ?>">&#8593;</button>
												<button type="button" class="button button-small v4v-app-move-down" title="<?php echo esc_attr( sprintf( __( 'Move %s down', 'v4v-musiclink-pro' ), $app['label'] ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s down', 'v4v-musiclink-pro' ), $app['label'] ) ); ?>">&#8595;</button>
											</td>
											<td>
												<input
													type="checkbox"
													name="<?php echo esc_attr( $field_name ); ?>[enabled]"
													value="1"
													<?php checked( $config['enabled'] ); ?>
												/>
											</td>
											<td><?php echo esc_html( $app['label'] ); ?></td>
											<td>
												<img
													class="v4v-app-icon-preview"
													src="<?php echo esc_url( $preview ); ?>"
													alt=""
													width="32"
													height="32"
													style="display:block; width:32px; height:32px; border-radius:4px;"
												/>
											</td>
											<td>
												<input
													type="url"
													class="v4v-app-icon-input regular-text"
													name="<?php echo esc_attr( $field_name ); ?>[icon_url]"
													value="<?php echo esc_attr( $config['icon_url'] ); ?>"
													placeholder="<?php esc_attr_e( 'Leave blank to use the default favicon', 'v4v-musiclink-pro' ); ?>"
													data-default-icon="<?php echo esc_url( $default_icon ); ?>"
												/>
												<button type="button" class="button v4v-app-icon-set"><?php esc_html_e( 'Set', 'v4v-musiclink-pro' ); ?></button>
												<span class="v4v-app-icon-warning" style="color:#a00; display:none;"><?php esc_html_e( 'Image could not be loaded from that URL.', 'v4v-musiclink-pro' ); ?></span>
											</td>
										</tr>
									<?php $position++; ?>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description">
								<?php esc_html_e( 'Unchecking "Active" hides that app\'s button entirely wherever the [musiclink_pro] shortcode is used. Use the arrows in the "Order" column to set the order the buttons appear in on the front end, then click "Save Changes" below to apply it. Click "Set" to preview an icon override before saving - the preview updates immediately, but nothing is saved until you click "Save Changes" below.', 'v4v-musiclink-pro' ); ?>
							</p>
							<script>
							(function () {
								var SPINNER_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50" width="32" height="32">' +
									'<circle cx="25" cy="25" r="20" fill="none" stroke="#ddd" stroke-width="5"/>' +
									'<circle cx="25" cy="25" r="20" fill="none" stroke="#666" stroke-width="5" stroke-dasharray="31.4 94.2" stroke-linecap="round">' +
									'<animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="0.8s" repeatCount="indefinite"/>' +
									'</circle></svg>';
								var SPINNER_URI = 'data:image/svg+xml;base64,' + window.btoa( SPINNER_SVG );

								document.querySelectorAll( '.v4v-app-icon-set' ).forEach( function ( button ) {
								button.addEventListener( 'click', function () {
									var row     = button.closest( '.v4v-app-row' );
									var input   = row.querySelector( '.v4v-app-icon-input' );
									var preview = row.querySelector( '.v4v-app-icon-preview' );
									var warning = row.querySelector( '.v4v-app-icon-warning' );
									var typed   = input.value.trim();
									var url     = typed || input.getAttribute( 'data-default-icon' );

									warning.style.display = 'none';
									preview.src = SPINNER_URI;

									var tester = new Image();
									tester.onload = function () {
										preview.src = url;
									};
									tester.onerror = function () {
										if ( typed ) {
											warning.style.display = 'inline';
										}
										preview.src = url; // standard broken-image icon, per design
									};
									tester.src = url;
								} );
							} );
							})();
							(function () {
								function v4vRenumberApps() {
									var rows = document.querySelectorAll( '.v4v-app-row' );
									rows.forEach( function ( row, index ) {
										row.querySelector( '.v4v-app-order-input' ).value = index;
										row.querySelector( '.v4v-app-order-position' ).textContent = index + 1;
										row.querySelector( '.v4v-app-move-up' ).disabled = ( 0 === index );
										row.querySelector( '.v4v-app-move-down' ).disabled = ( index === rows.length - 1 );
									} );
								}

								document.querySelectorAll( '.v4v-app-move-up, .v4v-app-move-down' ).forEach( function ( button ) {
									button.addEventListener( 'click', function () {
										var row = button.closest( '.v4v-app-row' );

										if ( button.classList.contains( 'v4v-app-move-up' ) && row.previousElementSibling ) {
											row.parentNode.insertBefore( row, row.previousElementSibling );
										} else if ( button.classList.contains( 'v4v-app-move-down' ) && row.nextElementSibling ) {
											row.parentNode.insertBefore( row.nextElementSibling, row );
										}

										v4vRenumberApps();
									} );
								} );

								v4vRenumberApps();
							})();
							</script>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_pro_random_app_order"><?php esc_html_e( 'Random order', 'v4v-musiclink-pro' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="v4v_musiclink_pro_random_app_order" value="0" />
							<input
								type="checkbox"
								id="v4v_musiclink_pro_random_app_order"
								name="v4v_musiclink_pro_random_app_order"
								value="1"
								<?php checked( get_option( 'v4v_musiclink_pro_random_app_order', false ) ); ?>
							/>
							<label for="v4v_musiclink_pro_random_app_order">
								<?php esc_html_e( 'Shuffle the music app buttons into a new random order on every page load', 'v4v-musiclink-pro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The "Order" column above is never changed by this: it stays the fixed order shown in the table, and is used whenever this box is unticked. When ticked, every load of a page containing the shortcode - including refreshes, and pages served from a cache - shows the buttons in a fresh random order. Without JavaScript the configured order is shown. The same randomisation applies to every visitor.', 'v4v-musiclink-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_pro_fountain_next_action"><?php esc_html_e( 'Fountain "next-action" header', 'v4v-musiclink-pro' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="v4v_musiclink_pro_fountain_next_action"
								name="v4v_musiclink_pro_fountain_next_action"
								value="<?php echo esc_attr( get_option( 'v4v_musiclink_pro_fountain_next_action', '' ) ); ?>"
								class="regular-text code"
							/>
							<p class="description">
								<?php
								esc_html_e(
									'Optional starting point for direct Fountain.fm links (without any value here, Fountain buttons link to fountain.fm/discover until one is found). This value changes whenever Fountain redeploys - if it goes stale, the plugin automatically tries to detect a fresh one in the background and saves it here when successful, so manual updates usually aren\'t needed. If you want to set it directly anyway: open fountain.fm with browser dev tools\' Network tab open, search for anything, find the POST request to "fountain.fm/search", and copy its "next-action" request header.',
									'v4v-musiclink-pro'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_pro_log_enabled"><?php esc_html_e( 'Log interactions', 'v4v-musiclink-pro' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="v4v_musiclink_pro_log_enabled" value="0" />
							<input
								type="checkbox"
								id="v4v_musiclink_pro_log_enabled"
								name="v4v_musiclink_pro_log_enabled"
								value="1"
								<?php checked( $this->logging_enabled() ); ?>
							/>
							<label for="v4v_musiclink_pro_log_enabled">
								<?php esc_html_e( 'Record search-result opens and app-button launches (View Log and Reports tabs).', 'v4v-musiclink-pro' ); ?>
							</label>
							<p class="description">
								<?php
								esc_html_e(
									'Enabled by default. A row stores only the resolved artist, album, track, app and timestamp - never an IP address or anything sent from the page - and page renders are never logged. Turning this off stops all new rows: the beacons acknowledge without writing anything (no rate-limit budget is spent and nothing is sent to Music Index); existing rows stay until you press Clear log or the row cap prunes them. The rate and row-cap settings below apply only while this is on.',
									'v4v-musiclink-pro'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_pro_log_app_rate"><?php esc_html_e( 'App-launch log rate (per minute, per IP)', 'v4v-musiclink-pro' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								min="1"
								max="600"
								id="v4v_musiclink_pro_log_app_rate"
								name="v4v_musiclink_pro_log_app_rate"
								value="<?php echo esc_attr( get_option( 'v4v_musiclink_pro_log_app_rate', 30 ) ); ?>"
								class="small-text"
							/>
							<p class="description">
								<?php
								esc_html_e(
									'How many app-button launch beacons one visitor IP address may have accepted per minute (default 30, allowed range 1-600). Beacons over the budget receive an error response and write nothing, and the check runs before any Music Index lookup, so a flood can never be relayed upstream; page rendering, search and the buttons themselves are never blocked by this. The budget is per endpoint and per IP (never the spoofable forwarded headers), and requests over the limit do not extend it, so a shared address can never be locked out permanently.',
									'v4v-musiclink-pro'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_pro_log_open_rate"><?php esc_html_e( 'Search-result open log rate (per minute, per IP)', 'v4v-musiclink-pro' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								min="1"
								max="600"
								id="v4v_musiclink_pro_log_open_rate"
								name="v4v_musiclink_pro_log_open_rate"
								value="<?php echo esc_attr( get_option( 'v4v_musiclink_pro_log_open_rate', 30 ) ); ?>"
								class="small-text"
							/>
							<p class="description">
								<?php
								esc_html_e(
									'Same budget, counted separately, for the beacon fired when a visitor picks a search result - the only thing that logs an "open" (the page render that follows logs nothing, and a shared link opened cold is deliberately not counted). Separate from the launch budget, so one kind of traffic can never starve the other. Blank or invalid values fall back to 30 on save.',
									'v4v-musiclink-pro'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_pro_log_max_rows"><?php esc_html_e( 'Maximum log rows kept', 'v4v-musiclink-pro' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								min="1000"
								max="1000000"
								id="v4v_musiclink_pro_log_max_rows"
								name="v4v_musiclink_pro_log_max_rows"
								value="<?php echo esc_attr( get_option( 'v4v_musiclink_pro_log_max_rows', 100000 ) ); ?>"
								class="small-text"
							/>
							<p class="description">
								<?php
								esc_html_e(
									'The log keeps only its newest rows: once the count passes this limit, the oldest are deleted (default 100,000, allowed range 1,000-1,000,000). The size check runs at most once per hour, after a row is written, so the table may briefly sit above the limit between checks; the Clear log button on the View Log tab still empties it immediately. Blank or invalid values fall back to 100,000 on save.',
									'v4v-musiclink-pro'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_pro_mi_refresh_hours"><?php esc_html_e( 'Music Index refresh', 'v4v-musiclink-pro' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								id="v4v_musiclink_pro_mi_refresh_hours"
								name="v4v_musiclink_pro_mi_refresh_hours"
								value="<?php echo esc_attr( get_option( 'v4v_musiclink_pro_mi_refresh_hours', self::MI_REFRESH_DEFAULT_HOURS ) ); ?>"
								min="1"
								max="720"
								step="1"
								class="small-text"
							/>
							<?php esc_html_e( 'hours', 'v4v-musiclink-pro' ); ?>
							<p class="description">
								<?php esc_html_e( 'When a background refresh of cached Music Index metadata (titles, artist, artwork) is triggered. Cached data keeps serving at any age and is never cleared by this value - lower means fresher metadata, higher means fewer Music Index requests. Default: 12 hours. This does not affect failure throttles, stored Fountain.fm links, search results, log beacons, or social previews.', 'v4v-musiclink-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function enqueue_assets() {
		wp_register_style( 'v4v-musiclink-pro', false );
		wp_enqueue_style( 'v4v-musiclink-pro' );
		wp_add_inline_style( 'v4v-musiclink-pro', $this->inline_css() );

		wp_register_script( 'v4v-musiclink-pro', false, array(), '1.20.30', true );
		wp_enqueue_script( 'v4v-musiclink-pro' );
		wp_localize_script( 'v4v-musiclink-pro', 'v4vMusicLinkPro', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'v4v_musiclink_pro_search' ),
			// Separate nonce for the log-write beacons (app launches and
			// search-result opens share it), so a token lifted from the
			// page can't be replayed against the search endpoint.
			'logNonce'  => wp_create_nonce( 'v4v_musiclink_pro_log_app' ),
			'i18n'      => array(
				'searching'   => __( 'Searching…', 'v4v-musiclink-pro' ),
				'noResults'   => __( 'No results found.', 'v4v-musiclink-pro' ),
				'searchError' => __( 'Search failed - please try again.', 'v4v-musiclink-pro' ),
				'copied'      => __( 'Link copied!', 'v4v-musiclink-pro' ),
				'prevPage'    => __( '‹ Previous', 'v4v-musiclink-pro' ),
				'nextPage'    => __( 'Next ›', 'v4v-musiclink-pro' ),
				/* translators: 1: current page number. */
				'pageLabel'   => __( 'Page %1$d', 'v4v-musiclink-pro' ),
				/* translators: 1: first result index on this page, 2: last result index on this page. */
				'rangeLabel'  => __( 'Results %1$d–%2$d', 'v4v-musiclink-pro' ),
			),
		) );
		wp_add_inline_script( 'v4v-musiclink-pro', $this->inline_js() );
	}

	private function inline_css() {
		return '
			.v4v-musiclink-pro { max-width: 480px; margin: 1.5em 0; }
			.v4v-musiclink-pro-header { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 1.2em; margin-bottom: 0.75em; }
			.v4v-musiclink-pro-info { flex: 1 1 220px; min-width: 0; }
			.v4v-musiclink-pro-title { font-weight: 700; font-size: 2em; line-height: 1.2; margin: 0 0 0.2em; }
			.v4v-musiclink-pro-artist { font-weight: 600; font-size: 1.1em; margin: 0; }
			.v4v-musiclink-pro-art {
				width: 200px; height: 200px; object-fit: cover; border-radius: 8px;
				flex: 0 0 200px; order: 2;
			}
			.v4v-musiclink-pro-share { margin: 0 0 1.1em; }
			.v4v-musiclink-pro-share-btn {
				display: inline-flex; align-items: center; gap: 0.4em;
				padding: 0.5em 0.9em; border: 1px solid #ddd; border-radius: 6px;
				background: #fafafa; cursor: pointer; font-size: 0.95em;
			}
			.v4v-musiclink-pro-share-btn:hover { background-color: #f0f0f0; }
			.v4v-musiclink-pro-heading { font-weight: 600; margin-bottom: 0.75em; }
			.v4v-musiclink-pro-list { list-style: none; margin: 0; padding: 0; }
			.v4v-musiclink-pro-item { margin-bottom: 0.5em; }
			.v4v-musiclink-pro-item a {
				display: flex;
				align-items: center;
				gap: 0.6em;
				padding: 0.75em 1em;
				border: 2px solid #ddd;
				border-radius: 6px;
				text-decoration: none;
				transition: background-color 0.15s ease;
			}
			.v4v-musiclink-pro-item a:hover { background-color: #f5f5f5; }
			.v4v-musiclink-pro-icon { width: 40px; height: 40px; flex: 0 0 40px; border-radius: 6px; }
			.v4v-musiclink-pro-error { color: #a00; }

			.v4v-musiclink-pro-modal-overlay {
				display: none;
				position: fixed;
				inset: 0;
				background: rgba(0, 0, 0, 0.55);
				align-items: center;
				justify-content: center;
				z-index: 100000;
				padding: 1em;
			}
			.v4v-musiclink-pro-modal-overlay.is-open { display: flex; }
			.v4v-musiclink-pro-modal {
				background: var(--wp--preset--color--background, #fff);
				color: var(--wp--preset--color--foreground, #1e1e1e);
				border-radius: 8px;
				padding: 1.5em;
				max-width: 380px;
				width: 100%;
				box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
				font-family: inherit;
			}
			.v4v-musiclink-pro-modal-message { margin: 0 0 0.75em; line-height: 1.5; }
			.v4v-musiclink-pro-modal-message:last-of-type { margin-bottom: 1.25em; }
			.v4v-musiclink-pro-modal-actions { display: flex; justify-content: flex-end; gap: 0.6em; }
			.v4v-musiclink-pro-modal-btn {
				padding: 0.55em 1.2em;
				border-radius: 6px;
				border: 1px solid #ccc;
				cursor: pointer;
				font-size: 0.95em;
				font-family: inherit;
			}
			.v4v-musiclink-pro-modal-cancel { background: #f5f5f5; color: #1e1e1e; }
			.v4v-musiclink-pro-modal-cancel:hover { background: #ebebeb; }
			.v4v-musiclink-pro-modal-ok {
				background: var(--wp--preset--color--primary, #2271b1);
				border-color: var(--wp--preset--color--primary, #2271b1);
				color: #fff;
			}
			.v4v-musiclink-pro-modal-ok:hover { filter: brightness(0.92); }
			.v4v-musiclink-pro-footer { margin-top: 1.25em; font-size: 0.85em; color: #444; }
			.v4v-musiclink-pro-footer a { color: inherit; text-decoration: underline; }

			.v4v-musiclink-pro-search-title { font-weight: 700; font-size: 2em; line-height: 1.2; margin: 0 0 0.2em; }
			.v4v-musiclink-pro-search-subtitle { font-weight: 600; font-size: 1.1em; margin: 0 0 1.1em; color: #666; }
			.v4v-musiclink-pro-search-row { display: flex; gap: 0.5em; margin-bottom: 1em; }
			.v4v-musiclink-pro-search-input {
				flex: 1 1 auto; padding: 0.65em 0.9em; border: 1px solid #ccc; border-radius: 6px; font-size: 1em;
			}
			.v4v-musiclink-pro-search-btn {
				flex: 0 0 auto; padding: 0.65em 1.2em; border: 1px solid #333; border-radius: 6px;
				background: #333; color: #fff; cursor: pointer; font-size: 1em;
			}
			.v4v-musiclink-pro-search-btn:hover { background: #111; }
			.v4v-musiclink-pro-search-status { color: #666; margin-bottom: 0.75em; }
			.v4v-musiclink-pro-results { list-style: none; margin: 0; padding: 0; }
			.v4v-musiclink-pro-result { margin-bottom: 0.5em; }
			.v4v-musiclink-pro-result button {
				display: block; width: 100%; text-align: left;
				padding: 0.7em 1em; border: 1px solid #ddd; border-radius: 6px;
				background: #fff; cursor: pointer; font: inherit;
			}
			.v4v-musiclink-pro-result button:hover { background-color: #f5f5f5; }
			.v4v-musiclink-pro-result-title { font-weight: 600; }
			.v4v-musiclink-pro-result-subtitle { color: #666; font-size: 0.9em; }

			.v4v-musiclink-pro-pager {
				display: flex; align-items: center; gap: 0.6em;
				margin-top: 0.9em;
			}
			.v4v-musiclink-pro-pager[hidden] { display: none; }
			.v4v-musiclink-pro-pager-btn {
				padding: 0.45em 0.9em; border: 1px solid #ddd; border-radius: 6px;
				background: #fff; cursor: pointer; font: inherit; font-size: 0.95em;
			}
			.v4v-musiclink-pro-pager-btn:hover:not(:disabled) { background-color: #f5f5f5; }
			.v4v-musiclink-pro-pager-btn:disabled { opacity: 0.45; cursor: default; }
			.v4v-musiclink-pro-pager-label { color: #666; font-size: 0.9em; }
		';
	}

	private function inline_js() {
		return <<<'JS'
		document.addEventListener("DOMContentLoaded", function () {
			var root = document.querySelector(".v4v-musiclink-pro-search");
			if (root) {
				var input   = root.querySelector(".v4v-musiclink-pro-search-input");
				var button  = root.querySelector(".v4v-musiclink-pro-search-btn");
				var status  = root.querySelector(".v4v-musiclink-pro-search-status");
				var results = root.querySelector(".v4v-musiclink-pro-results");
				var pager      = root.querySelector(".v4v-musiclink-pro-pager");
				var pagerPrev  = root.querySelector(".v4v-musiclink-pro-pager-prev");
				var pagerNext  = root.querySelector(".v4v-musiclink-pro-pager-next");
				var pagerLabel = root.querySelector(".v4v-musiclink-pro-pager-label");

				// The server picks up where it left off, so this is just the
				// page we last asked for - not a result count (the total is
				// unknown while Music Index still has more batches behind its
				// cursor).
				var currentPage = 1;
				var currentHasMore = false;
				var currentPerPage = 8;

				pagerPrev.textContent = v4vMusicLinkPro.i18n.prevPage;
				pagerNext.textContent = v4vMusicLinkPro.i18n.nextPage;

				// "%1$d"-style placeholders, so translators can reorder them.
				function fmt(template) {
					var values = Array.prototype.slice.call(arguments, 1);
					var i = 0;
					return template.replace(/%(\d+\$)?d/g, function (m, ord) {
						var idx = ord ? parseInt(ord, 10) - 1 : i;
						if (!ord) { i++; }
						return String(values[idx]);
					});
				}

				function renderPager(count) {
					var show = currentPage > 1 || currentHasMore;
					pager.hidden = !show;
					if (!show) { return; }

					pagerPrev.disabled = currentPage <= 1;
					pagerNext.disabled = !currentHasMore;

					var start = (currentPage - 1) * currentPerPage + 1;
					pagerLabel.textContent = fmt(v4vMusicLinkPro.i18n.pageLabel, currentPage);
					if (count > 0) {
						// An empty page past the end has no meaningful range.
						pagerLabel.textContent += " · "
							+ fmt(v4vMusicLinkPro.i18n.rangeLabel, start, start + count - 1);
					}
				}

				function runSearch(page) {
					var q = input.value.trim();
					if (!q) { return; }

					currentPage = page;
					results.innerHTML = "";
					pager.hidden = true;
					status.textContent = v4vMusicLinkPro.i18n.searching;

					var body = new URLSearchParams();
					body.append("action", "v4v_musiclink_pro_search");
					body.append("nonce", v4vMusicLinkPro.nonce);
					body.append("q", q);
					body.append("page", String(page));

					fetch(v4vMusicLinkPro.ajaxUrl, { method: "POST", body: body })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							if (!res.success || !res.data || !res.data.results) {
								status.textContent = v4vMusicLinkPro.i18n.searchError;
								return;
							}
							var data = res.data;
							var items = data.results;

							currentPage   = data.page;
							currentHasMore = !!data.has_more;
							currentPerPage = data.per_page || 8;

							if (!items.length) {
								status.textContent = v4vMusicLinkPro.i18n.noResults;
								renderPager(0);
								return;
							}
							status.textContent = "";
							items.forEach(function (item) {
								var li = document.createElement("li");
								li.className = "v4v-musiclink-pro-result";

								var btn = document.createElement("button");
								btn.type = "button";

								var titleEl = document.createElement("div");
								titleEl.className = "v4v-musiclink-pro-result-title";
								titleEl.textContent = item.title;

								var subtitleEl = document.createElement("div");
								subtitleEl.className = "v4v-musiclink-pro-result-subtitle";
								subtitleEl.textContent = item.subtitle;

								btn.appendChild(titleEl);
								btn.appendChild(subtitleEl);
								btn.addEventListener("click", function () {
									// Record the open: this click is what generates
									// the page URL, so it - not the render that
									// follows - is the event worth logging. Fire
									// and forget; sendBeacon survives the
									// navigation below, and titles are resolved
									// server-side from the guids alone.
									var logBody = new URLSearchParams();
									logBody.append("action", "v4v_musiclink_pro_log_open");
									logBody.append("nonce", v4vMusicLinkPro.logNonce);
									logBody.append("album", item.album_guid);
									logBody.append("track", item.track_guid || "");
									if (navigator.sendBeacon) {
										navigator.sendBeacon(v4vMusicLinkPro.ajaxUrl, logBody);
									} else {
										fetch(v4vMusicLinkPro.ajaxUrl, { method: "POST", body: logBody, keepalive: true }).catch(function () {});
									}

									var url = new URL(window.location.pathname, window.location.origin);
									url.searchParams.set("album", item.album_guid);
									if (item.track_guid) {
										url.searchParams.set("track", item.track_guid);
									}
									window.location.href = url.toString();
								});

								li.appendChild(btn);
								results.appendChild(li);
							});
							renderPager(items.length);
						})
						.catch(function () {
							status.textContent = v4vMusicLinkPro.i18n.searchError;
						});
				}

				pagerPrev.addEventListener("click", function () {
					if (currentPage > 1) { runSearch(currentPage - 1); }
				});
				pagerNext.addEventListener("click", function () {
					if (currentHasMore) { runSearch(currentPage + 1); }
				});

				button.addEventListener("click", function () { runSearch(1); });
				input.addEventListener("keydown", function (e) {
					if (e.key === "Enter") { runSearch(1); }
				});
			}

			// Fire-and-forget beacon for a button that actually launches.
			// sendBeacon survives the target=_blank navigation that follows
			// the click, and the guids are read off the container so this
			// works the same for URL-driven and shortcode-embedded
			// releases. Only the guids, the nonce and the slug cross the
			// wire - the server resolves and stores the titles itself.
			function logLaunch(slug) {
				var container = document.querySelector(".v4v-musiclink-pro");
				var album = container ? (container.getAttribute("data-album") || "") : "";
				if (!slug || !album) { return; }

				var body = new URLSearchParams();
				body.append("action", "v4v_musiclink_pro_log_app");
				body.append("nonce", v4vMusicLinkPro.logNonce);
				body.append("album", album);
				body.append("track", container.getAttribute("data-track") || "");
				body.append("app", slug);

				if (navigator.sendBeacon) {
					navigator.sendBeacon(v4vMusicLinkPro.ajaxUrl, body);
				} else {
					fetch(v4vMusicLinkPro.ajaxUrl, { method: "POST", body: body, keepalive: true }).catch(function () {});
				}
			}

			// Every button except Fountain: that one goes through its own
			// modal below, and a cancelled modal never launches anything.
			document.querySelectorAll(".v4v-musiclink-pro-item[data-app]").forEach(function (item) {
				var link = item.querySelector("a");
				var slug = item.getAttribute("data-app");
				if (!link || !slug || "fountain" === slug) { return; }
				link.addEventListener("click", function () { logLaunch(slug); });
			});

			document.querySelectorAll(".v4v-musiclink-pro-share-btn").forEach(function (btn) {
				btn.addEventListener("click", function () {
					var url = btn.getAttribute("data-url");
					var label = btn.querySelector(".v4v-musiclink-pro-share-label");
					var restore = label.textContent;

					if (navigator.share) {
						navigator.share({ url: url }).catch(function () {});
						return;
					}
					if (navigator.clipboard) {
						navigator.clipboard.writeText(url).then(function () {
							label.textContent = v4vMusicLinkPro.i18n.copied;
							setTimeout(function () { label.textContent = restore; }, 2000);
						});
					}
				});
			});

			var fountainLink = document.querySelector(".v4v-musiclink-pro-fountain a");
			var modal = document.getElementById("v4v-musiclink-pro-fountain-modal");
			if (fountainLink && modal) {
				var okBtn     = modal.querySelector(".v4v-musiclink-pro-modal-ok");
				var cancelBtn = modal.querySelector(".v4v-musiclink-pro-modal-cancel");
				var pendingHref = null;

				function closeModal() {
					modal.classList.remove("is-open");
					pendingHref = null;
				}

				fountainLink.addEventListener("click", function (e) {
					var href = fountainLink.getAttribute("href");
					// Unresolved fallback (fountain.fm/discover); the bare
					// homepage stays matched for a link set by hand.
					var isFallback = href === "https://fountain.fm/discover"
						|| href === "https://fountain.fm" || href === "https://fountain.fm/";
					if (isFallback) {
						e.preventDefault();
						pendingHref = href;
						modal.classList.add("is-open");
						// Nothing has launched yet - cancelling (button,
						// Escape or backdrop) closes without logging.
						return;
					}
					// A resolved Fountain URL navigates away on this click.
					logLaunch("fountain");
				});

				cancelBtn.addEventListener("click", closeModal);

				okBtn.addEventListener("click", function () {
					var href = pendingHref;
					closeModal();
					if (href) {
						logLaunch("fountain");
						window.open(href, "_blank", "noopener,noreferrer");
					}
				});

				modal.addEventListener("click", function (e) {
					if (e.target === modal) { closeModal(); }
				});

				document.addEventListener("keydown", function (e) {
					if (e.key === "Escape" && modal.classList.contains("is-open")) {
						closeModal();
					}
				});
			}
		});
JS;
	}

	/**
	 * Computes the title/description/image/type/site_name for the social
	 * preview - shared by both the "plugin only" mode (adds new tags via
	 * wp_head) and "entire site" mode (rewrites whatever tags already
	 * exist), so the two modes never show different content, only differ
	 * in how they get the tags into the page.
	 */
	private function compute_preview_fields( $post ) {
		$album = $this->get_guid_arg( 'album' );
		$track = $this->get_guid_arg( 'track' );

		if ( $album ) {
			$has_track = ! empty( $track );
			$release   = $this->resolve_release_meta( $album, $track, $has_track );

			if ( $release['album_title'] || $release['track_title'] ) {
				return array(
					'title'       => ( $has_track && $release['track_title'] )
						? sprintf( '"%s" from %s', $release['track_title'], $release['album_title'] )
						: $release['album_title'],
					'description' => $release['artist']
						? sprintf( __( 'by %s', 'v4v-musiclink-pro' ), $release['artist'] )
						: get_bloginfo( 'name' ),
					'image'       => $release['art_url'],
					'type'        => $has_track ? 'music.song' : 'music.album',
					'site_name'   => get_the_title( $post ),
				);
			}
			// Guid present but nothing resolved - fall through to the
			// generic fallback rather than showing blank title/image.
		}

		// Fallback: no guid, or nothing resolved for it.
		return array(
			'title'       => get_the_title( $post ),
			'description' => __( 'Powered by MusicIndex.org', 'v4v-musiclink-pro' ),
			'image'       => $this->site_logo_or_favicon_url(),
			'type'        => 'website',
			'site_name'   => '',
		);
	}

	/**
	 * "On (this plugin only)" mode: adds this plugin's own Open Graph /
	 * Twitter Card tags via wp_head. Only runs on a page that actually
	 * contains the [musiclink_pro] shortcode. If an SEO plugin is also adding
	 * its own tags site-wide, this page specifically may end up with two
	 * competing sets - see the settings page for the "Entire Site" mode,
	 * which avoids that by rewriting the existing tags instead.
	 */
	public function output_social_preview_meta() {
		global $post;
		if ( ! $post instanceof WP_Post || ! has_shortcode( $post->post_content, 'musiclink_pro' ) ) {
			return;
		}

		$fields      = $this->compute_preview_fields( $post );
		$current_url = $this->musiclink_canonical_url();

		$this->render_meta_tags( $fields['title'], $fields['description'], $fields['image'], $fields['type'], $current_url, $fields['site_name'] );
	}

	private function render_meta_tags( $title, $description, $image, $type, $url, $site_name ) {
		?>
		<meta property="og:title" content="<?php echo esc_attr( $title ); ?>" />
		<meta property="og:description" content="<?php echo esc_attr( $description ); ?>" />
		<meta property="og:type" content="<?php echo esc_attr( $type ); ?>" />
		<meta property="og:url" content="<?php echo esc_url( $url ); ?>" />
		<?php if ( $site_name ) : ?>
			<meta property="og:site_name" content="<?php echo esc_attr( $site_name ); ?>" />
		<?php endif; ?>
		<?php if ( $image ) : ?>
			<meta property="og:image" content="<?php echo esc_url( $image ); ?>" />
			<meta name="twitter:card" content="summary_large_image" />
			<meta name="twitter:image" content="<?php echo esc_url( $image ); ?>" />
		<?php else : ?>
			<meta name="twitter:card" content="summary" />
		<?php endif; ?>
		<meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>" />
		<meta name="twitter:description" content="<?php echo esc_attr( $description ); ?>" />
		<?php
	}

	/**
	 * Best-effort site logo/favicon URL for the fallback social preview -
	 * tries the theme's custom logo first, then the site icon (favicon),
	 * returns empty string if neither is set (image tags are then omitted
	 * rather than showing broken/missing image previews).
	 */
	private function site_logo_or_favicon_url() {
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo_url ) {
				return $logo_url;
			}
		}
		return get_site_icon_url( 512 );
	}

	/**
	 * Live favicon URL for a given domain - avoids storing/reproducing any
	 * app's logo assets ourselves, and keeps the visitor's browser from
	 * contacting any third party (this used to proxy through Google's
	 * favicon service). The icon is read straight off the app's own
	 * domain: every app serves the conventional /favicon.ico except
	 * PodTunes, which serves favicon.png (verified 2026-09-25 - its
	 * /favicon.ico 404s).
	 */
	private function favicon_url( $domain ) {
		$path = ( 'podtunes.app' === $domain ) ? '/favicon.png' : '/favicon.ico';
		return 'https://' . $domain . $path;
	}

	/**
	 * Resolves title/artist/artwork for an album (and optionally a track)
	 * via Music Index, once, in one place - the single source for the
	 * on-page display, social-preview meta tags, and log beacons.
	 */
	private function resolve_release_meta( $album, $track, $has_track ) {
		$track_data = $has_track ? $this->mi_get_track( $album, $track ) : null;
		$feed_data  = ( ! $has_track || empty( $track_data ) ) ? $this->mi_get_feed( $album ) : null;

		return array(
			'album_title' => $has_track && $track_data ? $track_data['album_title'] : ( $feed_data ? $feed_data['title'] : '' ),
			'track_title' => $has_track && $track_data ? $track_data['title'] : '',
			'artist'      => $has_track && $track_data ? $track_data['artist'] : ( $feed_data ? $feed_data['artist'] : '' ),
			'art_url'     => $has_track && $track_data ? $track_data['image'] : ( $feed_data ? $feed_data['image'] : '' ),
			'track_data'  => $track_data,
			'feed_data'   => $feed_data,
		);
	}

	public function render( $atts ) {
		// Page renders are the only context allowed to queue background
		// Music Index refreshes (see the $mi_refresh_allowed gate). The
		// search box, the log beacons and the og: preview all call
		// resolve_release_meta() without this flag and can never queue one.
		$this->mi_refresh_allowed = true;

		$atts = shortcode_atts( array(
			'album'  => '',
			'track'  => '',
			'header' => '',
		), $atts, 'musiclink_pro' );

		// Explicit shortcode attributes (for hardcoding a specific release
		// inline in post content) take priority over the URL's query string
		// (used by the shareable /musiclink page).
		$album = $atts['album']
			? $this->guard_guid( sanitize_text_field( $atts['album'] ) )
			: $this->get_guid_arg( 'album' );
		$track = $atts['track']
			? $this->guard_guid( sanitize_text_field( $atts['track'] ) )
			: $this->get_guid_arg( 'track' );

		// Optional per-placement override of the admin "Release header"
		// setting; empty (not supplied) falls back to that setting.
		$show_header = $this->resolve_show_header( $atts['header'] );

		if ( empty( $album ) ) {
			return $this->render_search_ui();
		}

		$has_track = ! empty( $track );

		$release = $this->resolve_release_meta( $album, $track, $has_track );
		$album_title = $release['album_title'];
		$track_title = $release['track_title'];
		$artist      = $release['artist'];
		$art_url     = $release['art_url'];
		$track_data  = $release['track_data'];
		$feed_data   = $release['feed_data'];

		if ( ! $album_title && ! $track_title ) {
			return $this->render_search_ui( __( 'Album/Song not found', 'v4v-musiclink-pro' ) );
		}

		$links = array();

		// --- Stablekraft: fully formulaic, no lookup needed ---
		if ( $this->is_app_enabled( 'stablekraft' ) ) {
			$stablekraft_url = 'https://stablekraft.app/album/' . rawurlencode( $album );
			if ( $has_track ) {
				$stablekraft_url .= '?track=' . rawurlencode( $track );
			}
			$links['stablekraft'] = array(
				'url'      => $stablekraft_url,
				'label'    => 'StableKraft.app',
				'icon_url' => $this->get_app_icon( 'stablekraft', 'stablekraft.app' ),
			);
		}

		// --- PodTunes.app: fully formulaic, no lookup needed ---
		if ( $this->is_app_enabled( 'podtunes' ) ) {
			$podtunes_url = 'https://podtunes.app/g/' . rawurlencode( $album );
			if ( $has_track ) {
				$podtunes_url .= '/' . rawurlencode( $track );
			}
			$links['podtunes'] = array(
				'url'      => $podtunes_url,
				'label'    => 'PodTunes.app',
				'icon_url' => $this->get_app_icon( 'podtunes', 'podtunes.app' ),
			);
		}

		// --- v4vmusic.com: fully formulaic guid lookup, no lookup needed ---
		if ( $this->is_app_enabled( 'v4vmusic' ) ) {
			$v4vmusic_url = 'https://v4vmusic.com/lookup/' . rawurlencode( $album );
			if ( $has_track ) {
				$v4vmusic_url .= '/' . rawurlencode( $track );
			}
			$links['v4vmusic'] = array(
				'url'      => $v4vmusic_url,
				'label'    => 'v4vmusic.com',
				'icon_url' => $this->get_app_icon( 'v4vmusic', 'v4vmusic.com' ),
			);
		}

		// --- Fountain: no reliable public link exists via guid or their
		// search page directly (both confirmed to 404 - see project notes).
		// Their ONLY resolution mechanism is an unofficial, undocumented
		// Next.js Server Action - fragile because its "next-action" build
		// fingerprint changes whenever Fountain redeploys. We call it here,
		// but cache successes for a long time and always fall back to
		// fountain.fm/discover on any failure, so a stale/broken hash
		// never breaks the button - it just stops being a direct link
		// until the hash is refreshed (see FOUNTAIN_NEXT_ACTION below).
		if ( $this->is_app_enabled( 'fountain' ) ) {
			$fountain_url = 'https://fountain.fm/discover';

			// Cached link first: an exact album/track match with a link on
			// file is used exactly as it stands - no search, no transient,
			// no self-heal and no write-back - so a link that already
			// works keeps working through a Fountain redeploy, and one
			// corrected by hand is never reverted. '' means either "no
			// entry yet" or "entry whose link was blanked to force a
			// re-search"; both fall through to the search below.
			$cached_url = $this->get_cached_fountain_url( $album, $track );

			if ( '' !== $cached_url ) {
				$fountain_url = $cached_url;
			} else {
				// Keep the search text and the guid we're matching against in
				// sync: if track-level data didn't resolve, fall back to the
				// ALBUM for both, rather than searching by album title while
				// still trying to match against the track guid (which would
				// never succeed).
				$fountain_has_track   = $has_track && $track_data;
				$fountain_search_text = $fountain_has_track ? $track_data['title'] : ( $feed_data ? $feed_data['title'] : '' );
				$fountain_target_guid = $fountain_has_track ? $track : $album;
				$fountain_hit         = $fountain_search_text ? $this->fountain_resolve( $fountain_target_guid, $fountain_search_text ) : null;

				if ( $fountain_hit ) {
					// The path prefix comes from Fountain's own _type on the
					// matched hit - see fountain_path_segment().
					$fountain_url = 'https://fountain.fm/'
						. $this->fountain_path_segment( $fountain_hit, $fountain_has_track )
						. '/'
						. rawurlencode( $fountain_hit['id'] );

					// Only a real deep link is ever stored: the
					// fountain.fm/discover fallback above must never be
					// persisted, or it would pin a generic Fountain link
					// to this release forever.
					$this->store_fountain_link( $album, $track, $fountain_url, $album_title, $track_title, $artist );
				}
			}

			$links['fountain'] = array(
				'url'      => $fountain_url,
				'label'    => 'Fountain.fm',
				'icon_url' => $this->get_app_icon( 'fountain', 'fountain.fm' ),
			);
		}

		// Buttons appear in the admin-configured order, not the order
		// the links happen to be assembled in above.
		$links = $this->sort_links_by_app_order( $links );

		return $this->render_html( $links, $has_track, $album_title, $track_title, $artist, $art_url, $show_header, $album, $track );
	}

	/**
	 * Whether the release header (title/artist/artwork) should render.
	 * The optional shortcode header="true|false" attribute overrides the
	 * admin "Release header" setting; when the attribute is absent or has
	 * an unrecognized value, the admin setting decides (default: shown).
	 */
	private function resolve_show_header( $raw ) {
		$default = (bool) get_option( 'v4v_musiclink_pro_show_header', true );

		if ( ! is_string( $raw ) ) {
			return $default;
		}
		$value = strtolower( sanitize_text_field( $raw ) );
		if ( in_array( $value, array( 'true', '1', 'yes', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'false', '0', 'no', 'off' ), true ) ) {
			return false;
		}
		return $default;
	}

	/**
	 * Search-box UI shown when no album/track guid is present in the URL.
	 */
	private function render_search_ui( $not_found_message = '' ) {
		ob_start();
		?>
		<div class="v4v-musiclink-pro v4v-musiclink-pro-search">
			<h1 class="v4v-musiclink-pro-search-title"><?php esc_html_e( 'V4V MusicLink', 'v4v-musiclink-pro' ); ?></h1>
			<p class="v4v-musiclink-pro-search-subtitle"><?php esc_html_e( 'Powered by MusicIndex.org', 'v4v-musiclink-pro' ); ?></p>
			<div class="v4v-musiclink-pro-search-row">
				<input
					type="text"
					class="v4v-musiclink-pro-search-input"
					placeholder="<?php esc_attr_e( 'Search for album or track…', 'v4v-musiclink-pro' ); ?>"
				/>
				<button type="button" class="v4v-musiclink-pro-search-btn"><?php esc_html_e( 'Search', 'v4v-musiclink-pro' ); ?></button>
			</div>
			<?php if ( $not_found_message ) : ?>
				<p class="v4v-musiclink-pro-error"><?php echo esc_html( $not_found_message ); ?></p>
			<?php endif; ?>
			<p class="v4v-musiclink-pro-search-status"></p>
			<ul class="v4v-musiclink-pro-results"></ul>
			<nav class="v4v-musiclink-pro-pager" hidden>
				<button type="button" class="v4v-musiclink-pro-pager-btn v4v-musiclink-pro-pager-prev"></button>
				<span class="v4v-musiclink-pro-pager-label"></span>
				<button type="button" class="v4v-musiclink-pro-pager-btn v4v-musiclink-pro-pager-next"></button>
			</nav>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Stable key for one search hit, used to drop the duplicates Music
	 * Index's /search sometimes repeats within and across batches. Must be
	 * based only on the hit's own identity - never on its position - or
	 * page boundaries would shift between requests.
	 */
	private function search_hit_key( $hit ) {
		if ( empty( $hit['entity_type'] ) || empty( $hit['entity_id'] ) ) {
			return '';
		}

		// Track-type hits carry a separate feed_guid (their parent album)
		// and need it to fetch details. Feed/album-type hits have no
		// feed_guid at all - entity_id IS the album's own guid.
		$album_guid = ( 'track' === $hit['entity_type'] ) ? ( $hit['feed_guid'] ?? '' ) : $hit['entity_id'];
		if ( 'track' === $hit['entity_type'] && '' === $album_guid ) {
			return ''; // Can't resolve a track without knowing its album.
		}

		return $hit['entity_type'] . '|' . $hit['entity_id'] . '|' . $album_guid;
	}

	/**
	 * Returns the accumulated, de-duplicated hits for a query in Music
	 * Index's relevance order, fetching more cursor batches only when fewer
	 * than $needed hits are already known.
	 *
	 * The accumulated list is stored in a per-query transient, so it only
	 * ever grows by appending: a hit keeps the same index for the lifetime of
	 * the cache, which is what makes page boundaries stable and lets the
	 * browser walk backwards for free. Relevance order is exactly the order
	 * Music Index returned - nothing is re-sorted here.
	 *
	 * @return array{hits: array, has_more: bool} De-duplicated hits, and
	 *         whether a further batch is known to exist behind the cursor.
	 */
	private function mi_search_hits( $query, $needed ) {
		$cache_key = 'mi_search_hits_v' . self::SEARCH_CACHE_SCHEMA . '_' . md5( $query );
		$state     = get_transient( $cache_key );
		if (
			! is_array( $state )
			|| ! isset( $state['hits'], $state['has_more'], $state['cursor'] )
			|| ! is_array( $state['hits'] )
		) {
			$state = array(
				'hits'     => array(),
				'seen'     => array(),
				'cursor'   => '',
				'has_more' => true, // Assume more until a batch says otherwise.
			);
		}

		$batches = 0;
		while (
			count( $state['hits'] ) < $needed
			&& $state['has_more']
			&& $batches < self::SEARCH_MAX_BATCHES
		) {
			$path = '/search?q=' . rawurlencode( $query ) . '&limit=' . self::SEARCH_BATCH_LIMIT;
			if ( '' !== $state['cursor'] ) {
				$path .= '&cursor=' . rawurlencode( $state['cursor'] );
			}

			$body   = $this->mi_api_get( $path );
			$batches++;

			if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
				// An empty batch ends the chain even when the response still
				// claims has_more - a no-match query returns zero rows with
				// has_more=true, and trusting it would loop forever.
				$state['has_more'] = false;
				break;
			}

			foreach ( $body['data'] as $hit ) {
				$key = $this->search_hit_key( $hit );
				if ( '' === $key || isset( $state['seen'][ $key ] ) ) {
					continue;
				}
				$state['seen'][ $key ] = true;
				$state['hits'][]       = $hit;
			}

			$cursor  = (string) ( $body['pagination']['cursor'] ?? '' );
			$has_more = ! empty( $body['pagination']['has_more'] ) && '' !== $cursor;
			if ( '' === $cursor || $cursor === $state['cursor'] ) {
				$has_more = false; // No progress possible, stop regardless.
			}
			$state['cursor']   = $cursor;
			$state['has_more'] = $has_more;
		}

		set_transient( $cache_key, $state, self::SEARCH_CACHE_TTL );

		return array(
			'hits'     => $state['hits'],
			'has_more' => $state['has_more'],
		);
	}

	/**
	 * Per-client rate limit for the public (nopriv) AJAX endpoints.
	 *
	 * Their nonces are printed on every page containing the shortcode, so they
	 * only prove a request came from a real page - not who is asking. Without
	 * a budget, a single client could loop the search relay (up to
	 * SEARCH_MAX_BATCHES upstream batches plus one detail lookup per result
	 * for every call) and turn this site into a request amplifier.
	 *
	 * Semantics - sliding window, counted requests only:
	 *  - Each allowed request resets the counter's TTL to $window, so a quiet
	 *    client gets its full budget back $window seconds after its last
	 *    allowed request.
	 *  - Requests over the cap are rejected WITHOUT advancing the counter, so
	 *    hammering never extends the block: the sustained ceiling is exactly
	 *    $max allowed requests per $window, and a shared/NAT address can never
	 *    be locked out permanently.
	 *
	 * Keyed on REMOTE_ADDR only - never X-Forwarded-For, which any client can
	 * spoof to mint itself a fresh bucket.
	 *
	 * @param string $bucket Namespace so separate endpoints don't share a budget.
	 * @param int    $max    Allowed requests per window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when over budget - the caller should reject.
	 */
	private function is_rate_limited( $bucket, $max, $window ) {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key  = 'v4v_rl_' . md5( $bucket . '|' . $ip );
		$hits = (int) get_transient( $key );

		if ( $hits >= $max ) {
			return true;
		}

		set_transient( $key, $hits + 1, $window );
		return false;
	}

	/**
	 * AJAX handler backing the search box: queries Music Index's /search
	 * endpoint, then resolves each hit's title/artist (search results only
	 * contain guids, not display text) before returning results to the
	 * browser.
	 *
	 * Results are paginated server-side in Music Index's relevance order.
	 * Because the endpoint only offers opaque cursors (never an offset or a
	 * total), a grand total cannot be reported - the response says whether
	 * another page exists instead.
	 */
	public function ajax_search() {
		check_ajax_referer( 'v4v_musiclink_pro_search', 'nonce' );

		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$page  = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$page  = max( 1, $page );
		$per_page = self::SEARCH_PER_PAGE;

		$payload = array(
			'results'  => array(),
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => false,
		);

		if ( '' === $query ) {
			wp_send_json_success( $payload );
		}

		// Bound the upstream relay per client before it can spend any
		// outbound requests - see is_rate_limited() for the window semantics.
		if ( $this->is_rate_limited( 'search', 30, MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( null, 429 );
		}

		$offset = ( $page - 1 ) * $per_page;
		$search = $this->mi_search_hits( $query, $offset + $per_page );
		$hits   = $search['hits'];

		// De-duplicate again over just this page as a cheap guard: a stale
		// cache written by an older schema could otherwise leak repeats.
		$seen = array();

		foreach ( array_slice( $hits, $offset, $per_page ) as $hit ) {
			$key = $this->search_hit_key( $hit );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$album_guid_for_hit = ( 'track' === $hit['entity_type'] ) ? $hit['feed_guid'] : $hit['entity_id'];

			if ( 'track' === $hit['entity_type'] ) {
				$track_data = $this->mi_get_track( $album_guid_for_hit, $hit['entity_id'] );
				if ( ! $track_data ) {
					continue;
				}
				$payload['results'][] = array(
					'album_guid' => $album_guid_for_hit,
					'track_guid' => $hit['entity_id'],
					'title'      => $track_data['title'],
					'subtitle'   => trim( sprintf(
						/* translators: 1: album title, 2: artist name */
						__( 'from %1$s · %2$s', 'v4v-musiclink-pro' ),
						$track_data['album_title'],
						$track_data['artist']
					), " ·" ),
				);
			} else {
				// Anything not explicitly a track (e.g. "feed"/"album") is
				// treated as an album-level hit.
				$feed_data = $this->mi_get_feed( $album_guid_for_hit );
				if ( ! $feed_data ) {
					continue;
				}
				$payload['results'][] = array(
					'album_guid' => $album_guid_for_hit,
					'track_guid' => null,
					'title'      => $feed_data['title'],
					'subtitle'   => $feed_data['artist']
						? sprintf( __( 'Album by %s', 'v4v-musiclink-pro' ), $feed_data['artist'] )
						: __( 'Album', 'v4v-musiclink-pro' ),
				);
			}
		}

		// Another page exists if we already hold hits beyond this one, or if
		// the cursor says another batch is fetchable. An empty page is always
		// the last one, so a request past the end can't offer "Next".
		$payload['has_more'] = ( ! $payload['results'] )
			? false
			: ( isset( $hits[ $offset + $per_page ] ) || $search['has_more'] );

		wp_send_json_success( $payload );
	}

	/**
	 * Resolves a guid to Fountain's opaque internal ID plus the content
	 * type Fountain filed it under, via their unofficial Server Action
	 * search endpoint. Returns array( 'id' => ..., 'type' => ... ) or
	 * null on any failure - missing config, network error, unexpected
	 * response shape, or no matching hit - so the caller can fall back to
	 * a plain link.
	 *
	 * Successes are cached for a long time (30 days) since a release's
	 * Fountain ID never changes once assigned. Failures are cached briefly
	 * (1 hour) so a temporarily stale next-action hash doesn't cause a
	 * repeated slow request on every page view, but recovers automatically
	 * once the hash is fixed.
	 */

	private function fountain_resolve( $guid, $search_text ) {
		$next_action = get_option( 'v4v_musiclink_pro_fountain_next_action', '' );
		if ( ! $next_action && defined( 'V4V_MUSICLINK_FOUNTAIN_NEXT_ACTION' ) ) {
			$next_action = V4V_MUSICLINK_FOUNTAIN_NEXT_ACTION; // Optional fallback for advanced users.
		}

		if ( $next_action ) {
			$result = $this->fountain_try_resolve( $guid, $search_text, $next_action );
			if ( $result ) {
				return $result;
			}
			if ( $this->last_fountain_hits_parsed ) {
				// The stored id answered with a readable hits payload that
				// simply doesn't contain this guid: the action works and
				// Fountain just doesn't know the release, so there is nothing
				// for self-heal to fix - don't crawl for a new id every 15
				// minutes on a release that can never resolve.
				return null;
			}
		}

		// No stored value, or the stored one is dead (Fountain rotates these
		// ids on every deploy, and a redeploy can leave several of them side
		// by side in the same chunk where only one still works). Probe the
		// detected candidates in scan order and persist the FIRST one that
		// actually answers with a hits payload - never a silent, 404 or
		// unparsable one, or a freshly discovered dead id would be pinned as
		// the stored value and self-heal would re-derive it forever. A miss
		// cached for candidate #1 does not block candidate #2: those caches
		// are keyed per id (see fountain_try_resolve()).
		foreach ( $this->fountain_autodetect_next_actions() as $candidate ) {
			if ( $candidate === $next_action ) {
				continue; // Already tried (and failed) above - don't repeat it.
			}

			$result = $this->fountain_try_resolve( $guid, $search_text, $candidate );
			if ( $result ) {
				update_option( 'v4v_musiclink_pro_fountain_next_action', $candidate );
				return $result;
			}

			if ( $this->last_fountain_hits_parsed ) {
				// Live, readable "no match": this id IS a working search
				// action (the release just isn't in Fountain's index), so
				// store it - otherwise the option keeps pointing at a dead id
				// and every later release repeats this whole crawl.
				update_option( 'v4v_musiclink_pro_fountain_next_action', $candidate );
				return null;
			}
		}

		return null;
	}

	/**
	 * A single resolution attempt against Fountain's unofficial search
	 * endpoint using a specific next-action value. Cached per guid+value
	 * pair, so a stale cached failure never survives a next-action change;
	 * how long a miss is remembered depends on how trustworthy it was
	 * (see fountain_negative_cache_ttl()).
	 *
	 * last_fountain_hits_parsed is reset up front so it always describes
	 * THIS attempt: anything that short-circuits before the body is parsed
	 * (a cached miss, a 404, a transport error) leaves it false, which is
	 * what lets fountain_resolve() tell "this id is alive and simply has no
	 * match" from "this id is dead" while probing several candidates.
	 */
	private function fountain_try_resolve( $guid, $search_text, $next_action ) {
		$this->last_fountain_hits_parsed = false;

		// v5: the cached value is now the whole hit (id + _type), not just
		// the id, so the old v4 entries - which carry no type and would
		// otherwise survive for up to 30 days - are deliberately left
		// behind and re-resolved once each.
		$cache_key = 'fountain_id_pro_v5_' . md5( $guid . '|' . $next_action );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		// redirection => 0: never let a remote host bounce an outbound
		// request elsewhere - WP's default 5 hops would follow an open
		// redirect to arbitrary (e.g. internal) addresses. SSRF hardening.
		//
		// POST target is /search, NOT the site root: searchContent is a
		// page-bound action registered from the search page's own module
		// graph, and since Fountain's restructure a root POST gets an
		// empty application/json {} back instead of results (confirmed
		// live in 2026 - same id, same body, only the path differs),
		// while the same POST to /search returns the hits payload. The
		// headers and body below stay exactly what Fountain's own client
		// sends (the state-tree header is tolerated as-is).
		$response = wp_remote_post( 'https://fountain.fm/search', array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'Accept'                 => 'text/x-component',
				'Content-Type'           => 'text/plain;charset=UTF-8',
				'next-action'            => $next_action,
				'next-router-state-tree' => '%5B%22%22%2C%7B%22children%22%3A%5B%22__PAGE__%22%2C%7B%7D%2Cnull%2Cnull%2C0%5D%7D%2Cnull%2Cnull%2C16%5D',
				'Origin'                 => 'https://fountain.fm',
				'Referer'                => 'https://fountain.fm/',
				'Accept-Language'        => 'en-US,en;q=0.8',
				'Sec-Fetch-Dest'         => 'empty',
				'Sec-Fetch-Mode'         => 'cors',
				'Sec-Fetch-Site'         => 'same-origin',
				'Sec-GPC'                => '1',
				'sec-ch-ua'              => '"Not=A?Brand";v="99", "Chromium";v="151"',
				'sec-ch-ua-mobile'       => '?0',
				'sec-ch-ua-platform'     => '"Linux"',
				'User-Agent'             => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
			),
			'body' => wp_json_encode( array( $search_text, array() ) ),
		) );

		$this->last_fountain_status = is_wp_error( $response )
			? 0
			: (int) wp_remote_retrieve_response_code( $response );

		$hit = null;
		if ( 200 === $this->last_fountain_status ) {
			$hit = $this->extract_fountain_hit( wp_remote_retrieve_body( $response ), $guid );
			if ( ! $hit && ! $this->last_fountain_hits_parsed ) {
				// A 200 whose body wasn't a readable hits payload is not a
				// trustworthy "no" - treat it like an unreliable failure.
				$this->last_fountain_status = 0;
			}
		}

		if ( ! $hit ) {
			set_transient( $cache_key, '', $this->fountain_negative_cache_ttl() );
			return null;
		}

		set_transient( $cache_key, $hit, 30 * DAY_IN_SECONDS );
		return $hit;
	}

	/**
	 * How long to remember a Fountain resolution miss - the Fountain-side
	 * twin of negative_cache_ttl(), applying the same policy (#8) to
	 * finding a hit:
	 *
	 *   - 404: Fountain doesn't know the next-action id (stale build
	 *     fingerprint after a redeploy). The self-heal path then tries a
	 *     FRESH id, whose attempts are cached under a different key, so
	 *     the long TTL can never block recovery.
	 *   - 200 whose hits payload we could read but which lacks our guid:
	 *     Fountain genuinely has no match for this guid+title pair.
	 *
	 * Both are definitive and keep FAIL_CACHE_TTL. Everything else -
	 * transport failure, 5xx, redirect, or a 200 body we couldn't read -
	 * is unconfirmed and gets SOFT_FAIL_CACHE_TTL, so one blip can't pin
	 * the button to the fountain.fm/discover fallback for a full hour.
	 */
	private function fountain_negative_cache_ttl() {
		return ( 200 === $this->last_fountain_status || 404 === $this->last_fountain_status )
			? self::FAIL_CACHE_TTL
			: self::SOFT_FAIL_CACHE_TTL;
	}

	/**
	 * Best-effort background attempt to discover Fountain's current
	 * "next-action" Server Action IDs by fetching Fountain's search page
	 * AND its homepage, finding same-origin JS chunk URLs, and searching
	 * each for 40-44 character hex strings appearing near the word
	 * "search" (a plausibility filter - minifiers preserve string
	 * literals but mangle variable/function names).
	 *
	 * Returns EVERY candidate the walk below finds as an ordered,
	 * de-duplicated list (chunk order, then file order within a chunk), or
	 * an empty list when nothing was found or the run is throttled.
	 * Fountain publishes several near-"search" ids at once - typically an
	 * older or unrelated action listed first - and rotates them on every
	 * deploy, so a single "first one wins" pick is exactly how a dead id
	 * got stored and re-derived forever (O11). The caller probes the list
	 * in order instead; this function only gathers, and never decides
	 * which id is live.
	 *
	 * The walk is two phases so a self-heal stays as cheap as it was
	 * before O11: phase 1 is the chunks ONLY the search page references
	 * (a handful - that is where the registration lives), walked in full;
	 * phase 2, the homepage's historic first 20, is fallback coverage for
	 * a layout Fountain has not used since 2026 and is only walked when
	 * phase 1 found nothing. Walking phase 2 regardless - it holds the
	 * big framework chunks - would add tens of downloads and megabytes to
	 * a crawl that runs inline in a visitor's page render.
	 *
	 * The search page leads because that is where the registration now
	 * lives: searchContent is bound from chunks ONLY /search references
	 * (verified live in 2026 - there are zero near-"search" candidates
	 * anywhere in the homepage's own chunks), so a homepage-only crawl
	 * can no longer self-heal anything. If Fountain moved the registration
	 * back into a chunk both pages share, phase 1 would come up empty and
	 * phase 2 would pick it up.
	 *
	 * Throttled to at most once per 15 minutes (regardless of outcome) so
	 * a broken detection mechanism doesn't repeatedly hammer fountain.fm
	 * on every page view - a successful detection persists the new value
	 * via update_option() in fountain_resolve() above, so this path is
	 * only ever hit again if something's still wrong after that.
	 */
	private function fountain_autodetect_next_actions() {
		$throttle_key = 'fountain_autodetect_throttle_pro';
		if ( get_transient( $throttle_key ) ) {
			return array();
		}
		set_transient( $throttle_key, true, 15 * MINUTE_IN_SECONDS );

		// Collect same-origin _next chunk URLs from both seed pages.
		// redirection => 0: never let a remote host bounce an outbound
		// request elsewhere - WP's default 5 hops would follow an open
		// redirect to arbitrary (e.g. internal) addresses. SSRF hardening.
		$seed_scripts = array(); // Seed URL => unique trusted chunk URLs on that page.
		foreach ( array( 'https://fountain.fm/search', 'https://fountain.fm/' ) as $seed_url ) {
			$seed_response = wp_remote_get( $seed_url, array(
				'timeout'     => 10,
				'redirection' => 0,
			) );
			if ( is_wp_error( $seed_response ) || 200 !== (int) wp_remote_retrieve_response_code( $seed_response ) ) {
				continue;
			}
			preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/i', wp_remote_retrieve_body( $seed_response ), $matches );

			$found = array();
			foreach ( $matches[1] as $src ) {
				// Normalise relative forms first (protocol-relative "//host/x",
				// root-relative "/path") so the check below sees a real URL.
				if ( 0 === strpos( $src, '//' ) ) {
					$src = 'https:' . $src;
				} elseif ( 0 === strpos( $src, '/' ) ) {
					$src = 'https://fountain.fm' . $src;
				}

				// Exact host match (apex or subdomain), HTTPS only. The previous
				// substring check also accepted e.g. https://evil.tld/fountain.fm/_next/x.js,
				// sending this server after whatever such a page declared. The
				// scheme check keeps a cleartext http:// chunk - modifiable in
				// transit - from supplying a next-action value this plugin then
				// persists via update_option().
				$host    = wp_parse_url( $src, PHP_URL_HOST );
				$scheme  = wp_parse_url( $src, PHP_URL_SCHEME );
				$trusted = $scheme && 'https' === strtolower( $scheme )
					&& $host && ( 'fountain.fm' === $host || '.fountain.fm' === substr( $host, -12 ) );

				if ( $trusted && false !== strpos( $src, '_next' ) ) {
					$found[] = $src;
				}
			}
			$seed_scripts[ $seed_url ] = array_values( array_unique( $found ) );
		}
		if ( ! $seed_scripts ) {
			return array();
		}

		// Phase 1: chunks the search page references and the homepage does
		// NOT - that delta is where the action registration lives now.
		// Phase 2: the homepage's historic first 20 as fallback only, and
		// only when phase 1 turned up nothing (see the docblock) - so a
		// routine self-heal downloads the handful of delta chunks and
		// stops instead of re-walking the homepage's big framework bundles.
		// Both caps keep a chunk-storm from turning this into a full-site
		// crawl; the 15-minute throttle bounds it anyway. A failed seed
		// just contributes an empty list, so either page alone still
		// yields a sane scan (homepage-only exactly matches the pre-2026
		// behaviour as a floor).
		$search_scripts = isset( $seed_scripts['https://fountain.fm/search'] )
			? $seed_scripts['https://fountain.fm/search']
			: array();
		$home_scripts   = isset( $seed_scripts['https://fountain.fm/'] )
			? $seed_scripts['https://fountain.fm/']
			: array();

		$candidates = $this->fountain_scan_chunks_for_actions(
			array_slice( array_values( array_diff( $search_scripts, $home_scripts ) ), 0, 20 )
		);

		if ( ! $candidates ) {
			$candidates = $this->fountain_scan_chunks_for_actions( array_slice( $home_scripts, 0, 20 ) );
		}

		return $candidates;
	}

	/**
	 * Walks JS chunk URLs in order and returns every near-"search" hex id
	 * they carry as an ordered, de-duplicated list (a hex seen in two
	 * chunks is listed once, at its first position). A chunk that fails to
	 * fetch simply contributes nothing - one missing chunk must not abort
	 * the walk.
	 */
	private function fountain_scan_chunks_for_actions( $script_urls ) {
		$candidates = array(); // Keyed by hex => order-preserving de-duplication.
		foreach ( $script_urls as $script_url ) {
			$response = wp_remote_get( $script_url, array(
				'timeout'     => 8,
				'redirection' => 0,
			) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$js = wp_remote_retrieve_body( $response );

			if ( ! preg_match_all( '/[0-9a-f]{40,44}/i', $js, $hex_matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $hex_matches[0] as $hex_match ) {
				$hex    = $hex_match[0];
				$offset = $hex_match[1];
				$window = substr( $js, max( 0, $offset - 300 ), 600 );
				if ( false !== stripos( $window, 'search' ) ) {
					$candidates[ $hex ] = true;
				}
			}
		}

		return array_keys( $candidates );
	}

	/**
	 * Parses Fountain's React Server Components wire format looking for
	 * the chunk containing a "hits" array, then returns the _id AND _type
	 * of whichever hit's _guid matches ours, as array( 'id' => ..., 'type'
	 * => ... ). The _type is essential: Fountain serves a different path
	 * prefix per type (see fountain_path_segment()), and guessing it from
	 * the shortcode's own attributes 404s whenever Fountain has filed the
	 * release under the other category.
	 *
	 * IMPORTANT: this chunk is NOT reliably at the start of its own line -
	 * confirmed via live testing that it can be glued directly onto the
	 * end of a preceding text chunk with no newline separator at all
	 * (e.g. "...</p>1:{"hits":[...>). An earlier version of this parser
	 * assumed line-start boundaries and silently failed to find real
	 * matches because of this. Since the hits chunk is reliably the LAST
	 * chunk in the stream, this instead searches for its distinctive
	 * "N:{"hits":" marker anywhere in the body (no line-start assumption)
	 * and takes everything from there to the end as the JSON payload.
	 * Also maintains last_fountain_hits_parsed so
	 * fountain_negative_cache_ttl() can tell a readable (even if empty)
	 * answer from a body we couldn't understand.
	 */
	private function extract_fountain_hit( $raw_body, $target_guid ) {
		$this->last_fountain_hits_parsed = false;
		if ( ! preg_match( '/\d+:(\{"hits":.*)/s', (string) $raw_body, $matches ) ) {
			return null;
		}
		$decoded = json_decode( trim( $matches[1] ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['hits'] ) || ! is_array( $decoded['hits'] ) ) {
			return null;
		}
		// Past this point the answer is readable even if empty: a hits
		// array without our guid is a trustworthy "no match".
		$this->last_fountain_hits_parsed = true;
		foreach ( $decoded['hits'] as $hit ) {
			if ( isset( $hit['_guid'], $hit['_id'] ) && $hit['_guid'] === $target_guid ) {
				return array(
					'id'   => $hit['_id'],
					'type' => isset( $hit['_type'] ) ? (string) $hit['_type'] : '',
				);
			}
		}
		return null;
	}

	/**
	 * The fountain.fm path prefix for a resolved hit.
	 *
	 * Fountain serves the SAME opaque id under four different prefixes,
	 * chosen by how IT has filed the release - not by anything the
	 * shortcode knows. Music ingested normally is TRACK/ALBUM, but a
	 * release Fountain has categorised as a podcast is EPISODE/SHOW (this
	 * happens to real music releases, e.g. an album Fountain ingested via
	 * an RSS feed). The wrong prefix always renders Fountain's client-side
	 * 404 - the response is still HTTP 200, so the status code cannot be
	 * used to detect it.
	 *
	 * The type therefore decides the prefix. Only when Fountain omits a
	 * type we recognise do we fall back to the historic guess - whether
	 * the shortcode supplied a track guid - which preserves the previous
	 * behaviour rather than degrading to the fountain.fm/discover fallback.
	 *
	 * @param array $hit      Resolution result from extract_fountain_hit().
	 * @param bool  $has_track Whether the shortcode carried track data (fallback only).
	 */
	private function fountain_path_segment( $hit, $has_track ) {
		$by_type = array(
			'TRACK'   => 'track',
			'ALBUM'   => 'album',
			'EPISODE' => 'episode',
			'SHOW'    => 'show',
		);

		$type = isset( $hit['type'] ) ? strtoupper( (string) $hit['type'] ) : '';
		if ( isset( $by_type[ $type ] ) ) {
			return $by_type[ $type ];
		}

		return $has_track ? 'track' : 'album';
	}

	/**
	 * GET /v1/feeds/{feedGuid} - album/feed-level metadata: title and
	 * artist, served from the cache row (stale-while-revalidate):
	 *
	 *  1. Row has MI data and mi_updated_at is within
	 *     mi_refresh_interval()  -> serve, no HTTP.
	 *  2. Row has MI data but is stale (or mi_updated_at IS NULL) ->
	 *     serve it immediately, then queue a background pull - only when
	 *     the render gate allows, no failure is being remembered and no
	 *     attempt is already in flight (see queue_mi_refresh()).
	 *  3. Row has no MI data at all -> the old blocking miss path:
	 *     negative-transient check, fetch, record onto the row (or
	 *     remember the failure per negative_cache_ttl()).
	 *
	 * Branch 3 is NOT gated: the search box, the log beacons and the og:
	 * preview all need titles to do their job, and filling an absent row
	 * costs the same API traffic the old transient fill did. Only branch
	 * 2's queueing is render-only. Rows are never evicted: stale data
	 * keeps serving at any age, so a Music Index outage cannot turn an
	 * already-seen release into "Album/Song not found".
	 */
	private function mi_get_feed( $feed_guid ) {
		$row = $this->cache_get_row( $feed_guid, '' );

		if ( ! $row || ! $this->row_has_mi( $row ) ) {
			return $this->mi_fetch_and_record( 'feed', $feed_guid, '' );
		}

		if ( ! $this->row_mi_is_fresh( $row ) ) {
			$this->queue_mi_refresh( 'feed', $feed_guid, '' );
		}

		return array(
			'title'  => $row->album_title,
			'artist' => $row->artist,
			'image'  => $row->image_url,
		);
	}

	/**
	 * GET /v1/feeds/{feedGuid}/tracks/{trackGuid} - track-level metadata:
	 * track title, its parent album title, and artist. Same three-branch
	 * cache contract as mi_get_feed(); see the docblock there.
	 */
	private function mi_get_track( $feed_guid, $track_guid ) {
		$row = $this->cache_get_row( $feed_guid, $track_guid );

		if ( ! $row || ! $this->row_has_mi( $row ) ) {
			return $this->mi_fetch_and_record( 'track', $feed_guid, $track_guid );
		}

		if ( ! $this->row_mi_is_fresh( $row ) ) {
			$this->queue_mi_refresh( 'track', $feed_guid, $track_guid );
		}

		return array(
			'title'       => $row->track_title,
			'album_title' => $row->album_title,
			'artist'      => $row->artist,
			'image'       => $row->image_url,
		);
	}

	/**
	 * Branch 3: no cached MI data for this pair at all - fetch it now
	 * (blocking, exactly like the old transient miss path) and record it
	 * on the row. Deliberately not gated (see mi_get_feed()).
	 *
	 * Returns the parsed result array, or null when the lookup failed -
	 * in which case the failure is remembered per negative_cache_ttl()
	 * and the row is left untouched.
	 */
	private function mi_fetch_and_record( $kind, $album, $track ) {
		$result = ( 'feed' === $kind )
			? $this->mi_fetch_feed( $album )
			: $this->mi_fetch_track( $album, $track );

		if ( null === $result ) {
			return null;
		}

		$this->mi_store_mi_fields( $kind, $album, $track, $result );

		return $result;
	}

	/**
	 * The fetch half of the feed lookup: negative-transient check, API
	 * call, parse. Shared by branch 3 and the background pull so the two
	 * can never drift. On a miss (definitive or not) the failure is
	 * remembered under the key branch 3 checks, for however long
	 * negative_cache_ttl() says it deserves.
	 */
	private function mi_fetch_feed( $feed_guid ) {
		$negative_key = $this->mi_negative_key( 'feed', $feed_guid, '' );

		if ( false !== get_transient( $negative_key ) ) {
			return null;
		}

		$body = $this->mi_api_get( '/feeds/' . rawurlencode( $feed_guid ) );

		if ( empty( $body['data']['title'] ) ) {
			set_transient( $negative_key, array(), $this->negative_cache_ttl() );
			return null;
		}

		return array(
			'title'  => $body['data']['title'],
			'artist' => isset( $body['data']['release_artist'] ) ? $body['data']['release_artist'] : '',
			'image'  => isset( $body['data']['image_url'] ) ? $body['data']['image_url'] : '',
		);
	}

	/**
	 * The fetch half of the track lookup - see mi_fetch_feed().
	 */
	private function mi_fetch_track( $feed_guid, $track_guid ) {
		$negative_key = $this->mi_negative_key( 'track', $feed_guid, $track_guid );

		if ( false !== get_transient( $negative_key ) ) {
			return null;
		}

		$body = $this->mi_api_get( '/feeds/' . rawurlencode( $feed_guid ) . '/tracks/' . rawurlencode( $track_guid ) );

		if ( empty( $body['data']['title'] ) ) {
			set_transient( $negative_key, array(), $this->negative_cache_ttl() );
			return null;
		}

		$data = $body['data'];

		return array(
			'title'       => $data['title'],
			'album_title' => isset( $data['feed_title'] ) ? $data['feed_title'] : '',
			'artist'      => ! empty( $data['track_artist'] ) ? $data['track_artist'] : ( isset( $data['release_artist'] ) ? $data['release_artist'] : '' ),
			'image'       => isset( $data['image_url'] ) ? $data['image_url'] : '',
		);
	}

	/**
	 * Writes freshly fetched MI data onto the cache row: the MI columns
	 * plus mi_updated_at, and NOTHING else - fountain_url and
	 * fountain_updated_at are owned by the link path and are never
	 * touched here (the ON DUPLICATE KEY UPDATE lists only MI columns).
	 * A successful pull with no artwork still stamps mi_updated_at, so a
	 * legitimately empty image_url never re-pulls.
	 */
	private function mi_store_mi_fields( $kind, $album, $track, $result ) {
		global $wpdb;
		$table = $this->cache_table();
		$hash  = $this->cache_row_hash( $album, $track );

		if ( 'feed' === $kind ) {
			$album_title = $result['title'];
			$track_title = '';
		} else {
			$album_title = isset( $result['album_title'] ) ? $result['album_title'] : '';
			$track_title = $result['title'];
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(lookup_hash, album_guid, track_guid, album_title, track_title, artist, image_url, fountain_url, fountain_updated_at, mi_updated_at)
				VALUES (%s, %s, %s, %s, %s, %s, %s, '', %s, %s)
				ON DUPLICATE KEY UPDATE
					album_title = VALUES(album_title),
					track_title = VALUES(track_title),
					artist = VALUES(artist),
					image_url = VALUES(image_url),
					mi_updated_at = VALUES(mi_updated_at)",
				$hash,
				$album,
				$track,
				$album_title,
				$track_title,
				isset( $result['artist'] ) ? $result['artist'] : '',
				isset( $result['image'] ) ? $result['image'] : '',
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);

		unset( $this->row_memo[ $hash ] );
	}

	/**
	 * The transient key a failed MI lookup is remembered under - the key
	 * branch 3 and the background pull both check first. Unchanged
	 * formula from the old positive/negative shared key (the `_pro_`
	 * keeps this plugin's misses in their own keyspace): the positives
	 * have moved to the cache table, so this key only ever holds
	 * remembered misses.
	 */
	private function mi_negative_key( $kind, $album, $track ) {
		return 'feed' === $kind
			? 'mi_feed_pro_v' . self::CACHE_SCHEMA_VERSION . '_' . md5( $album )
			: 'mi_track_pro_v' . self::CACHE_SCHEMA_VERSION . '_' . md5( $album . '|' . $track );
	}

	/**
	 * Whether a cache row carries Music Index data worth serving. A
	 * migrated row counts (its copied titles are real data even though
	 * mi_updated_at is still NULL - which is exactly why a NULL stamp is
	 * always refresh-eligible).
	 */
	private function row_has_mi( $row ) {
		return ( null !== $row->mi_updated_at && '' !== $row->mi_updated_at )
			|| '' !== $row->album_title
			|| '' !== $row->track_title
			|| '' !== $row->artist
			|| '' !== $row->image_url;
	}

	/**
	 * Whether the row's MI half is within the admin-set refresh interval
	 * (mi_refresh_interval()). NULL/empty/unparsable mi_updated_at is
	 * always stale. Both sides of the comparison are site-local
	 * 'Y-m-d H:i:s' strings: mi_updated_at is stamped with
	 * current_time('mysql'), and the cutoff is "now - interval" in GMT
	 * converted the same way, so no timezone conversion can skew the
	 * window.
	 */
	private function row_mi_is_fresh( $row ) {
		if ( empty( $row->mi_updated_at ) ) {
			return false;
		}

		$cutoff = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - $this->mi_refresh_interval() ) );

		return (string) $row->mi_updated_at >= $cutoff;
	}

	/**
	 * The admin-configured MI refresh interval in seconds: option
	 * (hours), 0/missing/unset falling back to the 12-hour default,
	 * clamped to 1..720 (1 hour .. 30 days). Refresh-only: it decides
	 * when a background pull is queued, never when a row is dropped.
	 */
	private function mi_refresh_interval() {
		$hours = (int) get_option( 'v4v_musiclink_pro_mi_refresh_hours', self::MI_REFRESH_DEFAULT_HOURS );

		return min( max( $hours, 1 ), 720 ) * HOUR_IN_SECONDS;
	}

	/**
	 * Queue a background Music Index pull for a stale row (branch 2 of
	 * mi_get_feed()/mi_get_track()). Default-deny: the $mi_refresh_allowed
	 * gate means only render() can ever get here - the search box, the
	 * log beacons and the og: preview cannot trigger a refresh. The
	 * per-row attempt marker keeps concurrent renders of the same stale
	 * row from stacking pulls, and expires after a minute so a hard-killed
	 * attempt self-heals on the next allowed render.
	 */
	private function queue_mi_refresh( $kind, $album, $track ) {
		if ( ! $this->mi_refresh_allowed ) {
			return;
		}

		$hash         = $this->cache_row_hash( $album, $track );
		$marker       = 'v4v_musiclink_pro_mi_attempt_' . md5( $hash );
		$negative_key = $this->mi_negative_key( $kind, $album, $track );

		// A remembered failure, or an attempt already in flight: this row
		// is spoken for - do nothing. (The failure transient holds an
		// empty array, so the check is "is set", never "is truthy".)
		if ( false !== get_transient( $negative_key ) ) {
			return;
		}
		if ( get_transient( $marker ) ) {
			return;
		}

		$this->mi_refresh_pending[ $hash ] = array(
			'kind'  => $kind,
			'album' => $album,
			'track' => $track,
		);

		set_transient( $marker, 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Runs the queued background pulls on the shutdown hook (priority 10,
	 * so WordPress's own wp_ob_end_flush_all at priority 1 has already
	 * sent the page). No cron, no queue option, no server config:
	 * ignore_user_abort() plus fastcgi_finish_request() when available
	 * close the response first, then one short Music Index GET completes
	 * per stale row. If PHP dies mid-pull the row simply stays stale,
	 * keeps serving, and the next allowed render retries (the attempt
	 * marker expires after a minute).
	 */
	public function run_queued_mi_refresh() {
		if ( empty( $this->mi_refresh_pending ) ) {
			return;
		}

		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$pending                  = $this->mi_refresh_pending;
		$this->mi_refresh_pending = array();

		foreach ( $pending as $item ) {
			$kind  = $item['kind'];
			$album = $item['album'];
			$track = $item['track'];
			$hash  = $this->cache_row_hash( $album, $track );

			// A failure remembered since the queue was taken: skip; the
			// row's next view re-queues once that transient expires.
			if ( false !== get_transient( $this->mi_negative_key( $kind, $album, $track ) ) ) {
				continue;
			}

			// Re-read the row straight from the database (the memo may
			// predate this request's end): gone, or already refreshed by
			// a concurrent request, means there is nothing to do.
			global $wpdb;
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . $this->cache_table() . ' WHERE lookup_hash = %s LIMIT 1',
					$hash
				)
			);
			if ( ! $row || $this->row_mi_is_fresh( $row ) ) {
				continue;
			}
			unset( $this->row_memo[ $hash ] );

			// The shared fetch half; a failure sets the negative
			// transient (via negative_cache_ttl()) and leaves the row
			// stale - the same contract branch 3 has.
			$result = ( 'feed' === $kind )
				? $this->mi_fetch_feed( $album )
				: $this->mi_fetch_track( $album, $track );

			if ( null !== $result ) {
				$this->mi_store_mi_fields( $kind, $album, $track, $result );
			}
		}
	}

	/**
	 * How long a failed lookup may be remembered as "not found".
	 *
	 * A definitive answer - HTTP 404, or a 200 whose payload simply lacks
	 * the title - proves the guid doesn't resolve, so it earns the full
	 * FAIL_CACHE_TTL. Anything else (timeout, DNS, 5xx, redirect,
	 * undecodable body) says nothing about the guid itself. Caching *that*
	 * for an hour is what made a brief Music Index outage keep rendering
	 * "Album/Song not found" long after the API recovered: the negative
	 * transient short-circuited every retry, and the one plugin could show
	 * a stale success from its own separate keyspace while the other kept
	 * serving the recorded failure. Unconfirmed failures get only
	 * SOFT_FAIL_CACHE_TTL - enough to spare a struggling API a retry storm,
	 * little enough to self-heal almost immediately.
	 */
	private function negative_cache_ttl() {
		return ( 200 === $this->last_mi_status || 404 === $this->last_mi_status )
			? self::FAIL_CACHE_TTL
			: self::SOFT_FAIL_CACHE_TTL;
	}

	/**
	 * Low-level GET against the Music Index API. Returns the decoded
	 * response body as an array, or null on any failure (network error,
	 * non-200 response, bad JSON). No authentication required. Records the
	 * outcome's HTTP status in $last_mi_status (0 = unreliable: transport
	 * error or undecodable body) so callers can pick the right negative
	 * cache TTL - see negative_cache_ttl().
	 */
	private function mi_api_get( $path ) {
		$url = self::MI_API_BASE . $path;

		// redirection => 0: never let a remote host bounce an outbound
		// request elsewhere - WP's default 5 hops would follow an open
		// redirect to arbitrary (e.g. internal) addresses. SSRF hardening.
		$response = wp_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'User-Agent' => 'phafe.com-MusicLink/1.10 (' . home_url() . ')',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->last_mi_status = 0;
			return null;
		}

		$this->last_mi_status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $this->last_mi_status ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			// A 200 we can't parse proves nothing either - don't let a
			// transient proxy error be remembered as a missing guid.
			$this->last_mi_status = 0;
			return null;
		}
		return $body;
	}

	private function render_html( $links, $has_track, $album_title, $track_title, $artist, $art_url, $show_header = true, $album_guid = '', $track_guid = '' ) {
		$heading = $has_track
			? __( 'Listen to this track:', 'v4v-musiclink-pro' )
			: __( 'Listen to this album:', 'v4v-musiclink-pro' );

		$title_line = $has_track && $track_title
			? sprintf( '"%s" from %s', $track_title, $album_title )
			: $album_title;

		$current_url = $this->musiclink_canonical_url();

		ob_start();
		?>
		<div class="v4v-musiclink-pro" data-album="<?php echo esc_attr( $album_guid ); ?>" data-track="<?php echo esc_attr( $track_guid ); ?>">
			<?php if ( $show_header && ( $title_line || $art_url ) ) : ?>
				<div class="v4v-musiclink-pro-header">
					<div class="v4v-musiclink-pro-info">
						<?php if ( $title_line ) : ?>
							<h1 class="v4v-musiclink-pro-title"><?php echo esc_html( $title_line ); ?></h1>
						<?php endif; ?>
						<?php if ( $artist ) : ?>
							<p class="v4v-musiclink-pro-artist"><?php echo esc_html( sprintf( __( 'by %s', 'v4v-musiclink-pro' ), $artist ) ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( $art_url ) : ?>
						<img
							class="v4v-musiclink-pro-art"
							src="<?php echo esc_url( $art_url ); ?>"
							alt="<?php echo esc_attr( $title_line ? $title_line : __( 'Album art', 'v4v-musiclink-pro' ) ); ?>"
							loading="lazy"
						/>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p class="v4v-musiclink-pro-share">
				<button type="button" class="v4v-musiclink-pro-share-btn" data-url="<?php echo esc_url( $current_url ); ?>">
					<span aria-hidden="true">🔗</span>
					<span class="v4v-musiclink-pro-share-label"><?php esc_html_e( 'Share', 'v4v-musiclink-pro' ); ?></span>
				</button>
			</p>

			<p class="v4v-musiclink-pro-heading"><?php echo esc_html( $heading ); ?></p>
			<?php if ( empty( $links ) ) : ?>
				<p class="v4v-musiclink-pro-error"><?php esc_html_e( 'No music apps are currently enabled - check Settings > V4V MusicLink.', 'v4v-musiclink-pro' ); ?></p>
			<?php else :
				$random_order = (bool) get_option( 'v4v_musiclink_pro_random_app_order', false ) && count( $links ) > 1;
			?>
				<ul class="v4v-musiclink-pro-list"<?php echo $random_order ? ' data-v4v-shuffle="1"' : ''; ?>>
					<?php foreach ( $links as $slug => $link ) : ?>
						<li class="v4v-musiclink-pro-item v4v-musiclink-pro-<?php echo esc_attr( $slug ); ?>" data-app="<?php echo esc_attr( $slug ); ?>">
							<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<img
									class="v4v-musiclink-pro-icon"
									src="<?php echo esc_url( $link['icon_url'] ); ?>"
									alt=""
									width="40"
									height="40"
									loading="lazy"
								/>
								<span><?php echo esc_html( $link['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $random_order ) : ?>
					<script>
					(function () {
						var lists = document.querySelectorAll( '[data-v4v-shuffle="1"]' );
						for ( var i = 0; i < lists.length; i++ ) {
							var ul = lists[ i ];
							var items = Array.prototype.slice.call( ul.children );
							for ( var j = items.length - 1; j > 0; j-- ) {
								var k = Math.floor( Math.random() * ( j + 1 ) );
								var tmp = items[ j ];
								items[ j ] = items[ k ];
								items[ k ] = tmp;
							}
							for ( var n = 0; n < items.length; n++ ) {
								ul.appendChild( items[ n ] );
							}
							ul.setAttribute( 'data-v4v-shuffle', '0' );
						}
					})();
					</script>
				<?php endif; ?>
			<?php endif; ?>

			<p class="v4v-musiclink-pro-footer">
				<?php esc_html_e( 'Generated by', 'v4v-musiclink-pro' ); ?>
				<a href="https://phafe.com/musiclink"><?php esc_html_e( 'V4V MusicLink', 'v4v-musiclink-pro' ); ?></a>.
				<?php esc_html_e( 'Powered by', 'v4v-musiclink-pro' ); ?>
				<a href="https://musicindex.org">MusicIndex.org</a>
			</p>
		</div>

		<div class="v4v-musiclink-pro-modal-overlay" id="v4v-musiclink-pro-fountain-modal">
			<div class="v4v-musiclink-pro-modal" role="dialog" aria-modal="true">
				<p class="v4v-musiclink-pro-modal-message"><?php esc_html_e( 'Unable to generate Fountain.fm link for this release.', 'v4v-musiclink-pro' ); ?></p>
				<p class="v4v-musiclink-pro-modal-message"><?php esc_html_e( 'Please use Fountain\'s search.', 'v4v-musiclink-pro' ); ?></p>
				<div class="v4v-musiclink-pro-modal-actions">
					<button type="button" class="v4v-musiclink-pro-modal-btn v4v-musiclink-pro-modal-cancel"><?php esc_html_e( 'Cancel', 'v4v-musiclink-pro' ); ?></button>
					<button type="button" class="v4v-musiclink-pro-modal-btn v4v-musiclink-pro-modal-ok"><?php esc_html_e( 'OK', 'v4v-musiclink-pro' ); ?></button>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

register_activation_hook( __FILE__, array( 'V4V_Music_Link_Resolver_Pro', 'create_log_table' ) );
new V4V_Music_Link_Resolver_Pro();
