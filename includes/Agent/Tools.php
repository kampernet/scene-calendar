<?php
namespace SceneCalendar\Agent;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Db;
use SceneCalendar\Settings;
use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * Tools available to the event-finding agent, plus the Instagram pre-fetch.
 *
 * Every tool returns a string (what the model sees) and never throws.
 */
final class Tools {

	const READ_URL_MAX_CHARS = 30000;

	/** Tool definitions (provider-neutral JSON Schema). */
	public static function definitions( bool $with_search ): array {
		$event_props = array(
			'venue_name'     => array(
				'type'        => 'string',
				'description' => 'Name of the physical venue hosting the show',
			),
			'venue_city'     => array( 'type' => 'string' ),
			'venue_province' => array(
				'type'        => 'string',
				'description' => 'Province/state code, e.g. MB',
			),
			'venue_country'  => array( 'type' => 'string' ),
			'date'           => array(
				'type'        => 'string',
				'description' => 'Show date, YYYY-MM-DD',
			),
			'title'          => array( 'type' => 'string' ),
			'description'    => array(
				'type'        => 'string',
				'description' => 'Brief description (one or two sentences)',
			),
			'lineup'         => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Performing artist/band names',
			),
			'ticket_price'   => array(
				'type'        => 'string',
				'description' => 'e.g. "$15", "PWYC", "Free", or "" if unknown',
			),
			'ticket_link'    => array( 'type' => 'string' ),
			'start_time'     => array(
				'type'        => 'string',
				'description' => '24-hour HH:MM, e.g. "20:00", or "" if unknown',
			),
			'end_time'       => array(
				'type'        => 'string',
				'description' => '24-hour HH:MM, or ""',
			),
			'source_urls'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Page URLs where the information was found',
			),
		);

		$tools = array(
			array(
				'name'        => 'read_url',
				'description' => 'Fetch a web page and return its content as compact markdown (links preserved). Use for venue calendars, event pages and ticketing pages. For showpass.com URLs use showpass_lookup instead.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'Absolute http(s) URL',
						),
					),
					'required'   => array( 'url' ),
				),
			),
			array(
				'name'        => 'showpass_lookup',
				'description' => 'Look up events from the Showpass API with correct local dates/times. Accepts a showpass.com event URL (https://www.showpass.com/<event-slug>/) or organizer/venue URL (https://www.showpass.com/o/<slug>/).',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array( 'type' => 'string' ),
					),
					'required'   => array( 'url' ),
				),
			),
			array(
				'name'        => 'existing_events',
				'description' => 'List upcoming events already in the calendar for a venue name (case-insensitive substring match). Use it to avoid submitting duplicates.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'venue_name' => array( 'type' => 'string' ),
					),
					'required'   => array( 'venue_name' ),
				),
			),
			array(
				'name'        => 'submit_events',
				'description' => 'Submit the final list of live music events found. Call exactly once when finished; pass an empty list if none were found. This ends the task.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'events' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => $event_props,
								'required'   => array( 'venue_name', 'date', 'title' ),
							),
						),
					),
					'required'   => array( 'events' ),
				),
			),
		);

		if ( $with_search ) {
			array_unshift(
				$tools,
				array(
					'name'        => 'search_web',
					'description' => 'Search the web. Returns titles, URLs and snippets.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array( 'type' => 'string' ),
							'count' => array(
								'type'        => 'integer',
								'description' => 'Results to return, max 10 (default 5)',
							),
						),
						'required'   => array( 'query' ),
					),
				)
			);
		}
		return $tools;
	}

	/** Dispatch a tool call by name. */
	public static function run( string $name, array $args, string $today ): string {
		try {
			switch ( $name ) {
				case 'search_web':
					return self::search_web( (string) ( $args['query'] ?? '' ), (int) ( $args['count'] ?? 5 ) );
				case 'read_url':
					return self::read_url( (string) ( $args['url'] ?? '' ) );
				case 'showpass_lookup':
					return self::showpass_lookup( (string) ( $args['url'] ?? '' ) );
				case 'existing_events':
					return self::existing_events( (string) ( $args['venue_name'] ?? '' ), $today );
				default:
					return "Unknown tool: $name";
			}
		} catch ( \Throwable $e ) {
			return 'Error: ' . $e->getMessage();
		}
	}

	public static function has_search(): bool {
		return Search::configured();
	}

	public static function search_web( string $query, int $count = 5 ): string {
		/**
		 * Replace the search provider entirely: return a string of results
		 * (title, URL, snippet per result).
		 */
		$custom = apply_filters( 'scene_calendar_search_web', null, $query, $count );
		if ( is_string( $custom ) ) {
			return $custom;
		}
		if ( ! Search::configured() ) {
			return 'Error: web search is not configured.';
		}
		$items = Search::query( $query, $count );
		if ( ! $items ) {
			return 'No search results found.';
		}
		$out = array();
		foreach ( $items as $i => $item ) {
			$out[] = ( $i + 1 ) . '. ' . $item['title'] . "\n   URL: " . $item['url'] . "\n   " . $item['snippet'];
		}
		return implode( "\n", $out );
	}

	public static function read_url( string $url ): string {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return 'Error: url must be an absolute http(s) URL.';
		}
		$content = Html::to_markdown( Http::get( $url, array( 'retries' => 1 ) ), $url );
		$length  = mb_strlen( $content );
		if ( $length > self::READ_URL_MAX_CHARS ) {
			$content = mb_substr( $content, 0, self::READ_URL_MAX_CHARS ) . "\n\n[Truncated from $length chars. Fetch a more specific sub-page (month view, single event) for the rest.]";
		}
		return $content;
	}

	public static function showpass_lookup( string $url ): string {
		$path  = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$parts = '' === $path ? array() : explode( '/', $path );
		if ( ! $parts ) {
			return "Could not parse showpass URL: $url";
		}

		if ( count( $parts ) >= 2 && in_array( $parts[0], array( 'o', 'venues' ), true ) ) {
			$venue = Http::get_json( 'https://www.showpass.com/api/public/venues/' . rawurlencode( $parts[1] ) . '/', array( 'query' => array( 'format' => 'json' ) ) );
			if ( empty( $venue['id'] ) ) {
				return "Could not resolve showpass venue for: {$parts[1]}";
			}
			// venue__in is the only filter the events API honours.
			$data    = Http::get_json(
				'https://www.showpass.com/api/public/events/',
				array(
					'query' => array(
						'venue__in' => (string) $venue['id'],
						'page_size' => '100',
						'format'    => 'json',
					),
				)
			);
			$results = $data['results'] ?? array();
			if ( ! $results ) {
				return "No events found for: {$parts[1]}";
			}
			return 'Found ' . count( $results ) . " events:\n\n" . implode( "\n\n---\n\n", array_map( array( self::class, 'format_showpass' ), $results ) );
		}

		$item = Http::get_json( 'https://www.showpass.com/api/public/events/' . rawurlencode( $parts[0] ) . '/', array( 'query' => array( 'format' => 'json' ) ) );
		return isset( $item['name'] ) ? self::format_showpass( $item ) : "No event found for slug: {$parts[0]}";
	}

	private static function format_showpass( array $item ): string {
		$lines = array( 'Title: ' . ( $item['name'] ?? '' ) );
		$start = $item['local_starts_on'] ?? $item['starts_on'] ?? '';
		if ( $start ) {
			$lines[] = 'Starts (local): ' . substr( $start, 0, 16 );
		}
		if ( ! empty( $item['venue']['name'] ) ) {
			$lines[] = 'Venue: ' . $item['venue']['name'];
		}
		if ( isset( $item['frontend_price'] ) ) {
			$lines[] = 'Price: ' . Text::format_price( $item['frontend_price'] );
		}
		if ( ! empty( $item['slug'] ) ) {
			$lines[] = 'Ticket Link: https://www.showpass.com/' . $item['slug'] . '/';
		}
		$desc = Text::truncate( Html::to_line( (string) ( $item['description'] ?? '' ) ), 300 );
		if ( '' !== $desc ) {
			$lines[] = 'Description: ' . $desc;
		}
		return implode( "\n", $lines );
	}

	public static function existing_events( string $venue_name, string $today ): string {
		global $wpdb;
		$table = Db::events();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT title, venue_name, event_date, start_time, lineup FROM $table WHERE event_date >= %s AND status IN ('publish','pending','hidden','deleted') AND venue_name LIKE %s ORDER BY event_date LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today,
				'%' . $wpdb->esc_like( trim( $venue_name ) ) . '%'
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return "No existing events found for '$venue_name'.";
		}
		$out = 'Found ' . count( $rows ) . " existing events:\n";
		foreach ( $rows as $i => $row ) {
			$lineup = json_decode( $row['lineup'], true );
			$out   .= ( $i + 1 ) . ". {$row['title']} | {$row['venue_name']} | {$row['event_date']}" . ( $row['start_time'] ? " {$row['start_time']}" : '' );
			$out   .= is_array( $lineup ) && $lineup ? ' | ' . implode( ', ', $lineup ) : '';
			$out   .= "\n";
		}
		return $out;
	}

	/**
	 * Fetch recent Instagram posts via the Apify instagram-scraper actor.
	 * Returns a text digest for the agent prompt, or '' when unavailable.
	 */
	public static function instagram_digest( string $username, int $limit ): string {
		$key      = (string) Settings::get( 'apify_api_key' );
		$username = trim( ltrim( $username, '@' ) );
		if ( '' === $key || '' === $username ) {
			return '';
		}
		$response = Http::request(
			'POST',
			'https://api.apify.com/v2/acts/apify~instagram-scraper/run-sync-get-dataset-items',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				),
				'body'    => wp_json_encode(
					array(
						'directUrls'   => array( "https://www.instagram.com/$username/" ),
						'resultsType'  => 'posts',
						'resultsLimit' => $limit,
					)
				),
				'timeout' => 170,
				'retries' => 0,
			)
		);
		$posts    = json_decode( $response['body'], true );
		if ( ! is_array( $posts ) || ! $posts ) {
			return '';
		}
		$out = 'Recent posts from @' . $username . ":\n\n";
		foreach ( $posts as $i => $post ) {
			$caption = trim( (string) ( $post['caption'] ?? '' ) );
			if ( '' === $caption ) {
				continue;
			}
			$out .= '--- Post ' . ( $i + 1 ) . " ---\n";
			$out .= ! empty( $post['timestamp'] ) ? 'Posted: ' . $post['timestamp'] . "\n" : '';
			$out .= ! empty( $post['url'] ) ? 'URL: ' . $post['url'] . "\n" : '';
			$out .= 'Caption: ' . mb_substr( $caption, 0, 1500 ) . "\n\n";
		}
		return $out;
	}
}
