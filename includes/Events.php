<?php
namespace SceneCalendar;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Text;

/**
 * Event storage in the scenecal_events table.
 *
 * Each row has two keys:
 *  - event_key:  hash of the CURRENT venue|date|title (unique).
 *  - origin_key: hash of what the scraper first saw. Never changes.
 *
 * When the scraper finds an event it looks up both keys, so an event an admin
 * has edited (locked) or deleted (status=deleted tombstone) is not re-added
 * or overwritten on the next run.
 */
final class Events {

	/** publish | pending (AI-found, awaiting review) | hidden | duplicate | deleted (tombstone). */
	const STATUSES = array( 'publish', 'pending', 'hidden', 'duplicate', 'deleted' );

	/** Statuses that block the scraper from re-adding an event (everything that exists). */
	const KNOWN_STATUSES = array( 'publish', 'pending', 'hidden', 'deleted' );

	public static function hydrate( $row ): array {
		$row    = (array) $row;
		$lineup = json_decode( $row['lineup'] ?: '[]', true );
		$urls   = json_decode( $row['source_urls'] ?: '[]', true );
		return array(
			'id'             => (int) $row['id'],
			'event_key'      => $row['event_key'],
			'origin_key'     => $row['origin_key'],
			'venue_id'       => null === $row['venue_id'] ? null : (int) $row['venue_id'],
			'venue_name'     => $row['venue_name'],
			'venue_city'     => $row['venue_city'],
			'venue_province' => $row['venue_province'],
			'venue_country'  => $row['venue_country'],
			'date'           => $row['event_date'],
			'start_time'     => $row['start_time'],
			'end_time'       => $row['end_time'],
			'title'          => $row['title'],
			'description'    => $row['description'],
			'lineup'         => is_array( $lineup ) ? $lineup : array(),
			'ticket_price'   => $row['ticket_price'],
			'ticket_link'    => $row['ticket_link'],
			'source_urls'    => is_array( $urls ) ? $urls : array(),
			'genre'          => $row['genre'],
			'status'         => $row['status'],
			'duplicate_of'   => null === $row['duplicate_of'] ? null : (int) $row['duplicate_of'],
			'source'         => $row['source'],
			'found_by'       => $row['found_by'],
			'locked'         => (bool) $row['locked'],
			'dedup_exempt'   => ! empty( $row['dedup_exempt'] ),
			'created_at'     => $row['created_at'],
			'updated_at'     => $row['updated_at'],
			'last_seen_at'   => $row['last_seen_at'],
		);
	}

	/**
	 * May a scrape labelled $incoming overwrite fields stored by $stored?
	 * Labels look like "<Venue> (<parser slug>|agent)". Same venue label and a
	 * source at least as reliable: parser > agent > import. So a venue switching
	 * parsers, or its parser confirming an agent-found show, can correct the
	 * stored copy, while a promoter's listing never overwrites the venue's.
	 */
	public static function may_correct( string $stored, string $incoming ): bool {
		if ( $stored === $incoming ) {
			return true;
		}
		$label = static fn( $f ) => preg_replace( '/ \([^)]*\)$/', '', $f );
		$rank  = static function ( $f ) {
			if ( 'import' === $f ) {
				return 0;
			}
			return str_ends_with( $f, ' (agent)' ) ? 1 : 2;
		};
		return '' !== $label( $incoming ) && $label( $stored ) === $label( $incoming ) && $rank( $incoming ) >= $rank( $stored );
	}

	public static function key_for( array $event ): string {
		return Text::event_key( (string) $event['venue_name'], (string) $event['date'], (string) $event['title'] );
	}

	/** Map a normalized event array to DB columns. */
	private static function columns( array $e ): array {
		return array(
			'venue_name'     => mb_substr( (string) ( $e['venue_name'] ?? '' ), 0, 191 ),
			'venue_city'     => mb_substr( (string) ( $e['venue_city'] ?? '' ), 0, 100 ),
			'venue_province' => mb_substr( (string) ( $e['venue_province'] ?? '' ), 0, 100 ),
			'venue_country'  => mb_substr( (string) ( $e['venue_country'] ?? '' ), 0, 100 ),
			'event_date'     => (string) $e['date'],
			'start_time'     => (string) ( $e['start_time'] ?? '' ),
			'end_time'       => (string) ( $e['end_time'] ?? '' ),
			'title'          => mb_substr( (string) ( $e['title'] ?? '' ), 0, 255 ),
			'description'    => mb_strcut( (string) ( $e['description'] ?? '' ), 0, 60000 ), // TEXT column is 64 KB.
			'lineup'         => wp_json_encode( array_values( $e['lineup'] ?? array() ), JSON_UNESCAPED_UNICODE ),
			'ticket_price'   => mb_substr( (string) ( $e['ticket_price'] ?? '' ), 0, 100 ),
			// An over-long (tracking) URL would make the insert fail; drop it instead.
			'ticket_link'    => strlen( (string) ( $e['ticket_link'] ?? '' ) ) <= 2048 ? (string) ( $e['ticket_link'] ?? '' ) : '',
			'source_urls'    => wp_json_encode( array_values( $e['source_urls'] ?? array() ), JSON_UNESCAPED_SLASHES ),
			'genre'          => (string) ( $e['genre'] ?? '' ),
			'venue_id'       => Venues::id_for_name( (string) ( $e['venue_name'] ?? '' ) ),
		);
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Db::events();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/** Row whose current or origin key equals $key (any status), or null. */
	public static function find_by_key( string $key ): ?array {
		global $wpdb;
		$table = Db::events();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE event_key = %s OR origin_key = %s ORDER BY id ASC LIMIT 1", $key, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Store an event found by the scraper.
	 *
	 * @param string $status Status for a new row: 'publish', or 'pending' to hold it for review.
	 * @return array{action:string, id:int} action: inserted | enriched | seen | skipped | failed
	 */
	public static function upsert_scraped( array $event, string $found_by, string $status = 'publish' ): array {
		global $wpdb;
		$key      = self::key_for( $event );
		$existing = self::find_by_key( $key );
		$now      = Db::now();

		if ( ! $existing ) {
			$row = self::columns( $event ) + array(
				'event_key'    => $key,
				'origin_key'   => $key,
				'status'       => 'pending' === $status ? 'pending' : 'publish',
				'source'       => 'scraper',
				'found_by'     => mb_substr( $found_by, 0, 191 ),
				'locked'       => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
				'last_seen_at' => $now,
			);
			if ( false === $wpdb->insert( Db::events(), $row ) ) {
				return array(
					'action' => 'failed',
					'id'     => 0,
					'error'  => $wpdb->last_error,
				);
			}
			return array(
				'action' => 'inserted',
				'id'     => (int) $wpdb->insert_id,
			);
		}

		$wpdb->update( Db::events(), array( 'last_seen_at' => $now ), array( 'id' => $existing['id'] ) );

		// A pending show (held for review) that a later publishing source confirms is published.
		if ( 'pending' === $existing['status'] && 'publish' === $status && ! $existing['locked'] ) {
			$wpdb->update( Db::events(), array( 'status' => 'publish' ), array( 'id' => $existing['id'] ) );
			$existing['status'] = 'publish';
		}

		// Admin-touched, hidden or removed rows are never modified by the scraper.
		// Duplicate and pending copies still get corrections (status unchanged).
		if ( $existing['locked'] || in_array( $existing['status'], array( 'hidden', 'deleted' ), true ) ) {
			return array(
				'action' => 'skipped',
				'id'     => $existing['id'],
			);
		}

		// Fill in fields the stored copy is missing. When the same source reports a
		// different value (a changed show time, a new ticket link, a corrected time
		// zone), it wins; another source never overwrites, so two listings of the
		// same show can't flip-flop each week.
		$same_source = self::may_correct( $existing['found_by'], $found_by );
		$fill        = array();
		foreach ( array( 'description', 'ticket_price', 'ticket_link', 'start_time', 'end_time', 'venue_city', 'venue_province', 'venue_country' ) as $field ) {
			$incoming = (string) ( $event[ $field ] ?? '' );
			if ( '' !== $incoming && ( '' === (string) $existing[ $field ] || ( $same_source && $incoming !== (string) $existing[ $field ] ) ) ) {
				$fill[ $field ] = $event[ $field ];
			}
		}
		// Same length limits as inserts (see columns()).
		$limits = self::columns( array_merge( $event, array( 'date' => $existing['date'] ) ) );
		foreach ( $fill as $field => $value ) {
			if ( isset( $limits[ $field ] ) ) {
				$fill[ $field ] = $limits[ $field ];
			}
		}
		$fill = array_filter( $fill, static fn( $v ) => '' !== $v );
		if ( ! empty( $event['lineup'] ) && ( ! $existing['lineup'] || ( $same_source && array_values( $event['lineup'] ) !== $existing['lineup'] ) ) ) {
			$fill['lineup'] = wp_json_encode( array_values( $event['lineup'] ), JSON_UNESCAPED_UNICODE );
		}
		$urls = array_values( array_unique( array_merge( $existing['source_urls'], $event['source_urls'] ?? array() ) ) );
		if ( count( $urls ) !== count( $existing['source_urls'] ) ) {
			$fill['source_urls'] = wp_json_encode( $urls, JSON_UNESCAPED_SLASHES );
		}
		if ( $fill ) {
			$fill['updated_at'] = $now;
			$wpdb->update( Db::events(), $fill, array( 'id' => $existing['id'] ) );
			return array(
				'action' => 'enriched',
				'id'     => $existing['id'],
			);
		}
		return array(
			'action' => 'seen',
			'id'     => $existing['id'],
		);
	}

	/**
	 * Create or update an event from the admin form. Edits lock the row so the
	 * scraper leaves it alone. Returns the id, or a \WP_Error on key collision.
	 *
	 * @return int|\WP_Error
	 */
	public static function save_manual( array $event, ?int $id = null, string $status = 'publish' ) {
		global $wpdb;
		$key   = self::key_for( $event );
		$table = Db::events();
		$clash = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE event_key = %s AND id <> %d", $key, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $clash ) {
			return new \WP_Error(
				'scenecal_duplicate',
				sprintf(
					/* translators: %d: id of the existing event */
					__( 'Another event (#%d) already has the same venue, date and title.', 'scene-calendar' ),
					(int) $clash
				)
			);
		}

		$now = Db::now();
		$row = self::columns( $event ) + array(
			'event_key'  => $key,
			'status'     => in_array( $status, self::STATUSES, true ) ? $status : 'publish',
			'locked'     => 1,
			'updated_at' => $now,
		);
		if ( 'duplicate' !== $row['status'] ) {
			$row['duplicate_of'] = null;
		}

		if ( $id ) {
			$wpdb->update( Db::events(), $row, array( 'id' => $id ) );
			return $id;
		}
		$row += array(
			'origin_key' => $key,
			'source'     => 'manual',
			'found_by'   => wp_get_current_user()->user_login ?? '',
			'created_at' => $now,
		);
		$wpdb->insert( Db::events(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete an event. Scraped events become tombstones (status=deleted) so the
	 * next scrape doesn't re-add them; manual events are removed outright.
	 */
	public static function delete( int $id, bool $force = false ): void {
		global $wpdb;
		$event = self::get( $id );
		if ( ! $event ) {
			return;
		}
		if ( $force || 'manual' === $event['source'] ) {
			$wpdb->delete( Db::events(), array( 'id' => $id ) );
			return;
		}
		$wpdb->update(
			Db::events(),
			array(
				'status'     => 'deleted',
				'updated_at' => Db::now(),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Change an event's status. $lock = true for editor actions, so the decision
	 * sticks (e.g. a duplicate an editor re-published is not demoted again).
	 */
	public static function set_status( int $id, string $status, ?int $duplicate_of = null, bool $lock = false ): void {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$data = array(
			'status'       => $status,
			'duplicate_of' => 'duplicate' === $status ? $duplicate_of : null,
			'updated_at'   => Db::now(),
		);
		if ( $lock ) {
			$data['locked'] = 1;
		}
		$wpdb->update( Db::events(), $data, array( 'id' => $id ) );
	}

	public static function set_fields( int $id, array $fields ): void {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'genre', 'lineup', 'lineup_checked', 'dedup_exempt' ) ) );
		if ( isset( $allowed['lineup'] ) && is_array( $allowed['lineup'] ) ) {
			$allowed['lineup'] = wp_json_encode( array_values( $allowed['lineup'] ), JSON_UNESCAPED_UNICODE );
		}
		if ( $allowed ) {
			$allowed['updated_at'] = Db::now();
			$wpdb->update( Db::events(), $allowed, array( 'id' => $id ) );
		}
	}

	/**
	 * Published events in a date window.
	 *
	 * @param array $args from, to (Y-m-d), venue, genre, status (default publish), limit.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table  = Db::events();
		$where  = array( '1=1' );
		$params = array();

		$statuses = (array) ( $args['status'] ?? 'publish' );
		$where[]  = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$params   = array_merge( $params, $statuses );

		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'event_date >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'event_date <= %s';
			$params[] = $args['to'];
		}
		if ( ! empty( $args['dates'] ) ) {
			$where[] = 'event_date IN (' . implode( ',', array_fill( 0, count( $args['dates'] ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_values( $args['dates'] ) );
		}
		if ( ! empty( $args['venue'] ) ) {
			$where[]  = 'venue_name = %s';
			$params[] = $args['venue'];
		}
		if ( ! empty( $args['genre'] ) ) {
			$where[]  = 'genre = %s';
			$params[] = $args['genre'];
		}
		if ( ! empty( $args['venue_like'] ) ) {
			$where[]  = 'venue_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['venue_like'] ) . '%';
		}
		if ( isset( $args['genre_missing'] ) && $args['genre_missing'] ) {
			$where[] = "genre = ''";
		}
		if ( ! empty( $args['lineup_missing'] ) ) {
			$where[] = "(lineup = '[]' OR lineup = '') AND lineup_checked = 0";
		}
		if ( ! empty( $args['unlocked'] ) ) {
			$where[] = 'locked = 0';
		}
		$limit = isset( $args['limit'] ) ? ' LIMIT ' . max( 1, (int) $args['limit'] ) : '';

		$sql  = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . " ORDER BY event_date ASC, start_time ASC, title ASC$limit"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( self::class, 'hydrate' ), $rows ?: array() );
	}

	/** Distinct venue names and genres among upcoming published events (for filters). */
	public static function facets( string $from ): array {
		global $wpdb;
		$table  = Db::events();
		$venues = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT venue_name FROM $table WHERE status = 'publish' AND event_date >= %s ORDER BY venue_name", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$genres = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT genre FROM $table WHERE status = 'publish' AND event_date >= %s AND genre <> '' ORDER BY genre", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'venues' => $venues ?: array(),
			'genres' => $genres ?: array(),
		);
	}

	/** Remove events that ended more than $days ago (0 = keep forever). */
	public static function purge_past( int $days, string $today ): int {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$table  = Db::events();
		$cutoff = gmdate( 'Y-m-d', strtotime( $today . " -$days days" ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE event_date < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count_by_status(): array {
		global $wpdb;
		$table = Db::events();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array_fill_keys( self::STATUSES, 0 );
		foreach ( $rows ?: array() as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Import events from the original Python scraper's events.json format.
	 * Returns [inserted, skipped].
	 */
	public static function import_json( string $json ): array {
		$list = json_decode( $json, true );
		if ( ! is_array( $list ) ) {
			return array( 0, 0 );
		}
		$inserted = 0;
		$skipped  = 0;
		foreach ( $list as $raw ) {
			$event = Pipeline\Normalize::event( $raw );
			if ( Pipeline\Normalize::validate( $event ) ) {
				++$skipped;
				continue;
			}
			$event['venue_name'] = Venues::canonicalize( $event['venue_name'] );
			$result              = self::upsert_scraped( $event, 'import' );
			if ( 'inserted' === $result['action'] ) {
				global $wpdb;
				$wpdb->update( Db::events(), array( 'source' => 'import' ), array( 'id' => $result['id'] ) );
				++$inserted;
			} else {
				++$skipped;
			}
		}
		return array( $inserted, $skipped );
	}
}
