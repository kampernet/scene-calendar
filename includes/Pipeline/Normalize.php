<?php
namespace SceneCalendar\Pipeline;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Text;

/**
 * Normalize raw event arrays (from parsers or the AI agent) into the canonical
 * shape, and validate them.
 */
final class Normalize {

	const GENRES = array(
		'indie/alternative',
		'punk/hardcore',
		'rock',
		'metal',
		'electronic/dance',
		'hip-hop/rap',
		'jazz/blues',
		'country/folk',
		'pop',
		'tribute/cover',
		'comedy/variety',
		'unknown',
	);

	/** Field-name variants an LLM might use => canonical name. */
	const FIELD_MAP = array(
		'venue'        => 'venue_name',
		'venueName'    => 'venue_name',
		'city'         => 'venue_city',
		'province'     => 'venue_province',
		'state'        => 'venue_province',
		'country'      => 'venue_country',
		'event_date'   => 'date',
		'show_date'    => 'date',
		'name'         => 'title',
		'event_name'   => 'title',
		'show_name'    => 'title',
		'event_title'  => 'title',
		'desc'         => 'description',
		'details'      => 'description',
		'artists'      => 'lineup',
		'performers'   => 'lineup',
		'acts'         => 'lineup',
		'bands'        => 'lineup',
		'price'        => 'ticket_price',
		'cost'         => 'ticket_price',
		'admission'    => 'ticket_price',
		'ticket_url'   => 'ticket_link',
		'ticketlink'   => 'ticket_link',
		'venue name'   => 'venue_name',
		'venuename'    => 'venue_name',
		'starttime'    => 'start_time',
		'endtime'      => 'end_time',
		'category'     => 'genre',
		'tickets_url'  => 'ticket_link',
		'url'          => 'ticket_link',
		'link'         => 'ticket_link',
		'time'         => 'start_time',
		'start'        => 'start_time',
		'startTime'    => 'start_time',
		'doors'        => 'start_time',
		'door_time'    => 'start_time',
		'end'          => 'end_time',
		'endTime'      => 'end_time',
		'source'       => 'source_urls',
		'source_url'   => 'source_urls',
		'sources'      => 'source_urls',
		'urls'         => 'source_urls',
	);

	const FIELDS = array(
		'venue_name', 'venue_city', 'venue_province', 'venue_country', 'date', 'title',
		'description', 'lineup', 'ticket_price', 'ticket_link', 'start_time', 'end_time',
		'source_urls', 'genre',
	);

	public static function event( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		// Match field names case-insensitively ("Venue", "Title", "ticketLink").
		$lower = array();
		foreach ( $raw as $key => $value ) {
			if ( is_string( $key ) && ! isset( $lower[ strtolower( $key ) ] ) ) {
				$lower[ strtolower( $key ) ] = $value;
			}
		}
		$raw = $raw + $lower;

		$e = array();
		// Canonical names first so they win over variants.
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $raw ) && null !== $raw[ $field ] && '' !== $raw[ $field ] ) {
				$e[ $field ] = $raw[ $field ];
			}
		}
		foreach ( self::FIELD_MAP as $variant => $canonical ) {
			if ( ! isset( $e[ $canonical ] ) && isset( $raw[ $variant ] ) && '' !== $raw[ $variant ] ) {
				$e[ $canonical ] = $raw[ $variant ];
			}
		}

		// venue returned as an object.
		if ( isset( $e['venue_name'] ) && is_array( $e['venue_name'] ) ) {
			$v               = $e['venue_name'];
			$e['venue_name'] = (string) ( $v['name'] ?? $v['venue_name'] ?? '' );
			$e['venue_city'] = $e['venue_city'] ?? ( $v['city'] ?? '' );
			$e['venue_province'] = $e['venue_province'] ?? ( $v['province'] ?? '' );
		}

		// Agent-style headliner/support fields.
		if ( empty( $e['lineup'] ) && ! empty( $raw['artist_or_headliner'] ) ) {
			$e['lineup'] = array_merge( array( $raw['artist_or_headliner'] ), (array) ( $raw['supporting_artists'] ?? array() ) );
		}
		$e['lineup']      = self::string_list( $e['lineup'] ?? array(), true );
		$e['source_urls'] = array_values( array_filter( array_map( 'esc_url_raw', self::string_list( $e['source_urls'] ?? array(), false ) ) ) );
		$e['date']        = Text::parse_date( is_string( $e['date'] ?? null ) ? $e['date'] : '' );
		$e['start_time']  = Text::parse_time( $e['start_time'] ?? '' );
		$e['end_time']    = Text::parse_time( $e['end_time'] ?? '' );

		$genre      = is_string( $e['genre'] ?? null ) ? strtolower( trim( $e['genre'] ) ) : '';
		$e['genre'] = in_array( $genre, self::GENRES, true ) ? $genre : '';

		foreach ( array( 'venue_name', 'venue_city', 'venue_province', 'venue_country', 'title', 'description', 'ticket_price', 'ticket_link' ) as $field ) {
			$value       = $e[ $field ] ?? '';
			$e[ $field ] = is_scalar( $value ) ? self::clean( (string) $value ) : '';
		}
		$e['ticket_link'] = esc_url_raw( $e['ticket_link'] );
		$e['title']       = mb_substr( $e['title'], 0, 255 );
		$e['ticket_price'] = mb_substr( $e['ticket_price'], 0, 100 );

		return array_intersect_key( $e, array_flip( self::FIELDS ) );
	}

	/**
	 * Strip real HTML tags and decode entities, collapsing whitespace. Unlike
	 * strip_tags() this keeps text such as "We <3 Punk".
	 */
	public static function clean( string $value ): string {
		$value = preg_replace( '#<(script|style)\b[\s\S]*?</\1>#i', ' ', $value );
		$value = preg_replace( '#</?[a-z][a-z0-9-]*(?:\s[^<>]*)?/?>#i', ' ', $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	/** Coerce a list (or comma-separated string) to trimmed unique strings. */
	private static function string_list( $value, bool $split_commas ): array {
		if ( is_string( $value ) ) {
			$value = $split_commas ? explode( ',', $value ) : array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$item = self::clean( (string) $item );
				if ( '' !== $item && ! in_array( $item, $out, true ) ) {
					$out[] = $item;
				}
			}
		}
		return $out;
	}

	/**
	 * Validate a normalized event. Returns a list of error strings (empty = valid).
	 */
	public static function validate( array $e, string $start_date = '', string $end_date = '' ): array {
		$errors = array();
		if ( '' === ( $e['venue_name'] ?? '' ) ) {
			$errors[] = 'missing venue_name';
		}
		if ( '' === ( $e['title'] ?? '' ) ) {
			$errors[] = 'missing title';
		}
		$date = $e['date'] ?? '';
		if ( '' === $date ) {
			$errors[] = 'missing or unparseable date';
		} else {
			if ( '' !== $start_date && $date < $start_date ) {
				$errors[] = "date $date before $start_date";
			}
			if ( '' !== $end_date && $date > $end_date ) {
				$errors[] = "date $date after $end_date";
			}
		}
		return $errors;
	}
}
