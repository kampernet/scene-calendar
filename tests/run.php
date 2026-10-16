<?php
/**
 * Test runner. Usage (inside the docker wpcli container):
 *
 *   wp eval-file wp-content/plugins/scene-calendar/tests/run.php [name-filter]
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

define( 'SCENECAL_TESTING', true );

require __DIR__ . '/harness.php';

$files = array_merge( glob( __DIR__ . '/unit/*.php' ) ?: array(), glob( __DIR__ . '/parsers/*.php' ) ?: array() );
foreach ( $files as $file ) {
	require $file;
}

global $wpdb;
$filter = $args[0] ?? '';
$passed = 0;
$failed = array();

foreach ( $GLOBALS['scenecal_tests'] as $name => $fn ) {
	if ( '' !== $filter && false === stripos( $name, $filter ) ) {
		continue;
	}
	$GLOBALS['scenecal_http_map'] = array();
	// Each test runs in a transaction that is rolled back, so the dev database is untouched.
	$wpdb->query( 'START TRANSACTION' );
	// Start each test from empty plugin tables (also rolled back).
	foreach ( array( SceneCalendar\Db::events(), SceneCalendar\Db::venues(), SceneCalendar\Db::runs() ) as $scenecal_table ) {
		$wpdb->query( "DELETE FROM $scenecal_table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	SceneCalendar\Venues::flush();
	try {
		$fn();
		++$passed;
		echo "  ok   $name\n";
	} catch ( Throwable $e ) {
		$failed[ $name ] = get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
		echo "  FAIL $name\n       {$failed[$name]}\n";
	} finally {
		$wpdb->query( 'ROLLBACK' );
		remove_all_filters( 'scene_calendar_llm_client' );
		wp_cache_flush();
		SceneCalendar\Venues::flush();
	}
}

echo "\n$passed passed, " . count( $failed ) . " failed\n";
if ( $failed ) {
	exit( 1 );
}
