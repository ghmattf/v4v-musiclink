<?php
/**
 * The standard plugin's shared message block must show a "V4V MusicLink"
 * heading ABOVE the message for every path into render_missing_params_message():
 *
 *   - bare [musiclink] (no attributes)          -> "Missing parameter(s)"
 *   - unexpected attribute name                  -> "Missing parameter(s)"
 *   - guid that doesn't resolve in Music Index   -> "Album/Song not found"
 *
 * Stub preamble reused from mi_ttl_test.php (loads the real plugin file
 * with WordPress stubbed out; render() needs no constructor state).
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

class Fake_WP_Error { public $msg = 'stubbed transport failure'; }

$GLOBALS['fake_response'] = null;
$GLOBALS['transients']    = array();
$GLOBALS['last_ttl']      = null;

function is_wp_error( $x )                          { return $x instanceof Fake_WP_Error; }
function wp_remote_get( $url, $args = array() )     { return $GLOBALS['fake_response']; }
function wp_remote_post( $url, $args = array() )    { return $GLOBALS['fake_response']; }
function wp_remote_retrieve_response_code( $r )     { return is_array($r) && isset($r['response']['code']) ? (int) $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )              { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }
function get_transient( $k )                        { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl )              { $GLOBALS['transients'][ $k ] = $v; $GLOBALS['last_ttl'] = $ttl; return true; }
function delete_transient( $k )                     { unset( $GLOBALS['transients'][ $k ] ); return true; }
function add_shortcode() {}
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function get_option( $k, $d = false )               {
	if ( 'v4v_musiclink_cache_table_version' === $k ) { return '1'; }
	return $d;
}
function update_option( $k, $v, $a = false )        { return true; }
function home_url( $p = '' )                        { return 'http://example.test' . $p; }
function __( $s, $d = null )                        { return $s; }
function _e( $s, $d = null )                        { echo $s; }
function esc_html( $s )                             { return $s; }
function esc_attr( $s )                             { return $s; }
function esc_url( $s )                              { return $s; }
function esc_html_e( $s, $d = null )                { echo $s; }
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
function wp_die( $m = '' )                          { throw new RuntimeException( 'wp_die: ' . $m ); }
function wp_send_json( $d )                         { throw new RuntimeException( 'wp_send_json' ); }
function wp_send_json_error( $d = null, $c = null ) { throw new RuntimeException( 'wp_send_json_error' ); }
function wp_send_json_success( $d = null )          { throw new RuntimeException( 'wp_send_json_success' ); }

// --- $wpdb stub: render() reads a cache row before it ever hits the
// transport above. Nothing is stored here, so every lookup misses and
// falls through to the scripted wp_remote_get()/wp_remote_post(). ---
class Wpdb_Stub {
	public $prefix = 'wp_';
	public function get_charset_collate()        { return ''; }
	public function esc_like( $t )               { return $t; }
	public function prepare( $sql, ...$args )    { return $sql; }
	public function get_var( $sql )              { return null; }
	public function get_col( $sql )              { return array(); }
	public function get_results( $sql )          { return array(); }
	public function get_row( $sql )              { return null; }
	public function query( $sql )                { return 1; }
	public function insert( $t, $d, $f = null )  { return 1; }
}
$GLOBALS['wpdb'] = new Wpdb_Stub();

require __DIR__ . '/../v4v-musiclink/v4v-musiclink.php';

$pass = 0; $fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label" . ( $detail ? " ($detail)" : "" ) . "\n"; }
}

function assert_block( $label, $out, $message ) {
	$h = strpos( $out, 'V4V MusicLink' );
	$m = strpos( $out, $message );
	check( "$label: message shown", false !== $m, 'missing: ' . $message );
	check( "$label: h1 title markup", false !== strpos( $out, '<h1 class="v4v-musiclink-title">V4V MusicLink</h1>' ) );
	check( "$label: heading ABOVE message", ( false !== $h && false !== $m && $h < $m ),
		'h@' . var_export( $h, true ) . ' msg@' . var_export( $m, true ) );
	check( "$label: heading exactly once", 1 === substr_count( $out, 'V4V MusicLink' ),
		'count=' . substr_count( $out, 'V4V MusicLink' ) );
}

$rc  = new ReflectionClass( 'V4V_Music_Link_Resolver' );
$obj = $rc->newInstanceWithoutConstructor();

// 1. Bare shortcode: no attributes at all.
$out = $obj->render( array() );
assert_block( 'bare shortcode', $out, 'Missing parameter(s)' );

// 2. Unexpected attribute name: all attributes ignored.
$out = $obj->render( array( 'id' => 'oops' ) );
assert_block( 'unexpected attr', $out, 'Missing parameter(s)' );

// 3. Album guid that doesn't resolve (definitive 404 from Music Index).
$GLOBALS['fake_response'] = array( 'response' => array( 'code' => 404 ), 'body' => '' );
$GLOBALS['transients']    = array();
$out = $obj->render( array( 'album' => 'not-a-real-guid' ) );
assert_block( 'unresolved guid', $out, 'Album/Song not found' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
