<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for direct (non-AI) event parsers.
 *
 * A parser turns one venue's configured URL into a list of raw event arrays:
 *
 *   [
 *     'venue_name' => string, 'venue_city' => string, 'venue_province' => string, 'venue_country' => string,
 *     'date' => 'Y-m-d', 'title' => string, 'description' => string, 'lineup' => string[],
 *     'ticket_price' => string, 'ticket_link' => string,
 *     'start_time' => string, 'end_time' => string,   // "HH:MM" preferred; "8:00 PM" is normalized later
 *     'source_urls' => string[],
 *   ]
 *
 * Raw events are normalized, validated and deduplicated by the pipeline, so a
 * parser only needs to extract faithfully and filter to the date range.
 *
 * Parsers throw \SceneCalendar\Util\HttpException (or any \Throwable) on fetch
 * failure; the runner logs it and falls back to the AI agent if enabled.
 */
abstract class Parser {

	/** Machine slug stored on the venue, e.g. "squarespace". */
	abstract public static function slug(): string;

	/** Human label for the venue form dropdown. */
	abstract public static function label(): string;

	/** One-line help text: which sites/platforms this handles and what URL to enter. */
	abstract public static function description(): string;

	/**
	 * Extra per-venue settings this parser needs, rendered on the venue form and
	 * stored in the venue's meta. Keys are meta keys.
	 *
	 * @return array<string, array{label:string, help?:string, required?:bool}>
	 */
	public static function config_fields(): array {
		return array();
	}

	/** True if this parser should be auto-selected for $url (venue parser = "auto"). */
	public static function matches_url( string $url ): bool {
		return false;
	}

	/**
	 * Fetch and extract events.
	 *
	 * @param array   $venue Venue: name, type ('venue'|'promoter'), city, province,
	 *                       country, url, instagram, aliases[], plus config_fields() keys.
	 * @param Context $ctx   Date range, timezone, logger, optional LLM.
	 * @return array[] Raw event arrays (see class doc).
	 */
	abstract public function parse( array $venue, Context $ctx ): array;

	/** Raw event skeleton pre-filled with venue location fields. */
	protected function base_event( array $venue ): array {
		return array(
			'venue_name'     => $venue['name'] ?? '',
			'venue_city'     => $venue['city'] ?? '',
			'venue_province' => $venue['province'] ?? '',
			'venue_country'  => $venue['country'] ?? '',
			'date'           => '',
			'title'          => '',
			'description'    => '',
			'lineup'         => array(),
			'ticket_price'   => '',
			'ticket_link'    => '',
			'start_time'     => '',
			'end_time'       => '',
			'source_urls'    => array(),
		);
	}

	/** Host of a URL, lowercased, without "www.". */
	protected static function host( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
