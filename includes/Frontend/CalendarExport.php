<?php
namespace SceneCalendar\Frontend;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;

/**
 * "Add to calendar" support: Google Calendar links and single-event .ics
 * downloads (?scene_calendar_ics=<id>, published events only).
 *
 * Times are computed in the site timezone and emitted in UTC, so they are
 * right even when the site uses a fixed UTC offset (see README: Timezones).
 */
final class CalendarExport {

	/** Default length of a show when the source gives no end time. */
	const DEFAULT_HOURS = 3;

	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_serve_ics' ) );
	}

	/**
	 * Start/end of an event.
	 *
	 * @return array{all_day:bool, start:\DateTimeImmutable, end:\DateTimeImmutable}
	 */
	public static function range( array $e ): array {
		$tz = wp_timezone();
		if ( '' === (string) $e['start_time'] ) {
			$start = new \DateTimeImmutable( $e['date'] . ' 00:00', $tz );
			return array(
				'all_day' => true,
				'start'   => $start,
				'end'     => $start->modify( '+1 day' ),
			);
		}
		$start = new \DateTimeImmutable( $e['date'] . ' ' . $e['start_time'], $tz );
		$end   = '' !== (string) $e['end_time'] ? new \DateTimeImmutable( $e['date'] . ' ' . $e['end_time'], $tz ) : $start->modify( '+' . self::DEFAULT_HOURS . ' hours' );
		if ( $end <= $start ) {
			$end = $end->modify( '+1 day' ); // Ends after midnight.
		}
		return array(
			'all_day' => false,
			'start'   => $start,
			'end'     => $end,
		);
	}

	private static function utc( \DateTimeImmutable $dt ): string {
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	public static function location( array $e ): string {
		return implode( ', ', array_filter( array( $e['venue_name'], $e['venue_city'], $e['venue_province'] ), 'strlen' ) );
	}

	/** Plain-text details shared by Google and .ics. */
	private static function details( array $e ): string {
		$parts = array();
		if ( '' !== (string) $e['description'] ) {
			$parts[] = $e['description'];
		}
		if ( $e['lineup'] ) {
			/* translators: %s: comma-separated artists */
			$parts[] = sprintf( __( 'Lineup: %s', 'scene-calendar' ), implode( ', ', $e['lineup'] ) );
		}
		if ( '' !== (string) $e['ticket_price'] ) {
			/* translators: %s: ticket price */
			$parts[] = sprintf( __( 'Tickets: %s', 'scene-calendar' ), $e['ticket_price'] );
		}
		$link = self::link( $e );
		if ( '' !== $link ) {
			$parts[] = $link;
		}
		return implode( "\n\n", $parts );
	}

	/** Ticket link, else the first source page. */
	public static function link( array $e ): string {
		return (string) ( $e['ticket_link'] ?: ( $e['source_urls'][0] ?? '' ) );
	}

	/** Google Calendar template fields (details capped to keep the URL well under browser limits). */
	public static function google_parts( array $e ): array {
		$r = self::range( $e );
		return array(
			'dates'    => $r['all_day']
				? $r['start']->format( 'Ymd' ) . '/' . $r['end']->format( 'Ymd' )
				: self::utc( $r['start'] ) . '/' . self::utc( $r['end'] ),
			'details'  => mb_substr( self::details( $e ), 0, 600 ),
			'location' => self::location( $e ),
		);
	}

	public static function google_url( array $e ): string {
		$p = self::google_parts( $e );
		// "dates" is [0-9TZ/] only and stays unencoded, as in Google's own examples.
		return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode( $e['title'] )
			. '&dates=' . $p['dates'] . '&details=' . rawurlencode( $p['details'] ) . '&location=' . rawurlencode( $p['location'] );
	}

	public static function ics_url( array $e ): string {
		return add_query_arg( 'scene_calendar_ics', (int) $e['id'], home_url( '/' ) );
	}

	public static function ics( array $e ): string {
		$r     = self::range( $e );
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Scene Calendar//' . $host . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			// origin_key never changes, so an edited title doesn't create a second calendar entry.
			'UID:scenecal-' . $e['origin_key'] . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		);
		if ( $r['all_day'] ) {
			$lines[] = 'DTSTART;VALUE=DATE:' . $r['start']->format( 'Ymd' );
			$lines[] = 'DTEND;VALUE=DATE:' . $r['end']->format( 'Ymd' );
		} else {
			$lines[] = 'DTSTART:' . self::utc( $r['start'] );
			$lines[] = 'DTEND:' . self::utc( $r['end'] );
		}
		$lines[] = 'SUMMARY:' . self::escape( $e['title'] );
		$lines[] = 'LOCATION:' . self::escape( self::location( $e ) );
		$lines[] = 'DESCRIPTION:' . self::escape( self::details( $e ) );
		$link    = self::link( $e );
		if ( '' !== $link ) {
			$lines[] = 'URL:' . $link;
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/** RFC 5545 TEXT escaping. */
	public static function escape( string $text ): string {
		return str_replace( array( '\\', ';', ',', "\r\n", "\r", "\n" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n', '\\n' ), $text );
	}

	/** Fold lines at 75 octets without splitting a UTF-8 character. */
	public static function fold( string $line ): string {
		$out   = '';
		$first = true;
		while ( strlen( $line ) > ( $first ? 75 : 74 ) ) {
			$chunk = mb_strcut( $line, 0, $first ? 75 : 74, 'UTF-8' );
			$out  .= ( $first ? '' : "\r\n " ) . $chunk;
			$line  = substr( $line, strlen( $chunk ) );
			$first = false;
		}
		return $out . ( $first ? '' : "\r\n " ) . $line;
	}

	/** Serve ?scene_calendar_ics=<id> for published events. */
	public static function maybe_serve_ics(): void {
		if ( ! isset( $_GET['scene_calendar_ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only.
			return;
		}
		$event = Events::get( absint( $_GET['scene_calendar_ics'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $event || 'publish' !== $event['status'] ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		// ASCII-only filename (sanitize_title keeps percent-encoded emoji etc.).
		$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $event['title'] ) ) ), '-' ) ?: 'event';
		$slug = substr( $slug, 0, 60 );
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.ics"' );
		header( 'X-Robots-Tag: noindex' );
		echo self::ics( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped per RFC 5545.
		exit;
	}
}
