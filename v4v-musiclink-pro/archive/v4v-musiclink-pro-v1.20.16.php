<?php
/**
 * Plugin Name: V4V Music Link Resolver Pro
 * Description: PRO EDITION - isolated copy of V4V Music Link Resolver with entirely separate settings/options/cache/database storage from the standard edition, safe to run alongside it on the same site without conflicts. Uses the [musiclink_pro] shortcode (not [musiclink]). Provides guid-based cross-platform music links (Stablekraft, PodTunes.app, v4vmusic.com, Fountain) based on ?album={guid}&track={guid} in the page URL. With no guid supplied, shows a Music Index search box instead.
 * Version: 1.20.16
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
 *      c) Find the POST request to "fountain.fm/", open its Headers, copy
 *         the "next-action" header value (a long hex string).
 *      d) Paste it into Settings > V4V MusicLink in wp-admin.
 *    This value is a build fingerprint that changes whenever Fountain
 *    redeploys - if it goes stale, the plugin now tries to self-heal
 *    automatically (see below) before falling back to a plain link, so
 *    manual re-capture is a fallback rather than a requirement. Nothing
 *    breaks if this is left unset from the start either - Fountain just
 *    always falls back to a plain fountain.fm link until a value (manual
 *    or auto-detected) is available.
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
 * - v4vmusic.com: has no public album/track pages (modal-only UI), so this
 *   links to a v4vmusic.com *search* instead - song search when a track
 *   guid is present, album search otherwise. Titles come from Music Index.
 * - Fountain: no reliable public guid-based or search-page link exists
 *   (both confirmed to 404 by direct testing). Their only resolution
 *   mechanism is the unofficial Server Action endpoint described above.
 *   If a resolution attempt fails (most often because the configured
 *   "next-action" has gone stale after a Fountain redeploy), the plugin
 *   automatically tries to self-heal: it crawls fountain.fm's own script
 *   files looking for a fresh next-action value (best-effort heuristic,
 *   throttled to once per 15 minutes), retries once with it, and - if
 *   that succeeds - saves it so future requests use it directly without
 *   repeating the crawl. Falls back to a plain fountain.fm link if all of
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
 *   - VIEWS: a guid supplied in the URL that resolves to a real release.
 *     One row per URL-driven render. Releases hardcoded into a shortcode
 *     attribute are not logged as views - that would add a row on every
 *     render of the post, feeds and crawlers included.
 *   - LAUNCHES: a click on an app button that actually navigates. One row
 *     carrying the same date/artist/album/track plus the `app` slug,
 *     from every placement (a click is a deliberate action), and only
 *     when the click goes through - cancelling Fountain's modal dialog
 *     logs nothing.
 *
 * The columns hold titles resolved from Music Index; the album/track
 * guids that drove a row are never stored, and nothing is written
 * anywhere else (no error log, no debug file). Rows are logged
 * unconditionally, not deduplicated per visitor/session. Read them and
 * run the reports - "Total by Artist/Album/Track" counts views,
 * "Total by Music App" counts launches - from the View Log and Reports
 * tabs under Settings > V4V MusicLink Pro, which can also clear the
 * table.
 *
 * NOT FOUND HANDLING: if a guid is validly formatted but Music Index has no
 * matching release, the page falls back to the search view (same as no
 * guid at all) with an "Album/Song not found" message, rather than showing
 * broken/empty app buttons.
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
	const CACHE_TTL       = 12 * HOUR_IN_SECONDS;
	const FAIL_CACHE_TTL  = HOUR_IN_SECONDS;
	// Negative cache for failures that do NOT prove the guid is missing
	// (timeout, DNS error, 5xx, redirect, undecodable body). Long enough to
	// stop hammering a struggling API, short enough that an outage stops
	// masquerading as "Album/Song not found" almost immediately - see
	// negative_cache_ttl().
	const SOFT_FAIL_CACHE_TTL = 30;
	// Bump whenever the shape of what gets cached changes, so old
	// transients (with different/missing fields) are never served stale.
	const CACHE_SCHEMA_VERSION = 4;

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

	public function __construct() {
		add_shortcode( 'musiclink_pro', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_v4v_musiclink_pro_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_v4v_musiclink_pro_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_v4v_musiclink_pro_log_app', array( $this, 'ajax_log_app' ) );
		add_action( 'wp_ajax_nopriv_v4v_musiclink_pro_log_app', array( $this, 'ajax_log_app' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_v4v_musiclink_pro_clear_log', array( $this, 'handle_clear_log' ) );

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
		$album = isset( $_GET['album'] ) ? sanitize_text_field( wp_unslash( $_GET['album'] ) ) : '';
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
	 * Logs one row: date, artist, album, track (blank when it's an
	 * album-only view) plus the music app that was launched - blank for a
	 * plain page view, one of app_registry()'s slugs for a button launch.
	 * Only titles resolved from Music Index are stored; the album/track
	 * guids that drove the row never are. Page views are logged for
	 * URL-driven renders only (render() skips this when the guid came from
	 * a hardcoded shortcode attribute); launches are logged wherever the
	 * button was clicked, because a click is a deliberate action. Logged
	 * unconditionally on every qualifying row, not deduplicated per
	 * visitor/session.
	 *
	 * @param string $artist      Resolved artist title.
	 * @param string $album_title Resolved album title.
	 * @param string $track_title Resolved track title, or ''.
	 * @param string $app         Launched app slug, or '' for a view.
	 */
	private function log_view( $artist, $album_title, $track_title, $app = '' ) {
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

		$album    = isset( $_POST['album'] ) ? sanitize_text_field( wp_unslash( $_POST['album'] ) ) : '';
		$track    = isset( $_POST['track'] ) ? sanitize_text_field( wp_unslash( $_POST['track'] ) ) : '';
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
	 * This page's canonical URL: the request path plus the sanitised
	 * album/track args, and nothing else.
	 *
	 * The query string IS this page's content, but echoing the raw
	 * REQUEST_URI (add_query_arg( null, null )) into the get_canonical_url
	 * filter or the buffer rewrite hands consumers every other -
	 * attacker-supplied - query arg, and not all of them escape on output.
	 * Rebuilding also drops junk args, so both producers of the canonical
	 * tag (this filter and rewrite_canonical_in_html()) agree on one URL.
	 */
	private function musiclink_canonical_url() {
		$args = array();
		if ( ! empty( $_GET['album'] ) ) {
			$args['album'] = sanitize_text_field( wp_unslash( $_GET['album'] ) );
		}
		if ( ! empty( $_GET['track'] ) ) {
			$args['track'] = sanitize_text_field( wp_unslash( $_GET['track'] ) );
		}

		$path = explode( '?', add_query_arg( null, null ), 2 )[0];

		return esc_url( home_url( add_query_arg( $args, $path ) ) );
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
				return $matches[1] . $correct_url . $matches[2];
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
		$current_url = home_url( add_query_arg( null, null ) );

		$html = $this->rewrite_canonical_in_html( $html );

		// Also rewrite the actual <title> tag, not just og:title - some
		// link-preview clients read the plain document title instead of
		// Open Graph tags (confirmed via a real screenshot). Only touches
		// it when a guid is present and resolved; the bare search page
		// keeps its normal WordPress title.
		$album = isset( $_GET['album'] ) ? sanitize_text_field( wp_unslash( $_GET['album'] ) ) : '';
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
		if ( ! in_array( $date_range, array( 'today', '7days', '30days', '90days', 'all', 'custom' ), true ) ) {
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
			// Launch rows only - a blank app means a plain page view.
			$where[] = "app != ''";
		} else {
			// Keep launch rows out of the three view reports: a launch
			// repeats the artist/album/track of the click it came from, so
			// counting both would double up numbers that have always meant
			// "logged views".
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
				/* translators: %d: total number of log entries (page views and app launches) */
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
							<?php /* Blank app = a page view; a slug = the button that launched. */ ?>
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
		register_setting( 'v4v_musiclink_pro', 'v4v_musiclink_pro_apps', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_apps_settings' ),
			'default'           => array(),
		) );
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
		if ( ! in_array( $active_tab, array( 'settings', 'log', 'reports' ), true ) ) {
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
			</h2>

			<?php if ( 'log' === $active_tab ) : ?>
				<?php $this->render_log_tab_content(); ?>
			<?php elseif ( 'reports' === $active_tab ) : ?>
				<?php $this->render_reports_tab_content(); ?>
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
									'Optional starting point for direct Fountain.fm links (without any value here, Fountain buttons link to fountain.fm plainly until one is found). This value changes whenever Fountain redeploys - if it goes stale, the plugin automatically tries to detect a fresh one in the background and saves it here when successful, so manual updates usually aren\'t needed. If you want to set it directly anyway: open fountain.fm with browser dev tools\' Network tab open, search for anything, find the POST request to "fountain.fm/", and copy its "next-action" request header.',
									'v4v-musiclink-pro'
								);
								?>
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

		wp_register_script( 'v4v-musiclink-pro', false, array(), '1.20.16', true );
		wp_enqueue_script( 'v4v-musiclink-pro' );
		wp_localize_script( 'v4v-musiclink-pro', 'v4vMusicLinkPro', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'v4v_musiclink_pro_search' ),
			// Separate nonce for the launch beacon, so a token lifted from
			// the page can't be replayed against the other endpoint.
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
					if (href === "https://fountain.fm" || href === "https://fountain.fm/") {
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
		$album = isset( $_GET['album'] ) ? sanitize_text_field( wp_unslash( $_GET['album'] ) ) : '';
		$track = isset( $_GET['track'] ) ? sanitize_text_field( wp_unslash( $_GET['track'] ) ) : '';

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
		$current_url = home_url( add_query_arg( null, null ) );

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
	 * app's logo assets ourselves.
	 */
	private function favicon_url( $domain ) {
		return 'https://www.google.com/s2/favicons?sz=128&domain=' . rawurlencode( $domain );
	}

	/**
	 * Resolves title/artist/artwork for an album (and optionally a track)
	 * via Music Index, once, in one place - used by both the on-page
	 * display and the social-preview meta tags below, so they never drift
	 * out of sync with each other.
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
		$atts = shortcode_atts( array(
			'album'  => '',
			'track'  => '',
			'header' => '',
		), $atts, 'musiclink_pro' );

		// Did either guid arrive as a hardcoded shortcode attribute (a
		// release embedded in post content) rather than from the URL's
		// query string? Decided here, before the two sources are merged
		// below, because afterwards the value no longer says where it came
		// from. Drives whether the view is logged - see log_view() call.
		$from_shortcode = ! empty( $atts['album'] ) || ! empty( $atts['track'] );

		// Explicit shortcode attributes (for hardcoding a specific release
		// inline in post content) take priority over the URL's query string
		// (used by the shareable /musiclink page).
		$album = $atts['album']
			? sanitize_text_field( $atts['album'] )
			: ( isset( $_GET['album'] ) ? sanitize_text_field( wp_unslash( $_GET['album'] ) ) : '' );
		$track = $atts['track']
			? sanitize_text_field( $atts['track'] )
			: ( isset( $_GET['track'] ) ? sanitize_text_field( wp_unslash( $_GET['track'] ) ) : '' );

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

		// Only URL-driven views are logged: a release hardcoded into a
		// shortcode attribute belongs to the post, not to a shared link, so
		// logging it would write a row on *every* render of that post -
		// page views, feeds, previews and crawler hits alike - and bury the
		// numbers the reports are meant to show.
		if ( ! $from_shortcode ) {
			$this->log_view( $artist, $album_title, $track_title );
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

		// --- v4vmusic.com: search by title, resolved from Music Index ---
		if ( $this->is_app_enabled( 'v4vmusic' ) ) {
			$v4vmusic_icon = $this->get_app_icon( 'v4vmusic', 'v4vmusic.com' );
			if ( $has_track && $track_data && ! empty( $track_data['title'] ) ) {
				$links['v4vmusic'] = array(
					'url'      => 'https://v4vmusic.com/search?q=' . urlencode( $track_data['title'] ) . '&type=songs',
					'label'    => 'v4vmusic.com',
					'icon_url' => $v4vmusic_icon,
				);
			} elseif ( ! $has_track && $feed_data && ! empty( $feed_data['title'] ) ) {
				$links['v4vmusic'] = array(
					'url'      => 'https://v4vmusic.com/search?q=' . urlencode( $feed_data['title'] ) . '&type=albums',
					'label'    => 'v4vmusic.com',
					'icon_url' => $v4vmusic_icon,
				);
			}
			// If title resolution fails, v4vmusic.com is silently omitted
			// rather than showing a search link with no meaningful query.
		}

		// --- Fountain: no reliable public link exists via guid or their
		// search page directly (both confirmed to 404 - see project notes).
		// Their ONLY resolution mechanism is an unofficial, undocumented
		// Next.js Server Action - fragile because its "next-action" build
		// fingerprint changes whenever Fountain redeploys. We call it here,
		// but cache successes for a long time and always fall back to the
		// plain fountain.fm link on any failure, so a stale/broken hash
		// never breaks the button - it just stops being a direct link
		// until the hash is refreshed (see FOUNTAIN_NEXT_ACTION below).
		if ( $this->is_app_enabled( 'fountain' ) ) {
			// Keep the search text and the guid we're matching against in
			// sync: if track-level data didn't resolve, fall back to the
			// ALBUM for both, rather than searching by album title while
			// still trying to match against the track guid (which would
			// never succeed).
			$fountain_has_track   = $has_track && $track_data;
			$fountain_search_text = $fountain_has_track ? $track_data['title'] : ( $feed_data ? $feed_data['title'] : '' );
			$fountain_target_guid = $fountain_has_track ? $track : $album;
			$fountain_hit         = $fountain_search_text ? $this->fountain_resolve( $fountain_target_guid, $fountain_search_text ) : null;

			$fountain_url = 'https://fountain.fm';
			if ( $fountain_hit ) {
				// The path prefix comes from Fountain's own _type on the
				// matched hit - see fountain_path_segment().
				$fountain_url = 'https://fountain.fm/'
					. $this->fountain_path_segment( $fountain_hit, $fountain_has_track )
					. '/'
					. rawurlencode( $fountain_hit['id'] );
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
		}

		// No configured value, or the configured one just failed (most
		// likely a stale "next-action" after a Fountain redeploy). Try to
		// self-heal: detect a fresh value in the background and retry once
		// with it. If that succeeds, persist it so future requests use it
		// directly without needing to re-detect every time.
		$detected = $this->fountain_autodetect_next_action();
		if ( $detected && $detected !== $next_action ) {
			$result = $this->fountain_try_resolve( $guid, $search_text, $detected );
			if ( $result ) {
				update_option( 'v4v_musiclink_pro_fountain_next_action', $detected );
				return $result;
			}
		}

		return null;
	}

	/**
	 * A single resolution attempt against Fountain's unofficial search
	 * endpoint using a specific next-action value. Cached per guid+value
	 * pair, so a stale cached failure never survives a next-action change.
	 */
	private function fountain_try_resolve( $guid, $search_text, $next_action ) {
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
		$response = wp_remote_post( 'https://fountain.fm/', array(
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

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, '', self::FAIL_CACHE_TTL );
			return null;
		}

		$hit = $this->extract_fountain_hit( wp_remote_retrieve_body( $response ), $guid );

		if ( ! $hit ) {
			set_transient( $cache_key, '', self::FAIL_CACHE_TTL );
			return null;
		}

		set_transient( $cache_key, $hit, 30 * DAY_IN_SECONDS );
		return $hit;
	}

	/**
	 * Best-effort background attempt to discover Fountain's current
	 * "next-action" Server Action ID by fetching the homepage, finding
	 * same-origin JS chunk URLs, and searching each for a 40-44 character
	 * hex string appearing near the word "search" (a plausibility filter -
	 * minifiers preserve string literals but mangle variable/function
	 * names). Returns the FIRST candidate found, short-circuiting rather
	 * than exhaustively scanning every chunk, since live testing found the
	 * first result tends to be the correct one.
	 *
	 * Throttled to at most once per 15 minutes (regardless of outcome) so
	 * a broken detection mechanism doesn't repeatedly hammer fountain.fm
	 * on every page view - a successful detection persists the new value
	 * via update_option() in fountain_resolve() above, so this path is
	 * only ever hit again if something's still wrong after that.
	 */
	private function fountain_autodetect_next_action() {
		$throttle_key = 'fountain_autodetect_throttle_pro';
		if ( get_transient( $throttle_key ) ) {
			return null;
		}
		set_transient( $throttle_key, true, 15 * MINUTE_IN_SECONDS );

		// redirection => 0: never let a remote host bounce an outbound
		// request elsewhere - WP's default 5 hops would follow an open
		// redirect to arbitrary (e.g. internal) addresses. SSRF hardening.
		$home = wp_remote_get( 'https://fountain.fm/', array(
			'timeout'     => 10,
			'redirection' => 0,
		) );
		if ( is_wp_error( $home ) || 200 !== (int) wp_remote_retrieve_response_code( $home ) ) {
			return null;
		}
		$html = wp_remote_retrieve_body( $home );

		preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/i', $html, $matches );
		$script_urls = array();
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
				$script_urls[] = $src;
			}
		}
		$script_urls = array_slice( array_unique( $script_urls ), 0, 20 );

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
					return $hex; // First candidate found - short-circuit.
				}
			}
		}

		return null;
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
	 */
	private function extract_fountain_hit( $raw_body, $target_guid ) {
		if ( ! preg_match( '/\d+:(\{"hits":.*)/s', (string) $raw_body, $matches ) ) {
			return null;
		}
		$decoded = json_decode( trim( $matches[1] ), true );
		if ( empty( $decoded['hits'] ) || ! is_array( $decoded['hits'] ) ) {
			return null;
		}
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
	 * behaviour rather than degrading to a bare fountain.fm link.
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
	 * GET /v1/feeds/{feedGuid} - album/feed-level metadata: title and artist.
	 * Cached via transient.
	 */
	private function mi_get_feed( $feed_guid ) {
		$cache_key = 'mi_feed_pro_v' . self::CACHE_SCHEMA_VERSION . '_' . md5( $feed_guid );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ! empty( $cached ) ? $cached : null;
		}

		$body = $this->mi_api_get( '/feeds/' . rawurlencode( $feed_guid ) );

		if ( empty( $body['data']['title'] ) ) {
			set_transient( $cache_key, array(), $this->negative_cache_ttl() );
			return null;
		}

		$result = array(
			'title'  => $body['data']['title'],
			'artist' => isset( $body['data']['release_artist'] ) ? $body['data']['release_artist'] : '',
			'image'  => isset( $body['data']['image_url'] ) ? $body['data']['image_url'] : '',
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * GET /v1/feeds/{feedGuid}/tracks/{trackGuid} - track-level metadata:
	 * track title, its parent album title, and artist. Cached via transient.
	 */
	private function mi_get_track( $feed_guid, $track_guid ) {
		$cache_key = 'mi_track_pro_v' . self::CACHE_SCHEMA_VERSION . '_' . md5( $feed_guid . '|' . $track_guid );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ! empty( $cached ) ? $cached : null;
		}

		$body = $this->mi_api_get( '/feeds/' . rawurlencode( $feed_guid ) . '/tracks/' . rawurlencode( $track_guid ) );

		if ( empty( $body['data']['title'] ) ) {
			set_transient( $cache_key, array(), $this->negative_cache_ttl() );
			return null;
		}

		$data   = $body['data'];
		$result = array(
			'title'       => $data['title'],
			'album_title' => isset( $data['feed_title'] ) ? $data['feed_title'] : '',
			'artist'      => ! empty( $data['track_artist'] ) ? $data['track_artist'] : ( isset( $data['release_artist'] ) ? $data['release_artist'] : '' ),
			'image'       => isset( $data['image_url'] ) ? $data['image_url'] : '',
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
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

		$current_url = home_url( add_query_arg( null, null ) );

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
			<?php else : ?>
				<ul class="v4v-musiclink-pro-list">
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
