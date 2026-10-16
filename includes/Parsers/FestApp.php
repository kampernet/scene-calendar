<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;

/**
 * festapp.io festival pages.
 *
 * The page's JSON-LD holds the festival with a subEvent per performance. We
 * group performances by (stage/tent, date) and emit one event per stage per
 * day, with all performers in the lineup in running order.
 */
class FestApp extends Parser {

	public static function slug(): string {
		return 'festapp';
	}

	public static function label(): string {
		return 'FestApp';
	}

	public static function description(): string {
		return __( 'Festivals that publish their schedule on festapp.io. Enter the festival\'s FestApp page as the URL. Produces one event per stage per day.', 'scene-calendar' );
	}

	public static function matches_url( string $url ): bool {
		$host = self::host( $url );
		return 'festapp.io' === $host || str_ends_with( $host, '.festapp.io' );
	}

	public function parse( array $venue, Context $ctx ): array {
		$url  = (string) ( $venue['url'] ?? '' );
		$html = Http::get( $url );

		$festival = null;
		foreach ( Html::json_ld( $html ) as $block ) {
			if ( isset( $block['subEvent'] ) && is_array( $block['subEvent'] ) ) {
				$festival = $block;
				break;
			}
		}
		if ( ! $festival ) {
			$ctx->log( '[festapp] No subEvent data found in JSON-LD' );
			return array();
		}

		$festival_name = trim( (string) ( $festival['name'] ?? '' ) );
		$sub_events    = $festival['subEvent'];
		$ctx->log( sprintf( '[festapp] Found %d performances in JSON-LD', count( $sub_events ) ) );

		$city   = trim( (string) ( $venue['city'] ?? '' ) );
		$groups = array();

		foreach ( $sub_events as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			try {
				$location = self::location_name( $sub['location'] ?? null, $city );
				if ( '' === $location ) {
					continue;
				}

				$performer = trim( (string) ( $sub['name'] ?? '' ) );
				if ( '' === $performer ) {
					continue;
				}

				$start = self::local_datetime( (string) ( $sub['startDate'] ?? '' ) );
				if ( ! $start ) {
					continue;
				}
				$date = $start->format( 'Y-m-d' );
				if ( ! $ctx->in_range( $date ) ) {
					continue;
				}
				$end = self::local_datetime( (string) ( $sub['endDate'] ?? '' ) );

				$groups[ $location . "\0" . $date ][] = array( $performer, $start, $end );
			} catch ( \Throwable $e ) {
				$ctx->log( '[festapp] Error parsing performance: ' . $e->getMessage() );
			}
		}

		$events = array();
		foreach ( $groups as $key => $performances ) {
			list( $location, $date ) = explode( "\0", $key );
			usort(
				$performances,
				static function ( $a, $b ) {
					return $a[1] <=> $b[1];
				}
			);

			$lineup = array();
			foreach ( $performances as $p ) {
				if ( ! in_array( $p[0], $lineup, true ) ) {
					$lineup[] = $p[0];
				}
			}

			$ends     = array_filter( array_column( $performances, 2 ) );
			$end_time = $ends ? max( $ends )->format( 'H:i' ) : '';

			$event                = $this->base_event( $venue );
			$event['venue_name']  = $location;
			$event['date']        = $date;
			$event['title']       = '' !== $festival_name ? $festival_name : ( $venue['name'] ?? '' );
			$event['lineup']      = $lineup;
			$event['start_time']  = $performances[0][1]->format( 'H:i' );
			$event['end_time']    = $end_time;
			$event['ticket_link'] = $url;
			$event['source_urls'] = array( $url );
			$events[]             = $event;
		}

		$ctx->log( sprintf( '[festapp] Grouped into %d stage-day events', count( $events ) ) );
		return $events;
	}

	/**
	 * Stage name with a trailing " - <city>" removed and fancy Unicode letters
	 * (e.g. math italic) folded to ASCII. Empty when the location is just the city.
	 */
	public static function location_name( $location, string $city ): string {
		$name = is_array( $location ) ? (string) ( $location['name'] ?? '' ) : ( is_string( $location ) ? $location : '' );

		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $name, \Normalizer::FORM_KD );
			if ( false !== $normalized ) {
				$name = $normalized;
			}
		}
		if ( '' !== $city ) {
			$name = preg_replace( '/\s*-\s*' . preg_quote( $city, '/' ) . '$/iu', '', $name );
		}
		$name = trim( $name );

		if ( '' === $name || ( '' !== $city && 0 === strcasecmp( $name, $city ) ) ) {
			return '';
		}
		return $name;
	}

	/**
	 * FestApp writes local times with a misleading "Z" ("2026-02-14T20:00:00.000Z"
	 * matches the displayed schedule), so the suffix and milliseconds are dropped
	 * and the value is taken as local wall-clock time.
	 */
	public static function local_datetime( string $value ): ?\DateTimeImmutable {
		$value = preg_replace( '/\.\d+$/', '', rtrim( trim( $value ), 'Z' ) );
		if ( '' === $value ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s', $value );
		if ( ! $dt ) {
			$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value );
		}
		return $dt ?: null;
	}
}
