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

echo "\n" . str_repeat( '=', 78 ) . "\n";
printf( "RESULT: %d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
