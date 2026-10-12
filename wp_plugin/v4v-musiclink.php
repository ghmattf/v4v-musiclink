<?php
/**
 * Plugin Name: V4V Music Link Resolver
 * Description: Provides a [musiclink] shortcode (album/track attributes required) that renders Stablekraft, PodTunes.app, v4vmusic.com, and Fountain links for a V4V music album/track.
 * Version: 1.22.31
 * Author: Matt Finlay
 * Plugin URI: https://github.com/ghmattf/v4v-musiclink
 * Author URI: https://phafe.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: v4v-musiclink
 * Requires at least: 5.8
 * Tested up to: 7.1.3
 * Requires PHP: 7.4
 *
 * USAGE:
 * 1. Install and activate this plugin. No API credentials needed for
 *    Stablekraft, PodTunes.app, or Music Index (title/artwork lookups).
 *
 *    Fountain.fm deep links are OPTIONAL and admin-supplied: paste one
 *    onto the Cache tab for a release, or leave the button to fall back
 *    to a Fountain search for the release title. This version performs
 *    no Fountain discovery itself (no endpoint config, no background
 *    crawling) - the Pro version resolves and self-heals them.
 * 2. Use the shortcode inline in any post/page, with explicit attributes -
 *    this is the ONLY way to use it; there is no URL-parameter or search
 *    UI mode:
 *    [musiclink album="{album-guid}"]
 *    [musiclink album="{album-guid}" track="{track-guid}"]
 *    [musiclink album="{album-guid}" header="false"]
 *    A bare [musiclink] with no attributes, or one with any attribute
 *    name other than album/track/header, shows a "Missing parameter(s)"
 *    message with these same usage examples rather than guessing at intent.
 *
 * HEADER CONTROLS: whether the release header (title, artist, artwork)
 * appears above the buttons defaults to the "Release header" Show/Hide
 * drop-down under Settings > V4V MusicLink (default: Show). The optional
 * header="true" / header="false" shortcode attribute overrides that
 * setting per placement - useful when one page wants the bare button
 * list while the site default is the opposite. Unrecognized header
 * values fall back to the admin setting.
 *
 * NOTES ON EACH TARGET APP (see project reference doc for full details):
 * - Stablekraft: fully formulaic guid-based URL, no lookup required.
 * - PodTunes.app: fully formulaic guid-based URL, no lookup required.
 * - v4vmusic.com: fully formulaic guid-based URL (/lookup/{album}, or
 *   /lookup/{album}/{track} when a track guid is present), no lookup
 *   required - so the button renders even if Music Index titles were
 *   only partially resolved.
 * - Fountain: no reliable public guid-based or search-page link exists
 *   (both confirmed to 404 by direct testing). This version never
 *   resolves one: the button uses a link stored on the cache row (set
 *   by hand on the Cache tab) when there is one, and otherwise falls
 *   back to a Fountain search for the release (or fountain.fm/discover
 *   if no title is known). The Pro version resolves links itself via
 *   the unofficial Server Action endpoint, with background self-healing.
 *
 * PER-APP CONTROLS: under Settings > V4V MusicLink, each of the four
 * apps (Stablekraft, PodTunes.app, v4vmusic.com, Fountain) can be
 * individually disabled (hides its button entirely) and/or given a custom
 * icon URL overriding the default live favicon.
 * The same table's "Order" column (arrow buttons) sets the order in
 * which those buttons are listed on the front end.
 *
 * NOT FOUND HANDLING: if a guid doesn't match anything in Music Index,
 * the shortcode shows the same "Missing parameter(s)" style message
 * (with an "Album/Song not found" reason) rather than showing broken/
 * empty app buttons.
 *
 * CACHING: everything a render needs for one release lives on a single
 * cache-table row keyed on album/track guid - the Music Index metadata
 * (titles, artist, artwork) and the resolved Fountain.fm link. Stale
 * metadata keeps serving while a background refresh runs after the page
 * has been sent (plain PHP on the shutdown hook - no cron, no queue),
 * so a Music Index outage can't blank an already-seen release. When
 * that refresh is triggered is admin-configurable (Settings >
 * V4V MusicLink, "Music Index refresh", default 12 hours), and only
 * front-end page renders may trigger it - never search or logging.
 * Fountain.fm links are permanent: they never go stale and are never
 * auto-refreshed (blank a link on the Cache tab to drop the button back
 * to the search fallback; this version never re-resolves a link).
 * Failure throttles (not-found / outage) stay in short-lived
 * transients.
 *
 * Icons are pulled live from each app's own favicon via a favicon service
 * (no logo assets are stored or reproduced by this plugin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class V4V_Music_Link_Resolver {

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

	// Search pagination for the block-editor builder's Music Index search.
	// Music Index caps `limit` at 100 and pages the rest behind opaque
	// cursors, so a batch is at most SEARCH_BATCH_LIMIT rows and deeper
	// results are reached by chaining; SEARCH_MAX_BATCHES bounds how many
	// upstream calls one REST search request may trigger. Values mirror
	// Pro's constants exactly; the per-query transient key is namespaced
	// 'v' . SEARCH_CACHE_SCHEMA and lives only in this plugin's cache.
	const SEARCH_PER_PAGE     = 8;
	const SEARCH_BATCH_LIMIT  = 100;
	const SEARCH_MAX_BATCHES  = 3;
	const SEARCH_CACHE_SCHEMA = 2;
	const SEARCH_CACHE_TTL    = HOUR_IN_SECONDS;

	/**
	 * HTTP status of the most recent mi_api_get() call (0 = transport
	 * failure or an undecodable body). Lets mi_get_feed()/mi_get_track()
	 * distinguish a definitive 404 from an unreliable failure when they
	 * decide how long to remember a miss - see negative_cache_ttl().
	 */
	private $last_mi_status = 0;

	/**
	 * Whether a stale cache row may queue a background Music Index
	 * refresh in this request. Default-deny: only render() turns it on,
	 * so the Pro search box, the log beacons and the og: preview can
	 * never trigger a refresh (they may still fill an absent row).
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
		add_shortcode( 'musiclink', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_v4v_musiclink_update_cache_row', array( $this, 'handle_update_cache_row' ) );
		add_action( 'admin_post_v4v_musiclink_delete_cache_row', array( $this, 'handle_delete_cache_row' ) );
		add_action( 'admin_post_v4v_musiclink_clear_cache', array( $this, 'handle_clear_cache' ) );
		// Block-editor builder (O19): editor-only REST routes for the
		// settings-pane search/fountain field, and the block's inline JS.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		// Canvas content styles for the block preview: enqueue_block_assets
		// is the only hook whose CSS reaches the iframed editor canvas
		// (WP 6.3+; the post editor iframes from WP 7.1 / Gutenberg 23.6) -
		// see enqueue_block_editor_styles().
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_editor_styles' ) );
		// Background Music Index refreshes, after the page has gone out
		// (priority 10 lets wp_ob_end_flush_all at priority 1 flush first).
		add_action( 'shutdown', array( $this, 'run_queued_mi_refresh' ) );

		$this->maybe_create_cache_table();
	}

	public function register_settings_page() {
		add_options_page(
			__( 'V4V MusicLink', 'v4v-musiclink' ),
			__( 'V4V MusicLink', 'v4v-musiclink' ),
			'manage_options',
			'v4v-musiclink',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'v4v_musiclink', 'v4v_musiclink_show_header', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'v4v_musiclink', 'v4v_musiclink_random_app_order', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
		register_setting( 'v4v_musiclink', 'v4v_musiclink_show_credits', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
		register_setting( 'v4v_musiclink', 'v4v_musiclink_apps', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_apps_settings' ),
			'default'           => array(),
		) );
		register_setting( 'v4v_musiclink', 'v4v_musiclink_mi_refresh_hours', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_mi_refresh_hours' ),
			'default'           => self::MI_REFRESH_DEFAULT_HOURS,
		) );
	}

	/**
	 * Admin "Music Index refresh" hours: absint, then 0/empty/non-numeric
	 * fall back to the default (12), then clamp to 1..720 (1 hour ..
	 * 30 days).
	 */
	public function sanitize_mi_refresh_hours( $value ) {
		$hours = absint( $value );
		if ( ! $hours ) {
			$hours = self::MI_REFRESH_DEFAULT_HOURS;
		}

		return min( max( $hours, 1 ), 720 );
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

	private function get_app_config( $slug ) {
		$all      = get_option( 'v4v_musiclink_apps', array() );
		$defaults = array( 'enabled' => true, 'icon_url' => '', 'icon_bg' => '' );
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

	/**
	 * The per-app icon background colour: a validated CSS hex colour
	 * (#abc / #aabbcc) or '' for transparent (no background). The rule
	 * mirrors core's sanitize_hex_color(); it runs on the render side
	 * too, so a value that bypassed the settings sanitizer (hand-edited
	 * option) can never reach the page as anything but a hex colour.
	 */
	private function app_icon_bg( $slug ) {
		$config = $this->get_app_config( $slug );
		$color  = isset( $config['icon_bg'] ) ? (string) $config['icon_bg'] : '';
		return preg_match( '/^#([0-9a-fA-F]{3}){1,2}$/', $color ) ? strtolower( $color ) : '';
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
			// "None" ticked wins over whatever the colour input holds,
			// so an unchecked-then-reticked swatch cannot resurrect a
			// colour the admin has explicitly cleared.
			$icon_bg = '';
			if ( empty( $row['icon_bg_none'] ) && isset( $row['icon_bg'] ) ) {
				$color   = sanitize_hex_color( $row['icon_bg'] );
				$icon_bg = $color ? $color : '';
			}
			$clean[ $slug ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'icon_url' => ! empty( $row['icon_url'] ) ? esc_url_raw( trim( $row['icon_url'] ) ) : '',
				'icon_bg'  => $icon_bg,
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
		$all      = get_option( 'v4v_musiclink_apps', array() );
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
		if ( ! in_array( $active_tab, array( 'settings', 'cache' ), true ) ) {
			$active_tab = 'settings';
		}
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'V4V MusicLink Settings', 'v4v-musiclink' ); ?>
				<span class="description">
					<?php
					printf(
						/* translators: %s: this plugin's version number. */
						esc_html__( 'Version %s', 'v4v-musiclink' ),
						esc_html( $this->plugin_version() )
					);
					?>
				</span>
			</h1>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=v4v-musiclink&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'v4v-musiclink' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=v4v-musiclink&tab=cache' ) ); ?>" class="nav-tab <?php echo 'cache' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Cache', 'v4v-musiclink' ); ?>
				</a>
			</h2>

			<?php if ( 'cache' === $active_tab ) : ?>
				<?php $this->render_cache_tab_content(); ?>
			<?php else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'v4v_musiclink' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Release header', 'v4v-musiclink' ); ?>
						</th>
						<td>
							<?php $show_header_value = get_option( 'v4v_musiclink_show_header', true ) ? '1' : '0'; ?>
							<select id="v4v_musiclink_show_header" name="v4v_musiclink_show_header">
								<option value="1" <?php selected( $show_header_value, '1' ); ?>><?php esc_html_e( 'Show', 'v4v-musiclink' ); ?></option>
								<option value="0" <?php selected( $show_header_value, '0' ); ?>><?php esc_html_e( 'Hide', 'v4v-musiclink' ); ?></option>
							</select>
							<label for="v4v_musiclink_show_header"><?php esc_html_e( ' the release title, artist and artwork above the music app buttons', 'v4v-musiclink' ); ?></label>
							<p class="description">
								<?php esc_html_e( 'This is the site-wide default for every [musiclink] shortcode. It can be overridden per placement with the optional header attribute: header="false" hides the header for that instance, header="true" shows it, regardless of this setting.', 'v4v-musiclink' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Music apps', 'v4v-musiclink' ); ?></th>
						<td>
						<table class="widefat" style="max-width:900px;">
							<thead>
								<tr>
									<th style="width:100px;"><?php esc_html_e( 'Order', 'v4v-musiclink' ); ?></th>
									<th style="width:60px;"><?php esc_html_e( 'Active', 'v4v-musiclink' ); ?></th>
									<th><?php esc_html_e( 'App', 'v4v-musiclink' ); ?></th>
									<th style="width:60px;"><?php esc_html_e( 'Icon', 'v4v-musiclink' ); ?></th>
									<th><?php esc_html_e( 'Icon override URL', 'v4v-musiclink' ); ?></th>
									<th style="width:120px;"><?php esc_html_e( 'Background', 'v4v-musiclink' ); ?></th>
								</tr>
							</thead>
								<tbody>
									<?php $position = 0; ?>
									<?php foreach ( $this->ordered_app_registry() as $slug => $app ) : ?>
										<?php
										$config        = $this->get_app_config( $slug );
										$preview        = $this->get_app_icon( $slug, $app['domain'] );
										$default_icon   = $this->favicon_url( $app['domain'] );
										$icon_bg        = $this->app_icon_bg( $slug );
										$field_name     = 'v4v_musiclink_apps[' . $slug . ']';
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
												<button type="button" class="button button-small v4v-app-move-up" title="<?php echo esc_attr( sprintf( __( 'Move %s up', 'v4v-musiclink' ), $app['label'] ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s up', 'v4v-musiclink' ), $app['label'] ) ); ?>">&#8593;</button>
												<button type="button" class="button button-small v4v-app-move-down" title="<?php echo esc_attr( sprintf( __( 'Move %s down', 'v4v-musiclink' ), $app['label'] ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s down', 'v4v-musiclink' ), $app['label'] ) ); ?>">&#8595;</button>
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
												style="display:block; width:32px; height:32px; border-radius:4px;<?php echo '' !== $icon_bg ? ' background-color:' . esc_attr( $icon_bg ) . ';' : ''; ?>"
											/>
										</td>
											<td>
												<input
													type="url"
													class="v4v-app-icon-input regular-text"
													name="<?php echo esc_attr( $field_name ); ?>[icon_url]"
													value="<?php echo esc_attr( $config['icon_url'] ); ?>"
													placeholder="<?php esc_attr_e( 'Leave blank to use the default favicon', 'v4v-musiclink' ); ?>"
													data-default-icon="<?php echo esc_url( $default_icon ); ?>"
												/>
												<button type="button" class="button v4v-app-icon-set"><?php esc_html_e( 'Set', 'v4v-musiclink' ); ?></button>
												<span class="v4v-app-icon-warning" style="color:#a00; display:none;"><?php esc_html_e( 'Image could not be loaded from that URL.', 'v4v-musiclink' ); ?></span>
											</td>
											<td>
												<label style="display:block; margin-bottom:4px;">
													<input
														type="checkbox"
														class="v4v-app-bg-none"
														name="<?php echo esc_attr( $field_name ); ?>[icon_bg_none]"
														value="1"
														<?php checked( '' === $icon_bg ); ?>
													/>
													<?php esc_html_e( 'None (transparent)', 'v4v-musiclink' ); ?>
												</label>
												<input
													type="color"
													class="v4v-app-bg-color"
													name="<?php echo esc_attr( $field_name ); ?>[icon_bg]"
													value="<?php echo esc_attr( '' !== $icon_bg ? $icon_bg : '#ffffff' ); ?>"
													title="<?php esc_attr_e( 'Icon background colour', 'v4v-musiclink' ); ?>"
												/>
											</td>
										</tr>
									<?php $position++; ?>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description">
								<?php esc_html_e( 'Unchecking "Active" hides that app\'s button entirely wherever the [musiclink] shortcode is used. Use the arrows in the "Order" column to set the order the buttons appear in on the front end, then click "Save Changes" below to apply it. Click "Set" to preview an icon override before saving - the preview updates immediately, but nothing is saved until you click "Save Changes" below. In the "Background" column, pick a colour to sit behind the icon (useful for transparent PNGs) or tick "None (transparent)" to keep today\'s look - the 32px preview updates immediately; a saved colour is applied to the icon itself on the front end.', 'v4v-musiclink' ); ?>
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
							(function () {
								function v4vUpdateAppBg( row ) {
									if ( ! row ) {
										return;
									}
									var none    = row.querySelector( '.v4v-app-bg-none' );
									var color   = row.querySelector( '.v4v-app-bg-color' );
									var preview = row.querySelector( '.v4v-app-icon-preview' );
									if ( ! none || ! color || ! preview ) {
										return;
									}
									preview.style.backgroundColor = none.checked ? '' : color.value;
								}

								document.querySelectorAll( '.v4v-app-bg-none, .v4v-app-bg-color' ).forEach( function ( input ) {
									input.addEventListener( 'change', function () { v4vUpdateAppBg( input.closest( '.v4v-app-row' ) ); } );
									input.addEventListener( 'input', function () { v4vUpdateAppBg( input.closest( '.v4v-app-row' ) ); } );
								} );
							})();
							</script>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_random_app_order"><?php esc_html_e( 'Random order', 'v4v-musiclink' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="v4v_musiclink_random_app_order" value="0" />
							<input
								type="checkbox"
								id="v4v_musiclink_random_app_order"
								name="v4v_musiclink_random_app_order"
								value="1"
								<?php checked( get_option( 'v4v_musiclink_random_app_order', false ) ); ?>
							/>
							<label for="v4v_musiclink_random_app_order">
								<?php esc_html_e( 'Shuffle the music app buttons into a new random order on every page load', 'v4v-musiclink' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The "Order" column above is never changed by this: it stays the fixed order shown in the table, and is used whenever this box is unticked. When ticked, every load of a page containing the shortcode - including refreshes, and pages served from a cache - shows the buttons in a fresh random order. Without JavaScript the configured order is shown. The same randomisation applies to every visitor.', 'v4v-musiclink' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_mi_refresh_hours"><?php esc_html_e( 'Music Index refresh', 'v4v-musiclink' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								id="v4v_musiclink_mi_refresh_hours"
								name="v4v_musiclink_mi_refresh_hours"
								value="<?php echo esc_attr( get_option( 'v4v_musiclink_mi_refresh_hours', self::MI_REFRESH_DEFAULT_HOURS ) ); ?>"
								min="1"
								max="720"
								step="1"
								class="small-text"
							/>
							<?php esc_html_e( 'hours', 'v4v-musiclink' ); ?>
							<p class="description">
								<?php esc_html_e( 'When a background refresh of cached Music Index metadata (titles, artist, artwork) is triggered. Cached data keeps serving at any age and is never cleared by this value - lower means fresher metadata, higher means fewer Music Index requests. Default: 12 hours. This does not affect failure throttles, stored Fountain.fm links, or the release header.', 'v4v-musiclink' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="v4v_musiclink_show_credits"><?php esc_html_e( 'Credit links', 'v4v-musiclink' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="v4v_musiclink_show_credits" value="0" />
							<input
								type="checkbox"
								id="v4v_musiclink_show_credits"
								name="v4v_musiclink_show_credits"
								value="1"
								<?php checked( get_option( 'v4v_musiclink_show_credits', false ) ); ?>
							/>
							<label for="v4v_musiclink_show_credits">
								<?php esc_html_e( 'Show a credit line under the buttons linking to V4V MusicLink (phafe.com) and MusicIndex.org', 'v4v-musiclink' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. When off, nothing linking back to this plugin\'s site or MusicIndex.org is printed on your public pages - tick this box only if you are happy to display those two credit links to your visitors.', 'v4v-musiclink' ); ?>
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
	 * Refreshes are queued only from render() (the Pro search box, the log
	 * beacons and the og: preview are gated off) and run after the response
	 * is flushed, on the shutdown hook - no cron, no queue option.
	 * --------------------------------------------------------------------- */

	/**
	 * Creates the cache table if it doesn't exist yet. Checked on every
	 * load behind a cheap option flag rather than relying solely on
	 * register_activation_hook, since updates to this plugin are typically
	 * deployed by overwriting the file in place without a
	 * deactivate/reactivate cycle - which means a normal activation hook
	 * would never fire for this change.
	 *
	 * The migrations that used to run here first (the v1 stored-links
	 * fixup, then the copy of the old table into this one) have been
	 * removed: only an installation predating 1.22.17 could have needed
	 * them, and because every step was gated on columns/tables actually
	 * being present, a fresh install never ran them either.
	 *
	 * Each step is individually re-runnable, so a run interrupted half-way
	 * resumes on the next load instead of failing.
	 */
	private function maybe_create_cache_table() {
		if ( self::CACHE_TABLE_VERSION === (int) get_option( 'v4v_musiclink_cache_table_version' ) ) {
			return;
		}

		self::create_cache_table();

		update_option( 'v4v_musiclink_cache_table_version', self::CACHE_TABLE_VERSION );
	}

	private static function create_cache_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'v4v_musiclink_cache';
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

		return $wpdb->prefix . 'v4v_musiclink_cache';
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
	 * there is no row OR the row's link was blanked (blanking drops the
	 * button back to the search fallback).
	 * Returning '' for both cases means the caller only ever needs one
	 * branch: '' -> search.
	 */
	private function get_cached_fountain_url( $album, $track ) {
		$row = $this->cache_get_row( $album, $track );

		return ( $row && is_string( $row->fountain_url ) ) ? $row->fountain_url : '';
	}

	/**
	 * Whether an admin-supplied stored link is acceptable: always https,
	 * and on the app's own domain when we know it (fountain.fm for
	 * Fountain, including subdomains - the domain Fountain's own pages
	 * are served from). An app with no registry domain (unknown slug or
	 * a future entry missing its `domain` key) fails closed: with no
	 * known domain there is no host to positively match, so rejecting
	 * outright is the only safe answer (security review 2026-10-06).
	 */
	private function is_valid_stored_url( $url, $app ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$registry = $this->app_registry();
		if ( ! isset( $registry[ $app ]['domain'] ) ) {
			return false;
		}

		$host   = strtolower( $parts['host'] );
		$domain = strtolower( $registry[ $app ]['domain'] );

		return $host === $domain || '.' . $domain === substr( $host, -1 * ( strlen( $domain ) + 1 ) );
	}

	/**
	 * Display label for an app slug, falling back to the slug itself so a
	 * row written by a future app (or one whose slug isn't in the registry
	 * any more) still renders something readable.
	 */
	private function app_display_label( $slug ) {
		$registry = $this->app_registry();

		return isset( $registry[ $slug ]['label'] ) ? $registry[ $slug ]['label'] : $slug;
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
			'artist'       => __( 'Artist', 'v4v-musiclink' ),
			'album_title'  => __( 'Album title', 'v4v-musiclink' ),
			'track_title'  => __( 'Track title', 'v4v-musiclink' ),
			'album_guid'   => __( 'Album GUID', 'v4v-musiclink' ),
			'track_guid'   => __( 'Track GUID', 'v4v-musiclink' ),
			// One key per app link column (LINK_COLUMNS), labelled for
			// the app that owns one today.
			'fountain_url' => __( 'Fountain.fm link', 'v4v-musiclink' ),
		);
	}

	/**
	 * URL of the Cache tab, carrying any active filters so pagination
	 * keeps the admin's current view.
	 */
	private function cache_admin_url( $extra = array() ) {
		$args = array(
			'page' => 'v4v-musiclink',
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
		if ( ! $base || false === strpos( $base, 'page=v4v-musiclink' ) ) {
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

		$plain_tab_url = admin_url( 'options-general.php?page=v4v-musiclink&tab=cache' );
		?>
		<?php if ( isset( $_GET['sl_links_cleared'], $_GET['sl_cleared_app'] ) ) : ?>
			<?php // Per-app clear: the link column was blanked, the rows stay. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php printf(
					esc_html__( '%1$s link cleared on %2$s row(s).', 'v4v-musiclink' ),
					esc_html( $this->app_display_label( sanitize_text_field( wp_unslash( $_GET['sl_cleared_app'] ) ) ) ),
					esc_html( sanitize_text_field( wp_unslash( $_GET['sl_links_cleared'] ) ) )
				); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_cleared'] ) ) : ?>
			<?php // Clear all: the rows themselves are gone. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php printf( esc_html__( '%s cache row(s) removed.', 'v4v-musiclink' ), esc_html( sanitize_text_field( wp_unslash( $_GET['sl_cleared'] ) ) ) ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Fountain.fm link updated.', 'v4v-musiclink' ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_art_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Artwork URL updated.', 'v4v-musiclink' ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Cache row removed. It will be created again if the release is viewed again.', 'v4v-musiclink' ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'That link was not saved: it must be an https URL on this app\'s own domain.', 'v4v-musiclink' ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['sl_art_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'That artwork URL was not saved: it must be an http(s) URL, or blank to remove the artwork.', 'v4v-musiclink' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'One row per release: cached Music Index metadata (titles, artist, artwork) plus the resolved Fountain.fm link. Artwork and the Fountain.fm link are edited here per row; titles and artist refresh in the background on the interval set under Settings ("Music Index refresh") while stale rows keep serving.', 'v4v-musiclink' ); ?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin:1em 0;">
			<input type="hidden" name="page" value="v4v-musiclink" />
			<input type="hidden" name="tab" value="cache" />
			<?php foreach ( $filter_fields as $field => $label ) : ?>
				<label style="flex:1 1 130px; min-width:110px;">
					<span style="display:block;"><?php echo esc_html( $label ); ?></span>
					<input type="text" name="sl_<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $filters[ $field ] ); ?>" style="width:100%;" />
				</label>
			<?php endforeach; ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'v4v-musiclink' ); ?></button>
			<a class="button" href="<?php echo esc_url( $plain_tab_url ); ?>"><?php esc_html_e( 'Clear filters', 'v4v-musiclink' ); ?></a>
		</form>

		<?php if ( $total > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 1em;" onsubmit="var label = this.scope.options[ this.scope.selectedIndex ].text; return confirm( 'all' === this.scope.value ? '<?php echo esc_js( __( 'Clear all cached rows? Every row is removed - hand-edited links included - and each release re-resolves when it is viewed again.', 'v4v-musiclink' ) ); ?>' : label + ' <?php echo esc_js( __( 'The rows stay, but those links are blanked and re-resolve when their releases are viewed again.', 'v4v-musiclink' ) ); ?>' );">
				<input type="hidden" name="action" value="v4v_musiclink_clear_cache" />
				<?php wp_nonce_field( 'v4v_musiclink_clear_cache' ); ?>
				<label for="v4v-sl-clear-scope"><?php esc_html_e( 'Clear cache:', 'v4v-musiclink' ); ?></label>
				<select name="scope" id="v4v-sl-clear-scope">
					<option value="all"><?php esc_html_e( 'Clear all cached rows', 'v4v-musiclink' ); ?></option>
					<?php foreach ( $link_apps as $app_slug ) : ?>
						<option value="<?php echo esc_attr( $app_slug ); ?>"><?php printf( esc_html__( 'Clear %s links', 'v4v-musiclink' ), esc_html( $this->app_display_label( $app_slug ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Clear', 'v4v-musiclink' ); ?></button>
			</form>
		<?php endif; ?>

		<p>
			<?php printf( esc_html__( '%s cached row(s).', 'v4v-musiclink' ), esc_html( number_format_i18n( $total ) ) ); ?>
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
					<th><?php esc_html_e( 'Metadata Updated', 'v4v-musiclink' ); ?></th>
					<th><?php esc_html_e( 'Artist', 'v4v-musiclink' ); ?></th>
					<th><?php esc_html_e( 'Album title', 'v4v-musiclink' ); ?></th>
					<th><?php esc_html_e( 'Track title', 'v4v-musiclink' ); ?></th>
					<th><?php esc_html_e( 'Album GUID', 'v4v-musiclink' ); ?></th>
					<th><?php esc_html_e( 'Track GUID', 'v4v-musiclink' ); ?></th>
					<th><?php esc_html_e( 'Art', 'v4v-musiclink' ); ?></th>
					<th><?php esc_html_e( 'Fountain.fm link', 'v4v-musiclink' ); ?></th>
					<th><?php esc_html_e( 'Fountain updated', 'v4v-musiclink' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'v4v-musiclink' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No cached rows match.', 'v4v-musiclink' ); ?></td></tr>
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
									title="<?php echo esc_attr( $row->image_url ? __( 'Edit artwork URL', 'v4v-musiclink' ) : __( 'Add an artwork URL', 'v4v-musiclink' ) ); ?>">
									<?php if ( $row->image_url ) : ?>
										<span style="color:#00a32a; font-size:16px; line-height:1;" aria-hidden="true">&#10003;</span>
										<span class="screen-reader-text"><?php esc_html_e( 'Artwork URL stored - edit it', 'v4v-musiclink' ); ?></span>
									<?php else : ?>
										<span style="color:#d63638; font-size:16px; line-height:1;" aria-hidden="true">&#10007;</span>
										<span class="screen-reader-text"><?php esc_html_e( 'No artwork URL stored - add one', 'v4v-musiclink' ); ?></span>
									<?php endif; ?>
								</button>
							</td>
							<td style="white-space:nowrap;">
								<button type="button" class="button button-small v4v-sl-edit-toggle" data-id="<?php echo (int) $row->id; ?>"><?php esc_html_e( 'Edit', 'v4v-musiclink' ); ?></button>
								<?php if ( $row->fountain_url ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $row->fountain_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Launch', 'v4v-musiclink' ); ?></a>
								<?php else : ?>
									<span style="color:#d63638; font-size:16px; line-height:1;" aria-hidden="true">&#10007;</span>
									<span class="screen-reader-text"><?php esc_html_e( 'No Fountain.fm link stored', 'v4v-musiclink' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( substr( (string) $row->fountain_updated_at, 0, 10 ) ); ?></td>
							<td style="white-space:nowrap;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="v4v_musiclink_delete_cache_row" />
									<input type="hidden" name="link_id" value="<?php echo (int) $row->id; ?>" />
									<?php wp_nonce_field( 'v4v_musiclink_delete_cache_row' ); ?>
									<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Remove this cached row? The release re-resolves the next time it is viewed.', 'v4v-musiclink' ) ); ?>');"><?php esc_html_e( 'Remove', 'v4v-musiclink' ); ?></button>
								</form>
							</td>
						</tr>
						<tr id="v4v-sl-edit-<?php echo (int) $row->id; ?>" hidden>
							<td colspan="10">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:0.5em 0;">
									<input type="hidden" name="action" value="v4v_musiclink_update_cache_row" />
									<input type="hidden" name="link_id" value="<?php echo (int) $row->id; ?>" />
									<?php // Which column is being edited; whitelisted against CACHE_EDIT_COLUMNS. ?>
									<input type="hidden" name="link_field" value="fountain" />
									<?php wp_nonce_field( 'v4v_musiclink_update_cache_row' ); ?>
									<label for="v4v-sl-url-<?php echo (int) $row->id; ?>"><?php esc_html_e( 'Fountain.fm link', 'v4v-musiclink' ); ?></label>
									<input type="url" class="regular-text code" id="v4v-sl-url-<?php echo (int) $row->id; ?>" name="url" value="<?php echo esc_attr( $row->fountain_url ); ?>" style="flex:1 1 320px;" />
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'v4v-musiclink' ); ?></button>
									<span class="description"><?php esc_html_e( 'Leave blank to clear it: the next page view re-runs the Fountain search and stores the result again. Titles and artist are read-only - the next refresh overwrites them.', 'v4v-musiclink' ); ?></span>
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
					<input type="hidden" name="action" value="v4v_musiclink_update_cache_row" />
					<input type="hidden" name="link_id" id="v4v-sl-art-id" value="" />
					<input type="hidden" name="link_field" value="art" />
					<?php wp_nonce_field( 'v4v_musiclink_update_cache_row' ); ?>
					<label for="v4v-sl-art-url"><?php esc_html_e( 'Artwork URL', 'v4v-musiclink' ); ?></label>
					<input type="url" class="regular-text code" id="v4v-sl-art-url" name="url" value="" style="width:100%; box-sizing:border-box;" />
					<p class="description"><?php esc_html_e( 'Leave blank to remove the artwork. The next Music Index refresh fills it in again unless the row is still within its refresh interval.', 'v4v-musiclink' ); ?></p>
					<p style="margin-bottom:0; display:flex; gap:8px;">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Update', 'v4v-musiclink' ); ?></button>
						<button type="button" class="button v4v-sl-art-close"><?php esc_html_e( 'Cancel', 'v4v-musiclink' ); ?></button>
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
	 * Fountain link (or a blank, to drop back to the search fallback)
	 * must be an https URL on the app's own domain; the artwork URL only
	 * has to be http(s), or blank to drop the art. Each edit stamps its
	 * own half's timestamp - fountain_updated_at for the link,
	 * mi_updated_at for artwork, which also defers the next background
	 * pull - and leaves the other half alone.
	 */
	public function handle_update_cache_row() {
		check_admin_referer( 'v4v_musiclink_update_cache_row' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit cache rows.', 'v4v-musiclink' ) );
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
		check_admin_referer( 'v4v_musiclink_delete_cache_row' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to remove cache rows.', 'v4v-musiclink' ) );
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
	 * privilege, so it also works on restricted WordPress database users.
	 */
	public function handle_clear_cache() {
		check_admin_referer( 'v4v_musiclink_clear_cache' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache.', 'v4v-musiclink' ) );
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

	public function enqueue_assets() {
		wp_register_style( 'v4v-musiclink', false );
		wp_enqueue_style( 'v4v-musiclink' );
		wp_add_inline_style( 'v4v-musiclink', $this->inline_css() );

		wp_register_script( 'v4v-musiclink', false, array(), '1.22.31', true );
		wp_enqueue_script( 'v4v-musiclink' );
		wp_add_inline_script( 'v4v-musiclink', $this->inline_js() );
	}

	/**
	 * Block-editor JS for the shortcode builder (O19): a src-less handle
	 * carrying the block-editor dependencies, then the whole block as one
	 * inline script. Deliberately src-false: the project ships no .js
	 * files (single-file plugin convention) and there is no URL to
	 * cache-bust, so the version argument stays false instead of a literal
	 * that would drift from the plugin version. Admin editors only - the
	 * block talks to the manage_options REST routes, so anyone else gets
	 * nothing registered. Scripts belong on this hook (they run in the
	 * editor's outer document, where block registration happens);
	 * styles deliberately do NOT - see enqueue_block_editor_styles().
	 */
	public function enqueue_block_editor_assets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_register_script(
			'v4v-musiclink-block',
			false,
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-api-fetch' ),
			false,
			true
		);
		wp_enqueue_script( 'v4v-musiclink-block' );
		wp_add_inline_script( 'v4v-musiclink-block', $this->block_js() );
	}

	/**
	 * Canvas content styles for the block preview: the plugin's own
	 * front-end CSS plus the editor-only preview rules (inert anchors,
	 * scaled artwork, trimmed footer/modal), attached to a src-false
	 * `v4v-musiclink-editor` handle.
	 *
	 * Delivered through enqueue_block_assets - the documented channel for
	 * editor CONTENT styles since WP 6.3 - because the post/site editor
	 * canvas is an iframe (site editor always; post editor always from WP
	 * 7.1 / Gutenberg 23.6): styles printed from enqueue_block_editor_assets
	 * land in the outer admin document only and never reach the iframe,
	 * which rendered the preview unstyled (artwork at natural size,
	 * spilling out of the page). The two guards keep this CSS to the
	 * canvas surface alone: the iframe-asset capture (core brackets its
	 * dispatch of this hook with should_load_block_editor_scripts_and_styles
	 * forced to false) or an actual block-editor screen - never the front
	 * end (where enqueue_assets() already prints inline_css()) and never
	 * unrelated admin pages. Same admin-only stance as the script hook.
	 */
	public function enqueue_block_editor_styles() {
		$captured = (bool) has_filter( 'should_load_block_editor_scripts_and_styles', '__return_false' );
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$in_editor = $screen && is_object( $screen )
			&& is_callable( array( $screen, 'is_block_editor' ) )
			&& $screen->is_block_editor();

		if ( ! $captured && ! $in_editor ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_register_style( 'v4v-musiclink-editor', false );
		wp_enqueue_style( 'v4v-musiclink-editor' );
		wp_add_inline_style( 'v4v-musiclink-editor', $this->inline_css() . $this->block_editor_css() );
	}

	/**
	 * The builder block itself, inlined as source (see
	 * enqueue_block_editor_assets()). Plain wp.element.createElement - no
	 * JSX and no build step - and no template literals, so the nowdoc
	 * stays both build-free and interpolation-free.
	 *
	 * Attributes mirror the shortcode's whitelist exactly (album, track,
	 * header): save() emits the raw [musiclink ...] string as the block's
	 * content, so the front end runs the same single shortcode path it
	 * always has. The Fountain link is NOT an attribute - it reads and
	 * writes the cache row through the REST routes, which is why there is
	 * no fountain= anywhere below (O18 stays independent).
	 */
	private function block_js() {
		return <<<'V4VML'
( function ( blocks, element, blockEditor, components, i18n, apiFetch ) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var RawHTML = element.RawHTML;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var Button = components.Button;
	var Spinner = components.Spinner;
	var Notice = components.Notice;

	var NAMESPACE = '/v4v-musiclink/v1';

	// Strip quotes and HTML-significant characters so a hand-typed guid
	// can never break out of the attribute quotes in the saved string;
	// the server re-checks via guard_guid()/sanitize_text_field anyway.
	function clean_value( value ) {
		return String( value === null || value === undefined ? '' : value )
			.replace( /["'<>&]/g, '' )
			.trim();
	}

	function build_shortcode( attributes ) {
		var album = clean_value( attributes.album );
		var track = clean_value( attributes.track );
		var header = attributes.header || 'site';
		if ( ! album ) {
			return '';
		}
		var parts = [ 'album="' + album + '"' ];
		if ( track ) {
			parts.push( 'track="' + track + '"' );
		}
		// "Site setting" OMITS the attribute entirely - render()'s
		// absent/unrecognized fallback is the admin Release header
		// setting, so no sentinel value is ever emitted.
		if ( 'true' === header || 'false' === header ) {
			parts.push( 'header="' + header + '"' );
		}
		return '[musiclink ' + parts.join( ' ' ) + ']';
	}

	function error_message( err ) {
		return ( err && err.message ) ? err.message : __( 'Request failed.', 'v4v-musiclink' );
	}

	// On-demand search (O23): collapsed, the panel is a single [Search]
	// button; opening it reveals the query field and results area, and
	// picking a hit fills the GUID attributes and collapses the area
	// again. Explicit submit (inner button or Enter) instead of
	// type-ahead, so the REST search only runs when asked; Escape or
	// Close resets the area without touching the saved attributes (the
	// Release GUIDs panel remains the manual view). A generation counter
	// drops in-flight responses once the area closes or a newer search
	// starts. All hooks run unconditionally before the collapsed/open
	// branch, per the Rules of Hooks.
	function SearchPanel( props ) {
		var openState = useState( false );
		var open = openState[0];
		var setOpen = openState[1];
		var queryState = useState( '' );
		var query = queryState[0];
		var setQuery = queryState[1];
		var resultsState = useState( [] );
		var results = resultsState[0];
		var setResults = resultsState[1];
		var pageState = useState( 1 );
		var page = pageState[0];
		var setPage = pageState[1];
		var moreState = useState( false );
		var has_more = moreState[0];
		var setHasMore = moreState[1];
		var loadingState = useState( false );
		var loading = loadingState[0];
		var setLoading = loadingState[1];
		var errorState = useState( '' );
		var error = errorState[0];
		var setError = errorState[1];
		var seq = useRef( 0 );
		var inputRef = useRef( null );

		function reset() {
			seq.current++;
			setQuery( '' );
			setResults( [] );
			setHasMore( false );
			setPage( 1 );
			setLoading( false );
			setError( '' );
		}

		function close() {
			reset();
			setOpen( false );
		}

		// Focus the query field as soon as the area opens, so Enter can
		// submit without reaching for the mouse.
		useEffect( function () {
			if ( open && inputRef.current ) {
				inputRef.current.focus();
			}
		}, [ open ] );

		function do_search( q, p, append ) {
			var my_seq = ++seq.current;
			setLoading( true );
			setError( '' );
			apiFetch( {
				path: NAMESPACE + '/search?q=' + encodeURIComponent( q ) + '&page=' + p
			} ).then( function ( data ) {
				if ( my_seq !== seq.current ) {
					return;
				}
				setResults( append
					? function ( prev ) { return prev.concat( data.results ); }
					: data.results );
				setHasMore( !! data.has_more );
				setPage( p );
				setLoading( false );
			} ).catch( function ( err ) {
				if ( my_seq !== seq.current ) {
					return;
				}
				setLoading( false );
				setError( error_message( err ) );
			} );
		}

		function submit() {
			var q = query.trim();
			if ( ! q || loading ) {
				return;
			}
			do_search( q, 1, false );
		}

		function pick( hit ) {
			props.setAttributes( {
				album: hit.album_guid,
				track: hit.track_guid || ''
			} );
			close();
		}

		if ( ! open ) {
			return el( Button, { className: 'v4v-ml-search-open', onClick: function () { setOpen( true ); } },
				__( 'Search', 'v4v-musiclink' )
			);
		}

		return el( Fragment, null,
			el( 'div', { className: 'v4v-ml-searchbar' },
				el( 'input', {
					type: 'text',
					className: 'components-text-control__input',
					'aria-label': __( 'Search by title', 'v4v-musiclink' ),
					placeholder: __( 'Song or album title...', 'v4v-musiclink' ),
					value: query,
					ref: inputRef,
					onChange: function ( e ) { setQuery( e.target.value ); },
					onKeyDown: function ( e ) {
						if ( 'Enter' === e.key ) {
							e.preventDefault();
							submit();
						} else if ( 'Escape' === e.key ) {
							e.preventDefault();
							close();
						}
					}
				} ),
				el( Button, { className: 'v4v-ml-search-submit', onClick: submit, disabled: loading },
					__( 'Search', 'v4v-musiclink' )
				),
				el( Button, { className: 'v4v-ml-search-cancel', onClick: close },
					__( 'Close', 'v4v-musiclink' )
				)
			),
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			loading ? el( Spinner ) : null,
			el( 'ul', { className: 'v4v-ml-results' },
				results.map( function ( hit ) {
					return el( 'li', { key: hit.album_guid + '|' + ( hit.track_guid || '' ) },
						el( Button, { className: 'v4v-ml-hit', onClick: function () { pick( hit ); } },
							el( 'strong', null, hit.title ),
							hit.subtitle ? el( 'span', { className: 'v4v-ml-sub' }, ' \u2014 ' + hit.subtitle ) : null
						)
					);
				} )
			),
			has_more && ! loading
				? el( Button, { onClick: function () { do_search( query.trim(), page + 1, true ); } },
					__( 'Load more', 'v4v-musiclink' ) )
				: null
		);
	}

	function FountainPanel( props ) {
		var album = props.album;
		var track = props.track;
		var valueState = useState( '' );
		var value = valueState[0];
		var setValue = valueState[1];
		var savedState = useState( '' );
		var saved = savedState[0];
		var setSaved = savedState[1];
		var statusState = useState( 'idle' );
		var status = statusState[0];
		var setStatus = statusState[1];
		var errorState = useState( '' );
		var error = errorState[0];
		var setError = errorState[1];

		useEffect( function () {
			if ( ! album ) {
				setValue( '' );
				setSaved( '' );
				setStatus( 'idle' );
				return undefined;
			}
			setStatus( 'loading' );
			apiFetch( {
				path: NAMESPACE + '/row?album=' + encodeURIComponent( album ) +
					'&track=' + encodeURIComponent( track || '' )
			} ).then( function ( data ) {
				setValue( data.fountain_url || '' );
				setSaved( data.fountain_url || '' );
				setStatus( 'idle' );
				setError( '' );
			} ).catch( function ( err ) {
				setStatus( 'error' );
				setError( error_message( err ) );
			} );
		}, [ album, track ] );

		function persist( next ) {
			if ( next === saved ) {
				return;
			}
			setStatus( 'saving' );
			setError( '' );
			apiFetch( {
				path: NAMESPACE + '/row',
				method: 'PUT',
				data: { album: album, track: track || '', fountain_url: next }
			} ).then( function ( data ) {
				setValue( data.fountain_url || '' );
				setSaved( data.fountain_url || '' );
				setStatus( 'saved' );
			} ).catch( function ( err ) {
				setStatus( 'error' );
				setError( error_message( err ) );
			} );
		}

		return el( Fragment, null,
			el( TextControl, {
				label: __( 'Fountain.fm link', 'v4v-musiclink' ),
				help: __(
					'Stored in this release\'s cache row (the same field as the Cache tab\'s "Fountain.fm link"), so it is shared by every shortcode of this release. Leave empty to render the search fallback.',
					'v4v-musiclink'
				),
				value: value,
				placeholder: 'https://fountain.fm/...',
				disabled: ! album || 'loading' === status || 'saving' === status,
				onChange: setValue,
				onBlur: function () { persist( value ); }
			} ),
			'saving' === status ? el( Spinner ) : null,
			'saved' === status
				? el( 'p', { className: 'v4v-ml-saved' }, __( 'Saved to the cache row.', 'v4v-musiclink' ) )
				: null,
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			! album
				? el( 'p', { className: 'v4v-ml-hint' }, __(
					'Pick a search result or enter GUIDs first - the link lives on the cache row for that release.',
					'v4v-musiclink'
				) )
				: null
		);
	}

	// Read-only Album / Track / Artist text for the current selection,
	// read from the cache row (GET /row - the same cells the Cache tab
	// shows). Plain text rows only, deliberately no inputs: the values
	// are display metadata owned by the cache row, edited nowhere here.
	// An empty album guid shows a hint; a pair whose row does not exist
	// yet (hand-pasted GUIDs before the first render) shows em dashes
	// plus a note instead of pretending to know the title.
	function ReleaseDetails( props ) {
		var album = props.album;
		var track = props.track;
		var infoState = useState( null );
		var info = infoState[0];
		var setInfo = infoState[1];
		var errorState = useState( '' );
		var error = errorState[0];
		var setError = errorState[1];
		var requestSeq = useRef( 0 );

		useEffect( function () {
			var seq = ++requestSeq.current;
			if ( ! album ) {
				setInfo( null );
				setError( '' );
				return undefined;
			}
			apiFetch( {
				path: NAMESPACE + '/row?album=' + encodeURIComponent( album ) +
					'&track=' + encodeURIComponent( track || '' )
			} ).then( function ( data ) {
				if ( seq !== requestSeq.current ) {
					return;
				}
				setInfo( data );
				setError( '' );
			} ).catch( function ( err ) {
				if ( seq !== requestSeq.current ) {
					return;
				}
				setError( error_message( err ) );
			} );
		}, [ album, track ] );

		function row( label, text ) {
			return el( 'p', { className: 'v4v-ml-info-row' },
				el( 'strong', null, label ),
				text ? text : '—'
			);
		}

		if ( ! album ) {
			return el( 'p', { className: 'v4v-ml-hint' }, __(
				'No release selected yet.',
				'v4v-musiclink'
			) );
		}
		if ( error ) {
			return el( 'p', { className: 'v4v-ml-error' }, __(
				'Could not load release details: ',
				'v4v-musiclink'
			) + error );
		}
		if ( ! info ) {
			return el( 'p', { className: 'v4v-ml-hint' }, __(
				'Loading…',
				'v4v-musiclink'
			) );
		}
		return el( Fragment, null,
			row( __( 'Album: ', 'v4v-musiclink' ), info.album_title ),
			row( __( 'Track: ', 'v4v-musiclink' ), info.track_title ),
			row( __( 'Artist: ', 'v4v-musiclink' ), info.artist ),
			false === info.has_row
				? el( 'p', { className: 'v4v-ml-hint' }, __(
					'Not cached yet - the text fills in once the release has been rendered or searched.',
					'v4v-musiclink'
				) )
				: null
		);
	}

	blocks.registerBlockType( 'v4v-musiclink/musiclink', {
		title: __( 'Music Link', 'v4v-musiclink' ),
		description: __(
			'Insert a [musiclink] shortcode; search Music Index and edit every value from this block\'s settings pane.',
			'v4v-musiclink'
		),
		icon: 'format-audio',
		category: 'widgets',
		attributes: {
			album: { type: 'string', default: '' },
			track: { type: 'string', default: '' },
			header: { type: 'string', default: 'site' }
		},
		edit: function ( props ) {
			var album = props.attributes.album;
			var track = props.attributes.track;
			var header = props.attributes.header;
			var shortcode = build_shortcode( props.attributes );

			var htmlState = useState( '' );
			var html = htmlState[0];
			var setHtml = htmlState[1];
			var errorState = useState( '' );
			var previewError = errorState[0];
			var setPreviewError = errorState[1];
			var requestSeq = useRef( 0 );

			// Live canvas preview: the server runs the real shortcode
			// (REST /render), so what the block shows - titles, artist,
			// art, buttons in admin order - is exactly what the public
			// page will show, read from the cache row. Debounced because
			// the GUID fields type one character at a time; the sequence
			// counter drops responses that arrive after a newer request.
			useEffect( function () {
				var seq = ++requestSeq.current;
				if ( ! album ) {
					setHtml( '' );
					setPreviewError( '' );
					return undefined;
				}
				var timer = setTimeout( function () {
					var path = NAMESPACE + '/render?album=' + encodeURIComponent( album ) +
						'&track=' + encodeURIComponent( track || '' );
					if ( 'true' === header || 'false' === header ) {
						path += '&header=' + encodeURIComponent( header );
					}
					apiFetch( { path: path } ).then( function ( data ) {
						if ( seq !== requestSeq.current ) {
							return;
						}
						setHtml( data && data.html ? data.html : '' );
						setPreviewError( '' );
					} ).catch( function ( err ) {
						if ( seq !== requestSeq.current ) {
							return;
						}
						setPreviewError( error_message( err ) );
					} );
				}, 400 );
				return function () {
					clearTimeout( timer );
				};
			}, [ album, track, header ] );

			// No release yet: point at the settings pane. Release chosen:
			// show the rendered preview (falling back to an error note
			// plus the raw shortcode only if the render route fails, so
			// the block stays usable offline).
			var canvas;
			if ( ! album ) {
				canvas = el( 'p', { className: 'v4v-ml-hint' }, __(
					'Search by title in the settings pane, or paste the release GUIDs.',
					'v4v-musiclink'
				) );
			} else if ( html ) {
				canvas = el( Fragment, null,
					el( 'div', { className: 'v4v-ml-preview' },
						el( RawHTML, null, html )
					),
					previewError
						? el( 'p', { className: 'v4v-ml-error' }, __(
							'Preview refresh failed: ',
							'v4v-musiclink'
						) + previewError )
						: null
				);
			} else if ( previewError ) {
				canvas = el( Fragment, null,
					el( 'p', { className: 'v4v-ml-error' }, __(
						'Preview failed: ',
						'v4v-musiclink'
					) + previewError ),
					shortcode ? el( 'code', null, shortcode ) : null
				);
			} else {
				canvas = el( 'p', { className: 'v4v-ml-hint' }, __(
					'Rendering preview…',
					'v4v-musiclink'
				) );
			}

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Search Music Index', 'v4v-musiclink' ), initialOpen: true },
						el( SearchPanel, {
							attributes: props.attributes,
							setAttributes: props.setAttributes
						} )
					),
					el( PanelBody, { title: __( 'Release details', 'v4v-musiclink' ), initialOpen: true },
						el( ReleaseDetails, {
							album: props.attributes.album,
							track: props.attributes.track
						} )
					),
					el( PanelBody, { title: __( 'Fountain.fm link', 'v4v-musiclink' ), initialOpen: true },
						el( FountainPanel, {
							album: props.attributes.album,
							track: props.attributes.track
						} )
					),
					el( PanelBody, { title: __( 'Release header', 'v4v-musiclink' ), initialOpen: false },
						el( SelectControl, {
							label: __( 'Header', 'v4v-musiclink' ),
							value: props.attributes.header,
							options: [
								{ label: __( 'Site setting', 'v4v-musiclink' ), value: 'site' },
								{ label: __( 'Always Show', 'v4v-musiclink' ), value: 'true' },
								{ label: __( 'Always Hide', 'v4v-musiclink' ), value: 'false' }
							],
							onChange: function ( next ) { props.setAttributes( { header: next } ); }
						} ),
						el( 'p', { className: 'v4v-ml-hint' }, __(
							'Site setting omits the attribute and follows the plugin\'s Release header setting.',
							'v4v-musiclink'
						) )
					),
					el( PanelBody, { title: __( 'Release GUIDs', 'v4v-musiclink' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Album GUID', 'v4v-musiclink' ),
							value: props.attributes.album,
							onChange: function ( next ) { props.setAttributes( { album: clean_value( next ) } ); }
						} ),
						el( TextControl, {
							label: __( 'Track GUID', 'v4v-musiclink' ),
							value: props.attributes.track,
							help: __( 'Optional for album-only links; each value is limited to 256 bytes.', 'v4v-musiclink' ),
							onChange: function ( next ) { props.setAttributes( { track: clean_value( next ) } ); }
						} )
					)
				),
				el( 'div', { className: 'v4v-ml-block' },
					canvas
				)
			);
		},
		save: function ( props ) {
			return el( RawHTML, null, build_shortcode( props.attributes ) );
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.apiFetch );
V4VML;
	}

	private function inline_css() {
		return '
			.v4v-musiclink { max-width: 480px; margin: 1.5em 0; }
			.v4v-musiclink-header { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 1.2em; margin-bottom: 0.75em; }
			.v4v-musiclink-info { flex: 1 1 220px; min-width: 0; }
			.v4v-musiclink-title { font-weight: 700; font-size: 2em; line-height: 1.2; margin: 0 0 0.2em; }
			.v4v-musiclink-artist { font-weight: 600; font-size: 1.1em; margin: 0; }
			.v4v-musiclink-art {
				width: 200px; height: 200px; object-fit: cover; border-radius: 8px;
				flex: 0 0 200px; order: 2;
			}
			.v4v-musiclink-heading { font-weight: 600; margin-bottom: 0.75em; }
			.v4v-musiclink-list { list-style: none; margin: 0; padding: 0; }
			.v4v-musiclink-item { margin-bottom: 0.5em; }
			.v4v-musiclink-item a {
				display: flex;
				align-items: center;
				gap: 0.6em;
				padding: 0.75em 1em;
				border: 2px solid #ddd;
				border-radius: 6px;
				text-decoration: none;
				transition: background-color 0.15s ease;
			}
			.v4v-musiclink-item a:hover { background-color: #f5f5f5; }
			.v4v-musiclink-icon { width: 40px; height: 40px; flex: 0 0 40px; border-radius: 6px; }
			.v4v-musiclink-error { color: #a00; }

			.v4v-musiclink-modal-overlay {
				display: none;
				position: fixed;
				inset: 0;
				background: rgba(0, 0, 0, 0.55);
				align-items: center;
				justify-content: center;
				z-index: 100000;
				padding: 1em;
			}
			.v4v-musiclink-modal-overlay.is-open { display: flex; }
			.v4v-musiclink-modal {
				background: var(--wp--preset--color--background, #fff);
				color: var(--wp--preset--color--foreground, #1e1e1e);
				border-radius: 8px;
				padding: 1.5em;
				max-width: 380px;
				width: 100%;
				box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
				font-family: inherit;
			}
			.v4v-musiclink-modal-message { margin: 0 0 0.75em; line-height: 1.5; }
			.v4v-musiclink-modal-message:last-of-type { margin-bottom: 1.25em; }
			.v4v-musiclink-modal-actions { display: flex; justify-content: flex-end; gap: 0.6em; }
			.v4v-musiclink-modal-btn {
				padding: 0.55em 1.2em;
				border-radius: 6px;
				border: 1px solid #ccc;
				cursor: pointer;
				font-size: 0.95em;
				font-family: inherit;
			}
			.v4v-musiclink-modal-cancel { background: #f5f5f5; color: #1e1e1e; }
			.v4v-musiclink-modal-cancel:hover { background: #ebebeb; }
			.v4v-musiclink-modal-ok {
				background: var(--wp--preset--color--primary, #2271b1);
				border-color: var(--wp--preset--color--primary, #2271b1);
				color: #fff;
			}
			.v4v-musiclink-modal-ok:hover { filter: brightness(0.92); }
			.v4v-musiclink-footer { margin-top: 1.25em; font-size: 0.85em; color: #444; }
			.v4v-musiclink-footer a { color: inherit; text-decoration: underline; }

			.v4v-musiclink-missing-params pre {
				background: #f5f5f5; padding: 0.75em 1em; border-radius: 6px;
				overflow-x: auto; font-size: 0.9em;
			}
		';
	}

	/**
	 * Editor-only rules printed alongside inline_css() on the
	 * `v4v-musiclink-editor` handle (see enqueue_block_editor_styles()),
	 * so the preview looks like the public page while staying inert and
	 * preview-sized: anchors are pointer-events:none so the launch
	 * buttons and the Fountain confirm modal never fire from inside the
	 * editor; the album art is scaled down to a 120px thumbnail (the
	 * front-end 200px dominates the edit page); everything that follows
	 * the button list - the attribution footer and the modal markup - is
	 * omitted from the preview; and the inspector's on-demand search bar
	 * lays out as one row (query field + Search + Close). A light dashed
	 * outline marks where the preview sits on a blank canvas.
	 */
	private function block_editor_css() {
		return '
			.v4v-ml-preview { outline: 1px dashed rgba(0, 0, 0, 0.25); outline-offset: 8px; }
			.v4v-ml-preview a { pointer-events: none; }
			.v4v-ml-hint { color: #666; font-style: italic; }
			.v4v-ml-error { color: #a00; }
			.v4v-ml-preview .v4v-musiclink-art {
				width: 120px; height: 120px; flex: 0 0 120px;
				max-width: 100%; object-fit: cover;
			}
			.v4v-ml-preview .v4v-musiclink-footer,
			.v4v-ml-preview .v4v-musiclink-modal-overlay { display: none; }
			.v4v-ml-searchbar { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
			.v4v-ml-searchbar input { flex: 1 1 120px; min-width: 0; width: auto; }
		';
	}

	private function inline_js() {
		return <<<'JS'
		document.addEventListener("DOMContentLoaded", function () {
			var fountainLink = document.querySelector(".v4v-musiclink-fountain a");
			var modal = document.getElementById("v4v-musiclink-fountain-modal");
			if (fountainLink && modal) {
				var okBtn     = modal.querySelector(".v4v-musiclink-modal-ok");
				var cancelBtn = modal.querySelector(".v4v-musiclink-modal-cancel");
				var pendingHref = null;

				function closeModal() {
					modal.classList.remove("is-open");
					pendingHref = null;
				}

				fountainLink.addEventListener("click", function (e) {
					var href = fountainLink.getAttribute("href");
					// Unresolved fallbacks: the generic discover page or
					// the release search URL; the bare homepage stays
					// matched for a link set by hand.
					var isFallback = href === "https://fountain.fm/discover"
						|| 0 === href.indexOf("https://fountain.fm/search?")
						|| href === "https://fountain.fm" || href === "https://fountain.fm/";
					if (isFallback) {
						e.preventDefault();
						pendingHref = href;
						modal.classList.add("is-open");
					}
				});

				cancelBtn.addEventListener("click", closeModal);

				okBtn.addEventListener("click", function () {
					var href = pendingHref;
					closeModal();
					if (href) {
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
	 * on-page display (this plugin emits no social-preview meta tags).
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

	/**
	 * Length-only guard for guids at intake (#3): anything over 256 bytes
	 * is dropped and behaves exactly like an absent guid (the "Missing
	 * parameter(s)" message), before it can reach an API lookup or an
	 * outbound app URL. Deliberately NO charset whitelist - Music Index
	 * ids are free-form (e.g. track_01-they're-right!) - so only size is
	 * bounded. Same contract as the Pro plugin's guard_guid().
	 */
	private function guard_guid( $guid ) {
		return strlen( $guid ) <= 256 ? $guid : '';
	}

	public function render( $atts ) {
		// Page renders are the only context allowed to queue background
		// Music Index refreshes (see the $mi_refresh_allowed gate).
		$this->mi_refresh_allowed = true;

		$raw_atts   = is_array( $atts ) ? $atts : array();
		$unexpected = array_diff( array_keys( $raw_atts ), array( 'album', 'track', 'header' ) );

		$atts = shortcode_atts( array(
			'album'  => '',
			'track'  => '',
			'header' => '',
		), $raw_atts, 'musiclink' );

		// An unexpected attribute name means we ignore ALL attributes and
		// treat this exactly like a bare [musiclink] with nothing supplied,
		// rather than guessing at which of the given attributes to trust.
		$album = ( empty( $unexpected ) && $atts['album'] )
			? $this->guard_guid( sanitize_text_field( $atts['album'] ) )
			: '';
		$track = ( empty( $unexpected ) && $atts['track'] )
			? $this->guard_guid( sanitize_text_field( $atts['track'] ) )
			: '';

		// Optional per-placement override of the admin "Release header"
		// setting; empty (not supplied) falls back to that setting.
		$show_header = $this->resolve_show_header(
			empty( $unexpected ) ? $atts['header'] : ''
		);

		if ( empty( $album ) ) {
			return $this->render_missing_params_message();
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
			return $this->render_missing_params_message( __( 'Album/Song not found', 'v4v-musiclink' ) );
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
		// search page directly (both confirmed to 404 - see project notes),
		// and this version never resolves one itself (the Pro version
		// does): the button uses a link already on the cache row - set by
		// hand on the Cache tab - and otherwise falls back to a Fountain
		// search for the release title (or fountain.fm/discover when no
		// title is known).
		if ( $this->is_app_enabled( 'fountain' ) ) {
			$fountain_url = 'https://fountain.fm/discover';

			// Cached link: an exact album/track match with a link on file
			// is used exactly as it stands - no search and no write-back -
			// so a link corrected by hand is never reverted. '' means
			// either "no entry yet" or "entry whose link was blanked";
			// both fall through to the search fallback below.
			$cached_url = $this->get_cached_fountain_url( $album, $track );

			if ( '' !== $cached_url ) {
				$fountain_url = $cached_url;
			} else {
				// Track title when the shortcode carries track data, else
				// the album title; '' keeps the discover default above.
				$fountain_search_text = ( $has_track && $track_data ) ? $track_data['title'] : ( $feed_data ? $feed_data['title'] : '' );

				if ( $fountain_search_text ) {
					// Hand the visitor Fountain's own search results for
					// this release - the double quotes are Fountain's
					// exact-phrase syntax - instead of the generic
					// discover page. Just as generic as discover, so
					// neither is ever persisted to the cache row.
					$fountain_url = 'https://fountain.fm/search?q='
						. urlencode( '"' . $fountain_search_text . '"' );
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

		return $this->render_html( $links, $has_track, $album_title, $track_title, $artist, $art_url, $show_header );
	}

	/**
	 * Whether the release header (title/artist/artwork) should render.
	 * The optional shortcode header="true|false" attribute overrides the
	 * admin "Release header" setting; when the attribute is absent or has
	 * an unrecognized value, the admin setting decides (default: shown).
	 */
	private function resolve_show_header( $raw ) {
		$default = (bool) get_option( 'v4v_musiclink_show_header', true );

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
	 * Shown whenever there's no usable album/track to display: no album
	 * attribute given, an unexpected attribute name was present, or the
	 * given guid(s) didn't match anything in Music Index. Since URL
	 * parameters and the search UI have both been removed, the only way
	 * to use this shortcode is with explicit attributes - so this message
	 * doubles as inline usage instructions. A "V4V MusicLink" heading sits
	 * above the message so the block is identifiable wherever it appears.
	 */
	private function render_missing_params_message( $reason = '' ) {
		ob_start();
		?>
		<div class="v4v-musiclink v4v-musiclink-missing-params">
			<h1 class="v4v-musiclink-title"><?php esc_html_e( 'V4V MusicLink', 'v4v-musiclink' ); ?></h1>
			<p class="v4v-musiclink-error"><?php echo esc_html( $reason ? $reason : __( 'Missing parameter(s)', 'v4v-musiclink' ) ); ?></p>
			<p><?php esc_html_e( 'params:', 'v4v-musiclink' ); ?></p>
			<pre>album="&lt;album-guid&gt;"</pre>
			<pre>track="&lt;track-guid&gt;" (optional)</pre>
			<pre>header="&lt;true/false&gt;" (optional)</pre>
			<p><?php esc_html_e( 'Usage:', 'v4v-musiclink' ); ?></p>
			<pre>[musiclink album="&lt;album-guid&gt;"]</pre>
			<pre>[musiclink album="&lt;album-guid&gt;" track="&lt;track-guid&gt;"]</pre>
			<pre>[musiclink album="&lt;album-guid&gt;" header="false"]</pre>
		</div>
		<?php
		return ob_get_clean();
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
	 * Rows are never evicted: stale data keeps serving at any age, so a
	 * Music Index outage cannot turn an already-seen release into
	 * "Album/Song not found".
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
	 * on the row. Not gated: filling an absent row costs the same API
	 * traffic the old transient fill did, and the Pro search box / log
	 * beacons need titles to do their job. Only refreshes of already
	 * stale rows (branch 2) are gated.
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
	 * fountain_updated_at belong to the link half (Cache-tab edits and
	 * the clear action) and are never touched here (the ON DUPLICATE KEY
	 * UPDATE lists only MI columns).
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
	 * formula from the old positive/negative shared key: the positives
	 * have moved to the cache table, so this key only ever holds
	 * remembered misses.
	 */
	private function mi_negative_key( $kind, $album, $track ) {
		return 'feed' === $kind
			? 'mi_feed_v' . self::CACHE_SCHEMA_VERSION . '_' . md5( $album )
			: 'mi_track_v' . self::CACHE_SCHEMA_VERSION . '_' . md5( $album . '|' . $track );
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
		$hours = (int) get_option( 'v4v_musiclink_mi_refresh_hours', self::MI_REFRESH_DEFAULT_HOURS );

		return min( max( $hours, 1 ), 720 ) * HOUR_IN_SECONDS;
	}

	/**
	 * Queue a background Music Index pull for a stale row (branch 2 of
	 * mi_get_feed()/mi_get_track()). Default-deny: the $mi_refresh_allowed
	 * gate means only render() can ever get here - the Pro search box,
	 * the log beacons and the og: preview cannot trigger a refresh. The
	 * per-row attempt marker keeps concurrent renders of the same stale
	 * row from stacking pulls, and expires after a minute so a hard-killed
	 * attempt self-heals on the next allowed render.
	 */
	private function queue_mi_refresh( $kind, $album, $track ) {
		if ( ! $this->mi_refresh_allowed ) {
			return;
		}

		$hash         = $this->cache_row_hash( $album, $track );
		$marker       = 'v4v_musiclink_mi_attempt_' . md5( $hash );
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

	/**
	 * Stable identity for one search hit: type|entity_id|album_guid, or ''
	 * when the hit is unusable (no type/id, or a track whose parent album
	 * guid is missing). Used for de-duplication across cursor batches.
	 * Ported unchanged from Pro's search_hit_key().
	 */
	private function search_hit_key( $hit ) {
		if ( empty( $hit['entity_type'] ) || empty( $hit['entity_id'] ) ) {
			return '';
		}

		// Track-type hits carry a separate feed_guid (their parent album)
		// and need it to fetch details. Feed/album-type hits have no
		// feed_guid at all - entity_id IS the album's own guid.
		$album_guid = ( 'track' === $hit['entity_type'] ) ? ( isset( $hit['feed_guid'] ) ? $hit['feed_guid'] : '' ) : $hit['entity_id'];
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
	 * ever grows by appending: a hit keeps the same index for the lifetime
	 * of the cache, which is what makes page boundaries stable and lets the
	 * browser walk backwards for free. Relevance order is exactly the order
	 * Music Index returned - nothing is re-sorted here.
	 *
	 * Ported unchanged from Pro's mi_search_hits(); only the transient
	 * namespace differs (this plugin's own 'v' . SEARCH_CACHE_SCHEMA key).
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

			$cursor  = isset( $body['pagination']['cursor'] ) ? (string) $body['pagination']['cursor'] : '';
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
	 * Registers the editor-only REST routes backing the block-editor
	 * shortcode builder: Music Index search, and cache-row fountain-link
	 * read/write for the settings pane's link field. Admin-only (see
	 * rest_permission()) - standard never exposes a public search
	 * endpoint, unlike Pro's nopriv AJAX relay, so none of Pro's nonce
	 * printing or per-IP rate limiting applies here.
	 */
	public function register_rest_routes() {
		register_rest_route( 'v4v-musiclink/v1', '/search', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_search' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		register_rest_route( 'v4v-musiclink/v1', '/row', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_row' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'rest_update_row' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
		) );

		register_rest_route( 'v4v-musiclink/v1', '/render', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_render' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );
	}

	/**
	 * Permission gate shared by every route above: administrators only.
	 * The block's script is never enqueued for anyone else either (see
	 * enqueue_block_editor_assets()), so an ordinary editor never even
	 * sees the UI that would call these.
	 */
	public function rest_permission() {
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error(
				'v4v_musiclink_forbidden',
				__( 'Only administrators may use the Music Link block endpoints.', 'v4v-musiclink' ),
				array( 'status' => 403 )
			);
	}

	/**
	 * GET /v4v-musiclink/v1/search?q=&page= - the settings pane's search
	 * box. Queries Music Index via mi_search_hits(), then resolves each
	 * hit's display text (search results carry guids only) through
	 * mi_get_track()/mi_get_feed() - which also upserts those releases'
	 * cache rows, same as Pro's relay, so picked hits already show up on
	 * the Cache tab. Pagination mirrors ajax_search(): relevance order,
	 * no grand total (the upstream API offers only opaque cursors), just
	 * page/per_page/has_more. Returns the payload array; WP's REST server
	 * wraps it as JSON.
	 */
	public function rest_search( $request ) {
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$page  = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = self::SEARCH_PER_PAGE;

		$payload = array(
			'results'  => array(),
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => false,
		);

		if ( '' === $query ) {
			return $payload;
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

			$album_guid_for_hit = ( 'track' === $hit['entity_type'] ) ? ( isset( $hit['feed_guid'] ) ? $hit['feed_guid'] : '' ) : $hit['entity_id'];

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
						__( 'from %1$s · %2$s', 'v4v-musiclink' ),
						$track_data['album_title'],
						$track_data['artist']
					), ' ·' ),
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
						? sprintf( __( 'Album by %s', 'v4v-musiclink' ), $feed_data['artist'] )
						: __( 'Album', 'v4v-musiclink' ),
				);
			}
		}

		// Another page exists if we already hold hits beyond this one, or if
		// the cursor says another batch is fetchable. An empty page is always
		// the last one, so a request past the end can't offer "Next".
		$payload['has_more'] = ( ! $payload['results'] )
			? false
			: ( isset( $hits[ $offset + $per_page ] ) || $search['has_more'] );

		return $payload;
	}

	/**
	 * GET /v4v-musiclink/v1/render?album=&track=&header= - the block
	 * canvas's live preview. Runs the real render() so the editor shows
	 * exactly what the public page will show: release header, titles,
	 * artist, art and the buttons in admin order. It IS a page render,
	 * so previewing a pasted guid pair caches the release and may queue
	 * a background Music Index refresh - same as a front-end visit.
	 * header passes through only as the literal 'true'/'false' override;
	 * 'site'/anything else is omitted so the admin "Release header"
	 * setting decides, mirroring the shortcode attribute semantics. An
	 * unguarded (over-long) album guid is a 400, like an absent one -
	 * never a render of a usage message the block would not display.
	 * Administrator-only, like every route here.
	 */
	public function rest_render( $request ) {
		$album = $this->guard_guid( sanitize_text_field( (string) $request->get_param( 'album' ) ) );

		if ( '' === $album ) {
			return new WP_Error(
				'v4v_musiclink_missing_album',
				__( 'An album GUID is required for a preview.', 'v4v-musiclink' ),
				array( 'status' => 400 )
			);
		}

		$track  = $this->guard_guid( sanitize_text_field( (string) $request->get_param( 'track' ) ) );
		$header = (string) $request->get_param( 'header' );

		$atts = array(
			'album' => $album,
			'track' => $track,
		);

		if ( in_array( $header, array( 'true', 'false' ), true ) ) {
			$atts['header'] = $header;
		}

		return array( 'html' => $this->render( $atts ) );
	}

	/**
	 * GET /v4v-musiclink/v1/row?album=&track= - the settings pane's
	 * fountain field loads the pair's cached link (plus display text for
	 * context) from the cache row, or has_row=false when the release has
	 * never been cached. Album guid is required; track may be empty for
	 * album-only shortcodes.
	 */
	public function rest_get_row( $request ) {
		$album = $this->guard_guid( sanitize_text_field( (string) $request->get_param( 'album' ) ) );
		$track = $this->guard_guid( sanitize_text_field( (string) $request->get_param( 'track' ) ) );

		if ( '' === $album ) {
			return new WP_Error(
				'v4v_musiclink_bad_request',
				__( 'An album guid is required.', 'v4v-musiclink' ),
				array( 'status' => 400 )
			);
		}

		$row = $this->cache_get_row( $album, $track );

		return array(
			'has_row'      => (bool) $row,
			'fountain_url' => ( $row && is_string( $row->fountain_url ) ) ? $row->fountain_url : '',
			'album_title'  => $row ? $row->album_title : '',
			'track_title'  => $row ? $row->track_title : '',
			'artist'       => $row ? $row->artist : '',
		);
	}

	/**
	 * PUT /v4v-musiclink/v1/row - the settings pane's fountain field
	 * writes the pair's cached link. Same rules as the Cache-tab editor
	 * (handle_update_cache_row()): https fountain.fm URL or empty to
	 * clear; the write touches ONLY the fountain half - titles, artist,
	 * art and mi_updated_at are never in the statement. A single
	 * INSERT ... ON DUPLICATE KEY UPDATE covers both cases: an existing
	 * row gets its two fountain columns refreshed, a missing row is
	 * created with empty MI columns and mi_updated_at NULL, so the first
	 * render takes the blocking fetch path and backfills the metadata
	 * while this link stays put. Empty URL with no row writes nothing -
	 * blank rows are never created.
	 */
	public function rest_update_row( $request ) {
		$album = $this->guard_guid( sanitize_text_field( (string) $request->get_param( 'album' ) ) );
		$track = $this->guard_guid( sanitize_text_field( (string) $request->get_param( 'track' ) ) );
		$url   = esc_url_raw( trim( (string) $request->get_param( 'fountain_url' ) ) );

		if ( '' === $album ) {
			return new WP_Error(
				'v4v_musiclink_bad_request',
				__( 'An album guid is required.', 'v4v-musiclink' ),
				array( 'status' => 400 )
			);
		}

		if ( '' !== $url && ! $this->is_valid_stored_url( $url, 'fountain' ) ) {
			return new WP_Error(
				'v4v_musiclink_bad_url',
				__( 'The Fountain.fm link must be an https URL on fountain.fm.', 'v4v-musiclink' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;
		$table = $this->cache_table();
		$hash  = $this->cache_row_hash( $album, $track );
		$row   = $this->cache_get_row( $album, $track );

		if ( $row || '' !== $url ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
						(lookup_hash, album_guid, track_guid, album_title, track_title, artist, image_url, fountain_url, fountain_updated_at, mi_updated_at)
					VALUES (%s, %s, %s, '', '', '', '', %s, %s, NULL)
					ON DUPLICATE KEY UPDATE
						fountain_url = VALUES(fountain_url),
						fountain_updated_at = VALUES(fountain_updated_at)",
					$hash,
					$album,
					$track,
					$url,
					current_time( 'mysql' )
				)
			);
		}

		unset( $this->row_memo[ $hash ] );
		$row = $this->cache_get_row( $album, $track );

		return array(
			'has_row'      => (bool) $row,
			'fountain_url' => ( $row && is_string( $row->fountain_url ) ) ? $row->fountain_url : '',
			'album_title'  => $row ? $row->album_title : '',
			'track_title'  => $row ? $row->track_title : '',
			'artist'       => $row ? $row->artist : '',
		);
	}

	private function render_html( $links, $has_track, $album_title, $track_title, $artist, $art_url, $show_header = true ) {
		$heading = $has_track
			? __( 'Listen to this track:', 'v4v-musiclink' )
			: __( 'Listen to this album:', 'v4v-musiclink' );

		$title_line = $has_track && $track_title
			? sprintf( '"%s" from %s', $track_title, $album_title )
			: $album_title;

		ob_start();
		?>
		<div class="v4v-musiclink">
			<?php if ( $show_header && ( $title_line || $art_url ) ) : ?>
				<div class="v4v-musiclink-header">
					<div class="v4v-musiclink-info">
						<?php if ( $title_line ) : ?>
							<h1 class="v4v-musiclink-title"><?php echo esc_html( $title_line ); ?></h1>
						<?php endif; ?>
						<?php if ( $artist ) : ?>
							<p class="v4v-musiclink-artist"><?php echo esc_html( sprintf( __( 'by %s', 'v4v-musiclink' ), $artist ) ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( $art_url ) : ?>
						<img
							class="v4v-musiclink-art"
							src="<?php echo esc_url( $art_url ); ?>"
							alt="<?php echo esc_attr( $title_line ? $title_line : __( 'Album art', 'v4v-musiclink' ) ); ?>"
							loading="lazy"
						/>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p class="v4v-musiclink-heading"><?php echo esc_html( $heading ); ?></p>
			<?php if ( empty( $links ) ) : ?>
				<p class="v4v-musiclink-error"><?php esc_html_e( 'No music apps are currently enabled - check Settings > V4V MusicLink.', 'v4v-musiclink' ); ?></p>
			<?php else :
				$random_order = (bool) get_option( 'v4v_musiclink_random_app_order', false ) && count( $links ) > 1;
			?>
				<ul class="v4v-musiclink-list"<?php echo $random_order ? ' data-v4v-shuffle="1"' : ''; ?>>
					<?php foreach ( $links as $slug => $link ) : ?>
						<?php $icon_bg = $this->app_icon_bg( $slug ); ?>
						<li class="v4v-musiclink-item v4v-musiclink-<?php echo esc_attr( $slug ); ?>">
							<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<img
									class="v4v-musiclink-icon"
									src="<?php echo esc_url( $link['icon_url'] ); ?>"
									alt=""
									width="40"
									height="40"
									loading="lazy"
									<?php if ( '' !== $icon_bg ) : ?>
									style="background-color: <?php echo esc_attr( $icon_bg ); ?>"
									<?php endif; ?>
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

			<?php if ( get_option( 'v4v_musiclink_show_credits', false ) ) : ?>
				<p class="v4v-musiclink-footer">
					<?php esc_html_e( 'Generated by', 'v4v-musiclink' ); ?>
					<a href="https://phafe.com/musiclink"><?php esc_html_e( 'V4V MusicLink', 'v4v-musiclink' ); ?></a>.
					<?php esc_html_e( 'Powered by', 'v4v-musiclink' ); ?>
					<a href="https://musicindex.org">MusicIndex.org</a>
				</p>
			<?php endif; ?>
		</div>

		<div class="v4v-musiclink-modal-overlay" id="v4v-musiclink-fountain-modal">
			<div class="v4v-musiclink-modal" role="dialog" aria-modal="true">
				<p class="v4v-musiclink-modal-message"><?php esc_html_e( 'Unable to generate Fountain.fm link for this release.', 'v4v-musiclink' ); ?></p>
				<p class="v4v-musiclink-modal-message"><?php esc_html_e( 'Please use Fountain\'s search.', 'v4v-musiclink' ); ?></p>
				<div class="v4v-musiclink-modal-actions">
					<button type="button" class="v4v-musiclink-modal-btn v4v-musiclink-modal-cancel"><?php esc_html_e( 'Cancel', 'v4v-musiclink' ); ?></button>
					<button type="button" class="v4v-musiclink-modal-btn v4v-musiclink-modal-ok"><?php esc_html_e( 'OK', 'v4v-musiclink' ); ?></button>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

new V4V_Music_Link_Resolver();
