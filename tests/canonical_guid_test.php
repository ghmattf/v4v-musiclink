<?php
/**
 * Behavioural test for the current security round:
 *
 *  #6  og:url (both social-preview modes) and the Share button build from
 *      musiclink_canonical_url() instead of the raw request URI: one URL
 *      for canonical/og:url/Share with junk query args dropped, and the
 *      helper returns it UNESCAPED so every consumer (core's
 *      rel_canonical(), this plugin's output sites) escapes exactly once.
 *
 *  #3  Every guid intake point drops input over 256 bytes as if the guid
 *      were absent - Pro query args, both log-beacon POSTs, and both
 *      plugins' shortcode attributes - LENGTH ONLY: no charset
 *      whitelist, so free-form Music Index ids (track_01-they're-right!)
 *      pass through untouched.
 *
 * The esc_url() stub below is entity-aware and idempotent like core's
 * (bare & -> &#038;, existing entities left alone), so output assertions
 * model what a browser really gets.
 *
 * Run:  ./php/php tmp/canonical_guid_test.php
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'ABSPATH', '/tmp/' );

// --- WordPress stubs (same shape as the sibling harnesses) ---
function add_shortcode( ...$a ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_options_page( ...$a ) {}
function register_activation_hook( ...$a ) {}
function register_setting( ...$a ) {}
function get_file_data( ...$a ) { return array( 'Version' => '1.20.20' ); }

$GLOBALS['__options'] = array(
	'v4v_musiclink_pro_log_table_version' => '2', // Current schema: no dbDelta run.
	'v4v_musiclink_cache_table_version' => '1', // Current schema: no dbDelta run.
	'v4v_musiclink_pro_cache_table_version' => '1', // Current schema: no dbDelta run.
	// Only formulaic apps enabled: no Fountain self-heal network chatter
	// during renders.
	'v4v_musiclink_pro_apps' => array(
		'stablekraft' => array( 'enabled' => true, 'icon_url' => '' ),
		'podtunes'    => array( 'enabled' => false, 'icon_url' => '' ),
		'v4vmusic'    => array( 'enabled' => true, 'icon_url' => '' ),
		'fountain'    => array( 'enabled' => false, 'icon_url' => '' ),
	),
	'v4v_musiclink_apps' => array(
		'stablekraft' => array( 'enabled' => true, 'icon_url' => '' ),
		'podtunes'    => array( 'enabled' => false, 'icon_url' => '' ),
		'v4vmusic'    => array( 'enabled' => true, 'icon_url' => '' ),
		'fountain'    => array( 'enabled' => false, 'icon_url' => '' ),
	),
);
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( ...$a ) { return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }

$GLOBALS['__transients'] = array();
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function esc_url_raw( $s ) { return (string) $s; }
/**
 * Entity-aware, idempotent - the parts of core's display-context esc_url()
 * that matter here: a bare & becomes &#038;, an already-escaped &#038; is
 * left alone, an apostrophe becomes &#039;.
 */
function esc_url( $s ) {
	$s = (string) $s;
	$s = str_replace( '&amp;', '&#038;', $s );
	$s = preg_replace( '/&(?!#[0-9]+;|[a-zA-Z][a-zA-Z0-9]+;)/', '&#038;', $s );
	$s = str_replace( "'", '&#039;', $s );
	return $s;
}
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function current_time( $type = 'mysql' ) { return '2026-09-25 12:00:00'; }
function get_date_from_gmt( $ts, $format = 'Y-m-d H:i:s' ) { return $ts; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function get_the_title( $post = null ) { return 'See Through by Fentlay'; }
function get_bloginfo( $show = '' ) { return 'phafe.com'; }
function get_theme_mod( $k, $d = false ) { return $d; }
function get_site_icon_url( $size = 512, $post = 0 ) { return ''; }
function has_shortcode( $content, $tag = '' ) { return $tag && false !== strpos( (string) $content, '[' . $tag ); }
// WP-core parity: 2nd param defaults to true.
function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }

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

class Nonce_Failed extends Exception {}
$GLOBALS['__ajax_nonce_ok'] = true;
function check_admin_referer( ...$a ) { return true; }
function check_ajax_referer( ...$a ) {
	if ( ! $GLOBALS['__ajax_nonce_ok'] ) { throw new Nonce_Failed( 'ajax nonce' ); }
	return true;
}

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
function wp_remote_retrieve_response_code( $r ) { return is_array($r) && isset($r['response']['code']) ? (int) $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }

// Every Music Index call is counted: guard tests assert the counter stays
// at 0. Successes are served from the seeded cache rows below, so the
// counter only ever moves when a lookup was actually attempted.
$GLOBALS['__mi_calls'] = 0;
function wp_remote_get( $url, $args = array() ) {
	$parts = parse_url( $url );
	if ( isset( $parts['host'] ) && 'api.musicindex.org' === $parts['host'] ) {
		$GLOBALS['__mi_calls']++;
	}
	return new WP_Error(); // Unknown lookup: fails fast and negative-caches.
}
function wp_remote_post( $url, $args = array() ) {
	$parts = parse_url( $url );
	if ( isset( $parts['host'] ) && 'api.musicindex.org' === $parts['host'] ) {
		$GLOBALS['__mi_calls']++;
	}
	return new WP_Error(); // Fountain self-heal fails fast -> plain link.
}

// --- $wpdb stub: records writes instead of touching a database ---
class Wpdb_Stub {
	public $prefix     = 'wp_';
	public $inserts    = array();
	public $queries    = array();
	public $results    = array();
	public $var        = 0;
	public $cache_rows = array();
	public $last_hash  = null;
	public function insert( $table, $data, $format = null ) {
		$this->inserts[] = array( 'table' => $table, 'data' => $data, 'format' => $format );
		return 1;
	}
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
	public function get_results( $sql ) { $this->queries[] = $sql; return $this->results; }
	public function get_var( $sql ) { $this->queries[] = $sql; return $this->var; }
	public function get_row( $sql ) {
		$this->queries[] = $sql;
		$hash = $this->last_hash;
		return ( null !== $hash && isset( $this->cache_rows[ $hash ] ) ) ? $this->cache_rows[ $hash ] : null;
	}
	public function get_charset_collate() { return ''; }
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, ...$args ) {
		// prepare() here does not interpolate, so the hash the cache
		// lookup binds is captured from the arguments instead.
		if ( false !== strpos( $sql, 'lookup_hash = %s' ) && isset( $args[0] ) ) {
			$this->last_hash = (string) $args[0];
		}
		return $sql;
	}
}
$GLOBALS['wpdb'] = new Wpdb_Stub();

class WP_Post {
	public $post_content = '';
	public $post_title   = '';
}
$GLOBALS['post'] = new WP_Post();
$GLOBALS['post']->post_content = '[musiclink_pro album="FEED1"]';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['REQUEST_URI'] = '/musiclink';

// --- The real plugin files ---
require_once dirname( __DIR__ ) . '/v4v-musiclink-pro/v4v-musiclink-pro.php';
require_once dirname( __DIR__ ) . '/v4v-musiclink/v4v-musiclink.php';

// --- Harness helpers ---
$pass = 0;
$fail = 0;
function check( $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		$pass++;
		printf( "  PASS  %-72s\n", $label );
	} else {
		$fail++;
		printf( "  FAIL  %-72s\n         expected: %s\n         actual:   %s\n",
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
/** Runs ajax_log_app() and returns the wp_send_json_* payload. */
function beacon( $post ) {
	global $pro;
	$_POST = $post;
	try {
		call_private( $pro, 'ajax_log_app' );
	} catch ( Json_Sent $e ) {
		return $e->payload;
	} catch ( Exception $e ) {
		return array( 'thrown' => get_class( $e ) );
	}
	return array( 'thrown' => 'none' );
}
/** Runs ajax_log_open() and returns the wp_send_json_* payload. */
function open_beacon( $post ) {
	global $pro;
	$_POST = $post;
	try {
		call_private( $pro, 'ajax_log_open' );
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
	$_POST = array();
	$_GET  = array();
	$_SERVER['REQUEST_URI'] = '/musiclink';
	$GLOBALS['__mi_calls']  = 0;
	foreach ( array_keys( $GLOBALS['__transients'] ) as $key ) {
		if ( 0 === strpos( $key, 'v4v_rl_' ) ) {
			unset( $GLOBALS['__transients'][ $key ] );
		}
	}
}

/**
 * Seeds one cache row (the O12 storage both plugins read). $mi supplies
 * the MI half; the Fountain half stays empty, so link resolution still
 * exercises its own path. mi_updated_at is stamped well into the future
 * so no render ever queues a background refresh behind these rows.
 */
function seed_cache_row( $album, $track, array $mi ) {
	global $wpdb;
	$hash        = sha1( $album . '|' . $track );
	$wpdb->cache_rows[ $hash ] = (object) array(
		'id'                  => count( $wpdb->cache_rows ) + 1,
		'lookup_hash'         => $hash,
		'album_guid'          => $album,
		'track_guid'          => $track,
		'album_title'         => $mi['album_title'] ?? '',
		'track_title'         => $mi['track_title'] ?? '',
		'artist'              => $mi['artist'] ?? '',
		'image_url'           => $mi['image'] ?? '',
		'fountain_url'        => '',
		'mi_updated_at'       => '2099-01-01 00:00:00',
		'fountain_updated_at' => '',
	);
}

// --- Fixtures ---
$album = 'FEED1';
$track = 'TRK1';
$free  = "track_01-they're-right!"; // The free-form Music Index id from the review.

seed_cache_row( $album, '', array( 'album_title' => 'Kind of Blue', 'artist' => 'Miles Davis' ) );
seed_cache_row( $album, $track, array( 'album_title' => 'Kind of Blue', 'track_title' => 'So What', 'artist' => 'Miles Davis' ) );
seed_cache_row( $free, '', array( 'album_title' => 'Free Form Album', 'artist' => 'Free Artist' ) );

$pro = new V4V_Music_Link_Resolver_Pro();
$std = new V4V_Music_Link_Resolver();
$wpdb = $GLOBALS['wpdb'];

echo "\n--- #6: one URL for canonical/og:url/Share, escaped once ---\n";

reset_state();
$_SERVER['REQUEST_URI'] = '/musiclink?album=FEED1&track=TRK1&junk=evil';
$_GET = array( 'album' => $album, 'track' => $track, 'junk' => 'evil' );

$canonical = call_private( $pro, 'musiclink_canonical_url' );
check( 'C1 helper returns the raw canonical URL (junk dropped, bare &)', $canonical, 'https://example.test/musiclink?album=FEED1&track=TRK1' );
check( 'C2 get_canonical_url filter hands core an unescaped URL', $pro->fix_canonical_url( 'https://old.example/other', $GLOBALS['post'] ), $canonical );

$out = $pro->rewrite_canonical_in_html( '<html><head><link rel="canonical" href="https://old.example/" /></head><body>x</body></html>' );
contains( 'C3 buffer rewrite spits the same URL out escaped exactly once', $out, 'href="https://example.test/musiclink?album=FEED1&#038;track=TRK1"' );
excludes( 'C4 ...and never as an entity of an entity', $out, '&amp;#038;' );

ob_start();
$pro->output_social_preview_meta();
$og = ob_get_clean();
contains( 'C5 plugin-only og:url is the canonical URL, escaped once', $og, '<meta property="og:url" content="https://example.test/musiclink?album=FEED1&#038;track=TRK1"' );
excludes( 'C6 plugin-only og:url carries no raw request junk args', $og, 'junk=evil' );

$site = $pro->rewrite_all_meta_in_html( '<html><head><title>Old</title><link rel="canonical" href="https://old.example/" /><meta property="og:url" content="https://old.example/" /></head><body>x</body></html>' );
contains( 'C7 entire-site og:url rewritten to the canonical URL', $site, '<meta property="og:url" content="https://example.test/musiclink?album=FEED1&#038;track=TRK1"' );
excludes( 'C8 entire-site og:url carries no raw request junk args', $site, 'junk=evil' );
contains( 'C9 entire-site <title> rewrite still works alongside', $site, '<title>"So What" from Kind of Blue</title>' );

$page = $pro->render( array() );
contains( 'C10 Share data-url is the same canonical URL, escaped once', $page, 'data-url="https://example.test/musiclink?album=FEED1&#038;track=TRK1"' );
excludes( 'C11 Share button no longer shares raw request junk args', $page, 'junk=evil' );

echo "\n--- #3: length-only guid guard (no charset whitelist) ---\n";

reset_state();
check( 'G1 a 256-byte guid passes untouched', call_private( $pro, 'guard_guid', str_repeat( 'a', 256 ) ), str_repeat( 'a', 256 ) );
check( 'G2 a 257-byte guid is dropped', call_private( $pro, 'guard_guid', str_repeat( 'a', 257 ) ), '' );
check( 'G3 a free-form id survives the guard verbatim', call_private( $pro, 'guard_guid', $free ), $free );

$_GET = array( 'album' => str_repeat( 'z', 300 ) );
check( 'G4 an overlong query arg reads as absent', call_private( $pro, 'get_guid_arg', 'album' ), '' );
$_GET = array( 'album' => $free );
check( 'G5 a free-form query arg reads back verbatim', call_private( $pro, 'get_guid_arg', 'album' ), $free );

reset_state();
$_SERVER['REQUEST_URI'] = '/musiclink?album=' . str_repeat( 'z', 300 );
$_GET = array( 'album' => str_repeat( 'z', 300 ) );
check( 'G6 canonical URL drops an overlong guid entirely', call_private( $pro, 'musiclink_canonical_url' ), 'https://example.test/musiclink' );

reset_state();
$page = $pro->render( array( 'album' => str_repeat( 'z', 300 ) ) );
contains( 'G7 overlong attribute falls back to the search UI', $page, 'v4v-musiclink-pro-search-input' );
excludes( 'G8 ...without a not-found lookup having been attempted', $page, 'Album/Song not found' );
check( 'G9 no Music Index call was made', $GLOBALS['__mi_calls'], 0 );

reset_state();
$_GET = array( 'album' => str_repeat( 'z', 300 ) );
$fields = call_private( $pro, 'compute_preview_fields', $GLOBALS['post'] );
check( 'G10 preview fields fall back for an overlong guid', $fields['title'], 'See Through by Fentlay' );
check( 'G11 ...with no Music Index call', $GLOBALS['__mi_calls'], 0 );

reset_state();
$res = open_beacon( array( 'album' => str_repeat( 'z', 300 ), 'track' => '' ) );
check( 'G12 the open beacon rejects an overlong guid with 400', $res['code'], 400 );
check( 'G13 ...before any Music Index lookup', $GLOBALS['__mi_calls'], 0 );
check( 'G14 ...and writes no log row', count( $wpdb->inserts ), 0 );

reset_state();
$res = beacon( array( 'album' => str_repeat( 'z', 300 ), 'track' => '', 'app' => 'stablekraft' ) );
check( 'G15 the launch beacon rejects an overlong guid', $res['success'], false );
check( 'G16 ...and writes no log row', count( $wpdb->inserts ), 0 );

reset_state();
$res = open_beacon( array( 'album' => $free, 'track' => '' ) );
check( 'G17 a free-form id passes the guard end to end', $res['success'], true );
check( 'G18 exactly one row logged', count( $wpdb->inserts ), 1 );
check( 'G19 the row holds server-resolved titles, not the guid', $wpdb->inserts[0]['data']['album'], 'Free Form Album' );

echo "\n--- #3 standard plugin: same guard on shortcode attributes ---\n";

reset_state();
$msg = $std->render( array( 'album' => str_repeat( 'z', 300 ) ) );
contains( 'S1 overlong attribute renders the missing-parameters block', $msg, '<h1 class="v4v-musiclink-title">V4V MusicLink</h1>' );
check( 'S2 ...with no Music Index call', $GLOBALS['__mi_calls'], 0 );

reset_state();
$page = $std->render( array( 'album' => $free ) );
excludes( 'S3 a free-form id is accepted (no missing-parameters block)', $page, 'Missing parameter(s)' );
contains( 'S4 ...and reaches the outbound lookup URL verbatim', $page, 'https://v4vmusic.com/lookup/track_01-they%27re-right%21' );
excludes( 'S5 ...without a not-found message', $page, 'Album/Song not found' );
check( 'S6 resolution came from cache, not a live call', $GLOBALS['__mi_calls'], 0 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
