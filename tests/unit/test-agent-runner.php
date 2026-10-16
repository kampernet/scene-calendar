<?php
/**
 * Agent, LLM client wire formats, and the end-to-end runner (with a fake LLM).
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Agent\ShowsAgent;
use SceneCalendar\Events;
use SceneCalendar\Llm\Anthropic;
use SceneCalendar\Llm\Conversation;
use SceneCalendar\Llm\OpenAI;
use SceneCalendar\Runner;
use SceneCalendar\Settings;

scenecal_test(
	'agent: tool call, then submit_events; tool output reaches the model',
	static function () {
		scenecal_mock_http( array( 'example.com/shows' => '<html><body><h1>Shows</h1><p>Oct 30 - Band Z</p></body></html>' ) );
		$venue = scenecal_make_venue( array( 'name' => 'Agent Hall', 'url' => 'https://example.com/shows' ) );
		$llm   = new Scenecal_Fake_Llm(
			array(),
			array(
				array( 'tool_calls' => array( array( 'read_url', array( 'url' => 'https://example.com/shows' ) ) ) ),
				array( 'tool_calls' => array( array( 'submit_events', array( 'events' => array( array( 'venue_name' => 'Agent Hall', 'date' => '2026-10-30', 'title' => 'Band Z' ) ) ) ) ) ),
			)
		);
		$state = ShowsAgent::begin( $llm, $venue, '2026-10-16', '', '', 10 );
		scenecal_assert( str_contains( $llm->prompts[0], 'https://example.com/shows' ), 'task mentions the URL' );
		$state = ShowsAgent::advance( $llm, $state );
		scenecal_assert( ! $state['done'] );
		scenecal_assert( str_contains( $llm->tool_results[0]['content'], 'Band Z' ), 'read_url output passed back' );
		$state = ShowsAgent::advance( $llm, $state );
		scenecal_assert( $state['done'] );
		scenecal_assert_same( 'Band Z', $state['events'][0]['title'] );
	}
);

scenecal_test(
	'agent: step limit forces a submit nudge, then gives up',
	static function () {
		$venue = scenecal_make_venue( array( 'name' => 'Loop Hall', 'url' => 'https://example.com/loop' ) );
		$turns = array_fill( 0, 5, array( 'tool_calls' => array( array( 'existing_events', array( 'venue_name' => 'Loop Hall' ) ) ) ) );
		$llm   = new Scenecal_Fake_Llm( array(), $turns );
		$state = ShowsAgent::begin( $llm, $venue, '2026-10-16', '', '', 2 );
		$state = ShowsAgent::advance( $llm, $state );
		$state = ShowsAgent::advance( $llm, $state );
		scenecal_assert( $state['nudged'] && ! $state['done'] );
		$state = ShowsAgent::advance( $llm, $state );
		scenecal_assert( $state['done'] );
		scenecal_assert_same( array(), $state['events'] );
	}
);

scenecal_test(
	'agent: JSON text answer accepted when the model skips submit_events',
	static function () {
		$venue = scenecal_make_venue( array( 'name' => 'Text Hall', 'url' => 'https://example.com/t' ) );
		$llm   = new Scenecal_Fake_Llm( array(), array( array( 'text' => '```json\n{"events":[{"venue_name":"Text Hall","date":"2026-11-01","title":"T"}]}\n```' ) ) );
		$state = ShowsAgent::advance( $llm, ShowsAgent::begin( $llm, $venue, '2026-10-16', '', '', 5 ) );
		scenecal_assert( $state['done'] );
		scenecal_assert_same( 'T', $state['events'][0]['title'] );
	}
);

scenecal_test(
	'conversation: JSON round trip keeps empty objects as objects',
	static function () {
		$conv = Conversation::from_json( '{"provider":"anthropic","system":"s","tools":[],"messages":[{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"x","input":{}}]}]}' );
		scenecal_assert( str_contains( $conv->to_json(), '"input":{}' ), $conv->to_json() );
	}
);

scenecal_test(
	'anthropic client: request shape, tool_use parsing, tool_result turn',
	static function () {
		$sent = array();
		scenecal_mock_http(
			array(
				'api.anthropic.com' => static function ( $url, $args ) use ( &$sent ) {
					$sent[] = array(
						'headers' => $args['headers'],
						'body'    => json_decode( $args['body'], true ),
					);
					return wp_json_encode(
						array(
							'content'     => array(
								array( 'type' => 'thinking', 'thinking' => 'hmm', 'signature' => 'sig' ),
								array( 'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'read_url', 'input' => array( 'url' => 'https://a.example' ) ),
							),
							'stop_reason' => 'tool_use',
						)
					);
				},
			)
		);
		$client = new Anthropic( 'sk-ant-test', 'claude-haiku-4-5' );
		$conv   = $client->start( 'sys', 'hello', array( array( 'name' => 'read_url', 'description' => 'd', 'parameters' => array( 'type' => 'object', 'properties' => array( 'url' => array( 'type' => 'string' ) ) ) ) ) );
		$res    = $client->step( $conv );
		scenecal_assert_same( 'sk-ant-test', $sent[0]['headers']['x-api-key'] );
		scenecal_assert_same( '2023-06-01', $sent[0]['headers']['anthropic-version'] );
		scenecal_assert_same( 'sys', $sent[0]['body']['system'] );
		scenecal_assert_same( 'read_url', $sent[0]['body']['tools'][0]['name'] );
		scenecal_assert( isset( $sent[0]['body']['tools'][0]['input_schema'] ) );
		scenecal_assert_same( 'read_url', $res['tool_calls'][0]['name'] );
		scenecal_assert_same( array( 'url' => 'https://a.example' ), $res['tool_calls'][0]['arguments'] );

		$client->add_tool_results( $conv, array( array( 'id' => 'tu_1', 'content' => 'page text' ) ) );
		$client->add_user_message( $conv, 'finish now' );
		$client->step( Conversation::from_json( $conv->to_json() ) );
		$messages = $sent[1]['body']['messages'];
		scenecal_assert_same( 'thinking', $messages[1]['content'][0]['type'], 'thinking block round-trips' );
		scenecal_assert_same( 'tool_result', $messages[2]['content'][0]['type'] );
		scenecal_assert_same( 'text', $messages[2]['content'][1]['type'], 'nudge merged into the user turn' );
		scenecal_assert_same( 3, count( $messages ) );
	}
);

scenecal_test(
	'openai client: request shape, tool_calls parsing, tool messages',
	static function () {
		$sent = array();
		scenecal_mock_http(
			array(
				'/chat/completions' => static function ( $url, $args ) use ( &$sent ) {
					$sent[] = array(
						'url'     => $url,
						'headers' => $args['headers'],
						'body'    => json_decode( $args['body'], true ),
					);
					return wp_json_encode(
						array(
							'choices' => array(
								array(
									'message'       => array(
										'role'       => 'assistant',
										'content'    => null,
										'tool_calls' => array(
											array( 'id' => 'call_1', 'type' => 'function', 'function' => array( 'name' => 'existing_events', 'arguments' => '{"venue_name":"X"}' ) ),
										),
									),
									'finish_reason' => 'tool_calls',
								),
							),
						)
					);
				},
			)
		);
		$client = new OpenAI( 'sk-test', 'gpt-5-mini', 'https://api.openai.com/v1' );
		$conv   = $client->start( 'sys', 'hi', array( array( 'name' => 'existing_events', 'description' => 'd', 'parameters' => array( 'type' => 'object', 'properties' => array( 'venue_name' => array( 'type' => 'string' ) ) ) ) ) );
		$res    = $client->step( $conv );
		scenecal_assert_same( 'https://api.openai.com/v1/chat/completions', $sent[0]['url'] );
		scenecal_assert_same( 'Bearer sk-test', $sent[0]['headers']['Authorization'] );
		scenecal_assert_same( 'system', $sent[0]['body']['messages'][0]['role'] );
		scenecal_assert_same( 'function', $sent[0]['body']['tools'][0]['type'] );
		scenecal_assert( isset( $sent[0]['body']['max_completion_tokens'] ) );
		scenecal_assert_same( array( 'venue_name' => 'X' ), $res['tool_calls'][0]['arguments'] );
		$client->add_tool_results( $conv, array( array( 'id' => 'call_1', 'content' => 'none' ) ) );
		$client->step( Conversation::from_json( $conv->to_json() ) );
		$msgs = $sent[1]['body']['messages'];
		scenecal_assert_same( 'assistant', $msgs[2]['role'] );
		scenecal_assert_same( 'call_1', $msgs[2]['tool_calls'][0]['id'] );
		scenecal_assert_same( 'tool', $msgs[3]['role'] );
	}
);

scenecal_test(
	'llm errors carry the API message',
	static function () {
		scenecal_mock_http(
			array(
				'api.anthropic.com' => array(
					'status' => 401,
					'body'   => '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}',
				),
			)
		);
		try {
			( new Anthropic( 'bad', 'm' ) )->complete( 'x' );
			throw new Scenecal_Assertion_Failed( 'expected exception' );
		} catch ( SceneCalendar\Llm\LlmException $e ) {
			scenecal_assert( str_contains( $e->getMessage(), 'invalid x-api-key' ), $e->getMessage() );
		}
	}
);

scenecal_test(
	'settings: wp-config constant / env override beats the stored key',
	static function () {
		Settings::save( array_merge( Settings::all(), array( 'apify_api_key' => 'stored' ) ) );
		scenecal_assert_same( getenv( 'SCENECAL_APIFY_API_KEY' ) ?: 'stored', Settings::get( 'apify_api_key' ) );
		$clean = Settings::sanitize( array( 'provider' => 'anthropic', 'apify_api_key' => '' ) );
		scenecal_assert_same( 'stored', $clean['apify_api_key'], 'blank field keeps stored key' );
		$clean = Settings::sanitize( array( 'provider' => 'bogus', 'clear_apify_api_key' => '1' ) );
		scenecal_assert_same( '', $clean['apify_api_key'] );
		scenecal_assert_same( 'openai', $clean['provider'] );
	}
);

scenecal_test(
	'weekly schedule: next timestamp is the configured weekday and hour in site time',
	static function () {
		Settings::save( array_merge( Settings::all(), array( 'schedule_day' => 1, 'schedule_hour' => 3 ) ) );
		// Friday 2026-10-16 12:00 Winnipeg -> Monday 2026-10-19 03:00.
		$after = ( new DateTimeImmutable( '2026-10-16 12:00', wp_timezone() ) )->getTimestamp();
		$next  = SceneCalendar\Plugin::next_weekly_timestamp( $after );
		scenecal_assert_same( '2026-10-19 03:00 Mon', wp_date( 'Y-m-d H:i D', $next ) );
	}
);

scenecal_test(
	'runner: full run with parser venue + agent venue, dedup and genres',
	static function () {
		Settings::save( array_merge( Settings::all(), array( 'instagram_enabled' => false, 'purge_past_days' => 0 ) ) );
		$d1 = gmdate( 'Y-m-d', strtotime( '+5 days' ) );

		$ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;TZID=America/Winnipeg:" . str_replace( '-', '', $d1 ) . "T200000\r\nSUMMARY:Runner Test Band\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		scenecal_mock_http( array( 'runner.example/cal.ics' => $ics ) );

		$parser_venue = scenecal_make_venue( array( 'name' => 'Runner Pub', 'parser' => 'ical', 'url' => 'https://runner.example/cal.ics', 'agent_mode' => 'fallback' ) );
		$promoter     = scenecal_make_venue( array( 'name' => 'Gig Collective', 'type' => 'promoter', 'parser' => 'none', 'url' => 'https://runner.example/promo', 'agent_mode' => 'always' ) );

		scenecal_use_fake_llm(
			new Scenecal_Fake_Llm(
				array(
					'"candidates"'           => '[]',
					'on two consecutive dates' => static function ( $prompt ) {
						preg_match_all( '/"id":"(e\d+)","title":"Runner Test Band( - live)?"/', $prompt, $m );
						return count( $m[1] ) >= 2 ? wp_json_encode( array( $m[1] ) ) : '[]';
					},
					'extract the performing' => static function ( $prompt ) {
						preg_match_all( '/"id":"(e\d+)"/', $prompt, $m );
						return wp_json_encode( array_fill_keys( $m[1], array( 'Runner Test Band' ) ) );
					},
					'Classify EACH'          => static function ( $prompt ) {
						preg_match_all( '/"id":"(e\d+)"/', $prompt, $m );
						return wp_json_encode( array_fill_keys( $m[1], 'rock' ) );
					},
				),
				array(
					array( 'tool_calls' => array( array( 'submit_events', array( 'events' => array( array( 'venue_name' => 'Runner Pub', 'date' => $d1, 'title' => 'Runner Test Band - live', 'description' => 'Gig Collective presents, at Runner Pub', 'ticket_link' => 'https://tix.example/1' ) ) ) ) ) ),
				)
			)
		);

		$id = Runner::start( 'cli', array( $parser_venue['id'], $promoter['id'] ) );
		scenecal_assert( is_int( $id ), 'run started' );
		$guard = 0;
		while ( Runner::tick( 0 ) && ++$guard < 5 ) {
			// tick(0) runs to completion; loop only guards against lock contention.
		}
		$run = Runner::get_run( $id );
		scenecal_assert_same( 'done', $run['status'], $run['log'] );
		scenecal_assert_same( 2, $run['stats']['venues_done'] );
		scenecal_assert_same( 2, $run['stats']['inserted'], $run['log'] );

		$rows = Events::query( array( 'venue' => 'Runner Pub', 'status' => array( 'publish', 'duplicate' ) ) );
		scenecal_assert_same( 2, count( $rows ) );
		$statuses = array_column( $rows, 'status', 'title' );
		scenecal_assert_same( 1, count( array_keys( $statuses, 'duplicate', true ) ), wp_json_encode( $statuses ) );
		$kept = Events::query( array( 'venue' => 'Runner Pub' ) )[0];
		scenecal_assert_same( 'rock', $kept['genre'] );
		scenecal_assert( str_contains( $run['log'], 'parser ical: 1 events' ), $run['log'] );
		scenecal_assert( str_contains( $run['log'], 'agent submitted 1 events' ), $run['log'] );
	}
);

scenecal_test(
	'runner: a step that keeps failing is skipped after MAX_ATTEMPTS',
	static function () {
		$venue = scenecal_make_venue( array( 'name' => 'Crashy', 'parser' => 'none', 'agent_mode' => 'never' ) );
		$id    = Runner::start( 'cli', array( $venue['id'] ), array( 'skip_postprocess' => true ) );
		global $wpdb;
		// Simulate two ticks that died mid-step.
		$state             = Runner::get_run( $id )['state'];
		$state['attempts'] = 2;
		$wpdb->update( SceneCalendar\Db::runs(), array( 'state' => wp_json_encode( $state ) ), array( 'id' => $id ) );
		Runner::tick( 0 );
		$run = Runner::get_run( $id );
		scenecal_assert_same( 'done', $run['status'] );
		scenecal_assert( str_contains( $run['log'], 'skipping it' ), $run['log'] );
		scenecal_assert_same( 1, $run['stats']['errors'] );
	}
);

scenecal_test(
	'shortcode: renders list markup, filters and JSON-LD',
	static function () {
		$d = gmdate( 'Y-m-d', strtotime( '+3 days' ) );
		Events::upsert_scraped( SceneCalendar\Pipeline\Normalize::event( scenecal_raw_event( array( 'venue_name' => 'Zed Hall', 'title' => 'Shortcode <b>Show</b> We <3 "Punk"', 'date' => $d, 'start_time' => '20:00', 'ticket_link' => 'https://t.example/s' ) ) ), 'x' );
		$html = do_shortcode( '[scene_calendar venue="Zed Hall"]' );
		scenecal_assert( str_contains( $html, 'Shortcode Show We &lt;3 &quot;Punk&quot;' ), 'tags stripped, text kept and escaped' );
		scenecal_assert( str_contains( $html, 'data-filter="venue"' ) );
		scenecal_assert( str_contains( $html, '"@type":"MusicEvent"' ) );
		scenecal_assert( ! str_contains( $html, '<3 "Punk"' ), 'no raw text from data' );
		scenecal_assert( str_contains( do_shortcode( '[scene_calendar venue="Nowhere"]' ), 'No upcoming shows' ) );
	}
);
