<?php
/**
 * Search providers (request shape + response mapping) and same-source updates.
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Agent\Search;
use SceneCalendar\Agent\Tools;
use SceneCalendar\Events;
use SceneCalendar\Pipeline\Normalize;
use SceneCalendar\Settings;

function scenecal_search_settings( array $values ): void {
	Settings::save( array_merge( Settings::all(), $values ) );
}

scenecal_test(
	'search: brave sends the subscription token and maps web.results',
	static function () {
		scenecal_search_settings( array( 'search_provider' => 'brave', 'brave_api_key' => 'BSA-test' ) );
		$seen = array();
		scenecal_mock_http(
			array(
				'api.search.brave.com/res/v1/web/search' => static function ( $url, $args ) use ( &$seen ) {
					$seen = array( $url, $args['headers'] );
					return '{"web":{"results":[{"title":"Sidestage <b>shows</b>","url":"https://sidestagewpg.com/","description":"Upcoming <strong>gigs</strong>"}]}}';
				},
			)
		);
		scenecal_assert( Search::configured() );
		$out = Tools::search_web( 'sidestage winnipeg', 3 );
		scenecal_assert( str_contains( $seen[0], 'q=sidestage' ) && str_contains( $seen[0], 'count=3' ), $seen[0] );
		scenecal_assert_same( 'BSA-test', $seen[1]['X-Subscription-Token'] ?? '' );
		scenecal_assert( str_contains( $out, 'Sidestage shows' ) && str_contains( $out, 'URL: https://sidestagewpg.com/' ) && str_contains( $out, 'Upcoming gigs' ), $out );
	}
);

scenecal_test(
	'search: tavily posts JSON with a Bearer key and maps results',
	static function () {
		scenecal_search_settings( array( 'search_provider' => 'tavily', 'tavily_api_key' => 'tvly-test' ) );
		$seen = array();
		scenecal_mock_http(
			array(
				'api.tavily.com/search' => static function ( $url, $args ) use ( &$seen ) {
					$seen = array( json_decode( $args['body'], true ), $args['headers'] );
					return '{"results":[{"title":"King Cob events","url":"https://kingcob.example/events","content":"Live music Fridays"}]}';
				},
			)
		);
		$out = Tools::search_web( 'king cob pub live music', 4 );
		scenecal_assert_same( array( 'query' => 'king cob pub live music', 'max_results' => 4 ), $seen[0] );
		scenecal_assert_same( 'Bearer tvly-test', $seen[1]['Authorization'] ?? '' );
		scenecal_assert( str_contains( $out, 'King Cob events' ) && str_contains( $out, 'Live music Fridays' ), $out );
	}
);

scenecal_test(
	'search: searxng uses the configured instance with format=json; none disables search',
	static function () {
		scenecal_search_settings( array( 'search_provider' => 'searxng', 'searxng_url' => 'http://searx.local:8888' ) );
		$seen = '';
		scenecal_mock_http(
			array(
				'searx.local:8888/search' => static function ( $url ) use ( &$seen ) {
					$seen = $url;
					return '{"results":[{"title":"A","url":"https://a.example","content":"a"},{"title":"B","url":"https://b.example","content":"b"},{"title":"C","url":"https://c.example","content":"c"}]}';
				},
			)
		);
		$items = Search::query( 'x', 2 );
		scenecal_assert( str_contains( $seen, 'format=json' ), $seen );
		scenecal_assert_same( 2, count( $items ) );
		scenecal_search_settings( array( 'search_provider' => 'none' ) );
		scenecal_assert( ! Search::configured() );
		scenecal_assert_same( 'Error: web search is not configured.', Tools::search_web( 'x' ) );
	}
);

scenecal_test(
	'search: provider errors reach the model as tool output, keys redacted',
	static function () {
		scenecal_search_settings( array( 'search_provider' => 'google', 'google_api_key' => 'SECRETKEY123', 'google_cse_id' => 'cx1' ) );
		scenecal_mock_http( array( 'googleapis.com' => array( 'status' => 403, 'body' => 'quota exceeded' ) ) );
		$out = Tools::run( 'search_web', array( 'query' => 'x' ), '2026-10-16' );
		scenecal_assert( str_starts_with( $out, 'Error:' ) && str_contains( $out, 'quota exceeded' ), $out );
		scenecal_assert( ! str_contains( $out, 'SECRETKEY123' ), 'key redacted: ' . $out );
	}
);

scenecal_test(
	'events: the same source can correct a stored time; other sources only fill gaps',
	static function () {
		$e  = Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Tz Fix', 'start_time' => '19:00' ) ) );
		$id = Events::upsert_scraped( $e, 'Zed Hall (threecommon)' )['id'];

		$other               = $e;
		$other['start_time'] = '21:00';
		scenecal_assert_same( 'seen', Events::upsert_scraped( $other, 'Promoter (showpass)' )['action'] );
		scenecal_assert_same( '19:00', Events::get( $id )['start_time'] );

		$fixed               = $e;
		$fixed['start_time'] = '20:00';
		scenecal_assert_same( 'enriched', Events::upsert_scraped( $fixed, 'Zed Hall (threecommon)' )['action'] );
		scenecal_assert_same( '20:00', Events::get( $id )['start_time'] );
	}
);

scenecal_test(
	'timezone: a fixed UTC-5 site converts 3common winter shows to the real local time (Manitoba 2026)',
	static function () {
		scenecal_mock_http(
			array(
				'upcoming-events' => '{"events":[{"_id":"x1","name":"Winter Show","start":"2026-11-07T01:00:00.000Z","end":"2026-11-07T04:00:00.000Z","timezone":{"value":"America/Winnipeg","offset":300},"items":[]}],"pagination":{"pageCount":1}}',
			)
		);
		$ctx    = new SceneCalendar\Parsers\Context( '2026-10-01', '2027-12-31', new DateTimeZone( '-05:00' ) );
		$events = ( new SceneCalendar\Parsers\ThreeCommon() )->parse( scenecal_test_venue( array( '3common_uid' => 'u1', 'url' => 'https://3common.com/u/x' ) ), $ctx );
		scenecal_assert_same( '2026-11-06', $events[0]['date'] );
		scenecal_assert_same( '20:00', $events[0]['start_time'] );
	}
);

scenecal_test(
	'timezone: with a fixed-offset site, iCal TZID-local times stay as the venue wall time',
	static function () {
		$ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;TZID=America/Winnipeg:20261212T200000\r\nSUMMARY:December Gig\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		scenecal_mock_http( array( 'cal.example' => $ics ) );
		$ctx    = new SceneCalendar\Parsers\Context( '2026-10-01', '2027-12-31', new DateTimeZone( '-05:00' ) );
		$events = ( new SceneCalendar\Parsers\ICal() )->parse( scenecal_test_venue( array( 'url' => 'https://cal.example/x.ics' ) ), $ctx );
		scenecal_assert_same( '20:00', $events[0]['start_time'] );
	}
);

scenecal_test(
	'dedup: the venue own listing is kept over an aggregator copy, even if less detailed',
	static function () {
		$d   = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$own = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Own Listing', 'date' => $d, 'start_time' => '20:00' ) ) ), 'Zed Hall (threecommon)' )['id'];
		$agg = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Own Listing w/ friends', 'date' => $d, 'start_time' => '19:00', 'description' => 'long aggregator blurb', 'ticket_price' => '$10' ) ) ), 'Manitoba Music (rss)' )['id'];
		SceneCalendar\Pipeline\Dedup::resolve( array( array( $own, $agg ) ), static function () {} );
		scenecal_assert_same( 'publish', Events::get( $own )['status'] );
		scenecal_assert_same( 'duplicate', Events::get( $agg )['status'] );
	}
);

scenecal_test(
	'venues admin: parser test runs unsaved settings and flags missing required fields',
	static function () {
		scenecal_mock_http( array( 'upcoming-events' => '{"events":[{"_id":"a","name":"Form Test Show","start":"2026-11-20T02:00:00.000Z","items":[]}],"pagination":{"pageCount":1}}' ) );
		$ok = SceneCalendar\Admin\VenuesPage::run_parser_test( scenecal_test_venue( array( 'parser' => 'threecommon', '3common_uid' => 'abc', 'url' => 'https://3common.com/u/x' ) ) );
		scenecal_assert_same( '', $ok['error'] );
		scenecal_assert_same( 'Form Test Show', $ok['events'][0]['title'] );

		$missing = SceneCalendar\Admin\VenuesPage::run_parser_test( scenecal_test_venue( array( 'parser' => 'threecommon', 'url' => 'https://3common.com/u/x' ) ) );
		scenecal_assert( str_contains( implode( "\n", $missing['log'] ), 'Missing required setting' ) );

		$none = SceneCalendar\Admin\VenuesPage::run_parser_test( scenecal_test_venue( array( 'parser' => 'auto', 'url' => 'https://example.com/shows' ) ) );
		scenecal_assert( str_contains( $none['error'], 'No parser matched' ) );
	}
);
