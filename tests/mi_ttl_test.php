<?php
/**
 * Behavioural test for negative-cache TTL selection (#8) plus the O12
 * positive path: successes are written to the cache row (with a
 * mi_updated_at stamp) instead of a positive transient.
 *
 * Loads both real plugin files with WordPress stubbed out, then drives the
 * private mi_get_track()/mi_get_feed() methods through every failure shape
 * and asserts how long each miss is remembered:
 *
 *   definitive (404, or 200-without-title)  -> FAIL_CACHE_TTL    (3600 s)
 *   unconfirmed (transport/5xx/3xx/bad body) -> SOFT_FAIL_CACHE  (30 s)
 *   success                                 -> a row write, no transient
 *
 * Plus the option-driven staleness case: the same 2-hour-old row queues a
 * background pull at a 1-hour refresh interval and stays quiet at 48 hours.
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

class Fake_WP_Error { public $msg = 'stubbed transport failure'; }

$GLOBALS['fake_response']  = null;
$GLOBALS['transients']     = array();
$GLOBALS['transients_ttl'] = array();
$GLOBALS['last_ttl']       = null;

function is_wp_error( $x )                          { return $x instanceof Fake_WP_Error; }
function wp_remote_get( $url, $args = array() )     { return $GLOBALS['fake_response']; }
function wp_remote_post( $url, $args = array() )    { return $GLOBALS['fake_response']; }
function wp_remote_retrieve_response_code( $r )     { return is_array($r) && isset($r['response']['code']) ? (int)$r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )              { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }
function get_transient( $k )                        { return array_key_exists($k, $GLOBALS['transients']) ? $GLOBALS['transients'][$k] : false; }
function set_transient( $k, $v, $ttl )              { $GLOBALS['transients'][$k] = $v; $GLOBALS['transients_ttl'][$k] = $ttl; $GLOBALS['last_ttl'] = $ttl; return true; }
function delete_transient( $k )                     { unset($GLOBALS['transients'][$k]); return true; }
function add_shortcode() {}
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function get_option( $k, $d = false )               {
	// Per-test overrides (the refresh interval) win over the defaults.
	if ( isset( $GLOBALS['__options'][ $k ] ) ) { return $GLOBALS['__options'][ $k ]; }
	// Pretend the schemas are already installed, so a constructor would
	// never reach dbDelta()/upgrade.php in this stub.
	if ( 'v4v_musiclink_cache_table_version' === $k || 'v4v_musiclink_pro_cache_table_version' === $k ) { return '1'; }
	if ( 'v4v_musiclink_pro_log_table_version' === $k ) { return '2'; }
	return $d;
}
function update_option( $k, $v, $a = false )        { return true; }
function delete_option( $k )                        { return true; }
function home_url( $p = '' )                        { return 'http://example.test' . $p; }
function current_time( $type )                      { return 'mysql' === $type ? '2026-09-26 12:00:00' : time(); }
// Harness clock: the site runs in GMT, so the freshness cutoff and the
// current_time() stamp are directly comparable.
function get_date_from_gmt( $string, $format = 'Y-m-d H:i:s' ) { return $string; }
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

/**
 * Just enough $wpdb for this test: the row SELECT by hash (so a seeded
 * row can be served through branch 1/2) and a recorder for the
 * INSERT ... ON DUPLICATE KEY UPDATE that a successful fetch issues.
 */
class Wpdb_Stub {
	public $prefix  = 'wp_';
	public $queries = array();
	public $rows    = array(); // lookup_hash => assoc row

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

	public function query( $sql ) {
		$this->queries[] = $sql;
		// Record the written row so a success can be asserted on data,
		// not just on the statement's presence.
		if ( preg_match( "/^INSERT INTO\s+\S+\s*\((.*?)\)\s*VALUES\s*\((.*?)\)\s*ON DUPLICATE/is", $sql, $m ) ) {
			$cols = array_map( 'trim', explode( ',', $m[1] ) );
			$vals = str_getcsv( $m[2], ',', "'", '\\' );
			if ( count( $cols ) === count( $vals ) ) {
				$row = array_combine( $cols, $vals );
				if ( isset( $row['lookup_hash'] ) ) {
					$this->rows[ $row['lookup_hash'] ] = array_merge(
						isset( $this->rows[ $row['lookup_hash'] ] ) ? $this->rows[ $row['lookup_hash'] ] : array(),
						$row
					);
				}
			}
		}
		return 1;
	}

	public function get_row( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/lookup_hash = '([^']*)'/", $sql, $m ) && isset( $this->rows[ $m[1] ] ) ) {
			return (object) $this->rows[ $m[1] ];
		}
		return null;
	}

	public function has_sql( $needle ) {
		foreach ( $this->queries as $q ) {
			if ( false !== stripos( $q, $needle ) ) { return true; }
		}
		return false;
	}
}
$GLOBALS['wpdb'] = new Wpdb_Stub();

require __DIR__ . '/../v4v-musiclink/v4v-musiclink.php';
require __DIR__ . '/../v4v-musiclink-pro/v4v-musiclink-pro.php';

$track_ok = json_encode( array( 'data' => array( 'title' => 'See Through', 'feed_title' => 'The Singles', 'track_artist' => 'Fentlay', 'image_url' => 'http://x/y.jpg' ) ) );
$feed_ok  = json_encode( array( 'data' => array( 'title' => 'The Singles', 'release_artist' => 'Fentlay', 'image_url' => 'http://x/y.jpg' ) ) );
$no_title = json_encode( array( 'data' => array( 'feed_guid' => 'x' ) ) );

$album = 'd5c805fb-6016-4038-9cda-2e68d2b8dcc4';
$track = 'cbdaeaa0-3c58-41ce-92d1-c718710ade54';
// Two identities: a track row (album|track) and an album-only row (album|).
$hash       = sha1( $album . '|' . $track );
$hash_feed  = sha1( $album . '|' );

$cases = array(
	// label,                          response,                              expect-null, expected TTL, method
	'404 definitively missing guid' => array( array( 'response' => array( 'code' => 404 ), 'body' => '' ), true, 3600, 'track' ),
	'200 but titleless payload'     => array( array( 'response' => array( 'code' => 200 ), 'body' => $no_title ), true, 3600, 'track' ),
	'transport error (WP_Error)'    => array( new Fake_WP_Error(), true, 30, 'track' ),
	'500 server error'              => array( array( 'response' => array( 'code' => 500 ), 'body' => '' ), true, 30, 'track' ),
	'301 redirect (blocked)'        => array( array( 'response' => array( 'code' => 301 ), 'body' => '' ), true, 30, 'track' ),
	'200 but undecodable body'      => array( array( 'response' => array( 'code' => 200 ), 'body' => '<html>gateway error</html>' ), true, 30, 'track' ),
	'success writes the row'        => array( array( 'response' => array( 'code' => 200 ), 'body' => $track_ok ), false, null, 'track' ),
	'feed 404 also definitive'      => array( array( 'response' => array( 'code' => 404 ), 'body' => '' ), true, 3600, 'feed' ),
	'feed transport error is soft'  => array( new Fake_WP_Error(), true, 30, 'feed' ),
	'feed success writes the row'   => array( array( 'response' => array( 'code' => 200 ), 'body' => $feed_ok ), false, null, 'feed' ),
);

$classes = array(
	'V4V_Music_Link_Resolver'     => 'v4v_musiclink_mi_refresh_hours',
	'V4V_Music_Link_Resolver_Pro' => 'v4v_musiclink_pro_mi_refresh_hours',
);
$pass = 0; $fail = 0;

function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS  $label\n"; }
	else       { $fail++; echo "FAIL  $label $detail\n"; }
}
function fresh_obj( $class ) {
	$rc = new ReflectionClass( $class );
	return $rc->newInstanceWithoutConstructor();
}
function set_private( $obj, $prop, $value ) {
	$p = new ReflectionProperty( get_class( $obj ), $prop );
	$p->setValue( $obj, $value );
}
function get_private( $obj, $prop ) {
	$p = new ReflectionProperty( get_class( $obj ), $prop );
	return $p->getValue( $obj );
}
function mi_transient_keys() {
	return array_values( array_filter( array_keys( $GLOBALS['transients'] ), function ( $k ) {
		return 0 === strpos( $k, 'mi_feed_' ) || 0 === strpos( $k, 'mi_track_' );
	} ) );
}
function reset_case() {
	$GLOBALS['wpdb']->queries = array();
	$GLOBALS['wpdb']->rows    = array();
	$GLOBALS['transients']    = array();
	$GLOBALS['transients_ttl']= array();
	$GLOBALS['last_ttl']      = null;
}

foreach ( $classes as $class => $mi_opt ) {
	$rc = new ReflectionClass( $class );

	foreach ( $cases as $label => $case ) {
		list( $response, $expect_null, $expect_ttl, $which ) = $case;

		reset_case();
		$GLOBALS['fake_response'] = $response;
		$obj  = fresh_obj( $class );
		$method = $rc->getMethod( 'mi_get_' . $which );
		$result = $method->invoke( $obj, $album, $track );

		$problems = array();
		if ( $expect_null && null !== $result ) { $problems[] = 'expected null, got ' . json_encode( $result ); }
		if ( ! $expect_null && null === $result ) { $problems[] = 'expected data, got null'; }

		if ( null === $expect_ttl ) {
			// Success: written onto the cache row, nothing remembered.
			if ( array() !== mi_transient_keys() ) {
				$problems[] = 'positive transient set: ' . json_encode( mi_transient_keys() );
			}
			if ( ! $GLOBALS['wpdb']->has_sql( 'ON DUPLICATE KEY UPDATE' ) ) {
				$problems[] = 'no row write issued';
			}
			if ( ! $GLOBALS['wpdb']->has_sql( 'mi_updated_at = VALUES(mi_updated_at)' ) ) {
				$problems[] = 'row write does not stamp mi_updated_at';
			}
			$row_hash  = ( 'feed' === $which ) ? $hash_feed : $hash;
			$row = isset( $GLOBALS['wpdb']->rows[ $row_hash ] ) ? $GLOBALS['wpdb']->rows[ $row_hash ] : null;
			if ( ! $row || ! isset( $row['mi_updated_at'] ) ) {
				$problems[] = 'written row has no mi_updated_at: ' . json_encode( $row );
			}
			// The link half is not the Music Index path's to touch.
			if ( $GLOBALS['wpdb']->has_sql( 'fountain_url = VALUES(fountain_url)' ) ) {
				$problems[] = 'success wrote the link half too';
			}
		} elseif ( $expect_ttl !== $GLOBALS['last_ttl'] ) {
			$problems[] = 'TTL ' . var_export( $GLOBALS['last_ttl'], true ) . ' != expected ' . $expect_ttl;
		}

		if ( $problems ) {
			$fail++;
			echo "FAIL  [$class] $label: " . implode( '; ', $problems ) . "\n";
		} else {
			$pass++;
			$verb = ( null === $expect_ttl ) ? 'row write' : ( $expect_null ? 'neg ' . $GLOBALS['last_ttl'] . 's' : 'pos ' . $GLOBALS['last_ttl'] . 's' );
			echo "PASS  [$class] $label (" . str_pad( $verb, 10 ) . ")\n";
		}
	}

	// --- option-driven staleness: the SAME 2-hour-old row queues at a
	//     1-hour interval and stays quiet at 48 hours.
	$stale = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
	$seeded = function () use ( $hash, $album, $track, $stale ) {
		$GLOBALS['wpdb']->rows[ $hash ] = array(
			'lookup_hash' => $hash, 'album_guid' => $album, 'track_guid' => $track,
			'album_title' => 'The Singles', 'track_title' => 'See Through',
			'artist' => 'Fentlay', 'image_url' => 'http://x/y.jpg',
			'fountain_url' => 'https://fountain.fm/episode/KEEP',
			'mi_updated_at' => $stale, 'fountain_updated_at' => '2026-01-01 00:00:00',
		);
	};

	foreach ( array( 1 => true, 48 => false ) as $hours => $expect_queue ) {
		reset_case();
		$seeded();
		$GLOBALS['__options'][ $mi_opt ] = $hours;
		$GLOBALS['fake_response'] = new Fake_WP_Error(); // never reached if queued-only
		$obj = fresh_obj( $class );
		set_private( $obj, 'mi_refresh_allowed', true ); // the render context
		$rc->getMethod( 'mi_get_' . 'track' )->invoke( $obj, $album, $track );
		$pending  = get_private( $obj, 'mi_refresh_pending' );
		$queued   = isset( $pending[ $hash ] );
		$expected = $expect_queue;
		$label = "[$class] option {$hours}h: 2-hour-old row " . ( $expect_queue ? 'queues' : 'does not queue' );
		if ( $queued === $expected ) {
			$pass++;
			echo "PASS  $label\n";
		} else {
			$fail++;
			echo "FAIL  $label (pending: " . json_encode( $pending ) . ")\n";
		}
	}

	// A gated context (search box / log beacon / og: preview) serves the
	// stale row but never queues, whatever the interval says.
	reset_case();
	$seeded();
	$GLOBALS['__options'][ $mi_opt ] = 1;
	$obj = fresh_obj( $class );
	$rc->getMethod( 'mi_get_track' )->invoke( $obj, $album, $track );
	$pending = get_private( $obj, 'mi_refresh_pending' );
	if ( array() === $pending ) {
		$pass++;
		echo "PASS  [$class] gated context queues nothing\n";
	} else {
		$fail++;
		echo "FAIL  [$class] gated context queues nothing (pending: " . json_encode( $pending ) . ")\n";
	}
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
