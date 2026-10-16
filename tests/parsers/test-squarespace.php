<?php

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;
use SceneCalendar\Parsers\Context;
use SceneCalendar\Parsers\Squarespace;

/**
 * DST-mechanism tests use America/Chicago: its CST/CDT rules are stable, whereas
 * America/Winnipeg moved to permanent UTC-5 on 2026-11-01 and older tzdata
 * (e.g. PHP's bundled timezonedb 2025.x) still applies UTC-6 there in winter.
 */
function scenecal_chicago_context( string $start = '2026-10-01', string $end = '2027-12-31' ): Context {
	return new Context( $start, $end, new DateTimeZone( 'America/Chicago' ) );
}

scenecal_test(
	'squarespace: list view (WECC) — body price, show time and ticket links',
	static function () {
		$requested = array();
		scenecal_mock_http(
			array(
				'wecc.ca/events' => static function ( $url ) use ( &$requested ) {
					$requested[] = $url;
					return scenecal_fixture( 'squarespace-wecc.json' );
				},
			)
		);
		$venue  = scenecal_test_venue( array( 'name' => 'West End Cultural Centre', 'url' => 'https://www.wecc.ca/events' ) );
		$events = ( new Squarespace() )->parse( $venue, scenecal_test_context() );

		scenecal_assert_same( array( 'https://www.wecc.ca/events?format=json' ), $requested );
		// "Fall Season ... Announced" starts 2026-09-04, before the window.
		scenecal_assert_same( array( '2026-10-16', '2026-10-29', '2026-10-30' ), array_column( $events, 'date' ) );

		$jarlath = $events[0];
		scenecal_assert_same( 'Jarlath Regan - "Gas Man"', $jarlath['title'] );
		scenecal_assert_same( '20:00', $jarlath['start_time'], '"Show - 8:00pm" preferred over "Doors - 7:00pm"' );
		scenecal_assert_same( '23:00', $jarlath['end_time'] );
		scenecal_assert( str_starts_with( $jarlath['ticket_link'], 'https://admitone.com/events/' ), 'known ticketing host' );
		scenecal_assert( str_starts_with( $jarlath['source_urls'][0], 'https://www.wecc.ca/events/' ), 'absolute source url' );
		scenecal_assert( '' !== $jarlath['description'] && mb_strlen( $jarlath['description'] ) <= 303, 'description' );

		$flora = $events[1];
		scenecal_assert_same( '20:00', $flora['start_time'] );
		scenecal_assert_same( '$25-$30', $flora['ticket_price'] );
		scenecal_assert_same( 'https://westendculturalcentre.thundertix.com/events/266800', $flora['ticket_link'] );
		scenecal_assert_same( '$30-$35', $events[2]['ticket_price'] );
	}
);

scenecal_test(
	'squarespace: timestamps use the site timezone across DST (CST in January, CDT in July)',
	static function () {
		scenecal_mock_http( array( 'example.com/events' => scenecal_fixture( 'squarespace-dst.json' ) ) );
		$events = ( new Squarespace() )->parse( scenecal_test_venue( array( 'url' => 'https://example.com/events' ) ), scenecal_chicago_context() );

		$by_title = array_column( $events, null, 'title' );
		// 2027-01-16 02:00 UTC = 2027-01-15 20:00 CST (UTC-6).
		scenecal_assert_same( '2027-01-15', $by_title['Winter Show']['date'] );
		scenecal_assert_same( '20:00', $by_title['Winter Show']['start_time'] );
		scenecal_assert_same( '23:00', $by_title['Winter Show']['end_time'] );
		// 2027-07-10 01:00 UTC = 2027-07-09 20:00 CDT (UTC-5).
		scenecal_assert_same( '2027-07-09', $by_title['Summer Show']['date'] );
		scenecal_assert_same( '20:00', $by_title['Summer Show']['start_time'] );
		scenecal_assert_same( '23:30', $by_title['Summer Show']['end_time'] );
		// Local midnight means "no time set".
		scenecal_assert_same( '2027-03-01', $by_title['Midnight Placeholder']['date'] );
		scenecal_assert_same( '', $by_title['Midnight Placeholder']['start_time'] );
		scenecal_assert_same( '', $by_title['Midnight Placeholder']['end_time'] );
	}
);

scenecal_test(
	'squarespace: list view follows nextPageUrl offsets',
	static function () {
		scenecal_mock_http(
			array(
				'offset=1799999999999&format=json' => scenecal_fixture( 'squarespace-page2.json' ),
				'example.com/events?format=json'   => scenecal_fixture( 'squarespace-page1.json' ),
			)
		);
		$events = ( new Squarespace() )->parse( scenecal_test_venue( array( 'url' => 'https://example.com/events' ) ), scenecal_chicago_context() );

		scenecal_assert_same( array( 'Page One Show', 'Page Two & Friends' ), array_column( $events, 'title' ) );
		$one = $events[0];
		scenecal_assert_same( '2026-11-06', $one['date'], 'after DST ends: 02:00Z = 20:00 CST previous day' );
		scenecal_assert_same( '20:00', $one['start_time'] );
		scenecal_assert_same( '$15', $one['ticket_price'] );
		scenecal_assert_same( 'https://example.thundertix.com/events/1', $one['ticket_link'] );
		scenecal_assert_same( 'Free', $events[1]['ticket_price'] );
		scenecal_assert_same( '20:00', $events[1]['start_time'], 'time from timestamp when body has none' );
	}
);

scenecal_test(
	'squarespace: calendar view (Kings Head) fetches following months',
	static function () {
		$requested = array();
		$record    = static function ( $body ) use ( &$requested ) {
			return static function ( $url ) use ( $body, &$requested ) {
				$requested[] = $url;
				return $body;
			};
		};
		scenecal_mock_http(
			array(
				'month=november-2026' => $record( scenecal_fixture( 'squarespace-kingshead-nov.json' ) ),
				'live-entertainment?format=json' => $record( scenecal_fixture( 'squarespace-kingshead-oct.json' ) ),
			)
		);
		$venue  = scenecal_test_venue( array( 'name' => 'The Kings Head Pub', 'url' => 'https://www.kingshead.ca/live-entertainment' ) );
		$events = ( new Squarespace() )->parse( $venue, scenecal_test_context( '2026-10-01', '2026-11-30' ) );

		scenecal_assert_same(
			array(
				'https://www.kingshead.ca/live-entertainment?format=json',
				'https://www.kingshead.ca/live-entertainment?month=november-2026&view=calendar&format=json',
			),
			$requested,
			'month URLs resolved against the collection, stopping at the window end'
		);
		scenecal_assert_same( 6, count( $events ) );
		$by_title = array_column( $events, null, 'title' );
		scenecal_assert_same( '2026-10-31', $by_title["LIVE BAND: CHEERS TO THE 90's"]['date'] );
		scenecal_assert_same( '21:00', $by_title["LIVE BAND: CHEERS TO THE 90's"]['start_time'] );
		scenecal_assert( isset( $by_title['LIVE BAND: BANGERS & MASH'] ), 'entities decoded in titles' );
		// 2026-11-29 03:00Z: 22:00 under current tzdata (Winnipeg permanent UTC-5), 21:00 under
		// pre-2026 tzdata — only the date is tzdata-independent.
		scenecal_assert_same( '2026-11-28', $by_title['LIVE BAND: LEDDWYN']['date'] );
	}
);

scenecal_test(
	'squarespace: helpers',
	static function () {
		scenecal_assert_same( 'https://x.com/events?format=json', Squarespace::json_url( 'https://x.com/events' ) );
		scenecal_assert_same( 'https://x.com/events?view=list&format=json', Squarespace::json_url( 'https://x.com/events?view=list' ) );
		scenecal_assert_same( 'PWYC', Squarespace::extract_price( 'Tickets are pay what you can' ) );
		scenecal_assert_same( '$10.50', Squarespace::extract_price( 'Cover $10.50 at the door' ) );
		scenecal_assert_same( '21:00', Squarespace::extract_time( "Doors: 8pm\nShow - 9:00 PM" ) );
		scenecal_assert( Squarespace::matches_url( 'https://myvenue.squarespace.com/events' ) );
		scenecal_assert( ! Squarespace::matches_url( 'https://www.wecc.ca/events' ) );
	}
);
