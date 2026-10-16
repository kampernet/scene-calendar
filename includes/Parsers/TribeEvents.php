<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * WordPress sites running The Events Calendar ("Tribe Events"), via its REST API:
 *
 *   GET https://<site>/wp-json/tribe/events/v1/events?start_date=YYYY-MM-DD
 *
 * Events come back with local start/end datetimes, so no timezone math is needed.
 */
class TribeEvents extends Parser {

	const PER_PAGE  = 50;
	const MAX_PAGES = 5;

	public static function slug(): string {
		return 'tribe_events';
	}

	public static function label(): string {
		return __( 'The Events Calendar (WordPress)', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'WordPress sites using The Events Calendar plugin (event pages usually live under /events/ or /event/). Enter any URL on the site; the plugin\'s REST API at /wp-json/tribe/events/v1/events is used.', 'scene-calendar' );
	}

	public function parse( array $venue, Context $ctx ): array {
		$parts = wp_parse_url( (string) ( $venue['url'] ?? '' ) );
		if ( empty( $parts['host'] ) ) {
			$ctx->log( '[tribe events] No valid URL configured, skipping' );
			return array();
		}
		$api_url = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/wp-json/tribe/events/v1/events';

		$query = array(
			'start_date' => $ctx->start_date,
			'per_page'   => self::PER_PAGE,
		);
		if ( '9999-12-31' !== $ctx->end_date ) {
			$query['end_date'] = $ctx->end_date . ' 23:59:59';
		}

		$events = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$query['page'] = $page;
			try {
				$data = Http::get_json( $api_url, array( 'query' => $query ) );
			} catch ( \Throwable $e ) {
				// The API answers 400 for a page past the last one.
				if ( 1 === $page ) {
					throw $e;
				}
				break;
			}

			$items = is_array( $data['events'] ?? null ) ? $data['events'] : array();
			if ( ! $items ) {
				break;
			}
			foreach ( $items as $item ) {
				try {
					$event = is_array( $item ) ? $this->convert( $item, $venue, $ctx ) : null;
					if ( $event ) {
						$events[] = $event;
					}
				} catch ( \Throwable $e ) {
					$ctx->log( '[tribe events] Error parsing event: ' . $e->getMessage() );
				}
			}

			if ( $page >= (int) ( $data['total_pages'] ?? 1 ) ) {
				break;
			}
		}

		$ctx->log( sprintf( '[tribe events] Extracted %d events', count( $events ) ) );
		return $events;
	}

	/** Strip tags, decode entities, collapse whitespace. */
	public static function clean( $text ): string {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}
		$text = preg_replace( '/<[^>]+>/', ' ', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	private function convert( array $item, array $venue, Context $ctx ): ?array {
		$title = self::clean( $item['title'] ?? '' );
		if ( '' === $title ) {
			return null;
		}

		// Local datetimes like "2026-09-05 17:00:00".
		$start = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) ( $item['start_date'] ?? '' ) );
		if ( ! $start ) {
			return null;
		}
		$date = $start->format( 'Y-m-d' );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$end_time = '';
		$end      = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) ( $item['end_date'] ?? '' ) );
		if ( $end && $end->format( 'Y-m-d' ) === $date ) {
			$end_time = $end->format( 'H:i' );
		}

		// "venue" is an object, a list of objects, or [] when unset.
		$venue_data = $item['venue'] ?? array();
		if ( is_array( $venue_data ) && array_is_list( $venue_data ) ) {
			$venue_data = $venue_data[0] ?? array();
		}
		if ( ! is_array( $venue_data ) ) {
			$venue_data = array();
		}

		$event_url  = (string) ( $item['url'] ?? '' );
		$venue_name = self::clean( $venue_data['venue'] ?? '' );
		$city       = self::clean( $venue_data['city'] ?? '' );
		$all_day    = ! empty( $item['all_day'] );

		$event                 = $this->base_event( $venue );
		$event['venue_name']   = '' !== $venue_name ? $venue_name : ( $venue['name'] ?? '' );
		$event['venue_city']   = '' !== $city ? $city : ( $venue['city'] ?? '' );
		$event['date']         = $date;
		$event['title']        = $title;
		$event['description']  = Text::truncate( self::clean( $item['description'] ?? '' ) );
		$event['ticket_price'] = self::clean( $item['cost'] ?? '' );
		$event['ticket_link']  = ! empty( $item['website'] ) && is_string( $item['website'] ) ? $item['website'] : $event_url;
		$event['start_time']   = $all_day ? '' : $start->format( 'H:i' );
		$event['end_time']     = $all_day ? '' : $end_time;
		$event['source_urls']  = '' !== $event_url ? array( $event_url ) : array();
		return $event;
	}
}
