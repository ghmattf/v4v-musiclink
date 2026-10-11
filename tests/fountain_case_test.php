<?php
/**
 * Final pre-code checks against POST /search:
 *  1. ORIGINAL-CASE query (what the plugin actually sends: track title as-is)
 *     vs lowercased (what the browser client sends) - target guid found?
 *  2. Album path: album-title query matching album guid -> _type/_id/URL.
 *  3. Bogus id POSTed to /search still 404s (stale-hash detection intact).
 *
 * LIVE NETWORK TEST - hits fountain.fm on every run and can flake or
 * time out; re-run once before concluding. Known stale since 2026-09-26
 * (hardcoded next-action tokens 404) - OUTSTANDING O9.
 */
error_reporting( E_ALL & ~E_DEPRECATED );

$TRACK = 'cbdaeaa0-3c58-41ce-92d1-c718710ade54';
$ALBUM = 'd5c805fb-6016-4038-9cda-2e68d2b8dcc4';
$SID   = '605af9c39c662ad04c72e52279d6db26f12973e250';
$STATE = '%5B%22%22%2C%7B%22children%22%3A%5B%22__PAGE__%22%2C%7B%7D%2Cnull%2Cnull%2C0%5D%7D%2Cnull%2Cnull%2C16%5D';

function search( $query, $target_guid ) {
	global $SID, $STATE;
	$ch = curl_init( 'https://fountain.fm/search' );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => json_encode( array( $query, array() ) ),
		CURLOPT_HTTPHEADER     => array(
			'Accept: text/x-component',
			'Content-Type: text/plain;charset=UTF-8',
			'next-action: ' . $SID,
			'next-router-state-tree: ' . $STATE,
			'Origin: https://fountain.fm',
			'Referer: https://fountain.fm/',
			'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
		),
	) );
	$resp = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

	// plugin's exact extract_fountain_hit()
	$hit = null;
	if ( preg_match( '/\d+:(\{"hits":.*)/s', (string) $resp, $m ) ) {
		$decoded = json_decode( trim( $m[1] ), true );
		if ( ! empty( $decoded['hits'] ) && is_array( $decoded['hits'] ) ) {
			foreach ( $decoded['hits'] as $h ) {
				if ( isset( $h['_guid'], $h['_id'] ) && $h['_guid'] === $target_guid ) {
					$hit = array( 'id' => $h['_id'], 'type' => isset( $h['_type'] ) ? (string) $h['_type'] : '' );
					break;
				}
			}
		}
	}
	return array( $code, strlen( (string) $resp ), $hit );
}

$by_type = array( 'TRACK' => 'track', 'ALBUM' => 'album', 'EPISODE' => 'episode', 'SHOW' => 'show' );

$cases = array(
	'track original-case "See Through" -> track guid' => array( 'See Through', $TRACK ),
	'track lowercase   "see through" -> track guid'   => array( 'see through', $TRACK ),
	'album original-case (album title?) -> album guid' => array( 'The Singles', $ALBUM ),
	'album lowercase                    -> album guid' => array( 'the singles', $ALBUM ),
);
foreach ( $cases as $label => $args ) {
	list( $q, $g ) = $args;
	list( $code, $len, $hit ) = search( $q, $g );
	printf( "%-56s HTTP %d len %-6d hit: %s\n", $label, $code, $len,
		$hit ? json_encode( $hit ) : 'NO MATCH' );
	if ( $hit ) {
		$type = strtoupper( $hit['type'] );
		$seg  = isset( $by_type[ $type ] ) ? $by_type[ $type ] : 'track';
		echo '    url: https://fountain.fm/' . $seg . '/' . rawurlencode( $hit['id'] ) . "\n";
	}
}

// stale-hash detection: bogus id against /search
$ch = curl_init( 'https://fountain.fm/search' );
curl_setopt_array( $ch, array(
	CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true,
	CURLOPT_POSTFIELDS => json_encode( array( 'x', array() ) ),
	CURLOPT_HTTPHEADER => array(
		'Content-Type: text/plain;charset=UTF-8',
		'next-action: ' . str_repeat( '0', 40 ),
		'next-router-state-tree: ' . $STATE,
		'Origin: https://fountain.fm',
	),
) );
$r = curl_exec( $ch );
$c = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
echo "\nbogus id POST /search: HTTP $c body '" . trim( $r ) . "'\n";
