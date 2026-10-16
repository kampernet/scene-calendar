<?php

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;
use SceneCalendar\Parsers\TicketWebList;

scenecal_test(
	'ticketweb_list: parses Sidestage list blocks',
	static function () {
		scenecal_mock_http( array( 'sidestagewpg.com' => scenecal_fixture( 'ticketweb_list-sidestage.html' ) ) );
		$venue  = scenecal_test_venue( array( 'name' => 'Sidestage', 'url' => 'https://www.sidestagewpg.com/' ) );
		$events = ( new TicketWebList() )->parse( $venue, scenecal_test_context() );

		scenecal_assert_same( 4, count( $events ), 'event count' );

		$first = $events[0];
		scenecal_assert_same( 'Sidestage', $first['venue_name'] );
		scenecal_assert_same( '2026-10-16', $first['date'] );
		scenecal_assert_same( 'Big Tones | OSANI + Break Dancing', $first['title'] );
		scenecal_assert_same( array( 'Big Tones', 'OSANI + Break Dancing' ), $first['lineup'] );
		scenecal_assert_same( '20:00', $first['start_time'] );
		scenecal_assert_same( '$13.08', $first['ticket_price'] );
		scenecal_assert_same( 'All Ages', $first['description'] );
		scenecal_assert( str_starts_with( $first['ticket_link'], 'https://www.ticketweb.ca/event/big-tones-osani-sidestage-tickets/' ), 'ticket link' );
		scenecal_assert_same( array( 'https://www.sidestagewpg.com/tm-event/raythenihilist-break-dancing/' ), $first['source_urls'] );

		scenecal_assert_same( array(), $events[1]['lineup'], 'no pipe, no lineup' );
		scenecal_assert_same( '$15.74 - $21.14', $events[2]['ticket_price'], 'price range' );
		scenecal_assert_same( '19:00', $events[2]['start_time'] );

		$last = $events[3];
		scenecal_assert_same( "*SOLD OUT* Sami Landri's Sami Party", $last['title'] );
		scenecal_assert_same( '2026-11-11', $last['date'] );
		scenecal_assert_same( '18 and up', $last['description'] );
	}
);

scenecal_test(
	'ticketweb_list: date window filters blocks',
	static function () {
		scenecal_mock_http( array( 'sidestagewpg.com' => scenecal_fixture( 'ticketweb_list-sidestage.html' ) ) );
		$venue  = scenecal_test_venue( array( 'url' => 'https://www.sidestagewpg.com/' ) );
		$events = ( new TicketWebList() )->parse( $venue, scenecal_test_context( '2026-10-17', '2026-10-31' ) );
		scenecal_assert_same( array( '2026-10-17', '2026-10-18' ), array_column( $events, 'date' ) );
	}
);

scenecal_test(
	'ticketweb_list: year-less dates infer the year from the window start',
	static function () {
		scenecal_assert_same( '2027-08-27', TicketWebList::parse_date( '27 Aug', '2026-10-01' ) );
		scenecal_assert_same( '2026-10-16', TicketWebList::parse_date( '16 Oct @', '2026-10-01' ) );
		scenecal_assert_same( '2027-03-13', TicketWebList::parse_date( 'Fri, Mar 13', '2026-10-01' ) );
		scenecal_assert_same( '2026-12-05', TicketWebList::parse_date( 'Dec 5', '2026-10-01' ) );
		scenecal_assert_same( '', TicketWebList::parse_date( 'TBA', '2026-10-01' ) );
	}
);

scenecal_test(
	'ticketweb_list: price parsing',
	static function () {
		scenecal_assert_same( '$18.23', TicketWebList::parse_price( 'CAD18.23' ) );
		scenecal_assert_same( 'Free', TicketWebList::parse_price( 'CAD0.00' ) );
		scenecal_assert_same( '$12.05 - $18.23', TicketWebList::parse_price( 'CAD12.05 - CAD18.23' ) );
		scenecal_assert_same( 'Sold Out', TicketWebList::parse_price( 'Sold Out' ) );
		scenecal_assert_same( '', TicketWebList::parse_price( '' ) );
	}
);

scenecal_test(
	'ticketweb_list: follows WordPress "next" pagination',
	static function () {
		$block = static fn( $name, $date ) => '<div class="tw-section"><div class="tw-name">' . $name . '</div><div class="tw-event-date">' . $date . '</div></div>';
		scenecal_mock_http(
			array(
				'example.com/page/2/' => '<html><body>' . $block( 'Second Page Show', '20 Nov' ) . '</body></html>',
				'example.com/'        => '<html><body>' . $block( 'First Page Show', '19 Nov' ) . '<a class="next page-numbers" href="https://example.com/page/2/">Next</a></body></html>',
			)
		);
		$events = ( new SceneCalendar\Parsers\TicketWebList() )->parse( scenecal_test_venue( array( 'url' => 'https://example.com/' ) ), scenecal_test_context( '2026-10-01', '2027-12-31' ) );
		scenecal_assert_same( array( 'First Page Show', 'Second Page Show' ), array_column( $events, 'title' ) );
		scenecal_assert_same( '2026-11-20', $events[1]['date'] );
	}
);
