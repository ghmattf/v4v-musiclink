<?php
/**
 * Which of the two candidates found in the search page's chunk is the live
 * next-action? POST the plugin's exact request with each and look for our
 * track guid (plus its _type, which drives fountain_path_segment()).
 *
 * LIVE NETWORK TEST - hits fountain.fm (no assertion counter; prints what
 * it sees) and can flake or time out; re-run once before concluding.
 * Known stale since 2026-09-26 (rotated next-action tokens 404) - OUTSTANDING O9.
 */
error_reporting( E_ALL & ~E_DEPRECATED );

$TRACK = 'cbdaeaa0-3c58-41ce-92d1-c718710ade54';
$ALBUM = 'd5c805fb-6016-4038-9cda-2e68d2b8dcc4';

function resolve_with( $next_action, $search_text ) {
	$ch = curl_init( 'https://fountain.fm/' );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => json_encode( array( $search_text, array() ) ),
		CURLOPT_HTTPHEADER     => array(
			'Accept: text/x-component',
			'Content-Type: text/plain;charset=UTF-8',
			'next-action: ' . $next_action,
			'next-router-state-tree: %5B%22%22%2C%7B%22children%22%3A%5B%22__PAGE__%22%2C%7B%7D%2Cnull%2Cnull%2C0%5D%7D%2Cnull%2Cnull%2C16%5D',
			'Origin: https://fountain.fm',
			'Referer: https://fountain.fm/',
			'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
		),
	) );
	$r = curl_exec( $ch );
	$c = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	return array( $c, is_string( $r ) ? $r : '' );
}

$candidates = array(
	'605af9c39c662ad04c72e52279d6db26f12973e250',
	'40654cc3d3587b324f983ff18577240adfcc98e15f',
);

foreach ( $candidates as $ca ) {
	list( $code, $resp ) = resolve_with( $ca, 'See Through' );
	$found = false !== strpos( $resp, $TRACK );
	$type  = '';
	if ( $found ) {
		$around = substr( $resp, max( 0, strpos( $resp, $TRACK ) - 300 ), 600 );
		if ( preg_match( '/"_type":"([^"]+)"/', $around, $t ) ) { $type = $t[1]; }
	}
	printf( "candidate %s\n  HTTP %-3d  body %-6d  track guid: %-5s  _type: %s%s\n",
		$ca, $code, strlen( $resp ), $found ? 'FOUND' : 'no', $type ?: '-', "\n" );
	if ( ! $found ) { echo '  first 200: ' . substr( $resp, 0, 200 ) . "\n"; }
}
