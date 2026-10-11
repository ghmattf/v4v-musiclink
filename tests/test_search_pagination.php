<?php
/**
 * Standalone verification for server-side search pagination.
 *
 * Loads the real Pro plugin file against minimal WordPress stubs, then
 * drives the real ajax_search() / mi_search_hits() methods against a
 * scripted Music Index HTTP layer so the batching, de-duplication, ordering
 * and page-boundary behaviour can be asserted exactly.
 *
 * Run:  ./php/php tmp/test_search_pagination.php
 */

error_reporting( E_ALL );

// --- WordPress constants the plugin file reads at class-definition time ---
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ABSPATH', __DIR__ . '/' );

// --- WordPress stubs: only what the search path needs ---
function add_shortcode( ...$a ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_activation_hook( ...$a ) {}
function get_option( $k, $d = false ) {
	if ( 'v4v_musiclink_cache_table_version' === $k || 'v4v_musiclink_pro_cache_table_version' === $k ) { return '1'; }
	if ( 'v4v_musiclink_pro_log_table_version' === $k ) {
		return '2'; // Short-circuit the DB write in the constructor.
	}
	if ( 'v4v_musiclink_pro_social_preview_mode' === $k ) {
		return 'off';
	}
	return $d;
}
function update_option( ...$a ) { return true; }
function delete_option( ...$a ) { return true; }

// Transient store: a real key/value map, so caching behaves like production.
$GLOBALS['__transients'] = array();
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['__transients'][ $k ] = $v;
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['__transients'][ $k ] );
	return true;
}

function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( ...$a ) { return 'nonce'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function check_ajax_referer( ...$a ) { return true; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function current_time( $type = 'mysql' ) { return '2026-10-01 12:00:00'; }
function get_date_from_gmt( $ts, $format = 'Y-m-d H:i:s' ) { return $ts; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }

class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

// wp_send_json_success() terminates the request; model that with an exception.
class Json_Sent extends Exception {
	public $payload;
	public function __construct( $payload ) {
		$this->payload = $payload;
		parent::__construct( 'json-sent' );
	}
}
function wp_send_json_success( $data = null ) { throw new Json_Sent( $data ); }

// --- $wpdb stub: hit detail resolution reads a cache row before it calls
// Music Index. Nothing is stored here, so every read misses and the
// scripted wp_remote_get() above answers with the detail payloads. ---
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
	public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
	public function delete( $t, $w, $f = null )  { return 1; }
}
$GLOBALS['wpdb'] = new Wpdb_Stub();

// --- Scripted Music Index HTTP layer ---
//
// $GLOBALS['__script'] maps a query string to an ordered list of batches.
// A batch is either an array of hit rows, or the special string 'empty'
// meaning "zero rows but has_more=true" (the real trap Music Index emits
// for a no-match query).
//
// $GLOBALS['__detail_fail'] holds feed guids whose detail lookups 404.
$GLOBALS['__script']       = array();
$GLOBALS['__detail_fail']  = array();
$GLOBALS['__http_calls']   = array();

function mkhit( $type, $id, $feed = null ) {
	$h = array(
		'entity_type'    => $type,
		'entity_id'      => $id,
		'rank'           => -1.0,
		'quality_score'  => 1.0,
		'title'          => 'raw title ' . $id,
	);
	if ( 'track' === $type ) {
		$h['feed_guid'] = $feed;
	} else {
		$h['feed_image_url'] = 'img';
	}
	return $h;
}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__http_calls'][] = $url;

	$parts = parse_url( $url );
	$path  = $parts['path'] ?? '';
	parse_str( $parts['query'] ?? '', $qs );

	$ok = function ( $body ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( $body ),
		);
	};
	$err = function () {
		return new WP_Error();
	};

	if ( str_ends_with( $path, '/search' ) ) {
		$q      = $qs['q'] ?? '';
		$cursor = $qs['cursor'] ?? '';
		$batches = $GLOBALS['__script'][ $q ] ?? array( array() );
		$index   = ( '' === $cursor ) ? 0 : (int) substr( $cursor, 1 );
		$rows    = $batches[ $index ] ?? array();
		$next    = ( $index + 1 < count( $batches ) ) ? ( $index + 1 ) : null;

		if ( 'empty' === $rows ) {
			// Zero rows while STILL claiming has_more - the trap.
			return $ok( array(
				'data'       => array(),
				'pagination' => array( 'cursor' => 'c' . ( $index + 1 ), 'has_more' => true ),
				'meta'       => array(),
			) );
		}

		return $ok( array(
			'data'       => $rows,
			'pagination' => array(
				'cursor'   => ( null === $next ) ? '' : 'c' . $next,
				'has_more' => ( null !== $next ),
			),
			'meta'       => array(),
		) );
	}

	// /v1/feeds/{feed}  or  /v1/feeds/{feed}/tracks/{track}
	if ( preg_match( '#/v1/feeds/([^/]+)(?:/tracks/([^/]+))?$#', $path, $m ) ) {
		$feed  = rawurldecode( $m[1] );
		$track = isset( $m[2] ) ? rawurldecode( $m[2] ) : null;

		if ( in_array( $feed, $GLOBALS['__detail_fail'], true ) ) {
			return $err();
		}
		if ( null === $track ) {
			return $ok( array( 'data' => array(
				'title'          => 'Album ' . $feed,
				'release_artist' => 'Artist ' . $feed,
				'image_url'      => '',
			) ) );
		}
		return $ok( array( 'data' => array(
			'title'       => 'Track ' . $track,
			'feed_title'  => 'Album ' . $feed,
			'track_artist'=> 'Artist ' . $feed,
			'image_url'   => '',
		) ) );
	}

	return $err();
}

require_once dirname( __DIR__ ) . '/v4v-musiclink-pro/v4v-musiclink-pro.php';

// --- Harness helpers ---
$pass = 0;
$fail = 0;
function check( $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		$pass++;
		printf( "  PASS  %-66s\n", $label );
	} else {
		$fail++;
		printf( "  FAIL  %-66s\n         expected: %s\n         actual:   %s\n",
			$label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

function search_calls() {
	return count( array_filter(
		$GLOBALS['__http_calls'],
		fn( $u ) => str_ends_with( parse_url( $u, PHP_URL_PATH ), '/search' )
	) );
}
function reset_calls() { $GLOBALS['__http_calls'] = array(); }

/** Runs the real AJAX handler and returns the JSON payload it sent. */
function run_search( $q, $page = 1 ) {
	$_POST = array( 'q' => $q, 'page' => (string) $page );
	$inst  = new V4V_Music_Link_Resolver_Pro();
	try {
		$inst->ajax_search();
	} catch ( Json_Sent $e ) {
		return $e->payload;
	}
	throw new RuntimeException( 'ajax_search() sent no response' );
}

/** Compact "type:id" view of results, for order/count assertions. */
function sig( $results ) {
	return array_map(
		fn( $r ) => ( null === $r['track_guid'] ? 'feed:' : 'track:' )
			. ( $r['track_guid'] ?? $r['album_guid'] ),
		$results
	);
}

// Stable query key for a query the plugin derives with md5().
function cache_key( $q ) {
	$rc = new ReflectionClass( 'V4V_Music_Link_Resolver_Pro' );
	return 'mi_search_hits_v' . $rc->getConstant( 'SEARCH_CACHE_SCHEMA' ) . '_' . md5( $q );
}

echo str_repeat( '=', 78 ) . "\n";

// ---------------------------------------------------------------------------
echo "\n-- Scenario: 3 batches, duplicates within and across batches --\n";
// ---------------------------------------------------------------------------
// batch0: 5 rows, all unique
// batch1: 5 rows, first is a repeat of batch0's track T1
// batch2: 5 rows, first is a repeat of batch0's feed F1
// => 15 raw rows, 13 unique, in relevance order.
$GLOBALS['__script']['paged'] = array(
	array(
		mkhit( 'feed', 'F1' ),
		mkhit( 'track', 'T1', 'F1' ),
		mkhit( 'track', 'T2', 'F1' ),
		mkhit( 'feed', 'F2' ),
		mkhit( 'track', 'T3', 'F2' ),
	),
	array(
		mkhit( 'track', 'T1', 'F1' ), // duplicate of batch0
		mkhit( 'feed', 'F3' ),
		mkhit( 'track', 'T4', 'F3' ),
		mkhit( 'track', 'T5', 'F3' ),
		mkhit( 'feed', 'F4' ),
	),
	array(
		mkhit( 'feed', 'F1' ),        // duplicate of batch0
		mkhit( 'track', 'T6', 'F1' ),
		mkhit( 'feed', 'F5' ),
		mkhit( 'track', 'T7', 'F5' ),
		mkhit( 'feed', 'F6' ),
	),
);

$expected_order = array(
	'feed:F1', 'track:T1', 'track:T2', 'feed:F2', 'track:T3',
	'feed:F3', 'track:T4', 'track:T5',
	'feed:F4', 'track:T6', 'feed:F5', 'track:T7', 'feed:F6',
);

reset_calls();
$p1 = run_search( 'paged', 1 );
check( 'page 1: full page of 8 results', count( $p1['results'] ), 8 );
check( 'page 1: reports page number', $p1['page'], 1 );
check( 'page 1: reports per_page', $p1['per_page'], 8 );
check( 'page 1: has_more true (more hits held)', $p1['has_more'], true );
check( 'page 1: results are the first 8 in relevance order',
	sig( $p1['results'] ), array_slice( $expected_order, 0, 8 ) );
// needed 8 > 5 unique in batch0, so exactly one chained batch.
check( 'page 1: chained exactly 2 search batches', search_calls(), 2 );

$p2 = run_search( 'paged', 2 );
check( 'page 2: remaining 5 results', count( $p2['results'] ), 5 );
check( 'page 2: results continue relevance order (no re-sort)',
	sig( $p2['results'] ), array_slice( $expected_order, 8 ) );
check( 'page 2: has_more false (cursor exhausted)', $p2['has_more'], false );
// Page 2 needs 16 hits, so it fetches the final batch - and stops there.
check( 'page 2: fetches the last batch and no more (3 of 3)', search_calls(), 3 );

$p3 = run_search( 'paged', 3 );
check( 'page 3 past the end: empty results', count( $p3['results'] ), 0 );
check( 'page 3 past the end: has_more false', $p3['has_more'], false );
check( 'page 3 past the end: still no extra search calls', search_calls(), 3 );

$seen = array_merge( sig( $p1['results'] ), sig( $p2['results'] ) );
check( 'union of pages has no duplicate result', count( $seen ), count( array_unique( $seen ) ) );
check( 'union of pages covers every unique hit', $seen, $expected_order );

// Backwards navigation must be free: cached hits, cached details.
$before = search_calls();
$p1b    = run_search( 'paged', 1 );
check( 're-requesting page 1 issues no search batch', search_calls(), $before );
check( 're-requesting page 1 returns identical results', sig( $p1b['results'] ), sig( $p1['results'] ) );

// Page boundaries must not shift after deeper requests.
check( 'page 2 unchanged after visiting page 3', sig( $p2['results'] ), array_slice( $expected_order, 8 ) );

// ---------------------------------------------------------------------------
echo "\n-- Scenario: no-match query returns 0 rows but has_more=true --\n";
// ---------------------------------------------------------------------------
$GLOBALS['__script']['nomatch'] = array( 'empty' );
reset_calls();
$nm = run_search( 'nomatch', 1 );
check( 'no results', count( $nm['results'] ), 0 );
check( 'has_more forced false despite upstream claim', $nm['has_more'], false );
check( 'only one search call - the chain is broken, not looped', search_calls(), 1 );
check( 'repeat request does not re-hit upstream', ( function () {
	run_search( 'nomatch', 1 );
	return search_calls();
} )(), 1 );

// ---------------------------------------------------------------------------
echo "\n-- Scenario: SEARCH_MAX_BATCHES caps one request --\n";
// ---------------------------------------------------------------------------
// 10 batches of 5 unique rows each, all claiming has_more.
$batches = array();
for ( $b = 0; $b < 10; $b++ ) {
	$rows = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$rows[] = mkhit( 'feed', sprintf( 'BIG%02d_%d', $b, $i ) );
	}
	$batches[] = $rows;
}
$GLOBALS['__script']['big'] = $batches;

$rc        = new ReflectionClass( 'V4V_Music_Link_Resolver_Pro' );
$max_batch = $rc->getConstant( 'SEARCH_MAX_BATCHES' );
$per_page  = $rc->getConstant( 'SEARCH_PER_PAGE' );
check( 'SEARCH_PER_PAGE is still 8', $per_page, 8 );
check( 'SEARCH_MAX_BATCHES is a small positive number', is_int( $max_batch ) && $max_batch > 0, true );

reset_calls();
$deep = run_search( 'big', 100 ); // needs 800 hits - far beyond the cap
check( 'deep page request capped at SEARCH_MAX_BATCHES calls', search_calls(), $max_batch );
check( 'deep page beyond held hits returns empty', count( $deep['results'] ), 0 );
check( 'deep page past the end reports has_more false', $deep['has_more'], false );

// Paging forward from the cap must still advance (append-only cache).
$before = search_calls();
$p2b    = run_search( 'big', 2 );
check( 'page 2 after a capped request chains one more batch',
	search_calls(), $before + 1 );
check( 'page 2 delivers a full page', count( $p2b['results'] ), 8 );
check( 'page 2 has_more true (holds 20 of many)', $p2b['has_more'], true );

// Page 1 must be intact despite the deep requests happening first.
$p1c = run_search( 'big', 1 );
check( 'page 1 delivers first 8 hits of batch 0',
	sig( $p1c['results'] ),
	array( 'feed:BIG00_0', 'feed:BIG00_1', 'feed:BIG00_2', 'feed:BIG00_3', 'feed:BIG00_4',
	       'feed:BIG01_0', 'feed:BIG01_1', 'feed:BIG01_2' ) );

// ---------------------------------------------------------------------------
echo "\n-- Scenario: a failing detail lookup drops its row, not the page --\n";
// ---------------------------------------------------------------------------
$GLOBALS['__script']['risky'] = array( array(
	mkhit( 'feed', 'OK1' ),
	mkhit( 'feed', 'BOOM' ),   // detail lookup 404s
	mkhit( 'feed', 'OK2' ),
) );
$GLOBALS['__detail_fail'] = array( 'BOOM' );
reset_calls();
$risky = run_search( 'risky', 1 );
check( 'unresolvable hit is skipped', sig( $risky['results'] ), array( 'feed:OK1', 'feed:OK2' ) );
check( 'page still reports no further page', $risky['has_more'], false );

// ---------------------------------------------------------------------------
echo "\n-- Scenario: empty query never calls upstream --\n";
// ---------------------------------------------------------------------------
reset_calls();
$blank = run_search( '', 1 );
check( 'empty payload shape', $blank, array(
	'results' => array(), 'page' => 1, 'per_page' => 8, 'has_more' => false,
) );
check( 'zero HTTP calls', search_calls(), 0 );

// ---------------------------------------------------------------------------
echo "\n-- search_hit_key: identity + degenerate hits --\n";
// ---------------------------------------------------------------------------
$inst = new V4V_Music_Link_Resolver_Pro();
$key  = new ReflectionMethod( 'V4V_Music_Link_Resolver_Pro', 'search_hit_key' );
$kk   = fn( $hit ) => $key->invoke( $inst, $hit );

check( 'feed hit keys on entity_id (no feed_guid exists)',
	$kk( mkhit( 'feed', 'F1' ) ), 'feed|F1|F1' );
check( 'track hit keys on its parent album guid',
	$kk( mkhit( 'track', 'T1', 'F1' ) ), 'track|T1|F1' );
check( 'track without feed_guid is unresolvable => empty key',
	$kk( array( 'entity_type' => 'track', 'entity_id' => 'T1' ) ), '' );
check( 'missing entity_type => empty key',
	$kk( array( 'entity_id' => 'F1' ) ), '' );
check( 'missing entity_id => empty key',
	$kk( array( 'entity_type' => 'feed' ) ), '' );
check( 'same track under a different album is a different result',
	$kk( mkhit( 'track', 'T1', 'F9' ) ), 'track|T1|F9' );

// ---------------------------------------------------------------------------
echo "\n-- Stale cache containing duplicates is filtered on the way out --\n";
// ---------------------------------------------------------------------------
// Simulates a transient written by an older schema: the same hit twice.
$stale = array(
	'hits'     => array( mkhit( 'feed', 'S1' ), mkhit( 'feed', 'S1' ) ),
	'seen'     => array(),
	'cursor'   => '',
	'has_more' => false,
);
$GLOBALS['__transients'][ cache_key( 'stale' ) ] = $stale;
reset_calls();
$stale_res = run_search( 'stale', 1 );
check( 'duplicate rows in the cache yield one result', count( $stale_res['results'] ), 1 );
check( '...and it is the expected one', sig( $stale_res['results'] ), array( 'feed:S1' ) );

// ---------------------------------------------------------------------------
echo "\n-- Relevance order is preserved verbatim (no re-sorting) --\n";
// ---------------------------------------------------------------------------
// Craft ranks that are deliberately NOT sorted, since relevance order must
// win over any incidental ordering of the ids/ranks.
$GLOBALS['__script']['rel'] = array( array(
	mkhit( 'feed', 'ZZZ' ),
	mkhit( 'feed', 'AAA' ),
	mkhit( 'feed', 'MMM' ),
) );
reset_calls();
$rel = run_search( 'rel', 1 );
check( 'returned in Music Index order, not alphabetical/rank order',
	sig( $rel['results'] ), array( 'feed:ZZZ', 'feed:AAA', 'feed:MMM' ) );

// ---------------------------------------------------------------------------
echo str_repeat( '=', 78 ) . "\n";
printf( "RESULT: %d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
