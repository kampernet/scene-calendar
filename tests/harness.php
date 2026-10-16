<?php
/**
 * Minimal test harness that runs inside a real WordPress (via `wp eval-file`).
 *
 *   wp eval-file wp-content/plugins/scene-calendar/tests/run.php [filter]
 *
 * Test files register cases with scenecal_test(); HTTP is mocked through the
 * core `pre_http_request` filter so parsers run against saved fixtures.
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

$GLOBALS['scenecal_tests']    = array();
$GLOBALS['scenecal_http_map'] = array();

function scenecal_test( string $name, callable $fn ): void {
	$GLOBALS['scenecal_tests'][ $name ] = $fn;
}

class Scenecal_Assertion_Failed extends Exception {}

function scenecal_assert( $condition, string $message = 'assertion failed' ): void {
	if ( ! $condition ) {
		throw new Scenecal_Assertion_Failed( $message );
	}
}

function scenecal_assert_same( $expected, $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		throw new Scenecal_Assertion_Failed(
			( $message ? "$message: " : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
		);
	}
}

/** Path to a fixture file under tests/fixtures/. */
function scenecal_fixture( string $name ): string {
	$path = __DIR__ . '/fixtures/' . $name;
	if ( ! is_readable( $path ) ) {
		throw new Scenecal_Assertion_Failed( "missing fixture $name" );
	}
	return file_get_contents( $path );
}

/**
 * Mock HTTP: map of URL substring => response body (string) or
 * ['status'=>int, 'body'=>string, 'headers'=>array]. First matching substring wins;
 * unmatched requests fail with a 599 so tests never hit the network.
 */
function scenecal_mock_http( array $map ): void {
	$GLOBALS['scenecal_http_map'] = $map;
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! defined( 'SCENECAL_TESTING' ) ) {
			return $pre;
		}
		foreach ( $GLOBALS['scenecal_http_map'] as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $response ) ) {
					$response = $response( $url, $args );
				}
				if ( is_string( $response ) ) {
					$response = array( 'body' => $response );
				}
				return array(
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $response['headers'] ?? array() ),
					'body'     => $response['body'] ?? '',
					'response' => array(
						'code'    => $response['status'] ?? 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
			'body'     => '',
			'response' => array(
				'code'    => 599,
				'message' => "unmocked URL in test: $url",
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

/**
 * Parser context for tests: fixed window, America/Chicago. (Not Winnipeg:
 * Manitoba dropped standard time in Nov 2026 and expectations would depend on
 * which timezone database version the test PHP ships.)
 */
function scenecal_test_context( string $start = '2026-10-01', string $end = '2027-12-31', $llm = null ): SceneCalendar\Parsers\Context {
	return new SceneCalendar\Parsers\Context( $start, $end, new DateTimeZone( 'America/Chicago' ), null, $llm );
}

/** Sample venue array as parsers receive it. */
function scenecal_test_venue( array $overrides = array() ): array {
	return array_merge(
		array(
			'name'     => 'Test Venue',
			'type'     => 'venue',
			'city'     => 'Winnipeg',
			'province' => 'MB',
			'country'  => 'Canada',
			'url'      => 'https://example.com/events',
			'instagram'=> '',
			'aliases'  => array(),
		),
		$overrides
	);
}

/**
 * Scripted LLM client for tests.
 *
 *  - complete(): each call pops the first handler in $complete whose key is a
 *    substring of the prompt (or '*'); a handler is a string or fn( $prompt ).
 *  - step(): pops the next scripted turn: ['text' => ..., 'tool_calls' => [[name, args], ...]].
 */
class Scenecal_Fake_Llm implements SceneCalendar\Llm\Client {
	public array $complete;
	public array $turns;
	public array $prompts = array();
	public array $tool_results = array();

	public function __construct( array $complete = array(), array $turns = array() ) {
		$this->complete = $complete;
		$this->turns    = $turns;
	}
	public function provider(): string {
		return 'fake';
	}
	public function model(): string {
		return 'fake-1';
	}
	public function complete( string $prompt, int $max_tokens = 8000 ): string {
		$this->prompts[] = $prompt;
		foreach ( $this->complete as $needle => $handler ) {
			if ( '*' === $needle || false !== strpos( $prompt, $needle ) ) {
				return is_callable( $handler ) ? (string) $handler( $prompt ) : (string) $handler;
			}
		}
		return '[]';
	}
	public function vision( string $prompt, string $image_base64, string $media_type, int $max_tokens = 8000 ): string {
		return $this->complete( $prompt, $max_tokens );
	}
	public function start( string $system, string $user, array $tools ): SceneCalendar\Llm\Conversation {
		$this->prompts[] = $user;
		return new SceneCalendar\Llm\Conversation( 'fake', $system, $tools, array( array( 'role' => 'user', 'content' => $user ) ) );
	}
	public function step( SceneCalendar\Llm\Conversation $conv, int $max_tokens = 8000 ): array {
		$turn  = array_shift( $this->turns ) ?? array( 'text' => '', 'tool_calls' => array() );
		$calls = array();
		foreach ( $turn['tool_calls'] ?? array() as $i => $call ) {
			$calls[] = array(
				'id'        => 'call_' . count( $conv->messages ) . "_$i",
				'name'      => $call[0],
				'arguments' => $call[1],
			);
		}
		$conv->messages[] = array( 'role' => 'assistant', 'content' => $turn['text'] ?? '', 'calls' => $calls );
		return array( 'text' => $turn['text'] ?? '', 'tool_calls' => $calls, 'stop' => ! $calls );
	}
	public function add_tool_results( SceneCalendar\Llm\Conversation $conv, array $results ): void {
		$this->tool_results = array_merge( $this->tool_results, $results );
		$conv->messages[]   = array( 'role' => 'tool', 'results' => $results );
	}
	public function add_user_message( SceneCalendar\Llm\Conversation $conv, string $text ): void {
		$conv->messages[] = array( 'role' => 'user', 'content' => $text );
	}
}

/** Install a fake client as the configured LLM for the current test. */
function scenecal_use_fake_llm( Scenecal_Fake_Llm $llm ): Scenecal_Fake_Llm {
	add_filter( 'scene_calendar_llm_client', static fn() => $llm );
	return $llm;
}

/** Create a venue row and return its hydrated array. */
function scenecal_make_venue( array $data ): array {
	$id = SceneCalendar\Venues::save(
		array_merge(
			array(
				'name'     => 'Test Venue',
				'type'     => 'venue',
				'city'     => 'Winnipeg',
				'province' => 'MB',
				'country'  => 'Canada',
				'enabled'  => true,
				'parser'   => 'none',
			),
			$data
		)
	);
	return SceneCalendar\Venues::get( $id );
}

/** Raw event with sensible defaults in the future. */
function scenecal_raw_event( array $overrides = array() ): array {
	return array_merge(
		array(
			'venue_name' => 'The Handsome Daughter',
			'date'       => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
			'title'      => 'Test Show',
			'lineup'     => array( 'Band A' ),
		),
		$overrides
	);
}
