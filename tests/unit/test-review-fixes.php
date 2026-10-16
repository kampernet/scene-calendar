<?php
/**
 * Regression tests for findings from the adversarial review.
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;
use SceneCalendar\Pipeline\Dedup;
use SceneCalendar\Pipeline\Normalize;
use SceneCalendar\Runner;
use SceneCalendar\Settings;
use SceneCalendar\Util\Http;

function scenecal_set_lock( string $value ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "REPLACE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('scenecal_lock', %s, 'no')", $value ) );
}

scenecal_test(
	'review B1: a lock orphaned by a fatal error does not stall the run; healthcheck resumes it',
	static function () {
		global $wpdb;
		$venue = scenecal_make_venue( array( 'name' => 'Orphan Hall', 'parser' => 'none', 'agent_mode' => 'never' ) );
		$id    = Runner::start( 'cli', array( $venue['id'] ), array( 'skip_postprocess' => true ) );
		$wpdb->update( SceneCalendar\Db::runs(), array( 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $id ) );
		scenecal_set_lock( 'deadproc0000|' . ( time() - 3600 ) );
		scenecal_assert( ! Runner::is_locked(), 'stale lock is not considered held' );
		Runner::healthcheck();
		scenecal_assert_same( 'done', Runner::get_run( $id )['status'] );
	}
);

scenecal_test(
	'review B2: a busy lock makes tick() return null (callers must not re-dispatch)',
	static function () {
		$venue = scenecal_make_venue( array( 'name' => 'Busy Hall', 'parser' => 'none', 'agent_mode' => 'never' ) );
		$id    = Runner::start( 'cli', array( $venue['id'] ) );
		scenecal_set_lock( 'liveproc0000|' . time() );
		scenecal_assert_same( null, Runner::tick( 0 ) );
		scenecal_assert_same( 'queued', Runner::get_run( $id )['status'], 'run untouched while another process works' );
	}
);

scenecal_test(
	'review S1: admin-configured LLM endpoints may be on localhost; other requests stay SSRF-safe',
	static function () {
		$seen = array();
		scenecal_mock_http(
			array(
				'127.0.0.1:11434' => static function ( $url, $args ) use ( &$seen ) {
					$seen[] = ! empty( $args['reject_unsafe_urls'] );
					return '{"ok":true}';
				},
			)
		);
		// pre_http_request runs before core's URL validation, so assert the flag instead.
		Http::request( 'GET', 'http://127.0.0.1:11434/v1/models', array( 'trusted' => true ) );
		Http::request( 'GET', 'http://127.0.0.1:11434/v1/models' );
		scenecal_assert_same( array( false, true ), $seen );
		scenecal_assert( false === wp_http_validate_url( 'http://127.0.0.1:11434/v1/models' ), 'core refuses loopback for safe requests' );
	}
);

scenecal_test(
	'review S2: lineup extraction skips locked events',
	static function () {
		$d  = gmdate( 'Y-m-d', strtotime( '+8 days' ) );
		$id = Events::save_manual( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'DJ Bob Night', 'date' => $d, 'lineup' => array() ) ) ) );
		$todo = Events::query( array( 'from' => wp_date( 'Y-m-d' ), 'lineup_missing' => true, 'unlocked' => true ) );
		scenecal_assert( ! in_array( $id, array_column( $todo, 'id' ), true ) );
	}
);

scenecal_test(
	'review S3: an editor re-publishing a duplicate is not demoted again',
	static function () {
		$d = gmdate( 'Y-m-d', strtotime( '+9 days' ) );
		$a = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Twin Show', 'date' => $d, 'description' => 'richer copy here' ) ) ), 'x' )['id'];
		$b = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Twin Show Encore', 'date' => $d, 'lineup' => array() ) ) ), 'y' )['id'];
		Dedup::resolve( array( array( $a, $b ) ), static function () {} );
		scenecal_assert_same( 'duplicate', Events::get( $b )['status'] );
		Events::set_status( $b, 'publish', null, true ); // What the admin row/bulk action does.
		Dedup::resolve( array( array( $a, $b ) ), static function () {} );
		scenecal_assert_same( 'publish', Events::get( $b )['status'] );
	}
);

scenecal_test(
	'review S4: saving a deleted event through the form keeps it deleted',
	static function () {
		$e  = Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Gone Show' ) ) );
		$id = Events::upsert_scraped( $e, 'x' )['id'];
		Events::delete( $id );
		$row          = Events::get( $id );
		$row['title'] = 'Gone Show (typo fixed)';
		Events::save_manual( $row, $id, $row['status'] );
		scenecal_assert_same( 'deleted', Events::get( $id )['status'] );
	}
);

scenecal_test(
	'review S5: over-long city / ticket link no longer drop the event silently',
	static function () {
		$e = Normalize::event(
			scenecal_raw_event(
				array(
					'venue_name'  => 'Zed Hall',
					'title'       => 'Long Fields',
					'venue_city'  => str_repeat( 'c', 120 ),
					'ticket_link' => 'https://l.facebook.com/l.php?u=' . str_repeat( 'x', 2100 ),
				)
			)
		);
		$r = Events::upsert_scraped( $e, 'x' );
		scenecal_assert_same( 'inserted', $r['action'] );
		$row = Events::get( $r['id'] );
		scenecal_assert_same( 100, mb_strlen( $row['venue_city'] ) );
		scenecal_assert_same( '', $row['ticket_link'] );
	}
);

scenecal_test(
	'review S8: agent events can be held for review and are not re-added or published by later runs',
	static function () {
		$e = Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Agent Found' ) ) );
		$r = Events::upsert_scraped( $e, 'agent', 'pending' );
		scenecal_assert_same( 'pending', Events::get( $r['id'] )['status'] );
		// The agent finding it again next week keeps it pending.
		scenecal_assert( in_array( Events::upsert_scraped( $e, 'agent', 'pending' )['action'], array( 'seen', 'enriched' ), true ) );
		scenecal_assert_same( 'pending', Events::get( $r['id'] )['status'] );
		scenecal_assert( ! str_contains( do_shortcode( '[scene_calendar venue="Zed Hall"]' ), 'Agent Found' ), 'pending is not public' );
	}
);

scenecal_test(
	'review nit: venue ids resolve for venues saved after the cache was built',
	static function () {
		SceneCalendar\Venues::id_for_name( 'warm the cache' );
		$v = scenecal_make_venue( array( 'name' => 'Brand New Room' ) );
		scenecal_assert_same( $v['id'], SceneCalendar\Venues::id_for_name( 'Brand New Room' ) );
	}
);

scenecal_test(
	'review nit: capitalized and headliner field variants normalize',
	static function () {
		$e = Normalize::event( array( 'Venue' => 'Zed Hall', 'Title' => 'Caps', 'Date' => '2027-01-02', 'ticketLink' => 'https://t.example/c', 'artist_or_headliner' => 'Head', 'supporting_artists' => array( 'Support' ) ) );
		scenecal_assert_same( 'Zed Hall', $e['venue_name'] );
		scenecal_assert_same( 'Caps', $e['title'] );
		scenecal_assert_same( 'https://t.example/c', $e['ticket_link'] );
		scenecal_assert_same( array( 'Head', 'Support' ), $e['lineup'] );
	}
);

scenecal_test(
	'review round 2: an editor un-duplicating an event does not flip which one is the duplicate',
	static function () {
		$d    = gmdate( 'Y-m-d', strtotime( '+12 days' ) );
		$a    = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Show One', 'date' => $d, 'description' => 'richer' ) ) ), 'x' )['id'];
		$b    = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Show One Encore', 'date' => $d ) ) ), 'y' )['id'];
		$noop = static function () {};
		Dedup::resolve( array( array( $a, $b ) ), $noop );
		scenecal_assert_same( 'duplicate', Events::get( $b )['status'] );
		// What the Publish row/bulk action does for a duplicate.
		$ref = new ReflectionMethod( SceneCalendar\Admin\EventsPage::class, 'apply' );
		$ref->setAccessible( true );
		$ref->invoke( null, 'publish', array( $b ) );
		Dedup::resolve( array( array( $a, $b ) ), $noop );
		scenecal_assert_same( 'publish', Events::get( $a )['status'], 'original stays published' );
		scenecal_assert_same( 'publish', Events::get( $b )['status'], 'un-duplicated stays published' );
	}
);

scenecal_test(
	'review round 2: a failed lineup call leaves events eligible for the next run',
	static function () {
		$id  = Events::upsert_scraped( Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Band Q', 'lineup' => array() ) ) ), 'x' )['id'];
		$bad = new class() extends Scenecal_Fake_Llm {
			public function complete( string $prompt, int $max_tokens = 8000 ): string {
				throw new SceneCalendar\Llm\LlmException( 'HTTP 429 rate limited' );
			}
		};
		SceneCalendar\Pipeline\Classify::lineups( $bad, array( Events::get( $id ) ), static function () {} );
		$todo = Events::query( array( 'lineup_missing' => true ) );
		scenecal_assert( in_array( $id, array_column( $todo, 'id' ), true ), 'still eligible after failure' );

		$ok = new Scenecal_Fake_Llm( array( '*' => wp_json_encode( array( "e$id" => array() ) ) ) );
		SceneCalendar\Pipeline\Classify::lineups( $ok, array( Events::get( $id ) ), static function () {} );
		$todo = Events::query( array( 'lineup_missing' => true ) );
		scenecal_assert( ! in_array( $id, array_column( $todo, 'id' ), true ), 'answered with no artists: not re-sent' );
	}
);

scenecal_test(
	'review round 2: a parser confirming a pending agent event publishes it',
	static function () {
		$e = Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Confirmed Later' ) ) );
		$r = Events::upsert_scraped( $e, 'agent', 'pending' );
		$e['ticket_link'] = 'https://t.example/confirmed';
		scenecal_assert_same( 'enriched', Events::upsert_scraped( $e, 'parser', 'publish' )['action'] );
		$row = Events::get( $r['id'] );
		scenecal_assert_same( 'publish', $row['status'] );
		scenecal_assert_same( 'https://t.example/confirmed', $row['ticket_link'] );
	}
);

scenecal_test(
	'review round 2: a tick whose lock is taken over stops without writing state',
	static function () {
		global $wpdb;
		$venue = scenecal_make_venue( array( 'name' => 'Thief Hall', 'parser' => 'ical', 'url' => 'https://thief.example/cal.ics', 'agent_mode' => 'never' ) );
		$id    = Runner::start( 'cli', array( $venue['id'] ), array( 'skip_postprocess' => true ) );
		// While the parser is fetching, another process takes over the lock.
		scenecal_mock_http(
			array(
				'thief.example' => static function () use ( $wpdb ) {
					$wpdb->query( "UPDATE {$wpdb->options} SET option_value = 'thief|" . time() . "' WHERE option_name = 'scenecal_lock'" );
					return "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";
				},
			)
		);
		$result = Runner::tick( 0 );
		scenecal_assert_same( null, $result );
		scenecal_assert( 'done' !== Runner::get_run( $id )['status'], 'thief owns the run; we did not finish it' );
		scenecal_assert( str_starts_with( (string) get_option( 'scenecal_lock' ), 'thief|' ), 'our finally did not delete the thief\'s lock' );
	}
);
