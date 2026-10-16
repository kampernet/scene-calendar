<?php
namespace SceneCalendar\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Pure string/date helpers shared by parsers and the pipeline. No WordPress calls,
 * so these are safe to unit test in isolation.
 */
final class Text {

	/** Lowercase, '&' -> 'and', drop parentheticals and punctuation, collapse whitespace. */
	public static function normalize( $s ): string {
		if ( ! is_string( $s ) ) {
			return '';
		}
		$s = mb_strtolower( trim( $s ) );
		$s = str_replace( '&', 'and', $s );
		$s = preg_replace( '/\([^)]*\)/u', '', $s );
		$s = preg_replace( '/[^\p{L}\p{N}_\s]/u', '', $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( (string) $s );
	}

	/** Similarity ratio 0..1 of two normalized strings. */
	public static function similarity( $a, $b ): float {
		$na = self::normalize( $a );
		$nb = self::normalize( $b );
		if ( '' === $na || '' === $nb ) {
			return 0.0;
		}
		similar_text( $na, $nb, $percent );
		return $percent / 100;
	}

	/**
	 * Stable event key: first 12 hex chars of sha256("venue|date|title"), normalized.
	 * Identical to the key used by the original Python scraper, so imported data lines up.
	 */
	public static function event_key( string $venue, string $date, string $title ): string {
		return substr( hash( 'sha256', self::normalize( $venue ) . '|' . $date . '|' . self::normalize( $title ) ), 0, 12 );
	}

	/**
	 * Normalize a time string to 24-hour "HH:MM", or '' if unparseable.
	 * Accepts "20:00", "8pm", "8:30 PM", "Show: 8:00 pm", "Doors - 7pm".
	 */
	public static function parse_time( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$v = strtolower( trim( $value ) );
		$v = preg_replace( '/^[a-z]+\s*[:\-]\s*/', '', $v );
		$v = preg_replace( '/\s+/', ' ', $v );
		$v = str_replace( '.', '', $v ); // "8 p.m."

		if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m ) ) {
			$h = (int) $m[1];
			$i = (int) $m[2];
			return ( $h <= 23 && $i <= 59 ) ? sprintf( '%02d:%02d', $h, $i ) : '';
		}
		if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/', $v, $m ) ) {
			$h = (int) $m[1];
			$i = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
			if ( $h < 1 || $h > 12 || $i > 59 ) {
				return '';
			}
			if ( 'pm' === $m[3] && 12 !== $h ) {
				$h += 12;
			} elseif ( 'am' === $m[3] && 12 === $h ) {
				$h = 0;
			}
			return sprintf( '%02d:%02d', $h, $i );
		}
		return '';
	}

	/** Date formats tried by parse_date(), in order. */
	const DATE_FORMATS = array(
		'Y-m-d',
		'F j, Y',
		'M j, Y',
		'F j Y',
		'M j Y',
		'j F Y',
		'j M Y',
		'm/d/Y',
		'Y/m/d',
		'm-d-Y',
	);

	/** Parse a date string into "Y-m-d", or '' if unparseable. */
	public static function parse_date( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ) ? $value : '';
		}
		// A leading weekday ("Friday, March 13, 2026") is dropped: PHP would otherwise
		// shift the date to match a mismatched weekday.
		$value = preg_replace( '/^(?:mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,?\s+/i', '', $value );
		foreach ( self::DATE_FORMATS as $format ) {
			$dt     = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $dt && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $dt->format( 'Y-m-d' );
			}
		}
		return '';
	}

	/**
	 * Resolve a month/day with no year to the first matching date on or after
	 * $range_start (e.g. "27 Aug" seen in October means next August).
	 */
	public static function infer_year( int $month, int $day, string $range_start ): string {
		$year = (int) substr( $range_start, 0, 4 );
		for ( $y = $year; $y <= $year + 1; $y++ ) {
			if ( checkdate( $month, $day, $y ) ) {
				$candidate = sprintf( '%04d-%02d-%02d', $y, $month, $day );
				if ( $candidate >= $range_start ) {
					return $candidate;
				}
			}
		}
		return checkdate( $month, $day, $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
	}

	/** Truncate to $max characters with a trailing ellipsis. */
	public static function truncate( string $s, int $max = 300 ): string {
		return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max ) . '...' : $s;
	}

	/** Format a price amount: 0 -> "Free", else "$12.50" (or "$12" when $trim_cents and whole). */
	public static function format_price( $amount, bool $trim_cents = false ): string {
		if ( ! is_numeric( $amount ) ) {
			return '';
		}
		$amount = (float) $amount;
		if ( 0.0 === $amount ) {
			return 'Free';
		}
		if ( $trim_cents && floor( $amount ) === $amount ) {
			return '$' . number_format( $amount, 0, '.', '' );
		}
		return '$' . number_format( $amount, 2, '.', '' );
	}

	/** Strip markdown code fences and <think> blocks from an LLM response. */
	public static function strip_fences( string $text ): string {
		$text = trim( preg_replace( '/<think>[\s\S]*?<\/think>/', '', $text ) );
		if ( str_starts_with( $text, '```' ) ) {
			$text = preg_replace( '/^```(?:json)?\s*\n?/', '', $text );
			$text = preg_replace( '/\n?```\s*$/', '', $text );
		}
		return trim( $text );
	}

	/** Decode JSON from an LLM response, tolerating fences and surrounding prose. */
	public static function decode_llm_json( string $text ) {
		$text = self::strip_fences( $text );
		$data = json_decode( $text, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $data;
		}
		foreach ( array( '/\[[\s\S]*\]/', '/\{[\s\S]*\}/' ) as $pattern ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$data = json_decode( $m[0], true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					return $data;
				}
			}
		}
		return null;
	}
}
