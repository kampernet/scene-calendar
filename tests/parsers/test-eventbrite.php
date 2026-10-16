<?php
/**
 * Eventbrite parser tests. eventbrite-organizer.html is a trimmed copy of the
 * live Times Change(d) organizer page (captured 2026-10-16): its JSON-LD is only
 * a ProfilePage, and the events live in the __NEXT_DATA__ page state.
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Parsers\Eventbrite;

function scenecal_eventbrite_venue( array $overrides = array() ): array {
	return scenecal_test_venue(
		array_merge(
			array(
				'name' => 'Times Changed High and Lonesome Club',
				'url'  => 'https://www.eventbrite.ca/o/times-changed-high-lonesome-club-18256543452',
			),
			$overrides
		)
	);
}

scenecal_test(
	'eventbrite: reads events from __NEXT_DATA__ page state',
	static function () {
		scenecal_mock_http( array( 'eventbrite.ca/o/' => scenecal_fixture( 'eventbrite-organizer.html' ) ) );
		$events = ( new Eventbrite() )->parse( scenecal_eventbrite_venue(), scenecal_test_context() );

		scenecal_assert_same( 4, count( $events ) );
		$e = $events[0];
		scenecal_assert_same( 'JD Edwards Band', $e['title'] );
		scenecal_assert_same( 'Times Changed High and Lonesome Club', $e['venue_name'] );
		scenecal_assert_same( '2026-10-16', $e['date'] );
		scenecal_assert_same( '21:30', $e['start_time'] );
		scenecal_assert_same( '', $e['end_time'], 'end == start placeholder dropped' );
		scenecal_assert_same( '$20.00', $e['ticket_price'] );
		scenecal_assert_same( 'https://www.eventbrite.ca/e/jd-edwards-band-tickets-2001060919076', $e['ticket_link'] );
		scenecal_assert_same( '$33.15', $events[1]['ticket_price'] );
		scenecal_assert_same( 'Ego Spank HALLOWEEN', $events[3]['title'] );
	}
);

scenecal_test(
	'eventbrite: filters page-state events to the date range',
	static function () {
		scenecal_mock_http( array( 'eventbrite.ca/o/' => scenecal_fixture( 'eventbrite-organizer.html' ) ) );
		$events = ( new Eventbrite() )->parse( scenecal_eventbrite_venue(), scenecal_test_context( '2026-10-17', '2026-10-30' ) );
		scenecal_assert_same( array( '2026-10-19', '2026-10-20' ), array_column( $events, 'date' ) );
	}
);

scenecal_test(
	'eventbrite: prefers JSON-LD events when present',
	static function () {
		$ld   = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'MusicEvent',
			'name'      => 'Late Show',
			'startDate' => '2026-11-06T02:00:00Z',
			'url'       => 'https://www.eventbrite.ca/e/late-show-1',
			'location'  => array( '@type' => 'Place', 'name' => 'Times Change(d) High & Lonesome Club' ),
			'offers'    => array( '@type' => 'Offer', 'price' => '15.00', 'priceCurrency' => 'CAD' ),
		);
		$html = '<html><head><script type="application/ld+json">' . wp_json_encode( $ld ) . '</script></head><body></body></html>';
		scenecal_mock_http( array( 'eventbrite.ca/o/' => $html ) );
		$events = ( new Eventbrite() )->parse( scenecal_eventbrite_venue(), scenecal_test_context() );

		scenecal_assert_same( 1, count( $events ) );
		// 02:00Z on Nov 6 is 20:00 on Nov 5 in Winnipeg (CST, UTC-6).
		scenecal_assert_same( '2026-11-05', $events[0]['date'] );
		scenecal_assert_same( '20:00', $events[0]['start_time'] );
		scenecal_assert_same( '$15.00', $events[0]['ticket_price'] );
		scenecal_assert_same( 'https://www.eventbrite.ca/e/late-show-1', $events[0]['ticket_link'] );
	}
);

scenecal_test(
	'eventbrite: falls back to HTML event cards',
	static function () {
		$html = '<html><body>'
			. '<article><h3>Card Show</h3><time datetime="2026-12-05T20:00:00">Sat, Dec 5</time>'
			. '<a href="/e/card-show-123">Tickets</a><div class="price">From $12.00</div></article>'
			. '<article><h3>Old Show</h3><time datetime="2026-01-05T20:00:00">Mon, Jan 5</time></article>'
			. '</body></html>';
		scenecal_mock_http( array( 'eventbrite.ca/o/' => $html ) );
		$events = ( new Eventbrite() )->parse( scenecal_eventbrite_venue(), scenecal_test_context() );

		scenecal_assert_same( 1, count( $events ), 'out-of-range card dropped' );
		scenecal_assert_same( 'Card Show', $events[0]['title'] );
		scenecal_assert_same( '2026-12-05', $events[0]['date'] );
		scenecal_assert_same( '$12.00', $events[0]['ticket_price'] );
		scenecal_assert_same( 'https://www.eventbrite.ca/e/card-show-123', $events[0]['ticket_link'] );
	}
);

scenecal_test(
	'eventbrite: card dates without a year and URL matching',
	static function () {
		scenecal_assert_same( '2026-10-16', Eventbrite::card_date( 'Fri, Oct 16', '2026-10-01' ) );
		scenecal_assert_same( '2027-01-09', Eventbrite::card_date( 'Saturday, January 9', '2026-10-01' ) );
		scenecal_assert_same( '2026-10-16', Eventbrite::card_date( 'October 16, 2026', '2026-10-01' ) );
		scenecal_assert( Eventbrite::matches_url( 'https://www.eventbrite.ca/o/x-1' ) );
		scenecal_assert( Eventbrite::matches_url( 'https://www.eventbrite.com/o/x-1' ) );
		scenecal_assert( Eventbrite::matches_url( 'https://www.eventbrite.co.uk/o/x-1' ) );
		scenecal_assert( ! Eventbrite::matches_url( 'https://example.com/eventbrite' ) );
	}
);
