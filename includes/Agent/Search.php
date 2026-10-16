<?php
namespace SceneCalendar\Agent;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Settings;
use SceneCalendar\Util\Http;

/**
 * Web search providers for the agent's search_web tool.
 *
 *  - brave:   Brave Search API (X-Subscription-Token). $5/month of free credit, then metered.
 *  - tavily:  Tavily (Bearer key). 1,000 free searches/month.
 *  - searxng: a SearXNG instance (yours or a trusted one) with the JSON format enabled.
 *  - google:  Google Custom Search JSON API. Closed to new customers; shuts down 2027-01-01.
 *
 * Plugins can replace all of this with the `scene_calendar_search_web` filter.
 */
final class Search {

	public static function provider(): string {
		return (string) Settings::get( 'search_provider' );
	}

	public static function configured(): bool {
		switch ( self::provider() ) {
			case 'brave':
				$ok = '' !== (string) Settings::get( 'brave_api_key' );
				break;
			case 'tavily':
				$ok = '' !== (string) Settings::get( 'tavily_api_key' );
				break;
			case 'searxng':
				$ok = '' !== (string) Settings::get( 'searxng_url' );
				break;
			case 'google':
				$ok = '' !== (string) Settings::get( 'google_api_key' ) && '' !== (string) Settings::get( 'google_cse_id' );
				break;
			default:
				$ok = false;
		}
		return (bool) apply_filters( 'scene_calendar_has_search', $ok );
	}

	/**
	 * Run a search. Returns a list of ['title' => ..., 'url' => ..., 'snippet' => ...].
	 * Throws on HTTP errors (the tool wrapper reports them to the model).
	 */
	public static function query( string $query, int $count ): array {
		$count = min( 10, max( 1, $count ) );
		switch ( self::provider() ) {
			case 'brave':
				$data = Http::get_json(
					'https://api.search.brave.com/res/v1/web/search',
					array(
						'query'   => array(
							'q'     => $query,
							'count' => (string) $count,
						),
						'headers' => array( 'X-Subscription-Token' => (string) Settings::get( 'brave_api_key' ) ),
					)
				);
				return self::map( $data['web']['results'] ?? array(), 'title', 'url', 'description' );

			case 'tavily':
				$data = Http::post_json(
					'https://api.tavily.com/search',
					array(
						'query'       => $query,
						'max_results' => $count,
					),
					array( 'headers' => array( 'Authorization' => 'Bearer ' . Settings::get( 'tavily_api_key' ) ) )
				);
				return self::map( $data['results'] ?? array(), 'title', 'url', 'content' );

			case 'searxng':
				$data = Http::get_json(
					Settings::get( 'searxng_url' ) . '/search',
					array(
						'query'   => array(
							'q'      => $query,
							'format' => 'json',
						),
						// Admin-configured; may be a self-hosted instance on the LAN. No
						// redirects: a "trusted" request must not be bounced elsewhere.
						'trusted'     => true,
						'redirection' => 0,
					)
				);
				return array_slice( self::map( $data['results'] ?? array(), 'title', 'url', 'content' ), 0, $count );

			case 'google':
				$data = Http::get_json(
					'https://www.googleapis.com/customsearch/v1',
					array(
						'query' => array(
							'key' => (string) Settings::get( 'google_api_key' ),
							'cx'  => (string) Settings::get( 'google_cse_id' ),
							'q'   => $query,
							'num' => (string) $count,
						),
					)
				);
				return self::map( $data['items'] ?? array(), 'title', 'link', 'snippet' );
		}
		return array();
	}

	private static function map( $items, string $title, string $url, string $snippet ): array {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item[ $url ] ) ) {
				$out[] = array(
					'title'   => wp_strip_all_tags( (string) ( $item[ $title ] ?? '' ) ),
					'url'     => (string) $item[ $url ],
					'snippet' => wp_strip_all_tags( (string) ( $item[ $snippet ] ?? '' ) ),
				);
			}
		}
		return $out;
	}
}
