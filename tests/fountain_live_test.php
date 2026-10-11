<?php
/**
 * LIVE end-to-end test of the real fountain_resolve() code path in the
 * PRO plugin file, against the real fountain.fm (the standard plugin no
 * longer resolves - O21 - so only Pro rows remain):
 *
 *   get_option returns nothing (fresh site) -> fountain_resolve must
 *   autodetect the current next-action from /search-page chunks, POST to
 *   https://fountain.fm/search, parse the hits, match our guid, and
 *   update_option() the detected value for next time.
 *
 * Network is real (curl); only WordPress is stubbed.
 *
 * LIVE NETWORK TEST - hits fountain.fm on every run and can flake or
 * time out; re-run it once before concluding a failure is a regression.
 * Run:  ./php/php tests/fountain_live_test.php
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

class Fake_WP_Error { public $msg = 'transport failure'; }

$GLOBALS['transients']    = array();
$GLOBALS['options']       = array();
$GLOBALS['updated']       = array();

function is_wp_error( $x ) { return $x instanceof Fake_WP_Error; }

function curl_exec_url( $url, $post_body = null, $headers = array() ) {
	$ch = curl_init( $url );
	$opts = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_FOLLOWLOCATION => false, // honour redirection => 0
	);
	if ( null !== $post_body ) {
		$opts[CURLOPT_POST] = true;
		$opts[CURLOPT_POSTFIELDS] = $post_body;
		$opts[CURLOPT_HTTPHEADER] = $headers;
	}
	curl_setopt_array( $ch, $opts );
	$body = curl_exec( $ch );
	if ( false === $body ) {
		curl_close( $ch );
		return new Fake_WP_Error();
	}
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}

function wp_remote_get( $url, $args = array() )     { return curl_exec_url( $url ); }
function wp_remote_post( $url, $args = array() )    {
	$curl_headers = array();
	if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
		foreach ( $args['headers'] as $k => $v ) {
			$curl_headers[] = $k . ': ' . $v;
		}
	}
	return curl_exec_url(
		$url,
		isset( $args['body'] ) ? $args['body'] : null,
		$curl_headers
	);
}
function wp_remote_retrieve_response_code( $r ) { return is_array($r) && isset($r['response']['code']) ? (int) $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )          { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }
function get_transient( $k )                    { return array_key_exists($k, $GLOBALS['transients']) ? $GLOBALS['transients'][$k] : false; }
function set_transient( $k, $v, $ttl )          { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient( $k )                 { unset($GLOBALS['transients'][$k]); return true; }
function add_shortcode() {}
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function get_option( $k, $d = false )           {
	if ( 'v4v_musiclink_cache_table_version' === $k || 'v4v_musiclink_pro_cache_table_version' === $k ) { return '1'; }
	if ( 'v4v_musiclink_pro_log_table_version' === $k ) { return '2'; }
	return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][$k] : $d;
}
function update_option( $k, $v, $a = false )    { $GLOBALS['options'][$k] = $v; $GLOBALS['updated'][] = $k; return true; }
function home_url( $p = '' )                    { return 'http://example.test' . $p; }
function __( $s, $d = null )                    { return $s; }
function _e( $s, $d = null )                    { echo $s; }
function esc_html( $s )                         { return $s; }
function esc_attr( $s )                         { return $s; }
function esc_url( $s )                          { return $s; }
function esc_html_e( $s, $d = null )            { echo $s; }
function sanitize_text_field( $s )              { return is_string($s) ? trim( strip_tags($s) ) : $s; }
function wp_unslash( $s )                       { return $s; }
function shortcode_atts( $pairs, $atts, $sc = ''){ return array_merge( $pairs, (array) $atts ); }
function absint( $n )                           { return abs( (int) $n ); }
function plugin_dir_url( $f )                   { return 'http://example.test/plugin/'; }
function plugins_url( $p = '', $f = '' )        { return 'http://example.test/plugin/' . $p; }
function did_action( $h )                       { return 0; }
function is_admin()                             { return false; }
function wp_doing_ajax()                        { return false; }
function current_user_can( $c )                 { return true; }
function wp_create_nonce( $a )                  { return 'stub-nonce'; }
function wp_verify_nonce( $n, $a )              { return 1; }
function check_ajax_referer( $a, $q = true )    { return true; }
function wp_json_encode( $d )                   { return json_encode($d); }
function wp_parse_url( $u, $c = -1 )            { return parse_url($u, $c); }
function wp_die( $m = '' )                      { throw new RuntimeException( 'wp_die: ' . $m ); }
function wp_send_json( $d )                     { throw new RuntimeException( 'wp_send_json' ); }
function wp_send_json_error( $d = null, $c = null ) { throw new RuntimeException( 'wp_send_json_error' ); }
function wp_send_json_success( $d = null )      { throw new RuntimeException( 'wp_send_json_success' ); }

require __DIR__ . '/../v4v-musiclink/v4v-musiclink.php';
require __DIR__ . '/../v4v-musiclink-pro/v4v-musiclink-pro.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS - $label\n"; }
	else         { $fail++; echo "FAIL - $label\n"; }
}

$cases = array(
	array( 'V4V_Music_Link_Resolver_Pro',  'pro', 'cbdaeaa0-3c58-41ce-92d1-c718710ade54', 'See Through', 'qkMEo45HgMB0uZ5tUlXE', 'TRACK', 'v4v_musiclink_pro_fountain_next_action' ),
	array( 'V4V_Music_Link_Resolver_Pro',  'pro', 'd5c805fb-6016-4038-9cda-2e68d2b8dcc4', 'The Singles', '9aSjTQRfSX1vb3dStBWs', 'ALBUM', 'v4v_musiclink_pro_fountain_next_action' ),
);

foreach ( $cases as $case ) {
	list( $class, $short, $guid, $title, $want_id, $want_type, $opt ) = $case;
	// Fresh site state per case: no stored hash, no throttle, no caches.
	$GLOBALS['transients'] = array();
	$GLOBALS['options']    = array();
	$GLOBALS['updated']    = array();

	$m = new ReflectionMethod( $class, 'fountain_resolve' );
	$obj = new $class();
	$t0  = microtime( true );
	$hit = $m->invoke( $obj, $guid, $title );
	$dt  = round( microtime( true ) - $t0, 1 );

	ok( is_array( $hit ), "[$short] live fountain_resolve(" . substr($guid,0,8) . ", '$title') -> hit ({$dt}s)" );
	ok( is_array( $hit ) && $want_id === $hit['id'], "[$short] resolved id = $want_id" . ( is_array($hit) && isset($hit['id']) ? ' (got ' . $hit['id'] . ')' : '' ) );
	ok( is_array( $hit ) && $want_type === strtoupper( (string) $hit['type'] ), "[$short] resolved type = $want_type" . ( is_array($hit) && isset($hit['type']) ? ' (got ' . $hit['type'] . ')' : '' ) );
	ok( in_array( $opt, $GLOBALS['updated'], true ), "[$short] detected next-action persisted via update_option($opt)" );
	ok( isset( $GLOBALS['options'][$opt] ) && preg_match( '/^[0-9a-f]{40,44}$/i', $GLOBALS['options'][$opt] ), "[$short] stored value is a hex id (" . ( $GLOBALS['options'][$opt] ?? 'none' ) . ")" );
}

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
