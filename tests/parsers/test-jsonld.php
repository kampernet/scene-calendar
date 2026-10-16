<?php
/**
 * Generic schema.org JSON-LD parser tests (hand-written fixture covering
 * @graph, ItemList/ListItem nesting, duplicates, cancellations, offers).
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Parsers\JsonLd;

function scenecal_jsonld_venue( array $overrides = array() ): array {
	return scenecal_test_venue( array_merge( array( 'url' => 'https://venue.example/shows' ), $overrides ) );
}

scenecal_test(
	'jsonld: extracts nested events, dedupes, skips cancelled',
	static function () {
		scenecal_mock_http( array( 'venue.example/shows' => scenecal_fixture( 'jsonld-events.html' ) ) );
		$events = ( new JsonLd() )->parse( scenecal_jsonld_venue(), scenecal_test_context() );

		scenecal_assert_same(
			array( 'Band One & Band Two', 'All Day Craft Fair', 'UTC Gig', 'Next Year Gig' ),
			array_column( $events, 'title' )
		);

		$e = $events[0];
		scenecal_assert_same( 'Test Venue', $e['venue_name'], 'venue keeps configured name' );
		scenecal_assert_same( '2026-11-20', $e['date'] );
		scenecal_assert_same( '20:00', $e['start_time'] );
		scenecal_assert_same( '23:00', $e['end_time'] );
		scenecal_assert_same( array( 'Band One', 'Band Two' ), $e['lineup'] );
		scenecal_assert_same( '$15.00 - $20.00', $e['ticket_price'] );
		scenecal_assert_same( 'https://venue.example/shows/band-one', $e['ticket_link'] );
		scenecal_assert_same( 'Double bill.', $e['description'] );

		$fair = $events[1];
		scenecal_assert_same( '2026-11-21', $fair['date'] );
		scenecal_assert_same( '', $fair['start_time'], 'date-only startDate' );
		scenecal_assert_same( 'Free', $fair['ticket_price'] );
		scenecal_assert_same( array( 'https://venue.example/shows' ), $fair['source_urls'] );

		$utc = $events[2];
		scenecal_assert_same( '2026-11-24', $utc['date'], 'UTC converted to site timezone' );
		scenecal_assert_same( '21:00', $utc['start_time'] );
		scenecal_assert_same( array( 'Solo Singer' ), $utc['lineup'] );
		scenecal_assert_same( '$12.50', $utc['ticket_price'] );
		scenecal_assert_same( 'https://tix.example/utc', $utc['ticket_link'], 'offer URL used when event has none' );
	}
);

scenecal_test(
	'jsonld: filters to the date range',
	static function () {
		scenecal_mock_http( array( 'venue.example/shows' => scenecal_fixture( 'jsonld-events.html' ) ) );
		$events = ( new JsonLd() )->parse( scenecal_jsonld_venue(), scenecal_test_context( '2026-11-21', '2026-11-30' ) );
		scenecal_assert_same( array( 'All Day Craft Fair', 'UTC Gig' ), array_column( $events, 'title' ) );
	}
);

scenecal_test(
	'jsonld: promoters take venue from location.name',
	static function () {
		scenecal_mock_http( array( 'venue.example/shows' => scenecal_fixture( 'jsonld-events.html' ) ) );
		$events = ( new JsonLd() )->parse( scenecal_jsonld_venue( array( 'type' => 'promoter', 'name' => 'Some Promoter' ) ), scenecal_test_context() );
		scenecal_assert_same( array( 'The Back Room', 'Community Hall', 'Some Promoter', 'Some Promoter' ), array_column( $events, 'venue_name' ) );
	}
);

scenecal_test(
	'jsonld: type detection and helpers',
	static function () {
		scenecal_assert( JsonLd::is_event_type( 'MusicEvent' ) );
		scenecal_assert( JsonLd::is_event_type( array( 'Thing', 'ComedyEvent' ) ) );
		scenecal_assert( JsonLd::is_event_type( 'https://schema.org/Event' ) );
		scenecal_assert( ! JsonLd::is_event_type( 'EventVenue' ) );
		scenecal_assert( ! JsonLd::is_event_type( null ) );
		scenecal_assert_same( array( '$10.00', 'https://t.example' ), JsonLd::offers( array( array( 'price' => '10', 'url' => 'https://t.example' ) ) ) );
		scenecal_assert_same( array( '', '' ), JsonLd::offers( null ) );
		scenecal_assert_same( array( 'A', 'B' ), JsonLd::performers( array( 'A', array( 'name' => 'B' ), 'A' ) ) );
		scenecal_assert_same( 'Hall', JsonLd::location_name( array( array( 'name' => 'Hall' ) ) ) );
	}
);
