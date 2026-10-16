<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * Sites using the TicketWeb "Event Discovery" WordPress plugin in list view,
 * which server-renders every upcoming show as a `.tw-section` block.
 *
 * The block's date has no year ("27 Aug" / "Fri, Mar 13"), so the year is
 * inferred as the first matching date on or after the window start.
 */
class TicketWebList extends Parser {

	public static function slug(): string {
		return 'ticketweb_list';
	}

	public static function label(): string {
		return __( 'TicketWeb plugin (list view)', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'WordPress sites using the TicketWeb event plugin in list layout (show blocks with "Buy Tickets" / "More Info" buttons). Enter the page that lists the shows.', 'scene-calendar' );
	}

	const MAX_PAGES = 5;

	public function parse( array $venue, Context $ctx ): array {
		$url    = $venue['url'] ?? '';
		$events = array();
		$seen   = array();
		$found  = 0;

		// WordPress paginates the list (/page/2/); follow the "next" link.
		for ( $page = 1; '' !== $url && $page <= self::MAX_PAGES && ! isset( $seen[ $url ] ); $page++ ) {
			$seen[ $url ] = true;
			$xp           = Html::load( Http::get( $url ) );
			$blocks       = Html::all( $xp, '//*[' . Html::cls( 'tw-section' ) . ']' );
			if ( ! $blocks ) {
				break;
			}
			$found += count( $blocks );
			foreach ( $blocks as $block ) {
				try {
					$event = $this->parse_block( $xp, $block, $venue, $url, $ctx );
					if ( $event ) {
						$events[] = $event;
					}
				} catch ( \Throwable $e ) {
					$ctx->log( 'Error parsing event block: ' . $e->getMessage() );
				}
			}
			$next = Html::first( $xp, '//a[' . Html::cls( 'next' ) . ' and ' . Html::cls( 'page-numbers' ) . ']' );
			$url  = $next ? Html::absolute_url( Html::attr( $next, 'href' ), $url ) : '';
		}

		if ( ! $found ) {
			$ctx->log( 'No .tw-section event blocks found on ' . ( $venue['url'] ?? '' ) );
		} else {
			$ctx->log( sprintf( 'Found %d TicketWeb event blocks', $found ) );
		}
		return $events;
	}

	private function parse_block( \DOMXPath $xp, \DOMElement $block, array $venue, string $page_url, Context $ctx ): ?array {
		$title = Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-name' ) . ']', $block ) );
		if ( '' === $title ) {
			return null;
		}

		$date = self::parse_date( Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-event-date' ) . ']', $block ) ), $ctx->start_date );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$start_time = Text::parse_time( Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-event-time' ) . ']', $block ) ) );
		$door_time  = Text::parse_time( Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-event-door-time' ) . ']', $block ) ) );
		$price      = self::parse_price( Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-price' ) . ']', $block ) ) );
		$age        = Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-age-restriction' ) . ']', $block ) );

		$ticket_link = Html::attr( Html::first( $xp, './/a[' . Html::cls( 'tw-buy-tix-btn' ) . ']', $block ), 'href' );
		$info_link   = Html::attr( Html::first( $xp, './/a[' . Html::cls( 'tw-more-info-btn' ) . ']', $block ), 'href' );

		// Multi-act bills are pipe-separated in the title: "Band A | Band B | Band C".
		$lineup = array();
		if ( str_contains( $title, '|' ) ) {
			$lineup = array_values( array_filter( array_map( 'trim', explode( '|', $title ) ), 'strlen' ) );
		}

		$event                 = $this->base_event( $venue );
		$event['date']         = $date;
		$event['title']        = $title;
		$event['description']  = $age;
		$event['lineup']       = $lineup;
		$event['ticket_price'] = $price;
		$event['ticket_link']  = $ticket_link;
		$event['start_time']   = '' !== $start_time ? $start_time : $door_time;
		$event['source_urls']  = array( '' !== $info_link ? $info_link : $page_url );
		return $event;
	}

	/** Parse "27 Aug", "Aug 27", "Fri, Mar 13" (no year) into Y-m-d using the window start for the year. */
	public static function parse_date( string $text, string $range_start ): string {
		$month = 0;
		$day   = 0;
		// Tokens like "Fri", "Mar", "13" — weekdays are ignored, the month name and day number are kept.
		foreach ( preg_split( '/[\s,.@]+/', strtolower( trim( $text ) ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( ctype_digit( $token ) && (int) $token >= 1 && (int) $token <= 31 && ! $day ) {
				$day = (int) $token;
			} elseif ( ! $month ) {
				$month = self::month_number( $token );
			}
		}
		return ( $month && $day ) ? Text::infer_year( $month, $day, $range_start ) : '';
	}

	/** Month number for an English month name or abbreviation ("aug", "august", "sept"), else 0. */
	public static function month_number( string $name ): int {
		$months = array( 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec' );
		$prefix = substr( strtolower( $name ), 0, 3 );
		$index  = array_search( $prefix, $months, true );
		if ( false === $index ) {
			return 0;
		}
		// Reject words that merely start like a month ("marathon", "decade"); allow full names and "sept".
		$full = array( 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december' );
		$name = strtolower( $name );
		return ( strlen( $name ) <= 4 || str_starts_with( $full[ $index ], $name ) ) ? $index + 1 : 0;
	}

	/** "CAD18.23" -> "$18.23"; "CAD12.05 - CAD18.23" -> "$12.05 - $18.23"; unknown text passes through. */
	public static function parse_price( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( str_contains( $text, ' - ' ) ) {
			$parts = array_map( array( self::class, 'parse_single_price' ), array_map( 'trim', explode( ' - ', $text ) ) );
			if ( ! in_array( '', $parts, true ) ) {
				return implode( ' - ', $parts );
			}
		}
		$single = self::parse_single_price( $text );
		return '' !== $single ? $single : $text;
	}

	private static function parse_single_price( string $text ): string {
		$cleaned = preg_replace( '/^[A-Z]{3}\s*/', '', trim( $text ) );
		$cleaned = ltrim( $cleaned, '$' );
		return is_numeric( $cleaned ) ? Text::format_price( (float) $cleaned ) : '';
	}
}
