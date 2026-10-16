<?php
/**
 * GoDaddy calendar widget parser tests (hand-written fixture).
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Parsers\GoDaddyCalendar;

function scenecal_godaddy_venue( array $overrides = array() ): array {
	return scenecal_test_venue(
		array_merge(
			array(
				'name'                 => 'Public Domain',
				'url'                  => 'https://publicdomain204.ca/',
				'godaddy_calendar_url' => 'https://calendar.apps.secureserver.net/v1/events/abc/def/ghi',
			),
			$overrides
		)
	);
}

scenecal_test(
	'godaddy_calendar: maps fields and converts to site time',
	static function () {
		scenecal_mock_http( array( 'calendar.apps.secureserver.net' => scenecal_fixture( 'godaddy_calendar-events.json' ) ) );
		$events = ( new GoDaddyCalendar() )->parse( scenecal_godaddy_venue(), scenecal_test_context() );

		scenecal_assert_same( array( 'Open Decks', 'Late Set' ), array_column( $events, 'title' ), 'untitled and past events dropped' );
		$e = $events[0];
		scenecal_assert_same( 'Public Domain', $e['venue_name'] );
		scenecal_assert_same( '2026-11-07', $e['date'] );
		scenecal_assert_same( '21:00', $e['start_time'] );
		scenecal_assert_same( '01:00', $e['end_time'] );
		scenecal_assert_same( 'Bring your records', $e['description'] );
		scenecal_assert_same( 'https://publicdomain204.ca/', $e['ticket_link'] );
		scenecal_assert_same( array( 'https://publicdomain204.ca/' ), $e['source_urls'] );

		// UTC timestamp: 02:00Z on Nov 10 is 20:00 on Nov 9 in Winnipeg.
		scenecal_assert_same( '2026-11-09', $events[1]['date'] );
		scenecal_assert_same( '20:00', $events[1]['start_time'] );
		scenecal_assert_same( '', $events[1]['end_time'] );
	}
);

scenecal_test(
	'godaddy_calendar: filters to the date range',
	static function () {
		scenecal_mock_http( array( 'calendar.apps.secureserver.net' => scenecal_fixture( 'godaddy_calendar-events.json' ) ) );
		$events = ( new GoDaddyCalendar() )->parse( scenecal_godaddy_venue(), scenecal_test_context( '2026-08-01', '2026-11-08' ) );
		scenecal_assert_same( array( 'Open Decks', 'Summer Patio Party' ), array_column( $events, 'title' ) );
	}
);

scenecal_test(
	'godaddy_calendar: missing calendar URL returns nothing',
	static function () {
		scenecal_mock_http( array() );
		$events = ( new GoDaddyCalendar() )->parse( scenecal_godaddy_venue( array( 'godaddy_calendar_url' => '' ) ), scenecal_test_context() );
		scenecal_assert_same( array(), $events );
		scenecal_assert( ! GoDaddyCalendar::matches_url( 'https://publicdomain204.ca/' ), 'no auto-detect' );
	}
);
