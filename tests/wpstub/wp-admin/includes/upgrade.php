<?php
/**
 * The dbDelta() WordPress would run from wp-admin/includes/upgrade.php,
 * which the plugin require_once's inside create_log_table(). Stands in
 * for the real one so the migration SQL can be inspected in a test.
 *
 * Path is relative to the ABSPATH the test defines.
 */
function dbDelta( $queries = array() ) {
	$GLOBALS['__dbdelta'][] = $queries;
	return array();
}
