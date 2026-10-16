<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Llm\Client;

/**
 * Everything a parser needs besides the venue: the date window, the site's
 * timezone (for converting UTC timestamps to local show times), a logger and,
 * for vision-based parsers, an LLM client.
 */
final class Context {

	/** @var string Inclusive start date, Y-m-d. */
	public string $start_date;

	/** @var string Inclusive end date, Y-m-d ('9999-12-31' when unbounded). */
	public string $end_date;

	public \DateTimeZone $tz;

	public ?Client $llm;

	/** @var callable(string):void */
	private $logger;

	public function __construct( string $start_date, string $end_date, \DateTimeZone $tz, ?callable $logger = null, ?Client $llm = null ) {
		$this->start_date = $start_date;
		$this->end_date   = $end_date;
		$this->tz         = $tz;
		$this->llm        = $llm;
		$this->logger     = $logger ?? static function () {};
	}

	/** True if a Y-m-d date falls inside the window. */
	public function in_range( string $date ): bool {
		return '' !== $date && $date >= $this->start_date && $date <= $this->end_date;
	}

	public function log( string $message ): void {
		( $this->logger )( $message );
	}

	/** Convert any parseable timestamp (ISO with offset/Z, or epoch seconds) to a local DateTimeImmutable. */
	public function to_local( $value ): ?\DateTimeImmutable {
		try {
			if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
				return ( new \DateTimeImmutable( '@' . (int) $value ) )->setTimezone( $this->tz );
			}
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return null;
			}
			// A stated numeric offset ("…T20:00:00-06:00") is the venue's own wall
			// time; keep it rather than re-converting with possibly different rules
			// (e.g. a venue site with stale tz data on a fixed UTC-5 Manitoba site).
			if ( preg_match( '/[+-]\d{2}:?\d{2}$/', trim( $value ) ) ) {
				return new \DateTimeImmutable( $value );
			}
			return ( new \DateTimeImmutable( $value, $this->tz ) )->setTimezone( $this->tz );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
