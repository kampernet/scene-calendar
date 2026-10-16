<?php

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;
use SceneCalendar\Parsers\TicketWebCalendar;

scenecal_test(
	'ticketweb_calendar: AJAX mode (plugin 7.x) posts nonce and parses popup dialogs',
	static function () {
		$posted = array();
		scenecal_mock_http(
			array(
				'admin-ajax.php' => static function ( $url, $args ) use ( &$posted ) {
					$posted = $args;
					return scenecal_fixture( 'ticketweb_calendar-ajax.json' );
				},
				'myparktheatre.com/calendar/' => scenecal_fixture( 'ticketweb_calendar-ajax-page.html' ),
			)
		);
		$venue  = scenecal_test_venue( array( 'name' => 'The Park Theatre', 'url' => 'https://myparktheatre.com/calendar/' ) );
		$events = ( new TicketWebCalendar() )->parse( $venue, scenecal_test_context( '2026-10-01', '9999-12-31' ) );

		scenecal_assert_same( 'POST', $posted['method'] ?? '' );
		scenecal_assert_same( 'get_events_for_calendar', $posted['body']['action'] ?? '' );
		scenecal_assert_same( '0123456789', $posted['body']['nonce'] ?? '' );
		scenecal_assert_same( '2026-10-01', $posted['body']['start'] ?? '' );
		scenecal_assert_same( '2027-10-01', $posted['body']['end'] ?? '', 'unbounded window capped at a year' );
		scenecal_assert( str_contains( $posted['body']['params'] ?? '', '"type":"calendar"' ), 'params blob forwarded' );

		// PRIVATE EVENT is skipped.
		scenecal_assert_same( array( '2026-10-02', '2026-10-08', '2026-10-09' ), array_column( $events, 'date' ) );

		$cantor = $events[0];
		scenecal_assert_same( 'Cantor Dust: The Anatomy of Malevolence: Album Release Show', $cantor['title'] );
		scenecal_assert_same( array( 'Cantor Dust', 'Luana Moth', 'Juniper Moon' ), $cantor['lineup'] );
		scenecal_assert_same( '20:30', $cantor['start_time'] );
		scenecal_assert_same( '$24.41', $cantor['ticket_price'] );
		scenecal_assert( str_contains( $cantor['ticket_link'], 'REFID=clientsitewp' ) && ! str_contains( $cantor['ticket_link'], '&amp;' ), 'ticket link unescaped' );
		scenecal_assert_same( array( 'https://myparktheatre.com/tm-event/cantor-dust-the-anatomy-of-malevolence-album-release-show/' ), $cantor['source_urls'] );
		scenecal_assert( str_starts_with( $cantor['description'], 'Winnipeg experimental rock quartet' ), 'description' );
		scenecal_assert( mb_strlen( $cantor['description'] ) <= 303, 'description truncated' );

		scenecal_assert_same( '$62.46 - $72.76', $events[1]['ticket_price'] );
		scenecal_assert_same( 7, count( $events[1]['lineup'] ) );
	}
);

scenecal_test(
	'ticketweb_calendar: legacy inline all_events array with dialogs and stub fallback',
	static function () {
		scenecal_mock_http( array( 'venue.example.com' => scenecal_fixture( 'ticketweb_calendar-legacy.html' ) ) );
		$venue  = scenecal_test_venue( array( 'url' => 'https://venue.example.com/calendar/' ) );
		$events = ( new TicketWebCalendar() )->parse( $venue, scenecal_test_context() );

		// PRIVATE EVENT and "Closed for Easter" skipped; past show filtered.
		scenecal_assert_same( array( 'Band & Friends', 'Stub Only Show' ), array_column( $events, 'title' ) );

		$band = $events[0];
		scenecal_assert_same( '2026-11-05', $band['date'] );
		scenecal_assert_same( array( 'Band', 'Friends' ), $band['lineup'] );
		scenecal_assert_same( '20:00', $band['start_time'] );
		scenecal_assert_same( '$20.00 - $25.50', $band['ticket_price'] );
		scenecal_assert_same( 'https://www.ticketweb.ca/event/band/1?pl=x&REFID=y', $band['ticket_link'] );
		scenecal_assert_same( 'A night of music.', $band['description'] );

		$stub = $events[1];
		scenecal_assert_same( '19:30', $stub['start_time'], 'falls back to doors' );
		scenecal_assert_same( array( 'https://venue.example.com/calendar/' ), $stub['source_urls'] );
	}
);

scenecal_test(
	'ticketweb_calendar: page without calendar data returns nothing',
	static function () {
		scenecal_mock_http( array( 'venue.example.com' => '<html><body><p>No calendar</p></body></html>' ) );
		$events = ( new TicketWebCalendar() )->parse( scenecal_test_venue( array( 'url' => 'https://venue.example.com/' ) ), scenecal_test_context() );
		scenecal_assert_same( array(), $events );
	}
);

scenecal_test(
	'ticketweb_calendar: skipped titles',
	static function () {
		scenecal_assert( TicketWebCalendar::is_skipped_title( 'PRIVATE EVENT' ) );
		scenecal_assert( TicketWebCalendar::is_skipped_title( 'CLOSED FOR EASTER' ) );
		scenecal_assert( TicketWebCalendar::is_skipped_title( 'Closed' ) );
		scenecal_assert( ! TicketWebCalendar::is_skipped_title( 'Private Event Horizon (band)' ) );
		scenecal_assert( ! TicketWebCalendar::is_skipped_title( 'The Closers' ) );
	}
);
