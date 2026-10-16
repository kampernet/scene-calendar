<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of direct parsers. A venue stores a parser slug:
 *   'auto' -> first parser whose matches_url() accepts the venue URL (else none)
 *   'none' -> skip direct parsing; use the AI agent only
 *   <slug> -> that parser
 *
 * Third-party code can add parsers with the `scene_calendar_parsers` filter:
 *   add_filter( 'scene_calendar_parsers', fn( $classes ) => [ ...$classes, My_Parser::class ] );
 */
final class Registry {

	const AUTO = 'auto';
	const NONE = 'none';

	/** @return array<string, class-string<Parser>> slug => class */
	public static function all(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$classes = array(
			ThreeCommon::class,
			Showpass::class,
			Eventbrite::class,
			FestApp::class,
			Rss::class,
			ICal::class,
			TicketWebList::class,
			TicketWebCalendar::class,
			Squarespace::class,
			TribeEvents::class,
			GoDaddyCalendar::class,
			JsonLd::class,
			PosterVision::class,
		);
		$classes = apply_filters( 'scene_calendar_parsers', $classes );

		$cache = array();
		foreach ( $classes as $class ) {
			if ( is_string( $class ) && class_exists( $class ) && is_subclass_of( $class, Parser::class ) ) {
				$cache[ $class::slug() ] = $class;
			}
		}
		return $cache;
	}

	/** Dropdown options: slug => label, including auto/none. */
	public static function options(): array {
		$options = array(
			self::AUTO => __( 'Auto-detect from URL', 'scene-calendar' ),
			self::NONE => __( 'None — AI agent only', 'scene-calendar' ),
		);
		foreach ( self::all() as $slug => $class ) {
			$options[ $slug ] = $class::label();
		}
		return $options;
	}

	/** Resolve a venue's configured parser to an instance, or null. */
	public static function for_venue( array $venue ): ?Parser {
		$slug = $venue['parser'] ?? self::AUTO;
		$url  = (string) ( $venue['url'] ?? '' );
		$all  = self::all();

		if ( self::NONE === $slug ) {
			return null;
		}
		if ( self::AUTO === $slug || '' === $slug ) {
			if ( '' === $url ) {
				return null;
			}
			foreach ( $all as $class ) {
				if ( $class::matches_url( $url ) ) {
					return new $class();
				}
			}
			return null;
		}
		return isset( $all[ $slug ] ) ? new $all[ $slug ]() : null;
	}

	/** Every config field across parsers: meta key => [field def + 'parser' => slug]. */
	public static function config_fields(): array {
		$fields = array();
		foreach ( self::all() as $slug => $class ) {
			foreach ( $class::config_fields() as $key => $def ) {
				if ( ! isset( $fields[ $key ] ) ) {
					$fields[ $key ] = $def + array( 'parsers' => array() );
				}
				$fields[ $key ]['parsers'][] = $slug;
			}
		}
		return $fields;
	}
}
