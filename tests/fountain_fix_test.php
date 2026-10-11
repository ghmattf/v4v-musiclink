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
require __DIR__ . '/../v4v-musiclink-pro/v4v-musiclink-pro.php';

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

$classes = array(
	// class => array (transient key prefix, stored-option key, throttle key)
	'V4V_Music_Link_Resolver_Pro' => array( 'fountain_id_pro_v5_', 'v4v_musiclink_pro_fountain_next_action', 'fountain_autodetect_throttle_pro' ),
);

// O21: standard's discovery surface must be gone entirely.
foreach ( array( 'fountain_resolve', 'fountain_try_resolve', 'fountain_autodetect_next_actions', 'fountain_scan_chunks_for_actions', 'extract_fountain_hit', 'store_fountain_link' ) as $gone ) {
	ok( ! method_exists( 'V4V_Music_Link_Resolver', $gone ), "[std] $gone removed" );
}

foreach ( $classes as $class => $meta ) {
	list( $key_prefix, $opt_key, $throttle_key ) = $meta;
	$short = ( 'V4V_Music_Link_Resolver_Pro' === $class ) ? 'pro' : 'std';
	$obj   = new $class();
	$try   = method( $class, 'fountain_try_resolve' );
	$auto  = method( $class, 'fountain_autodetect_next_actions' );
	$resolve = method( $class, 'fountain_resolve' );
	$key   = $key_prefix . md5( $GUID . '|' . $NA );

	// --- B: TTL matrix through fountain_try_resolve -----------------------
	$matrix = array(
		// label, response, expected_ttl, expected_hit
		array( 'transport error (WP_Error)', new Fake_WP_Error(), 30, false ),
		array( 'HTTP 500',                   resp( 500, 'oops' ), 30, false ),
		array( 'HTTP 302 redirect',          resp( 302, '' ),     30, false ),
		array( 'HTTP 404 stale next-action', resp( 404, 'Server action not found.' ), 3600, false ),
		array( '200 readable hits, no guid', resp( 200, $HITS_NO_TGT ), 3600, false ),
		array( '200 readable EMPTY hits',    resp( 200, $HITS_EMPTY ),  3600, false ),
		array( '200 unmarker {} body',       resp( 200, $NO_MARKER ),   30,   false ),
		array( '200 undecodable hits JSON',  resp( 200, $BAD_JSON ),    30,   false ),
	);
	foreach ( $matrix as $case ) {
		list( $label, $response, $want_ttl, $want_hit ) = $case;
		reset_state();
		$GLOBALS['post_response'] = $response;
		$result = $try->invoke( $obj, $GUID, $TITLE, $NA );
		ok( null === $result, "[$short] $label -> null" );
		ok( isset($GLOBALS['post_log'][0]) && 'https://fountain.fm/search' === $GLOBALS['post_log'][0]['url'], "[$short] $label -> POST /search" );
		ok( isset($GLOBALS['transient_ttls'][$key]) && $want_ttl === $GLOBALS['transient_ttls'][$key], "[$short] $label -> TTL {$want_ttl}s (got " . ( $GLOBALS['transient_ttls'][$key] ?? 'none' ) . ")" );
		ok( '' === ($GLOBALS['transients'][$key] ?? 'x'), "[$short] $label -> negative cached" );
	}

	// Success path
	reset_state();
	$GLOBALS['post_response'] = resp( 200, $HITS_TRACK );
	$result = $try->invoke( $obj, $GUID, $TITLE, $NA );
	ok( is_array($result) && 'qkMEo45HgMB0uZ5tUlXE' === $result['id'] && 'TRACK' === $result['type'], "[$short] success -> hit id+type" );
	ok( isset($GLOBALS['post_log'][0]) && 'https://fountain.fm/search' === $GLOBALS['post_log'][0]['url'], "[$short] success -> POST /search" );
	$logged = $GLOBALS['post_log'][0]['args'];
	ok( $NA === $logged['headers']['next-action'], "[$short] success -> next-action header passed through" );
	ok( json_encode( array( $TITLE, array() ) ) === $logged['body'], "[$short] success -> body [title,[]]" );
	ok( isset($GLOBALS['transient_ttls'][$key]) && 30 * DAY_IN_SECONDS === $GLOBALS['transient_ttls'][$key], "[$short] success ->30-day hit cache" );
	ok( isset($GLOBALS['transients'][$key]) && is_array($GLOBALS['transients'][$key]) && 'qkMEo45HgMB0uZ5tUlXE' === $GLOBALS['transients'][$key]['id'], "[$short] success -> hit cached" );

	// Cached-negative short-circuit: no HTTP at all
	reset_state();
	$GLOBALS['transients'][$key] = '';
	$result = $try->invoke( $obj, $GUID, $TITLE, $NA );
	ok( null === $result && 0 === count( $GLOBALS['post_log'] ), "[$short] cached negative -> served without HTTP" );

	// Cached-positive short-circuit
	$GLOBALS['transients'][$key] = array( 'id' => 'cached-id', 'type' => 'ALBUM' );
	$result = $try->invoke( $obj, $GUID, $TITLE, $NA );
	ok( is_array($result) && 'cached-id' === $result['id'] && 0 === count( $GLOBALS['post_log'] ), "[$short] cached positive -> served without HTTP" );

	// --- D: autodetect ------------------------------------------------------
	$search_html = '<script src="/_next/static/immutable/chunks/shared.js"></script>'
		. '<script src="/_next/static/immutable/chunks/delta.js"></script>'
		. '<script src="http://fountain.fm/_next/static/immutable/chunks/cleartext.js"></script>'
		. '<script src="https://evil.tld/fountain.fm/_next/static/immutable/chunks/evil.js"></script>';
	$home_html   = '<script src="/_next/static/immutable/chunks/shared.js"></script>'
		. '<script src="/_next/static/immutable/chunks/homeA.js"></script>';
	$delta_hit   = 'let l=(0,n.createServerReference)("605af9c39c662ad04c72e52279d6db26f12973e250",n.callServer,void 0,n.findSourceMapURL,"searchContent");';
	$home_hit    = 'var c="111111111111111111111111111111111111111a",searchCfg=1;';

	// D1: candidate in the search-only delta -> found, seeds ordered as before
	reset_state();
	$GLOBALS['get_router'] = array(
		'https://fountain.fm/search' => resp( 200, $search_html ),
		'https://fountain.fm/'       => resp( 200, $home_html ),
		'https://fountain.fm/_next/static/immutable/chunks/delta.js'  => resp( 200, $delta_hit ),
		'https://fountain.fm/_next/static/immutable/chunks/shared.js' => resp( 200, 'var a=1;' ),
	);
	$detected = $auto->invoke( $obj );
	ok( array( '605af9c39c662ad04c72e52279d6db26f12973e250' ) === $detected, "[$short] autodetect -> searchContent from /search-only delta" );
	$order = $GLOBALS['get_log'];
	ok( 'https://fountain.fm/search' === ($order[0] ?? '') && 'https://fountain.fm/' === ($order[1] ?? ''), "[$short] autodetect -> /search seeded before homepage" );
	ok( 'https://fountain.fm/_next/static/immutable/chunks/delta.js' === ($order[2] ?? ''), "[$short] autodetect -> delta chunk scanned before homepage chunks" );
	$joined = implode( ' ', $GLOBALS['get_log'] );
	ok( false === strpos( $joined, 'cleartext.js' ) && false === strpos( $joined, 'evil.js' ), "[$short] autodetect -> cleartext + wrong-host scripts never fetched" );
	ok( isset( $GLOBALS['transients'][$throttle_key] ), "[$short] autodetect -> throttle stored under $throttle_key" );

	// D2: throttled second run -> empty list, zero HTTP
	$before = count( $GLOBALS['get_log'] );
	$again  = $auto->invoke( $obj );
	ok( array() === $again && count( $GLOBALS['get_log'] ) === $before, "[$short] autodetect -> second run throttled (no HTTP)" );

	// D3: candidate only on homepage -> delta scanned first, then homepage fallback
	reset_state();
	$GLOBALS['get_router'] = array(
		'https://fountain.fm/search' => resp( 200, $search_html ),
		'https://fountain.fm/'       => resp( 200, $home_html ),
		'https://fountain.fm/_next/static/immutable/chunks/delta.js'  => resp( 200, 'var a=1;' ),
		'https://fountain.fm/_next/static/immutable/chunks/shared.js' => resp( 200, 'var a=1;' ),
		'https://fountain.fm/_next/static/immutable/chunks/homeA.js'  => resp( 200, $home_hit ),
	);
	$detected = $auto->invoke( $obj );
	ok( array( '111111111111111111111111111111111111111a' ) === $detected, "[$short] autodetect -> homepage fallback still finds a candidate" );
	$pos_delta = array_search( 'https://fountain.fm/_next/static/immutable/chunks/delta.js', $GLOBALS['get_log'], true );
	$pos_home  = array_search( 'https://fountain.fm/_next/static/immutable/chunks/homeA.js', $GLOBALS['get_log'], true );
	ok( false !== $pos_delta && false !== $pos_home && $pos_delta < $pos_home, "[$short] autodetect -> delta scanned before homepage chunk" );

	// D4: /search down -> homepage-only behaves exactly like the old code
	reset_state();
	$GLOBALS['get_router'] = array(
		'https://fountain.fm/search' => resp( 503, 'down' ),
		'https://fountain.fm/'       => resp( 200, $home_html ),
		'https://fountain.fm/_next/static/immutable/chunks/shared.js' => resp( 200, 'var a=1;' ),
		'https://fountain.fm/_next/static/immutable/chunks/homeA.js'  => resp( 200, $home_hit ),
	);
	$detected = $auto->invoke( $obj );
	ok( array( '111111111111111111111111111111111111111a' ) === $detected, "[$short] autodetect -> /search503 falls back to homepage-only" );
	ok( ! in_array( 'https://fountain.fm/_next/static/immutable/chunks/delta.js', $GLOBALS['get_log'], true ), "[$short] autodetect -> no delta chunk fetch when /search is down" );

	// D5: both seeds down -> empty list, no chunk fetches
	reset_state();
	$GLOBALS['get_router'] = array(
		'https://fountain.fm/search' => new Fake_WP_Error(),
		'https://fountain.fm/'       => new Fake_WP_Error(),
	);
	$detected = $auto->invoke( $obj );
	ok( array() === $detected && 2 === count( $GLOBALS['get_log'] ), "[$short] autodetect -> both seeds down -> empty list, no chunk crawl" );

	// D6: threat regression - protocol-relative and root-relative still accepted,
	//     cleartext/wrong-host still rejected (the #4 properties intact).
	reset_state();
	$proto_html = '<script src="//fountain.fm/_next/static/immutable/chunks/proto.js"></script>'
		. '<script src="/_next/static/immutable/chunks/root.js"></script>';
	$GLOBALS['get_router'] = array(
		'https://fountain.fm/search' => resp( 200, $proto_html ),
		'https://fountain.fm/'       => resp( 200, $proto_html ),
		'https://fountain.fm/_next/static/immutable/chunks/proto.js' => resp( 200, $home_hit ),
		'https://fountain.fm/_next/static/immutable/chunks/root.js'  => resp( 200, 'var a=1;' ),
	);
	$detected = $auto->invoke( $obj );
	ok( array( '111111111111111111111111111111111111111a' ) === $detected, "[$short] autodetect -> protocol-relative src normalised + accepted" );
	ok( ! in_array( 'http://fountain.fm/_next/static/immutable/chunks/cleartext.js', $GLOBALS['get_log'], true ), "[$short] autodetect -> cleartext still rejected (regression #4)" );

	// D7 (O11): several near-"search" ids in ONE chunk are ALL returned, in
	//     file order, a hex repeated in a later chunk is listed once at its
	//     first position - no more "first candidate wins" - and the
	//     homepage fallback phase is skipped once the delta has candidates.
	$DEAD1 = '6089300ccac831e642ad43ebadccbc79cc5798cebe';
	$GOOD  = '60bfa4dd096a239f664de4573a1569507827d5f879';
	$DEAD2 = '40f7d6ab111111111111111111111111111111111';
	$ALIVE = '111111111111111111111111111111111111111a';

	$three_hit = 'var a="' . $DEAD1 . '",search=1;'
		. ' var b="' . $GOOD . '",search=2;'
		. ' var c="' . $DEAD2 . '",search=3;';
	$alive_hit = 'var a="' . $DEAD1 . '",search=1;'
		. ' var b="' . $ALIVE . '",search=2;';

	// /search references shared.js + delta.js + delta2.js; the homepage
	// references shared.js + homeA.js -> the delta phase is [delta, delta2].
	$three_search_html = '<script src="/_next/static/immutable/chunks/shared.js"></script>'
		. '<script src="/_next/static/immutable/chunks/delta.js"></script>'
		. '<script src="/_next/static/immutable/chunks/delta2.js"></script>';
	$three_home_html   = '<script src="/_next/static/immutable/chunks/shared.js"></script>'
		. '<script src="/_next/static/immutable/chunks/homeA.js"></script>';

	reset_state();
	$GLOBALS['get_router'] = array(
		'https://fountain.fm/search' => resp( 200, $three_search_html ),
		'https://fountain.fm/'       => resp( 200, $three_home_html ),
		'https://fountain.fm/_next/static/immutable/chunks/delta.js'  => resp( 200, $three_hit ),
		'https://fountain.fm/_next/static/immutable/chunks/delta2.js' => resp( 200, 'var d="' . $GOOD . '",search=9;' ),
		'https://fountain.fm/_next/static/immutable/chunks/shared.js' => resp( 200, 'var a=1;' ),
		'https://fountain.fm/_next/static/immutable/chunks/homeA.js'  => resp( 200, $home_hit ),
	);
	$detected = $auto->invoke( $obj );
	ok( array( $DEAD1, $GOOD, $DEAD2 ) === $detected, "[$short] autodetect -> all 3 candidates in scan order (got " . implode( ',', (array) $detected ) . ")" );
	ok( 1 === count( array_keys( $detected, $GOOD, true ) ), "[$short] autodetect -> a hex repeated in a later chunk is de-duplicated" );
	ok( ! in_array( 'https://fountain.fm/_next/static/immutable/chunks/homeA.js', $GLOBALS['get_log'], true )
		&& ! in_array( 'https://fountain.fm/_next/static/immutable/chunks/shared.js', $GLOBALS['get_log'], true ),
		"[$short] autodetect -> homepage fallback phase skipped once the delta has candidates" );

	// --- E: fountain_resolve() probes candidates (the O11 fix) -------------
	$seed_router = array(
		'https://fountain.fm/search' => resp( 200, $search_html ),
		'https://fountain.fm/'       => resp( 200, $home_html ),
		'https://fountain.fm/_next/static/immutable/chunks/delta.js'  => resp( 200, $three_hit ),
		'https://fountain.fm/_next/static/immutable/chunks/shared.js' => resp( 200, 'var a=1;' ),
	);
	$posted = function () {
		$actions = array();
		foreach ( $GLOBALS['post_log'] as $p ) {
			$actions[] = $p['args']['headers']['next-action'];
		}
		return $actions;
	};

	// E1: fresh site, first candidate dead -> keep probing until a hit, and
	//     store ONLY the id that actually worked.
	reset_state();
	$GLOBALS['get_router'] = $seed_router;
	$GLOBALS['post_router'] = array(
		$DEAD1 => resp( 200, '{"content":[],"profiles":[]}' ),
		$GOOD  => resp( 200, $HITS_TRACK ),
		$DEAD2 => resp( 404, 'Server action not found.' ),
	);
	$hit = $resolve->invoke( $obj, $GUID, $TITLE );
	ok( is_array( $hit ) && 'qkMEo45HgMB0uZ5tUlXE' === $hit['id'], "[$short] resolve -> probes past a dead first candidate to a live one" );
	ok( array( $DEAD1, $GOOD ) === $posted(), "[$short] resolve -> candidates POSTed in scan order, stops at the hit (got " . implode( ',', $posted() ) . ")" );
	ok( isset( $GLOBALS['options'][$opt_key] ) && $GOOD === $GLOBALS['options'][$opt_key], "[$short] resolve -> the WORKING id is persisted, never the dead first" );

	// E2: no candidate answers -> null and nothing is persisted.
	reset_state();
	$GLOBALS['get_router'] = $seed_router;
	$GLOBALS['post_router'] = array(
		$DEAD1 => resp( 200, '{"content":[],"profiles":[]}' ),
		$DEAD2 => resp( 404, 'Server action not found.' ),
	);
	$hit = $resolve->invoke( $obj, $GUID, $TITLE );
	ok( null === $hit, "[$short] resolve -> every candidate dead -> null" );
	ok( array( $DEAD1, $GOOD, $DEAD2 ) === $posted(), "[$short] resolve -> all 3 candidates probed in order, none persisted (got " . implode( ',', $posted() ) . ")" );
	ok( array() === $GLOBALS['option_log'], "[$short] resolve -> dead candidates never write the stored id" );

	// E3: a candidate that parses hits but lacks our guid is a LIVE id -
	//     persist it (so the crawl stops) and don't burn POSTs on the rest.
	reset_state();
	$GLOBALS['get_router'] = array_merge( $seed_router, array(
		'https://fountain.fm/_next/static/immutable/chunks/delta.js' => resp( 200, $alive_hit ),
	) );
	$GLOBALS['post_router'] = array(
		$DEAD1 => resp( 200, '{"content":[],"profiles":[]}' ),
		$ALIVE => resp( 200, $HITS_NO_TGT ),
		$GOOD  => resp( 200, $HITS_TRACK ),
	);
	$hit = $resolve->invoke( $obj, $GUID, $TITLE );
	ok( null === $hit, "[$short] resolve -> live id without our guid -> null" );
	ok( array( $DEAD1, $ALIVE ) === $posted(), "[$short] resolve -> stops at the first id that answers with hits (got " . implode( ',', $posted() ) . ")" );
	ok( isset( $GLOBALS['options'][$opt_key] ) && $ALIVE === $GLOBALS['options'][$opt_key], "[$short] resolve -> live-but-no-match id is persisted" );

	// E4: a stored DEAD id is tried once, then the candidates (no repeat POST
	//     for the same id) - and a working one replaces it.
	reset_state();
	$GLOBALS['options'][$opt_key] = $DEAD1;
	$GLOBALS['get_router'] = $seed_router;
	$GLOBALS['post_router'] = array(
		$DEAD1 => resp( 200, '{"content":[],"profiles":[]}' ),
		$GOOD  => resp( 200, $HITS_TRACK ),
	);
	$hit = $resolve->invoke( $obj, $GUID, $TITLE );
	ok( is_array( $hit ), "[$short] resolve -> self-heal recovers from a stored dead id" );
	ok( array( $DEAD1, $GOOD ) === $posted(), "[$short] resolve -> stored id POSTed once, then candidates (got " . implode( ',', $posted() ) . ")" );
	ok( isset( $GLOBALS['options'][$opt_key] ) && $GOOD === $GLOBALS['options'][$opt_key], "[$short] resolve -> stored id replaced by the working one" );

	// E5: a stored id that already answers (readable hits, no match) is fine -
	//     no crawl at all, option untouched.
	reset_state();
	$GLOBALS['options'][$opt_key] = $ALIVE;
	$GLOBALS['post_router'] = array( $ALIVE => resp( 200, $HITS_NO_TGT ) );
	$hit = $resolve->invoke( $obj, $GUID, $TITLE );
	ok( null === $hit, "[$short] resolve -> stored live id, unknown release -> null" );
	ok( 1 === count( $GLOBALS['post_log'] ), "[$short] resolve -> stored live id -> exactly one POST" );
	ok( 0 === count( $GLOBALS['get_log'] ), "[$short] resolve -> stored live id -> no autodetect crawl" );
	ok( array() === $GLOBALS['option_log'], "[$short] resolve -> stored live id -> option untouched" );

	// E6: a stored id that resolves -> hit, no crawl, no option churn.
	reset_state();
	$GLOBALS['options'][$opt_key] = $GOOD;
	$GLOBALS['post_router'] = array( $GOOD => resp( 200, $HITS_TRACK ) );
	$hit = $resolve->invoke( $obj, $GUID, $TITLE );
	ok( is_array( $hit ) && 'qkMEo45HgMB0uZ5tUlXE' === $hit['id'], "[$short] resolve -> stored working id -> hit" );
	ok( 0 === count( $GLOBALS['get_log'] ), "[$short] resolve -> stored working id -> no autodetect crawl" );
	ok( array() === $GLOBALS['option_log'], "[$short] resolve -> stored working id -> option untouched" );

	// E7: stored dead id + no working candidate -> the option keeps its value
	//     (a dead candidate must never corrupt what is already stored).
	reset_state();
	$GLOBALS['options'][$opt_key] = $DEAD1;
	$GLOBALS['get_router'] = $seed_router;
	$GLOBALS['post_router'] = array(
		$DEAD1 => resp( 200, '{"content":[],"profiles":[]}' ),
		$DEAD2 => resp( 404, 'Server action not found.' ),
	);
	$hit = $resolve->invoke( $obj, $GUID, $TITLE );
	ok( null === $hit, "[$short] resolve -> no working candidate -> null" );
	ok( array( $DEAD1, $GOOD, $DEAD2 ) === $posted(), "[$short] resolve -> every candidate probed in order (got " . implode( ',', $posted() ) . ")" );
	ok( array() === $GLOBALS['option_log'], "[$short] resolve -> stored id survives an unsuccessful self-heal" );
}

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
