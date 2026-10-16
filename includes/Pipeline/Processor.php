<?php
namespace SceneCalendar\Pipeline;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;
use SceneCalendar\Llm\Client;
use SceneCalendar\Util\Text;
use SceneCalendar\Venues;

/**
 * Turn one venue's raw events (from a parser or the agent) into stored rows:
 * normalize -> backfill venue -> canonicalize -> promoter/venue checks ->
 * validate -> exact-key dedup -> LLM dedup against nearby events -> upsert.
 */
final class Processor {

	/**
	 * @param array       $raw_events Raw event arrays.
	 * @param array       $venue      Hydrated venue the events were found for.
	 * @param string      $start      Range start (Y-m-d).
	 * @param string      $end        Range end (Y-m-d) or ''.
	 * @param Client|null $llm        For fuzzy dedup; skipped when null.
	 * @param string      $found_by   Provenance label, e.g. "Sidestage (ticketweb_list)".
	 * @param callable    $log        fn(string): void
	 * @param string      $status     Status for new events ('publish' or 'pending').
	 * @return array{inserted:int, enriched:int, seen:int, skipped:int, invalid:int, duplicates:int, failed:int}
	 */
	public static function run( array $raw_events, array $venue, string $start, string $end, ?Client $llm, string $found_by, callable $log, string $status = 'publish' ): array {
		$stats = array_fill_keys( array( 'inserted', 'enriched', 'seen', 'skipped', 'invalid', 'duplicates', 'failed' ), 0 );

		$valid = array();
		foreach ( $raw_events as $raw ) {
			$event = self::prepare( Normalize::event( $raw ), $venue, $log );
			if ( null === $event ) {
				++$stats['invalid'];
				continue;
			}
			$errors = Normalize::validate( $event, $start, $end );
			if ( $errors ) {
				$log( sprintf( '  invalid "%s": %s', $event['title'] ?: '?', implode( '; ', $errors ) ) );
				++$stats['invalid'];
				continue;
			}
			$key = Events::key_for( $event );
			if ( isset( $valid[ $key ] ) ) {
				// Same venue/date/title twice in one batch: keep the richer copy.
				if ( Dedup::richness( $event ) > Dedup::richness( $valid[ $key ] ) ) {
					$valid[ $key ] = $event;
				}
				continue;
			}
			$valid[ $key ] = $event;
		}

		if ( ! $valid ) {
			return $stats;
		}

		// Events whose key already exists skip the LLM: they're handled by upsert.
		$new = array();
		foreach ( $valid as $key => $event ) {
			if ( ! Events::find_by_key( $key ) ) {
				$new[ $key ] = $event;
			}
		}

		// A venue's own parser listing is never dropped as a "duplicate" of a
		// promoter's copy stored earlier: it is stored, and the post-run dedup pass
		// keeps the first-party copy (Dedup::resolve). Promoters and the agent are
		// checked inline.
		$first_party    = 'venue' === $venue['type'] && ! str_ends_with( $found_by, ' (agent)' );
		$duplicate_keys = array();
		if ( $llm && $new && ! $first_party ) {
			$duplicate_keys = Dedup::check_candidates( $llm, $new, $log );
		}

		foreach ( $valid as $key => $event ) {
			if ( isset( $duplicate_keys[ $key ] ) ) {
				$log( sprintf( '  duplicate of existing: "%s" @ %s on %s', $event['title'], $event['venue_name'], $event['date'] ) );
				++$stats['duplicates'];
				continue;
			}
			$result = Events::upsert_scraped( $event, $found_by, $status );
			if ( 'failed' === $result['action'] ) {
				$log( sprintf( '  could not store "%s": %s', $event['title'], $result['error'] ?? '' ) );
			}
			++$stats[ $result['action'] ];
		}
		return $stats;
	}

	/** Backfill/canonicalize the venue and apply promoter/venue checks. Null = drop. */
	private static function prepare( array $event, array $venue, callable $log ): ?array {
		if ( ! $event ) {
			return null;
		}
		$is_promoter = 'promoter' === $venue['type'];

		// Agents often omit location fields when scraping a venue's own pages.
		if ( '' === $event['venue_name'] && ! $is_promoter ) {
			$event['venue_name'] = $venue['name'];
		}
		foreach ( array( 'city', 'province', 'country' ) as $field ) {
			if ( '' === $event[ "venue_$field" ] ) {
				$event[ "venue_$field" ] = $venue[ $field ];
			}
		}
		$event['venue_name'] = Venues::canonicalize( $event['venue_name'] );

		if ( $is_promoter ) {
			// A promoter is never the venue; recover the venue from the description.
			$returned = $event['venue_name'];
			if ( '' === $returned || Text::similarity( $returned, $venue['name'] ) >= 0.6 ) {
				$found               = Venues::find_in_text( $event['description'] . ' ' . $event['title'] );
				$event['venue_name'] = $found ?? 'Unknown';
				$log( sprintf( '  promoter name used as venue: "%s" -> "%s"', $returned, $event['venue_name'] ) );
			}
			return $event;
		}

		$returned = $event['venue_name'];
		if ( Venues::canonicalize( $returned ) !== Venues::canonicalize( $venue['name'] ) ) {
			// Not a known alias: accept close/containing names, else it's another venue's show.
			$a = str_replace( ' ', '', Text::normalize( $returned ) );
			$b = str_replace( ' ', '', Text::normalize( $venue['name'] ) );
			if ( Text::similarity( $returned, $venue['name'] ) < 0.6 && ! str_contains( $a, $b ) && ! str_contains( $b, $a ) ) {
				$log( sprintf( '  venue mismatch: "%s" is not "%s"; dropped "%s"', $returned, $venue['name'], $event['title'] ) );
				return null;
			}
			$event['venue_name'] = $venue['name'];
		}
		return $event;
	}
}
