<?php
/**
 * Standalone verification for app-launch logging and the
 * "Total by Music App" report.
 *
 * Loads the real Pro plugin file against minimal WordPress stubs (with a
 * dbDelta() stub on disk, so the log-table migration is exercised for
 * real) and drives the real methods:
 *   - ajax_log_app(): the beacon behind every app button click;
 *   - ajax_log_open(): the beacon behind a search-result click;
 *   - render()/render_html(): the DOM contract the beacon reads;
 *   - the reports and View Log tab against the new `app` column.
 *
 * Run:  ./php/php tmp/test_app_launch.php
 */

error_reporting( E_ALL );

// --- WordPress constants the plugin file reads at class-definition time ---
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
// ABSPATH points at a stub wp-admin/includes/upgrade.php, so
// create_log_table()'s require_once of dbDelta() resolves inside tests.
define( 'ABSPATH', __DIR__ . '/wpstub/' );

// --- WordPress stubs ---
function add_shortcode( ...$a ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_options_page( ...$a ) {}
function register_activation_hook( ...$a ) {}
function get_file_data( ...$a ) { return array( 'Version' => '1.20.14' ); }

$GLOBALS['__options'] = array(
	'v4v_musiclink_pro_log_table_version'   => '1', // Pre-app column: forces the migration.
	'v4v_musiclink_pro_cache_table_version' => '1', // Already current, so M6 still sees the log migration as the last write.
	'v4v_musiclink_pro_social_preview_mode' => 'off',
	// Only StableKraft enabled - it needs no HTTP (formulaic URL), so the
	// assertions see markup without Fountain/other network chatter.
	'v4v_musiclink_pro_apps' => array(
		'stablekraft' => array( 'enabled' => true, 'icon_url' => '' ),
		'podtunes'    => array( 'enabled' => false, 'icon_url' => '' ),
		'v4vmusic'    => array( 'enabled' => false, 'icon_url' => '' ),
		'fountain'    => array( 'enabled' => false, 'icon_url' => '' ),
	),
);
$GLOBALS['__option_writes'] = array();
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__option_writes'][] = array( $k, $v );
	$GLOBALS['__options'][ $k ]   = $v;
	return true;
}
function delete_option( ...$a ) { return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }

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
function esc_attr__( $s, $d = null ) { return $s; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function current_time( $type = 'mysql' ) { return '2026-09-23 12:00:00'; }
function get_date_from_gmt( $ts, $format = 'Y-m-d H:i:s' ) { return $ts; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
// Signatures mirror WP core: $current defaults to true, so checked(true) is legal.
function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function submit_button( ...$a ) { echo '<input type="submit" />'; }
function settings_fields( ...$a ) {}
function register_setting( $group, $option, $args = array() ) {
	$GLOBALS['__registered_settings'][] = array( 'group' => $group, 'option' => $option, 'args' => $args );
}
function wp_register_style( ...$a ) {}
function wp_enqueue_style( ...$a ) {}
function wp_add_inline_style( ...$a ) {}
function wp_register_script( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function wp_add_inline_script( ...$a ) {}
function wp_localize_script( $handle, $object_name, $l10n ) {
	$GLOBALS['__localized'][ $object_name ] = $l10n;
	return true;
}

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

function add_query_arg( ...$args ) {
	if ( 2 === count( $args ) && is_array( $args[0] ) ) {
		$qs = $args[0]; $url = $args[1];
	} elseif ( 3 === count( $args ) ) {
		$qs = array( $args[0] => $args[1] ); $url = $args[2];
	} else {
		$qs = null; $url = null;
	}
	$base  = null === $url ? ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) : $url;
	$parts = explode( '?', $base, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) { parse_str( $parts[1], $query ); }
	if ( null !== $qs ) {
		foreach ( $qs as $k => $v ) {
			if ( null === $v ) { unset( $query[ $k ] ); } else { $query[ $k ] = $v; }
		}
	}
	return $parts[0] . ( $query ? '?' . http_build_query( $query ) : '' );
}
function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	$sep = false !== strpos( $url, '?' ) ? '&' : '?';
	return $url . $sep . rawurlencode( $name ) . '=' . wp_create_nonce( $action );
}

class Nonce_Failed extends Exception {}
class Died extends Exception {}

$GLOBALS['__nonce_ok']     = true;
$GLOBALS['__ajax_nonce_ok'] = true;
$GLOBALS['__can_manage']   = true;

function check_admin_referer( ...$a ) {
	if ( ! $GLOBALS['__nonce_ok'] ) { throw new Nonce_Failed( 'admin nonce' ); }
	return true;
}
function check_ajax_referer( ...$a ) {
	if ( ! $GLOBALS['__ajax_nonce_ok'] ) { throw new Nonce_Failed( 'ajax nonce' ); }
	return true;
}
function current_user_can( ...$a ) { return (bool) $GLOBALS['__can_manage']; }
function wp_die( $message = '' ) { throw new Died( is_string( $message ) ? $message : 'died' ); }
function wp_safe_redirect( $url ) { throw new Died( 'redirect ' . $url ); }

// wp_send_json_*() terminates the request; model that with an exception.
class Json_Sent extends Exception {
	public $payload;
	public function __construct( $payload ) {
		$this->payload = $payload;
		parent::__construct( 'json-sent' );
	}
}
function wp_send_json_success( $data = null ) { throw new Json_Sent( array( 'success' => true, 'data' => $data ) ); }
function wp_send_json_error( $data = null, $code = null ) { throw new Json_Sent( array( 'success' => false, 'data' => $data, 'code' => $code ) ); }

class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

// --- $wpdb stub: records every write/read instead of touching a database ---
class Wpdb_Stub {
	public $prefix  = 'wp_';
	public $inserts = array();
	public $queries = array();
	public $prepared = array();
	public $results = array();
	public $var     = 0;

	public function insert( $table, $data, $format = null ) {
		$this->inserts[] = array( 'table' => $table, 'data' => $data, 'format' => $format );
		return 1;
	}
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
	public function get_row( $sql ) { $this->queries[] = $sql; return null; }
	public function get_results( $sql ) { $this->queries[] = $sql; return $this->results; }
	public function get_var( $sql ) { $this->queries[] = $sql; return $this->var; }
	public function get_charset_collate() { return ''; }
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, ...$args ) {
		// Real wpdb interpolates the values; keeping them beside the SQL
		// lets a test assert what the query was actually bound to.
		$this->prepared[] = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		return $sql;
	}
}
$GLOBALS['wpdb'] = new Wpdb_Stub();

// --- Scripted Music Index HTTP layer ---
$GLOBALS['__feeds'] = array(
	'FEED1' => array( 'album' => 'Kind of Blue', 'artist' => 'Miles Davis', 'tracks' => array( 'TRK1' => 'So What' ) ),
	'FEED2' => array( 'album' => 'Love Songs', 'artist' => 'Etta James', 'tracks' => array() ),
);
function wp_remote_get( $url, $args = array() ) {
	$parts = parse_url( $url );
	$path  = $parts['path'] ?? '';
	$ok    = function ( $body ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
	};

	if ( preg_match( '#/v1/feeds/([^/]+)(?:/tracks/([^/]+))?$#', $path, $m ) ) {
		$feed  = rawurldecode( $m[1] );
		$track = isset( $m[2] ) ? rawurldecode( $m[2] ) : null;
		if ( ! isset( $GLOBALS['__feeds'][ $feed ] ) ) { return new WP_Error(); }
		$meta = $GLOBALS['__feeds'][ $feed ];
		if ( null === $track ) {
			return $ok( array( 'data' => array( 'title' => $meta['album'], 'release_artist' => $meta['artist'], 'image_url' => '' ) ) );
		}
		if ( ! isset( $meta['tracks'][ $track ] ) ) { return new WP_Error(); }
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
function excludes( $label, $haystack, $needle ) {
	check( $label, false === strpos( (string) $haystack, $needle ), true );
}
function call_private( $object, $method, ...$args ) {
	$ref = new ReflectionMethod( get_class( $object ), $method );
	return $ref->invokeArgs( $object, $args );
}
function captured( $object, $method, ...$args ) {
	ob_start();
	call_private( $object, $method, ...$args );
	return ob_get_clean();
}
/** Runs ajax_log_app() and returns the wp_send_json_* payload. */
function beacon( $post ) {
	global $plugin;
	$_POST = $post;
	try {
		call_private( $plugin, 'ajax_log_app' );
	} catch ( Json_Sent $e ) {
		return $e->payload;
	} catch ( Exception $e ) {
		return array( 'thrown' => get_class( $e ) );
	}
	return array( 'thrown' => 'none' );
}
/** Runs ajax_log_open() and returns the wp_send_json_* payload. */
function open_beacon( $post ) {
	global $plugin;
	$_POST = $post;
	try {
		call_private( $plugin, 'ajax_log_open' );
	} catch ( Json_Sent $e ) {
		return $e->payload;
	} catch ( Exception $e ) {
		return array( 'thrown' => get_class( $e ) );
	}
	return array( 'thrown' => 'none' );
}
function reset_state() {
	global $wpdb;
	$wpdb->inserts = array();
	$wpdb->queries = array();
	$wpdb->prepared = array();
	$_POST = array();
	$_GET  = array();
	// Clear rate-limit buckets so every block starts with a full budget
	// (the budgets themselves are exercised in tmp/test_log_limits.php).
	foreach ( array_keys( $GLOBALS['__transients'] ) as $key ) {
		if ( 0 === strpos( $key, 'v4v_rl_' ) ) {
			unset( $GLOBALS['__transients'][ $key ] );
		}
	}
}

$plugin = new V4V_Music_Link_Resolver_Pro();
$wpdb   = $GLOBALS['wpdb'];

echo "\n--- Schema migration: log table gains the app column ---\n";

$sql_seen = '';
foreach ( $GLOBALS['__dbdelta'] ?? array() as $q ) {
	$sql_seen .= ( is_array( $q ) ? implode( "\n", $q ) : $q ) . "\n";
}
contains( 'M1 the pre-existing table was altered (version gate was 1)', $sql_seen, 'v4v_musiclink_pro_log' );
contains( 'M2 CREATE carries the app column', $sql_seen, 'app VARCHAR(64)' );
contains( 'M3 app column defaults to blank (existing rows = views)', $sql_seen, "app VARCHAR(64) NOT NULL DEFAULT ''" );
contains( 'M4 index on app for the new report', $sql_seen, 'KEY app (app)' );
contains( 'M5 view/log columns all still declared', $sql_seen, 'track VARCHAR(255) NOT NULL DEFAULT' );
check( 'M6 schema version bumped 1 -> 2', end( $GLOBALS['__option_writes'] ), array( 'v4v_musiclink_pro_log_table_version', '2' ) );
check( 'M7 version now short-circuits further dbDelta runs', get_option( 'v4v_musiclink_pro_log_table_version' ), '2' );

echo "\n--- DOM contract the beacon reads ---\n";

reset_state();
$_GET = array( 'album' => 'FEED1', 'track' => 'TRK1' );
$page = $plugin->render( array() );
contains( 'D1 container carries the album guid', $page, 'data-album="FEED1"' );
contains( 'D2 container carries the track guid', $page, 'data-track="TRK1"' );
contains( 'D3 button item carries its app slug', $page, 'data-app="stablekraft"' );
contains( 'D4 button list item class still present', $page, 'v4v-musiclink-pro-item v4v-musiclink-pro-stablekraft' );
check( 'D5 the render itself writes no row (opens are beaconed on the search click)', count( $wpdb->inserts ), 0 );

reset_state();
$page = $plugin->render( array() ); // No guids: the search UI.
excludes( 'D6 search page exposes no guids', $page, 'data-album=' );

echo "\n--- ajax_log_app(): the launch beacon ---\n";

reset_state();
$res = beacon( array( 'album' => 'FEED1', 'track' => 'TRK1', 'app' => 'stablekraft' ) );
check( 'L1 beacon reports success', $res['success'], true );
check( 'L2 exactly one row written', count( $wpdb->inserts ), 1 );
check( 'L3 row carries the app slug', $wpdb->inserts[0]['data']['app'], 'stablekraft' );
check( 'L4 row carries the resolved titles', array(
	'artist' => $wpdb->inserts[0]['data']['artist'],
	'album'  => $wpdb->inserts[0]['data']['album'],
	'track'  => $wpdb->inserts[0]['data']['track'],
), array( 'artist' => 'Miles Davis', 'album' => 'Kind of Blue', 'track' => 'So What' ) );
check( 'L5 row is timestamped like a view row', $wpdb->inserts[0]['data']['logged_at'], '2026-09-23 12:00:00' );
check( 'L6 row targets the same log table', $wpdb->inserts[0]['table'], 'wp_v4v_musiclink_pro_log' );
check( 'L7 all five columns use the string format', $wpdb->inserts[0]['format'], array( '%s', '%s', '%s', '%s', '%s' ) );
check( 'L8 no guid stored anywhere in the row', in_array( 'FEED1', $wpdb->inserts[0]['data'], true ) || in_array( 'TRK1', $wpdb->inserts[0]['data'], true ), false );

reset_state();
$res = beacon( array( 'album' => 'FEED2', 'app' => 'stablekraft' ) );
check( 'L9 album-only launch still logs', count( $wpdb->inserts ), 1 );
check( 'L10 with a blank track', $wpdb->inserts[0]['data']['track'], '' );
check( 'L11 and the album artist', $wpdb->inserts[0]['data']['artist'], 'Etta James' );

reset_state();
$res = beacon( array( 'album' => 'FEED1', 'track' => 'TRK1', 'app' => 'notanapp', 'artist' => 'HACKED' ) );
check( 'L12 app outside the registry is rejected', $res['success'], false );
check( 'L13 with status 400', $res['code'], 400 );
check( 'L14 and writes nothing', count( $wpdb->inserts ), 0 );

reset_state();
$res = beacon( array( 'track' => 'TRK1', 'app' => 'stablekraft' ) );
check( 'L15 no album guid is rejected', $res['success'], false );
check( 'L16 and writes nothing', count( $wpdb->inserts ), 0 );

reset_state();
$res = beacon( array( 'album' => 'NOPE', 'app' => 'stablekraft' ) );
check( 'L17 unresolvable release is rejected', $res['success'], false );
check( 'L18 with status 404', $res['code'], 404 );
check( 'L19 and writes nothing', count( $wpdb->inserts ), 0 );

reset_state();
$GLOBALS['__ajax_nonce_ok'] = false;
$res                       = beacon( array( 'album' => 'FEED1', 'app' => 'stablekraft' ) );
check( 'L20 bad nonce aborts', isset( $res['thrown'] ) ? $res['thrown'] : null, 'Nonce_Failed' );
check( 'L21 before anything is written', count( $wpdb->inserts ), 0 );
$GLOBALS['__ajax_nonce_ok'] = true;

reset_state();
$res = beacon( array(
	'album'       => 'FEED1',
	'app'         => 'stablekraft',
	'artist'      => 'HACKED',
	'album_title' => 'HACKED',
	'track_title' => 'HACKED',
) );
check( 'L22 client-supplied titles are ignored', $wpdb->inserts[0]['data']['artist'], 'Miles Davis' );
check( 'L23 stored album comes from Music Index', $wpdb->inserts[0]['data']['album'], 'Kind of Blue' );
check( 'L24 nothing the client sent survives', strpos( json_encode( $wpdb->inserts[0]['data'] ), 'HACKED' ), false );

echo "\n--- ajax_log_open(): the search-result open beacon ---\n";

reset_state();
$res = open_beacon( array( 'album' => 'FEED1', 'track' => 'TRK1', 'artist' => 'HACKED' ) );
check( 'O1 open beacon reports success', $res['success'], true );
check( 'O2 exactly one row written', count( $wpdb->inserts ), 1 );
check( 'O3 row has a blank app (an open, not a launch)', $wpdb->inserts[0]['data']['app'], '' );
check( 'O4 titles come from Music Index, not the client', array(
	'artist' => $wpdb->inserts[0]['data']['artist'],
	'album'  => $wpdb->inserts[0]['data']['album'],
	'track'  => $wpdb->inserts[0]['data']['track'],
), array( 'artist' => 'Miles Davis', 'album' => 'Kind of Blue', 'track' => 'So What' ) );
check( 'O5 no guid stored anywhere in the row', in_array( 'FEED1', $wpdb->inserts[0]['data'], true ) || in_array( 'TRK1', $wpdb->inserts[0]['data'], true ), false );
check( 'O6 client-supplied titles are ignored', strpos( json_encode( $wpdb->inserts[0]['data'] ), 'HACKED' ), false );

reset_state();
$res = open_beacon( array( 'track' => 'TRK1' ) );
check( 'O7 open without an album guid is rejected', $res['success'], false );
check( 'O8 with status 400', $res['code'], 400 );
check( 'O9 and writes nothing', count( $wpdb->inserts ), 0 );

reset_state();
$res = open_beacon( array( 'album' => 'NOPE' ) );
check( 'O10 unresolvable open is rejected', $res['success'], false );
check( 'O11 with status 404', $res['code'], 404 );
check( 'O12 and writes nothing', count( $wpdb->inserts ), 0 );

reset_state();
$GLOBALS['__ajax_nonce_ok'] = false;
$res                       = open_beacon( array( 'album' => 'FEED1' ) );
check( 'O13 bad nonce aborts the open beacon', isset( $res['thrown'] ) ? $res['thrown'] : null, 'Nonce_Failed' );
check( 'O14 before anything is written', count( $wpdb->inserts ), 0 );
$GLOBALS['__ajax_nonce_ok'] = true;

echo "\n--- Report: Total by Music App ---\n";

reset_state();
$wpdb->results = array( (object) array( 'label' => 'stablekraft', 'total' => 7 ) );
$_GET          = array( 'report_type' => 'app', 'date_range' => 'all' );
$html          = captured( $plugin, 'render_reports_tab_content' );
$sql           = implode( "\n", $wpdb->queries );
contains( 'R1 report is offered in the picker', $html, 'Total by Music App' );
contains( 'R2 table is headed Music App', $html, '>Music App<' );
contains( 'R3 counts launches, not views', $html, 'Total Launches' );
contains( 'R4 only launch rows are counted', $sql, "app != ''" );
contains( 'R5 grouped by app', $sql, 'GROUP BY app' );
excludes( 'R6 view rows kept out of the launch report', $sql, "app = ''" );
contains( 'R7 slug rendered as its display label', $html, 'StableKraft.app' );
contains( 'R8 total rendered', $html, '>7</td>' );

reset_state();
$wpdb->results = array();
$_GET          = array( 'report_type' => 'app', 'date_range' => 'all' );
$html          = captured( $plugin, 'render_reports_tab_content' );
contains( 'R9 empty launch report explains itself', $html, 'No matching data.' );
contains( 'R10 colspan matches the 2 columns shown', $html, 'colspan="2"' );

reset_state();
$wpdb->results = array( (object) array( 'label' => 'retired-app', 'total' => 3 ) );
$_GET          = array( 'report_type' => 'app', 'date_range' => 'all' );
$html          = captured( $plugin, 'render_reports_tab_content' );
contains( 'R11 slug no longer in the registry still renders', $html, 'retired-app' );

foreach ( array( 'artist', 'album', 'track' ) as $type ) {
	reset_state();
	$wpdb->results = array();
	$_GET          = array( 'report_type' => $type, 'date_range' => 'all' );
	captured( $plugin, 'render_reports_tab_content' );
	$sql = implode( "\n", $wpdb->queries );
	contains( "R12 Total by {$type} still counts views only", $sql, "app = ''" );
	excludes( "R13 Total by {$type} excludes launch rows", $sql, "app != ''" );
}

reset_state();
$_GET = array( 'report_type' => 'nonsense', 'date_range' => 'all' );
$html = captured( $plugin, 'render_reports_tab_content' );
contains( 'R14 unknown report type still falls back to artist', $html, '>Artist<' );

echo "\n--- Report: calendar-month date ranges ---\n";

// Expected bounds derived independently of the implementation: UTC (the
// harness stubs wp_timezone() to UTC), 1st at midnight to 1st at midnight.
$month_start  = gmdate( 'Y-m-01 00:00:00' );
$month_end    = gmdate( 'Y-m-01 00:00:00', strtotime( gmdate( 'Y-m-01' ) . ' +1 month' ) );
$prev_start   = gmdate( 'Y-m-01 00:00:00', strtotime( gmdate( 'Y-m-01' ) . ' -1 month' ) );
$today_start  = gmdate( 'Y-m-d 00:00:00' );

reset_state();
$bounds = call_private( $plugin, 'get_report_date_bounds', 'this_month', '', '' );
check( 'R15 This month spans the current calendar month', $bounds, array( $month_start, $month_end ) );

reset_state();
$bounds = call_private( $plugin, 'get_report_date_bounds', 'last_month', '', '' );
check( 'R16 Last month spans the previous calendar month', $bounds, array( $prev_start, $month_start ) );

reset_state();
$bounds = call_private( $plugin, 'get_report_date_bounds', 'today', '', '' );
check( 'R17 Today still spans just today', $bounds, array( $today_start, gmdate( 'Y-m-d 00:00:00', strtotime( gmdate( 'Y-m-d' ) . ' +1 day' ) ) ) );

// The bounds have to reach the query, not just the helper. The stub's
// prepare() keeps the bound values beside the raw SQL it returns.
$bound_values = function () {
	global $wpdb;
	$vals = array();
	foreach ( $wpdb->prepared as $a ) { $vals = array_merge( $vals, array_values( $a ) ); }
	return $vals;
};

reset_state();
$wpdb->results = array();
$_GET          = array( 'report_type' => 'artist', 'date_range' => 'this_month' );
captured( $plugin, 'render_reports_tab_content' );
$sql = implode( "\n", $wpdb->queries );
contains( 'R18 This month bounds the start of logged_at', $sql, 'logged_at >= %s' );
contains( 'R19 This month bounds the end of logged_at', $sql, 'logged_at < %s' );
$vals = $bound_values();
check( 'R18b This month binds the calendar-month bounds', $vals, array( $month_start, $month_end ) );

reset_state();
$wpdb->results = array();
$_GET          = array( 'report_type' => 'artist', 'date_range' => 'last_month' );
captured( $plugin, 'render_reports_tab_content' );
$sql = implode( "\n", $wpdb->queries );
contains( 'R20 Last month still filters logged_at', $sql, 'logged_at >= %s' );
$vals = $bound_values();
check( 'R20b Last month binds the previous calendar-month bounds', $vals, array( $prev_start, $month_start ) );

reset_state();
$wpdb->results = array();
$_GET          = array( 'report_type' => 'artist', 'date_range' => 'this_month' );
$html          = captured( $plugin, 'render_reports_tab_content' );
contains( 'R22 This month offered in the picker', $html, 'value="this_month"' );
contains( 'R23 This month labelled', $html, '>This month<' );
contains( 'R24 Last month offered in the picker', $html, 'value="last_month"' );
contains( 'R25 Last month labelled', $html, '>Last month<' );
check( 'R26 calendar presets sit between Today and the rolling windows',
	strpos( $html, 'value="today"' ) < strpos( $html, 'value="this_month"' )
	&& strpos( $html, 'value="this_month"' ) < strpos( $html, 'value="last_month"' )
	&& strpos( $html, 'value="last_month"' ) < strpos( $html, 'value="7days"' ), true );

echo "\n--- View Log tab ---\n";

reset_state();
$wpdb->var     = 9;
$wpdb->results = array(
	(object) array( 'logged_at' => '2026-09-23 10:00:00', 'artist' => 'Miles Davis', 'album' => 'Kind of Blue', 'track' => '', 'app' => '' ),
	(object) array( 'logged_at' => '2026-09-23 11:00:00', 'artist' => 'Miles Davis', 'album' => 'Kind of Blue', 'track' => 'So What', 'app' => 'stablekraft' ),
);
$_GET  = array( 'tab' => 'log' );
$html  = captured( $plugin, 'render_log_tab_content' );
$w     = $wpdb->queries;
contains( 'V1 SELECT pulls the app column', implode( "\n", $w ), 'artist, album, track, app' );
contains( 'V2 App column added to the table', $html, '>App<' );
check( 'V3 view row shows em dashes for blank track and blank app', substr_count( $html, '>—</td>' ), 2 );
contains( 'V4 a launch row shows the app label', $html, 'StableKraft.app' );
contains( 'V5 count line says log entries now', $html, '9 total log entries' );
contains( 'V7 Clear log button still offered', $html, 'Clear log' );

$wpdb->results = array();
$html          = captured( $plugin, 'render_log_tab_content' );
contains( 'V6 empty-row colspan covers the new column', $html, 'colspan="5"' );
contains( 'V8 empty log still offers the clear button logic path', $html, 'No views logged yet.' );

echo "\n--- Settings: log budget + row-cap inputs and registration ---\n";

reset_state();
$_GET = array( 'tab' => 'settings' );
$html = captured( $plugin, 'render_settings_page' );
contains( 'U1 app-launch budget input offered', $html, 'id="v4v_musiclink_pro_log_app_rate"' );
contains( 'U2 open budget input offered', $html, 'id="v4v_musiclink_pro_log_open_rate"' );
contains( 'U3 row-cap input offered', $html, 'id="v4v_musiclink_pro_log_max_rows"' );
contains( 'U4 budgets capped at 600/min in the markup', $html, 'max="600"' );
contains( 'U5 app budget default of 30 shown', $html, 'value="30"' );
contains( 'U6 row cap defaults to 100000', $html, 'value="100000"' );
contains( 'U7 row cap floor in the markup', $html, 'min="1000"' );

$GLOBALS['__registered_settings'] = array();
$plugin->register_settings();
$by_option = array();
foreach ( $GLOBALS['__registered_settings'] as $s ) {
	$by_option[ $s['option'] ] = $s;
}
check( 'U8 app budget registered in the plugin group', isset( $by_option['v4v_musiclink_pro_log_app_rate'] ) ? $by_option['v4v_musiclink_pro_log_app_rate']['group'] : null, 'v4v_musiclink_pro' );
check( 'U9 app budget sanitized by sanitize_log_rate', $by_option['v4v_musiclink_pro_log_app_rate']['args']['sanitize_callback'], array( $plugin, 'sanitize_log_rate' ) );
check( 'U10 open budget sanitized the same way', $by_option['v4v_musiclink_pro_log_open_rate']['args']['sanitize_callback'], array( $plugin, 'sanitize_log_rate' ) );
check( 'U11 row cap sanitized by sanitize_log_max_rows', $by_option['v4v_musiclink_pro_log_max_rows']['args']['sanitize_callback'], array( $plugin, 'sanitize_log_max_rows' ) );
check( 'U12 app budget default of 30 registered', $by_option['v4v_musiclink_pro_log_app_rate']['args']['default'], 30 );
check( 'U13 open budget default of 30 registered', $by_option['v4v_musiclink_pro_log_open_rate']['args']['default'], 30 );
check( 'U14 row cap default of 100000 registered', $by_option['v4v_musiclink_pro_log_max_rows']['args']['default'], 100000 );
check( 'U15 the pre-existing settings are still registered', isset( $by_option['v4v_musiclink_pro_apps'] ), true );

echo "\n--- Beacon wiring ---\n";

reset_state();
$plugin->enqueue_assets();
$loc = $GLOBALS['__localized']['v4vMusicLinkPro'];
check( 'N1 search nonce is still handed to the page', $loc['nonce'], 'nonce-v4v_musiclink_pro_search' );
check( 'N2 a separate launch-beacon nonce is handed over', $loc['logNonce'], 'nonce-v4v_musiclink_pro_log_app' );
$js = call_private( $plugin, 'inline_js' );
contains( 'N3 beacon posts to the launch action', $js, 'v4v_musiclink_pro_log_app' );
contains( 'N4 beacon uses the launch nonce', $js, 'v4vMusicLinkPro.logNonce' );
contains( 'N5 beacon reads the guids off the container', $js, 'getAttribute("data-album")' );
contains( 'N6 a bare Fountain link returns before logging', $js, 'Nothing has launched yet' );
contains( 'N7 the search-result click beacons the open action', $js, 'v4v_musiclink_pro_log_open' );
contains( 'N8 the open beacon reuses the log nonce', $js, 'v4vMusicLinkPro.logNonce' );
$open_at = strpos( $js, 'v4v_musiclink_pro_log_open' );
$nav_at  = strpos( $js, 'window.location.href = url.toString()' );
check( 'N9 the open beacon is sent before navigating away', false !== $open_at && false !== $nav_at && $open_at < $nav_at, true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
