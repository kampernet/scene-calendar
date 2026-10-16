<?php
namespace SceneCalendar\Pipeline;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;
use SceneCalendar\Llm\Client;
use SceneCalendar\Util\Text;

/**
 * Batched LLM lineup extraction and genre classification.
 */
final class Classify {

	const BATCH = 25;

	const GENRE_GUIDE = <<<'TXT'
Genre guidelines:
1. indie/alternative - independent artists, alternative rock, indie pop, shoegaze, dream pop, or a multi-genre bill with a diverse lineup
2. punk/hardcore - punk rock, hardcore, post-punk, ska punk
3. rock - classic rock, hard rock, garage rock
4. metal - heavy, death, black, metalcore, doom
5. electronic/dance - EDM, house, techno, DJ sets
6. hip-hop/rap - hip hop, rap, trap, R&B
7. jazz/blues - jazz, blues, swing, fusion
8. country/folk - country, folk, bluegrass, Americana, singer-songwriter
9. pop - pop, pop-rock, synth-pop
10. tribute/cover - tribute and cover bands
11. comedy/variety - comedy, open mics, variety, karaoke, jam nights
12. unknown - cannot be determined
TXT;

	/** Extract lineups from titles for up to one batch of events. Returns number updated. */
	public static function lineups( Client $llm, array $events, callable $log ): int {
		if ( ! $events ) {
			return 0;
		}
		$payload = array();
		$by_id   = array();
		foreach ( $events as $e ) {
			$payload[]                 = array(
				'id'    => 'e' . $e['id'],
				'title' => $e['title'],
			);
			$by_id[ 'e' . $e['id'] ] = $e;
		}
		$prompt = 'Below is a JSON array of live music event titles. For EACH, extract the performing artist or band names from the title.

Return ONLY a JSON object mapping every id to an array of artist names, e.g. {"e1": ["Artist One", "Artist Two"], "e2": []}.
Use an empty array when the title has no artist names (karaoke night, open mic, contest, etc.).
Do NOT include venue names, event series names or promoter names. No markdown fences.

Events:
' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );

		try {
			$map = Text::decode_llm_json( $llm->complete( $prompt, 6000 ) );
		} catch ( \Throwable $e ) {
			$log( '  lineup extraction failed: ' . $e->getMessage() );
			return 0;
		}
		if ( ! is_array( $map ) ) {
			return 0;
		}
		$updated = 0;
		foreach ( $map as $id => $lineup ) {
			if ( ! isset( $by_id[ $id ] ) || ! is_array( $lineup ) ) {
				continue;
			}
			$lineup = array_values( array_filter( array_map( static fn( $a ) => is_string( $a ) ? trim( $a ) : '', $lineup ), 'strlen' ) );
			// Answered by the model (even with no artists): don't send this title again.
			$fields = array( 'lineup_checked' => 1 );
			if ( $lineup ) {
				$fields['lineup'] = $lineup;
				++$updated;
			}
			Events::set_fields( $by_id[ $id ]['id'], $fields );
		}
		return $updated;
	}

	/** Classify up to one batch of events; misses become "unknown". Returns number classified. */
	public static function genres( Client $llm, array $events, callable $log ): int {
		if ( ! $events ) {
			return 0;
		}
		$payload = array();
		$by_id   = array();
		foreach ( $events as $e ) {
			$payload[]                 = array(
				'id'          => 'e' . $e['id'],
				'title'       => $e['title'],
				'description' => mb_substr( $e['description'], 0, 200 ),
				'venue_name'  => $e['venue_name'],
				'lineup'      => $e['lineup'],
			);
			$by_id[ 'e' . $e['id'] ] = $e;
		}
		$genres = apply_filters( 'scene_calendar_genres', Normalize::GENRES );
		$prompt = self::GENRE_GUIDE . '

Below is a JSON array of live music events. Classify EACH into exactly ONE of: ' . implode( ', ', $genres ) . '.

Return ONLY a JSON object mapping every id to its genre, e.g. {"e1": "rock", "e2": "punk/hardcore"}. Every id must appear. No markdown fences.

Events:
' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );

		$map = null;
		try {
			$map = Text::decode_llm_json( $llm->complete( $prompt, 6000 ) );
		} catch ( \Throwable $e ) {
			$log( '  genre classification failed: ' . $e->getMessage() );
			return 0; // Leave genre empty so the next run retries.
		}
		$map = is_array( $map ) ? $map : array();

		foreach ( $by_id as $id => $event ) {
			$genre = 'unknown';
			if ( isset( $map[ $id ] ) && is_string( $map[ $id ] ) ) {
				$raw = strtolower( trim( $map[ $id ] ) );
				foreach ( $genres as $valid ) {
					if ( str_contains( $raw, $valid ) ) {
						$genre = $valid;
						break;
					}
				}
			}
			Events::set_fields( $event['id'], array( 'genre' => $genre ) );
		}
		return count( $by_id );
	}
}
