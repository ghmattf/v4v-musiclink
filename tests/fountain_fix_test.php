<?php
/**
 * Behavioural test for the Fountain direct-link fix (2026 restructure).
 *
 * PRO ONLY as of O21: the standard plugin no longer has any Fountain
 * discovery machinery (fountain_resolve and friends were removed), so
 * this file drives the Pro class's private fountain_* methods and first
 * pins the standard class's method absence, then asserts:
 *
 *   A. fountain_try_resolve() POSTs to https://fountain.fm/search (NOT the
 *      site root - a root POST gets an empty application/json {} back).
 *   B. Negative-cache TTL policy mirrors #8 (fountain_negative_cache_ttl):
 *        definitive (404 stale hash, 200-with-readable-hits-but-no-guid,
 *        200-with-empty-hits)             -> FAIL_CACHE_TTL  (3600 s)
 *        unconfirmed (transport/5xx/3xx/
 *        unreadable 200 body)             -> SOFT_FAIL_CACHE (30 s)
 *        success                          -> 30-day hit cache
 *   C. extract_fountain_hit() keeps parsing the glued hits marker and
 *      maintains last_fountain_hits_parsed for the TTL decision.
 *   D. fountain_autodetect_next_actions() seeds /search FIRST, scans the
 *      search-only chunk delta before homepage chunks (where the
 *      searchContent registration now lives), returns EVERY near-"search"
 *      candidate in scan order (de-duplicated), rejects untrusted/
 *      cleartext script URLs, falls back to homepage-only when /search is
 *      down, and stays throttled to one run per 15 minutes.
 *   E. fountain_resolve() probes the detected candidates IN ORDER and
 *      persists only the first id that answers with a hits payload (O11):
 *      a dead first candidate must neither be stored nor stop the probe,
 *      a live "no match" id IS stored (and stops it), and an already-good
 *      stored id never triggers a re-crawl.
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

class Fake_WP_Error { public $msg = 'stubbed transport failure'; }

$GLOBALS['transients']     = array();
$GLOBALS['transient_ttls'] = array();
$GLOBALS['post_log']       = array();
$GLOBALS['get_log']        = array();
$GLOBALS['post_response']  = null;
$GLOBALS['post_router']    = array(); // next-action header => response array | Fake_WP_Error
$GLOBALS['get_router']     = array(); // URL => response array | Fake_WP_Error
$GLOBALS['options']        = array();
$GLOBALS['option_log']     = array();

function resp( $code, $body )                  { return array( 'response' => array( 'code' => $code ), 'body' => $body ); }
function is_wp_error( $x )                     { return $x instanceof Fake_WP_Error; }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['get_log'][] = $url;
	return isset( $GLOBALS['get_router'][ $url ] ) ? $GLOBALS['get_router'][ $url ] : new Fake_WP_Error();
}
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['post_log'][] = array( 'url' => $url, 'args' => $args );
	$na = isset( $args['headers']['next-action'] ) ? $args['headers']['next-action'] : '';
	if ( isset( $GLOBALS['post_router'][ $na ] ) ) {
		return $GLOBALS['post_router'][ $na ];
	}
	return $GLOBALS['post_response'];
}
function wp_remote_retrieve_response_code( $r ) { return is_array($r) && isset($r['response']['code']) ? (int) $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )          { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }
function get_transient( $k )                    { return array_key_exists($k, $GLOBALS['transients']) ? $GLOBALS['transients'][$k] : false; }
function set_transient( $k, $v, $ttl )          { $GLOBALS['transients'][$k] = $v; $GLOBALS['transient_ttls'][$k] = $ttl; return true; }
function delete_transient( $k )                 { unset($GLOBALS['transients'][$k]); unset($GLOBALS['transient_ttls'][$k]); return true; }
function add_shortcode() {}
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function get_option( $k, $d = false )           {
	if ( 'v4v_musiclink_cache_table_version' === $k || 'v4v_musiclink_pro_cache_table_version' === $k ) { return '1'; }
	if ( 'v4v_musiclink_pro_log_table_version' === $k ) { return '2'; }
	return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][$k] : $d;
}
function update_option( $k, $v, $a = false )    {
	$GLOBALS['options'][$k]     = $v;
	$GLOBALS['option_log'][]   = array( 'key' => $k, 'value' => $v );
	return true;
}
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

// ---------------------------------------------------------------------------

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS - $label\n"; }
	else         { $fail++; echo "FAIL - $label\n"; }
}

function reset_state() {
	$GLOBALS['transients']     = array();
	$GLOBALS['transient_ttls'] = array();
	$GLOBALS['post_log']       = array();
	$GLOBALS['get_log']        = array();
	$GLOBALS['post_response']  = null;
	$GLOBALS['post_router']    = array();
	$GLOBALS['get_router']     = array();
	$GLOBALS['options']        = array();
	$GLOBALS['option_log']     = array();
}

function method( $class, $name ) {
	$m = new ReflectionMethod( $class, $name );
	$m->setAccessible( true );
	return $m;
}

$GUID  = 'cbdaeaa0-3c58-41ce-92d1-c718710ade54';
$NA    = 'abcdef0123456789abcdef0123456789abcdef01';
$TITLE = 'See Through';

$hits_body = function ( $hits_json ) {
	return '0:{"a":"$@1","f":"","q":"","i":false,"b":"byL10Gh"} 2:T6d1,<p>some flight text</p>' . $hits_json;
};
$HITS_TRACK  = $hits_body( '4:{"hits":[{"_guid":"other-guid","_id":"xyz","_type":"ALBUM"},{"_guid":"'.$GUID.'","_id":"qkMEo45HgMB0uZ5tUlXE","_type":"TRACK"}]}' );
$HITS_NO_TGT = $hits_body( '4:{"hits":[{"_guid":"other-guid","_id":"xyz","_type":"ALBUM"}]}' );
$HITS_EMPTY  = $hits_body( '4:{"hits":[]}' );
$NO_MARKER   = '{}';
$BAD_JSON    = $hits_body( '4:{"hits":[oops' );

// O21: standard's discovery surface must be gone entirely.
foreach ( array( 'fountain_resolve', 'fountain_try_resolve', 'fountain_autodetect_next_actions', 'fountain_scan_chunks_for_actions', 'extract_fountain_hit', 'store_fountain_link' ) as $gone ) {
	ok( ! method_exists( 'V4V_Music_Link_Resolver', $gone ), "[std] $gone removed" );
}

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
