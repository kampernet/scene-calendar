<?php
/**
 * Showpass parser tests. Fixtures are trimmed live API responses for the
 * Real Love Winnipeg promoter account (captured 2026-10-16).
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Parsers\Showpass;

function scenecal_showpass_venue( array $overrides = array() ): array {
	return scenecal_test_venue(
		array_merge(
			array(
				'name'        => 'Real Love',
				'type'        => 'promoter',
				'url'         => 'https://www.showpass.com/o/reallovewinnipeg/',
				'showpass_id' => '2439',
			),
			$overrides
		)
	);
}

scenecal_test(
	'showpass: maps fields, promoter venue from location name, local times',
	static function () {
		scenecal_mock_http( array( 'api/public/events/' => scenecal_fixture( 'showpass-events.json' ) ) );
		$events = ( new Showpass() )->parse( scenecal_showpass_venue(), scenecal_test_context() );

		scenecal_assert_same( 4, count( $events ) );

		$e = $events[0];
		scenecal_assert_same( "Compost, We're Only Here For The Snacks, Jason Tait", $e['title'] );
		scenecal_assert_same( 'Public Domain', $e['venue_name'], 'promoter event uses location.name' );
		scenecal_assert_same( 'Winnipeg', $e['venue_city'] );
		scenecal_assert_same( 'Manitoba', $e['venue_province'] );
		scenecal_assert_same( '2026-10-24', $e['date'] );
		scenecal_assert_same( '19:00', $e['start_time'] );
		// No local_ends_on: UTC ends_on 04:30Z is 23:30 local.
		scenecal_assert_same( '23:30', $e['end_time'] );
		scenecal_assert_same( '', $e['ticket_price'], 'frontend_price is null' );
		scenecal_assert_same( 'https://www.showpass.com/compost2026/', $e['ticket_link'] );
		scenecal_assert_same( array( 'https://www.showpass.com/compost2026/' ), $e['source_urls'] );
		scenecal_assert( str_starts_with( $e['description'], 'Real Love Winnipeg presents...' ), 'description is tag-stripped' );
		scenecal_assert( false === strpos( $e['description'], '<' ), 'no HTML left in description' );

		// location.name is a street address -> fall back to the API venue name (pipeline resolves it later).
		scenecal_assert_same( 'Real Love Winnipeg', $events[1]['venue_name'] );
		scenecal_assert_same( 'The Handsome Daughter', $events[2]['venue_name'] );
		scenecal_assert_same( '19:30', $events[3]['start_time'] );
	}
);

scenecal_test(
	'showpass: filters to date range and sends range to the API',
	static function () {
		$seen = array();
		scenecal_mock_http(
			array(
				'api/public/events/' => static function ( $url ) use ( &$seen ) {
					$seen[] = $url;
					return scenecal_fixture( 'showpass-events.json' );
				},
			)
		);
		$events = ( new Showpass() )->parse( scenecal_showpass_venue(), scenecal_test_context( '2026-10-25', '2026-10-29' ) );

		scenecal_assert_same( array( '2026-10-26', '2026-10-29' ), array_column( $events, 'date' ) );
		scenecal_assert( false !== strpos( $seen[0], 'venue__in=2439' ), 'venue__in sent' );
		scenecal_assert( false !== strpos( $seen[0], 'starts_on__gte=2026-10-25' ), 'start sent' );
		scenecal_assert( false !== strpos( $seen[0], 'starts_on__lte=2026-10-29' ), 'end sent' );
	}
);

scenecal_test(
	'showpass: resolves ID from slug, rejects other cities, prices',
	static function () {
		$seen    = array();
		$payload = array(
			'results' => array(
				array(
					'name'            => 'Home Show',
					'slug'            => 'home',
					'local_starts_on' => '2027-02-01T20:00:00-06:00',
					'frontend_price'  => 0,
					'venue'           => array( 'name' => 'Real Love Winnipeg' ),
					'location'        => array( 'name' => 'Sidestage', 'city' => 'Winnipeg' ),
				),
				array(
					'name'            => 'Road Show',
					'slug'            => 'road',
					'local_starts_on' => '2027-02-02T20:00:00-06:00',
					'frontend_price'  => 25,
					'venue'           => array( 'name' => 'Real Love Winnipeg' ),
					'location'        => array( 'name' => 'Some Bar', 'city' => 'Brandon' ),
				),
				array(
					'name'            => 'Paid Show',
					'slug'            => 'paid',
					'local_starts_on' => '2027-02-03T20:00:00-06:00',
					'frontend_price'  => 22.5,
					'venue'           => array( 'name' => 'Real Love Winnipeg' ),
					'location'        => array( 'city' => 'winnipeg' ),
				),
			),
		);
		scenecal_mock_http(
			array(
				'api/public/venues/reallovewinnipeg/' => scenecal_fixture( 'showpass-venue.json' ),
				'api/public/events/'                  => static function ( $url ) use ( &$seen, $payload ) {
					$seen[] = $url;
					return wp_json_encode( $payload );
				},
			)
		);
		$events = ( new Showpass() )->parse( scenecal_showpass_venue( array( 'showpass_id' => '' ) ), scenecal_test_context() );

		scenecal_assert( false !== strpos( $seen[0], 'venue__in=2439' ), 'slug resolved to ID' );
		scenecal_assert_same( array( 'Home Show', 'Paid Show' ), array_column( $events, 'title' ), 'Brandon event rejected' );
		scenecal_assert_same( 'Free', $events[0]['ticket_price'] );
		scenecal_assert_same( 'Sidestage', $events[0]['venue_name'] );
		scenecal_assert_same( '$22.50', $events[1]['ticket_price'] );
		scenecal_assert_same( 'Real Love Winnipeg', $events[1]['venue_name'] );
	}
);

scenecal_test(
	'showpass: slug extraction and venue-from-description helper',
	static function () {
		scenecal_assert_same( 'reallovewinnipeg', Showpass::extract_slug( 'https://www.showpass.com/o/reallovewinnipeg/' ) );
		scenecal_assert_same( 'public-domain', Showpass::extract_slug( 'https://www.showpass.com/venues/public-domain/' ) );
		scenecal_assert_same( null, Showpass::extract_slug( 'https://www.showpass.com/some-event/' ) );
		scenecal_assert_same( 'The Handsome Daughter', Showpass::venue_from_description( '<p>The Handsome Daughter<br>March 13</p>' ) );
		scenecal_assert_same( '', Showpass::venue_from_description( '<p>A great night out.</p>' ) );
		scenecal_assert( Showpass::matches_url( 'https://www.showpass.com/o/x/' ) );
		scenecal_assert( ! Showpass::matches_url( 'https://notshowpass.com/' ) );
	}
);
