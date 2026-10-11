<?php
/**
 * Generates tmp/e2e/page.html (release page) and tmp/e2e/search.html
 * (bare search page) from the REAL plugin render() output and the REAL
 * inline_js(), so a browser end-to-end test can click the actual
 * buttons wired by the shipped code. The captured HTML is scratch and
 * stays in gitignored tmp/e2e/ - only this script is tracked.
 *
 * Run:  ./php/php tests/e2e/gen_page.php
 */

error_reporting( E_ALL );

define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Captured output lives in tmp/e2e/ regardless of where this script runs from.
$e2e_out = dirname( __DIR__, 2 ) . '/tmp/e2e';
if ( ! is_dir( $e2e_out ) ) {
	mkdir( $e2e_out, 0775, true );
}

function add_shortcode( ...$a ) {}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_activation_hook( ...$a ) {}
function get_file_data( ...$a ) { return array( 'Version' => '1.20.19' ); }

$GLOBALS['__options'] = array(
	'v4v_musiclink_pro_log_table_version'   => '2',
	// Both cache schemas current, so the constructor never reaches
	// migrate/dbDelta/upgrade.php (there is no $wpdb here to run them on).
	'v4v_musiclink_cache_table_version'     => '1',
	'v4v_musiclink_pro_cache_table_version' => '1',
	'v4v_musiclink_pro_social_preview_mode' => 'off',
	// All four apps enabled - the point is to exercise every button's
	// click wiring, including Fountain's modal path (its next-action is
	// empty and every Fountain HTTP stub fails, so it renders the
	// Fountain search fallback, which is what opens the modal in the
	// JS).
	'v4v_musiclink_pro_apps' => array(
		'stablekraft' => array( 'enabled' => true, 'icon_url' => '' ),
		'podtunes'    => array( 'enabled' => true, 'icon_url' => '' ),
		'v4vmusic'    => array( 'enabled' => true, 'icon_url' => '' ),
		'fountain'    => array( 'enabled' => true, 'icon_url' => '' ),
	),
);
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( ...$a ) { return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }

$GLOBALS['__transients'] = array();
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function home_url( $p = '' ) { return 'http://127.0.0.1:8899' . $p; }
function admin_url( $p = '' ) { return 'http://127.0.0.1:8899/wp-admin/' . $p; }
function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function current_time( $type = 'mysql' ) { return '2026-09-24 12:00:00'; }
function get_date_from_gmt( $ts, $format = 'Y-m-d H:i:s' ) { return $ts; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function add_query_arg( ...$args ) {
	if ( 2 === count( $args ) && is_array( $args[0] ) ) { $qs = $args[0]; $url = $args[1]; }
	elseif ( 3 === count( $args ) ) { $qs = array( $args[0] => $args[1] ); $url = $args[2]; }
	else { $qs = null; $url = null; }
	$base  = null === $url ? ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) : $url;
	$parts = explode( '?', $base, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) { parse_str( $parts[1], $query ); }
	if ( null !== $qs ) {
		foreach ( $qs as $k => $v ) {
			if ( null === $v ) { unset( $query[ $k ] ); } else { $query[ $k ] = $v; }
		}
	}
	return $parts[0] . ( $query ? '?' . http_build_query( $query ) : '' );
}
function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	$sep = false !== strpos( $url, '?' ) ? '&' : '?';
	return $url . $sep . rawurlencode( $name ) . '=' . wp_create_nonce( $action );
}

function check_admin_referer( ...$a ) { return true; }
function check_ajax_referer( ...$a ) { return true; }
function current_user_can( ...$a ) { return true; }
function wp_die( $message = '' ) { throw new Exception( 'wp_die' ); }
function wp_safe_redirect( $url ) { throw new Exception( 'redirect ' . $url ); }

class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

// Music Index: the one release under test resolves.
$GLOBALS['__feeds'] = array(
	'FEED1' => array( 'album' => 'Kind of Blue', 'artist' => 'Miles Davis', 'tracks' => array( 'TRK1' => 'So What' ) ),
	// The exact guids from the production reproduction shortcode
	// [musiclink_pro album="d5c805fb-..." track="cbdaeaa0-..." header="true"].
	'd5c805fb-6016-4038-9cda-2e68d2b8dcc4' => array(
		'album'  => 'Kind of Blue',
		'artist' => 'Miles Davis',
		'tracks' => array( 'cbdaeaa0-3c58-41ce-92d1-c718710ade54' => 'So What' ),
	),
);
function wp_remote_get( $url, $args = array() ) {
	$parts = parse_url( $url );
	$path  = $parts['path'] ?? '';
	$ok    = function ( $body ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
	};
	if ( preg_match( '#/v1/feeds/([^/]+)(?:/tracks/([^/]+))?$#', $path, $m ) ) {
		$feed  = rawurldecode( $m[1] );
		$track = isset( $m[2] ) ? rawurldecode( $m[2] ) : null;
		if ( ! isset( $GLOBALS['__feeds'][ $feed ] ) ) { return new WP_Error(); }
		$meta = $GLOBALS['__feeds'][ $feed ];
		if ( null === $track ) {
			return $ok( array( 'data' => array( 'title' => $meta['album'], 'release_artist' => $meta['artist'], 'image_url' => '' ) ) );
		}
		if ( ! isset( $meta['tracks'][ $track ] ) ) { return new WP_Error(); }
		return $ok( array( 'data' => array(
			'title' => $meta['tracks'][ $track ], 'feed_title' => $meta['album'],
			'track_artist' => $meta['artist'], 'image_url' => '',
		) ) );
	}
	return new WP_Error();
}
// Fountain: every call fails, so the Fountain search fallback for the
// release is rendered.
function wp_remote_post( $url, $args = array() ) { return new WP_Error(); }

// No WordPress database in this fixture. The constructor is already kept
// off its table guard by the version seeds above, but render() also *reads*
// a cache row - and Fountain resolution fails by construction below
// (wp_remote_post always errors), so no link is ever written either. No row
// returned keeps the shortcode on the search path, which is exactly the
// behaviour this page exists to exercise. Music Index titles still resolve:
// with no row, the plugin falls through to its blocking fill, whose
// wp_remote_get stub above answers.
class Gen_Page_Wpdb {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) { return null; }
	public function get_row( $query ) { return null; }
	public function get_col( $query ) { return array(); }
	public function get_results( $query ) { return array(); }
	public function query( $query ) { return false; }
	public function insert( $table, $data, $format = null ) { return false; }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return false; }
	public function delete( $table, $where, $where_format = null ) { return false; }
	public function esc_like( $text ) { return $text; }
}
$GLOBALS['wpdb'] = new Gen_Page_Wpdb();

require_once dirname( __DIR__, 2 ) . '/v4v-musiclink-pro/v4v-musiclink-pro.php';

$_SERVER['REQUEST_URI'] = '/musiclink';
$plugin = new V4V_Music_Link_Resolver_Pro();

// The shipped inline JS, straight from the plugin's private method.
$ref   = new ReflectionMethod( 'V4V_Music_Link_Resolver_Pro', 'inline_js' );
$js    = $ref->invoke( $plugin );

// Exactly the production script stack order: localized data first, then
// the inline code (wp_localize_script = "before", add_inline_script = "after").
$localize = <<<'LOC'
<script>
window.v4vMusicLinkPro = {
	ajaxUrl: "/beacon",
	nonce: "search-nonce-1",
	logNonce: "log-nonce-1",
	i18n: {
		searching: "Searching\u2026",
		noResults: "No results found.",
		searchError: "Search failed - please try again.",
		copied: "Link copied!",
		prevPage: "\u2039 Previous",
		nextPage: "Next \u203a",
		pageLabel: "Page %1$d",
		rangeLabel: "%1$d\u2013%2$d"
	}
};
</script>
LOC;

// Test instrumentation, appended AFTER the plugin's script so its
// DOMContentLoaded listener registers second (fires after the plugin's
// handler has attached its listeners). Records every sendBeacon call
// synchronously to /report (survives navigation), traps uncaught errors,
// blocks external navigation, then drives the UI like a user would.
$instrument = <<<'INST'
<script>
(function () {
	var ctx = location.pathname + location.search;
	var errs = [];
	window.addEventListener("error", function (e) { errs.push(String(e.message || e)); });
	var origBeacon = navigator.sendBeacon ? navigator.sendBeacon.bind(navigator) : null;
	navigator.sendBeacon = function (url, data) {
		var body = (data === undefined || data === null) ? "" : String(data);
		// Forward the ORIGINAL body so the wire Content-Type matches
		// production exactly (URLSearchParams -> form-urlencoded).
		var queued = origBeacon ? origBeacon(url, data) : false;
		try {
			var x = new XMLHttpRequest();
			x.open("POST", "/report", false);
			x.setRequestHeader("Content-Type", "application/json");
			x.send(JSON.stringify({ kind: "beacon", ctx: ctx, url: String(url), body: body, queued: queued }));
		} catch (e) {}
		return queued;
	};
	window.open = function () { return null; };
	// Capture-phase: block target=_blank navigation/tab-opens without
	// stopping the plugin's bubble listeners on the same anchors.
	document.addEventListener("click", function (e) {
		var a = e.target && e.target.closest ? e.target.closest("a") : null;
		if (a && a.getAttribute("target") === "_blank") { e.preventDefault(); }
	}, true);
	function sendReport(extra) {
		try {
			var x = new XMLHttpRequest();
			x.open("POST", "/report", false);
			x.setRequestHeader("Content-Type", "application/json");
			x.send(JSON.stringify(Object.assign({ kind: "final", ctx: ctx, errs: errs }, extra || {})));
		} catch (e) {}
	}
	document.addEventListener("DOMContentLoaded", function () {
		setTimeout(function () {
			try {
				// Landed back here after a search-result click navigated.
				if (location.search.indexOf("album=") !== -1) { sendReport({ landed: true }); return; }
				var root = document.querySelector(".v4v-musiclink-pro-search");
				if (root) {
					var input = root.querySelector(".v4v-musiclink-pro-search-input");
					var button = root.querySelector(".v4v-musiclink-pro-search-btn");
					input.value = "kind of blue";
					button.click();
					setTimeout(function () {
						var res = document.querySelector(".v4v-musiclink-pro-result button");
						if (res) { res.click(); } else { sendReport({ noResult: true }); }
					}, 600);
					return;
				}
				var items = document.querySelectorAll(".v4v-musiclink-pro-item[data-app]");
				var clicked = [];
				Array.prototype.forEach.call(items, function (li) {
					var slug = li.getAttribute("data-app");
					if (slug === "fountain") { return; }
					var a = li.querySelector("a");
					if (a) { a.click(); clicked.push(slug); }
				});
				var report = { clicked: clicked, modalOpen: null, okClicked: false };
				var f = document.querySelector(".v4v-musiclink-pro-fountain a");
				var modal = document.getElementById("v4v-musiclink-pro-fountain-modal");
				if (f && modal) {
					f.click();
					report.modalOpen = modal.classList.contains("is-open");
					var ok = modal.querySelector(".v4v-musiclink-pro-modal-ok");
					if (ok && report.modalOpen) { ok.click(); report.okClicked = true; }
				}
				sendReport(report);
			} catch (e) { sendReport({ flowError: e.message }); }
		}, 150);
	});
})();
</script>
INST;

function wrap( $body_html, $js, $localize, $instrument ) {
	return "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>V4V e2e</title></head>\n<body>\n"
		. $body_html . "\n"
		. $localize . "\n<script>\n" . $js . "\n</script>\n"
		. $instrument
		. "</body>\n</html>\n";
}

// Release page: the user's EXACT production shortcode, rendered the way
// WordPress renders it - hardcoded attributes, NO query string.
$_GET    = array();
$release = $plugin->render( array(
	'album'  => 'd5c805fb-6016-4038-9cda-2e68d2b8dcc4',
	'track'  => 'cbdaeaa0-3c58-41ce-92d1-c718710ade54',
	'header' => 'true',
) );

// Bare search page: no guids, search UI (root + pager) rendered.
$_GET  = array();
$search = $plugin->render( array() );

file_put_contents( $e2e_out . '/page.html', wrap( $release, $js, $localize, $instrument ) );
file_put_contents( $e2e_out . '/search.html', wrap( $search, $js, $localize, $instrument ) );

// Structural self-checks against the real markup.
$checks = array(
	'4 app items with data-app'        => ( 4 === preg_match_all( '/class="v4v-musiclink-pro-item [^"]*" data-app="/', $release ) ),
	'release page has NO search root'   => ( false === strpos( $release, 'v4v-musiclink-pro-search-input' ) ),
	'container carries data-album'     => ( false !== strpos( $release, 'data-album="d5c805fb-6016-4038-9cda-2e68d2b8dcc4"' ) ),
	'container carries data-track'     => ( false !== strpos( $release, 'data-track="cbdaeaa0-3c58-41ce-92d1-c718710ade54"' ) ),
	'fountain item via slug class'     => ( false !== strpos( $release, 'v4v-musiclink-pro-fountain' ) ),
	'fountain fallback href search'     => ( false !== strpos( $release, 'href="https://fountain.fm/search?q=%22So+What%22"' ) ),
	'no discover fallback link'         => ( false === strpos( $release, 'href="https://fountain.fm/discover"' ) ),
	'modal markup present'              => ( false !== strpos( $release, 'id="v4v-musiclink-pro-fountain-modal"' ) ),
	'modal matches search fallback'     => ( false !== strpos( $js, 'href.indexOf("https://fountain.fm/search?")' ) ),
	'search root present'              => ( false !== strpos( $search, 'v4v-musiclink-pro-search"' ) ),
	'search pager prev present'        => ( false !== strpos( $search, 'v4v-musiclink-pro-pager-prev' ) ),
	'search pager next present'        => ( false !== strpos( $search, 'v4v-musiclink-pro-pager-next' ) ),
	'inline js is non-empty'           => ( strlen( $js ) > 1000 ),
	'no script-break in inline js'     => ( false === strpos( $js, '</script>' ) ),
);
$fail = 0;
foreach ( $checks as $label => $ok ) {
	printf( "  %s  %s\n", $ok ? 'OK  ' : 'FAIL', $label );
	if ( ! $ok ) { $fail++; }
}
	printf( "wrote page.html (%d bytes), search.html (%d bytes), inline js %d bytes\n",
	strlen( file_get_contents( $e2e_out . '/page.html' ) ),
	strlen( file_get_contents( $e2e_out . '/search.html' ) ),
	strlen( $js ) );
exit( $fail ? 1 : 0 );
