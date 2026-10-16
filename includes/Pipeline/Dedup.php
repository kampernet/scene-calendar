<?php
namespace SceneCalendar\Pipeline;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;
use SceneCalendar\Llm\Client;
use SceneCalendar\Util\Text;
use SceneCalendar\Venues;

/**
 * Duplicate detection.
 *
 * Exact duplicates are caught by the event key. This class handles the fuzzy
 * cases (a promoter and the venue listing the same show under different titles,
 * dates off by one from timezone bugs) in two places:
 *
 *  - check_candidates(): while scraping, new events vs stored events on nearby dates.
 *  - window_groups():    after scraping, all upcoming events in sliding two-day
 *                        windows, so each LLM call sees a small batch (large
 *                        batches made small models unreliable).
 */
final class Dedup {

	const CANDIDATE_BATCH = 20;
	const WINDOW_MAX      = 40;

	/** Sort key preferring events with more filled fields, then longer descriptions. */
	public static function richness( array $event ): array {
		$filled = 0;
		foreach ( array( 'description', 'lineup', 'ticket_price', 'ticket_link', 'start_time', 'end_time', 'source_urls', 'genre', 'venue_city' ) as $field ) {
			if ( ! empty( $event[ $field ] ) ) {
				++$filled;
			}
		}
		return array( $filled, mb_strlen( (string) ( $event['description'] ?? '' ) ) );
	}

	private static function trim_for_prompt( array $event, string $id ): array {
		return array(
			'id'          => $id,
			'title'       => $event['title'],
			'venue_name'  => $event['venue_name'],
			'date'        => $event['date'],
			'start_time'  => $event['start_time'] ?? '',
			'lineup'      => $event['lineup'] ?? array(),
			'description' => mb_substr( (string) ( $event['description'] ?? '' ), 0, 200 ),
			// URLs often carry the original act name after a venue renames a show.
			'urls'        => self::urls( $event ),
		);
	}

	private static function alias_section(): string {
		$lines = Venues::alias_prompt_lines();
		return $lines
			? "\nKnown venue name equivalences (each line is ONE physical venue and other names it appears under):\n" . implode( "\n", $lines ) . "\n"
			: '';
	}

	/** Dates within +/- 1 day of each given date. */
	private static function neighbour_dates( array $dates ): array {
		$out = array();
		foreach ( $dates as $date ) {
			$ts = strtotime( $date . ' 12:00:00 UTC' );
			foreach ( array( -1, 0, 1 ) as $offset ) {
				$out[ gmdate( 'Y-m-d', $ts + $offset * DAY_IN_SECONDS ) ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Which candidates duplicate already-stored events?
	 *
	 * @param array<string, array> $candidates key => normalized event
	 * @return array<string, true> candidate keys that are duplicates
	 */
	public static function check_candidates( Client $llm, array $candidates, callable $log ): array {
		$duplicates = array();
		foreach ( array_chunk( $candidates, self::CANDIDATE_BATCH, true ) as $batch ) {
			$existing = Events::query(
				array(
					'dates'  => self::neighbour_dates( array_unique( array_column( $batch, 'date' ) ) ),
					'status' => Events::KNOWN_STATUSES,
				)
			);
			if ( ! $existing ) {
				continue;
			}
			// Only send candidates with a plausible match among nearby events, and only those matches.
			$existing_payload  = array();
			$candidate_payload = array();
			foreach ( $batch as $key => $c ) {
				$near = self::neighbour_dates( array( $c['date'] ) );
				foreach ( $existing as $e ) {
					if ( in_array( $e['date'], $near, true ) && self::plausible_pair( $c, $e ) ) {
						$existing_payload[ $e['id'] ] = self::trim_for_prompt( $e, 'e' . $e['id'] );
						$candidate_payload[ $key ]    = self::trim_for_prompt( $c, 'c' . $key );
					}
				}
			}
			if ( ! $candidate_payload ) {
				continue;
			}
			$existing_payload  = array_values( $existing_payload );
			$candidate_payload = array_values( $candidate_payload );

			$prompt = 'You are a duplicate event detector for a live music calendar.

Below are two JSON arrays: "existing" events already in the calendar, and "candidates" that may be new.

Identify which candidates are the SAME real-world show as an existing event. Duplicates share the same or an adjacent date (off by one from timezone errors) AND have a similar title or overlapping lineup. A common pattern is a promoter listing the same show as the venue: titles differ slightly but the date, lineup or venue match. Descriptions often name the real venue and date.

IMPORTANT: Different shows CAN happen at the same venue on the same night. Only flag clear matches.
' . self::alias_section() . '
Return ONLY a JSON array of duplicate candidate ids, e.g. ["cabc123","cdef456"]. If none, return []. No markdown fences.

Existing events:
' . wp_json_encode( $existing_payload, JSON_UNESCAPED_UNICODE ) . '

Candidate events:
' . wp_json_encode( $candidate_payload, JSON_UNESCAPED_UNICODE );

			try {
				$ids = Text::decode_llm_json( $llm->complete( $prompt, 4000 ) );
			} catch ( \Throwable $e ) {
				$log( '  LLM dedup failed: ' . $e->getMessage() );
				continue;
			}
			if ( ! is_array( $ids ) ) {
				$log( '  LLM dedup returned non-list; skipping batch' );
				continue;
			}
			foreach ( $ids as $id ) {
				$key = is_string( $id ) ? substr( $id, 1 ) : '';
				if ( isset( $batch[ $key ] ) ) {
					$duplicates[ $key ] = true;
				}
			}
		}
		return $duplicates;
	}

	/**
	 * Deterministic duplicate check for two stored events, used before the LLM:
	 * same date, and either the same known venue with near-identical titles, or
	 * one side has an Unknown venue and the normalized titles are identical.
	 */
	public static function obviously_same( array $a, array $b ): bool {
		if ( $a['date'] !== $b['date'] ) {
			return false;
		}
		$same_venue = Venues::canonicalize( $a['venue_name'] ) === Venues::canonicalize( $b['venue_name'] );
		$unknown    = 'Unknown' === $a['venue_name'] || 'Unknown' === $b['venue_name'];
		$ta         = Text::normalize( $a['title'] );
		$tb         = Text::normalize( $b['title'] );
		if ( $same_venue && Text::similarity( $ta, $tb ) >= 0.9 ) {
			return true;
		}
		return $unknown && '' !== $ta && $ta === $tb;
	}

	const STOPWORDS = array( 'the', 'and', 'with', 'w', 'presents', 'present', 'live', 'at', 'a', 'an', 'of', 'feat', 'ft', 'featuring', 'tour', 'night', 'show', 'in', 'on', 'for', 'to', 'guests', 'special', 'plus', 'vs', 'x' );

	/** Meaningful lowercase tokens of a title. */
	private static function tokens( string $title ): array {
		$tokens = array_filter( explode( ' ', Text::normalize( $title ) ), static fn( $t ) => strlen( $t ) > 1 && ! in_array( $t, self::STOPWORDS, true ) );
		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Cheap pre-filter: could these two events be the same show? True when the
	 * titles share a meaningful share of words, the lineups overlap, or one
	 * event's artist appears in the other's title. False negatives cost a missed
	 * duplicate; false positives only cost an LLM call.
	 */
	/** Ticket and source URLs of an event, without query strings, de-duplicated. */
	private static function urls( array $event ): array {
		$urls = array_merge( array( (string) ( $event['ticket_link'] ?? '' ) ), (array) ( $event['source_urls'] ?? array() ) );
		$out  = array();
		foreach ( $urls as $url ) {
			$url = strtolower( rtrim( (string) strtok( (string) $url, '?#' ), '/' ) );
			if ( '' !== $url && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		return $out;
	}

	/** Words in an event's URL paths ("/tm-event/raythenihilist-break-dancing/" -> "raythenihilist break dancing"). */
	private static function url_words( array $event ): string {
		$words = array();
		foreach ( self::urls( $event ) as $url ) {
			$words[] = preg_replace( '/[^a-z0-9]+/', ' ', (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}
		return ' ' . trim( preg_replace( '/\s+/', ' ', implode( ' ', $words ) ) ) . ' ';
	}

	public static function plausible_pair( array $a, array $b ): bool {
		// Same known venue, same night: always worth asking (a venue rarely lists
		// more than one or two shows a night, so this is cheap).
		$venue_a = (string) ( $a['venue_name'] ?? '' );
		if ( '' !== ( $a['date'] ?? '' ) && ( $a['date'] ?? '' ) === ( $b['date'] ?? '' ) && '' !== $venue_a && 'Unknown' !== $venue_a
			&& Venues::canonicalize( $venue_a ) === Venues::canonicalize( (string) ( $b['venue_name'] ?? '' ) ) ) {
			return true;
		}
		// Same ticket link (not source pages: a venue's listing page is shared by all its shows).
		$ticket = static fn( $e ) => strtolower( rtrim( (string) strtok( (string) ( $e['ticket_link'] ?? '' ), '?#' ), '/' ) );
		if ( '' !== $ticket( $a ) && $ticket( $a ) === $ticket( $b ) && ! in_array( $ticket( $a ), self::urls( array( 'source_urls' => $a['source_urls'] ?? array() ) ), true ) ) {
			return true;
		}
		$ta = self::tokens( $a['title'] );
		$tb = self::tokens( $b['title'] );
		if ( $ta && $tb ) {
			$shared = count( array_intersect( $ta, $tb ) );
			if ( $shared / min( count( $ta ), count( $tb ) ) >= 0.5 ) {
				return true;
			}
		}
		$la = array_filter( array_map( array( Text::class, 'normalize' ), $a['lineup'] ?? array() ) );
		$lb = array_filter( array_map( array( Text::class, 'normalize' ), $b['lineup'] ?? array() ) );
		if ( array_intersect( $la, $lb ) ) {
			return true;
		}
		// An artist from one event named in the other's title, description or URLs.
		$text_a = ' ' . Text::normalize( ( $a['title'] ?? '' ) . ' ' . ( $a['description'] ?? '' ) ) . self::url_words( $a );
		$text_b = ' ' . Text::normalize( ( $b['title'] ?? '' ) . ' ' . ( $b['description'] ?? '' ) ) . self::url_words( $b );
		foreach ( $la as $artist ) {
			if ( strlen( $artist ) > 3 && str_contains( $text_b, ' ' . $artist . ' ' ) ) {
				return true;
			}
		}
		foreach ( $lb as $artist ) {
			if ( strlen( $artist ) > 3 && str_contains( $text_a, ' ' . $artist . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find duplicate groups among events dated $date or the following day.
	 *
	 * @param array[] $events Stored events (publish) on $date and $date+1.
	 * @return array<int, int[]> groups of event ids (each group >= 2)
	 */
	public static function window_groups( ?Client $llm, array $events, callable $log ): array {
		if ( count( $events ) < 2 ) {
			return array();
		}
		$groups = array();

		// Deterministic pre-pass.
		$n = count( $events );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( self::obviously_same( $events[ $i ], $events[ $j ] ) ) {
					$groups[] = array( $events[ $i ]['id'], $events[ $j ]['id'] );
				}
			}
		}

		if ( ! $llm ) {
			return $groups;
		}

		// Only ask the LLM about events that have a plausible partner; most windows have none.
		$keep = array();
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( self::plausible_pair( $events[ $i ], $events[ $j ] ) ) {
					$keep[ $i ] = true;
					$keep[ $j ] = true;
				}
			}
		}
		if ( ! $keep ) {
			return $groups;
		}
		$events = array_values( array_intersect_key( $events, $keep ) );
		$n      = count( $events );
		if ( $n > self::WINDOW_MAX ) {
			$log( "  window of $n events exceeds " . self::WINDOW_MAX . '; LLM dedup limited to the first ' . self::WINDOW_MAX );
			$events = array_slice( $events, 0, self::WINDOW_MAX );
		}

		$by_id   = array();
		$payload = array();
		foreach ( $events as $e ) {
			$by_id[ $e['id'] ] = true;
			$payload[]         = self::trim_for_prompt( $e, 'e' . $e['id'] );
		}

		$prompt = 'You are a duplicate event detector for a live music calendar.

Below is a JSON array of events on two consecutive dates. Identify groups of events that are the SAME real-world show. Duplicates share the same or adjacent date AND a similar title or overlapping lineup — typically a promoter and the venue listing the same show. Venues sometimes rename a show after announcing it, so also compare descriptions and the words in the URLs (e.g. an event page slug naming the act).

IMPORTANT: Different shows CAN happen at the same venue on the same night — those are NOT duplicates. Only group clear matches.
' . self::alias_section() . '
Return ONLY a JSON array of groups of ids, e.g. [["e1","e2"],["e7","e9","e12"]]. If none, return []. No markdown fences.

Events:
' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );

		try {
			$decoded = Text::decode_llm_json( $llm->complete( $prompt, 4000 ) );
		} catch ( \Throwable $e ) {
			$log( '  LLM dedup failed: ' . $e->getMessage() );
			return $groups;
		}
		if ( ! is_array( $decoded ) ) {
			$log( '  LLM dedup returned non-list' );
			return $groups;
		}
		foreach ( $decoded as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$ids = array();
			foreach ( $group as $id ) {
				$id = is_string( $id ) ? (int) substr( $id, 1 ) : 0;
				if ( isset( $by_id[ $id ] ) ) {
					$ids[] = $id;
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( count( $ids ) >= 2 ) {
				$groups[] = $ids;
			}
		}
		return $groups;
	}

	/**
	 * Mark all but the best event of each group as status=duplicate.
	 * Groups are merged transitively first. Locked (admin-edited) events are never demoted.
	 *
	 * @param array<int,int[]> $groups
	 * @return int number of events marked duplicate
	 */
	public static function resolve( array $groups, callable $log ): int {
		// Union-find over ids.
		$parent = array();
		$find   = static function ( $x ) use ( &$parent, &$find ) {
			if ( ! isset( $parent[ $x ] ) || $parent[ $x ] === $x ) {
				$parent[ $x ] = $x;
				return $x;
			}
			return $parent[ $x ] = $find( $parent[ $x ] );
		};
		foreach ( $groups as $group ) {
			$root = $find( $group[0] );
			foreach ( $group as $id ) {
				$parent[ $find( $id ) ] = $root;
			}
		}
		$sets = array();
		foreach ( array_keys( $parent ) as $id ) {
			$sets[ $find( $id ) ][] = $id;
		}

		$marked = 0;
		foreach ( $sets as $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$events = array_values( array_filter( array_map( array( Events::class, 'get' ), $ids ) ) );
			// Events an editor has declared "not a duplicate" take no part (neither kept nor demoted).
			$events = array_values( array_filter( $events, static fn( $e ) => 'publish' === $e['status'] && ! $e['dedup_exempt'] ) );
			if ( count( $events ) < 2 ) {
				continue;
			}
			usort(
				$events,
				static function ( $a, $b ) {
					// Locked first, then a known venue over Unknown, then the venue's own
					// listing over a promoter/aggregator copy (its times are authoritative),
					// then the richest.
					$first_party = static fn( $e ) => (int) ( str_starts_with( (string) $e['found_by'], $e['venue_name'] . ' (' ) && ! str_ends_with( (string) $e['found_by'], ' (agent)' ) );
					return array( (int) $b['locked'], (int) ( 'Unknown' !== $b['venue_name'] ), $first_party( $b ), self::richness( $b ) )
						<=> array( (int) $a['locked'], (int) ( 'Unknown' !== $a['venue_name'] ), $first_party( $a ), self::richness( $a ) );
				}
			);
			$keeper = array_shift( $events );
			foreach ( $events as $dup ) {
				if ( $dup['locked'] ) {
					continue;
				}
				Events::set_status( $dup['id'], 'duplicate', $keeper['id'] );
				$log( sprintf( '  duplicate: "%s" (%s, %s) -> keeping #%d "%s"', $dup['title'], $dup['venue_name'], $dup['date'], $keeper['id'], $keeper['title'] ) );
				++$marked;
			}
		}
		return $marked;
	}
}
