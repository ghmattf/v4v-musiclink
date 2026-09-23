<?php
/**
 * Plugin Name: V4V Music Link Resolver
 * Description: Provides a [musiclink] shortcode (album/track attributes required) that renders Stablekraft, PodTunes.app, v4vmusic.com, and Fountain links for a V4V music album/track.
 * Version: 1.22.8
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
 * Icons are pulled live from each app's own favicon via a favicon service
 * (no logo assets are stored or reproduced by this plugin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class V4V_Music_Link_Resolver {

	const MI_ROOT        = 'https://api.musicindex.org';
	const MI_API_BASE    = 'https://api.musicindex.org/v1';
	const CACHE_TTL       = 12 * HOUR_IN_SECONDS;
	const FAIL_CACHE_TTL  = HOUR_IN_SECONDS;
	// Bump whenever the shape of what gets cached changes, so old
	// transients (with different/missing fields) are never served stale.
	const CACHE_SCHEMA_VERSION = 4;

	public function __construct() {
		add_shortcode( 'musiclink', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
		register_setting( 'v4v_musiclink', 'v4v_musiclink_fountain_next_action', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'v4v_musiclink', 'v4v_musiclink_show_header', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'v4v_musiclink', 'v4v_musiclink_apps', array(
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

	private function get_app_config( $slug ) {
		$all      = get_option( 'v4v_musiclink_apps', array() );
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
							<table class="widefat" style="max-width:760px;">
								<thead>
									<tr>
										<th style="width:100px;"><?php esc_html_e( 'Order', 'v4v-musiclink' ); ?></th>
										<th style="width:60px;"><?php esc_html_e( 'Active', 'v4v-musiclink' ); ?></th>
										<th><?php esc_html_e( 'App', 'v4v-musiclink' ); ?></th>
										<th style="width:60px;"><?php esc_html_e( 'Icon', 'v4v-musiclink' ); ?></th>
										<th><?php esc_html_e( 'Icon override URL', 'v4v-musiclink' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php $position = 0; ?>
									<?php foreach ( $this->ordered_app_registry() as $slug => $app ) : ?>
										<?php
										$config        = $this->get_app_config( $slug );
										$preview        = $this->get_app_icon( $slug, $app['domain'] );
										$default_icon   = $this->favicon_url( $app['domain'] );
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
													style="display:block; width:32px; height:32px; border-radius:4px;"
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
										</tr>
									<?php $position++; ?>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description">
								<?php esc_html_e( 'Unchecking "Active" hides that app\'s button entirely wherever the [musiclink] shortcode is used. Use the arrows in the "Order" column to set the order the buttons appear in on the front end, then click "Save Changes" below to apply it. Click "Set" to preview an icon override before saving - the preview updates immediately, but nothing is saved until you click "Save Changes" below.', 'v4v-musiclink' ); ?>
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
							<label for="v4v_musiclink_fountain_next_action"><?php esc_html_e( 'Fountain "next-action" header', 'v4v-musiclink' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="v4v_musiclink_fountain_next_action"
								name="v4v_musiclink_fountain_next_action"
								value="<?php echo esc_attr( get_option( 'v4v_musiclink_fountain_next_action', '' ) ); ?>"
								class="regular-text code"
							/>
							<p class="description">
								<?php
								esc_html_e(
									'Optional starting point for direct Fountain.fm links (without any value here, Fountain buttons link to fountain.fm plainly until one is found). This value changes whenever Fountain redeploys - if it goes stale, the plugin automatically tries to detect a fresh one in the background and saves it here when successful, so manual updates usually aren\'t needed. If you want to set it directly anyway: open fountain.fm with browser dev tools\' Network tab open, search for anything, find the POST request to "fountain.fm/", and copy its "next-action" request header.',
									'v4v-musiclink'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function enqueue_assets() {
		wp_register_style( 'v4v-musiclink', false );
		wp_enqueue_style( 'v4v-musiclink' );
		wp_add_inline_style( 'v4v-musiclink', $this->inline_css() );

		wp_register_script( 'v4v-musiclink', false, array(), '1.22.8', true );
		wp_enqueue_script( 'v4v-musiclink' );
		wp_add_inline_script( 'v4v-musiclink', $this->inline_js() );
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
					if (href === "https://fountain.fm" || href === "https://fountain.fm/") {
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
		$album = ( empty( $unexpected ) && $atts['album'] ) ? sanitize_text_field( $atts['album'] ) : '';
		$track = ( empty( $unexpected ) && $atts['track'] ) ? sanitize_text_field( $atts['track'] ) : '';

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
	 * doubles as inline usage instructions.
	 */
	private function render_missing_params_message( $reason = '' ) {
		ob_start();
		?>
		<div class="v4v-musiclink v4v-musiclink-missing-params">
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
		$next_action = get_option( 'v4v_musiclink_fountain_next_action', '' );
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
				update_option( 'v4v_musiclink_fountain_next_action', $detected );
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
		$cache_key = 'fountain_id_v5_' . md5( $guid . '|' . $next_action );
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
		$throttle_key = 'fountain_autodetect_throttle';
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

			// Exact host match (apex or subdomain). The previous substring
			// check also accepted e.g. https://evil.tld/fountain.fm/_next/x.js,
			// sending this server after whatever such a page declared.
			$host    = wp_parse_url( $src, PHP_URL_HOST );
			$trusted = $host && ( 'fountain.fm' === $host || '.fountain.fm' === substr( $host, -12 ) );

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
		$cache_key = 'mi_feed_v' . self::CACHE_SCHEMA_VERSION . '_' . md5( $feed_guid );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ! empty( $cached ) ? $cached : null;
		}

		$body = $this->mi_api_get( '/feeds/' . rawurlencode( $feed_guid ) );

		if ( empty( $body['data']['title'] ) ) {
			set_transient( $cache_key, array(), self::FAIL_CACHE_TTL );
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
		$cache_key = 'mi_track_v' . self::CACHE_SCHEMA_VERSION . '_' . md5( $feed_guid . '|' . $track_guid );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ! empty( $cached ) ? $cached : null;
		}

		$body = $this->mi_api_get( '/feeds/' . rawurlencode( $feed_guid ) . '/tracks/' . rawurlencode( $track_guid ) );

		if ( empty( $body['data']['title'] ) ) {
			set_transient( $cache_key, array(), self::FAIL_CACHE_TTL );
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
	 * Low-level GET against the Music Index API. Returns the decoded
	 * response body as an array, or null on any failure (network error,
	 * non-200 response, bad JSON). No authentication required.
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

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
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
			<?php else : ?>
				<ul class="v4v-musiclink-list">
					<?php foreach ( $links as $slug => $link ) : ?>
						<li class="v4v-musiclink-item v4v-musiclink-<?php echo esc_attr( $slug ); ?>">
							<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<img
									class="v4v-musiclink-icon"
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

			<p class="v4v-musiclink-footer">
				<?php esc_html_e( 'Generated by', 'v4v-musiclink' ); ?>
				<a href="https://phafe.com/musiclink"><?php esc_html_e( 'V4V MusicLink', 'v4v-musiclink' ); ?></a>.
				<?php esc_html_e( 'Powered by', 'v4v-musiclink' ); ?>
				<a href="https://musicindex.org">MusicIndex.org</a>
			</p>
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
