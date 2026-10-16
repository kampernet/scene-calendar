<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * Squarespace event collections. Any Squarespace events page returns its data
 * as JSON when "?format=json" is appended.
 *
 * Two layouts are handled:
 *  - List view: {upcoming: [...], pagination: {nextPageUrl: "/events?offset=..."}}
 *  - Calendar view: {items: [...], monthFilter: ..., pagination: {nextPageUrl: "?month=november-2026&view=calendar"}}
 *    — fetched month by month from the window start.
 *
 * startDate/endDate are epoch milliseconds (UTC) and are converted with the
 * site timezone, so daylight saving time is handled correctly. Price, show time
 * and ticket link are read from the event body HTML.
 */
class Squarespace extends Parser {

	const MAX_LIST_PAGES      = 10;
	const MAX_CALENDAR_MONTHS = 6;

	/** Ticketing hosts recognized as ticket links in an event body, besides any URL containing "ticket". */
	const TICKET_HOSTS = array( 'thundertix', 'admitone', 'showpass', 'eventbrite', 'ticketweb', 'ticketmaster', 'universe.com', '3common', 'tixr', 'dice.fm', 'ticketscene', 'tickettailor' );

	public static function slug(): string {
		return 'squarespace';
	}

	public static function label(): string {
		return __( 'Squarespace events page', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'Venue websites built on Squarespace with an Events page (list or calendar layout). Enter the events page URL, e.g. https://example.com/events.', 'scene-calendar' );
	}

	public static function matches_url( string $url ): bool {
		return str_ends_with( self::host( $url ), 'squarespace.com' );
	}

	public function parse( array $venue, Context $ctx ): array {
		$url   = $venue['url'] ?? '';
		$first = Http::get_json( self::json_url( $url ) );
		if ( ! is_array( $first ) ) {
			$ctx->log( 'Squarespace JSON response was not an object' );
			return array();
		}

		$items = self::is_calendar_view( $first )
			? $this->fetch_calendar_months( $url, $first, $ctx )
			: $this->fetch_list_pages( $url, $first, $ctx );

		$ctx->log( sprintf( 'Fetched %d Squarespace items', count( $items ) ) );

		$events = array();
		$seen   = array();
		foreach ( $items as $item ) {
			$id = (string) ( $item['id'] ?? '' );
			if ( '' !== $id && isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			try {
				$event = $this->parse_item( $item, $venue, $url, $ctx );
				if ( $event ) {
					$events[] = $event;
				}
			} catch ( \Throwable $e ) {
				$ctx->log( 'Error parsing Squarespace item: ' . $e->getMessage() );
			}
		}
		return $events;
	}

	/** Add format=json to a URL, keeping any existing query string. */
	public static function json_url( string $url ): string {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'format=json';
	}

	private static function is_calendar_view( array $data ): bool {
		return array_key_exists( 'monthFilter', $data )
			|| str_contains( (string) ( $data['pagination']['nextPageUrl'] ?? '' ), 'month=' );
	}

	private static function page_items( array $data ): array {
		$items = $data['upcoming'] ?? null;
		if ( ! is_array( $items ) || ! $items ) {
			$items = $data['items'] ?? array();
		}
		return is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : array();
	}

	private function fetch_list_pages( string $url, array $data, Context $ctx ): array {
		$items = self::page_items( $data );
		$base  = self::collection_url( $url );
		for ( $page = 1; $page < self::MAX_LIST_PAGES; $page++ ) {
			$next = $data['pagination']['nextPageUrl'] ?? '';
			if ( empty( $data['pagination']['nextPage'] ) || ! is_string( $next ) || '' === $next ) {
				break;
			}
			$data = Http::get_json( self::json_url( self::resolve( $next, $base ) ) );
			if ( ! is_array( $data ) ) {
				break;
			}
			$items = array_merge( $items, self::page_items( $data ) );
		}
		return $items;
	}

	private function fetch_calendar_months( string $url, array $first, Context $ctx ): array {
		$items = self::page_items( $first );
		$base  = self::collection_url( $url );
		// The first response is the site's current month (monthFilter = its start, epoch ms);
		// fetch the following months explicitly.
		$shown = is_numeric( $first['monthFilter'] ?? null ) ? $ctx->to_local( (int) floor( $first['monthFilter'] / 1000 ) ) : null;
		$month = new \DateTimeImmutable( ( $shown ? $shown->format( 'Y-m' ) : substr( $ctx->start_date, 0, 7 ) ) . '-01' );

		for ( $i = 1; $i < self::MAX_CALENDAR_MONTHS; $i++ ) {
			$month = $month->modify( '+1 month' );
			if ( $month->format( 'Y-m-d' ) > $ctx->end_date ) {
				break;
			}
			$query = '?month=' . strtolower( $month->format( 'F-Y' ) ) . '&view=calendar';
			try {
				$data = Http::get_json( self::json_url( $base . $query ) );
			} catch ( \Throwable $e ) {
				$ctx->log( 'Squarespace month fetch failed: ' . $e->getMessage() );
				break;
			}
			if ( is_array( $data ) ) {
				$items = array_merge( $items, self::page_items( $data ) );
			}
		}
		return $items;
	}

	/** The collection URL without query string or fragment. */
	private static function collection_url( string $url ): string {
		return preg_replace( '/[?#].*$/', '', $url );
	}

	/** Resolve a nextPageUrl that may be "?query", "/path?query" or absolute. */
	private static function resolve( string $next, string $collection_url ): string {
		if ( str_starts_with( $next, '?' ) ) {
			return $collection_url . $next;
		}
		return Html::absolute_url( $next, $collection_url );
	}

	private function parse_item( array $item, array $venue, string $url, Context $ctx ): ?array {
		$title = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) ( $item['title'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( '' === $title || empty( $item['startDate'] ) || ! is_numeric( $item['startDate'] ) ) {
			return null;
		}

		$start = $ctx->to_local( (int) floor( $item['startDate'] / 1000 ) );
		if ( ! $start ) {
			return null;
		}
		$date = $start->format( 'Y-m-d' );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$body = self::parse_body( (string) ( $item['body'] ?? '' ) );

		$start_time = $body['start_time'];
		if ( '' === $start_time ) {
			$start_time = $start->format( 'H:i' );
			// Midnight start means "time not set" on Squarespace.
			if ( '00:00' === $start_time ) {
				$start_time = '';
			}
		}

		$end_time = '';
		if ( ! empty( $item['endDate'] ) && is_numeric( $item['endDate'] ) ) {
			$end = $ctx->to_local( (int) floor( $item['endDate'] / 1000 ) );
			if ( $end && '00:00' !== $end->format( 'H:i' ) ) {
				$end_time = $end->format( 'H:i' );
			}
		}

		$full_url = (string) ( $item['fullUrl'] ?? '' );
		$source   = '' !== $full_url ? Html::absolute_url( $full_url, $url ) : $url;

		$event                 = $this->base_event( $venue );
		$event['date']         = $date;
		$event['title']        = $title;
		$event['description']  = $body['description'];
		$event['ticket_price'] = $body['price'];
		$event['ticket_link']  = $body['ticket_link'];
		$event['start_time']   = $start_time;
		$event['end_time']     = $end_time;
		$event['source_urls']  = array( $source );
		return $event;
	}

	/**
	 * Extract price, show time, ticket link and a short description from an event body.
	 *
	 * @return array{price:string, start_time:string, ticket_link:string, description:string}
	 */
	public static function parse_body( string $html ): array {
		$out = array(
			'price'       => '',
			'start_time'  => '',
			'ticket_link' => '',
			'description' => '',
		);
		if ( '' === trim( $html ) ) {
			return $out;
		}

		if ( preg_match_all( '/<a\b[^>]*\bhref=["\']([^"\']+)["\']/i', $html, $links ) ) {
			foreach ( $links[1] as $href ) {
				$href  = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$lower = strtolower( $href );
				$match = str_contains( $lower, 'ticket' );
				foreach ( self::TICKET_HOSTS as $host ) {
					$match = $match || str_contains( $lower, $host );
				}
				if ( $match ) {
					$out['ticket_link'] = $href;
					break;
				}
			}
		}

		$text               = Html::to_text( $html );
		$out['price']       = self::extract_price( $text );
		$out['start_time']  = self::extract_time( $text );
		$out['description'] = self::extract_description( $text );
		return $out;
	}

	public static function extract_price( string $text ): string {
		if ( preg_match( '/\$\d+(?:\.\d{2})?(?:\s*[-\/]\s*\$?\d+(?:\.\d{2})?)?/', $text, $m ) ) {
			return $m[0];
		}
		$lower = strtolower( $text );
		if ( preg_match( '/\bfree\b/', $lower ) ) {
			return 'Free';
		}
		if ( str_contains( $lower, 'pwyc' ) || str_contains( $lower, 'pay what you can' ) ) {
			return 'PWYC';
		}
		return '';
	}

	/** "Show - 8:00pm" / "Show: 8pm", falling back to "Doors - 7pm". */
	public static function extract_time( string $text ): string {
		foreach ( array( 'show', 'doors?' ) as $label ) {
			if ( preg_match( '/\b' . $label . '\s*[-:]\s*(\d{1,2}(?::\d{2})?\s*[ap]\.?m\.?)/i', $text, $m ) ) {
				return Text::parse_time( $m[1] );
			}
		}
		return '';
	}

	/** First meaningful lines of the body, skipping price/time/ticket metadata lines. */
	public static function extract_description( string $text ): string {
		$parts = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( $line );
			if ( mb_strlen( $line ) <= 15 || preg_match( '/^(\$|doors?\s*[-:]|show\s*[-:]|all ages|tickets|buy tickets|on sale)/i', $line ) ) {
				continue;
			}
			$parts[] = $line;
			if ( mb_strlen( implode( ' ', $parts ) ) > 200 ) {
				break;
			}
		}
		return $parts ? Text::truncate( implode( ' ', $parts ) ) : '';
	}
}
