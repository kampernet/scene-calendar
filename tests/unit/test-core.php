<?php
/**
 * Core tests: text helpers, normalization, venues, event storage, pipeline, dedup.
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;
use SceneCalendar\Pipeline\Dedup;
use SceneCalendar\Pipeline\Normalize;
use SceneCalendar\Pipeline\Processor;
use SceneCalendar\Util\Text;
use SceneCalendar\Venues;

function scenecal_seed_example_venues(): void {
	Venues::import_json( file_get_contents( SCENECAL_DIR . 'data/example-venues-winnipeg.json' ) );
}

function scenecal_venue_named( string $name ): array {
	foreach ( Venues::all() as $v ) {
		if ( $v['name'] === $name ) {
			return $v;
		}
	}
	throw new Scenecal_Assertion_Failed( "no venue $name" );
}

$noop = static function () {};

// -- Text -----------------------------------------------------------------------

scenecal_test(
	'text: normalize matches the Python scraper',
	static function () {
		scenecal_assert_same( 'the kings head', Text::normalize( "The King's Head!" ) );
		scenecal_assert_same( 'high and lonesome', Text::normalize( 'High & Lonesome' ) );
		scenecal_assert_same( 'times change', Text::normalize( 'Times Change(d)' ) );
		scenecal_assert_same( '', Text::normalize( null ) );
	}
);

scenecal_test(
	'text: event_key is identical to the Python generate_event_id',
	static function () {
		// Computed with scripts/v2/utils/dedup.py generate_event_id().
		scenecal_assert_same( '30a8276774f6', Text::event_key( "The King's Head Pub", '2027-01-01', 'Big Show! (Night 2)' ) );
		scenecal_assert_same( Text::event_key( 'the kings head pub', '2027-01-01', 'big show' ), Text::event_key( "The King's Head Pub", '2027-01-01', 'Big Show!' ) );
	}
);

scenecal_test(
	'text: parse_time handles 12h, 24h and labels',
	static function () {
		$cases = array(
			'20:00'          => '20:00',
			'8pm'            => '20:00',
			'8:30 PM'        => '20:30',
			'12am'           => '00:00',
			'12:15 pm'       => '12:15',
			'Show: 9:00 pm'  => '21:00',
			'Doors - 7pm'    => '19:00',
			'8 p.m.'         => '20:00',
			'25:00'          => '',
			'13pm'           => '',
			'tbd'            => '',
		);
		foreach ( $cases as $in => $out ) {
			scenecal_assert_same( $out, Text::parse_time( $in ), $in );
		}
	}
);

scenecal_test(
	'text: parse_date formats; a mismatched weekday does not shift the date',
	static function () {
		scenecal_assert_same( '2026-03-13', Text::parse_date( 'March 13, 2026' ) );
		scenecal_assert_same( '2026-03-13', Text::parse_date( 'Mar 13 2026' ) );
		scenecal_assert_same( '2026-03-13', Text::parse_date( '13 March 2026' ) );
		// March 13 2026 is a Friday; a wrong weekday must not move it.
		scenecal_assert_same( '2026-03-13', Text::parse_date( 'Monday, March 13, 2026' ) );
		scenecal_assert_same( '', Text::parse_date( '2026-02-30' ) );
		scenecal_assert_same( '', Text::parse_date( 'next friday' ) );
	}
);

scenecal_test(
	'text: infer_year rolls into next year',
	static function () {
		scenecal_assert_same( '2027-08-27', Text::infer_year( 8, 27, '2026-10-16' ) );
		scenecal_assert_same( '2026-10-20', Text::infer_year( 10, 20, '2026-10-16' ) );
	}
);

scenecal_test(
	'text: decode_llm_json tolerates fences and prose',
	static function () {
		scenecal_assert_same( array( 'a' ), Text::decode_llm_json( "```json\n[\"a\"]\n```" ) );
		scenecal_assert_same( array( 'x' => 1 ), Text::decode_llm_json( 'Here you go: {"x": 1} hope that helps' ) );
		scenecal_assert_same( null, Text::decode_llm_json( 'nope' ) );
	}
);

// -- Normalize ------------------------------------------------------------------

scenecal_test(
	'normalize: maps variants and coerces types',
	static function () {
		$e = Normalize::event(
			array(
				'venue'   => array( 'name' => 'Sidestage', 'city' => 'Winnipeg' ),
				'name'    => '  Big   Show ',
				'date'    => 'March 13, 2027',
				'time'    => '8pm',
				'artists' => 'A, B, A',
				'price'   => '$10',
				'url'     => 'https://t.example/x',
				'source'  => 'https://src.example/',
				'genre'   => 'Rock',
				'junk'    => 'dropped',
			)
		);
		scenecal_assert_same( 'Sidestage', $e['venue_name'] );
		scenecal_assert_same( 'Winnipeg', $e['venue_city'] );
		scenecal_assert_same( 'Big Show', $e['title'] );
		scenecal_assert_same( '2027-03-13', $e['date'] );
		scenecal_assert_same( '20:00', $e['start_time'] );
		scenecal_assert_same( array( 'A', 'B' ), $e['lineup'] );
		scenecal_assert_same( 'https://t.example/x', $e['ticket_link'] );
		scenecal_assert_same( array( 'https://src.example/' ), $e['source_urls'] );
		scenecal_assert_same( 'rock', $e['genre'] );
		scenecal_assert( ! isset( $e['junk'] ) );
	}
);

scenecal_test(
	'normalize: validate reports missing fields and range',
	static function () {
		scenecal_assert_same( array( 'missing venue_name', 'missing title', 'missing or unparseable date' ), Normalize::validate( Normalize::event( array() ) ) );
		$errors = Normalize::validate( Normalize::event( scenecal_raw_event( array( 'date' => '2020-01-01' ) ) ), '2026-01-01' );
		scenecal_assert( 1 === count( $errors ) && str_contains( $errors[0], 'before' ) );
	}
);

// -- Venues ---------------------------------------------------------------------

scenecal_test(
	'venues: canonicalize aliases (ported from test_venues.py)',
	static function () {
		scenecal_seed_example_venues();
		scenecal_assert_same( 'The Handsome Daughter', Venues::canonicalize( 'HD' ) );
		scenecal_assert_same( 'The Handsome Daughter', Venues::canonicalize( 'Handsome Daughter' ) );
		scenecal_assert_same( 'Park Alleys', Venues::canonicalize( 'The Park Alleys' ) );
		scenecal_assert_same( 'Times Changed High and Lonesome Club', Venues::canonicalize( 'Times Change(d)' ) );
		scenecal_assert_same( 'Times Changed High and Lonesome Club', Venues::canonicalize( 'The Times Changed High & Lonesome Club' ) );
		scenecal_assert_same( 'The Kings Head Pub', Venues::canonicalize( "The King's Head Pub" ) );
		scenecal_assert_same( 'West End Cultural Centre', Venues::canonicalize( 'wecc' ) );
		scenecal_assert_same( 'Some Random Bar', Venues::canonicalize( 'Some Random Bar' ) );
		foreach ( Venues::all() as $v ) {
			foreach ( $v['aliases'] as $alias ) {
				scenecal_assert_same( $v['name'], Venues::canonicalize( $alias ), "alias $alias" );
			}
		}
	}
);

scenecal_test(
	'venues: import keeps parser settings as meta and skips existing names',
	static function () {
		scenecal_seed_example_venues();
		$hd = scenecal_venue_named( 'The Handsome Daughter' );
		scenecal_assert_same( 'threecommon', $hd['parser'] );
		scenecal_assert_same( '667447a736fa859aaebb088b', $hd['3common_uid'] );
		scenecal_assert_same( 'always', $hd['agent_mode'] );
		$toad = scenecal_venue_named( 'The Toad in the Hole Pub' );
		scenecal_assert( str_contains( $toad['ical_url'], '%40group.calendar.google.com' ), 'percent-encoding survives: ' . $toad['ical_url'] );
		scenecal_assert_same( 0, Venues::import_json( file_get_contents( SCENECAL_DIR . 'data/example-venues-winnipeg.json' ) ) );
		$roundtrip = json_decode( Venues::export_json(), true );
		scenecal_assert_same( count( Venues::all() ), count( $roundtrip ) );
	}
);

scenecal_test(
	'registry: explicit, auto and none parser resolution',
	static function () {
		$r = SceneCalendar\Parsers\Registry::class;
		scenecal_assert( $r::for_venue( array( 'parser' => 'auto', 'url' => 'https://3common.com/u/x' ) ) instanceof SceneCalendar\Parsers\ThreeCommon );
		scenecal_assert( $r::for_venue( array( 'parser' => 'auto', 'url' => 'https://www.manitobamusic.com/livemusic.rss' ) ) instanceof SceneCalendar\Parsers\Rss );
		scenecal_assert( null === $r::for_venue( array( 'parser' => 'auto', 'url' => 'https://example.com/shows' ) ) );
		scenecal_assert( null === $r::for_venue( array( 'parser' => 'none', 'url' => 'https://3common.com/u/x' ) ) );
		scenecal_assert( $r::for_venue( array( 'parser' => 'squarespace', 'url' => 'https://www.wecc.ca/events' ) ) instanceof SceneCalendar\Parsers\Squarespace );
		scenecal_assert( count( $r::all() ) >= 13 );
		$fields = $r::config_fields();
		scenecal_assert( isset( $fields['3common_uid'], $fields['ical_url'], $fields['showpass_id'] ) );
	}
);

// -- Event storage --------------------------------------------------------------

scenecal_test(
	'events: upsert inserts, then reports seen, then enriches missing fields',
	static function () {
		$e = Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall' ) ) );
		$r = Events::upsert_scraped( $e, 'test' );
		scenecal_assert_same( 'inserted', $r['action'] );
		scenecal_assert_same( 'seen', Events::upsert_scraped( $e, 'test' )['action'] );
		$richer                 = $e;
		$richer['ticket_price'] = '$12';
		scenecal_assert_same( 'enriched', Events::upsert_scraped( $richer, 'test' )['action'] );
		scenecal_assert_same( '$12', Events::get( $r['id'] )['ticket_price'] );
	}
);

scenecal_test(
	'events: deleted scraped events are tombstoned and never re-added',
	static function () {
		$e  = Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall' ) ) );
		$id = Events::upsert_scraped( $e, 'test' )['id'];
		Events::delete( $id );
		scenecal_assert_same( 'deleted', Events::get( $id )['status'] );
		scenecal_assert_same( 'skipped', Events::upsert_scraped( $e, 'test' )['action'] );
		scenecal_assert_same( 'deleted', Events::get( $id )['status'] );
	}
);

scenecal_test(
	'events: an admin edit locks the event and the original key still matches',
	static function () {
		$e  = Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Orig Title' ) ) );
		$id = Events::upsert_scraped( $e, 'test' )['id'];

		$edited          = Events::get( $id );
		$edited['title'] = 'Fixed Title';
		scenecal_assert_same( $id, Events::save_manual( $edited, $id ) );
		$row = Events::get( $id );
		scenecal_assert( $row['locked'] );
		scenecal_assert( $row['event_key'] !== $row['origin_key'] );

		// The scraper sees the original title again: no new row, no overwrite.
		scenecal_assert_same( 'skipped', Events::upsert_scraped( $e, 'test' )['action'] );
		scenecal_assert_same( 'Fixed Title', Events::get( $id )['title'] );
	}
);

scenecal_test(
	'events: manual save rejects a venue/date/title collision; manual delete is permanent',
	static function () {
		$e   = Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall' ) ) );
		$id  = Events::save_manual( $e );
		$dup = Events::save_manual( $e );
		scenecal_assert( is_wp_error( $dup ) );
		Events::delete( $id );
		scenecal_assert( null === Events::get( $id ) );
	}
);

scenecal_test(
	'events: import_json reads the Python events.json format',
	static function () {
		$json = wp_json_encode(
			array(
				scenecal_raw_event( array( 'venue_name' => 'HD', 'title' => 'Imported', 'id' => 'whatever', 'genre' => 'rock' ) ),
				array( 'title' => 'no date' ),
			)
		);
		scenecal_seed_example_venues();
		list( $inserted, $skipped ) = Events::import_json( $json );
		scenecal_assert_same( array( 1, 1 ), array( $inserted, $skipped ) );
		$rows = Events::query( array( 'venue' => 'The Handsome Daughter' ) );
		scenecal_assert_same( 'Imported', $rows[0]['title'] );
		scenecal_assert_same( 'rock', $rows[0]['genre'] );
		scenecal_assert_same( 'import', $rows[0]['source'] );
	}
);

// -- Processor (ported from test_shows_pipeline.py) -----------------------------

scenecal_test(
	'processor: alias venue canonicalized; other venues and past dates dropped',
	static function () use ( $noop ) {
		scenecal_seed_example_venues();
		$venue = scenecal_venue_named( 'The Handsome Daughter' );
		$today = wp_date( 'Y-m-d' );
		$stats = Processor::run(
			array(
				scenecal_raw_event( array( 'venue_name' => 'HD', 'title' => 'Alias Show' ) ),
				scenecal_raw_event( array( 'venue_name' => 'The Royal Albert Arms', 'title' => 'Elsewhere' ) ),
				scenecal_raw_event( array( 'date' => '2020-01-01', 'title' => 'Old' ) ),
				scenecal_raw_event( array( 'venue_name' => '', 'title' => 'Backfilled' ) ),
			),
			$venue,
			$today,
			'',
			null,
			'test',
			$noop
		);
		scenecal_assert_same( 2, $stats['inserted'] );
		scenecal_assert_same( 2, $stats['invalid'] );
		$titles = array_column( Events::query( array( 'venue' => 'The Handsome Daughter' ) ), 'title' );
		sort( $titles );
		scenecal_assert_same( array( 'Alias Show', 'Backfilled' ), $titles );
	}
);

scenecal_test(
	'processor: promoter name replaced by venue from description, else Unknown',
	static function () use ( $noop ) {
		scenecal_seed_example_venues();
		$promoter = scenecal_venue_named( 'Real Love' );
		Processor::run(
			array(
				scenecal_raw_event( array( 'venue_name' => 'Real Love', 'title' => 'P1', 'description' => 'Presented by Real Love at The Handsome Daughter.' ) ),
				scenecal_raw_event( array( 'venue_name' => 'Real Love', 'title' => 'P2', 'description' => 'A great show.' ) ),
			),
			$promoter,
			wp_date( 'Y-m-d' ),
			'',
			null,
			'test',
			$noop
		);
		$by_title = array();
		foreach ( Events::query( array( 'venue_like' => '' ) ) as $e ) {
			$by_title[ $e['title'] ] = $e['venue_name'];
		}
		scenecal_assert_same( 'The Handsome Daughter', $by_title['P1'] );
		scenecal_assert_same( 'Unknown', $by_title['P2'] );
	}
);

scenecal_test(
	'processor: LLM-flagged candidates are not stored',
	static function () use ( $noop ) {
		scenecal_seed_example_venues();
		$venue = scenecal_venue_named( 'Sidestage' );
		$date  = gmdate( 'Y-m-d', strtotime( '+20 days' ) );
		Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Sidestage', 'title' => 'Band X with Band Y', 'date' => $date ) ) ), 'seed' );

		$llm   = new Scenecal_Fake_Llm(
			array(
				'duplicate event detector' => static function ( $prompt ) {
					preg_match_all( '/"id":"(c[0-9a-f]{12})","title":"([^"]+)"/', $prompt, $m, PREG_SET_ORDER );
					foreach ( $m as $match ) {
						if ( 'Band X + Band Y' === $match[2] ) {
							return wp_json_encode( array( $match[1] ) );
						}
					}
					return '[]';
				},
			)
		);
		$stats = Processor::run(
			array(
				scenecal_raw_event( array( 'venue_name' => 'Sidestage', 'title' => 'Band X + Band Y', 'date' => $date ) ),
				scenecal_raw_event( array( 'venue_name' => 'Sidestage', 'title' => 'Different Night', 'date' => $date ) ),
			),
			$venue,
			wp_date( 'Y-m-d' ),
			'',
			$llm,
			'Sidestage (agent)', // Inline LLM dedup applies to agent/promoter listings, not the venue's own parser.
			$noop
		);
		scenecal_assert_same( 1, $stats['duplicates'] );
		scenecal_assert_same( 1, $stats['inserted'] );
	}
);

// -- Dedup ----------------------------------------------------------------------

scenecal_test(
	'dedup: deterministic pre-pass and resolve keeps the locked/richest copy',
	static function () use ( $noop ) {
		$date = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		$a    = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'The Big Gig', 'date' => $date ) ) ), 'x' )['id'];
		$b    = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Unknown', 'title' => 'The Big Gig', 'date' => $date, 'description' => 'longer and richer description', 'ticket_link' => 'https://t.example' ) ) ), 'y' )['id'];
		$c    = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Another Act', 'date' => $date ) ) ), 'z' )['id'];

		$groups = Dedup::window_groups( null, Events::query( array( 'dates' => array( $date ) ) ), $noop );
		scenecal_assert_same( array( array( $a, $b ) ), $groups );

		// Known venue beats Unknown even when Unknown is richer.
		scenecal_assert_same( 1, Dedup::resolve( $groups, $noop ) );
		scenecal_assert_same( 'publish', Events::get( $a )['status'] );
		scenecal_assert_same( 'duplicate', Events::get( $b )['status'] );
		scenecal_assert_same( $a, Events::get( $b )['duplicate_of'] );
		scenecal_assert_same( 'publish', Events::get( $c )['status'] );
	}
);

scenecal_test(
	'dedup: LLM groups are merged transitively and locked events are never demoted',
	static function () use ( $noop ) {
		$date = gmdate( 'Y-m-d', strtotime( '+31 days' ) );
		$ids  = array();
		foreach ( array( 'One', 'Two', 'Three' ) as $t ) {
			$ids[] = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => "Show $t", 'date' => $date ) ) ), 'x' )['id'];
		}
		$locked          = Events::get( $ids[2] );
		$locked['title'] = 'Show Three (edited)';
		Events::save_manual( $locked, $ids[2] );

		$marked = Dedup::resolve( array( array( $ids[0], $ids[1] ), array( $ids[1], $ids[2] ) ), $noop );
		scenecal_assert_same( 2, $marked );
		scenecal_assert_same( 'publish', Events::get( $ids[2] )['status'] );
		scenecal_assert_same( 'duplicate', Events::get( $ids[0] )['status'] );
		scenecal_assert_same( 'duplicate', Events::get( $ids[1] )['status'] );
	}
);

scenecal_test(
	'dedup: plausible_pair pre-filter',
	static function () {
		$p = array( SceneCalendar\Pipeline\Dedup::class, 'plausible_pair' );
		scenecal_assert( $p( array( 'title' => 'Real Love presents: Sam Jacobs', 'lineup' => array() ), array( 'title' => 'Sam Jacobs w/ Double Life', 'lineup' => array() ) ) );
		scenecal_assert( $p( array( 'title' => 'Fall Bash', 'lineup' => array( 'Clipwing' ) ), array( 'title' => 'Clipwing album release', 'lineup' => array() ) ) );
		scenecal_assert( $p( array( 'title' => 'A', 'lineup' => array( 'Band X' ) ), array( 'title' => 'B', 'lineup' => array( 'band x' ) ) ) );
		scenecal_assert( ! $p( array( 'title' => 'Karaoke Night', 'lineup' => array() ), array( 'title' => 'Metal Monday: Skullsplitter', 'lineup' => array( 'Skullsplitter' ) ) ) );
		scenecal_assert( ! $p( array( 'title' => 'The Show', 'lineup' => array() ), array( 'title' => 'Live at the show', 'lineup' => array() ) ), 'stopwords only' );
	}
);

scenecal_test(
	'dedup: a venue-renamed show is still sent to the LLM (real Sidestage/Manitoba Music case)',
	static function () {
		$p      = array( SceneCalendar\Pipeline\Dedup::class, 'plausible_pair' );
		$venue  = array(
			'venue_name'  => 'Sidestage',
			'date'        => '2026-10-16',
			'title'       => 'Big Tones | OSANI + Break Dancing',
			'lineup'      => array( 'Big Tones', 'OSANI + Break Dancing' ),
			'description' => 'All Ages',
			'ticket_link' => 'https://www.ticketweb.ca/event/big-tones-osani-sidestage-tickets/14341474?pl=sidestage',
			'source_urls' => array( 'https://www.sidestagewpg.com/tm-event/raythenihilist-break-dancing/' ),
		);
		$promo  = array(
			'venue_name'  => 'Sidestage',
			'date'        => '2026-10-16',
			'title'       => 'Goodway Fridays: Raythenihilist',
			'lineup'      => array( 'Raythenihilist' ),
			'description' => 'Live music + break dance performances + ALL AGES',
			'ticket_link' => 'https://www.manitobamusic.com/livemusic/display,event/113081/goodway-fridays',
			'source_urls' => array(),
		);
		scenecal_assert( $p( $venue, $promo ), 'same venue + night' );
		// Even across a venue-name mismatch, the URL slug names the act.
		$promo['venue_name'] = 'Unknown';
		scenecal_assert( $p( $venue, $promo ), 'artist in the other event URL slug' );
		// Shared ticket link.
		scenecal_assert( $p( array( 'venue_name' => 'A', 'date' => '2026-10-16', 'title' => 'X', 'ticket_link' => 'https://t.example/e/1?ref=a' ), array( 'venue_name' => 'B', 'date' => '2026-10-17', 'title' => 'Y', 'ticket_link' => 'https://t.example/e/1/' ) ) );
	}
);

scenecal_test(
	'processor: a venue parser listing is stored even if it looks like a promoter copy (resolve keeps it)',
	static function () use ( $noop ) {
		scenecal_seed_example_venues();
		$venue = scenecal_venue_named( 'Sidestage' );
		$date  = gmdate( 'Y-m-d', strtotime( '+21 days' ) );
		$promo = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Sidestage', 'title' => 'Goodway Fridays: Act', 'date' => $date ) ) ), 'Manitoba Music (rss)' )['id'];
		$llm   = new Scenecal_Fake_Llm( array( '*' => static fn( $p ) => preg_match( '/"id":"(c[0-9a-f]{12})"/', $p, $m ) ? wp_json_encode( array( $m[1] ) ) : '[]' ) );
		$stats = Processor::run( array( scenecal_raw_event( array( 'venue_name' => 'Sidestage', 'title' => 'Act + Break Dancing', 'date' => $date ) ) ), $venue, wp_date( 'Y-m-d' ), '', $llm, 'Sidestage (ticketweb_list)', $noop );
		scenecal_assert_same( 1, $stats['inserted'] );
		$own = Events::query( array( 'venue' => 'Sidestage', 'dates' => array( $date ) ) );
		$own = array_values( array_filter( $own, static fn( $e ) => 'Sidestage (ticketweb_list)' === $e['found_by'] ) )[0];
		Dedup::resolve( array( array( $promo, $own['id'] ) ), $noop );
		scenecal_assert_same( 'publish', Events::get( $own['id'] )['status'] );
		scenecal_assert_same( 'duplicate', Events::get( $promo )['status'] );
	}
);

scenecal_test(
	'events: source ranking — the venue parser can correct an agent-found row; promoters cannot',
	static function () {
		scenecal_assert( Events::may_correct( 'Zed Hall (agent)', 'Zed Hall (ical)' ) );
		scenecal_assert( Events::may_correct( 'Zed Hall (jsonld)', 'Zed Hall (tribe_events)' ), 'parser switch' );
		scenecal_assert( ! Events::may_correct( 'Zed Hall (ical)', 'Zed Hall (agent)' ), 'agent never overrides a parser' );
		scenecal_assert( ! Events::may_correct( 'Zed Hall (ical)', 'Manitoba Music (rss)' ) );
		scenecal_assert( Events::may_correct( 'import', 'Zed Hall (ical)' ) === false, 'different label' );
	}
);

scenecal_test(
	'context: stated numeric offsets keep the venue wall time; Z/epoch convert to site time',
	static function () {
		$ctx = new SceneCalendar\Parsers\Context( '2026-01-01', '2027-12-31', new DateTimeZone( '-05:00' ) );
		scenecal_assert_same( '20:00', $ctx->to_local( '2026-11-20T20:00:00-06:00' )->format( 'H:i' ) );
		scenecal_assert_same( '20:00', $ctx->to_local( '2026-11-21T01:00:00Z' )->format( 'H:i' ) );
		scenecal_assert_same( '20:00', $ctx->to_local( (string) strtotime( '2026-11-21T01:00:00Z' ) )->format( 'H:i' ) );
	}
);
