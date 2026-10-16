<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * Sites using the TicketWeb "Event Discovery" WordPress plugin in calendar view.
 *
 * Two generations of the plugin are supported:
 *  - Inline: the page embeds `var all_events = [...]` (id, start, title, doors,
 *    displayTime) plus hidden `#tw-event-dialog-{id}` divs with full details.
 *  - AJAX (plugin 7.x): the page embeds a jQuery.ajax call to admin-ajax.php
 *    with action `get_events_for_calendar`, a nonce and a params blob. POSTing
 *    the same fields returns JSON {events: [...stubs], popupdata: "<dialogs html>"}.
 *
 * Stubs give the date; dialogs give lineup, price, times and ticket links.
 */
class TicketWebCalendar extends Parser {

	/** Max days requested from the AJAX endpoint when the window is unbounded. */
	const MAX_WINDOW_DAYS = 365;

	public static function slug(): string {
		return 'ticketweb_calendar';
	}

	public static function label(): string {
		return __( 'TicketWeb plugin (calendar view)', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'WordPress sites using the TicketWeb event plugin in calendar layout (a month grid with event pop-ups). Enter the calendar page URL.', 'scene-calendar' );
	}

	public function parse( array $venue, Context $ctx ): array {
		$url  = $venue['url'] ?? '';
		$page = Http::get( $url );

		$stubs = self::extract_inline_stubs( $page );
		$popup = $page;

		if ( ! $stubs ) {
			$ajax = self::extract_ajax_config( $page );
			if ( ! $ajax ) {
				$ctx->log( 'No TicketWeb calendar data (inline all_events or admin-ajax config) found on ' . $url );
				return array();
			}
			$end = $ctx->end_date;
			$max = ( new \DateTimeImmutable( $ctx->start_date ) )->modify( '+' . self::MAX_WINDOW_DAYS . ' days' )->format( 'Y-m-d' );
			if ( $end > $max ) {
				$end = $max;
			}
			$response = Http::request(
				'POST',
				$ajax['url'],
				array(
					'body'    => array(
						'action' => 'get_events_for_calendar',
						'nonce'  => $ajax['nonce'],
						'start'  => $ctx->start_date,
						'end'    => $end,
						'params' => $ajax['params'],
					),
					'headers' => array(
						'X-Requested-With' => 'XMLHttpRequest',
						'Referer'          => $url,
					),
					'timeout' => 45,
				)
			);
			$data = json_decode( $response['body'], true );
			if ( ! is_array( $data ) || empty( $data['events'] ) || ! is_array( $data['events'] ) ) {
				$ctx->log( 'TicketWeb calendar AJAX returned no events' );
				return array();
			}
			$stubs = array();
			foreach ( $data['events'] as $item ) {
				if ( is_array( $item ) && ! empty( $item['id'] ) && ! empty( $item['start'] ) ) {
					$stubs[] = array(
						'id'          => (string) $item['id'],
						'start'       => (string) $item['start'],
						'title'       => html_entity_decode( (string) ( $item['title'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
						'doors'       => (string) ( $item['doors'] ?? '' ),
						'displayTime' => (string) ( $item['displayTime'] ?? '' ),
					);
				}
			}
			$popup = is_string( $data['popupdata'] ?? null ) ? $data['popupdata'] : '';
		}

		$ctx->log( sprintf( 'Found %d TicketWeb calendar entries', count( $stubs ) ) );

		$xp     = Html::load( '<html><body>' . $popup . '</body></html>' );
		$events = array();
		$seen   = array();
		foreach ( $stubs as $stub ) {
			$date = substr( $stub['start'], 0, 10 );
			if ( isset( $seen[ $stub['id'] ] ) || ! $ctx->in_range( $date ) || self::is_skipped_title( $stub['title'] ) ) {
				continue;
			}
			$seen[ $stub['id'] ] = true;
			try {
				$dialog = Html::first( $xp, '//div[@id="tw-event-dialog-' . preg_replace( '/[^A-Za-z0-9_-]/', '', $stub['id'] ) . '"]' );
				$event  = $dialog
					? $this->from_dialog( $xp, $dialog, $stub, $date, $venue )
					: $this->from_stub( $stub, $date, $venue );
				if ( $event ) {
					$events[] = $event;
				}
			} catch ( \Throwable $e ) {
				$ctx->log( 'Error parsing TicketWeb event ' . $stub['id'] . ': ' . $e->getMessage() );
			}
		}
		return $events;
	}

	/** Titles for closures and private bookings rather than shows. */
	public static function is_skipped_title( string $title ): bool {
		$t = strtolower( trim( $title ) );
		return 'private event' === $t || str_starts_with( $t, 'closed' );
	}

	/** Parse the legacy `var all_events = [ {id: '..', start: '..', ...}, ... ];` JS array. */
	public static function extract_inline_stubs( string $html ): array {
		if ( ! preg_match( '/var\s+all_events\s*=\s*\[(.*?)\];/s', $html, $m ) ) {
			return array();
		}
		$stubs = array();
		if ( preg_match_all( '/\{(.*?)\}/s', $m[1], $objects ) ) {
			foreach ( $objects[1] as $obj ) {
				$stub = array();
				foreach ( array( 'id', 'start', 'title', 'doors', 'displayTime' ) as $field ) {
					$stub[ $field ] = preg_match( '/\b' . $field . ':\s*\'(.*?)\'/s', $obj, $fm )
						? html_entity_decode( $fm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' )
						: '';
				}
				if ( '' !== $stub['id'] && '' !== $stub['start'] ) {
					$stubs[] = $stub;
				}
			}
		}
		return $stubs;
	}

	/** Find the admin-ajax URL, nonce and params blob of the plugin's calendar loader. */
	public static function extract_ajax_config( string $html ): ?array {
		if ( ! preg_match( '/action:\s*[\'"]get_events_for_calendar[\'"]/', $html, $am, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		// Look in a window around the action for the url, nonce and params of the same call.
		$offset = max( 0, $am[0][1] - 1500 );
		$chunk  = substr( $html, $offset, 20000 );
		if ( ! preg_match( '/nonce:\s*[\'"]([^\'"]+)[\'"]/', $chunk, $nm ) ) {
			return null;
		}
		$url = preg_match( '/url:\s*[\'"]([^\'"]*admin-ajax\.php)[\'"]/', $chunk, $um ) ? $um[1] : '';
		if ( '' === $url ) {
			return null;
		}
		$params = preg_match( "/params:\s*'([^']*)'/", $chunk, $pm ) ? $pm[1] : '';
		return array(
			'url'    => $url,
			'nonce'  => $nm[1],
			'params' => $params,
		);
	}

	private function from_dialog( \DOMXPath $xp, \DOMElement $dialog, array $stub, string $date, array $venue ): array {
		$name_link = Html::first( $xp, './/*[' . Html::cls( 'tw-name' ) . ']//a', $dialog );
		$title     = Html::text( $name_link );
		if ( '' === $title ) {
			$title = $stub['title'];
		}
		$more_info = Html::attr( $name_link, 'href' );

		$lineup = array();
		foreach ( Html::all( $xp, './/*[' . Html::cls( 'tw-attractions' ) . ']//span', $dialog ) as $span ) {
			$artist = Html::text( $span );
			if ( '' !== $artist ) {
				$lineup[] = $artist;
			}
		}

		$show_time = Text::parse_time( Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-event-time-complete' ) . ']', $dialog ) ) );
		if ( '' === $show_time ) {
			$show_time = Text::parse_time( $stub['displayTime'] );
		}
		$door_time = Text::parse_time( Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-event-door-time' ) . ']', $dialog ) ) );
		if ( '' === $door_time ) {
			$door_time = Text::parse_time( $stub['doors'] );
		}

		$price       = TicketWebList::parse_price( Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-price' ) . ']', $dialog ) ) );
		$ticket_link = Html::attr( Html::first( $xp, './/a[' . Html::cls( 'tw-buy-tix-btn' ) . ']', $dialog ), 'href' );
		$description = Text::truncate( Html::text( Html::first( $xp, './/*[' . Html::cls( 'tw-description' ) . ']', $dialog ) ) );
		$source      = '' !== $more_info ? $more_info : $ticket_link;

		$event                 = $this->base_event( $venue );
		$event['date']         = $date;
		$event['title']        = $title;
		$event['description']  = $description;
		$event['lineup']       = $lineup;
		$event['ticket_price'] = $price;
		$event['ticket_link']  = $ticket_link;
		$event['start_time']   = '' !== $show_time ? $show_time : $door_time;
		$event['source_urls']  = '' !== $source ? array( $source ) : array( $venue['url'] ?? '' );
		return $event;
	}

	private function from_stub( array $stub, string $date, array $venue ): ?array {
		if ( '' === $stub['title'] ) {
			return null;
		}
		$show = Text::parse_time( $stub['displayTime'] );

		$event                = $this->base_event( $venue );
		$event['date']        = $date;
		$event['title']       = $stub['title'];
		$event['start_time']  = '' !== $show ? $show : Text::parse_time( $stub['doors'] );
		$event['source_urls'] = array( $venue['url'] ?? '' );
		return $event;
	}
}
