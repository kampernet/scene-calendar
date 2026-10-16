<?php
namespace SceneCalendar\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Genre labels and colours for the public calendar.
 *
 * Colours are dark enough for white text (WCAG AA). Change them with:
 *   add_filter( 'scene_calendar_genre_colors', fn( $g ) => array_merge( $g, [ 'rock' => [ 'label' => 'Rock', 'color' => '#000000' ] ] ) );
 */
final class Genres {

	const DEFAULTS = array(
		'indie/alternative' => array( 'Indie/Alt', '#5b21b6' ),
		'punk/hardcore'     => array( 'Punk/Hardcore', '#991b1b' ),
		'rock'              => array( 'Rock', '#b91c1c' ),
		'metal'             => array( 'Metal', '#1f2937' ),
		'electronic/dance'  => array( 'Electronic', '#7c3aed' ),
		'hip-hop/rap'       => array( 'Hip-Hop', '#c2410c' ),
		'jazz/blues'        => array( 'Jazz/Blues', '#1e40af' ),
		'country/folk'      => array( 'Country/Folk', '#15803d' ),
		'pop'               => array( 'Pop', '#be185d' ),
		'tribute/cover'     => array( 'Tribute', '#a16207' ),
		'comedy/variety'    => array( 'Comedy/Variety', '#0e7490' ),
		'unknown'           => array( 'Other', '#6b7280' ),
	);

	/** @return array<string, array{label:string, color:string}> genre slug => meta, in display order. */
	public static function all(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$genres = array();
		foreach ( self::DEFAULTS as $slug => $meta ) {
			$genres[ $slug ] = array(
				'label' => __( $meta[0], 'scene-calendar' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				'color' => $meta[1],
			);
		}
		$genres = apply_filters( 'scene_calendar_genre_colors', $genres );
		$cache  = array();
		foreach ( (array) $genres as $slug => $meta ) {
			$color = sanitize_hex_color( $meta['color'] ?? '' ) ?: '#6b7280';
			$cache[ (string) $slug ] = array(
				'label' => (string) ( $meta['label'] ?? $slug ),
				'color' => $color,
			);
		}
		return $cache;
	}

	/** Meta for an event's genre; empty/unknown/unlisted genres map to "Other". */
	public static function for_event( string $genre ): array {
		$all = self::all();
		$key = isset( $all[ $genre ] ) && '' !== $genre ? $genre : 'unknown';
		return array( 'slug' => $key ) + ( $all[ $key ] ?? array(
			'label' => 'Other',
			'color' => '#6b7280',
		) );
	}
}
