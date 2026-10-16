<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * RSS 2.0 and Atom event feeds (e.g. a music association's live music feed).
 *
 * Feeds rarely carry a structured event date, so the show date is searched for
 * in the title, then the description (ISO, "October 16, 2026", "16 Oct 26",
 * "Friday, October 16"), falling back to pubDate/updated. The venue comes from
 * a "<h2>Venue</h2><p><b>Name</b>" block (Manitoba Music style) or an
 * "at Venue" / "@ Venue" phrase, else the configured venue/promoter name.
 */
class Rss extends Parser {

	public static function slug(): string {
		return 'rss';
	}

	public static function label(): string {
		return __( 'RSS / Atom feed', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'An RSS or Atom feed of events. Enter the feed URL (often ending in .rss, .xml or /feed).', 'scene-calendar' );
	}

	public static function matches_url( string $url ): bool {
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		// Not "/feed/": on WordPress that's the blog-post feed, not an events feed.
		return (bool) preg_match( '#\.(rss|xml|atom)$#', $path );
	}

	public function parse( array $venue, Context $ctx ): array {
		$url  = $venue['url'] ?? '';
		$body = Http::get( $url );

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			$ctx->log( 'Feed is not valid XML: ' . $url );
			return array();
		}

		$events = array();
		$items  = $xml->xpath( '//item' );
		if ( $items ) {
			foreach ( $items as $item ) {
				try {
					$event = $this->parse_rss_item( $item, $venue, $url, $ctx );
					if ( $event ) {
						$events[] = $event;
					}
				} catch ( \Throwable $e ) {
					$ctx->log( 'Error parsing feed item: ' . $e->getMessage() );
				}
			}
			return $events;
		}

		$xml->registerXPathNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
		foreach ( $xml->xpath( '//atom:entry' ) ?: array() as $entry ) {
			try {
				$event = $this->parse_atom_entry( $entry, $venue, $url, $ctx );
				if ( $event ) {
					$events[] = $event;
				}
			} catch ( \Throwable $e ) {
				$ctx->log( 'Error parsing feed entry: ' . $e->getMessage() );
			}
		}
		return $events;
	}

	private function parse_rss_item( \SimpleXMLElement $item, array $venue, string $feed_url, Context $ctx ): ?array {
		$title = trim( (string) $item->title );
		if ( '' === $title ) {
			return null;
		}
		$description = trim( (string) $item->description );
		$link        = trim( (string) $item->link );

		$date = self::extract_date( $title, $ctx->start_date );
		if ( '' === $date && '' !== $description ) {
			$date = self::extract_date( Html::to_line( $description ), $ctx->start_date );
		}
		if ( '' === $date && '' !== trim( (string) $item->pubDate ) ) {
			$local = $ctx->to_local( trim( (string) $item->pubDate ) );
			$date  = $local ? $local->format( 'Y-m-d' ) : '';
		}
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$plain      = '' !== $description ? Html::to_line( $description ) : '';
		$venue_name = self::venue_from_html( $description );
		if ( '' === $venue_name ) {
			$venue_name = self::venue_from_text( $title . ' ' . $plain );
		}

		$event                = $this->base_event( $venue );
		$event['venue_name']  = '' !== $venue_name ? $venue_name : ( $venue['name'] ?? '' );
		$event['date']        = $date;
		$event['title']       = $title;
		$event['description'] = Text::truncate( $plain );
		$event['ticket_link'] = $link;
		$event['start_time']  = self::extract_time( $title . ' ' . $plain );
		$event['source_urls'] = array( '' !== $link ? $link : $feed_url );
		return $event;
	}

	private function parse_atom_entry( \SimpleXMLElement $entry, array $venue, string $feed_url, Context $ctx ): ?array {
		$atom  = $entry->children( 'http://www.w3.org/2005/Atom' );
		$title = trim( (string) $atom->title );
		if ( '' === $title ) {
			return null;
		}
		$content = trim( (string) $atom->content );
		if ( '' === $content ) {
			$content = trim( (string) $atom->summary );
		}
		$link = '';
		if ( isset( $atom->link ) ) {
			$link = trim( (string) $atom->link->attributes()->href );
		}

		$plain = '' !== $content ? Html::to_line( $content ) : '';
		$date  = self::extract_date( $title, $ctx->start_date );
		if ( '' === $date && '' !== $plain ) {
			$date = self::extract_date( $plain, $ctx->start_date );
		}
		if ( '' === $date && '' !== trim( (string) $atom->updated ) ) {
			$local = $ctx->to_local( trim( (string) $atom->updated ) );
			$date  = $local ? $local->format( 'Y-m-d' ) : '';
		}
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$venue_name = self::venue_from_html( $content );

		$event                = $this->base_event( $venue );
		$event['venue_name']  = '' !== $venue_name ? $venue_name : ( $venue['name'] ?? '' );
		$event['date']        = $date;
		$event['title']       = $title;
		$event['description'] = Text::truncate( $plain );
		$event['ticket_link'] = $link;
		$event['source_urls'] = array( '' !== $link ? $link : $feed_url );
		return $event;
	}

	/**
	 * Find a show date in free text. Recognizes 2026-10-16, "October 16, 2026" /
	 * "Oct 16 2026", "16 Oct 26" / "16 October 2026", and year-less
	 * "Friday, October 16" (year inferred from $range_start). When several
	 * appear, the one earliest in the text is returned.
	 */
	public static function extract_date( string $text, string $range_start ): string {
		if ( '' === $text ) {
			return '';
		}
		// Collect every recognizable date with its offset; the earliest one in the text wins
		// (feeds typically lead with the show date, while later prose may mention other dates).
		$found = array();

		if ( preg_match_all( '/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all as $m ) {
				if ( checkdate( (int) $m[2][0], (int) $m[3][0], (int) $m[1][0] ) ) {
					$found[ $m[0][1] ] = $m[0][0];
				}
			}
		}
		if ( preg_match_all( '/\b([A-Za-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all as $m ) {
				$month = TicketWebList::month_number( $m[1][0] );
				if ( $month && checkdate( $month, (int) $m[2][0], (int) $m[3][0] ) ) {
					$found[ $m[0][1] ] = sprintf( '%04d-%02d-%02d', (int) $m[3][0], $month, (int) $m[2][0] );
				}
			}
		}
		if ( preg_match_all( '/\b(\d{1,2})\s+([A-Za-z]{3,9})\.?,?\s+(\d{4}|\d{2})\b/', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all as $m ) {
				$month = TicketWebList::month_number( $m[2][0] );
				$year  = strlen( $m[3][0] ) === 2 ? 2000 + (int) $m[3][0] : (int) $m[3][0];
				if ( $month && checkdate( $month, (int) $m[1][0], $year ) ) {
					$found[ $m[0][1] ] = sprintf( '%04d-%02d-%02d', $year, $month, (int) $m[1][0] );
				}
			}
		}
		if ( preg_match_all( '/\b(?:mon|tues?|wed(?:nes)?|thu(?:rs)?|fri|sat(?:ur)?|sun)[a-z]*,?\s+([A-Za-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?!,?\s*\d{4})/i', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all as $m ) {
				$month = TicketWebList::month_number( $m[1][0] );
				$date  = $month ? Text::infer_year( $month, (int) $m[2][0], $range_start ) : '';
				if ( '' !== $date && ! isset( $found[ $m[0][1] ] ) ) {
					$found[ $m[0][1] ] = $date;
				}
			}
		}

		if ( ! $found ) {
			return '';
		}
		ksort( $found );
		return reset( $found );
	}

	/** First "8pm" / "11:45AM" style time in the text, as HH:MM. */
	public static function extract_time( string $text ): string {
		return preg_match( '/\b(\d{1,2}(?::\d{2})?\s*(?:am|pm))\b/i', $text, $m ) ? Text::parse_time( $m[1] ) : '';
	}

	/** Venue from "<h2>Venue</h2><p><b>Name</b>..." markup. */
	public static function venue_from_html( string $html ): string {
		if ( preg_match( '/<h2>\s*Venue\s*<\/h2>\s*<p>\s*<b>([^<]+)<\/b>/i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return '';
	}

	/** Venue from "at Venue Name" / "@ Venue Name" phrases. */
	public static function venue_from_text( string $text ): string {
		if ( preg_match( '/(?:\bat|@)\s+([A-Z][A-Za-z\s\'&]+?)(?:\s*[,\-|]|\s+on\s+|\s*$)/', $text, $m ) ) {
			$name = trim( $m[1] );
			if ( strlen( $name ) > 3 ) {
				return $name;
			}
		}
		return '';
	}
}
