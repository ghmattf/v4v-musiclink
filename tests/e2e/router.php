<?php
/**
 * Router for `php -S` in the launch/open beacon end-to-end test.
 *
 *   POST /beacon   - stands in for admin-ajax.php: appends every request
 *                    (timestamp, content-type, raw body) to captured.log
 *                    and answers like WP would (JSON success), serving
 *                    canned search results for the search action.
 *   anything else  - return false: serve the static file from the
 *                    docroot (gitignored tmp/e2e, where gen_page.php
 *                    writes the captured pages).
 *
 * The log files also land in tmp/e2e/ regardless of where this script
 * lives - only this script is tracked.
 */

// Captured logs stay in gitignored tmp/e2e/.
$log_dir = dirname( __DIR__, 2 ) . '/tmp/e2e';
if ( ! is_dir( $log_dir ) ) {
	mkdir( $log_dir, 0775, true );
}

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( '/report' === $path ) {
	// Instrumentation channel: synchronous XHR records (beacon calls as
	// the plugin made them, uncaught errors, flow completion) - these
	// survive page navigation, unlike in-memory state.
	file_put_contents(
		$log_dir . '/reports.log',
		date( 'H:i:s' ) . ' | ' . file_get_contents( 'php://input' ) . "\n",
		FILE_APPEND
	);
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'ok' => true ) );
	exit;
}

if ( '/beacon' === $path ) {
	$body  = file_get_contents( 'php://input' );
	$ctype = isset( $_SERVER['CONTENT_TYPE'] ) ? $_SERVER['CONTENT_TYPE'] : '';

	file_put_contents(
		$log_dir . '/captured.log',
		date( 'H:i:s' ) . ' | ' . $ctype . ' | ' . $body . "\n",
		FILE_APPEND
	);

	header( 'Content-Type: application/json' );

	$fields = array();
	parse_str( $body, $fields );
	$action = isset( $fields['action'] ) ? $fields['action'] : '';

	if ( 'v4v_musiclink_pro_search' === $action ) {
		// Shape mirrors ajax_search(): guids only, as Music Index returns.
		echo json_encode( array(
			'success' => true,
			'data'    => array(
				'results'  => array( array(
					'title'      => 'Kind of Blue',
					'subtitle'   => 'Miles Davis - So What',
					'album_guid' => 'FEED1',
					'track_guid' => 'TRK1',
				) ),
				'page'      => 1,
				'has_more'  => false,
				'per_page'  => 8,
			),
		) );
	} else {
		echo json_encode( array( 'success' => true, 'data' => null ) );
	}
	exit;
}

return false; // Static file (page.html, search.html) straight from the docroot.
