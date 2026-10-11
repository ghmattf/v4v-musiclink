<?php
/**
 * Cache rows: the Fountain button uses a cached link when one exists,
 * stores a freshly resolved one when it doesn't, never stores the
 * failure fallback (a Fountain search for the release), and never reverts
 * a link an admin edited
 * by hand - plus the O12 cache contract: Music Index metadata and the
 * Fountain link live on one row, stale metadata serves while a background
 * pull is queued (render-gated), and nothing ever evicts a row.
 * Also covers the admin-post handlers (scoped clear / per-row update /
 * delete, nonce + capability gates), the Cache tab's filter SQL and field
 * order, the fresh-install path of maybe_create_cache_table(), and the
 * sanitize_mi_refresh_hours matrix.
 *
 * Loads both real plugin files with WordPress stubbed out, including a
 * small in-memory $wpdb that speaks the query shapes the feature issues
 * (SELECT by hash, INSERT ... ON DUPLICATE KEY UPDATE, UPDATE, DELETE,
 * plus wpdb->update()/delete() for the row handlers). dbDelta() comes
 * from tmp/wpstub/, which is why ABSPATH points there.
 */

define( 'ABSPATH', __DIR__ . '/wpstub/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

class Fake_WP_Error { public $msg = 'stubbed transport failure'; }

$GLOBALS['__options'] = array(
	// Both cache schemas "already installed": the constructors then never
	// reach dbDelta()/upgrade.php. Reset to '1' before every test, so the
	// M0 fresh-install test can't leak into the next one.
	'v4v_musiclink_cache_table_version'     => '1',
	'v4v_musiclink_pro_cache_table_version' => '1',
	'v4v_musiclink_pro_log_table_version'   => '2',
);
$GLOBALS['__deleted_options'] = array();
$GLOBALS['transients']        = array();
$GLOBALS['transients_ttl']    = array();
$GLOBALS['http_calls']        = array();
$GLOBALS['fake_response']     = new Fake_WP_Error(); // Fountain transport
$GLOBALS['mi_response']       = new Fake_WP_Error(); // Music Index transport
$GLOBALS['redirected_to']     = null;
$GLOBALS['nonces_checked']    = array();
$GLOBALS['__can_manage']      = true;
$GLOBALS['__bad_nonce']       = false;
$GLOBALS['__dbdelta']         = array();
$GLOBALS['__hooks']           = array();

// --- transport -----------------------------------------------------------
function is_wp_error( $x )                          { return $x instanceof Fake_WP_Error; }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['http_calls'][] = 'GET ' . $url;
	$parts = parse_url( $url );
	if ( isset( $parts['host'] ) && 'api.musicindex.org' === $parts['host'] ) {
		return $GLOBALS['mi_response'];
	}
	return $GLOBALS['fake_response'];
}
function wp_remote_post( $url, $args = array() )    { $GLOBALS['http_calls'][] = 'POST ' . $url; return $GLOBALS['fake_response']; }
function wp_remote_retrieve_response_code( $r )     { return is_array($r) && isset($r['response']['code']) ? (int)$r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )              { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }

// --- options / transients ------------------------------------------------
function get_transient( $k )                        { return array_key_exists($k, $GLOBALS['transients']) ? $GLOBALS['transients'][$k] : false; }
function set_transient( $k, $v, $ttl )              { $GLOBALS['transients'][$k] = $v; $GLOBALS['transients_ttl'][$k] = $ttl; return true; }
function delete_transient( $k )                     { unset($GLOBALS['transients'][$k]); unset($GLOBALS['transients_ttl'][$k]); return true; }
function get_option( $k, $d = false )               { return array_key_exists($k, $GLOBALS['__options']) ? $GLOBALS['__options'][$k] : $d; }
function update_option( $k, $v, $a = false )        { $GLOBALS['__options'][$k] = $v; return true; }
function delete_option( $k )                        { $GLOBALS['__deleted_options'][] = $k; unset( $GLOBALS['__options'][$k] ); return true; }

// --- WP plumbing ---------------------------------------------------------
function add_shortcode() {}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['__hooks'][ $tag ][] = $cb; }
function add_filter() {}
function register_activation_hook() {}
function home_url( $p = '' )                        { return 'http://example.test' . $p; }
function admin_url( $p = '' )                       { return 'http://example.test/wp-admin/' . $p; }
function plugin_dir_url( $f )                       { return 'http://example.test/plugin/'; }
function plugins_url( $p = '', $f = '' )            { return 'http://example.test/plugin/' . $p; }
function did_action( $h )                           { return 0; }
function is_admin()                                 { return true; }
function wp_doing_ajax()                            { return false; }
function shortcode_atts( $pairs, $atts, $sc = '' )  { return array_merge( $pairs, (array) $atts ); }
function absint( $n )                               { return abs( (int) $n ); }
function wp_json_encode( $d )                       { return json_encode( $d ); }
function wp_parse_url( $u, $c = -1 )                { return parse_url( $u, $c ); }
function wp_parse_args( $a, $d = null )             { return array_merge( (array) $d, (array) $a ); }
function wp_unslash( $v )                           { return $v; }
function wp_kses( $s, $a = '' )                     { return $s; }
function wp_kses_post( $s )                         { return $s; }
function get_file_data( $f, $h, $c = '' )           { $out = array(); foreach ( $h as $k => $v ) { $out[$k] = '0.0.0'; } return $out; }
// Harness clock: the site runs in GMT, so the freshness cutoff and the
// current_time() stamp below are directly comparable.
function get_date_from_gmt( $string, $format = 'Y-m-d H:i:s' ) { return $string; }
function add_query_arg( ...$args ) {
	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
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
		if ( '' === (string) $k && null === $v ) { continue; }
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

// --- escaping / i18n -----------------------------------------------------
function __( $s, $d = null )                        { return $s; }
function _e( $s, $d = null )                        { echo $s; }
function esc_html( $s )                             { return $s; }
function esc_attr( $s )                             { return $s; }
function esc_url( $s )                              { return $s; }
function esc_js( $s )                               { return $s; }
function esc_textarea( $s )                         { return $s; }
function esc_url_raw( $s )                          { return $s; }
function esc_html_e( $s, $d = null )                { echo $s; }
function esc_attr_e( $s, $d = null )                { echo $s; }
function esc_html__( $s, $d = null )                { return $s; }
function number_format_i18n( $n )                   { return (string) $n; }
function sanitize_text_field( $s )                  { return is_string($s) ? trim( strip_tags($s) ) : $s; }
function sanitize_key( $s )                         { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); }
function sanitize_hex_color( $c )                   { $c = (string) $c; return ( '' === $c || preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $c ) ) ? $c : ''; }
function checked( $a, $b = true, $e = true )        { $s = ( (string) $a === (string) $b ) ? " checked='checked'" : ''; if ($e) echo $s; return $s; }
function selected( $a, $b, $e = true )              { $s = ( (string) $a === (string) $b ) ? " selected='selected'" : ''; if ($e) echo $s; return $s; }
function disabled( $a, $b = true, $e = true )       { $s = ( (string) $a === (string) $b ) ? " disabled='disabled'" : ''; if ($e) echo $s; return $s; }

// --- admin plumbing ------------------------------------------------------
function current_time( $type )                      { return 'mysql' === $type ? '2026-09-26 12:00:00' : time(); }
function current_user_can( $c )                     { return (bool) $GLOBALS['__can_manage']; }
function wp_create_nonce( $a )                      { return 'nonce-' . $a; }
function wp_verify_nonce( $n, $a )                  { return 1; }
function check_ajax_referer( $a, $q = true )        { return true; }
function check_admin_referer( $a ) {
	$GLOBALS['nonces_checked'][] = $a;
	if ( $GLOBALS['__bad_nonce'] ) { throw new RuntimeException( 'nonce-fail' ); }
	return 1;
}
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $n ) . '" value="nonce" />';
	if ( $e ) { echo $field; }
	return $field;
}
// Thrown, not returned: the handlers call exit right after redirecting, and
// an exception stops them one statement early so the test keeps running.
function wp_safe_redirect( $u, $s = 302 )           { $GLOBALS['redirected_to'] = $u; throw new RuntimeException( 'redirect' ); }
function wp_get_referer()                           { return false; }
function settings_fields( $g = '' )                 { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '" />'; }
function submit_button( $t = null )                 { echo '<input type="submit" class="button button-primary" value="' . esc_attr( $t ? $t : 'Save Changes' ) . '" />'; }
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_register_style() {}
function wp_localize_script() {}

function wp_die( $m = '' )                          { throw new RuntimeException( 'wp_die: ' . $m ); }
function wp_send_json( $d )                         { throw new RuntimeException( 'wp_send_json' ); }
function wp_send_json_error( $d = null, $c = null ) { throw new RuntimeException( 'wp_send_json_error' ); }
function wp_send_json_success( $d = null )          { throw new RuntimeException( 'wp_send_json_success' ); }

/**
 * In-memory $wpdb: records every SQL string for assertions and maintains
 * per-table rows + column lists for the shapes the feature issues,
 * including the two ON DUPLICATE KEY UPDATE upserts (link half /
 * Music Index half).
 */
class Fake_Wpdb {
	public $prefix   = 'wp_';
	public $data     = array(); // table => list of assoc rows (each with 'id')
	public $columns  = array(); // table => list of column names
	public $queries  = array(); // every SQL string seen
	private $next_id = 1;

	public function get_charset_collate() { return ''; }
	public function esc_like( $t )        { return addcslashes( $t, '_%\\' ); }

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
	public function cols_in( $table ) {
		return isset( $this->columns[ $table ] ) ? $this->columns[ $table ] : array();
	}
	public function seed( $table, $row ) {
		$rows            = $this->rows_in( $table );
		$row['id']       = $this->next_id++;
		$rows[]          = $row;
		$this->data[ $table ] = $rows;
		return $row['id'];
	}
	public function row_by_hash( $table, $hash ) {
		foreach ( $this->rows_in( $table ) as $r ) {
			if ( isset( $r['lookup_hash'] ) && $r['lookup_hash'] === $hash ) { return $r; }
		}
		return null;
	}
	public function row_by_id( $table, $id ) {
		foreach ( $this->rows_in( $table ) as $r ) {
			if ( (int) $r['id'] === (int) $id ) { return $r; }
		}
		return null;
	}
	private function table_of( $sql ) {
		return preg_match( '/(?:FROM|INTO|UPDATE)\s+(\S+)/', $sql, $m ) ? $m[1] : '';
	}

	public function query( $sql ) {
		$this->queries[] = $sql;

		// --- per-app clear: blank one link column, stamp fountain_updated_at
		if ( preg_match( "/^UPDATE\s+(\S+)\s+SET (\S+) = '', fountain_updated_at = '([^']*)' WHERE \\2 <> ''$/", $sql, $m ) ) {
			$rows = $this->rows_in( $m[1] );
			$n    = 0;
			foreach ( $rows as $i => $r ) {
				if ( isset( $r[ $m[2] ] ) && '' !== $r[ $m[2] ] ) {
					$rows[ $i ][ $m[2] ]              = '';
					$rows[ $i ]['fountain_updated_at'] = $m[3];
					$n++;
				}
			}
			$this->data[ $m[1] ] = $rows;
			return $n;
		}

		// --- the two upserts: parse the column list, apply only the
		//     assignments the statement names on conflict.
		if ( preg_match( "/^INSERT INTO\s+(\S+)\s*\((.*?)\)\s*VALUES\s*\((.*?)\)\s*ON DUPLICATE KEY UPDATE\s*(.*)$/is", $sql, $m ) ) {
			$table = $m[1];
			$cols  = array_map( 'trim', explode( ',', $m[2] ) );
			$vals  = str_getcsv( $m[3], ',', "'", '\\' );
			if ( count( $cols ) !== count( $vals ) ) { return 0; }
			$row = array_combine( $cols, $vals );

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
			foreach ( $this->cols_in( $table ) as $c ) {
				if ( ! array_key_exists( $c, $row ) ) {
					$row[ $c ] = ( 'mi_updated_at' === $c ) ? null : '';
				}
			}
			$this->data[ $table ][] = $row;
			return 1;
		}

		if ( 0 === strpos( $sql, 'DELETE FROM' ) ) {
			$table = $this->table_of( $sql );
			$gone  = count( $this->rows_in( $table ) );
			$this->data[ $table ] = array();
			return $gone;
		}

		if ( preg_match( '/^DROP TABLE IF EXISTS\s+(\S+)/', $sql, $m ) ) {
			unset( $this->data[ $m[1] ] );
			unset( $this->columns[ $m[1] ] );
			return 1;
		}

		return 1;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;

		if ( preg_match( '/SELECT COUNT\(\*\).*?\sFROM\s+(\S+)/s', $sql, $m ) ) {
			return count( $this->rows_in( $m[1] ) );
		}
		if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
			$row = $this->row_by_id( $this->table_of( $sql ), (int) $m[1] );
			return $row ? (int) $row['id'] : null;
		}
		return null;
	}

	public function get_row( $sql ) {
		$this->queries[] = $sql;

		if ( preg_match( "/SELECT \* FROM\s+(\S+)\s+WHERE lookup_hash = '([^']*)'/", $sql, $m ) ) {
			$row = $this->row_by_hash( $m[1], $m[2] );
			return $row ? (object) $row : null;
		}
		return null;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;

		if ( preg_match( '/^SHOW TABLES/', $sql ) ) {
			return array_values( array_unique( array_merge(
				array_keys( $this->data ),
				array_keys( $this->columns )
			) ) );
		}
		return array();
	}

	public function get_results( $sql ) {
		$this->queries[] = $sql;
		return array_map( function ( $r ) { return (object) $r; }, $this->rows_in( $this->table_of( $sql ) ) );
	}

	public function update( $table, $data, $where, $fmt = null, $wfmt = null ) {
		$rows = $this->rows_in( $table );
		$id   = isset( $where['id'] ) ? (int) $where['id'] : 0;
		$n    = 0;
		foreach ( $rows as $i => $r ) {
			if ( (int) $r['id'] === $id ) { $rows[ $i ] = array_merge( $r, $data ); $n++; }
		}
		$this->data[ $table ] = $rows;
		return $n;
	}

	public function delete( $table, $where, $fmt = null ) {
		$id   = isset( $where['id'] ) ? (int) $where['id'] : 0;
		$rows = $this->rows_in( $table );
		$keep = array();
		$n    = 0;
		foreach ( $rows as $r ) {
			if ( (int) $r['id'] === $id ) { $n++; } else { $keep[] = $r; }
		}
		$this->data[ $table ] = $keep;
		return $n;
	}
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

require __DIR__ . '/../v4v-musiclink/v4v-musiclink.php';
require __DIR__ . '/../v4v-musiclink-pro/v4v-musiclink-pro.php';

// --- helpers -------------------------------------------------------------
define( 'CACHE_COLS', array(
	'id', 'lookup_hash', 'album_guid', 'track_guid',
	'album_title', 'track_title', 'artist', 'image_url',
	'fountain_url', 'mi_updated_at', 'fountain_updated_at',
) );

function reset_state() {
	global $wpdb;
	$wpdb->data    = array();
	$wpdb->queries = array();
	$wpdb->columns = array(
		'wp_v4v_musiclink_cache'       => CACHE_COLS,
		'wp_v4v_musiclink_pro_cache'   => CACHE_COLS,
	);
	$GLOBALS['transients']        = array();
	$GLOBALS['transients_ttl']    = array();
	$GLOBALS['http_calls']        = array();
	$GLOBALS['fake_response']     = new Fake_WP_Error();
	$GLOBALS['mi_response']       = new Fake_WP_Error();
	$GLOBALS['redirected_to']     = null;
	$GLOBALS['nonces_checked']    = array();
	$GLOBALS['__can_manage']      = true;
	$GLOBALS['__bad_nonce']       = false;
	$GLOBALS['__dbdelta']         = array();
	$GLOBALS['__hooks']           = array();
	$GLOBALS['__deleted_options'] = array();
	$GLOBALS['__options']['v4v_musiclink_cache_table_version']     = '1';
	$GLOBALS['__options']['v4v_musiclink_pro_cache_table_version'] = '1';
	$GLOBALS['__options']['v4v_musiclink_pro_log_table_version']   = '2';
	unset( $GLOBALS['__options']['v4v_musiclink_fountain_next_action'] );
	unset( $GLOBALS['__options']['v4v_musiclink_pro_fountain_next_action'] );
	unset( $GLOBALS['__options']['v4v_musiclink_mi_refresh_hours'] );
	unset( $GLOBALS['__options']['v4v_musiclink_pro_mi_refresh_hours'] );
	unset( $GLOBALS['__options']['v4v_musiclink_random_app_order'] );
	unset( $GLOBALS['__options']['v4v_musiclink_pro_random_app_order'] );
	$_GET  = array();
	$_POST = array();
}

/** A brand-new plugin instance: memo and queue always start empty. */
function fresh_obj( $class ) {
	$rc = new ReflectionClass( $class );
	return $rc->newInstanceWithoutConstructor();
}
function get_private( $obj, $prop ) {
	$p = new ReflectionProperty( get_class( $obj ), $prop );
	return $p->getValue( $obj );
}
function call_private( $obj, $name, array $args = array() ) {
	// No setAccessible(): private methods are invokable as-is since 8.1.
	$m = new ReflectionMethod( $obj, $name );
	return $m->invokeArgs( $obj, $args );
}
function try_call( $cb ) {
	try { $cb(); return 'ok'; }
	catch ( RuntimeException $e ) { return $e->getMessage(); }
}
/** Every recorded query containing $needle. */
function sqls( $needle ) {
	global $wpdb;
	return array_values( array_filter( $wpdb->queries, function ( $q ) use ( $needle ) {
		return false !== stripos( $q, $needle );
	} ) );
}
function has_sql( $needle ) { return (bool) sqls( $needle ); }
function fountain_calls() {
	return array_values( array_filter( $GLOBALS['http_calls'], function ( $u ) {
		return false !== strpos( $u, 'fountain.fm' );
	} ) );
}
function mi_calls() {
	return array_values( array_filter( $GLOBALS['http_calls'], function ( $u ) {
		return false !== strpos( $u, 'api.musicindex.org' );
	} ) );
}
function mi_transient_keys() {
	return array_values( array_filter( array_keys( $GLOBALS['transients'] ), function ( $k ) {
		return 0 === strpos( $k, 'mi_feed_' ) || 0 === strpos( $k, 'mi_track_' );
	} ) );
}
/** Whether every needle appears, in order, each after the previous one. */
function in_order( $haystack, array $needles ) {
	$pos = -1;
	foreach ( $needles as $n ) {
		$i = strpos( $haystack, $n );
		if ( false === $i || $i < $pos ) { return false; }
		$pos = $i;
	}
	return true;
}
/** The part of the rendered tab from a marker onward (or false). */
function after( $haystack, $needle ) {
	$i = strpos( $haystack, $needle );
	return false === $i ? false : substr( $haystack, $i );
}
function render_tab( $obj ) {
	ob_start();
	try { call_private( $obj, 'render_cache_tab_content' ); } catch ( Throwable $e ) { ob_end_clean(); throw $e; }
	return ob_get_clean();
}
function render_settings( $obj ) {
	ob_start();
	$obj->render_settings_page();
	return ob_get_clean();
}

$pass = 0; $fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS  $label\n"; }
	else       { $fail++; echo "FAIL  $label $detail\n"; }
}

// --- per-plugin configuration -------------------------------------------
$classes = array(
	'V4V_Music_Link_Resolver'     => array(
		'table'       => 'wp_v4v_musiclink_cache',
		'version_opt' => 'v4v_musiclink_cache_table_version',
		'random_opt'  => 'v4v_musiclink_random_app_order',
		'apps_opt'    => 'v4v_musiclink_apps',
		'track_key'   => 'mi_track_v4_',
		'feed_key'    => 'mi_feed_v4_',
		'hit_key'     => 'fountain_id_v5_',
		'next_opt'    => 'v4v_musiclink_fountain_next_action',
		'mi_opt'      => 'v4v_musiclink_mi_refresh_hours',
		'attempt'     => 'v4v_musiclink_mi_attempt_',
		'action'     => 'v4v_musiclink',
		'nonces'      => array(
			'clear'  => 'v4v_musiclink_clear_cache',
			'update' => 'v4v_musiclink_update_cache_row',
			'delete' => 'v4v_musiclink_delete_cache_row',
		),
	),
	'V4V_Music_Link_Resolver_Pro' => array(
		'table'       => 'wp_v4v_musiclink_pro_cache',
		'version_opt' => 'v4v_musiclink_pro_cache_table_version',
		'random_opt'  => 'v4v_musiclink_pro_random_app_order',
		'apps_opt'    => 'v4v_musiclink_pro_apps',
		'track_key'   => 'mi_track_pro_v4_',
		'feed_key'    => 'mi_feed_pro_v4_',
		'hit_key'     => 'fountain_id_pro_v5_',
		'next_opt'    => 'v4v_musiclink_pro_fountain_next_action',
		'mi_opt'      => 'v4v_musiclink_pro_mi_refresh_hours',
		'attempt'     => 'v4v_musiclink_pro_mi_attempt_',
		'action'     => 'v4v_musiclink_pro',
		'nonces'      => array(
			'clear'  => 'v4v_musiclink_pro_clear_cache',
			'update' => 'v4v_musiclink_pro_update_cache_row',
			'delete' => 'v4v_musiclink_pro_delete_cache_row',
		),
	),
);

$mi_track_ok = json_encode( array( 'data' => array(
	'title' => 'See Through', 'feed_title' => 'The Singles',
	'track_artist' => 'Fentlay', 'image_url' => 'http://x/y.jpg',
) ) );
$mi_feed_ok  = json_encode( array( 'data' => array(
	'title' => 'The Singles', 'release_artist' => 'Fentlay',
	'image_url' => 'http://x/y.jpg',
) ) );

foreach ( $classes as $class => $cfg ) {
	$table    = $cfg['table'];
	$album    = 'd5c805fb-6016-4038-9cda-2e68d2b8dcc4';
	$track    = 'cbdaeaa0-3c58-41ce-92d1-c718710ade54';
	$hash     = sha1( $album . '|' . $track );
	$album2   = 'aaaaaaaa-1111-2222-3333-444444444444';
	$track2   = 'bbbbbbbb-5555-6666-7777-888888888888';
	$hash2    = sha1( $album2 . '|' . $track2 );
	$deep     = 'https://fountain.fm/episode/SEETHROUGH123';
	$hit      = array( 'id' => 'SEETHROUGH123', 'type' => 'EPISODE' );
	$kept     = 'https://fountain.fm/episode/KEEP';
	$FUTURE   = '2099-01-01 00:00:00'; // inside any refresh window
	$PAST     = '2020-01-01 00:00:00'; // outside every refresh window
	// O21: only Pro still resolves Fountain links; standard renders the
	// O15 search fallback and never writes the link half.
	$can_resolve = 'V4V_Music_Link_Resolver_Pro' === $class;
	$search_fb   = 'https://fountain.fm/search?q=' . urlencode( '"See Through"' );

	/** A cache row with Music Index data (fresh by default) and an optional link. */
	$seed = function ( $h, $a, $t, $extra = array() ) use ( $table, $FUTURE ) {
		global $wpdb;
		$row = array_merge( array(
			'lookup_hash' => $h, 'album_guid' => $a, 'track_guid' => $t,
			'album_title' => 'The Singles', 'track_title' => 'See Through',
			'artist' => 'Fentlay', 'image_url' => 'http://x/y.jpg',
			'fountain_url' => '', 'mi_updated_at' => $FUTURE,
			'fountain_updated_at' => '2026-01-01 00:00:00',
		), $extra );
		return $wpdb->seed( $table, $row );
	};

	// A successful Fountain resolution already cached for the configured
	// next-action value - so the search path can run with zero HTTP.
	$warm_resolve = function () use ( $cfg, $track, $hit ) {
		$GLOBALS['transients'][ $cfg['hit_key'] . md5( $track . '|TESTHASH123' ) ] = $hit;
		$GLOBALS['__options'][ $cfg['next_opt'] ] = 'TESTHASH123';
	};

	// === Link half =====================================================

	// --- S1: a cached link is used verbatim, with no search and no write
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => 'https://fountain.fm/episode/STOREDSEED1' ) );
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] S1 cached link is what the button points at", false !== strpos( $html, 'https://fountain.fm/episode/STOREDSEED1' ) );
	check( "[$class] S1 cached link skips the Fountain search (no fountain HTTP)", array() === fountain_calls(), json_encode( fountain_calls() ) );
	check( "[$class] S1 fresh row triggers no Music Index HTTP", array() === mi_calls(), json_encode( mi_calls() ) );
	check( "[$class] S1 fresh row queues no background pull", array() === get_private( $obj, 'mi_refresh_pending' ), json_encode( get_private( $obj, 'mi_refresh_pending' ) ) );
	check( "[$class] S1 no attempt marker written", ! isset( $GLOBALS['transients'][ $cfg['attempt'] . md5( $hash ) ] ) );
	check( "[$class] S1 writes nothing", ! has_sql( 'INSERT INTO' ), 'unexpected INSERT' );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] S1 row untouched", $row && 'https://fountain.fm/episode/STOREDSEED1' === $row['fountain_url'] && '2026-01-01 00:00:00' === $row['fountain_updated_at'] );

	// --- S2: blank link. Pro re-searches and upserts the LINK half only;
	//     standard (O21) never resolves - it renders the O15 search
	//     fallback and writes nothing.
	reset_state();
	$seed( $hash, $album, $track );
	if ( $can_resolve ) { $warm_resolve(); }
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] S2 no Fountain HTTP needed", array() === fountain_calls(), json_encode( fountain_calls() ) );
	check( "[$class] S2 no Music Index HTTP needed (row was fresh)", array() === mi_calls(), json_encode( mi_calls() ) );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] S2 still exactly one row", 1 === count( $wpdb->rows_in( $table ) ), count( $wpdb->rows_in( $table ) ) . ' rows' );
	if ( $can_resolve ) {
		check( "[$class] S2 resolved deep link is rendered", false !== strpos( $html, $deep ), $deep );
		check( "[$class] S2 upsert (ON DUPLICATE KEY UPDATE) recorded", has_sql( 'ON DUPLICATE KEY UPDATE' ) );
	} else {
		check( "[$class] S2 search fallback rendered", false !== strpos( $html, 'href="' . $search_fb . '"' ), 'expected ' . $search_fb );
		check( "[$class] S2 no deep link rendered", false === strpos( $html, $deep ) );
		check( "[$class] S2 no upsert recorded", ! has_sql( 'ON DUPLICATE KEY UPDATE' ), 'unexpected link upsert' );
	}
	if ( is_array( $row ) ) {
		check( "[$class] S2 row link", $can_resolve ? ( $deep === $row['fountain_url'] ) : ( '' === $row['fountain_url'] ), var_export( $row['fountain_url'], true ) );
		check( "[$class] S2 fountain_updated_at", $can_resolve ? ( '2026-09-26 12:00:00' === $row['fountain_updated_at'] ) : ( '2026-01-01 00:00:00' === $row['fountain_updated_at'] ), $row['fountain_updated_at'] );
		check( "[$class] S2 Music Index half untouched", 'http://x/y.jpg' === $row['image_url'] && 'See Through' === $row['track_title'] && $FUTURE === $row['mi_updated_at'], json_encode( $row ) );
		check( "[$class] S2 row guids", $album === $row['album_guid'] && $track === $row['track_guid'] );
	}
	$link_upserts = array_values( array_filter( sqls( 'ON DUPLICATE KEY UPDATE' ), function ( $q ) {
		return false !== stripos( $q, 'fountain_url = VALUES(fountain_url)' );
	} ) );
	check( "[$class] S2 link upserts", $can_resolve ? ( 1 === count( $link_upserts ) ) : ( 0 === count( $link_upserts ) ), count( $link_upserts ) . ' found' );
	if ( $can_resolve ) {
		check( "[$class] S2 link upsert touches only the link half", 1 === count( $link_upserts )
			&& false === stripos( $link_upserts[0], 'mi_updated_at' )
			&& false !== stripos( $link_upserts[0], 'fountain_updated_at' ), implode( ' | ', $link_upserts ) );
		check( "[$class] S2 lookup now returns the cached link", $deep === call_private( $obj, 'get_cached_fountain_url', array( $album, $track ) ) );
	} else {
		check( "[$class] S2 lookup still returns nothing", '' === call_private( $obj, 'get_cached_fountain_url', array( $album, $track ) ) );
	}

	// --- S3: on Fountain failure the button lands on a Fountain search
	//     for the release (never the generic discover page) - and the
	//     fallback is still never persisted (it would pin a generic link
	//     to this release forever); the confirm-modal keeps matching it
	reset_state();
	$seed( $hash, $album, $track ); // MI data present, no Fountain hit cached
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] S3 search fallback rendered", false !== strpos( $html, 'href="' . $search_fb . '"' ), 'expected ' . $search_fb );
	check( "[$class] S3 search fallback replaces discover", false === strpos( $html, 'href="https://fountain.fm/discover"' ) );
	check( "[$class] S3 search fallback is never stored", ! has_sql( 'INSERT INTO' ) && '' === $wpdb->row_by_hash( $table, $hash )['fountain_url'], 'fallback row written' );
	// The confirm-modal's matcher for this fallback lives in inline_js(),
	// which is attached via wp_add_inline_script and so is not part of
	// render()'s return - tmp/e2e/gen_page.php gates that instead.

	// --- S4: Pro re-runs the search for a blanked link and fills it in
	//     (no duplicate row, MI half untouched); standard renders the
	//     fallback and never writes (O21)
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => '' ) );
	if ( $can_resolve ) { $warm_resolve(); }
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	if ( $can_resolve ) {
		check( "[$class] S4 blank link re-runs the search", false !== strpos( $html, $deep ), $deep );
	} else {
		check( "[$class] S4 blank link renders the search fallback", false !== strpos( $html, 'href="' . $search_fb . '"' ), 'expected ' . $search_fb );
	}
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] S4 still exactly one row", 1 === count( $wpdb->rows_in( $table ) ), count( $wpdb->rows_in( $table ) ) . ' rows' );
	if ( $can_resolve ) {
		check( "[$class] S4 blank link filled in", $row && $deep === $row['fountain_url'], var_export( $row ? $row['fountain_url'] : null, true ) );
	} else {
		check( "[$class] S4 blank link stays blank", $row && '' === $row['fountain_url'], var_export( $row ? $row['fountain_url'] : null, true ) );
	}
	check( "[$class] S4 Music Index half untouched", $row && 'http://x/y.jpg' === $row['image_url'] && $FUTURE === $row['mi_updated_at'] );

	// --- S5: an edited link is never reverted by a later render, even
	//     though a search would succeed
	reset_state();
	$manual = 'https://fountain.fm/custom/HandFixed';
	$seed( $hash, $album, $track, array( 'fountain_url' => $manual ) );
	$warm_resolve();
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] S5 edited link is what the button points at", false !== strpos( $html, $manual ), $manual );
	check( "[$class] S5 edited link is not overwritten", ! has_sql( 'INSERT INTO' ) && array() === fountain_calls(), 'search ran' );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] S5 row still holds the edited link", $row && $manual === $row['fountain_url'] && '2026-01-01 00:00:00' === $row['fountain_updated_at'] );

	// --- S6: album-only and track-level are separate rows
	reset_state();
	$album_hash = sha1( $album . '|' );
	$seed( $album_hash, $album, '', array( 'track_title' => '', 'fountain_url' => 'https://fountain.fm/album/ALBUMSEED1' ) );
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album ) );
	check( "[$class] S6 album-only render uses the album row", false !== strpos( $html, 'https://fountain.fm/album/ALBUMSEED1' ) );
	check( "[$class] S6 album-only render writes nothing", ! has_sql( 'INSERT INTO' ) );
	if ( $can_resolve ) { $warm_resolve(); }
	$seed( $hash, $album, $track );
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	if ( $can_resolve ) {
		check( "[$class] S6 track render does not reuse the album link", false === strpos( $html, 'ALBUMSEED1' ) && false !== strpos( $html, $deep ) );
	} else {
		check( "[$class] S6 track render does not reuse the album link", false === strpos( $html, 'ALBUMSEED1' ) && false !== strpos( $html, 'href="' . $search_fb . '"' ) );
	}
	check( "[$class] S6 two distinct rows now", 2 === count( $wpdb->rows_in( $table ) ), count( $wpdb->rows_in( $table ) ) . ' rows' );
	$track_row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] S6 track row carries track_guid", $track_row && $track === $track_row['track_guid'] );

	// === Music Index half: branch 1 / 2 / 3 ============================

	// --- B1: fresh metadata -> serve from the row, no HTTP, no queue
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept ) );
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] B1 fresh row renders its cached titles", false !== strpos( $html, 'See Through' ) && false !== strpos( $html, 'Fentlay' ) );
	check( "[$class] B1 fresh row makes no Music Index HTTP", array() === mi_calls(), json_encode( mi_calls() ) );
	check( "[$class] B1 fresh row queues nothing", array() === get_private( $obj, 'mi_refresh_pending' ) );
	check( "[$class] B1 no positive transient is used", array() === mi_transient_keys(), json_encode( mi_transient_keys() ) );

	// --- B2: stale metadata -> still serves immediately, then queues a
	//     background pull (render is the allowed context)
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => $PAST ) );
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] B2 stale row still renders its cached titles", false !== strpos( $html, 'See Through' ), 'titles missing' );
	check( "[$class] B2 stale row serves without blocking HTTP", array() === mi_calls(), json_encode( mi_calls() ) );
	$pending = get_private( $obj, 'mi_refresh_pending' );
	check( "[$class] B2 stale row queues a background pull", isset( $pending[ $hash ] ) && 'track' === $pending[ $hash ]['kind'], json_encode( $pending ) );
	check( "[$class] B2 attempt marker written", isset( $GLOBALS['transients'][ $cfg['attempt'] . md5( $hash ) ] ) );

	// --- B2b: the same stale row through a GATED context (search box,
	//     log beacon, og: preview) serves but never queues
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => $PAST ) );
	$obj = fresh_obj( $class );
	$mi_get_track = new ReflectionMethod( $class, 'mi_get_track' );
	$data = $mi_get_track->invoke( $obj, $album, $track );
	check( "[$class] B2b gated context serves the stale titles", is_array( $data ) && 'See Through' === $data['title'], json_encode( $data ) );
	check( "[$class] B2b gated context queues nothing", array() === get_private( $obj, 'mi_refresh_pending' ), json_encode( get_private( $obj, 'mi_refresh_pending' ) ) );
	check( "[$class] B2b gated context writes no attempt marker", ! isset( $GLOBALS['transients'][ $cfg['attempt'] . md5( $hash ) ] ) );

	// --- B2c: an attempt already in flight suppresses a second queue
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => $PAST ) );
	$GLOBALS['transients'][ $cfg['attempt'] . md5( $hash ) ] = 1;
	$obj = fresh_obj( $class );
	$obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] B2c attempt marker suppresses a second queue", array() === get_private( $obj, 'mi_refresh_pending' ), json_encode( get_private( $obj, 'mi_refresh_pending' ) ) );

	// --- B2d: a remembered failure also suppresses the queue
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => $PAST ) );
	$GLOBALS['transients'][ $cfg['track_key'] . md5( $album . '|' . $track ) ] = array();
	$obj = fresh_obj( $class );
	$obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] B2d remembered failure suppresses the queue", array() === get_private( $obj, 'mi_refresh_pending' ) );

	// --- B2e: a NULL mi_updated_at (a migrated row) is always refresh-
	//     eligible, even at a 48-hour setting
	reset_state();
	$GLOBALS['__options'][ $cfg['mi_opt'] ] = 48;
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => null ) );
	$obj = fresh_obj( $class );
	$obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] B2e NULL mi_updated_at queues even at 48h", isset( get_private( $obj, 'mi_refresh_pending' )[ $hash ] ), json_encode( get_private( $obj, 'mi_refresh_pending' ) ) );

	// --- B2f: the admin interval really decides staleness - the same
	//     2-hour-old stamp queues at 1h and not at 48h
	reset_state();
	$GLOBALS['__options'][ $cfg['mi_opt'] ] = 1;
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ) );
	$obj = fresh_obj( $class );
	$obj->render( array( 'album' => $album, 'track' => $track ) );
	$queued_at_1h = isset( get_private( $obj, 'mi_refresh_pending' )[ $hash ] );

	reset_state();
	$GLOBALS['__options'][ $cfg['mi_opt'] ] = 48;
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ) );
	$obj = fresh_obj( $class );
	$obj->render( array( 'album' => $album, 'track' => $track ) );
	$queued_at_48h = isset( get_private( $obj, 'mi_refresh_pending' )[ $hash ] );
	check( "[$class] B2f option 1h queues a 2-hour-old row", $queued_at_1h );
	check( "[$class] B2f option 48h leaves a 2-hour-old row alone", ! $queued_at_48h );

	// --- B3: no row at all -> blocking fill, recorded on the row
	reset_state();
	$GLOBALS['mi_response'] = array( 'response' => array( 'code' => 200 ), 'body' => $mi_track_ok );
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] B3 blocking fill renders the fetched titles", false !== strpos( $html, 'See Through' ) && false !== strpos( $html, 'Fentlay' ) );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] B3 row created by the fill", is_array( $row ), 'missing row' );
	if ( is_array( $row ) ) {
		check( "[$class] B3 mi_updated_at stamped", '2026-09-26 12:00:00' === $row['mi_updated_at'], var_export( $row['mi_updated_at'], true ) );
		check( "[$class] B3 artwork recorded", 'http://x/y.jpg' === $row['image_url'] );
		check( "[$class] B3 fill does not invent a link", '' === $row['fountain_url'] );
	}
	check( "[$class] B3 success sets no positive transient", array() === mi_transient_keys(), json_encode( mi_transient_keys() ) );
	check( "[$class] B3 fill does not queue a background pull", array() === get_private( $obj, 'mi_refresh_pending' ) );

	// --- B3f: a failed fill remembers the miss and writes no row
	reset_state();
	$GLOBALS['mi_response'] = array( 'response' => array( 'code' => 404 ), 'body' => '' );
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] B3f failed fill renders not-found", false !== strpos( $html, 'Album/Song not found' ), $html );
	check( "[$class] B3f no row written", array() === $wpdb->rows_in( $table ) );
	$neg = $cfg['track_key'] . md5( $album . '|' . $track );
	check( "[$class] B3f definitive miss remembered", array_key_exists( $neg, $GLOBALS['transients'] ) && 3600 === $GLOBALS['transients_ttl'][ $neg ], json_encode( $GLOBALS['transients_ttl'] ) );

	// --- B4: the shutdown pull runs the queued refresh and stamps the row
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => $PAST, 'album_title' => 'Old Title', 'image_url' => '' ) );
	$GLOBALS['mi_response'] = array( 'response' => array( 'code' => 200 ), 'body' => $mi_track_ok );
	$obj = new $class(); // real constructor: captures the shutdown hook
	$hooks = isset( $GLOBALS['__hooks']['shutdown'] ) ? $GLOBALS['__hooks']['shutdown'] : array();
	check( "[$class] B4 constructor registers the shutdown pull", ! empty( $hooks ), json_encode( array_keys( $GLOBALS['__hooks'] ) ) );
	$obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] B4 render queued the pull", isset( get_private( $obj, 'mi_refresh_pending' )[ $hash ] ) );
	check( "[$class] B4 pull has not run yet", 'Old Title' === $wpdb->row_by_hash( $table, $hash )['album_title'] );
	if ( $hooks ) {
		try { call_user_func( $hooks[0] ); } catch ( RuntimeException $e ) {}
	}
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] B4 shutdown pull refreshed the Music Index half", $row && 'The Singles' === $row['album_title'] && 'http://x/y.jpg' === $row['image_url'], json_encode( $row ) );
	check( "[$class] B4 shutdown pull stamped mi_updated_at", $row && '2026-09-26 12:00:00' === $row['mi_updated_at'], $row ? var_export( $row['mi_updated_at'], true ) : '' );
	check( "[$class] B4 shutdown pull left the link alone", $row && $kept === $row['fountain_url'] && '2026-01-01 00:00:00' === $row['fountain_updated_at'], json_encode( $row ) );
	check( "[$class] B4 queue drained", array() === get_private( $obj, 'mi_refresh_pending' ) );

	// --- B4f: a failing shutdown pull leaves the row stale and remembers
	//     the failure instead of touching the link
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'mi_updated_at' => $PAST ) );
	$obj = new $class();
	$obj->render( array( 'album' => $album, 'track' => $track ) );
	try { call_user_func( $GLOBALS['__hooks']['shutdown'][0] ); } catch ( RuntimeException $e ) {}
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] B4f failed pull leaves mi_updated_at stale", $row && $PAST === $row['mi_updated_at'], json_encode( $row ) );
	$neg = $cfg['track_key'] . md5( $album . '|' . $track );
	check( "[$class] B4f failed pull remembers the miss", array_key_exists( $neg, $GLOBALS['transients'] ), json_encode( $GLOBALS['transients'] ) );
	check( "[$class] B4f failed pull leaves the link alone", $row && $kept === $row['fountain_url'] );

	// --- B5: link permanence - across a stale refresh and a re-render the
	//     link half is only ever written by the search path
	reset_state();
	$manual = 'https://fountain.fm/custom/HandFixed';
	$seed( $hash, $album, $track, array( 'fountain_url' => $manual, 'mi_updated_at' => $PAST ) );
	$GLOBALS['mi_response'] = array( 'response' => array( 'code' => 200 ), 'body' => $mi_track_ok );
	$obj = new $class();
	$obj->render( array( 'album' => $album, 'track' => $track ) );
	try { call_user_func( $GLOBALS['__hooks']['shutdown'][0] ); } catch ( RuntimeException $e ) {}
	$row = $wpdb->row_by_hash( $table, $hash );
	$refresh_upserts = array_values( array_filter( sqls( 'ON DUPLICATE KEY UPDATE' ), function ( $q ) {
		return false !== stripos( $q, 'fountain_url = VALUES(fountain_url)' );
	} ) );
	check( "[$class] B5 refresh never rewrites the link", $row && $manual === $row['fountain_url'], json_encode( $row ) );
	check( "[$class] B5 only one link upsert exists and it belongs to the search path", 1 === count( $refresh_upserts ) || 0 === count( $refresh_upserts ), count( $refresh_upserts ) . ' upserts' );

	// --- L2: a genuine remembered miss (the empty array written under the
	//     same key since 1.22.21) still short-circuits the fetch
	reset_state();
	$GLOBALS['mi_response']       = array( 'response' => array( 'code' => 200 ), 'body' => $mi_feed_ok );
	$miss_key                     = $cfg['track_key'] . md5( $album . '|' . $track );
	$GLOBALS['transients'][ $miss_key ] = array();
	$obj  = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	check( "[$class] L2 remembered miss skips the track fetch", 1 === count( mi_calls() ) && false === strpos( json_encode( mi_calls() ), '/tracks/' ), json_encode( mi_calls() ) );
	check( "[$class] L2 remembered miss is kept", array_key_exists( $miss_key, $GLOBALS['transients'] ) );
	check( "[$class] L2 lookup falls back to the album", false !== strpos( $html, 'The Singles' ) && false === strpos( $html, 'from The Singles' ), $html );

	// --- N1: sanitize_mi_refresh_hours matrix
	reset_state();
	$obj = fresh_obj( $class );
	check( "[$class] N1 sanitize 0 -> 12", 12 === $obj->sanitize_mi_refresh_hours( 0 ) );
	check( "[$class] N1 sanitize 5 -> 5", 5 === $obj->sanitize_mi_refresh_hours( 5 ) );
	check( "[$class] N1 sanitize 9999 -> 720", 720 === $obj->sanitize_mi_refresh_hours( 9999 ) );
	check( "[$class] N1 sanitize 'abc' -> 12", 12 === $obj->sanitize_mi_refresh_hours( 'abc' ) );
	check( "[$class] N1 sanitize unset -> 12", 12 === $obj->sanitize_mi_refresh_hours( null ) );

	// === Handlers ======================================================

	// --- H1: clear one app - blanks that app's link column on every row,
	//     keeps the rows and everything else on them
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => 'https://fountain.fm/x/1' ) );
	$seed( $hash2, $album2, $track2, array( 'album_title' => 'Other Album', 'track_title' => 'Other Track', 'artist' => 'Other Artist', 'fountain_url' => 'https://fountain.fm/x/2', 'fountain_updated_at' => '2026-01-02 00:00:00' ) );
	$_POST = array( 'scope' => 'fountain' );
	$msg   = try_call( function () use ( $obj ) { $obj->handle_clear_cache(); } );
	check( "[$class] H1 clear is nonce-checked", in_array( $cfg['nonces']['clear'], $GLOBALS['nonces_checked'], true ), json_encode( $GLOBALS['nonces_checked'] ) );
	check( "[$class] H1 reports the scope + count", 'redirect' === $msg
		&& false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_links_cleared=2' )
		&& false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_cleared_app=fountain' ), (string) $GLOBALS['redirected_to'] );
	check( "[$class] H1 clear blanks the app's link column", has_sql( "SET fountain_url = '', fountain_updated_at =" ) && has_sql( "WHERE fountain_url <> ''" ), 'no column-scoped UPDATE' );
	check( "[$class] H1 clear touches no other column", ! has_sql( 'DELETE FROM' ), 'unexpected statement' );
	$row = $wpdb->row_by_hash( $table, $hash );
	$two = $wpdb->row_by_hash( $table, $hash2 );
	check( "[$class] H1 both rows survive with the link blanked", $row && '' === $row['fountain_url'] && $two && '' === $two['fountain_url'], json_encode( array( $row, $two ) ) );
	check( "[$class] H1 guids/titles/artist survive", $row && $album === $row['album_guid'] && $track === $row['track_guid'] && 'The Singles' === $row['album_title'] && 'See Through' === $row['track_title'] && 'Fentlay' === $row['artist'], json_encode( $row ) );
	check( "[$class] H1 clear stamps fountain_updated_at", $row && '2026-09-26 12:00:00' === $row['fountain_updated_at'], $row ? $row['fountain_updated_at'] : '' );

	// Only the release actually viewed re-resolves (Pro); standard (O21)
	// never re-resolves - it renders the fallback and the row stays
	// blank. The other release stays blank either way.
	if ( $can_resolve ) { $warm_resolve(); }
	$obj = fresh_obj( $class );
	$html = $obj->render( array( 'album' => $album, 'track' => $track ) );
	if ( $can_resolve ) {
		check( "[$class] H1 cleared link comes back, same row", false !== strpos( $html, $deep ) && 2 === count( $wpdb->rows_in( $table ) ), count( $wpdb->rows_in( $table ) ) . ' rows' );
	} else {
		check( "[$class] H1 cleared link renders the fallback, same 2 rows", false !== strpos( $html, 'href="' . $search_fb . '"' ) && 2 === count( $wpdb->rows_in( $table ) ), count( $wpdb->rows_in( $table ) ) . ' rows' );
	}
	$row = $wpdb->row_by_hash( $table, $hash );
	$two = $wpdb->row_by_hash( $table, $hash2 );
	if ( $can_resolve ) {
		check( "[$class] H1 row data intact after the re-resolve", $row && $deep === $row['fountain_url'] && 'The Singles' === $row['album_title'] && 'Fentlay' === $row['artist'], json_encode( $row ) );
	} else {
		check( "[$class] H1 row stays blank after the render", $row && '' === $row['fountain_url'] && 'The Singles' === $row['album_title'] && 'Fentlay' === $row['artist'], json_encode( $row ) );
	}
	check( "[$class] H1 an unviewed release stays blank", $two && '' === $two['fountain_url'] );

	// --- H2: clear all removes the rows themselves
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => 'https://fountain.fm/x/1' ) );
	$seed( $hash2, $album2, $track2, array( 'fountain_url' => 'https://fountain.fm/x/2' ) );
	$_POST = array( 'scope' => 'all' );
	$msg   = try_call( function () use ( $obj ) { $obj->handle_clear_cache(); } );
	check( "[$class] H2 clear-all empties every row", 'redirect' === $msg && false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_cleared=2' ), (string) $GLOBALS['redirected_to'] );
	check( "[$class] H2 no rows left", array() === $wpdb->rows_in( $table ) );
	check( "[$class] H2 uses DELETE, not TRUNCATE", has_sql( 'DELETE FROM' ) && ! has_sql( 'TRUNCATE' ) );

	// --- H3: an unknown scope writes nothing
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => 'https://fountain.fm/x/1' ) );
	$_POST = array( 'scope' => 'not-an-app' );
	try_call( function () use ( $obj ) { $obj->handle_clear_cache(); } );
	check( "[$class] H3 unknown scope clears nothing", null !== $wpdb->row_by_hash( $table, $hash ) && ! has_sql( 'UPDATE ' ) && ! has_sql( 'DELETE FROM' ) );
	check( "[$class] H3 reports zero cleared", false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_cleared=0' ), (string) $GLOBALS['redirected_to'] );

	// --- H4: edit a link (stamps fountain_updated_at, MI half read-only)
	reset_state();
	$id = $seed( $hash, $album, $track, array( 'fountain_url' => 'https://fountain.fm/episode/OLD', 'image_url' => 'http://x/y.jpg' ) );
	$_POST = array( 'link_id' => $id, 'link_field' => 'fountain', 'url' => 'https://fountain.fm/episode/REPLACED' );
	$msg   = try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
	check( "[$class] H4 update is nonce-checked", in_array( $cfg['nonces']['update'], $GLOBALS['nonces_checked'], true ) );
	check( "[$class] H4 redirects with sl_saved", 'redirect' === $msg && false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_saved=1' ), (string) $GLOBALS['redirected_to'] );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] H4 link replaced", $row && 'https://fountain.fm/episode/REPLACED' === $row['fountain_url'], var_export( $row ? $row['fountain_url'] : null, true ) );
	check( "[$class] H4 stamps fountain_updated_at", $row && '2026-09-26 12:00:00' === $row['fountain_updated_at'], $row ? $row['fountain_updated_at'] : '' );
	check( "[$class] H4 leaves the Music Index half alone", $row && 'http://x/y.jpg' === $row['image_url'] && $FUTURE === $row['mi_updated_at'], json_encode( $row ) );

	// --- H5: rejected edits (wrong scheme / off-domain) leave the row alone
	reset_state();
	$id = $seed( $hash, $album, $track, array( 'fountain_url' => $kept ) );
	foreach ( array( 'http://fountain.fm/episode/X', 'https://fountain.fm.evil.com/episode/X', 'https://evil.example/episode/X' ) as $bad ) {
		$_POST = array( 'link_id' => $id, 'link_field' => 'fountain', 'url' => $bad );
		try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
		$row = $wpdb->row_by_hash( $table, $hash );
		check( "[$class] H5 rejected: $bad", $row && $kept === $row['fountain_url'] && false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_error=1' ), var_export( $row ? $row['fountain_url'] : null, true ) );
	}
	// An unknown link field is not a column, so nothing is written either.
	$_POST = array( 'link_id' => $id, 'link_field' => 'notacolumn', 'url' => 'https://fountain.fm/episode/NOPE' );
	try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] H5 unknown link field rejected", $row && $kept === $row['fountain_url'] && false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_error=1' ), var_export( $row ? $row['fountain_url'] : null, true ) );

	// Fail closed for an app with no registry domain (security review
	// 2026-10-06): no known domain means no host can be matched, so the
	// https-only fallback that used to accept ANY host is gone.
	check(
		"[$class] H5 domainless app slug fails closed",
		false === call_private( $obj, 'is_valid_stored_url', array( 'https://anything.example/path', 'no_such_app' ) )
	);
	// The registry-backed paths are unchanged: exact domain accepted...
	check(
		"[$class] H5 registry app still accepts its own host",
		true === call_private( $obj, 'is_valid_stored_url', array( 'https://fountain.fm/episode/X', 'fountain' ) )
	);
	// ...and an unknown slug rejects even that same host.
	check(
		"[$class] H5 domainless slug rejects a registry host too",
		false === call_private( $obj, 'is_valid_stored_url', array( 'https://fountain.fm/episode/X', 'no_such_app' ) )
	);

	// --- H6: a subdomain of the app domain is allowed, blanking is allowed
	reset_state();
	$id = $seed( $hash, $album, $track, array( 'fountain_url' => 'https://fountain.fm/episode/OLD' ) );
	$_POST = array( 'link_id' => $id, 'link_field' => 'fountain', 'url' => 'https://listen.fountain.fm/episode/SUB' );
	try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] H6 subdomain accepted", $row && 'https://listen.fountain.fm/episode/SUB' === $row['fountain_url'], var_export( $row ? $row['fountain_url'] : null, true ) );

	$_POST = array( 'link_id' => $id, 'link_field' => 'fountain', 'url' => '' );
	try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] H6 blanking accepted (forces a re-search)", $row && '' === $row['fountain_url'], var_export( $row ? $row['fountain_url'] : null, true ) );

	// --- H7: remove one row
	reset_state();
	$id = $seed( $hash, $album, $track, array( 'fountain_url' => 'https://fountain.fm/episode/OLD' ) );
	$_POST = array( 'link_id' => $id );
	$msg   = try_call( function () use ( $obj ) { $obj->handle_delete_cache_row(); } );
	check( "[$class] H7 delete is nonce-checked", in_array( $cfg['nonces']['delete'], $GLOBALS['nonces_checked'], true ) );
	check( "[$class] H7 row removed + redirect", 'redirect' === $msg && false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_deleted=1' ) && array() === $wpdb->rows_in( $table ), (string) $GLOBALS['redirected_to'] );

	// --- H8: capability + nonce gates abort before any write
	reset_state();
	$id  = $seed( $hash, $album, $track, array( 'fountain_url' => $kept ) );
	$_POST = array( 'scope' => 'all' );
	$GLOBALS['__can_manage'] = false;
	$msg = try_call( function () use ( $obj ) { $obj->handle_clear_cache(); } );
	check( "[$class] H8 non-admin dies instead of clearing", 0 === strpos( $msg, 'wp_die' ) && null === $GLOBALS['redirected_to'], $msg );
	check( "[$class] H8 rows intact", null !== $wpdb->row_by_hash( $table, $hash ) );

	$_POST = array( 'link_id' => $id, 'link_field' => 'fountain', 'url' => 'https://fountain.fm/episode/NOPE' );
	$GLOBALS['__can_manage'] = true;
	$GLOBALS['__bad_nonce']  = true;
	$msg = try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
	check( "[$class] H8 bad nonce aborts the edit", 'nonce-fail' === $msg && null === $GLOBALS['redirected_to'], $msg );
	$row = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] H8 row untouched after nonce failure", $row && $kept === $row['fountain_url'] );

	// --- H9: artwork edits - no domain rule, blank drops the art, and
	//     only the metadata half is stamped
	reset_state();
	$id  = $seed( $hash, $album, $track, array( 'image_url' => 'http://x/old.jpg', 'mi_updated_at' => $PAST ) );
	$tag = '2026-09-26 12:00:00';

	$_POST = array( 'link_id' => $id, 'link_field' => 'art', 'url' => 'https://cdn.example/new.jpg' );
	$msg   = try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
	$row   = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] H9 artwork edit succeeds off-domain", 'redirect' === $msg && false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_art_saved=1' ), (string) $GLOBALS['redirected_to'] );
	check( "[$class] H9 artwork URL replaced", $row && 'https://cdn.example/new.jpg' === $row['image_url'], var_export( $row ? $row['image_url'] : null, true ) );
	check( "[$class] H9 artwork edit stamps mi_updated_at", $row && $tag === $row['mi_updated_at'], $row ? $row['mi_updated_at'] : '' );
	check( "[$class] H9 artwork edit leaves the Fountain half alone", $row && '2026-01-01 00:00:00' === $row['fountain_updated_at'] && '' === $row['fountain_url'], json_encode( $row ) );

	$_POST = array( 'link_id' => $id, 'link_field' => 'art', 'url' => 'notaurl' );
	$msg   = try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
	$row   = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] H9 non-http artwork URL rejected", $row && 'https://cdn.example/new.jpg' === $row['image_url'] && false !== strpos( (string) $GLOBALS['redirected_to'], 'sl_art_error=1' ), json_encode( $row ) );

	$_POST = array( 'link_id' => $id, 'link_field' => 'art', 'url' => '' );
	$msg   = try_call( function () use ( $obj ) { $obj->handle_update_cache_row(); } );
	$row   = $wpdb->row_by_hash( $table, $hash );
	check( "[$class] H9 blank artwork URL clears the art", 'redirect' === $msg && $row && '' === $row['image_url'], var_export( $row ? $row['image_url'] : null, true ) );
	check( "[$class] H9 clearing artwork stamps mi_updated_at", $row && $tag === $row['mi_updated_at'], $row ? $row['mi_updated_at'] : '' );

	// === Cache tab =====================================================

	// --- A1: the Cache tab renders rows + per-field filter SQL
	reset_state();
	$seed( $hash, $album, $track, array( 'fountain_url' => $kept, 'fountain_updated_at' => '2026-09-26 12:00:00' ) );
	$_GET = array( 'sl_artist' => 'Miles', 'sl_fountain_url' => 'fountain.fm', 'sl_paged' => '1' );
	$out  = render_tab( fresh_obj( $class ) );
	check( "[$class] A1 tab renders the row", false !== strpos( $out, 'The Singles' ), 'row missing' );
	check( "[$class] A1 artist filter matches with LIKE", has_sql( 'artist LIKE' ), 'no artist LIKE predicate' );
	check( "[$class] A1 link filter matches with LIKE", has_sql( 'fountain_url LIKE' ), 'no link LIKE predicate' );
	check( "[$class] A1 no app filter remains", ! has_sql( "app = '" ) && false === strpos( $out, 'name="sl_app"' ), 'app filter still present' );
	check( "[$class] A1 link field labelled 'Fountain.fm link'", false !== strpos( $out, 'Fountain.fm link' ), 'label missing' );
	check( "[$class] A1 edit row names its link field", false !== strpos( $out, 'name="link_field" value="fountain"' ), 'link_field missing' );
	check( "[$class] A1 filter form posts back to the cache tab", false !== strpos( $out, 'name="tab" value="cache"' ), 'hidden tab input missing' );
	check( "[$class] A1 clear control offers per-app scopes", false !== strpos( $out, 'value="fountain"' ) && false !== strpos( $out, 'value="all"' ) );
	check( "[$class] A1 row actions use the new handlers/nonces", false !== strpos( $out, 'value="' . $cfg['action'] . '_delete_cache_row"' )
		&& false !== strpos( $out, 'value="' . $cfg['action'] . '_update_cache_row"' )
		&& false !== strpos( $out, 'name="action" value="' . $cfg['action'] . '_clear_cache"' ), 'old action name still rendered' );

	// Pagination (and the handlers' redirects) reuse this URL, so a filter
	// it forgets would silently drop the admin back to the unfiltered page.
	$url = call_private( fresh_obj( $class ), 'cache_admin_url', array( array( 'sl_paged' => 2 ) ) );
	check( "[$class] A1 pagination carries every active filter", false !== strpos( $url, 'sl_artist=Miles' )
		&& false !== strpos( $url, 'sl_fountain_url=fountain.fm' )
		&& false !== strpos( $url, 'sl_paged=2' ), $url );
	check( "[$class] A1 pagination points at the cache tab", false !== strpos( $url, 'tab=cache' ), $url );
	check( "[$class] A1 pagination carries no dead filter keys", false === strpos( $url, 'sl_app' ) && false === strpos( $url, 'sl_url=' ), $url );

	// --- A2: column order follows Metadata Updated, Artist, Album, Track,
	//     GUIDs, Art, link, Fountain updated - and there is no App column
	$thead = after( $out, '<table' );
	check( "[$class] A2 column order", $thead && in_order( $thead, array(
		'>Metadata Updated<', '>Artist<', '>Album title<', '>Track title<', '>Album GUID<', '>Track GUID<',
		'>Art<', '>Fountain.fm link<', '>Fountain updated<',
	) ), 'unexpected column order' );
	check( "[$class] A2 rows span ten columns", $thead && false !== strpos( $thead, 'colspan="10"' ), 'colspan 10 missing' );
	check( "[$class] A2 no App column", $thead && false === strpos( $thead, '>App<' ), 'App column present' );
	$form = after( $out, '<form method="get"' );
	check( "[$class] A2 filter order matches", $form && in_order( $form, array(
		'name="sl_artist"', 'name="sl_album_title"', 'name="sl_track_title"',
		'name="sl_album_guid"', 'name="sl_track_guid"', 'name="sl_fountain_url"',
	) ), 'unexpected filter order' );
	check( "[$class] A2 description rewritten for the cache model", false !== strpos( $out, 'One row per release: cached Music Index metadata' )
		&& false === strpos( $out, 'Links are stored here the first time' )
		&& false === strpos( $out, 'Music apps that do not support direct access' ), 'description not replaced' );

	// --- A3: the notice set announces each outcome in cache vocabulary
	$_GET = array( 'sl_links_cleared' => '2', 'sl_cleared_app' => 'fountain' );
	$out  = render_tab( fresh_obj( $class ) );
	check( "[$class] A3 per-app notice names the app + row count", false !== strpos( $out, 'Fountain.fm link cleared on 2 row(s).' ), 'notice missing' );

	$_GET = array( 'sl_cleared' => '3' );
	$out  = render_tab( fresh_obj( $class ) );
	check( "[$class] A3 clear-all notice says cache rows were removed", false !== strpos( $out, '3 cache row(s) removed.' ), 'notice missing' );

	$_GET = array( 'sl_saved' => '1' );
	$out  = render_tab( fresh_obj( $class ) );
	check( "[$class] A3 save notice", false !== strpos( $out, 'Fountain.fm link updated.' ), 'notice missing' );

	$_GET = array( 'sl_art_saved' => '1' );
	$out  = render_tab( fresh_obj( $class ) );
	check( "[$class] A3 artwork save notice", false !== strpos( $out, 'Artwork URL updated.' ), 'notice missing' );

	$_GET = array( 'sl_art_error' => '1' );
	$out  = render_tab( fresh_obj( $class ) );
	check( "[$class] A3 artwork error notice", false !== strpos( $out, 'That artwork URL was not saved: it must be an http(s) URL, or blank to remove the artwork.' ), 'notice missing' );

	$_GET = array( 'sl_deleted' => '1' );
	$out  = render_tab( fresh_obj( $class ) );
	check( "[$class] A3 delete notice", false !== strpos( $out, 'Cache row removed. It will be created again if the release is viewed again.' ), 'notice missing' );

	$_GET = array( 'sl_paged' => '1' );
	$out  = render_tab( fresh_obj( $class ) );
	check( "[$class] A3 row counter uses cache vocabulary", false !== strpos( $out, 'cached row(s).' ), 'counter missing' );

	// --- A4: the settings page exposes the tab and still saves settings
	$_GET = array( 'tab' => 'cache' );
	$out  = render_settings( fresh_obj( $class ) );
	check( "[$class] A4 nav tabs rendered", false !== strpos( $out, 'nav-tab-wrapper' ) && false !== strpos( $out, 'Cache' ) );
	check( "[$class] A4 nav link points at tab=cache", false !== strpos( $out, 'tab=cache' ) );
	check( "[$class] A4 stale 'Stored links' wording is gone", false === strpos( $out, 'Stored links' ), 'old tab label still present' );

	$_GET = array( 'tab' => 'settings' );
	$out  = render_settings( fresh_obj( $class ) );
	check( "[$class] A4 settings form still posts to options.php", false !== strpos( $out, 'action="options.php"' ) );
	check( "[$class] A4 MI refresh setting present", false !== strpos( $out, $cfg['mi_opt'] ), 'refresh setting missing' );

	// --- A5: rows show status marks rather than rendered URLs or
	//     thumbnails, dates carry no time, and the artwork dialog exists
	reset_state();
	$seed( $hash, $album, $track, array(
		'fountain_url' => $kept, 'fountain_updated_at' => '2026-09-26 12:00:00',
		'mi_updated_at' => '2026-09-25 08:07:06', 'image_url' => 'https://img.example/art.jpg',
	) );
	$seed( $hash2, $album2, $track2, array(
		'fountain_url' => '', 'fountain_updated_at' => '2026-09-20 11:11:11',
		'mi_updated_at' => null, 'image_url' => '',
	) );
	$_GET = array();
	$out  = render_tab( fresh_obj( $class ) );

	check( "[$class] A5 stored artwork shows a tick", 1 === substr_count( $out, '&#10003;' ), substr_count( $out, '&#10003;' ) . ' ticks' );
	check( "[$class] A5 missing artwork / link show a cross", 2 === substr_count( $out, '&#10007;' ), substr_count( $out, '&#10007;' ) . ' crosses' );
	check( "[$class] A5 art control carries the stored URL", false !== strpos( $out, 'data-url="https://img.example/art.jpg"' ), 'data-url missing' );
	check( "[$class] A5 both rows expose an art control", 2 === substr_count( $out, 'v4v-sl-art-toggle"' ), substr_count( $out, 'v4v-sl-art-toggle"' ) . ' controls' );
	check( "[$class] A5 no thumbnail is rendered", false === strpos( $out, 'width="40"' ), 'thumbnail still rendered' );

	check( "[$class] A5 stored link is not printed as text", false === strpos( $out, '>' . $kept . '<' ), 'URL still shown as the cell text' );
	check( "[$class] A5 stored link gets a Launch button", 1 === substr_count( $out, '>Launch<' ) && false !== strpos( $out, 'href="' . $kept . '"' ), 'Launch missing' );
	check( "[$class] A5 empty link is not a Launch", 1 === substr_count( $out, '>Launch<' ), 'Launch rendered for an empty link' );
	check( "[$class] A5 both rows still offer Edit", 2 === substr_count( $out, '>Edit<' ), substr_count( $out, '>Edit<' ) . ' Edit buttons' );
	check( "[$class] A5 stale 'empty - will re-search' wording gone", false === strpos( $out, 'empty - will re-search' ), 'old wording present' );

	check( "[$class] A5 dates are date-only", false !== strpos( $out, '>2026-09-26<' ) && false !== strpos( $out, '>2026-09-25<' ) && false !== strpos( $out, '>2026-09-20<' ), 'date cell missing' );
	check( "[$class] A5 no time-of-day is printed", false === strpos( $out, '12:00:00' ) && false === strpos( $out, '08:07:06' ) && false === strpos( $out, '11:11:11' ), 'time still rendered' );
	check( "[$class] A5 a NULL metadata stamp is an em dash", false !== strpos( $out, '<td>—</td>' ), 'NULL stamp not blanked' );

	check( "[$class] A5 artwork dialog rendered", false !== strpos( $out, 'id="v4v-sl-art-modal"' )
		&& false !== strpos( $out, 'id="v4v-sl-art-img"' )
		&& false !== strpos( $out, 'id="v4v-sl-art-url"' ), 'dialog missing' );
	check( "[$class] A5 dialog edits the art column through the row handler", false !== strpos( $out, 'name="link_field" value="art"' )
		&& false !== strpos( $out, 'id="v4v-sl-art-id"' )
		&& false !== strpos( $out, 'value="' . $cfg['action'] . '_update_cache_row"' ), 'dialog wiring missing' );

	// === Fresh install ===================================================

	// --- M0: with no version flag at all (a brand-new site), the plugin
	//     creates the cache table, stamps the version, and issues none of
	//     the legacy migration SQL - that code no longer exists.
	reset_state();
	unset( $GLOBALS['__options'][ $cfg['version_opt'] ] );

	$obj = fresh_obj( $class );
	call_private( $obj, 'maybe_create_cache_table' );

	check( "[$class] M0 version stamped to 1", '1' === (string) ( isset( $GLOBALS['__options'][ $cfg['version_opt'] ] ) ? $GLOBALS['__options'][ $cfg['version_opt'] ] : '' ), var_export( isset( $GLOBALS['__options'][ $cfg['version_opt'] ] ) ? $GLOBALS['__options'][ $cfg['version_opt'] ] : null, true ) );
	$dbd = $GLOBALS['__dbdelta'] ? $GLOBALS['__dbdelta'][0] : '';
	check( "[$class] M0 dbDelta ran against the cache schema", $dbd && false !== strpos( $dbd, 'fountain_url' ) && false !== strpos( $dbd, 'mi_updated_at' ) && false !== strpos( $dbd, 'fountain_updated_at' ), $dbd ? 'cache schema missing fields' : 'dbDelta not called' );
	check( "[$class] M0 no legacy migration SQL issued", ! has_sql( 'ALTER TABLE' ) && ! has_sql( 'INSERT IGNORE' ) && ! has_sql( 'SHOW COLUMNS' ), 'legacy SQL still issued' );

	// A second call with the version already current does nothing at all.
	$before = count( $wpdb->queries ) + count( $GLOBALS['__dbdelta'] );
	call_private( $obj, 'maybe_create_cache_table' );
	check( "[$class] M0 second call is a no-op", count( $wpdb->queries ) + count( $GLOBALS['__dbdelta'] ) === $before, 'statements issued anyway' );

	reset_state();
	check( "[$class] M0 empty state text renders", false !== strpos( render_tab( fresh_obj( $class ) ), 'No cached rows match.' ) );

	// === Random order (O16) ==============================================

	$order_slugs = array( 'stablekraft', 'podtunes', 'v4vmusic', 'fountain' );
	$slugs_pos   = function ( $h ) use ( $order_slugs ) {
		$pos = array();
		foreach ( $order_slugs as $s ) {
			$p    = strpos( $h, '-' . $s . '"' );
			$pos[] = false === $p ? -1 : $p;
		}
		return $pos;
	};
	$in_order    = function ( array $pos ) {
		foreach ( range( 0, count( $pos ) - 2 ) as $i ) {
			if ( $pos[ $i ] < 0 || $pos[ $i ] >= $pos[ $i + 1 ] ) {
				return false;
			}
		}
		return true;
	};

	$links4 = array(
		'stablekraft' => array( 'url' => 'https://stablekraft.app/x', 'label' => 'StableKraft.app', 'icon_url' => '' ),
		'podtunes'    => array( 'url' => 'https://podtunes.app/x', 'label' => 'PodTunes.app', 'icon_url' => '' ),
		'v4vmusic'    => array( 'url' => 'https://v4vmusic.com/x', 'label' => 'v4vmusic.com', 'icon_url' => '' ),
		'fountain'    => array( 'url' => 'https://fountain.fm/x', 'label' => 'Fountain.fm', 'icon_url' => '' ),
	);
	$opt = $cfg['random_opt'];

	// --- R1: the settings tickbox renders; the admin table keeps the
	//     configured row order whatever the option is set to.
	reset_state();
	$GLOBALS['__options'][ $opt ] = true;
	$out = render_settings( fresh_obj( $class ) );
	check( "[$class] R1 random-order checkbox rendered", false !== strpos( $out, 'name="' . $opt . '"' ) && false !== strpos( $out, 'id="' . $opt . '"' ), 'checkbox missing' );
	check( "[$class] R1 help text promises a fresh order per load", false !== strpos( $out, 'fresh random order' ), 'description missing' );
	$admin_pos = array();
	foreach ( $order_slugs as $s ) {
		$p         = strpos( $out, '[' . $s . '][order]' );
		$admin_pos[] = false === $p ? -1 : $p;
	}
	check( "[$class] R1 settings row order untouched with the option on", $in_order( $admin_pos ), json_encode( $admin_pos ) );
	check( "[$class] R1 settings page carries no shuffle attribute", false === strpos( $out, 'data-v4v-shuffle' ) );

	// --- R2: server markup keeps the configured order; the shuffle
	//     attribute + script appear only when the option is on and more
	//     than one button renders.
	reset_state();
	$html = call_private( fresh_obj( $class ), 'render_html', array( $links4, true, 'Album', 'Track', 'Artist', '' ) );
	check( "[$class] R2 option off keeps the configured order", $in_order( $slugs_pos( $html ) ), json_encode( $slugs_pos( $html ) ) );
	check( "[$class] R2 option off emits no shuffle attribute or script", false === strpos( $html, 'data-v4v-shuffle' ) && false === strpos( $html, 'Math.random' ) );

	$GLOBALS['__options'][ $opt ] = true;
	$html = call_private( fresh_obj( $class ), 'render_html', array( $links4, true, 'Album', 'Track', 'Artist', '' ) );
	check( "[$class] R2 option on marks the list", false !== strpos( $html, 'data-v4v-shuffle="1"' ) );
	check( "[$class] R2 option on emits the shuffle script", false !== strpos( $html, 'Math.random' ) && false !== strpos( $html, 'querySelectorAll' ), 'script missing' );
	check( "[$class] R2 option on: server order is still the configured one", $in_order( $slugs_pos( $html ) ), json_encode( $slugs_pos( $html ) ) );
	check( "[$class] R2 exactly one self-contained script block", 1 === substr_count( $html, '<script>' ) && 1 === substr_count( $html, '</script>' ) );

	$html = call_private( fresh_obj( $class ), 'render_html', array( array( 'stablekraft' => $links4['stablekraft'] ), true, 'Album', 'Track', 'Artist', '' ) );
	check( "[$class] R2 a single button gets no shuffle", false === strpos( $html, 'data-v4v-shuffle' ) && false === strpos( $html, 'Math.random' ) );

	// === Icon background colour, per app (O22) ==========================

	// Save: a valid colour persists as stored, "None (transparent)"
	// beats whatever the swatch holds, invalid input falls back to
	// transparent, and every app row always carries the key.
	$clean = fresh_obj( $class )->sanitize_apps_settings( array(
		'stablekraft' => array( 'enabled' => '1', 'icon_url' => '', 'order' => '0', 'icon_bg' => '#12AB34' ),
		'podtunes'    => array( 'enabled' => '1', 'icon_url' => '', 'order' => '1', 'icon_bg' => '#ffcc00', 'icon_bg_none' => '1' ),
		'v4vmusic'    => array( 'enabled' => '1', 'icon_url' => '', 'order' => '2', 'icon_bg' => 'red;background:url(x)' ),
		'fountain'    => array( 'enabled' => '1', 'icon_url' => '', 'order' => '3' ),
	) );
	check( "[$class] G1 valid icon background colour persists", '#12AB34' === $clean['stablekraft']['icon_bg'], var_export( $clean['stablekraft']['icon_bg'], true ) );
	check( "[$class] G1 'None (transparent)' clears the colour", '' === $clean['podtunes']['icon_bg'], var_export( $clean['podtunes']['icon_bg'], true ) );
	check( "[$class] G1 invalid input falls back to transparent", '' === $clean['v4vmusic']['icon_bg'], var_export( $clean['v4vmusic']['icon_bg'], true ) );
	check( "[$class] G1 every app row carries the icon_bg key", array_key_exists( 'icon_bg', $clean['fountain'] ) && '' === $clean['fountain']['icon_bg'] );

	// Settings: the Background column and its two inputs render for
	// every app, with the saved state (colour vs None) reflected.
	$_GET = array( 'tab' => 'settings' );
	$GLOBALS['__options'][ $cfg['apps_opt'] ] = array( 'stablekraft' => array( 'enabled' => true, 'icon_url' => '', 'icon_bg' => '#12ab34', 'order' => 0 ) );
	$out  = render_settings( fresh_obj( $class ) );
	check( "[$class] G2 settings Background column header", false !== strpos( $out, '>Background</th>' ), 'column missing' );
	check( "[$class] G2 colour input named [icon_bg]", false !== strpos( $out, '[icon_bg]"' ) && false !== strpos( $out, 'type="color"' ), 'colour input missing' );
	check( "[$class] G2 None checkbox named [icon_bg_none]", false !== strpos( $out, '[icon_bg_none]"' ), 'none checkbox missing' );
	check( "[$class] G2 preview reflects the saved colour", false !== strpos( $out, 'background-color:#12ab34;' ), 'preview style missing' );
	check( "[$class] G2 unset apps render no preview background", false !== strpos( $out, 'background-color:' ) && 1 === substr_count( $out, 'background-color:' ) );

	// Front end: only the configured app's icon carries the inline
	// background, and junk stored by hand never reaches the page.
	$html = call_private( fresh_obj( $class ), 'render_html', array( $links4, true, 'Album', 'Track', 'Artist', '' ) );
	check( "[$class] G3 configured background renders on the icon", false !== strpos( $html, 'background-color: #12ab34' ), 'style missing' );
	check( "[$class] G3 only the configured app gets a background", 1 === substr_count( $html, 'background-color' ), substr_count( $html, 'background-color' ) . ' occurrences' );

	reset_state();
	$GLOBALS['__options'][ $cfg['apps_opt'] ] = array( 'stablekraft' => array( 'enabled' => true, 'icon_url' => '', 'icon_bg' => 'red;position:fixed', 'order' => 0 ) );
	$html = call_private( fresh_obj( $class ), 'render_html', array( $links4, true, 'Album', 'Track', 'Artist', '' ) );
	check( "[$class] G3 hand-edited junk never reaches the page", false === strpos( $html, 'background-color' ), 'junk rendered' );

	reset_state();
	$html = call_private( fresh_obj( $class ), 'render_html', array( $links4, true, 'Album', 'Track', 'Artist', '' ) );
	check( "[$class] G3 no configured background emits no style", false === strpos( $html, 'background-color' ), 'style emitted without a setting' );

	reset_state();
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
