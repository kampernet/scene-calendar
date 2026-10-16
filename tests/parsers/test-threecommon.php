<?php
/**
 * ThreeCommon parser tests. Fixtures are trimmed live API responses for
 * The Handsome Daughter (captured 2026-10-16).
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Parsers\ThreeCommon;

function scenecal_threecommon_venue( array $overrides = array() ): array {
	return scenecal_test_venue(
		array_merge(
			array(
				'name'        => 'The Handsome Daughter',
				'url'         => 'https://3common.com/u/thehandsomedaughter_',
				'3common_uid' => '667447a736fa859aaebb088b',
			),
			$overrides
		)
	);
}

function scenecal_threecommon_mock(): void {
	scenecal_mock_http(
		array(
			'page=0' => scenecal_fixture( 'threecommon-page0.json' ),
			'page=1' => scenecal_fixture( 'threecommon-page1.json' ),
		)
	);
}

scenecal_test(
	'threecommon: maps fields and converts UTC to venue local time across pages',
	static function () {
		scenecal_threecommon_mock();
		$events = ( new ThreeCommon() )->parse( scenecal_threecommon_venue(), scenecal_test_context() );

		scenecal_assert_same( 4, count( $events ), 'events from both pages' );

		$first = $events[0];
		scenecal_assert_same( 'The Handsome Daughter', $first['venue_name'] );
		scenecal_assert_same( 'SHOW PONY w/ Pure Impact & Placeholder', $first['title'] );
		// 2026-10-17T01:00Z is 20:00 the previous evening in Winnipeg (CDT, UTC-5).
		scenecal_assert_same( '2026-10-16', $first['date'] );
		scenecal_assert_same( '20:00', $first['start_time'] );
		scenecal_assert_same( '21:00', $first['end_time'] );
		scenecal_assert_same( '', $first['ticket_price'], 'price 0 is unreliable on 3common, so unknown' );
		scenecal_assert_same( 'https://3common.com/e/6aa0210e51db38a69382bf32', $first['ticket_link'] );
		scenecal_assert_same( array( 'https://3common.com/u/thehandsomedaughter_' ), $first['source_urls'] );

		// Late November: Manitoba stays on UTC-5 from Nov 2026 and the API's
		// offset (300) says so, so 01:00Z is 20:00 local whatever PHP's tz data says.
		$last = $events[3];
		scenecal_assert_same( '2026-11-28', $last['date'] );
		scenecal_assert_same( '20:00', $last['start_time'] );
	}
);

scenecal_test(
	'threecommon: filters to the date range',
	static function () {
		scenecal_threecommon_mock();
		$events = ( new ThreeCommon() )->parse( scenecal_threecommon_venue(), scenecal_test_context( '2026-10-17', '2026-10-31' ) );
		scenecal_assert_same(
			array( 'Federal Lights & An Unexpected End with Amber Rose', "Bob's Burgers Halloween Trivia Presented by The Handsome Daughter" ),
			array_column( $events, 'title' )
		);
		scenecal_assert_same( '2026-10-19', $events[1]['date'] );
		scenecal_assert_same( '19:00', $events[1]['start_time'] );
	}
);

scenecal_test(
	'threecommon: prices from donation and paid items; offset fallback when zone missing',
	static function () {
		$base  = array(
			'_id'      => 'abc',
			'name'     => 'Show',
			'start'    => '2027-01-15T02:30:00.000Z',
			'currency' => 'CAD',
			'timezone' => array( 'offset' => 360 ),
		);
		$page  = array(
			'pagination' => array( 'pageCount' => 1 ),
			'events'     => array(
				array_merge( $base, array( 'items' => array( array( 'type' => 'donation', 'price' => '0', 'minDonationCents' => 1000 ) ) ) ),
				array_merge( $base, array( 'name' => 'B', 'items' => array( array( 'type' => 'donation', 'price' => '0' ) ) ) ),
				array_merge( $base, array( 'name' => 'C', 'items' => array( array( 'type' => 'regular', 'price' => '0' ), array( 'type' => 'regular', 'price' => '17.5' ) ) ) ),
				array_merge( $base, array( 'name' => 'D', 'items' => array() ) ),
			),
		);
		scenecal_mock_http( array( 'upcoming-events' => wp_json_encode( $page ) ) );
		$events = ( new ThreeCommon() )->parse( scenecal_threecommon_venue(), scenecal_test_context() );

		scenecal_assert_same( array( '$10+ CAD (donation)', 'PWYC', '$17.50 CAD', '' ), array_column( $events, 'ticket_price' ) );
		// offset 360 = UTC-6: 02:30Z on the 15th is 20:30 on the 14th.
		scenecal_assert_same( '2027-01-14', $events[0]['date'] );
		scenecal_assert_same( '20:30', $events[0]['start_time'] );
		scenecal_assert_same( '', $events[0]['end_time'] );
	}
);

scenecal_test(
	'threecommon: missing owner ID returns no events without fetching',
	static function () {
		scenecal_mock_http( array() );
		$events = ( new ThreeCommon() )->parse( scenecal_threecommon_venue( array( '3common_uid' => '' ) ), scenecal_test_context() );
		scenecal_assert_same( array(), $events );
	}
);

scenecal_test(
	'threecommon: matches 3common URLs only',
	static function () {
		scenecal_assert( ThreeCommon::matches_url( 'https://3common.com/u/parkalleys' ) );
		scenecal_assert( ThreeCommon::matches_url( 'https://www.3common.com/u/parkalleys' ) );
		scenecal_assert( ! ThreeCommon::matches_url( 'https://example.com/3common.com' ) );
	}
);
