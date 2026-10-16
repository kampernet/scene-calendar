<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * showpass.com venue and organizer pages, via the public Showpass API.
 *
 *   GET https://www.showpass.com/api/public/events/?venue__in=<id>&starts_on__gte=...
 *
 * venue__in (numeric venue/organizer ID) is the only filter the API honours —
 * slug-based params like organizer__slug are silently ignored — so a slug from
 * the URL is resolved to its ID first.
 */
class Showpass extends Parser {

	const API_EVENTS = 'https://www.showpass.com/api/public/events/';
	const API_VENUES = 'https://www.showpass.com/api/public/venues/';
	const MAX_PAGES  = 5;

	public static function slug(): string {
		return 'showpass';
	}

	public static function label(): string {
		return 'Showpass';
	}

	public static function description(): string {
		return __( 'Venues and promoters selling on showpass.com. Enter their Showpass page as the URL, e.g. https://www.showpass.com/o/yourpromoter/ or https://www.showpass.com/venues/yourvenue/.', 'scene-calendar' );
	}

	public static function config_fields(): array {
		return array(
			'showpass_id' => array(
				'label' => __( 'Showpass venue/organizer ID', 'scene-calendar' ),
				'help'  => __( 'Optional. Numeric ID; leave blank to look it up automatically from the /o/<slug>/ or /venues/<slug>/ URL.', 'scene-calendar' ),
			),
		);
	}

	public static function matches_url( string $url ): bool {
		$host = self::host( $url );
		return 'showpass.com' === $host || str_ends_with( $host, '.showpass.com' );
	}

	public function parse( array $venue, Context $ctx ): array {
		$showpass_id = trim( (string) ( $venue['showpass_id'] ?? '' ) );
		if ( '' === $showpass_id ) {
			$slug = self::extract_slug( (string) ( $venue['url'] ?? '' ) );
			if ( ! $slug ) {
				$ctx->log( '[showpass] Could not extract a venue/organizer slug from ' . ( $venue['url'] ?? '' ) );
				return array();
			}
			$data        = Http::get_json( self::API_VENUES . rawurlencode( $slug ) . '/', array( 'query' => array( 'format' => 'json' ) ) );
			$showpass_id = (string) ( $data['id'] ?? '' );
			if ( '' === $showpass_id ) {
				$ctx->log( "[showpass] Could not resolve Showpass ID for slug '$slug'" );
				return array();
			}
		}

		$query = array(
			'venue__in'      => $showpass_id,
			'starts_on__gte' => $ctx->start_date,
			'page_size'      => 100,
			'format'         => 'json',
		);
		if ( '9999-12-31' !== $ctx->end_date ) {
			$query['starts_on__lte'] = $ctx->end_date;
		}

		$results = array();
		$url     = self::API_EVENTS;
		$args    = array( 'query' => $query );
		for ( $page = 0; $page < self::MAX_PAGES && $url; $page++ ) {
			$data = Http::get_json( $url, $args );
			$list = is_array( $data ) && isset( $data['results'] ) ? $data['results'] : $data;
			if ( is_array( $list ) && array_is_list( $list ) ) {
				$results = array_merge( $results, $list );
			}
			// "next" is an absolute URL with the query already applied.
			$url  = is_array( $data ) && ! empty( $data['next'] ) && is_string( $data['next'] ) ? $data['next'] : '';
			$args = array();
		}

		$events = array();
		foreach ( $results as $item ) {
			try {
				$event = is_array( $item ) ? $this->convert( $item, $venue, $ctx ) : null;
				if ( $event ) {
					$events[] = $event;
				}
			} catch ( \Throwable $e ) {
				$ctx->log( '[showpass] Error parsing event: ' . $e->getMessage() );
			}
		}

		$ctx->log( sprintf( '[showpass] Extracted %d events', count( $events ) ) );
		return $events;
	}

	/** Slug from https://www.showpass.com/o/<slug>/ or /venues/<slug>/. */
	public static function extract_slug( string $url ): ?string {
		$path  = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$parts = explode( '/', $path );
		if ( count( $parts ) >= 2 && in_array( $parts[0], array( 'o', 'venues' ), true ) && '' !== $parts[1] ) {
			return $parts[1];
		}
		return null;
	}

	/**
	 * Promoter descriptions often contain a line like "The Handsome DaughterMarch 13"
	 * (venue immediately followed by a weekday or month once tags are removed).
	 */
	public static function venue_from_description( string $description_html ): string {
		if ( '' === $description_html ) {
			return '';
		}
		$text   = html_entity_decode( wp_strip_all_tags( $description_html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$days   = '(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)';
		$months = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';
		if ( preg_match( "/([A-Z][A-Za-z'() ]+?)(?:$days|$months)/", $text, $m ) ) {
			$name = rtrim( trim( $m[1] ), "• \u{2022}" );
			$len  = strlen( $name );
			if ( $len > 3 && $len < 60 ) {
				return $name;
			}
		}
		return '';
	}

	private function convert( array $item, array $venue, Context $ctx ): ?array {
		$start_dt = $this->local_dt( $item, 'starts_on', $ctx );
		if ( ! $start_dt ) {
			return null;
		}
		$date = $start_dt->format( 'Y-m-d' );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$end_dt   = $this->local_dt( $item, 'ends_on', $ctx );
		$end_time = $end_dt ? $end_dt->format( 'H:i' ) : '';

		$description_html = (string) ( $item['description'] ?? '' );
		$venue_data       = is_array( $item['venue'] ?? null ) ? $item['venue'] : array();
		$location         = is_array( $item['location'] ?? null ) ? $item['location'] : array();
		$is_promoter      = 'promoter' === ( $venue['type'] ?? 'venue' );

		// For promoters, the event's "venue" is the promoter's own account; the
		// physical room is in location.name (unless that is just a street address).
		$venue_name = '';
		if ( $is_promoter ) {
			$loc_name = trim( (string) ( $location['name'] ?? '' ) );
			if ( '' !== $loc_name && ! preg_match( '/^\d/', $loc_name ) ) {
				$venue_name = $loc_name;
			}
		}
		if ( '' === $venue_name ) {
			$venue_name = trim( (string) ( $venue_data['name'] ?? '' ) );
		}
		if ( '' === $venue_name && $is_promoter ) {
			$venue_name = self::venue_from_description( $description_html );
		}
		if ( '' === $venue_name && ! $is_promoter ) {
			$venue_name = (string) ( $venue['name'] ?? '' );
		}

		// Reject events in other cities (promoters often tour).
		$event_city    = trim( (string) ( ( $location['city'] ?? '' ) ?: ( $venue_data['city'] ?? '' ) ) );
		$expected_city = trim( (string) ( $venue['city'] ?? '' ) );
		if ( '' !== $event_city && '' !== $expected_city && mb_strtolower( $event_city ) !== mb_strtolower( $expected_city ) ) {
			return null;
		}

		$title = trim( (string) ( ( $item['name'] ?? '' ) ?: ( $item['title'] ?? '' ) ) );
		if ( '' === $title ) {
			return null;
		}

		$price = '';
		if ( isset( $item['frontend_price'] ) && is_numeric( $item['frontend_price'] ) ) {
			$price = Text::format_price( $item['frontend_price'] );
		}

		$slug        = (string) ( $item['slug'] ?? '' );
		$ticket_link = '' !== $slug ? "https://www.showpass.com/$slug/" : '';

		$event                   = $this->base_event( $venue );
		$event['venue_name']     = $venue_name;
		$event['venue_city']     = '' !== $event_city ? $event_city : ( $venue['city'] ?? '' );
		$event['venue_province'] = ( $location['province'] ?? '' ) ?: ( $venue['province'] ?? '' );
		$event['venue_country']  = ( $location['country'] ?? '' ) ?: ( $venue['country'] ?? '' );
		$event['date']           = $date;
		$event['title']          = $title;
		$event['description']    = Text::truncate( Html::to_line( $description_html ) );
		$event['ticket_price']   = $price;
		$event['ticket_link']    = $ticket_link;
		$event['start_time']     = $start_dt->format( 'H:i' );
		$event['end_time']       = $end_time;
		$event['source_urls']    = '' !== $ticket_link ? array( $ticket_link ) : array();
		return $event;
	}

	/**
	 * local_<field> already carries the venue's offset ("2026-10-24T19:00:00-05:00")
	 * and is used as-is; the UTC <field> fallback is converted to the event's
	 * timezone (or the site timezone).
	 */
	private function local_dt( array $item, string $field, Context $ctx ): ?\DateTimeImmutable {
		$local = $item[ 'local_' . $field ] ?? '';
		if ( is_string( $local ) && '' !== $local ) {
			try {
				return new \DateTimeImmutable( $local );
			} catch ( \Exception $e ) {
				// Fall through to UTC field.
			}
		}
		$utc = $item[ $field ] ?? '';
		if ( ! is_string( $utc ) || '' === $utc ) {
			return null;
		}
		// Site timezone, so admins can correct for stale PHP timezone data.
		$tz = $ctx->tz;
		try {
			return ( new \DateTimeImmutable( $utc ) )->setTimezone( $tz );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
