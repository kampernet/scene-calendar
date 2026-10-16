<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Http;

/**
 * GoDaddy Website Builder calendar widget (calendar.apps.secureserver.net).
 *
 * The widget loads its events from a JSON endpoint; the venue must be
 * configured with that endpoint's full URL.
 */
class GoDaddyCalendar extends Parser {

	public static function slug(): string {
		return 'godaddy_calendar';
	}

	public static function label(): string {
		return __( 'GoDaddy calendar widget', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'Sites built with GoDaddy Website Builder that embed its calendar section. Enter the venue\'s website as the URL and the calendar API URL below.', 'scene-calendar' );
	}

	public static function config_fields(): array {
		return array(
			'godaddy_calendar_url' => array(
				'label'    => __( 'Calendar API URL', 'scene-calendar' ),
				'help'     => __( 'Open the venue\'s site with your browser\'s developer tools (Network tab) and copy the full URL of the request to calendar.apps.secureserver.net that returns the events JSON.', 'scene-calendar' ),
				'required' => true,
			),
		);
	}

	public function parse( array $venue, Context $ctx ): array {
		$api_url = trim( (string) ( $venue['godaddy_calendar_url'] ?? '' ) );
		if ( '' === $api_url ) {
			$ctx->log( sprintf( '[godaddy calendar] No calendar API URL configured for %s, skipping', $venue['name'] ?? '?' ) );
			return array();
		}

		$data       = Http::get_json( $api_url );
		$api_events = is_array( $data['events'] ?? null ) ? $data['events'] : array();
		$base_url   = (string) ( $venue['url'] ?? '' );

		$events = array();
		foreach ( $api_events as $ev ) {
			try {
				$event = is_array( $ev ) ? $this->convert( $ev, $base_url, $venue, $ctx ) : null;
				if ( $event ) {
					$events[] = $event;
				}
			} catch ( \Throwable $e ) {
				$ctx->log( '[godaddy calendar] Error parsing event: ' . $e->getMessage() );
			}
		}

		$ctx->log( sprintf( '[godaddy calendar] Extracted %d events (%d total from API)', count( $events ), count( $api_events ) ) );
		return $events;
	}

	private function convert( array $ev, string $base_url, array $venue, Context $ctx ): ?array {
		$start_dt = $ctx->to_local( $ev['start'] ?? '' );
		if ( ! $start_dt ) {
			return null;
		}
		$date = $start_dt->format( 'Y-m-d' );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$title = trim( (string) ( $ev['title'] ?? '' ) );
		if ( '' === $title ) {
			return null;
		}

		$end_dt = $ctx->to_local( $ev['end'] ?? '' );

		$event                = $this->base_event( $venue );
		$event['date']        = $date;
		$event['title']       = $title;
		$event['description'] = trim( (string) ( $ev['desc'] ?? '' ) );
		$event['ticket_link'] = $base_url;
		$event['start_time']  = $start_dt->format( 'H:i' );
		$event['end_time']    = $end_dt ? $end_dt->format( 'H:i' ) : '';
		$event['source_urls'] = '' !== $base_url ? array( $base_url ) : array();
		return $event;
	}
}
