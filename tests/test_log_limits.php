<?php
/**
 * Standalone verification for the log write-path limits introduced with
 * the opens/launches logging policy:
 *
 *   - settings sanitization: both per-endpoint budgets and the row cap
 *     clamp to sane ranges instead of ever disabling logging or the
 *     flood guard (and the private readers re-clamp defensively);
 *   - the search-result open beacon (ajax_log_open): server-resolved
 *     titles, validation order (nonce -> rate limit -> sanitize ->
 *     resolve), and over-budget rejection with no row and no Music
 *     Index lookup;
 *   - independent budgets per endpoint, keyed on REMOTE_ADDR only;
 *   - window semantics: allowed requests count and refresh the window,
 *     rejected ones never advance the counter;
 *   - retention: prune_log() keeps the newest N rows and runs at most
 *     once per hour, with the admin-configured cap driving the lookup.
 *   - the admin logging switch (v4v_musiclink_pro_log_enabled, default
 *     on): off makes both beacons acknowledge with no row, no budget and
 *     no Music Index call, and log_view() itself refuses as the
 *     write-path choke point.
 *
 * Run:  ./php/php tmp/test_log_limits.php
 */

error_reporting( E_ALL );

// --- WordPress constants the plugin file reads ---
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ABSPATH', __DIR__ . '/' );

// --- WordPress stubs ---
function add_shortcode( ...$a ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_activation_hook( ...$a ) {}
function get_file_data( ...$a ) { return array( 'Version' => '1.20.18' ); }

$GLOBALS['__options'] = array(
	// Already at the current schema version, so the constructor does not
	// try to dbDelta() - the migration path is covered by
	// tmp/test_app_launch.php against its own upgrade.php stub.
	'v4v_musiclink_pro_log_table_version'   => '2',
	'v4v_musiclink_pro_cache_table_version' => '1', // Current schema: no dbDelta run.
	'v4v_musiclink_pro_social_preview_mode' => 'off',
	// Only StableKraft enabled - it needs no HTTP for link building, and
	// this harness only ever beacons with that slug.
	'v4v_musiclink_pro_apps' => array(
		'stablekraft' => array( 'enabled' => true, 'icon_url' => '' ),
		'podtunes'    => array( 'enabled' => false, 'icon_url' => '' ),
		'v4vmusic'    => array( 'enabled' => false, 'icon_url' => '' ),
		'fountain'    => array( 'enabled' => false, 'icon_url' => '' ),
	),
);
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__options'][ $k ] = $v;
	return true;
}
function delete_option( ...$a ) { return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }

// Transient store with TTL bookkeeping: every set_transient() call is
// recorded in order, so window/throttle semantics are assertable.
$GLOBALS['__transients']     = array();
$GLOBALS['__transient_ttls'] = array();
$GLOBALS['__transient_sets'] = array();
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['__transients'][ $k ]     = $v;
	$GLOBALS['__transient_ttls'][ $k ] = $ttl;
	$GLOBALS['__transient_sets'][]     = array( 'key' => $k, 'value' => $v, 'ttl' => $ttl );
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['__transients'][ $k ], $GLOBALS['__transient_ttls'][ $k ] );
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
function current_time( $type = 'mysql' ) { return '2026-09-24 12:00:00'; }
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

$GLOBALS['__ajax_nonce_ok'] = true;

function check_admin_referer( ...$a ) { return true; }
function check_ajax_referer( ...$a ) {
	if ( ! $GLOBALS['__ajax_nonce_ok'] ) {
		throw new Nonce_Failed( 'ajax nonce' );
	}
	return true;
}
function current_user_can( ...$a ) { return true; }
function wp_die( $message = '' ) { throw new Exception( 'wp_die' ); }
function wp_safe_redirect( $url ) { throw new Exception( 'redirect ' . $url ); }

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

// --- $wpdb stub: records inserts/queries/prepare() calls, no database ---
class Wpdb_Stub {
	public $prefix   = 'wp_';
	public $inserts  = array();
	public $queries  = array();
	public $prepared = array();
	public $results  = array();
	public $var      = 0;

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
		// Real wpdb->prepare() interpolates placeholders; the harness keeps
		// the raw SQL and records the bound args so assertions can check
		// both the statement and the value that drove it.
		$this->prepared[] = array( 'sql' => $sql, 'args' => $args );
		return $sql;
	}
}
$GLOBALS['wpdb'] = new Wpdb_Stub();

// --- Scripted Music Index HTTP layer (records every call) ---
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
function call_private( $object, $method, ...$args ) {
	$ref = new ReflectionMethod( get_class( $object ), $method );
	return $ref->invokeArgs( $object, $args );
}
function prepared_like( $needle ) {
	global $wpdb;
	$out = array();
	foreach ( $wpdb->prepared as $entry ) {
		if ( false !== strpos( $entry['sql'], $needle ) ) {
			$out[] = $entry;
		}
	}
	return $out;
}
function reset_state() {
	global $wpdb;
	$wpdb->inserts       = array();
	$wpdb->queries       = array();
	$wpdb->prepared      = array();
	$_POST               = array();
	$_GET                = array();
	$GLOBALS['__http_calls']     = array();
	$GLOBALS['__transient_sets'] = array();
}
function rl_key( $bucket, $ip ) {
	return 'v4v_rl_' . md5( $bucket . '|' . $ip );
}
function rl_hits( $bucket, $ip ) {
	$key = rl_key( $bucket, $ip );
	return isset( $GLOBALS['__transients'][ $key ] ) ? (int) $GLOBALS['__transients'][ $key ] : 0;
}
function clear_rate_buckets() {
	foreach ( array_keys( $GLOBALS['__transients'] ) as $key ) {
		if ( 0 === strpos( $key, 'v4v_rl_' ) ) {
			unset( $GLOBALS['__transients'][ $key ], $GLOBALS['__transient_ttls'][ $key ] );
		}
	}
}
function clear_prune_throttle() {
	unset( $GLOBALS['__transients']['v4v_log_prune'], $GLOBALS['__transient_ttls']['v4v_log_prune'] );
}
function rl_sets() {
	$n = 0;
	foreach ( $GLOBALS['__transient_sets'] as $set ) {
		if ( 0 === strpos( $set['key'], 'v4v_rl_' ) ) {
			$n++;
		}
	}
	return $n;
}
/** Runs ajax_log_open() and returns the wp_send_json_* payload. */
function open_beacon( $post ) {
	global $plugin;
	$_POST = $post;
	try {
		$plugin->ajax_log_open();
	} catch ( Json_Sent $e ) {
		return $e->payload;
	} catch ( Exception $e ) {
		return array( 'thrown' => get_class( $e ) );
	}
	return array( 'thrown' => 'none' );
}
/** Runs ajax_log_app() and returns the wp_send_json_* payload. */
function launch_beacon( $post ) {
	global $plugin;
	$_POST = $post;
	try {
		$plugin->ajax_log_app();
	} catch ( Json_Sent $e ) {
		return $e->payload;
	} catch ( Exception $e ) {
		return array( 'thrown' => get_class( $e ) );
	}
	return array( 'thrown' => 'none' );
}

$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

$plugin = new V4V_Music_Link_Resolver_Pro();
$wpdb   = $GLOBALS['wpdb'];

echo "\n--- Settings sanitization: budgets and row cap clamp ---\n";

check( 'S1 blank budget falls back to 30', $plugin->sanitize_log_rate( '' ), 30 );
check( 'S2 zero budget falls back to 30 (never disables logging)', $plugin->sanitize_log_rate( '0' ), 30 );
check( 'S3 non-numeric budget falls back to 30', $plugin->sanitize_log_rate( 'abc' ), 30 );
check( 'S4 a sane budget passes through', $plugin->sanitize_log_rate( '15' ), 15 );
check( 'S5 the stated maximum passes through', $plugin->sanitize_log_rate( '600' ), 600 );
check( 'S6 budgets above 600/min are clamped', $plugin->sanitize_log_rate( '601' ), 600 );
check( 'S7 runaway budgets are clamped', $plugin->sanitize_log_rate( '9999' ), 600 );
check( 'S8 negative input loses its sign but stays in range', $plugin->sanitize_log_rate( '-9' ), 9 );
check( 'S9 blank row cap falls back to 100000', $plugin->sanitize_log_max_rows( '' ), 100000 );
check( 'S10 zero row cap falls back to 100000', $plugin->sanitize_log_max_rows( '0' ), 100000 );
check( 'S11 row caps below 1000 clamp up', $plugin->sanitize_log_max_rows( '999' ), 1000 );
check( 'S12 the stated minimum passes through', $plugin->sanitize_log_max_rows( '1000' ), 1000 );
check( 'S13 a configured cap passes through', $plugin->sanitize_log_max_rows( '50000' ), 50000 );
check( 'S14 row caps above 1000000 clamp down', $plugin->sanitize_log_max_rows( '1500000' ), 1000000 );

// Defensive re-clamp: options written outside the settings UI can't
// disable logging (0/blank) or remove the flood guard (huge values).
$GLOBALS['__options']['v4v_musiclink_pro_log_app_rate'] = '0';
check( 'S15 app budget option of 0 re-clamps to default 30', call_private( $plugin, 'log_rate_max', 'log_app' ), 30 );
$GLOBALS['__options']['v4v_musiclink_pro_log_app_rate'] = '9999';
check( 'S16 app budget option of 9999 re-clamps to 600', call_private( $plugin, 'log_rate_max', 'log_app' ), 600 );
$GLOBALS['__options']['v4v_musiclink_pro_log_app_rate'] = '45';
check( 'S17 a valid app budget option is honoured', call_private( $plugin, 'log_rate_max', 'log_app' ), 45 );
unset( $GLOBALS['__options']['v4v_musiclink_pro_log_app_rate'] );

$GLOBALS['__options']['v4v_musiclink_pro_log_max_rows'] = '5';
check( 'S18 row cap option below the floor re-clamps to 1000', call_private( $plugin, 'log_max_rows' ), 1000 );
$GLOBALS['__options']['v4v_musiclink_pro_log_max_rows'] = '';
check( 'S19 blank row cap option falls back to 100000', call_private( $plugin, 'log_max_rows' ), 100000 );
unset( $GLOBALS['__options']['v4v_musiclink_pro_log_max_rows'] );
check( 'S20 unset row cap option uses the 100000 default', call_private( $plugin, 'log_max_rows' ), 100000 );

echo "\n--- Open beacon: server-resolved titles, validation order ---\n";

clear_rate_buckets();
reset_state();
$res = open_beacon( array(
	'album'       => 'FEED1',
	'track'       => 'TRK1',
	'artist'      => 'HACKED',
	'album_title' => 'HACKED',
	'track_title' => 'HACKED',
) );
check( 'O1 open beacon reports success', $res['success'], true );
check( 'O2 exactly one row written', count( $wpdb->inserts ), 1 );
check( 'O3 row carries a blank app (an open, not a launch)', $wpdb->inserts[0]['data']['app'], '' );
check( 'O4 titles are resolved server-side from Music Index', array(
	'artist' => $wpdb->inserts[0]['data']['artist'],
	'album'  => $wpdb->inserts[0]['data']['album'],
	'track'  => $wpdb->inserts[0]['data']['track'],
), array( 'artist' => 'Miles Davis', 'album' => 'Kind of Blue', 'track' => 'So What' ) );
check( 'O5 no client-supplied title survives', strpos( json_encode( $wpdb->inserts[0]['data'] ), 'HACKED' ), false );
check( 'O6 no guid is stored anywhere in the row',
	in_array( 'FEED1', $wpdb->inserts[0]['data'], true ) || in_array( 'TRK1', $wpdb->inserts[0]['data'], true ), false );
check( 'O7 the row targets the log table', $wpdb->inserts[0]['table'], 'wp_v4v_musiclink_pro_log' );
check( 'O8 the budget counter advanced for this IP', rl_hits( 'log_open', '198.51.100.10' ), 1 );
check( 'O9 the window was recorded with a one-minute TTL', $GLOBALS['__transient_ttls'][ rl_key( 'log_open', '198.51.100.10' ) ], MINUTE_IN_SECONDS );

reset_state();
$res = open_beacon( array( 'track' => 'TRK1' ) );
check( 'O10 open without an album guid is rejected', $res['success'], false );
check( 'O11 with status 400', $res['code'], 400 );
check( 'O12 and nothing was written', count( $wpdb->inserts ), 0 );
check( 'O13 and Music Index was never asked', count( $GLOBALS['__http_calls'] ), 0 );

reset_state();
$res = open_beacon( array( 'album' => 'NOPE' ) );
check( 'O14 unresolvable open is rejected', $res['success'], false );
check( 'O15 with status 404', $res['code'], 404 );
check( 'O16 and nothing was written', count( $wpdb->inserts ), 0 );

reset_state();
$GLOBALS['__ajax_nonce_ok'] = false;
$res                       = open_beacon( array( 'album' => 'FEED1' ) );
check( 'O17 bad nonce aborts before anything else', isset( $res['thrown'] ) ? $res['thrown'] : null, 'Nonce_Failed' );
check( 'O18 nothing written on a bad nonce', count( $wpdb->inserts ), 0 );
$GLOBALS['__ajax_nonce_ok'] = true;

echo "\n--- Budgets: over-limit rejects with no row and no upstream call ---\n";

clear_rate_buckets();
reset_state();
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$GLOBALS['__options']['v4v_musiclink_pro_log_open_rate'] = 1;

$res = open_beacon( array( 'album' => 'FEED2' ) );
check( 'B1 the first request under a budget of 1 succeeds', $res['success'], true );
check( 'B2 and writes its row', count( $wpdb->inserts ), 1 );
$hits_before = rl_hits( 'log_open', '203.0.113.5' );
$sets_before = rl_sets();
$http_before = count( $GLOBALS['__http_calls'] );

$res = open_beacon( array( 'album' => 'FEED2' ) );
check( 'B3 the next request is rate limited', $res['success'], false );
check( 'B4 with status 429', $res['code'], 429 );
check( 'B5 and writes no row', count( $wpdb->inserts ), 1 );
check( 'B6 and never reaches Music Index', count( $GLOBALS['__http_calls'] ), $http_before );
check( 'B7 a rejected request does not advance the counter', rl_hits( 'log_open', '203.0.113.5' ), $hits_before );
check( 'B8 so hammering never extends the block', rl_sets(), $sets_before );

$res = open_beacon( array() );
check( 'B9 over budget, sanitizing never runs (429, not 400)', $res['code'], 429 );

$res = open_beacon( array( 'album' => 'NOPE' ) );
check( 'B10 over budget, resolving never runs (429, not 404)', $res['code'], 429 );
check( 'B11 and Music Index still sees nothing', count( $GLOBALS['__http_calls'] ), $http_before );

// Budgets are per endpoint: the exhausted open bucket must not starve launches.
$res = launch_beacon( array( 'album' => 'FEED1', 'track' => 'TRK1', 'app' => 'stablekraft' ) );
check( 'B12 launches still log on the same IP and moment', $res['success'], true );
check( 'B13 the launch row carries its app slug', $wpdb->inserts[ count( $wpdb->inserts ) - 1 ]['data']['app'], 'stablekraft' );
check( 'B14 launch budget is tracked in its own bucket', rl_hits( 'log_app', '203.0.113.5' ), 1 );
check( 'B15 open bucket untouched by the launch', rl_hits( 'log_open', '203.0.113.5' ), $hits_before );

echo "\n--- Keying: REMOTE_ADDR only, never the spoofable forwarded header ---\n";

clear_rate_buckets();
reset_state();
$_SERVER['REMOTE_ADDR']          = '192.0.2.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
$GLOBALS['__options']['v4v_musiclink_pro_log_open_rate'] = 1;

$res = open_beacon( array( 'album' => 'FEED2' ) );
check( 'K1 the first request succeeds', $res['success'], true );
check( 'K2 the bucket is keyed on REMOTE_ADDR', isset( $GLOBALS['__transients'][ rl_key( 'log_open', '192.0.2.7' ) ] ), true );
check( 'K3 nothing is keyed on X-Forwarded-For', isset( $GLOBALS['__transients'][ rl_key( 'log_open', '8.8.8.8' ) ] ), false );

$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';
$res = open_beacon( array( 'album' => 'FEED2' ) );
check( 'K4 a different X-Forwarded-For gets no fresh bucket', $res['code'], 429 );

$_SERVER['REMOTE_ADDR'] = '192.0.2.8';
$res = open_beacon( array( 'album' => 'FEED2' ) );
check( 'K5 a genuinely new client IP does', $res['success'], true );

unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';

echo "\n--- Default budget: 30 opens per minute per IP ---\n";

clear_rate_buckets();
reset_state();
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
unset( $GLOBALS['__options']['v4v_musiclink_pro_log_open_rate'] );

$ok = 0;
for ( $i = 0; $i < 30; $i++ ) {
	$res = open_beacon( array( 'album' => 'FEED2' ) );
	if ( ! empty( $res['success'] ) ) {
		$ok++;
	}
}
check( 'D1 the first 30 opens in the window are accepted', $ok, 30 );
check( 'D2 each accepted open wrote its row', count( $wpdb->inserts ), 30 );

$res = open_beacon( array( 'album' => 'FEED2' ) );
check( 'D3 the 31st is rate limited', $res['success'], false );
check( 'D4 with status 429', $res['code'], 429 );
check( 'D5 no row for the rejected one', count( $wpdb->inserts ), 30 );
check( 'D6 counter sits exactly at the budget', rl_hits( 'log_open', '198.51.100.99' ), 30 );

reset_state();
$GLOBALS['__ajax_nonce_ok'] = false;
$res                       = open_beacon( array( 'album' => 'FEED2' ) );
check( 'D7 even over budget, a bad nonce fails on the nonce (not 429)', isset( $res['thrown'] ) ? $res['thrown'] : null, 'Nonce_Failed' );
$GLOBALS['__ajax_nonce_ok'] = true;

echo "\n--- Retention: prune keeps the newest rows, at most hourly ---\n";

reset_state();
clear_prune_throttle();
$wpdb->var = 4242;

call_private( $plugin, 'log_view', 'Miles Davis', 'Kind of Blue', 'So What' );
check( 'P1 the row is written', count( $wpdb->inserts ), 1 );
$offset = prepared_like( 'OFFSET' );
check( 'P2 the size check is a primary-key OFFSET lookup at the configured cap', $offset[0]['args'], array( 100000 ) );
$delete = prepared_like( 'DELETE' );
check( 'P3 rows at or below the cutoff are targeted', $delete[0]['args'], array( 4242 ) );
contains( 'P4 the DELETE prunes by id order', implode( "\n", $wpdb->queries ), 'DELETE FROM wp_v4v_musiclink_pro_log WHERE id <= %d' );
check( 'P5 the hourly throttle is set', get_transient( 'v4v_log_prune' ), 1 );
check( 'P6 with an hour TTL', $GLOBALS['__transient_ttls']['v4v_log_prune'], HOUR_IN_SECONDS );

// Second write inside the hour: no size check, no DELETE.
call_private( $plugin, 'log_view', 'Etta James', 'Love Songs', '' );
check( 'P7 the next row still logs', count( $wpdb->inserts ), 2 );
check( 'P8 but the size check is not repeated within the hour', count( prepared_like( 'OFFSET' ) ), 1 );
check( 'P9 nor is any DELETE issued', count( prepared_like( 'DELETE' ) ), 1 );

// Throttle expired, table at/under the cap: lookup runs, no DELETE.
clear_prune_throttle();
reset_state();
$wpdb->var = 0;
call_private( $plugin, 'log_view', 'Miles Davis', 'Kind of Blue', '' );
check( 'P10 after the hour the size check runs again', count( prepared_like( 'OFFSET' ) ), 1 );
check( 'P11 at or under the cap nothing is deleted', count( prepared_like( 'DELETE' ) ), 0 );

// The admin-configured cap drives the lookup.
clear_prune_throttle();
reset_state();
$wpdb->var = 9999;
$GLOBALS['__options']['v4v_musiclink_pro_log_max_rows'] = 1500;
call_private( $plugin, 'log_view', 'Miles Davis', 'Kind of Blue', '' );
$offset = prepared_like( 'OFFSET' );
check( 'P12 the admin-configured cap drives the lookup', $offset[0]['args'], array( 1500 ) );
$delete = prepared_like( 'DELETE' );
check( 'P13 and the cutoff still prunes old rows', $delete[0]['args'], array( 9999 ) );
unset( $GLOBALS['__options']['v4v_musiclink_pro_log_max_rows'] );

echo "\n--- Admin logging switch: off stops everything, on stays the default ---\n";

// Option never set (fresh install, or a site that never opened settings)
// must keep logging exactly as before.
check( 'T1 logging is on when the option was never set', call_private( $plugin, 'logging_enabled' ), true );

clear_rate_buckets();
reset_state();
$GLOBALS['__options']['v4v_musiclink_pro_log_enabled'] = '0';
check( 'T2 the stored off value reads as disabled', call_private( $plugin, 'logging_enabled' ), false );

$res = open_beacon( array( 'album' => 'FEED1', 'track' => 'TRK1' ) );
check( 'T3 the disabled open beacon still answers success', $res['success'], true );
check( 'T4 but writes no row', count( $wpdb->inserts ), 0 );
check( 'T5 and never reaches Music Index', count( $GLOBALS['__http_calls'] ), 0 );
check( 'T6 and spends no open rate-limit budget', rl_hits( 'log_open', '198.51.100.10' ), 0 );
check( 'T7 and opens no rate-limit window', rl_sets(), 0 );

$res = launch_beacon( array( 'album' => 'FEED1', 'track' => 'TRK1', 'app' => 'stablekraft' ) );
check( 'T8 the disabled launch beacon also answers success', $res['success'], true );
check( 'T9 and writes no row', count( $wpdb->inserts ), 0 );
check( 'T10 the launch bucket stays untouched too', rl_hits( 'log_app', '198.51.100.10' ), 0 );

// Choke point: even a future caller that reaches log_view() directly
// writes nothing, and the retention work never runs either.
call_private( $plugin, 'log_view', 'Miles Davis', 'Kind of Blue', 'So What', 'stablekraft' );
check( 'T11 log_view writes nothing while disabled', count( $wpdb->inserts ), 0 );
check( 'T12 and never runs the prune size check', count( prepared_like( 'OFFSET' ) ), 0 );

// The nonce still authenticates first, switch or no switch.
$GLOBALS['__ajax_nonce_ok'] = false;
$res                       = open_beacon( array( 'album' => 'FEED1' ) );
check( 'T13 even while disabled, a bad nonce fails on the nonce', isset( $res['thrown'] ) ? $res['thrown'] : null, 'Nonce_Failed' );
$GLOBALS['__ajax_nonce_ok'] = true;

// Explicitly switched back on: rows flow again.
$GLOBALS['__options']['v4v_musiclink_pro_log_enabled'] = '1';
check( 'T14 an explicit on value re-enables logging', call_private( $plugin, 'logging_enabled' ), true );
call_private( $plugin, 'log_view', 'Miles Davis', 'Kind of Blue', 'So What', 'stablekraft' );
check( 'T15 log_view writes again once on', count( $wpdb->inserts ), 1 );
unset( $GLOBALS['__options']['v4v_musiclink_pro_log_enabled'] );

clear_rate_buckets();

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
