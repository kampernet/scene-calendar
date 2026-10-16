<?php

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;
use SceneCalendar\Llm\Client;
use SceneCalendar\Llm\Conversation;
use SceneCalendar\Parsers\PosterVision;

/** Fake LLM client that records vision() calls and returns a canned response. */
function scenecal_fake_vision_llm( string $response ): Client {
	return new class( $response ) implements Client {
		public array $calls = array();
		private string $response;

		public function __construct( string $response ) {
			$this->response = $response;
		}
		public function provider(): string {
			return 'fake';
		}
		public function model(): string {
			return 'fake-vision';
		}
		public function complete( string $prompt, int $max_tokens = 8000 ): string {
			return '';
		}
		public function vision( string $prompt, string $image_base64, string $media_type, int $max_tokens = 8000 ): string {
			$this->calls[] = compact( 'prompt', 'image_base64', 'media_type' );
			return $this->response;
		}
		public function start( string $system, string $user, array $tools ): Conversation {
			throw new RuntimeException( 'not used' );
		}
		public function step( Conversation $conv, int $max_tokens = 8000 ): array {
			throw new RuntimeException( 'not used' );
		}
		public function add_tool_results( Conversation $conv, array $results ): void {
		}
		public function add_user_message( Conversation $conv, string $text ): void {
		}
	};
}

scenecal_test(
	'poster_vision: picks the tallest portrait image and maps the model JSON',
	static function () {
		$png       = scenecal_fixture( 'poster_vision-tiny.png' );
		$image_req = array();
		scenecal_mock_http(
			array(
				'Upcomim.png'            => static function ( $url ) use ( $png, &$image_req ) {
					$image_req[] = $url;
					return array(
						'body'    => $png,
						'headers' => array( 'Content-Type' => 'image/png' ),
					);
				},
				'osbornetaphouse.ca/live' => scenecal_fixture( 'poster_vision-osborne.html' ),
			)
		);
		$llm = scenecal_fake_vision_llm(
			"```json\n" . wp_json_encode(
				array(
					array(
						'date'         => '2026-10-23',
						'title'        => 'The Steamers',
						'lineup'       => 'The Steamers, Opening Act',
						'start_time'   => '9pm',
						'ticket_price' => '$10',
						'description'  => 'Rock and roll',
					),
					array(
						'date'  => '2026-10-24',
						'title' => 'Solo Artist',
					),
					array(
						'date'  => '2026-09-01',
						'title' => 'Before the window',
					),
					array( 'title' => 'No date' ),
				)
			) . "\n```"
		);
		$venue  = scenecal_test_venue( array( 'name' => 'Osborne Taphouse', 'url' => 'https://www.osbornetaphouse.ca/live' ) );
		$events = ( new PosterVision() )->parse( $venue, scenecal_test_context( '2026-10-01', '2027-12-31', $llm ) );

		scenecal_assert_same(
			array( 'https://images.squarespace-cdn.com/content/v1/68eaabf5f5c1cd06910a2baf/c6bc8a8d-3f23-47f7-b53f-33103ba32d38/Upcomim.png?format=1500w' ),
			$image_req,
			'portrait poster chosen, Squarespace size hint added'
		);
		scenecal_assert_same( 1, count( $llm->calls ) );
		scenecal_assert_same( 'image/png', $llm->calls[0]['media_type'] );
		scenecal_assert_same( base64_encode( $png ), $llm->calls[0]['image_base64'] );
		scenecal_assert( str_contains( $llm->calls[0]['prompt'], 'Osborne Taphouse, Winnipeg' ), 'prompt names the venue' );
		scenecal_assert( str_contains( $llm->calls[0]['prompt'], 'use year 2026, or 2027' ), 'prompt year hint' );

		scenecal_assert_same( 2, count( $events ) );
		$first = $events[0];
		scenecal_assert_same( '2026-10-23', $first['date'] );
		scenecal_assert_same( array( 'The Steamers', 'Opening Act' ), $first['lineup'] );
		scenecal_assert_same( '21:00', $first['start_time'] );
		scenecal_assert_same( '$10', $first['ticket_price'] );
		scenecal_assert_same( 'Rock and roll', $first['description'] );
		scenecal_assert_same( 'Osborne Taphouse', $first['venue_name'] );
		scenecal_assert_same( array( 'Solo Artist' ), $events[1]['lineup'], 'lineup defaults to title' );
		scenecal_assert_same( '', $events[1]['start_time'], 'no default weekday times' );
	}
);

scenecal_test(
	'poster_vision: direct poster_image_url skips page scan; no LLM means no events',
	static function () {
		$png = scenecal_fixture( 'poster_vision-tiny.png' );
		scenecal_mock_http( array( 'cdn.example.com/poster.jpg' => $png ) );
		$llm   = scenecal_fake_vision_llm( '{"events": [{"date": "2026-11-01", "title": "Wrapped"}]}' );
		$venue = scenecal_test_venue( array( 'poster_image_url' => 'https://cdn.example.com/poster.jpg' ) );

		$events = ( new PosterVision() )->parse( $venue, scenecal_test_context( '2026-10-01', '2027-12-31', $llm ) );
		scenecal_assert_same( array( 'Wrapped' ), array_column( $events, 'title' ), '{"events": [...]} wrapper accepted' );
		scenecal_assert_same( 'image/png', $llm->calls[0]['media_type'], 'sniffed from magic bytes' );

		scenecal_assert_same( array(), ( new PosterVision() )->parse( $venue, scenecal_test_context() ) );
	}
);

scenecal_test(
	'poster_vision: find_poster_url uses width/height attributes and returns "" without portraits',
	static function () {
		$html = '<img src="/wide.jpg" width="1200" height="600"><img src="/tall.jpg" width="800" height="1400"><img src="/taller-but-square.jpg" width="2000" height="2000">';
		scenecal_assert_same( 'https://venue.example.com/tall.jpg', PosterVision::find_poster_url( $html, 'https://venue.example.com/live' ) );
		scenecal_assert_same( '', PosterVision::find_poster_url( '<img src="/a.jpg" width="10" height="5">', 'https://venue.example.com/' ) );
	}
);
