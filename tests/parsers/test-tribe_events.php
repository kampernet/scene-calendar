<?php
/**
 * The Events Calendar (Tribe) parser tests. tribe_events-page1.json is a trimmed
 * live response from pyramidcabaret.com (captured 2026-10-16).
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Parsers\TribeEvents;

function scenecal_tribe_venue(): array {
	return scenecal_test_venue(
		array(
			'name' => 'The Pyramid Cabaret',
			'url'  => 'https://pyramidcabaret.com/events/',
		)
	);
}

scenecal_test(
	'tribe_events: maps fields from the REST API',
	static function () {
		$seen = array();
		scenecal_mock_http(
			array(
				'pyramidcabaret.com/wp-json/tribe/events/v1/events' => static function ( $url ) use ( &$seen ) {
					$seen[] = $url;
					return scenecal_fixture( 'tribe_events-page1.json' );
				},
			)
		);
		$events = ( new TribeEvents() )->parse( scenecal_tribe_venue(), scenecal_test_context( '2026-10-16', '9999-12-31' ) );

		scenecal_assert_same( 4, count( $events ) );
		$e = $events[0];
		scenecal_assert_same( "The Castagne\u{2019}s", $e['title'], 'entities decoded' );
		scenecal_assert_same( 'The Pyramid Cabaret', $e['venue_name'], 'empty venue [] falls back to configured venue' );
		scenecal_assert_same( '2026-10-17', $e['date'] );
		scenecal_assert_same( '22:00', $e['start_time'] );
		scenecal_assert_same( '23:30', $e['end_time'] );
		scenecal_assert_same( '', $e['ticket_price'] );
		scenecal_assert_same( 'https://www.bloomtickets.ca/event/2397', $e['ticket_link'], 'website preferred as ticket link' );
		scenecal_assert_same( array( 'https://pyramidcabaret.com/event/the-castagnes/' ), $e['source_urls'] );

		// Ends after midnight -> end time dropped (different date).
		scenecal_assert_same( '19:30', $events[1]['start_time'] );
		scenecal_assert_same( '', $events[1]['end_time'] );
		// No website -> event page is the ticket link.
		scenecal_assert_same( 'https://pyramidcabaret.com/event/roll-and-bowl-bsds-2026/', $events[2]['ticket_link'] );

		scenecal_assert( false !== strpos( $seen[0], 'start_date=2026-10-16' ), 'start_date sent' );
		scenecal_assert( false === strpos( $seen[0], 'end_date=' ), 'no end_date when unbounded' );
		scenecal_assert_same( 1, count( $seen ), 'single page (total_pages=1)' );
	}
);

scenecal_test(
	'tribe_events: filters to range and passes end_date',
	static function () {
		$seen = array();
		scenecal_mock_http(
			array(
				'wp-json/tribe/events/v1/events' => static function ( $url ) use ( &$seen ) {
					$seen[] = $url;
					return scenecal_fixture( 'tribe_events-page1.json' );
				},
			)
		);
		$events = ( new TribeEvents() )->parse( scenecal_tribe_venue(), scenecal_test_context( '2026-10-20', '2026-10-28' ) );
		scenecal_assert_same( array( '2026-10-23', '2026-10-28' ), array_column( $events, 'date' ) );
		scenecal_assert( false !== strpos( $seen[0], 'end_date=2026-10-28' ), 'end_date sent' );
	}
);

scenecal_test(
	'tribe_events: venue object, cost, all-day events and pagination stop on 400',
	static function () {
		$calls = 0;
		$page1 = array(
			'total_pages' => 3,
			'events'      => array(
				array(
					'title'       => 'Folk Night &amp; Friends',
					'description' => '<p>Two <b>great</b> acts</p>',
					'url'         => 'https://example.com/event/folk/',
					'start_date'  => '2026-12-01 00:00:00',
					'end_date'    => '2026-12-01 23:59:59',
					'all_day'     => true,
					'cost'        => '$15 &ndash; $20',
					'venue'       => array(
						'venue' => 'Back Room',
						'city'  => 'Winnipeg',
					),
				),
			),
		);
		scenecal_mock_http(
			array(
				'wp-json/tribe/events/v1/events' => static function () use ( &$calls, $page1 ) {
					++$calls;
					return 1 === $calls ? wp_json_encode( $page1 ) : array( 'status' => 400, 'body' => '{"code":"rest_invalid_param"}' );
				},
			)
		);
		$events = ( new TribeEvents() )->parse( scenecal_tribe_venue(), scenecal_test_context() );

		scenecal_assert_same( 2, $calls, 'stopped after the 400 on page 2' );
		scenecal_assert_same( 1, count( $events ) );
		scenecal_assert_same( 'Folk Night & Friends', $events[0]['title'] );
		scenecal_assert_same( 'Back Room', $events[0]['venue_name'] );
		scenecal_assert_same( "\$15 \u{2013} \$20", $events[0]['ticket_price'] );
		scenecal_assert_same( 'Two great acts', $events[0]['description'] );
		scenecal_assert_same( '', $events[0]['start_time'], 'all-day event has no start time' );
		scenecal_assert_same( '', $events[0]['end_time'] );
	}
);
