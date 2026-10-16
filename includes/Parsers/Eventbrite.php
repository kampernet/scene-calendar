<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * Eventbrite organizer pages (eventbrite.ca/o/..., eventbrite.com/o/...).
 *
 * Tries, in order:
 *   1. schema.org JSON-LD Event objects (shared with the JsonLd parser);
 *   2. the Next.js page state (<script id="__NEXT_DATA__">, props.pageProps.upcomingEvents),
 *      which is where current organizer pages put their event list;
 *   3. HTML event cards (older page layouts).
 */
class Eventbrite extends Parser {

	public static function slug(): string {
		return 'eventbrite';
	}

	public static function label(): string {
		return 'Eventbrite';
	}

	public static function description(): string {
		return __( 'Venues and promoters selling on Eventbrite. Enter their Eventbrite organizer page as the URL, e.g. https://www.eventbrite.ca/o/your-venue-1234567890.', 'scene-calendar' );
	}

	public static function matches_url( string $url ): bool {
		return (bool) preg_match( '/(^|\.)eventbrite\.[a-z.]+$/', self::host( $url ) );
	}

	public function parse( array $venue, Context $ctx ): array {
		$url  = (string) ( $venue['url'] ?? '' );
		$html = Http::get( $url );

		$events = ( new JsonLd() )->extract( Html::json_ld( $html ), $venue, $ctx );
		if ( $events ) {
			$ctx->log( sprintf( '[eventbrite] Extracted %d events from JSON-LD', count( $events ) ) );
			return $events;
		}

		$next_data = self::next_data_events( $html );
		if ( null !== $next_data ) {
			$events = array();
			foreach ( $next_data as $item ) {
				try {
					$event = is_array( $item ) ? $this->convert_next_data( $item, $venue, $ctx ) : null;
					if ( $event ) {
						$events[] = $event;
					}
				} catch ( \Throwable $e ) {
					$ctx->log( '[eventbrite] Error parsing event: ' . $e->getMessage() );
				}
			}
			$ctx->log( sprintf( '[eventbrite] Extracted %d events from page data (%d listed)', count( $events ), count( $next_data ) ) );
			return $events;
		}

		$events = $this->parse_cards( $html, $url, $venue, $ctx );
		$ctx->log( sprintf( '[eventbrite] Extracted %d events from event cards', count( $events ) ) );
		return $events;
	}

	/** upcomingEvents from the Next.js page state, or null if absent. */
	public static function next_data_events( string $html ): ?array {
		if ( ! preg_match( '/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>([\s\S]*?)<\/script>/i', $html, $m ) ) {
			return null;
		}
		$data = json_decode( $m[1], true );
		$list = $data['props']['pageProps']['upcomingEvents'] ?? null;
		return is_array( $list ) ? $list : null;
	}

	private function convert_next_data( array $item, array $venue, Context $ctx ): ?array {
		if ( ! empty( $item['is_cancelled'] ) ) {
			return null;
		}
		$date = Text::parse_date( (string) ( $item['start_date'] ?? '' ) );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}
		$title = trim( html_entity_decode( (string) ( $item['name'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $title ) {
			return null;
		}

		// start_time/end_time are local to the event's timezone ("21:30:00").
		$start_time = Text::parse_time( (string) ( $item['start_time'] ?? '' ) );
		$end_time   = Text::parse_time( (string) ( $item['end_time'] ?? '' ) );
		if ( $end_time === $start_time ) {
			// Eventbrite fills end = start when the organizer gave no end time.
			$end_time = '';
		}

		$tickets = is_array( $item['ticket_availability'] ?? null ) ? $item['ticket_availability'] : array();
		$price   = '';
		if ( ! empty( $tickets['is_free'] ) ) {
			$price = 'Free';
		} else {
			$min = $tickets['minimum_ticket_price']['major_value'] ?? null;
			$max = $tickets['maximum_ticket_price']['major_value'] ?? null;
			if ( is_numeric( $min ) ) {
				$price = Text::format_price( $min );
				if ( is_numeric( $max ) && (float) $max > (float) $min ) {
					$price .= ' - ' . Text::format_price( $max );
				}
			}
		}

		$link       = (string) ( $item['url'] ?? '' );
		$venue_name = $venue['name'] ?? '';
		if ( 'promoter' === ( $venue['type'] ?? 'venue' ) && ! empty( $item['primary_venue']['name'] ) ) {
			$venue_name = (string) $item['primary_venue']['name'];
		}

		$event                 = $this->base_event( $venue );
		$event['venue_name']   = $venue_name;
		$event['date']         = $date;
		$event['title']        = $title;
		$event['description']  = Text::truncate( trim( (string) ( $item['summary'] ?? '' ) ) );
		$event['ticket_price'] = $price;
		$event['ticket_link']  = $link;
		$event['start_time']   = $start_time;
		$event['end_time']     = $end_time;
		$event['source_urls']  = '' !== $link ? array( $link ) : array( $venue['url'] ?? '' );
		return $event;
	}

	private function parse_cards( string $html, string $base_url, array $venue, Context $ctx ): array {
		$xp = Html::load( $html );

		$cards = array();
		foreach ( array(
			'//*[@data-testid="event-card"]',
			'//*[' . Html::cls( 'eds-event-card-content__content' ) . ']',
			'//*[' . Html::cls( 'event-card' ) . ']',
			'//article',
		) as $selector ) {
			$cards = Html::all( $xp, $selector );
			if ( $cards ) {
				break;
			}
		}

		$events = array();
		foreach ( $cards as $card ) {
			try {
				$event = $this->convert_card( $xp, $card, $base_url, $venue, $ctx );
				if ( $event ) {
					$events[] = $event;
				}
			} catch ( \Throwable $e ) {
				$ctx->log( '[eventbrite] Error parsing card: ' . $e->getMessage() );
			}
		}
		return $events;
	}

	private function convert_card( \DOMXPath $xp, \DOMElement $card, string $base_url, array $venue, Context $ctx ): ?array {
		$title_el = Html::first( $xp, './/h2|.//h3|.//*[' . Html::cls( 'eds-event-card-content__title' ) . ']|.//*[' . Html::cls( 'event-title' ) . ']', $card );
		$title    = Html::text( $title_el );
		if ( '' === $title ) {
			$title = Html::text( Html::first( $xp, './/a', $card ) );
		}
		if ( '' === $title ) {
			return null;
		}

		$date_el   = Html::first( $xp, './/*[' . Html::cls( 'eds-event-card-content__sub-title' ) . ']|.//*[' . Html::cls( 'event-date' ) . ']|.//time', $card );
		$date_text = '';
		if ( $date_el ) {
			$date_text = Html::attr( $date_el, 'datetime' );
			if ( '' === $date_text ) {
				$date_text = Html::text( $date_el );
			}
		}
		if ( '' === $date_text && preg_match( '/(\w+,?\s+\w+ \d{1,2},?\s*\d{4}|\d{4}-\d{2}-\d{2})/u', Html::text( $card ), $m ) ) {
			$date_text = $m[1];
		}
		$date = self::card_date( $date_text, $ctx->start_date );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$link = Html::attr( Html::first( $xp, './/a[@href]', $card ), 'href' );
		if ( '' !== $link ) {
			$link = Html::absolute_url( $link, $base_url );
		}

		$price    = '';
		$price_el = Html::first( $xp, './/*[' . Html::cls( 'eds-event-card-content__sub-content' ) . ']|.//*[' . Html::cls( 'price' ) . ']', $card );
		if ( $price_el && preg_match( '/\$[\d.]+|free|pwyc/i', Html::text( $price_el ), $m ) ) {
			$price = $m[0];
		}

		$event                 = $this->base_event( $venue );
		$event['date']         = $date;
		$event['title']        = $title;
		$event['ticket_price'] = $price;
		$event['ticket_link']  = $link;
		$event['source_urls']  = '' !== $link ? array( $link ) : array( $base_url );
		return $event;
	}

	/** Parse card date text; ISO datetimes, full dates, and year-less "Fri, Oct 16" forms. */
	public static function card_date( string $text, string $range_start ): string {
		$text = trim( $text );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})T/', $text, $m ) ) {
			return $m[1];
		}
		$date = Text::parse_date( $text );
		if ( '' !== $date ) {
			return $date;
		}
		// Drop a leading weekday: with no year, PHP would shift the date to match it.
		$text = preg_replace( '/^[A-Za-z]+,\s*/', '', $text );
		foreach ( array( 'F j', 'M j' ) as $format ) {
			$dt     = \DateTimeImmutable::createFromFormat( '!' . $format, $text );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $dt && ( false === $errors || 0 === $errors['warning_count'] + $errors['error_count'] ) ) {
				return Text::infer_year( (int) $dt->format( 'n' ), (int) $dt->format( 'j' ), $range_start );
			}
		}
		return '';
	}
}
