<?php

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;
use SceneCalendar\Parsers\Context;
use SceneCalendar\Parsers\ICal;

// Times are asserted in America/Chicago (stable CST/CDT rules); see test-squarespace.php.

scenecal_test(
	'ical: all-day, UTC (CDT and CST), TZID-local, folded and escaped fields',
	static function () {
		$requested = array();
		scenecal_mock_http(
			array(
				'calendar.google.com' => static function ( $url ) use ( &$requested ) {
					$requested[] = $url;
					return scenecal_fixture( 'ical-toad.ics' );
				},
			)
		);
		$venue  = scenecal_test_venue(
			array(
				'name'     => 'The Toad in the Hole Pub',
				'url'      => 'https://www.thetoadpubwinnipeg.com/live-music-calendar',
				'ical_url' => 'https://calendar.google.com/calendar/ical/abc%40group.calendar.google.com/public/basic.ics',
			)
		);
		$events = ( new ICal() )->parse( $venue, new Context( '2026-10-01', '2027-12-31', new DateTimeZone( 'America/Chicago' ) ) );

		scenecal_assert_same( 1, count( $requested ), 'fetches the configured ical_url' );
		scenecal_assert_same(
			array( 'Rockbuster', 'The Barnyards, Live', 'Winter Blues Jam', 'Halloween Bash', 'TOAD TIME MACHINE' ),
			array_column( $events, 'title' ),
			'past event filtered, SUMMARY unescaped and trimmed'
		);
		$by_title = array_column( $events, null, 'title' );

		$all_day = $by_title['Rockbuster'];
		scenecal_assert_same( '2026-11-20', $all_day['date'] );
		scenecal_assert_same( '', $all_day['start_time'] );
		scenecal_assert_same( 'https://www.thetoadpubwinnipeg.com/live-music-calendar', $all_day['ticket_link'], 'venue url fallback' );

		$cdt = $by_title['The Barnyards, Live'];
		scenecal_assert_same( '2026-10-23', $cdt['date'], '02:00Z in October = 21:00 CDT previous day' );
		scenecal_assert_same( '21:00', $cdt['start_time'] );
		scenecal_assert_same( '00:00', $cdt['end_time'] );
		scenecal_assert_same( 'Doors at 8, show at 9; bring friends Second line', $cdt['description'] );
		scenecal_assert_same( 'https://tickets.example.com/barnyards', $cdt['ticket_link'] );
		scenecal_assert_same( array( 'https://tickets.example.com/barnyards' ), $cdt['source_urls'] );

		$cst = $by_title['Winter Blues Jam'];
		scenecal_assert_same( '2027-01-15', $cst['date'], '03:00Z in January = 21:00 CST previous day' );
		scenecal_assert_same( '21:00', $cst['start_time'] );
		scenecal_assert( str_contains( $cst['description'], 'multiple lines because iCal' ), 'folded line unfolded' );
		scenecal_assert( str_ends_with( $cst['description'], 'folds them with a leading space.' ), 'folded line unfolded (2)' );

		$local = $by_title['Halloween Bash'];
		scenecal_assert_same( '2026-10-31', $local['date'] );
		scenecal_assert_same( '21:00', $local['start_time'] );
		scenecal_assert_same( '01:00', $local['end_time'] );

		scenecal_assert_same( '2026-11-06', $by_title['TOAD TIME MACHINE']['date'], 'RRULE not expanded: first occurrence only' );
	}
);

scenecal_test(
	'ical: venue url used when ical_url is empty',
	static function () {
		scenecal_mock_http( array( 'example.com/cal.ics' => scenecal_fixture( 'ical-toad.ics' ) ) );
		$events = ( new ICal() )->parse( scenecal_test_venue( array( 'url' => 'https://example.com/cal.ics' ) ), scenecal_test_context( '2026-11-20', '2026-11-20' ) );
		scenecal_assert_same( array( 'Rockbuster' ), array_column( $events, 'title' ) );
	}
);

scenecal_test(
	'ical: TZID in another zone converts to the site timezone',
	static function () {
		$tz = new DateTimeZone( 'America/Chicago' );
		$dt = ICal::parse_dt(
			array(
				'params' => array( 'TZID' => 'America/Toronto' ),
				'value'  => '20261120T210000',
			),
			$tz
		);
		scenecal_assert_same( array( '2026-11-20', '20:00' ), $dt );
		$unknown = ICal::parse_dt(
			array(
				'params' => array( 'TZID' => 'Central Standard Time' ),
				'value'  => '20261120T210000',
			),
			$tz
		);
		scenecal_assert_same( array( '2026-11-20', '21:00' ), $unknown, 'unknown TZID = local wall time' );
	}
);

scenecal_test(
	'ical: matches_url and unescape',
	static function () {
		scenecal_assert( ICal::matches_url( 'https://example.com/events.ics' ) );
		scenecal_assert( ICal::matches_url( 'https://calendar.google.com/calendar/ical/x%40group.calendar.google.com/public/basic.ics' ) );
		scenecal_assert( ! ICal::matches_url( 'https://calendar.google.com/calendar/embed?src=x' ) );
		scenecal_assert_same( "a, b; c\nd \\ e", ICal::unescape( 'a\\, b\\; c\\nd \\\\ e' ) );
	}
);
