<?php
/**
 * Standalone verification that no page render writes a log row, plus the
 * Clear log button.
 *
 * Loads the real Pro plugin file against minimal WordPress stubs, then:
 *   - drives the real render() with URL-sourced and shortcode-attribute-
 *     sourced guids (and the not-found / bare-search fallbacks) and
 *     asserts every one of them writes nothing - opens are logged only
 *     by the search-click beacon, launches only by the button beacon
 *     (both in tmp/test_app_launch.php, budgets/pruning in
 *     tmp/test_log_limits.php);
 *   - renders the View Log tab and the clear-log handler behind it.
 *
 * Run:  ./php/php tmp/test_log_scope.php
 */

error_reporting( E_ALL );

// --- WordPress constants the plugin file reads at class-definition time ---
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ABSPATH', __DIR__ . '/' );

// --- WordPress stubs ---
function add_shortcode( ...$a ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_activation_hook( ...$a ) {}
function get_file_data( ...$a ) { return array( 'Version' => '1.20.13' ); }

$GLOBALS['__options'] = array(
	// Already at the current schema version, so the constructor does not
	// try to dbDelta() - the migration path is covered by
	// tmp/test_app_launch.php against its own upgrade.php stub.
	'v4v_musiclink_pro_log_table_version' => '2',
	'v4v_musiclink_pro_cache_table_version' => '1', // Current schema: no dbDelta run.
	'v4v_musiclink_pro_social_preview_mode' => 'off',
	// Every app disabled so render() skips URL/icon/Fountain work and the
	// assertions below see only the logging behaviour under test.
	'v4v_musiclink_pro_apps' => array(
		'stablekraft' => array( 'enabled' => false, 'icon_url' => '' ),
		'podtunes'    => array( 'enabled' => false, 'icon_url' => '' ),
		'v4vmusic'    => array( 'enabled' => false, 'icon_url' => '' ),
		'fountain'    => array( 'enabled' => false, 'icon_url' => '' ),
	),
);
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d;
}
function update_option( ...$a ) { return true; }
function delete_option( ...$a ) { return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }

// Transient store: a real key/value map, so caching behaves like production.
$GLOBALS['__transients'] = array();
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['__transients'][ $k ] = $v;
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['__transients'][ $k ] );
	return true;
}

function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_html__( $s, $d = null ) { return $s; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( ...$a ) { return 'nonce'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function current_time( $type = 'mysql' ) { return '2026-09-23 12:00:00'; }
function get_date_from_gmt( $ts, $format = 'Y-m-d H:i:s' ) { return $ts; }

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

// add_query_arg( null, null ) => current URI; add_query_arg( array, $url ) => merged.
function add_query_arg( ...$args ) {
	if ( 2 === count( $args ) && is_array( $args[0] ) ) {
		$qs  = $args[0];
		$url = $args[1];
	} elseif ( 3 === count( $args ) ) {
		$qs  = array( $args[0] => $args[1] );
		$url = $args[2];
	} else {
		$qs  = null;
		$url = null;
	}

	$base  = null === $url ? ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) : $url;
	$parts = explode( '?', $base, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
	}
	if ( null !== $qs ) {
		foreach ( $qs as $k => $v ) {
			if ( null === $v ) {
				unset( $query[ $k ] );
			} else {
				$query[ $k ] = $v;
			}
		}
	}
	return $parts[0] . ( $query ? '?' . http_build_query( $query ) : '' );
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	$sep = false !== strpos( $url, '?' ) ? '&' : '?';
	return $url . $sep . rawurlencode( $name ) . '=' . wp_create_nonce( $action );
}

class Nonce_Failed extends Exception {}
class Permission_Denied extends Exception {}
class Redirected extends Exception {
	public $target;
	public function __construct( $url ) {
		$this->target = $url;
		parent::__construct( 'redirected' );
	}
}

$GLOBALS['__nonce_ok']  = true;
$GLOBALS['__can_manage'] = true;

function check_admin_referer( ...$a ) {
	if ( ! $GLOBALS['__nonce_ok'] ) {
		throw new Nonce_Failed( 'nonce check failed' );
	}
	return true;
}
function current_user_can( ...$a ) { return (bool) $GLOBALS['__can_manage']; }
function wp_die( $message = '' ) { throw new Permission_Denied( is_string( $message ) ? $message : 'died' ); }
function wp_safe_redirect( $url ) { throw new Redirected( $url ); }

class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

// --- $wpdb stub: records inserts/queries instead of touching a database ---
class Wpdb_Stub {
	public $prefix   = 'wp_';
	public $inserts  = array();
	public $queries  = array();
	public $results  = array();
	public $var      = 0;

	public function insert( $table, $data, $format = null ) {
		$this->inserts[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
	public function get_results( $sql ) { return $this->results; }
	public function get_row( $sql ) { $this->queries[] = $sql; return null; }
	public function get_var( $sql ) { return $this->var; }
	public function get_charset_collate() { return ''; }
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, ...$args ) { return $sql; }
}
$GLOBALS['wpdb'] = new Wpdb_Stub();

// --- Scripted Music Index HTTP layer (albums and one album's tracks) ---
$GLOBALS['__http_calls'] = array();
$GLOBALS['__feeds']      = array(
	'FEED1' => array(
		'album'  => 'Kind of Blue',
		'artist' => 'Miles Davis',
		'tracks' => array( 'TRK1' => 'So What' ),
	),
	'FEED2' => array(
		'album'  => 'Love Songs',
		'artist' => 'Etta James',
		'tracks' => array(),
	),
);

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__http_calls'][] = $url;

	$parts = parse_url( $url );
	$path  = $parts['path'] ?? '';
	$ok    = function ( $body ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
	};

	if ( preg_match( '#/v1/feeds/([^/]+)(?:/tracks/([^/]+))?$#', $path, $m ) ) {
		$feed  = rawurldecode( $m[1] );
		$track = isset( $m[2] ) ? rawurldecode( $m[2] ) : null;

		if ( ! isset( $GLOBALS['__feeds'][ $feed ] ) ) {
			return new WP_Error();
		}
		$meta = $GLOBALS['__feeds'][ $feed ];

		if ( null === $track ) {
			return $ok( array( 'data' => array(
				'title'          => $meta['album'],
				'release_artist' => $meta['artist'],
				'image_url'      => '',
			) ) );
		}
		if ( ! isset( $meta['tracks'][ $track ] ) ) {
			return new WP_Error();
		}
		return $ok( array( 'data' => array(
			'title'        => $meta['tracks'][ $track ],
			'feed_title'   => $meta['album'],
			'track_artist' => $meta['artist'],
			'image_url'    => '',
		) ) );
	}

	return new WP_Error();
}

require_once dirname( __DIR__ ) . '/v4v-musiclink-pro/v4v-musiclink-pro.php';

// --- Harness helpers ---
$pass = 0;
$fail = 0;
function check( $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		$pass++;
		printf( "  PASS  %-70s\n", $label );
	} else {
		$fail++;
		printf( "  FAIL  %-70s\n         expected: %s\n         actual:   %s\n",
			$label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}
function contains( $label, $haystack, $needle ) {
	check( $label, false !== strpos( (string) $haystack, $needle ), true );
}

$plugin = new V4V_Music_Link_Resolver_Pro();
$wpdb   = $GLOBALS['wpdb'];

function reset_state() {
	global $wpdb;
	$wpdb->inserts = array();
	$wpdb->queries = array();
	$_GET          = array();
	$GLOBALS['__http_calls'] = array();
}
function logged_rows() {
	global $wpdb;
	return $wpdb->inserts;
}
function render_with( $atts, $get = array() ) {
	global $plugin;
	reset_state();
	$_GET = $get;
	return $plugin->render( $atts );
}

echo "\n--- Scope: no page render writes a log row ---\n";

// A. URL-driven album view: rendered, deliberately NOT logged - the open
// was already logged by the search click that generated this URL.
$out = render_with( array(), array( 'album' => 'FEED2' ) );
check( 'A1 URL-driven album view writes no row', count( logged_rows() ), 0 );
contains( 'A2 the release still rendered for the visitor', $out, 'Love Songs' );
contains( 'A3 titles still resolved from Music Index for display', implode( "\n", $GLOBALS['__http_calls'] ), '/v1/feeds/FEED2' );

// B. Album hardcoded into the shortcode attribute: resolved, NOT logged.
$out = render_with( array( 'album' => 'FEED2' ) );
check( 'B1 shortcode album attribute writes no row', count( logged_rows() ), 0 );
contains( 'B2 but the release still rendered', $out, 'Love Songs' );

// C. Mixed: album from the URL, track from a hardcoded attribute -> not logged.
$out = render_with( array( 'track' => 'TRK1' ), array( 'album' => 'FEED1' ) );
check( 'C1 URL album + hardcoded track writes no row', count( logged_rows() ), 0 );
contains( 'C2 but the track still rendered', $out, 'So What' );

// D. Both guids hardcoded: not logged.
render_with( array( 'album' => 'FEED1', 'track' => 'TRK1' ) );
check( 'D1 both guids hardcoded writes no row', count( logged_rows() ), 0 );

// E. URL-driven track view: rendered, deliberately NOT logged either.
$out = render_with( array(), array( 'album' => 'FEED1', 'track' => 'TRK1' ) );
check( 'E1 URL-driven track view writes no row', count( logged_rows() ), 0 );
contains( 'E2 the track still rendered for the visitor', $out, 'So What' );

// F. Unresolved guid: not-found view, never logged (unchanged behaviour).
$out = render_with( array(), array( 'album' => 'NOPE' ) );
check( 'F1 unresolved guid writes no row', count( logged_rows() ), 0 );
contains( 'F2 unresolved guid shows the not-found search view', $out, 'Album/Song not found' );

// G. No guid at all: the search page logs nothing.
render_with( array() );
check( 'G1 bare search page writes no row', count( logged_rows() ), 0 );

echo "\n--- View Log tab: notice + Clear log button ---\n";

function render_settings_for( $get ) {
	global $plugin;
	reset_state();
	$_GET  = $get;
	ob_start();
	$plugin->render_settings_page();
	return ob_get_clean();
}

$wpdb->var    = 5;
$wpdb->results = array();
$page = render_settings_for( array( 'tab' => 'log' ) );
contains( 'H1 Clear log button rendered', $page, 'Clear log' );
contains( 'H2 button targets the clear-log admin-post action', $page, 'action=v4v_musiclink_pro_clear_log' );
contains( 'H3 button carries a nonce', $page, '_wpnonce=' );
contains( 'H4 button asks for confirmation first', $page, 'confirm(' );
contains( 'H5 row count line still shown', $page, '5 total log entries' );

$wpdb->var = 0;
$page      = render_settings_for( array( 'tab' => 'log' ) );
check( 'H6 no Clear log button on an empty log', false !== strpos( $page, 'Clear log' ), false );

$page = render_settings_for( array( 'tab' => 'log', 'cleared' => '1' ) );
contains( 'H7 cleared=1 shows the confirmation notice', $page, 'View log cleared.' );
$page = render_settings_for( array( 'tab' => 'log' ) );
check( 'H8 notice absent on a normal visit', false !== strpos( $page, 'View log cleared.' ), false );

echo "\n--- Clear log handler ---\n";

reset_state();
$GLOBALS['__nonce_ok']   = true;
$GLOBALS['__can_manage'] = true;
$redirect = null;
try {
	$plugin->handle_clear_log();
} catch ( Redirected $e ) {
	$redirect = $e->target;
}
check( 'I1 handler empties the table with DELETE', $wpdb->queries, array( 'DELETE FROM wp_v4v_musiclink_pro_log' ) );
contains( 'I2 returns to the settings page', $redirect, 'options-general.php?page=v4v-musiclink-pro' );
contains( 'I3 returns to the log tab', $redirect, 'tab=log' );
contains( 'I4 carries the notice flag', $redirect, 'cleared=1' );

reset_state();
$GLOBALS['__nonce_ok'] = false;
$threw = null;
try {
	$plugin->handle_clear_log();
} catch ( Nonce_Failed $e ) {
	$threw = 'nonce';
} catch ( Exception $e ) {
	$threw = get_class( $e );
}
check( 'J1 bad nonce aborts', $threw, 'nonce' );
check( 'J2 and touches nothing', $wpdb->queries, array() );

reset_state();
$GLOBALS['__nonce_ok']   = true;
$GLOBALS['__can_manage'] = false;
$threw = null;
try {
	$plugin->handle_clear_log();
} catch ( Permission_Denied $e ) {
	$threw = 'denied';
} catch ( Exception $e ) {
	$threw = get_class( $e );
}
check( 'K1 non-admin aborts', $threw, 'denied' );
check( 'K2 and touches nothing', $wpdb->queries, array() );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
