<?php
/**
 * Behavioural test for the v4vmusic.com guid-lookup button.
 *
 * Loads both real plugin files with WordPress stubbed out and calls the
 * public render() with the shortcode attributes a page would supply,
 * asserting the v4vmusic.com button URL shape:
 *
 *   album + track  -> https://v4vmusic.com/lookup/{album}/{track}
 *   album only     -> https://v4vmusic.com/lookup/{album}
 *   partial titles -> button still renders (it no longer needs titles)
 *
 * and that the old title-based v4vmusic /search?q=... URL is gone for
 * good (the Fountain failure fallback legitimately renders a /search?q=
 * URL, but on fountain.fm - so the ban is scoped to v4vmusic.com).
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

class Fake_WP_Error { public $msg = 'stubbed transport failure'; }

$GLOBALS['fake_response'] = new Fake_WP_Error(); // fountain self-heal fails fast -> plain link
$GLOBALS['transients']    = array();

function is_wp_error( $x )                          { return $x instanceof Fake_WP_Error; }
function wp_remote_get( $url, $args = array() )     { return $GLOBALS['fake_response']; }
function wp_remote_post( $url, $args = array() )    { return $GLOBALS['fake_response']; }
function wp_remote_retrieve_response_code( $r )     { return is_array($r) && isset($r['response']['code']) ? (int) $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )              { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }
function get_transient( $k )                        { return array_key_exists($k, $GLOBALS['transients']) ? $GLOBALS['transients'][$k] : false; }
function set_transient( $k, $v, $ttl )              { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient( $k )                     { unset( $GLOBALS['transients'][$k] ); return true; }
function add_shortcode() {}
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function get_option( $k, $d = false )               {
	if ( 'v4v_musiclink_cache_table_version' === $k || 'v4v_musiclink_pro_cache_table_version' === $k ) { return '1'; }
	if ( 'v4v_musiclink_pro_log_table_version' === $k ) { return '2'; }
	return $d; // apps: defaults = enabled, no icon overrides, registry order
}
function update_option( $k, $v, $a = false )        { return true; }
function home_url( $p = '' )                        { return 'http://example.test' . $p; }
function __( $s, $d = null )                        { return $s; }
function _e( $s, $d = null )                        { echo $s; }
function esc_html( $s )                             { return $s; }
function esc_attr( $s )                             { return $s; }
function esc_url( $s )                              { return $s; }
function esc_html_e( $s, $d = null )                { echo $s; }
function esc_attr_e( $s, $d = null )                { echo $s; }
function esc_textarea( $s )                         { return $s; }
function sanitize_text_field( $s )                  { return is_string($s) ? trim( strip_tags($s) ) : $s; }
function wp_unslash( $s )                           { return $s; }
function shortcode_atts( $pairs, $atts, $sc = '' )  { return array_merge( $pairs, (array) $atts ); }
function absint( $n )                               { return abs( (int) $n ); }
function current_time( $type = 'mysql' )            { return '2026-10-01 12:00:00'; }
function get_date_from_gmt( $ts, $format = 'Y-m-d H:i:s' ) { return $ts; }
function wp_timezone()                              { return new DateTimeZone( 'UTC' ); }
function plugin_dir_url( $f )                       { return 'http://example.test/plugin/'; }
function plugins_url( $p = '', $f = '' )            { return 'http://example.test/plugin/' . $p; }
function did_action( $h )                           { return 0; }
function is_admin()                                 { return false; }
function wp_doing_ajax()                            { return false; }
function current_user_can( $c )                     { return true; }
function wp_create_nonce( $a )                      { return 'stub-nonce'; }
function wp_verify_nonce( $n, $a )                  { return 1; }
function check_ajax_referer( $a, $q = true )        { return true; }
function wp_json_encode( $d )                       { return json_encode( $d ); }
function wp_parse_url( $u, $c = -1 )                { return parse_url( $u, $c ); }
function wp_parse_args( $a, $d )                    { return array_merge( (array) $d, (array) $a ); }
function add_query_arg( ...$args ) {
	// Mirrors WP: missing/false/null URL arg means "current request URI",
	// and add_query_arg( null, null ) is the WP idiom for "URI unchanged".
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
	$pairs = array();
	if ( is_array( $args[0] ) ) {
		$pairs = $args[0];
		if ( count( $args ) >= 2 && false !== $args[1] && null !== $args[1] ) { $uri = $args[1]; }
	} else {
		if ( count( $args ) >= 2 ) { $pairs[ (string) ( $args[0] ?? '' ) ] = $args[1] ?? ''; }
		if ( count( $args ) >= 3 && false !== $args[2] && null !== $args[2] ) { $uri = $args[2]; }
	}
	$qs = array();
	foreach ( $pairs as $k => $v ) {
		if ( '' === (string) $k && null === $v ) { continue; } // the null,null idiom
		$qs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
	}
	if ( ! $qs ) { return $uri; }
	$sep = ( false === strpos( $uri, '?' ) ) ? '?' : '&';
	return $uri . $sep . implode( '&', $qs );
}
function remove_query_arg( $keys, $url = '' ) {
	foreach ( (array) $keys as $k ) {
		$url = preg_replace( '/([?&])' . preg_quote( $k, '/' ) . '=[^&]*(&|$)/', '$1', $url );
	}
	return preg_replace( '/[?&]$/', '', $url );
}
function wp_kses( $s, $a = '' )                     { return $s; }
function wp_kses_post( $s )                         { return $s; }
function get_file_data( $f, $h, $c = '' )           { $out = array(); foreach ( $h as $k => $v ) { $out[ $k ] = '0.0.0'; } return $out; }
function wp_die( $m = '' )                          { throw new RuntimeException( 'wp_die: ' . $m ); }
function wp_send_json( $d )                         { throw new RuntimeException( 'wp_send_json' ); }
function wp_send_json_error( $d = null, $c = null ) { throw new RuntimeException( 'wp_send_json_error' ); }
function wp_send_json_success( $d = null )          { throw new RuntimeException( 'wp_send_json_success' ); }

// --- $wpdb stub: the render path reads a cache row before anything else.
// Rows are seeded per case below; an absent row falls through to the
// transport layer, so the tests keep exercising the search/fallback path. ---
class Wpdb_Stub {
	public $prefix     = 'wp_';
	public $cache_rows = array();
	public $last_hash  = null;
	public function get_charset_collate()          { return ''; }
	public function esc_like( $t )                 { return $t; }
	public function prepare( $sql, ...$args )      {
		// prepare() here does not interpolate, so the hash the cache
		// lookup binds is captured from the arguments instead.
		if ( false !== strpos( $sql, 'lookup_hash = %s' ) && isset( $args[0] ) ) {
			$this->last_hash = (string) $args[0];
		}
		return $sql;
	}
	public function get_var( $sql )                { return ''; }
	public function get_col( $sql )                { return array(); }
	public function get_results( $sql )            { return array(); }
	public function get_row( $sql ) {
		$hash = $this->last_hash;
		return ( null !== $hash && isset( $this->cache_rows[ $hash ] ) ) ? $this->cache_rows[ $hash ] : null;
	}
	public function query( $sql )                  { return 1; }
	public function insert( $t, $d, $f = null )    { return 1; }
	public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
	public function delete( $t, $w, $f = null )    { return 1; }
}
$GLOBALS['wpdb'] = new Wpdb_Stub();

/** Seeds one cache row; $fields holds the MI half of the row. */
function seed_row( $album, $track, array $fields ) {
	global $wpdb;
	$hash                = sha1( $album . '|' . $track );
	$wpdb->cache_rows[ $hash ] = (object) array(
		'id'                  => count( $wpdb->cache_rows ) + 1,
		'lookup_hash'         => $hash,
		'album_guid'          => $album,
		'track_guid'          => $track,
		'album_title'         => $fields['album_title'] ?? '',
		'track_title'         => $fields['track_title'] ?? '',
		'artist'              => $fields['artist'] ?? '',
		'image_url'           => $fields['image'] ?? '',
		'fountain_url'        => $fields['fountain_url'] ?? '',
		'mi_updated_at'       => '2099-01-01 00:00:00',
		'fountain_updated_at' => '',
	);
}

require __DIR__ . '/../v4v-musiclink/v4v-musiclink.php';

$album = 'd5c805fb-6016-4038-9cda-2e68d2b8dcc4';
$track = 'cbdaeaa0-3c58-41ce-92d1-c718710ade54';

$track_ok  = array( 'title' => 'See Through', 'album_title' => 'The Singles', 'artist' => 'Fentlay', 'image' => 'http://x/y.jpg' );
$feed_ok   = array( 'title' => 'The Singles', 'artist' => 'Fentlay', 'image' => 'http://x/y.jpg' );

$classes = array(
	'V4V_Music_Link_Resolver'      => array( 'mi_track_v4_', 'mi_feed_v4_' ),
);

$pass = 0; $fail = 0;

function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS  $label\n"; }
	else       { $fail++; echo "FAIL  $label $detail\n"; }
}

foreach ( $classes as $class => $keys ) {
	list( $tp, $fp ) = $keys;
	$rc  = new ReflectionClass( $class );
	$obj = $rc->newInstanceWithoutConstructor();

	// Drops the per-request cache-row memo so each case reads the rows
	// seeded for THAT case instead of the ones an earlier case already
	// looked up on this same instance.
	$clear_memo = function ( $inst ) {
		$rp = new ReflectionProperty( get_class( $inst ), 'row_memo' );
		$rp->setValue( $inst, array() );
	};

	// --- Case A: album + track, everything resolves ------------------------
	$GLOBALS['transients'] = array();
	$GLOBALS['wpdb']->cache_rows = array();
	seed_row( $album, $track, array(
		'album_title' => $track_ok['album_title'],
		'track_title' => $track_ok['title'],
		'artist'      => $track_ok['artist'],
		'image'       => $track_ok['image'],
	) );
	seed_row( $album, '', array(
		'album_title' => $feed_ok['title'],
		'artist'      => $feed_ok['artist'],
		'image'       => $feed_ok['image'],
	) );
	$want = 'https://v4vmusic.com/lookup/' . $album . '/' . $track;
	ob_start();
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	$stray = ob_get_clean();
	check( "[$class] A: stray output while rendering", '' === $stray, var_export( $stray, true ) );
	check( "[$class] A: album+track lookup URL", false !== strpos( $html, $want ), $want );
	check( "[$class] A: old v4vmusic /search?q= URL is gone", false === strpos( $html, 'v4vmusic.com/search?q=' ) );

	// --- Icons: read off each app's own domain, never a third party ------
	check( "[$class] I: stablekraft icon is its own favicon.ico", false !== strpos( $html, 'https://stablekraft.app/favicon.ico' ) );
	check( "[$class] I: podtunes icon is its own favicon.png (its /favicon.ico 404s)", false !== strpos( $html, 'https://podtunes.app/favicon.png' ) );
	check( "[$class] I: v4vmusic icon is its own favicon.ico", false !== strpos( $html, 'https://v4vmusic.com/favicon.ico' ) );
	check( "[$class] I: fountain icon is its own favicon.ico", false !== strpos( $html, 'https://fountain.fm/favicon.ico' ) );
	check( "[$class] I: no Google favicon proxy anywhere in the output", false === strpos( $html, 'google.com/s2' ) );

	// --- Case B: album only ------------------------------------------------
	$GLOBALS['transients'] = array();
	$GLOBALS['wpdb']->cache_rows = array();
	$clear_memo( $obj );
	seed_row( $album, '', array(
		'album_title' => $feed_ok['title'],
		'artist'      => $feed_ok['artist'],
		'image'       => $feed_ok['image'],
	) );
	$want = 'https://v4vmusic.com/lookup/' . $album;
	$html = $obj->render( array( 'album' => $album ) );
	check( "[$class] B: album-only lookup URL", false !== strpos( $html, $want . '"' ) || false !== strpos( $html, $want . "'" ), $want );
	check( "[$class] B: no track segment appended", false === strpos( $html, 'https://v4vmusic.com/lookup/' . $album . '/' ) );
	check( "[$class] B: old v4vmusic /search?q= URL is gone", false === strpos( $html, 'v4vmusic.com/search?q=' ) );

	// --- Case C: track lookup misses, feed resolves (partial titles) -------
	// Before the guid-lookup change this rendered NO v4vmusic button at all.
	// The track half is remembered as a failure (negative transient, key
	// unchanged by the cache-table work); only the feed row exists, so the
	// row read misses and the negative check stops the track HTTP call.
	$GLOBALS['transients'] = array(
		$tp . md5( $album . '|' . $track ) => array(),      // negative cache: track miss
	);
	$GLOBALS['wpdb']->cache_rows = array();
	$clear_memo( $obj );
	seed_row( $album, '', array(
		'album_title' => $feed_ok['title'],
		'artist'      => $feed_ok['artist'],
		'image'       => $feed_ok['image'],
	) );
	$want = 'https://v4vmusic.com/lookup/' . $album . '/' . $track;
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] C: gate passed on album title", false === strpos( $html, 'Album/Song not found' ) );
	check( "[$class] C: button renders despite missing track title", false !== strpos( $html, $want ), $want );
	check( "[$class] C: header shows what resolved", false !== strpos( $html, 'The Singles' ) );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
