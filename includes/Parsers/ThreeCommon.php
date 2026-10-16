<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * 3common.com venue pages.
 *
 * 3common is a JS-rendered SPA — the HTML contains no event data. Events come
 * from its Netlify Functions API:
 *   GET https://3common.com/.netlify/functions/upcoming-events?page=0&_uid=<owner id>
 */
class ThreeCommon extends Parser {

	const API_BASE = 'https://3common.com/.netlify/functions/upcoming-events';

	/** Safety cap on pagination. */
	const MAX_PAGES = 20;

	public static function slug(): string {
		return 'threecommon';
	}

	public static function label(): string {
		return '3common';
	}

	public static function description(): string {
		return __( 'Venues that sell tickets on 3common.com. Enter the venue\'s 3common page (e.g. https://3common.com/u/yourvenue) as the URL.', 'scene-calendar' );
	}

	public static function config_fields(): array {
		return array(
			'3common_uid' => array(
				'label'    => __( '3common owner ID', 'scene-calendar' ),
				'help'     => __( 'The venue\'s 24-character owner ID. Open the venue\'s 3common page with your browser\'s developer tools (Network tab) and look for the "_uid=" parameter on the "upcoming-events" request.', 'scene-calendar' ),
				'required' => true,
			),
		);
	}

	public static function matches_url( string $url ): bool {
		return '3common.com' === self::host( $url ); // host() strips "www."
	}

	public function parse( array $venue, Context $ctx ): array {
		$uid = trim( (string) ( $venue['3common_uid'] ?? '' ) );
		if ( '' === $uid ) {
			$ctx->log( sprintf( '[3common] No 3common owner ID configured for %s, skipping', $venue['name'] ?? '?' ) );
			return array();
		}

		$api_events = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			try {
				$data = Http::get_json(
					self::API_BASE,
					array(
						'query' => array(
							'page' => $page,
							'_uid' => $uid,
						),
					)
				);
			} catch ( \Throwable $e ) {
				// First page failing is a real fetch error; later pages just end pagination.
				if ( 0 === $page ) {
					throw $e;
				}
				$ctx->log( "[3common] Error fetching page $page: " . $e->getMessage() );
				break;
			}

			$batch = is_array( $data['events'] ?? null ) ? $data['events'] : array();
			if ( ! $batch ) {
				break;
			}
			$api_events = array_merge( $api_events, $batch );

			$page_count = (int) ( $data['pagination']['pageCount'] ?? 1 );
			if ( $page + 1 >= $page_count ) {
				break;
			}
		}

		$events = array();
		foreach ( $api_events as $ev ) {
			try {
				$event = $this->convert( $ev, $venue, $ctx );
				if ( $event ) {
					$events[] = $event;
				}
			} catch ( \Throwable $e ) {
				$ctx->log( '[3common] Error parsing event: ' . $e->getMessage() );
			}
		}

		$ctx->log( sprintf( '[3common] Extracted %d events (%d total from API)', count( $events ), count( $api_events ) ) );
		return $events;
	}

	private function convert( array $ev, array $venue, Context $ctx ): ?array {
		$start = $ev['start'] ?? '';
		if ( ! is_string( $start ) || '' === $start ) {
			return null;
		}

		$tz       = $this->event_timezone( $ev, $ctx );
		$start_dt = $this->to_tz( $start, $tz );
		if ( ! $start_dt ) {
			return null;
		}

		$date = $start_dt->format( 'Y-m-d' );
		if ( ! $ctx->in_range( $date ) ) {
			return null;
		}

		$name = trim( (string) ( $ev['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}

		$end_time = '';
		if ( ! empty( $ev['end'] ) && is_string( $ev['end'] ) ) {
			$end_dt = $this->to_tz( $ev['end'], $tz );
			if ( $end_dt ) {
				$end_time = $end_dt->format( 'H:i' );
			}
		}

		$id = (string) ( $ev['_id'] ?? '' );

		$event                 = $this->base_event( $venue );
		$event['date']         = $date;
		$event['title']        = $name;
		$event['ticket_price'] = $this->extract_price( $ev );
		$event['ticket_link']  = '' !== $id ? "https://3common.com/e/$id" : ( $venue['url'] ?? '' );
		$event['start_time']   = $start_dt->format( 'H:i' );
		$event['end_time']     = $end_time;
		$event['source_urls']  = ! empty( $venue['url'] ) ? array( $venue['url'] ) : array();
		return $event;
	}

	/**
	 * Timezone for an event. 3common sends the event's UTC offset in minutes
	 * behind UTC (JS convention); it reflects the event's own location (touring
	 * dates elsewhere) and 3common's current rules (e.g. Manitoba's 2026 switch
	 * to permanent UTC-5), so it beats PHP's possibly stale data for the zone
	 * name. Without an offset, the site timezone is used.
	 */
	private function event_timezone( array $ev, Context $ctx ): \DateTimeZone {
		$tz_info = is_array( $ev['timezone'] ?? null ) ? $ev['timezone'] : array();
		if ( isset( $tz_info['offset'] ) && is_numeric( $tz_info['offset'] ) ) {
			$minutes = -1 * (int) $tz_info['offset'];
			$sign    = $minutes < 0 ? '-' : '+';
			$minutes = abs( $minutes );
			return new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, intdiv( $minutes, 60 ), $minutes % 60 ) );
		}
		return $ctx->tz;
	}

	private function to_tz( string $iso, \DateTimeZone $tz ): ?\DateTimeImmutable {
		try {
			return ( new \DateTimeImmutable( $iso ) )->setTimezone( $tz );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/** Human-readable price from the event's ticket items. */
	private function extract_price( array $ev ): string {
		$items = is_array( $ev['items'] ?? null ) ? $ev['items'] : array();
		if ( ! $items ) {
			return '';
		}
		$currency = (string) ( $ev['currency'] ?? 'CAD' );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type  = (string) ( $item['type'] ?? '' );
			$price = is_numeric( $item['price'] ?? null ) ? (float) $item['price'] : 0.0;

			if ( 'donation' === $type ) {
				$min_cents = $item['minDonationCents'] ?? null;
				if ( is_numeric( $min_cents ) && (int) $min_cents > 0 ) {
					return Text::format_price( (int) $min_cents / 100, true ) . "+ $currency (donation)";
				}
				return 'PWYC';
			}

			if ( $price > 0 ) {
				return Text::format_price( $price, true ) . " $currency";
			}
		}

		// 3common reports price 0 on regular ticket items even for paid shows
		// (the real price is only in the description), so 0 means "unknown".
		return '';
	}
}
