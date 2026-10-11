<?php
/**
 * Standalone verification for the Fountain path-prefix fix.
 *
 * PRO ONLY as of O21: drives the Pro class's actual private methods via
 * reflection using captured LIVE fountain.fm search responses
 * (tests/fx_*.txt); the standard plugin's copies of fountain_path_segment()
 * and extract_fountain_hit() were removed with the rest of its discovery
 * machinery, so their absence is pinned up front.
 *
 * Run:  ./php/install.sh && ./php/php tests/test_fountain_path.php
 *       (no system PHP needed - the portable CLI lives in php/, see
 *        CONTRIBUTING.md "PHP runtime")
 */

error_reporting( E_ALL );

// --- WordPress constants the plugin file reads at class-definition time ---
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
// Pro's file guards on this; never exercised because the stubs below short-
// circuit the constructor's work (log table + social-preview hooks).
define( 'ABSPATH', __DIR__ . '/' );

// --- WordPress stubs: only what the constructors need ---
function add_shortcode( ...$a ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_activation_hook( ...$a ) {}
function get_option( $k, $d = false ) {
	// Short-circuit Pro's constructor away from DB writes / wp_head hooks.
	if ( 'v4v_musiclink_cache_table_version' === $k || 'v4v_musiclink_pro_cache_table_version' === $k ) { return '1'; }
	if ( 'v4v_musiclink_pro_log_table_version' === $k ) {
		return '2';
	}
	if ( 'v4v_musiclink_pro_social_preview_mode' === $k ) {
		return 'off';
	}
	return $d;
}
function update_option( ...$a ) { return true; }
function get_transient( ...$a ) { return false; }
function set_transient( ...$a ) { return true; }
function delete_transient( ...$a ) { return true; }

$GLOBALS['__fixtures'] = __DIR__;

require_once dirname( __DIR__ ) . '/v4v-musiclink/v4v-musiclink.php';
require_once dirname( __DIR__ ) . '/v4v-musiclink-pro/v4v-musiclink-pro.php';

$classes = array( 'V4V_Music_Link_Resolver_Pro' );

$pass = 0;
$fail = 0;
function check( $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		$pass++;
		printf( "  PASS  %-62s => %s\n", $label, var_export( $actual, true ) );
	} else {
		$fail++;
		printf( "  FAIL  %-62s\n         expected: %s\n         actual:   %s\n",
			$label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

function call_private( $class, $method, ...$args ) {
	$inst = new $class();
	$m    = new ReflectionMethod( $class, $method );
	return $m->invoke( $inst, ...$args );
}

/**
 * Reproduces the plugin's exact URL construction at the call site:
 *   'https://fountain.fm/' . segment . '/' . rawurlencode( id )
 */
function build_url( $class, $hit, $has_track ) {
	$seg = call_private( $class, 'fountain_path_segment', $hit, $has_track );
	return 'https://fountain.fm/' . $seg . '/' . rawurlencode( $hit['id'] );
}

// O21: standard's discovery surface must be gone entirely.
echo "-- standard plugin: fountain_path_segment/extract_fountain_hit removed --\n";
check( "[std] fountain_path_segment removed", method_exists( 'V4V_Music_Link_Resolver', 'fountain_path_segment' ), false );
check( "[std] extract_fountain_hit removed", method_exists( 'V4V_Music_Link_Resolver', 'extract_fountain_hit' ), false );

foreach ( $classes as $class ) {
	echo str_repeat( '=', 78 ) . "\nPLUGIN CLASS: $class\n" . str_repeat( '=', 78 ) . "\n";

	// ---------- 1. fountain_path_segment: every known type ----------
	echo "\n-- fountain_path_segment: known types (type decides, not has_track) --\n";
	$matrix = array(
		// type           has_track   expected segment
		array( 'TRACK',     true,  'track' ),
		array( 'TRACK',     false, 'track' ),
		array( 'ALBUM',     true,  'album' ),
		array( 'ALBUM',     false, 'album' ),
		array( 'EPISODE',   true,  'episode' ),
		array( 'EPISODE',   false, 'episode' ),
		array( 'SHOW',      true,  'show' ),
		array( 'SHOW',      false, 'show' ),
	);
	foreach ( $matrix as list( $type, $ht, $want ) ) {
		$got = call_private( $class, 'fountain_path_segment', array( 'id' => 'X', 'type' => $type ), $ht );
		check( "type=$type has_track=" . var_export( $ht, true ), $got, $want );
	}

	// ---------- 2. fallbacks when type is missing/unrecognised ----------
	echo "\n-- fountain_path_segment: fallback to historic guess --\n";
	check( "unknown type 'ARTIST', has_track=true",
		call_private( $class, 'fountain_path_segment', array( 'id' => 'X', 'type' => 'ARTIST' ), true ), 'track' );
	check( "unknown type 'ARTIST', has_track=false",
		call_private( $class, 'fountain_path_segment', array( 'id' => 'X', 'type' => 'ARTIST' ), false ), 'album' );
	check( "empty type, has_track=true",
		call_private( $class, 'fountain_path_segment', array( 'id' => 'X', 'type' => '' ), true ), 'track' );
	check( "type key absent, has_track=false",
		call_private( $class, 'fountain_path_segment', array( 'id' => 'X' ), false ), 'album' );

	// ---------- 3. extract_fountain_hit on LIVE captured responses ----------
	echo "\n-- extract_fountain_hit: real fountain.fm search bodies --\n";
	$cases = array(
		// fixture file          target guid                                 expected type
		'fx_track_hit.txt'   => array( '85146f36-4a3f-43e5-8688-04f5c38ad0c3', 'TRACK',   'KEkKUzOT3s9d3m78nx24' ),
		'fx_album_hit.txt'   => array( 'b0f6220f-3076-5ea3-b0b6-1629ab824292', 'ALBUM',   'bT3oyF5SiUoMaYGh0as6' ),
		'fx_show_hit.txt'    => array( '69c634ad-afea-5826-ad9a-8e1f06d6470b', 'SHOW',    'trSnfDZ8VBGzsh6hjoS4' ),
		'fx_episode_hit.txt' => array( '016b464c-8cfc-43ef-8463-5846c3f2ddb2', 'EPISODE', '3xnUxg38mNApJVgv4hXa' ),
	);
	foreach ( $cases as $file => list( $guid, $type, $id ) ) {
		$body = file_get_contents( __DIR__ . '/' . $file );
		$hit  = call_private( $class, 'extract_fountain_hit', $body, $guid );
		check( "$file: type", is_array( $hit ) ? $hit['type'] : $hit, $type );
		check( "$file: id",   is_array( $hit ) ? $hit['id']   : $hit, $id );
	}

	// ---------- 4. negative cases for the extractor ----------
	echo "\n-- extract_fountain_hit: negative cases --\n";
	check( "guid not in response => null",
		call_private( $class, 'extract_fountain_hit', file_get_contents( __DIR__ . '/fx_show_hit.txt' ), 'nope-nope' ), null );
	check( "empty body => null",
		call_private( $class, 'extract_fountain_hit', '', 'x' ), null );
	check( "garbage body => null",
		call_private( $class, 'extract_fountain_hit', 'not a stream at all', 'x' ), null );
	check( "hits chunk but no _type key still yields empty type",
		call_private(
			$class,
			'extract_fountain_hit',
			'0:{"foo":"bar"}1:{"hits":[{"_guid":"g1","_id":"id1"}]}',
			'g1'
		),
		array( 'id' => 'id1', 'type' => '' )
	);

	// ---------- 5. THE BUG: the reported URL now resolves correctly -------
	echo "\n-- reported bug end-to-end (guid -> final URL) --\n";
	$e2e = array(
		// guid, fixture, has_track, expected URL (each verified 200 + real <title>)
		array( 'track guid 016b464c…', 'fx_episode_hit.txt', '016b464c-8cfc-43ef-8463-5846c3f2ddb2', true,
			'https://fountain.fm/episode/3xnUxg38mNApJVgv4hXa' ),
		array( 'album guid 69c634ad…', 'fx_show_hit.txt',    '69c634ad-afea-5826-ad9a-8e1f06d6470b', false,
			'https://fountain.fm/show/trSnfDZ8VBGzsh6hjoS4' ),
		array( 'music track (TRACK)',  'fx_track_hit.txt',   '85146f36-4a3f-43e5-8688-04f5c38ad0c3', true,
			'https://fountain.fm/track/KEkKUzOT3s9d3m78nx24' ),
		array( 'music album (ALBUM)',  'fx_album_hit.txt',   'b0f6220f-3076-5ea3-b0b6-1629ab824292', false,
			'https://fountain.fm/album/bT3oyF5SiUoMaYGh0as6' ),
	);
	foreach ( $e2e as list( $label, $file, $guid, $has_track, $want ) ) {
		$hit = call_private( $class, 'extract_fountain_hit', file_get_contents( __DIR__ . '/' . $file ), $guid );
		$url = $hit ? build_url( $class, $hit, $has_track ) : null;
		check( $label, $url, $want );
	}

	// ---------- 6. the OLD code's answer, to prove it was wrong ----------
	echo "\n-- old behaviour for comparison (hardcoded track/album) --\n";
	foreach ( $e2e as list( $label, $file, $guid, $has_track, $correct ) ) {
		$hit = call_private( $class, 'extract_fountain_hit', file_get_contents( __DIR__ . '/' . $file ), $guid );
		$old = 'https://fountain.fm/' . ( $has_track ? 'track' : 'album' ) . '/' . rawurlencode( $hit['id'] );
		$status = ( $old === $correct ) ? "unchanged (was already right)" : "WAS 404 -> now $correct";
		printf( "  %-24s old=%-58s %s\n", $label, $old, $status );
	}
}

echo "\n" . str_repeat( '=', 78 ) . "\n";
printf( "RESULT: %d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
