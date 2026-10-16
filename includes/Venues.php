<?php
namespace SceneCalendar;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Text;

/**
 * Venue/promoter registry backed by the scenecal_venues table.
 *
 * Venue names arrive in many forms ("HD", "Handsome Daughter", "The Handsome
 * Daughter"). Canonicalization is a strict lookup against each venue's curated
 * alias list — no fuzzy matching, which produced false positives.
 */
final class Venues {

	const TYPES       = array( 'venue', 'promoter' );
	const AGENT_MODES = array( 'fallback', 'always', 'never' );

	/** @var array<string,string>|null normalized alias => canonical name */
	private static ?array $alias_map = null;

	/** @var array<string,int>|null canonical name => id */
	private static ?array $ids_by_name = null;

	/** Decode a DB row into the venue array parsers and the agent consume. */
	public static function hydrate( $row ): array {
		$row     = (array) $row;
		$aliases = json_decode( $row['aliases'] ?? '[]', true );
		$meta    = json_decode( $row['meta'] ?? '{}', true );
		$venue   = array(
			'id'              => (int) $row['id'],
			'name'            => $row['name'],
			'type'            => $row['type'],
			'city'            => $row['city'],
			'province'        => $row['province'],
			'country'         => $row['country'],
			'url'             => $row['url'],
			'instagram'       => $row['instagram'],
			'aliases'         => is_array( $aliases ) ? array_values( $aliases ) : array(),
			'parser'          => $row['parser'],
			'agent_mode'      => $row['agent_mode'],
			'meta'            => is_array( $meta ) ? $meta : array(),
			'enabled'         => (bool) $row['enabled'],
			'sort_order'      => (int) $row['sort_order'],
			'last_run_at'     => $row['last_run_at'],
			'last_run_status' => $row['last_run_status'],
		);
		// Parser config keys are flattened onto the venue for parser convenience
		// (e.g. $venue['3common_uid']), without clobbering core fields.
		foreach ( $venue['meta'] as $key => $value ) {
			if ( ! array_key_exists( $key, $venue ) ) {
				$venue[ $key ] = $value;
			}
		}
		return $venue;
	}

	/** @return array[] All venues ordered for processing. */
	public static function all( bool $enabled_only = false ): array {
		global $wpdb;
		$table = Db::venues();
		$where = $enabled_only ? 'WHERE enabled = 1' : '';
		$rows  = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY sort_order ASC, name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'hydrate' ), $rows ?: array() );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Db::venues();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function count(): int {
		global $wpdb;
		$table = Db::venues();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Insert or update a venue from a (sanitized) array. Returns the id.
	 *
	 * @param array    $data Venue fields; 'aliases' array and 'meta' array are JSON-encoded.
	 * @param int|null $id   Existing id to update.
	 */
	public static function save( array $data, ?int $id = null ): int {
		global $wpdb;
		$type = $data['type'] ?? 'venue';
		$mode = $data['agent_mode'] ?? 'fallback';
		$row  = array(
			'name'       => $data['name'],
			'type'       => in_array( $type, self::TYPES, true ) ? $type : 'venue',
			'city'       => $data['city'] ?? '',
			'province'   => $data['province'] ?? '',
			'country'    => $data['country'] ?? '',
			'url'        => $data['url'] ?? '',
			'instagram'  => ltrim( (string) ( $data['instagram'] ?? '' ), '@' ),
			'aliases'    => wp_json_encode( array_values( array_filter( array_map( 'trim', (array) ( $data['aliases'] ?? array() ) ), 'strlen' ) ) ),
			'parser'     => $data['parser'] ?? 'auto',
			'agent_mode' => in_array( $mode, self::AGENT_MODES, true ) ? $mode : 'fallback',
			'meta'       => wp_json_encode( (object) array_filter( (array) ( $data['meta'] ?? array() ), static fn( $v ) => '' !== $v && null !== $v ) ),
			'enabled'    => empty( $data['enabled'] ) ? 0 : 1,
			'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
			'updated_at' => Db::now(),
		);
		if ( $id ) {
			$wpdb->update( Db::venues(), $row, array( 'id' => $id ) );
		} else {
			$row['created_at'] = Db::now();
			$wpdb->insert( Db::venues(), $row );
			$id = (int) $wpdb->insert_id;
		}
		self::flush();
		return $id;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Db::venues(), array( 'id' => $id ) );
		self::flush();
	}

	public static function record_run( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			Db::venues(),
			array(
				'last_run_at'     => Db::now(),
				'last_run_status' => mb_substr( $status, 0, 250 ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Import venues from a JSON list (the format of data/example-venues-*.json and
	 * the original Python venues.json). Unknown keys become parser meta.
	 * Skips names that already exist. Returns number imported.
	 */
	public static function import_json( string $json ): int {
		$list = json_decode( $json, true );
		if ( ! is_array( $list ) ) {
			return 0;
		}
		$existing = array_map( static fn( $v ) => Text::normalize( $v['name'] ), self::all() );
		$core     = array( 'name', 'type', 'city', 'province', 'country', 'url', 'instagram', 'aliases', 'parser', 'agent_mode', 'enabled', 'sort_order' );
		$count    = 0;
		$order    = self::count();

		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) || in_array( Text::normalize( $item['name'] ), $existing, true ) ) {
				continue;
			}
			$meta = array();
			foreach ( $item as $key => $value ) {
				if ( ! in_array( $key, $core, true ) && is_scalar( $value ) ) {
					$meta[ sanitize_key( $key ) ] = self::sanitize_meta( (string) $value );
				}
			}
			self::save(
				array(
					'name'       => sanitize_text_field( $item['name'] ),
					'type'       => $item['type'] ?? 'venue',
					'city'       => sanitize_text_field( $item['city'] ?? '' ),
					'province'   => sanitize_text_field( $item['province'] ?? '' ),
					'country'    => sanitize_text_field( $item['country'] ?? '' ),
					'url'        => esc_url_raw( $item['url'] ?? '' ),
					'instagram'  => sanitize_text_field( $item['instagram'] ?? '' ),
					'aliases'    => array_map( 'sanitize_text_field', (array) ( $item['aliases'] ?? array() ) ),
					'parser'     => sanitize_key( $item['parser'] ?? 'auto' ),
					// The Python scraper always ran the agent for venues with Instagram.
					'agent_mode' => $item['agent_mode'] ?? ( ! empty( $item['instagram'] ) ? 'always' : 'fallback' ),
					'meta'       => $meta,
					'enabled'    => $item['enabled'] ?? true,
					'sort_order' => $item['sort_order'] ?? $order++,
				)
			);
			$existing[] = Text::normalize( $item['name'] );
			++$count;
		}
		return $count;
	}

	/**
	 * Sanitize a parser setting. URLs go through esc_url_raw(): sanitize_text_field()
	 * strips percent-encoded octets and would break e.g. Google Calendar iCal URLs (%40).
	 */
	public static function sanitize_meta( string $value ): string {
		$value = trim( $value );
		return preg_match( '#^https?://#i', $value ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
	}

	/** Export all venues as JSON in the import format. */
	public static function export_json(): string {
		$out = array();
		foreach ( self::all() as $v ) {
			$item = array(
				'name'       => $v['name'],
				'type'       => $v['type'],
				'city'       => $v['city'],
				'province'   => $v['province'],
				'country'    => $v['country'],
				'url'        => $v['url'],
				'instagram'  => $v['instagram'],
				'aliases'    => $v['aliases'],
				'parser'     => $v['parser'],
				'agent_mode' => $v['agent_mode'],
				'enabled'    => $v['enabled'],
			);
			$out[] = array_merge( $item, $v['meta'] );
		}
		return wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	// -- Canonicalization ---------------------------------------------------

	/** Lookup key: normalized text with a leading "the " stripped. */
	public static function alias_key( string $name ): string {
		$key = Text::normalize( $name );
		return str_starts_with( $key, 'the ' ) ? substr( $key, 4 ) : $key;
	}

	public static function alias_map(): array {
		if ( null === self::$alias_map ) {
			self::$alias_map = array();
			foreach ( self::all() as $venue ) {
				foreach ( array_merge( array( $venue['name'] ), $venue['aliases'] ) as $name ) {
					$key = self::alias_key( (string) $name );
					if ( '' !== $key ) {
						self::$alias_map[ $key ] = $venue['name'];
					}
				}
			}
		}
		return self::$alias_map;
	}

	/** Canonical venue name for a known alias, else the name unchanged. */
	public static function canonicalize( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return $name;
		}
		return self::alias_map()[ self::alias_key( $name ) ] ?? $name;
	}

	/** Venue id for a canonical name, or null. */
	public static function id_for_name( string $name ): ?int {
		if ( null === self::$ids_by_name ) {
			self::$ids_by_name = array();
			foreach ( self::all() as $venue ) {
				self::$ids_by_name[ $venue['name'] ] = $venue['id'];
			}
		}
		return self::$ids_by_name[ self::canonicalize( $name ) ] ?? null;
	}

	/** Venue-equivalence lines for LLM dedup prompts. */
	public static function alias_prompt_lines(): array {
		$lines = array();
		foreach ( self::all() as $venue ) {
			if ( $venue['aliases'] ) {
				$quoted  = implode( ', ', array_map( static fn( $a ) => '"' . $a . '"', $venue['aliases'] ) );
				$lines[] = '- "' . $venue['name'] . '" may also appear as: ' . $quoted;
			}
		}
		return $lines;
	}

	/** Known physical venue whose name or 5+ char alias appears in $text. */
	public static function find_in_text( string $text ): ?string {
		if ( '' === $text ) {
			return null;
		}
		$haystack = mb_strtolower( $text );
		foreach ( self::all() as $venue ) {
			if ( 'venue' !== $venue['type'] ) {
				continue;
			}
			// Short aliases like "HD" appear by accident in prose.
			$names = array_merge( array( $venue['name'] ), array_filter( $venue['aliases'], static fn( $a ) => mb_strlen( $a ) >= 5 ) );
			foreach ( $names as $name ) {
				if ( false !== mb_strpos( $haystack, mb_strtolower( $name ) ) ) {
					return $venue['name'];
				}
			}
		}
		return null;
	}

	public static function flush(): void {
		self::$alias_map   = null;
		self::$ids_by_name = null;
	}
}
