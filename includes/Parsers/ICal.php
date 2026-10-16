<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * iCalendar (.ics) feeds — e.g. a public Google Calendar embedded on a venue's
 * site (Google Calendar → Settings → "Public address in iCal format").
 *
 * Handles RFC 5545 line folding, all-day (VALUE=DATE), UTC ("...Z", converted
 * to the site timezone) and TZID-local start times, and text unescaping.
 * Recurring events (RRULE) are NOT expanded: only the first occurrence
 * (DTSTART) is considered.
 */
class ICal extends Parser {

	public static function slug(): string {
		return 'ical';
	}

	public static function label(): string {
		return __( 'iCal feed (.ics / Google Calendar)', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'A public iCalendar feed. For a Google Calendar embedded on the venue site, use its "public address in iCal format". Put the feed in the iCal URL field (or as the venue URL).', 'scene-calendar' );
	}

	public static function config_fields(): array {
		return array(
			'ical_url' => array(
				'label' => __( 'iCal feed URL', 'scene-calendar' ),
				'help'  => __( 'Optional. The .ics feed address; if empty the venue URL is used. Ticket links fall back to the venue URL.', 'scene-calendar' ),
			),
		);
	}

	public static function matches_url( string $url ): bool {
		$lower = strtolower( $url );
		$path  = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return str_ends_with( $path, '.ics' ) || str_contains( $lower, 'calendar.google.com/calendar/ical' );
	}

	public function parse( array $venue, Context $ctx ): array {
		$venue_url = $venue['url'] ?? '';
		$feed_url  = trim( (string) ( $venue['ical_url'] ?? '' ) );
		if ( '' === $feed_url ) {
			$feed_url = $venue_url;
		}

		$ics = Http::get( $feed_url );
		// Unfold continuation lines (CRLF followed by a space or tab) and normalize line endings.
		$ics = preg_replace( "/\r?\n[ \t]/", '', $ics );
		$ics = str_replace( "\r", '', $ics );

		if ( ! preg_match_all( '/BEGIN:VEVENT\n(.*?)END:VEVENT/s', $ics, $blocks ) ) {
			$ctx->log( 'No VEVENT blocks found in ' . $feed_url );
			return array();
		}
		$ctx->log( sprintf( 'Found %d VEVENT blocks', count( $blocks[1] ) ) );

		$link   = '' !== $venue_url ? $venue_url : $feed_url;
		$events = array();
		foreach ( $blocks[1] as $block ) {
			try {
				$event = $this->parse_vevent( $block, $venue, $link, $ctx );
				if ( $event ) {
					$events[] = $event;
				}
			} catch ( \Throwable $e ) {
				$ctx->log( 'Error parsing VEVENT: ' . $e->getMessage() );
			}
		}
		return $events;
	}

	private function parse_vevent( string $block, array $venue, string $fallback_link, Context $ctx ): ?array {
		$props = self::properties( $block );

		$title = isset( $props['SUMMARY'] ) ? trim( self::unescape( $props['SUMMARY']['value'] ) ) : '';
		if ( '' === $title || ! isset( $props['DTSTART'] ) ) {
			return null;
		}
		if ( isset( $props['STATUS'] ) && 'CANCELLED' === strtoupper( trim( $props['STATUS']['value'] ) ) ) {
			return null;
		}

		list( $date, $start_time ) = self::parse_dt( $props['DTSTART'], $ctx->tz );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}
		$end_time = isset( $props['DTEND'] ) ? self::parse_dt( $props['DTEND'], $ctx->tz )[1] : '';

		$url = isset( $props['URL'] ) ? trim( $props['URL']['value'] ) : '';

		$event                = $this->base_event( $venue );
		$event['date']        = $date;
		$event['title']       = $title;
		$event['description'] = isset( $props['DESCRIPTION'] ) ? Text::truncate( trim( preg_replace( '/\s+/u', ' ', self::unescape( $props['DESCRIPTION']['value'] ) ) ) ) : '';
		$event['ticket_link'] = '' !== $url ? $url : $fallback_link;
		$event['start_time']  = $start_time;
		$event['end_time']    = $end_time;
		$event['source_urls'] = array( '' !== $url ? $url : $fallback_link );
		return $event;
	}

	/**
	 * Map of property name => ['params' => [NAME => value], 'value' => raw value].
	 * First occurrence wins.
	 */
	public static function properties( string $block ): array {
		$props = array();
		foreach ( explode( "\n", $block ) as $line ) {
			if ( ! preg_match( '/^([A-Za-z0-9-]+)((?:;[^:;]+=(?:"[^"]*"|[^:;]*))*):(.*)$/', $line, $m ) ) {
				continue;
			}
			$name = strtoupper( $m[1] );
			if ( isset( $props[ $name ] ) ) {
				continue;
			}
			$params = array();
			if ( '' !== $m[2] && preg_match_all( '/;([^=;:]+)=("[^"]*"|[^;:]*)/', $m[2], $pm, PREG_SET_ORDER ) ) {
				foreach ( $pm as $p ) {
					$params[ strtoupper( $p[1] ) ] = trim( $p[2], '"' );
				}
			}
			$props[ $name ] = array(
				'params' => $params,
				'value'  => $m[3],
			);
		}
		return $props;
	}

	/**
	 * Parse DTSTART/DTEND into [Y-m-d, "HH:MM"] (time '' for all-day).
	 * UTC values are converted to $tz; TZID values are converted from their zone when PHP knows it.
	 */
	public static function parse_dt( array $prop, \DateTimeZone $tz ): array {
		$value = trim( $prop['value'] );
		if ( 'DATE' === strtoupper( $prop['params']['VALUE'] ?? '' ) || preg_match( '/^\d{8}$/', $value ) ) {
			$dt = \DateTimeImmutable::createFromFormat( '!Ymd', substr( $value, 0, 8 ) );
			return $dt ? array( $dt->format( 'Y-m-d' ), '' ) : array( '', '' );
		}
		if ( preg_match( '/^(\d{8}T\d{6})Z$/', $value, $m ) ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $m[1], new \DateTimeZone( 'UTC' ) );
			if ( ! $dt ) {
				return array( '', '' );
			}
			$dt = $dt->setTimezone( $tz );
			return array( $dt->format( 'Y-m-d' ), $dt->format( 'H:i' ) );
		}
		if ( preg_match( '/^(\d{8}T\d{6})$/', $value, $m ) ) {
			$zone = $tz;
			// A site on a fixed UTC offset has opted out of PHP's DST rules (stale
			// timezone data); treat calendar-local times as the venue's wall time.
			$fixed = in_array( $tz->getName()[0] ?? '', array( '+', '-' ), true );
			if ( ! $fixed && ! empty( $prop['params']['TZID'] ) ) {
				try {
					$zone = new \DateTimeZone( $prop['params']['TZID'] );
				} catch ( \Exception $e ) {
					$zone = $tz; // Unknown TZID (e.g. Outlook names): treat as local wall time.
				}
			}
			$dt = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $m[1], $zone );
			if ( ! $dt ) {
				return array( '', '' );
			}
			$dt = $dt->setTimezone( $tz );
			return array( $dt->format( 'Y-m-d' ), $dt->format( 'H:i' ) );
		}
		return array( '', '' );
	}

	/** Undo RFC 5545 TEXT escaping: \n, \, \; \\ . */
	public static function unescape( string $text ): string {
		return strtr(
			$text,
			array(
				'\\n'  => "\n",
				'\\N'  => "\n",
				'\\,'  => ',',
				'\\;'  => ';',
				'\\\\' => '\\',
			)
		);
	}
}
