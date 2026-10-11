<?php
/**
 * Block-editor builder REST surface (O19): route registration, the
 * manage_options permission gate, the Music Index search endpoint
 * (shape, dedupe, has_more, resolution side-effects), the cache-row
 * fountain-link read/write endpoints (validation, fountain-half-only
 * updates, minimal-row creation, memo invalidation) and the live canvas
 * preview endpoint /render (validation, header passthrough, cached-row
 * output) - plus the admin-only gate on enqueue_block_editor_assets().
 *
 * Loads the real standard plugin file with WordPress stubbed out,
 * including a small in-memory $wpdb that speaks the one INSERT ...
 * ON DUPLICATE KEY UPDATE shape rest_update_row() issues. The block JS
 * itself has no offline test (no JS runtime in the toolchain); its
 * enqueue is pinned here instead.
 *
 * Run: ./php/php tmp/test_block_rest.php
 */

define( 'ABSPATH', __DIR__ . '/wpstub/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$pass = 0;
$fail = 0;

function ok( $cond, $label, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		printf( "  PASS  %s\n", $label );
	} else {
		$fail++;
		printf( "  FAIL  %s%s\n", $label, '' !== $detail ? ' :: ' . $detail : '' );
	}
}

// --- WP_Error (the REST gates return one on refusal) ----------------------
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code()    { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data()    { return $this->data; }
}
class Fake_WP_Error { public $msg = 'stubbed transport failure'; }

// --- globals the stubs read ----------------------------------------------
$GLOBALS['__options'] = array(
	'v4v_musiclink_cache_table_version'     => '1',
	'v4v_musiclink_pro_cache_table_version' => '1',
	'v4v_musiclink_pro_log_table_version'   => '2',
);
$GLOBALS['transients']        = array();
$GLOBALS['http_calls']        = array();
$GLOBALS['fake_response']     = new Fake_WP_Error();
$GLOBALS['mi_search_response'] = new Fake_WP_Error();
$GLOBALS['mi_search_queue']   = array();
$GLOBALS['mi_detail']         = array();
$GLOBALS['__hooks']           = array();
$GLOBALS['__routes']          = array();
$GLOBALS['__can_manage']      = true;
$GLOBALS['__scripts']         = array();
$GLOBALS['__enqueued']        = array();
$GLOBALS['__inline']          = array();
$GLOBALS['__styles']          = array();
$GLOBALS['__styles_enqueued'] = array();
$GLOBALS['__style_inline']    = array();
$GLOBALS['__screen']          = null;
$GLOBALS['__has_filter']      = array();

// --- transport ------------------------------------------------------------
function is_wp_error( $x )                          { return $x instanceof Fake_WP_Error; }
function wp_response( $body ) {
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['http_calls'][] = 'GET ' . $url;
	$parts = wp_parse_url( $url );
	if ( isset( $parts['host'] ) && 'api.musicindex.org' === $parts['host'] ) {
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( '/v1/search' === $path ) {
			// Optional per-call queue: a static fixture's cursor never
			// advances, which mi_search_hits() correctly reads as "no
			// progress - stop", so has_more chains need staged responses.
			if ( ! empty( $GLOBALS['mi_search_queue'] ) ) {
				return array_shift( $GLOBALS['mi_search_queue'] );
			}
			return $GLOBALS['mi_search_response'];
		}
		if ( isset( $GLOBALS['mi_detail'][ $path ] ) ) {
			return $GLOBALS['mi_detail'][ $path ];
		}
		return new Fake_WP_Error();
	}
	return $GLOBALS['fake_response'];
}
function wp_remote_retrieve_response_code( $r )     { return is_array($r) && isset($r['response']['code']) ? (int)$r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )              { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }

// --- options / transients -------------------------------------------------
function get_transient( $k )   { return array_key_exists($k, $GLOBALS['transients']) ? $GLOBALS['transients'][$k] : false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient( $k )        { unset($GLOBALS['transients'][$k]); return true; }
function get_option( $k, $d = false )  { return array_key_exists($k, $GLOBALS['__options']) ? $GLOBALS['__options'][$k] : $d; }
function update_option( $k, $v, $a = false ) { $GLOBALS['__options'][$k] = $v; return true; }
function delete_option( $k )           { unset($GLOBALS['__options'][$k]); return true; }

// --- WP plumbing ----------------------------------------------------------
function add_shortcode() {}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['__hooks'][ $tag ][] = $cb; }
function add_filter() {}
// Flag-driven: true only when the test (or core's iframe-asset capture)
// has "attached" the callback - mirrors has_filter( $tag, $cb ) returning
// the priority (truthy) vs false.
function has_filter( $tag, $callback = false ) {
	return ! empty( $GLOBALS['__has_filter'][ $tag ] ) ? 10 : false;
}
function get_current_screen() { return $GLOBALS['__screen']; }
class Fake_Screen {
	public function is_block_editor() { return true; }
}
function register_activation_hook() {}
function home_url( $p = '' )  { return 'http://example.test' . $p; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function did_action( $h )     { return 0; }
function is_admin()           { return true; }
function absint( $n )         { return abs( (int) $n ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_parse_args( $a, $d = null ) { return array_merge( (array) $d, (array) $a ); }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
// Identity: tests seed site-local timestamps, and row_mi_is_fresh()
// compares those directly against a cutoff built through this function.
function get_date_from_gmt( $string, $format = 'Y-m-d H:i:s' ) { return $string; }
function wp_unslash( $v )     { return $v; }
function get_file_data( $f, $h, $c = '' ) { $out = array(); foreach ( $h as $k => $v ) { $out[$k] = '0.0.0'; } return $out; }

// --- escaping / i18n ------------------------------------------------------
function __( $s, $d = null )    { return $s; }
function esc_html( $s )         { return $s; }
function esc_attr( $s )         { return $s; }
function esc_url( $s )          { return $s; }
function esc_url_raw( $s )      { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function sanitize_text_field( $s ) { return is_string($s) ? trim( strip_tags($s) ) : $s; }
function sanitize_key( $s )     { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); }

// --- admin / capability ---------------------------------------------------
function current_time( $type )  { return 'mysql' === $type ? '2026-09-26 12:00:00' : time(); }
function current_user_can( $c ) { return (bool) $GLOBALS['__can_manage']; }
function wp_die( $m = '' )      { throw new RuntimeException( 'wp_die: ' . $m ); }

// --- script API (enqueue gate assertions) ---------------------------------
function wp_register_script( $handle, $src = false, $deps = array(), $ver = false, $footer = false ) {
	$GLOBALS['__scripts'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}
function wp_enqueue_script( $handle = '' ) { $GLOBALS['__enqueued'][] = $handle; }
function wp_add_inline_script( $handle, $data, $pos = 'after' ) { $GLOBALS['__inline'][ $handle ] = $data; }

// --- style API (editor CSS enqueue assertions) ----------------------------
function wp_register_style( $handle, $src = false, $deps = array(), $ver = false ) {
	$GLOBALS['__styles'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}
function wp_enqueue_style( $handle = '' ) { $GLOBALS['__styles_enqueued'][] = $handle; }
function wp_add_inline_style( $handle, $data ) { $GLOBALS['__style_inline'][ $handle ] = $data; }

// --- REST -----------------------------------------------------------------
function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['__routes'][] = array( 'ns' => $namespace, 'route' => $route, 'args' => $args );
}

/**
 * Minimal REST request: get_param() over a flat array (JSON body params
 * arrive the same way on real WP_REST_Request).
 */
class Fake_Rest_Request {
	private $params;
	public function __construct( $params = array() ) { $this->params = $params; }
	public function get_param( $k ) { return array_key_exists( $k, $this->params ) ? $this->params[ $k ] : null; }
}

/**
 * In-memory $wpdb for the shapes this feature issues: SELECT by hash and
 * the one INSERT ... ON DUPLICATE KEY UPDATE (fountain half). Literal
 * NULL in the VALUES list lands as the string 'NULL' through
 * str_getcsv(), so it is normalized back to null on read-back.
 */
class Fake_Wpdb {
	public $prefix   = 'wp_';
	public $data     = array(); // table => rows
	public $queries  = array();
	private $next_id = 1;

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$v = isset( $args[ $i ] ) ? $args[ $i ] : '';
			$i++;
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function rows_in( $table ) {
		return isset( $this->data[ $table ] ) ? $this->data[ $table ] : array();
	}
	public function seed( $table, $row ) {
		$rows          = $this->rows_in( $table );
		$row['id']     = $this->next_id++;
		$rows[]        = $row;
		$this->data[ $table ] = $rows;
		return $row['id'];
	}
	public function row_by_hash( $table, $hash ) {
		foreach ( $this->rows_in( $table ) as $r ) {
			if ( isset( $r['lookup_hash'] ) && $r['lookup_hash'] === $hash ) { return $r; }
		}
		return null;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;

		if ( preg_match( "/^INSERT INTO\s+(\S+)\s*\((.*?)\)\s*VALUES\s*\((.*?)\)\s*ON DUPLICATE KEY UPDATE\s*(.*)$/is", $sql, $m ) ) {
			$table = $m[1];
			$cols  = array_map( 'trim', explode( ',', $m[2] ) );
			$vals  = str_getcsv( $m[3], ',', "'", '\\' );
			if ( count( $cols ) !== count( $vals ) ) { return 0; }
			$row = array_combine( $cols, $vals );
			foreach ( $row as $k => $v ) {
				if ( is_string( $v ) && 'NULL' === trim( $v ) ) { $row[ $k ] = null; }
			}

			$assign = array();
			if ( preg_match_all( '/(\w+)\s*=\s*VALUES\(\s*(\w+)\s*\)/', $m[4], $mm, PREG_SET_ORDER ) ) {
				foreach ( $mm as $a ) { $assign[ $a[1] ] = $row[ $a[2] ]; }
			}

			$existing = $this->row_by_hash( $table, $row['lookup_hash'] );
			if ( $existing ) {
				foreach ( $this->rows_in( $table ) as $i => $r ) {
					if ( (int) $r['id'] === (int) $existing['id'] ) {
						foreach ( $assign as $k => $v ) { $this->data[ $table ][ $i ][ $k ] = $v; }
					}
				}
				return 1;
			}

			$row['id'] = $this->next_id++;
			$this->data[ $table ][] = $row;
			return 1;
		}

		return 1;
	}
	public function get_row( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/SELECT \* FROM\s+(\S+)\s+WHERE lookup_hash = '([^']*)'/", $sql, $m ) ) {
			$row = $this->row_by_hash( $m[1], $m[2] );
			return $row ? (object) $row : null;
		}
		return null;
	}
}

// --- load the real plugin -------------------------------------------------
$GLOBALS['wpdb'] = new Fake_Wpdb();

require_once dirname( __DIR__ ) . '/v4v-musiclink/v4v-musiclink.php';

$obj = new V4V_Music_Link_Resolver();
$table = 'wp_v4v_musiclink_cache';

function reset_state() {
	global $obj;
	$GLOBALS['wpdb']         = new Fake_Wpdb();
	$GLOBALS['transients']   = array();
	$GLOBALS['http_calls']   = array();
	$GLOBALS['mi_search_response'] = new Fake_WP_Error();
	$GLOBALS['mi_search_queue'] = array();
	$GLOBALS['mi_detail']    = array();
	// The plugin memoizes row reads per request; a fresh $wpdb means every
	// memo entry is a lie, so drop them (real requests never swap $wpdb).
	$prop = new ReflectionProperty( get_class( $obj ), 'row_memo' );
	$prop->setValue( $obj, array() );
}
function insert_queries() {
	$out = array();
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( 0 === stripos( $q, 'INSERT INTO' ) ) { $out[] = $q; }
	}
	return $out;
}

// ==========================================================================
echo "-- route registration --\n";
$GLOBALS['__routes'] = array();
call_user_func( $GLOBALS['__hooks']['rest_api_init'][0] );

$search_route = null;
$row_routes   = array();
$render_route = null;
foreach ( $GLOBALS['__routes'] as $r ) {
	if ( 'v4v-musiclink/v1' !== $r['ns'] ) { continue; }
	if ( '/search' === $r['route'] ) { $search_route = $r['args']; }
	if ( '/row' === $r['route'] )    { $row_routes   = $r['args']; }
	if ( '/render' === $r['route'] ) { $render_route = $r['args']; }
}
ok( is_array( $search_route ) && 'GET' === $search_route['methods'], 'GET /v4v-musiclink/v1/search registered' );
	// The plugin file self-instantiates on load, so the recorded hook may
	// belong to that instance rather than $obj - assert the callable's
	// shape, not object identity.
ok( is_callable( $search_route['permission_callback'] )
	&& is_array( $search_route['permission_callback'] )
	&& 'rest_permission' === $search_route['permission_callback'][1], 'search route gated by rest_permission' );
ok( is_array( $row_routes ) && 2 === count( $row_routes ), 'row route has GET + PUT endpoints', count( (array) $row_routes ) . ' endpoints' );
$methods = array();
foreach ( (array) $row_routes as $ep ) { $methods[] = $ep['methods']; }
sort( $methods );
ok( array( 'GET', 'PUT' ) === $methods, 'row endpoints are GET and PUT', implode( ',', $methods ) );
foreach ( (array) $row_routes as $ep ) {
	ok( is_callable( $ep['permission_callback'] )
		&& is_array( $ep['permission_callback'] )
		&& 'rest_permission' === $ep['permission_callback'][1], 'row endpoint gated by rest_permission' );
}
ok( is_array( $render_route ) && 'GET' === $render_route['methods'], 'GET /v4v-musiclink/v1/render registered' );
ok( is_callable( $render_route['permission_callback'] )
	&& is_array( $render_route['permission_callback'] )
	&& 'rest_permission' === $render_route['permission_callback'][1], 'render route gated by rest_permission' );

echo "-- permission gate --\n";
$GLOBALS['__can_manage'] = true;
ok( true === $obj->rest_permission(), 'manage_options passes' );
$GLOBALS['__can_manage'] = false;
$denied = $obj->rest_permission();
ok( $denied instanceof WP_Error, 'without manage_options returns WP_Error' );
ok( 403 === ( $denied->get_error_data()['status'] ?? null ), 'refusal is 403', json_encode( $denied->get_error_data() ) );
$GLOBALS['__can_manage'] = true;

echo "-- enqueue gate --\n";
$GLOBALS['__scripts']  = array();
$GLOBALS['__enqueued'] = array();
$GLOBALS['__inline']   = array();
$GLOBALS['__styles']          = array();
$GLOBALS['__styles_enqueued'] = array();
$GLOBALS['__style_inline']    = array();
$GLOBALS['__screen']          = null;
$GLOBALS['__has_filter']      = array();
$GLOBALS['__can_manage'] = false;
$obj->enqueue_block_editor_assets();
ok( ! isset( $GLOBALS['__scripts']['v4v-musiclink-block'] ), 'non-admin: block script not registered' );
$GLOBALS['__can_manage'] = true;
$obj->enqueue_block_editor_assets();
ok( isset( $GLOBALS['__scripts']['v4v-musiclink-block'] ), 'admin: block script registered' );
$reg = $GLOBALS['__scripts']['v4v-musiclink-block'];
ok( array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-api-fetch' ) === $reg['deps'], 'block script declares its editor deps', json_encode( $reg['deps'] ) );
ok( false === $reg['src'], 'block script is src-false (single-file convention, no URL to cache-bust)' );
ok( in_array( 'v4v-musiclink-block', $GLOBALS['__enqueued'], true ), 'block script enqueued' );
$js = $GLOBALS['__inline']['v4v-musiclink-block'] ?? '';
ok( false !== strpos( $js, "registerBlockType( 'v4v-musiclink/musiclink'" ), 'inline JS registers the block' );
ok( false === strpos( $js, "'fountain=\"'" ), 'inline JS never builds a fountain= attribute (O18-independent)', 'fountain=" found' );
ok( false !== strpos( $js, '[musiclink ' ), 'inline JS builds the shortcode string' );
ok( false !== strpos( $js, '/render?album=' ), 'inline JS fetches the live render preview' );
ok( false !== strpos( $js, 'Release details' ), 'inline JS has the read-only Release details panel' );
ok( false !== strpos( $js, 'v4v-ml-info-row' ), 'release details render as plain text rows (no inputs)' );
ok( false !== strpos( $js, "'v4v-ml-search-open'" ), 'search starts collapsed behind a Search button (O23)' );
ok( false !== strpos( $js, 'onKeyDown' ), 'search area submits on Enter and closes on Escape (O23)' );
ok( false !== strpos( $js, "'Close'" ), 'search area closes without touching attributes (O23)' );
ok( ! isset( $GLOBALS['__styles']['v4v-musiclink-editor'] ), 'styles deliberately NOT on enqueue_block_editor_assets (they would miss the iframed canvas)' );

echo "-- canvas styles: enqueue_block_assets --\n";
$canvas_hooked = false;
foreach ( ( $GLOBALS['__hooks']['enqueue_block_assets'] ?? array() ) as $cb ) {
	if ( is_array( $cb ) && 'enqueue_block_editor_styles' === ( $cb[1] ?? '' ) ) { $canvas_hooked = true; }
}
ok( $canvas_hooked, 'canvas styles hook into enqueue_block_assets (the iframe-reaching channel)' );

// No canvas context (front end / plain admin): nothing registered.
$obj->enqueue_block_editor_styles();
ok( ! isset( $GLOBALS['__styles']['v4v-musiclink-editor'] ), 'no canvas context -> no style' );

// Iframe-asset capture: core forces should_load_block_editor_scripts_and_styles
// to false while dispatching this hook inside the capture.
$GLOBALS['__has_filter']['should_load_block_editor_scripts_and_styles'] = true;
$obj->enqueue_block_editor_styles();
$sreg = $GLOBALS['__styles']['v4v-musiclink-editor'] ?? null;
ok( $sreg && false === $sreg['src'], 'capture: editor style registered src-false' );
ok( in_array( 'v4v-musiclink-editor', $GLOBALS['__styles_enqueued'], true ), 'capture: editor style enqueued' );
$css = $GLOBALS['__style_inline']['v4v-musiclink-editor'] ?? '';
ok( false !== strpos( $css, '.v4v-musiclink-title' ), 'editor gets the front-end CSS for the preview' );
ok( false !== strpos( $css, 'pointer-events: none' ), 'preview anchors are inert in the editor', 'pointer-events rule missing' );
ok( false !== strpos( $css, '.v4v-ml-preview .v4v-musiclink-art' ), 'preview art scaled for the edit page', 'art rule missing' );
ok( false !== strpos( $css, '.v4v-musiclink-footer' )
	&& false !== strpos( $css, '.v4v-musiclink-modal-overlay' ), 'preview omits the footer + modal that follow the buttons' );

// Non-admin inside the capture: still nothing.
$GLOBALS['__has_filter'] = array();
$GLOBALS['__styles']          = array();
$GLOBALS['__styles_enqueued'] = array();
$GLOBALS['__style_inline']    = array();
$GLOBALS['__can_manage'] = false;
$GLOBALS['__has_filter']['should_load_block_editor_scripts_and_styles'] = true;
$obj->enqueue_block_editor_styles();
ok( ! isset( $GLOBALS['__styles']['v4v-musiclink-editor'] ), 'non-admin: no canvas style even in the capture' );

// Non-iframed fallback: an actual block-editor screen.
$GLOBALS['__can_manage'] = true;
$GLOBALS['__has_filter'] = array();
$GLOBALS['__screen'] = new Fake_Screen();
$obj->enqueue_block_editor_styles();
ok( isset( $GLOBALS['__styles']['v4v-musiclink-editor'] ), 'non-iframed: block-editor screen also gets the style' );
$GLOBALS['__screen'] = null;

// ==========================================================================
echo "-- search: empty query --\n";
reset_state();
$res = $obj->rest_search( new Fake_Rest_Request( array( 'q' => '' ) ) );
ok( array() === $res['results'] && false === $res['has_more'], 'empty q -> empty payload, has_more false' );
ok( array() === $GLOBALS['http_calls'], 'empty q -> no upstream HTTP', json_encode( $GLOBALS['http_calls'] ) );

echo "-- search: resolves track + album hits --\n";
reset_state();
$GLOBALS['mi_search_response'] = wp_response( array(
	'data'       => array(
		array( 'entity_type' => 'track', 'entity_id' => 'TRK1', 'feed_guid' => 'ALB1' ),
		array( 'entity_type' => 'feed', 'entity_id' => 'ALB2' ),
	),
	'pagination' => array( 'cursor' => 'c1', 'has_more' => false ),
) );
$GLOBALS['mi_detail'] = array(
	'/v1/feeds/ALB1/tracks/TRK1' => wp_response( array( 'data' => array(
		'title' => 'See Through', 'feed_title' => 'The Singles', 'track_artist' => 'Fentlay', 'image_url' => 'http://x/a.jpg',
	) ) ),
	'/v1/feeds/ALB2' => wp_response( array( 'data' => array(
		'title' => 'Other Album', 'release_artist' => 'Other Artist', 'image_url' => 'http://x/b.jpg',
	) ) ),
);
$res = $obj->rest_search( new Fake_Rest_Request( array( 'q' => 'see' ) ) );
ok( 2 === count( $res['results'] ), 'two results resolved', count( $res['results'] ) . ' returned: ' . json_encode( $res['results'] ) );
$track_hit = $res['results'][0] ?? null;
$album_hit = $res['results'][1] ?? null;
ok( $track_hit && 'ALB1' === $track_hit['album_guid'] && 'TRK1' === $track_hit['track_guid'], 'track hit carries both guids', json_encode( $track_hit ) );
ok( $track_hit && 'See Through' === $track_hit['title'] && 'from The Singles · Fentlay' === $track_hit['subtitle'], 'track hit title/subtitle resolved', $track_hit ? $track_hit['subtitle'] : '' );
ok( $album_hit && 'ALB2' === $album_hit['album_guid'] && null === $album_hit['track_guid'], 'album hit has album guid, null track', json_encode( $album_hit ) );
ok( $album_hit && 'Other Album' === $album_hit['title'] && 'Album by Other Artist' === $album_hit['subtitle'], 'album hit subtitle', $album_hit ? $album_hit['subtitle'] : '' );
ok( false === $res['has_more'], 'has_more false when the cursor chain ends' );
ok( 2 === count( $GLOBALS['wpdb']->rows_in( $table ) ), 'resolution upserted a cache row per release pair', count( $GLOBALS['wpdb']->rows_in( $table ) ) . ' rows' );
// The track resolution stores ALB1|TRK1; the album resolution stores ALB2|''.
$hash_track = sha1( 'ALB1|TRK1' );
$hash_album = sha1( 'ALB2|' );
ok( null !== $GLOBALS['wpdb']->row_by_hash( $table, $hash_track ), 'track pair row exists after search' );
ok( null !== $GLOBALS['wpdb']->row_by_hash( $table, $hash_album ), 'album pair row exists after search' );

echo "-- search: dedupe + has_more + empty batch --\n";
reset_state();
$GLOBALS['mi_search_response'] = wp_response( array(
	'data'       => array(
		array( 'entity_type' => 'track', 'entity_id' => 'TRK1', 'feed_guid' => 'ALB1' ),
		array( 'entity_type' => 'track', 'entity_id' => 'TRK1', 'feed_guid' => 'ALB1' ),
	),
	'pagination' => array( 'cursor' => 'c2', 'has_more' => false ),
) );
$GLOBALS['mi_detail'] = array(
	'/v1/feeds/ALB1/tracks/TRK1' => wp_response( array( 'data' => array(
		'title' => 'See Through', 'feed_title' => 'The Singles', 'track_artist' => 'Fentlay',
	) ) ),
);
$res = $obj->rest_search( new Fake_Rest_Request( array( 'q' => 'dup' ) ) );
ok( 1 === count( $res['results'] ), 'duplicate hits collapse to one', json_encode( $res['results'] ) );

// has_more across batches: stage three responses with advancing cursors,
// so mi_search_hits() sees progress instead of "same cursor - stop".
reset_state();
$hit   = array( 'entity_type' => 'track', 'entity_id' => 'TRK1', 'feed_guid' => 'ALB1' );
$stage = function ( $cursor ) use ( $hit ) {
	return wp_response( array(
		'data'       => array( $hit ),
		'pagination' => array( 'cursor' => $cursor, 'has_more' => true ),
	) );
};
$GLOBALS['mi_search_queue'] = array( $stage( 'k1' ), $stage( 'k2' ), $stage( 'k3' ) );
$GLOBALS['mi_detail'] = array(
	'/v1/feeds/ALB1/tracks/TRK1' => wp_response( array( 'data' => array(
		'title' => 'See Through', 'feed_title' => 'The Singles', 'track_artist' => 'Fentlay',
	) ) ),
);
$res = $obj->rest_search( new Fake_Rest_Request( array( 'q' => 'more' ) ) );
ok( 1 === count( $res['results'] ), 'staged chain still resolves its hit' );
ok( true === $res['has_more'], 'has_more true while the cursor chain offers more', json_encode( $res ) );

reset_state();
$GLOBALS['mi_search_response'] = wp_response( array(
	'data'       => array(),
	'pagination' => array( 'cursor' => 'c3', 'has_more' => true ),
) );
$res = $obj->rest_search( new Fake_Rest_Request( array( 'q' => 'nothing' ) ) );
ok( array() === $res['results'] && false === $res['has_more'], 'empty batch ends the chain even when upstream claims has_more' );

echo "-- search: unresolvable hit is skipped --\n";
reset_state();
$GLOBALS['mi_search_response'] = wp_response( array(
	'data'       => array( array( 'entity_type' => 'track', 'entity_id' => 'TRK9', 'feed_guid' => 'ALB9' ) ),
	'pagination' => array( 'cursor' => 'c4', 'has_more' => false ),
) );
$GLOBALS['mi_detail'] = array(
	'/v1/feeds/ALB9/tracks/TRK9' => wp_response( array( 'data' => array( 'title' => '' ) ) ),
);
$res = $obj->rest_search( new Fake_Rest_Request( array( 'q' => 'ghost' ) ) );
ok( array() === $res['results'], 'hit whose detail lookup fails is dropped' );

// ==========================================================================
echo "-- row GET --\n";
reset_state();
$res = $obj->rest_get_row( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1' ) ) );
ok( false === $res['has_row'] && '' === $res['fountain_url'], 'missing row -> has_row false, empty link', json_encode( $res ) );

reset_state();
$GLOBALS['wpdb']->seed( $table, array(
	'lookup_hash' => sha1( 'ALB1|TRK1' ), 'album_guid' => 'ALB1', 'track_guid' => 'TRK1',
	'album_title' => 'The Singles', 'track_title' => 'See Through', 'artist' => 'Fentlay',
	'image_url' => 'http://x/a.jpg', 'fountain_url' => 'https://fountain.fm/episode/KEEP',
	'mi_updated_at' => '2026-01-01 00:00:00', 'fountain_updated_at' => '2026-01-01 00:00:00',
) );
$res = $obj->rest_get_row( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1' ) ) );
ok( true === $res['has_row'] && 'https://fountain.fm/episode/KEEP' === $res['fountain_url'], 'existing row returns its link', json_encode( $res ) );
ok( 'The Singles' === $res['album_title'] && 'Fentlay' === $res['artist'], 'existing row returns display text' );

$err = $obj->rest_get_row( new Fake_Rest_Request( array( 'track' => 'TRK1' ) ) );
ok( $err instanceof WP_Error && 400 === ( $err->get_error_data()['status'] ?? null ), 'album guid required (400)' );
$long = str_repeat( 'a', 257 );
$err  = $obj->rest_get_row( new Fake_Rest_Request( array( 'album' => $long ) ) );
ok( $err instanceof WP_Error && 400 === ( $err->get_error_data()['status'] ?? null ), 'album guid over 256 bytes rejected by guard_guid (400)' );

// ==========================================================================
echo "-- row PUT: validation --\n";
reset_state();
$before = count( $GLOBALS['wpdb']->rows_in( $table ) );
$err = $obj->rest_update_row( new Fake_Rest_Request( array(
	'album' => 'ALB1', 'track' => 'TRK1', 'fountain_url' => 'https://evil.example/x',
) ) );
ok( $err instanceof WP_Error && 400 === ( $err->get_error_data()['status'] ?? null ), 'non-fountain.fm host rejected (400)', $err instanceof WP_Error ? $err->get_error_code() : 'no error' );
ok( 0 === count( insert_queries() ), 'rejected URL writes nothing' );

$err = $obj->rest_update_row( new Fake_Rest_Request( array(
	'album' => 'ALB1', 'track' => 'TRK1', 'fountain_url' => 'http://fountain.fm/episode/X',
) ) );
ok( $err instanceof WP_Error, 'cleartext http rejected' );
ok( 0 === count( insert_queries() ), 'rejected scheme writes nothing' );

$err = $obj->rest_update_row( new Fake_Rest_Request( array( 'track' => 'TRK1', 'fountain_url' => '' ) ) );
ok( $err instanceof WP_Error, 'PUT without album guid rejected' );

echo "-- row PUT: create minimal row --\n";
reset_state();
$res = $obj->rest_update_row( new Fake_Rest_Request( array(
	'album' => 'ALB1', 'track' => 'TRK1', 'fountain_url' => 'https://fountain.fm/episode/NEW1',
) ) );
ok( true === $res['has_row'] && 'https://fountain.fm/episode/NEW1' === $res['fountain_url'], 'created row responds with the link', json_encode( $res ) );
$row = $GLOBALS['wpdb']->row_by_hash( $table, sha1( 'ALB1|TRK1' ) );
ok( null !== $row, 'row exists under the pair hash' );
ok( $row && 'ALB1' === $row['album_guid'] && 'TRK1' === $row['track_guid'], 'row carries the guids' );
ok( $row && 'https://fountain.fm/episode/NEW1' === $row['fountain_url'], 'row fountain_url set' );
ok( $row && '2026-09-26 12:00:00' === $row['fountain_updated_at'], 'fountain_updated_at stamped with current_time', $row ? var_export( $row['fountain_updated_at'], true ) : '' );
ok( $row && null === $row['mi_updated_at'], 'mi_updated_at stays NULL so the first render backfills MI data', $row ? var_export( $row['mi_updated_at'], true ) : '' );
ok( $row && '' === $row['album_title'] && '' === $row['track_title'] && '' === $row['artist'] && '' === $row['image_url'], 'MI columns start empty' );

reset_state();
$res = $obj->rest_update_row( new Fake_Rest_Request( array(
	'album' => 'ALB1', 'track' => '', 'fountain_url' => '',
) ) );
ok( false === $res['has_row'], 'no row + empty URL -> still no row' );
ok( 0 === count( insert_queries() ), 'no row + empty URL -> no INSERT' );

echo "-- row PUT: fountain half only --\n";
reset_state();
$GLOBALS['wpdb']->seed( $table, array(
	'lookup_hash' => sha1( 'ALB1|TRK1' ), 'album_guid' => 'ALB1', 'track_guid' => 'TRK1',
	'album_title' => 'The Singles', 'track_title' => 'See Through', 'artist' => 'Fentlay',
	'image_url' => 'http://x/a.jpg', 'fountain_url' => '',
	'mi_updated_at' => '2026-01-01 00:00:00', 'fountain_updated_at' => '2026-01-01 00:00:00',
) );
$res = $obj->rest_update_row( new Fake_Rest_Request( array(
	'album' => 'ALB1', 'track' => 'TRK1', 'fountain_url' => 'https://fountain.fm/episode/FILL1',
) ) );
$row = $GLOBALS['wpdb']->row_by_hash( $table, sha1( 'ALB1|TRK1' ) );
ok( $row && 'https://fountain.fm/episode/FILL1' === $row['fountain_url'], 'existing row: link written' );
ok( $row && 'The Singles' === $row['album_title'] && 'Fentlay' === $row['artist'] && 'http://x/a.jpg' === $row['image_url'], 'existing row: MI half untouched' );
ok( $row && '2026-01-01 00:00:00' === $row['mi_updated_at'], 'existing row: mi_updated_at untouched' );
ok( $row && '2026-09-26 12:00:00' === $row['fountain_updated_at'], 'existing row: fountain_updated_at stamped' );
$inserts = insert_queries();
ok( 1 === count( $inserts ) && false !== stripos( $inserts[0], 'ON DUPLICATE KEY UPDATE' ) && false === stripos( $inserts[0], 'album_title = VALUES' ) && false === stripos( $inserts[0], 'mi_updated_at = VALUES' ), 'upsert assigns ONLY the fountain columns', implode( ' | ', $inserts ) );

echo "-- row PUT: clear + memo invalidation --\n";
$got = $obj->rest_get_row( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1' ) ) );
ok( 'https://fountain.fm/episode/FILL1' === $got['fountain_url'], 'GET before PUT memoizes the old value' );
$res = $obj->rest_update_row( new Fake_Rest_Request( array(
	'album' => 'ALB1', 'track' => 'TRK1', 'fountain_url' => '',
) ) );
ok( '' === $res['fountain_url'] && true === $res['has_row'], 'clear responds with empty link, row kept', json_encode( $res ) );
$row = $GLOBALS['wpdb']->row_by_hash( $table, sha1( 'ALB1|TRK1' ) );
ok( $row && '' === $row['fountain_url'], 'clear blanks the cell' );
ok( $row && 'The Singles' === $row['album_title'], 'clear leaves the MI half alone' );
$again = $obj->rest_get_row( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1' ) ) );
ok( '' === $again['fountain_url'], 'memo was invalidated - a repeat GET sees the cleared value' );

// ==========================================================================
echo "-- render: validation --\n";
reset_state();
$err = $obj->rest_render( new Fake_Rest_Request( array( 'track' => 'TRK1' ) ) );
ok( $err instanceof WP_Error && 400 === ( $err->get_error_data()['status'] ?? null ), 'missing album -> 400' );
$err = $obj->rest_render( new Fake_Rest_Request( array( 'album' => str_repeat( 'a', 257 ) ) ) );
ok( $err instanceof WP_Error && 400 === ( $err->get_error_data()['status'] ?? null ), 'over-long album -> 400' );

echo "-- render: cached row preview --\n";
$seed_track_row = function ( $album = 'ALB1', $track = 'TRK1' ) use ( $table ) {
	$GLOBALS['wpdb']->seed( $table, array(
		'lookup_hash' => sha1( $album . '|' . $track ), 'album_guid' => $album, 'track_guid' => $track,
		'album_title' => 'The Singles', 'track_title' => 'See Through', 'artist' => 'Fentlay',
		'image_url' => 'http://x/a.jpg', 'fountain_url' => 'https://fountain.fm/episode/KEEP',
		// Fresh: so mi_get_track() serves from cache with no MI HTTP and
		// no queued background refresh (gmdate vs the identity cutoff).
		'mi_updated_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		'fountain_updated_at' => '2026-01-01 00:00:00',
	) );
};

reset_state();
$seed_track_row();
$res = $obj->rest_render( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1' ) ) );
ok( is_array( $res ) && isset( $res['html'] ), 'render responds with an html payload', is_array( $res ) ? 'ok' : gettype( $res ) );
$html = isset( $res['html'] ) ? $res['html'] : '';
ok( false !== strpos( $html, 'v4v-musiclink-header' ), 'site setting shows the release header' );
ok( false !== strpos( $html, 'See Through' ) && false !== strpos( $html, 'The Singles' ), 'preview carries track/album titles from the cache row' );
ok( false !== strpos( $html, 'Fentlay' ), 'preview carries the artist' );
ok( false !== strpos( $html, 'https://fountain.fm/episode/KEEP' ), 'preview uses the cached Fountain link' );
ok( false !== strpos( $html, 'StableKraft.app' ), 'buttons rendered for the enabled apps' );
ok( array() === $GLOBALS['http_calls'], 'fresh row -> preview does no MI HTTP', json_encode( $GLOBALS['http_calls'] ) );

$res = $obj->rest_render( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1', 'header' => 'false' ) ) );
ok( false === strpos( $res['html'], 'v4v-musiclink-header' ), 'header=false hides the release header' );
$res = $obj->rest_render( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1', 'header' => 'true' ) ) );
ok( false !== strpos( $res['html'], 'v4v-musiclink-header' ), 'header=true shows the release header' );
$res = $obj->rest_render( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1', 'header' => 'maybe' ) ) );
ok( false !== strpos( $res['html'], 'v4v-musiclink-header' ), 'unrecognized header falls back to the site setting' );

echo "-- render: site setting + album-only + unknown guid --\n";
$GLOBALS['__options']['v4v_musiclink_show_header'] = false;
$res = $obj->rest_render( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1' ) ) );
ok( false === strpos( $res['html'], 'v4v-musiclink-header' ), 'site setting off -> header hidden' );
$res = $obj->rest_render( new Fake_Rest_Request( array( 'album' => 'ALB1', 'track' => 'TRK1', 'header' => 'true' ) ) );
ok( false !== strpos( $res['html'], 'v4v-musiclink-header' ), 'header=true overrides the site setting off' );
unset( $GLOBALS['__options']['v4v_musiclink_show_header'] );

reset_state();
$GLOBALS['wpdb']->seed( $table, array(
	'lookup_hash' => sha1( 'ALB7|' ), 'album_guid' => 'ALB7', 'track_guid' => '',
	'album_title' => 'Feed Record', 'track_title' => '', 'artist' => 'Feed Artist',
	'image_url' => '', 'fountain_url' => '',
	'mi_updated_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'fountain_updated_at' => '',
) );
$res = $obj->rest_render( new Fake_Rest_Request( array( 'album' => 'ALB7' ) ) );
ok( false !== strpos( $res['html'], 'Feed Record' ) && false !== strpos( $res['html'], 'Feed Artist' ), 'album-only preview renders the feed row', '' );
ok( false === strpos( $res['html'], 'See Through' ), 'album-only preview carries no track title' );

reset_state();
$res = $obj->rest_render( new Fake_Rest_Request( array( 'album' => 'NOBODY' ) ) );
ok( is_array( $res ) && false !== strpos( $res['html'], 'Album/Song not found' ), 'unknown guid previews the not-found message (no fatal)', is_array( $res ) ? 'ok' : 'WP_Error' );

// ==========================================================================
echo "\n" . str_repeat( '=', 70 ) . "\n";
printf( "RESULT: %d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
