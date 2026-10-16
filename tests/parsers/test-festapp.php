<?php
/**
 * FestApp parser tests (hand-written fixture modelled on the Festival du
 * Voyageur page the Python parser was built against).
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Parsers\FestApp;

function scenecal_festapp_venue(): array {
	return scenecal_test_venue(
		array(
			'name' => 'Festival du Voyageur',
			'type' => 'promoter',
			'url'  => 'https://festapp.io/festival-du-voyageur',
		)
	);
}

scenecal_test(
	'festapp: groups performances into one event per stage per day',
	static function () {
		scenecal_mock_http( array( 'festapp.io' => scenecal_fixture( 'festapp-festival.html' ) ) );
		$events = ( new FestApp() )->parse( scenecal_festapp_venue(), scenecal_test_context() );

		scenecal_assert_same( 3, count( $events ), 'city-only location skipped' );

		$tent = $events[0];
		if ( class_exists( 'Normalizer' ) ) {
			scenecal_assert_same( 'Bell MTS Tent', $tent['venue_name'], 'math-italic folded and " - Winnipeg" stripped' );
		}
		scenecal_assert_same( 'Festival du Voyageur 2027', $tent['title'] );
		scenecal_assert_same( '2027-02-13', $tent['date'] );
		scenecal_assert_same( array( 'Performer B', 'Performer A' ), $tent['lineup'], 'running order' );
		scenecal_assert_same( '18:30', $tent['start_time'], 'trailing Z treated as local time' );
		scenecal_assert_same( '21:00', $tent['end_time'] );
		scenecal_assert_same( 'https://festapp.io/festival-du-voyageur', $tent['ticket_link'] );

		$snow = $events[1];
		scenecal_assert_same( 'Snow Stage', $snow['venue_name'], 'plain-string location' );
		scenecal_assert_same( array( 'Performer D', 'Performer A' ), $snow['lineup'] );
		scenecal_assert_same( '22:00', $snow['start_time'] );
		scenecal_assert_same( '00:30', $snow['end_time'], 'latest end, past midnight' );
	}
);

scenecal_test(
	'festapp: filters performances to the date range',
	static function () {
		scenecal_mock_http( array( 'festapp.io' => scenecal_fixture( 'festapp-festival.html' ) ) );
		$events = ( new FestApp() )->parse( scenecal_festapp_venue(), scenecal_test_context( '2027-02-01', '2027-02-28' ) );
		scenecal_assert_same( array( '2027-02-13', '2027-02-14' ), array_column( $events, 'date' ) );
	}
);

scenecal_test(
	'festapp: page without subEvent JSON-LD yields nothing; helpers',
	static function () {
		scenecal_mock_http( array( 'festapp.io' => '<html><head><script type="application/ld+json">{"@type":"Organization","name":"X"}</script></head></html>' ) );
		scenecal_assert_same( array(), ( new FestApp() )->parse( scenecal_festapp_venue(), scenecal_test_context() ) );

		scenecal_assert_same( '', FestApp::location_name( array( 'name' => 'winnipeg' ), 'Winnipeg' ) );
		scenecal_assert_same( 'Main Stage', FestApp::location_name( 'Main Stage - Brandon', 'Brandon' ) );
		scenecal_assert_same( 'Main Stage - Brandon', FestApp::location_name( 'Main Stage - Brandon', 'Winnipeg' ) );
		scenecal_assert_same( '2027-02-13 20:00', FestApp::local_datetime( '2027-02-13T20:00:00.000Z' )->format( 'Y-m-d H:i' ) );
		scenecal_assert_same( null, FestApp::local_datetime( '' ) );
		scenecal_assert( FestApp::matches_url( 'https://festapp.io/festival-du-voyageur' ) );
		scenecal_assert( ! FestApp::matches_url( 'https://example.com/festapp.io' ) );
	}
);
