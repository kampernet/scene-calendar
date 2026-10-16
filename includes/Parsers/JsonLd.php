<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * Generic schema.org structured data (JSON-LD) parser.
 *
 * Many venue sites, ticketing platforms and WordPress event plugins embed
 * <script type="application/ld+json"> blocks describing their events. This
 * parser reads every object whose @type ends in "Event" (Event, MusicEvent,
 * ComedyEvent, ...), including ones nested in @graph, ItemList/itemListElement,
 * mainEntity, or a Place/Organization's "event"/"events" property.
 */
class JsonLd extends Parser {

	/** Max nesting depth when walking structured data. */
	const MAX_DEPTH = 6;

	public static function slug(): string {
		return 'jsonld';
	}

	public static function label(): string {
		return __( 'Structured data (schema.org JSON-LD)', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'Any events page that embeds schema.org Event data (common on Squarespace, Wix, many WordPress event plugins and ticketing sites). Enter the events listing page as the URL. Try this before falling back to the AI agent.', 'scene-calendar' );
	}

	public function parse( array $venue, Context $ctx ): array {
		$url = (string) ( $venue['url'] ?? '' );
		if ( '' === $url ) {
			$ctx->log( '[jsonld] No URL configured, skipping' );
			return array();
		}

		$html   = Http::get( $url );
		$events = $this->extract( Html::json_ld( $html ), $venue, $ctx );
		$ctx->log( sprintf( '[jsonld] Extracted %d events from %s', count( $events ), $url ) );
		return $events;
	}

	/**
	 * Convert decoded JSON-LD objects to raw events (shared with other parsers).
	 *
	 * @param array[] $items Top-level JSON-LD objects (e.g. from Html::json_ld()).
	 */
	public function extract( array $items, array $venue, Context $ctx ): array {
		$found = array();
		foreach ( $items as $item ) {
			$this->collect( $item, $found, 0 );
		}

		$events = array();
		$seen   = array();
		foreach ( $found as $item ) {
			try {
				$event = $this->convert( $item, $venue, $ctx );
			} catch ( \Throwable $e ) {
				$ctx->log( '[jsonld] Error parsing event: ' . $e->getMessage() );
				continue;
			}
			if ( ! $event ) {
				continue;
			}
			// The same event is often repeated (e.g. in an ItemList and standalone).
			$key = $event['date'] . '|' . $event['start_time'] . '|' . $event['title'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$events[]     = $event;
		}
		return $events;
	}

	/** True if a JSON-LD @type (string or list) names an Event subtype. */
	public static function is_event_type( $type ): bool {
		foreach ( (array) $type as $t ) {
			if ( is_string( $t ) && str_ends_with( preg_replace( '#^.*[/:]#', '', $t ), 'Event' ) ) {
				return true;
			}
		}
		return false;
	}

	private function collect( $node, array &$found, int $depth ): void {
		if ( ! is_array( $node ) || $depth > self::MAX_DEPTH ) {
			return;
		}
		if ( array_is_list( $node ) ) {
			foreach ( $node as $child ) {
				$this->collect( $child, $found, $depth + 1 );
			}
			return;
		}
		if ( self::is_event_type( $node['@type'] ?? null ) ) {
			$found[] = $node;
			return;
		}
		foreach ( array( '@graph', 'itemListElement', 'item', 'mainEntity', 'event', 'events' ) as $key ) {
			if ( isset( $node[ $key ] ) ) {
				$this->collect( $node[ $key ], $found, $depth + 1 );
			}
		}
	}

	private function convert( array $item, array $venue, Context $ctx ): ?array {
		$status = (string) ( $item['eventStatus'] ?? '' );
		if ( '' !== $status && preg_match( '/EventCancelled$/', $status ) ) {
			return null;
		}

		$start = is_string( $item['startDate'] ?? null ) ? trim( $item['startDate'] ) : '';
		if ( '' === $start ) {
			return null;
		}
		$date_only = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start );
		$start_dt  = $ctx->to_local( $start );
		if ( ! $start_dt ) {
			return null;
		}
		$date = $start_dt->format( 'Y-m-d' );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$title = self::clean( $item['name'] ?? '' );
		if ( '' === $title ) {
			return null;
		}

		$end_time = '';
		$end      = is_string( $item['endDate'] ?? null ) ? trim( $item['endDate'] ) : '';
		if ( '' !== $end && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
			$end_dt = $ctx->to_local( $end );
			if ( $end_dt && $end_dt > $start_dt ) {
				$end_time = $end_dt->format( 'H:i' );
			}
		}

		list( $price, $offer_url ) = self::offers( $item['offers'] ?? null );
		$ticket_link               = is_string( $item['url'] ?? null ) && '' !== $item['url'] ? $item['url'] : $offer_url;

		$event                 = $this->base_event( $venue );
		$location_name         = self::location_name( $item['location'] ?? null );
		$event['venue_name']   = ( 'promoter' === ( $venue['type'] ?? 'venue' ) && '' !== $location_name ) ? $location_name : ( $venue['name'] ?? '' );
		$event['date']         = $date;
		$event['title']        = $title;
		$event['description']  = Text::truncate( Html::to_line( is_string( $item['description'] ?? null ) ? $item['description'] : '' ) );
		$event['lineup']       = self::performers( $item['performer'] ?? null );
		$event['ticket_price'] = $price;
		$event['ticket_link']  = $ticket_link;
		$event['start_time']   = $date_only ? '' : $start_dt->format( 'H:i' );
		$event['end_time']     = $end_time;
		$event['source_urls']  = '' !== $ticket_link ? array( $ticket_link ) : ( ! empty( $venue['url'] ) ? array( $venue['url'] ) : array() );
		return $event;
	}

	private static function clean( $s ): string {
		if ( ! is_string( $s ) ) {
			return '';
		}
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Name of a schema.org location (Place object, list of Places, or plain string). */
	public static function location_name( $location ): string {
		if ( is_string( $location ) ) {
			return self::clean( $location );
		}
		if ( is_array( $location ) && array_is_list( $location ) ) {
			$location = $location[0] ?? null;
		}
		return is_array( $location ) ? self::clean( $location['name'] ?? '' ) : '';
	}

	/** Performer names from a Person/MusicGroup object, a list of them, or strings. */
	public static function performers( $performer ): array {
		if ( null === $performer || '' === $performer ) {
			return array();
		}
		$list  = ( is_array( $performer ) && array_is_list( $performer ) ) ? $performer : array( $performer );
		$names = array();
		foreach ( $list as $p ) {
			$name = is_array( $p ) ? self::clean( $p['name'] ?? '' ) : self::clean( $p );
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Price string and URL from schema.org offers (Offer, AggregateOffer, or a list).
	 *
	 * @return array{0:string, 1:string}
	 */
	public static function offers( $offers ): array {
		if ( ! is_array( $offers ) || ! $offers ) {
			return array( '', '' );
		}
		$list   = array_is_list( $offers ) ? $offers : array( $offers );
		$prices = array();
		$url    = '';
		foreach ( $list as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			foreach ( array( 'price', 'lowPrice', 'highPrice' ) as $key ) {
				$v = $offer[ $key ] ?? null;
				if ( is_string( $v ) ) {
					$v = str_replace( array( '$', ',' ), '', $v );
				}
				if ( is_numeric( $v ) ) {
					$prices[] = (float) $v;
				}
			}
			if ( '' === $url && ! empty( $offer['url'] ) && is_string( $offer['url'] ) ) {
				$url = $offer['url'];
			}
		}
		if ( ! $prices ) {
			return array( '', $url );
		}
		$min   = min( $prices );
		$max   = max( $prices );
		$price = $min === $max ? Text::format_price( $min ) : Text::format_price( $min ) . ' - ' . Text::format_price( $max );
		return array( $price, $url );
	}
}
