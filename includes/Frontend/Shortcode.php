<?php
namespace SceneCalendar\Frontend;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;

/**
 * [scene_calendar] shortcode.
 *
 * Renders an accessible list grouped by date on the server (works without JS
 * and is crawlable, with schema.org MusicEvent markup). The script adds venue
 * and genre filters and a month-grid view.
 *
 * Attributes:
 *   view    list | month          (default list)
 *   days    how many days ahead   (default 90; 0 = all)
 *   venue   only this venue name
 *   genre   only this genre
 *   filters yes | no              (default yes)
 *   limit   max events            (default 300)
 *   align   wide | full           (break out of a narrow content column; theme must support it)
 */
final class Shortcode {

	public static function register(): void {
		add_shortcode( 'scene_calendar', array( self::class, 'render' ) );
		CalendarExport::register();
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	public static function register_assets(): void {
		wp_register_style( 'scene-calendar', SCENECAL_URL . 'assets/public.css', array(), SCENECAL_VERSION );
		wp_register_script( 'scene-calendar', SCENECAL_URL . 'assets/public.js', array(), SCENECAL_VERSION, true );
	}

	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'view'    => 'list',
				'days'    => '90',
				'venue'   => '',
				'genre'   => '',
				'filters' => 'yes',
				'limit'   => '300',
				'align'   => '',
			),
			$atts,
			'scene_calendar'
		);

		$today = wp_date( 'Y-m-d' );
		$days  = max( 0, (int) $atts['days'] );
		$args  = array(
			'from'  => $today,
			'limit' => min( 1000, max( 1, (int) $atts['limit'] ) ),
		);
		if ( $days > 0 ) {
			$args['to'] = wp_date( 'Y-m-d', strtotime( "+$days days" ) );
		}
		if ( '' !== $atts['venue'] ) {
			$args['venue'] = sanitize_text_field( $atts['venue'] );
		}
		if ( '' !== $atts['genre'] ) {
			$args['genre'] = sanitize_text_field( $atts['genre'] );
		}
		$events = apply_filters( 'scene_calendar_shortcode_events', Events::query( $args ), $atts );

		wp_enqueue_style( 'scene-calendar' );
		wp_enqueue_script( 'scene-calendar' );

		$venues = array_values( array_unique( array_column( $events, 'venue_name' ) ) );
		sort( $venues, SORT_NATURAL | SORT_FLAG_CASE );
		// Genre chips: every genre present, in palette order, with counts (JS recounts per view).
		$genre_counts = array();
		foreach ( $events as $e ) {
			$slug                  = Genres::for_event( $e['genre'] )['slug'];
			$genre_counts[ $slug ] = ( $genre_counts[ $slug ] ?? 0 ) + 1;
		}
		$genres = array_intersect_key( Genres::all(), $genre_counts );

		$by_date = array();
		foreach ( $events as $e ) {
			$by_date[ $e['date'] ][] = $e;
		}

		$view = 'month' === $atts['view'] ? 'month' : 'list';
		$uid  = 'scenecal-' . wp_unique_id();

		ob_start();
		?>
		<div class="scenecal<?php echo in_array( $atts['align'], array( 'wide', 'full' ), true ) ? ' align' . esc_attr( $atts['align'] ) : ''; ?>" id="<?php echo esc_attr( $uid ); ?>" data-view="<?php echo esc_attr( $view ); ?>" data-today="<?php echo esc_attr( $today ); ?>" data-locale="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
			<?php if ( 'no' !== $atts['filters'] ) : ?>
				<?php if ( count( $genres ) > 1 ) : ?>
					<div class="scenecal-genres" role="group" aria-label="<?php esc_attr_e( 'Filter by genre', 'scene-calendar' ); ?>" hidden>
						<?php foreach ( $genres as $slug => $meta ) : ?>
							<button type="button" class="scenecal-chip" data-genre="<?php echo esc_attr( $slug ); ?>" aria-pressed="false" style="--scenecal-g:<?php echo esc_attr( $meta['color'] ); ?>">
								<?php echo esc_html( $meta['label'] ); ?>
								<span class="scenecal-chip-count"><?php echo (int) $genre_counts[ $slug ]; ?></span>
							</button>
						<?php endforeach; ?>
						<button type="button" class="scenecal-chip-clear" hidden><?php esc_html_e( 'Show all', 'scene-calendar' ); ?></button>
					</div>
				<?php endif; ?>
				<div class="scenecal-toolbar" hidden>
					<label class="scenecal-field">
						<span><?php esc_html_e( 'Venue', 'scene-calendar' ); ?></span>
						<select data-filter="venue">
							<option value=""><?php esc_html_e( 'All venues', 'scene-calendar' ); ?></option>
							<?php foreach ( $venues as $v ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="scenecal-field scenecal-search">
						<span><?php esc_html_e( 'Search', 'scene-calendar' ); ?></span>
						<input type="search" data-filter="q" placeholder="<?php esc_attr_e( 'Artist, venue…', 'scene-calendar' ); ?>">
					</label>
					<div class="scenecal-viewtoggle" role="group" aria-label="<?php esc_attr_e( 'View', 'scene-calendar' ); ?>">
						<button type="button" data-view-btn="list" aria-pressed="<?php echo 'list' === $view ? 'true' : 'false'; ?>"><?php esc_html_e( 'List', 'scene-calendar' ); ?></button>
						<button type="button" data-view-btn="month" aria-pressed="<?php echo 'month' === $view ? 'true' : 'false'; ?>"><?php esc_html_e( 'Month', 'scene-calendar' ); ?></button>
					</div>
				</div>
			<?php endif; ?>

			<div class="scenecal-month" hidden></div>

			<div class="scenecal-list">
				<?php if ( ! $events ) : ?>
					<p class="scenecal-empty"><?php esc_html_e( 'No upcoming shows listed yet.', 'scene-calendar' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $by_date as $date => $day_events ) : ?>
					<?php $ts = strtotime( $date . ' 12:00:00 UTC' ); ?>
					<section class="scenecal-day" data-date="<?php echo esc_attr( $date ); ?>">
						<h3 class="scenecal-date">
							<time datetime="<?php echo esc_attr( $date ); ?>"><?php echo esc_html( wp_date( 'l, F j', $ts, new \DateTimeZone( 'UTC' ) ) ); ?></time>
						</h3>
						<ul class="scenecal-events">
							<?php foreach ( $day_events as $e ) : ?>
								<?php self::render_event( $e ); ?>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endforeach; ?>
				<p class="scenecal-nomatch" hidden><?php esc_html_e( 'No shows match these filters.', 'scene-calendar' ); ?></p>
			</div>
			<?php echo self::modal_data( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG. ?>
			<dialog class="scenecal-modal" aria-labelledby="<?php echo esc_attr( $uid ); ?>-modal-title">
				<div class="scenecal-modal-inner">
					<button type="button" class="scenecal-modal-close" aria-label="<?php esc_attr_e( 'Close', 'scene-calendar' ); ?>">&times;</button>
					<h2 class="scenecal-modal-title" id="<?php echo esc_attr( $uid ); ?>-modal-title"></h2>
					<span class="scenecal-genre scenecal-modal-genre"></span>
					<dl class="scenecal-modal-facts">
						<dt data-k="when"><?php esc_html_e( 'Date & time', 'scene-calendar' ); ?></dt><dd data-k="when"></dd>
						<dt data-k="venue"><?php esc_html_e( 'Venue', 'scene-calendar' ); ?></dt><dd data-k="venue"></dd>
						<dt data-k="price"><?php esc_html_e( 'Price', 'scene-calendar' ); ?></dt><dd data-k="price"></dd>
						<dt data-k="lineup"><?php esc_html_e( 'Lineup', 'scene-calendar' ); ?></dt><dd data-k="lineup" class="scenecal-modal-lineup"></dd>
						<dt data-k="description"><?php esc_html_e( 'Description', 'scene-calendar' ); ?></dt><dd data-k="description" class="scenecal-modal-desc"></dd>
					</dl>
					<p class="scenecal-modal-actions">
						<a class="scenecal-btn scenecal-btn-primary" data-k="link" target="_blank" rel="noopener"></a>
					</p>
					<p class="scenecal-modal-addcal">
						<span><?php esc_html_e( 'Add to calendar', 'scene-calendar' ); ?></span>
						<a class="scenecal-btn" data-k="gcal" target="_blank" rel="noopener"><?php esc_html_e( 'Google Calendar', 'scene-calendar' ); ?></a>
						<a class="scenecal-btn" data-k="ics" download><?php esc_html_e( 'Download .ics', 'scene-calendar' ); ?></a>
					</p>
				</div>
			</dialog>
			<?php echo self::json_ld( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG. ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_event( array $e ): void {
		$time   = $e['start_time'] ? self::format_time( $e['start_time'] ) : '';
		$search = mb_strtolower( $e['title'] . ' ' . $e['venue_name'] . ' ' . implode( ' ', $e['lineup'] ) );
		$lineup = array_filter( $e['lineup'], static fn( $a ) => false === mb_stripos( $e['title'], $a ) );
		$genre  = Genres::for_event( $e['genre'] );
		?>
		<li class="scenecal-event" style="--scenecal-g:<?php echo esc_attr( $genre['color'] ); ?>" data-id="<?php echo (int) $e['id']; ?>" data-venue="<?php echo esc_attr( $e['venue_name'] ); ?>" data-genre="<?php echo esc_attr( $genre['slug'] ); ?>" data-search="<?php echo esc_attr( $search ); ?>" data-date="<?php echo esc_attr( $e['date'] ); ?>" data-time="<?php echo esc_attr( $time ); ?>">
			<div class="scenecal-time"><?php echo '' !== $time ? esc_html( $time ) : '&nbsp;'; ?></div>
			<div class="scenecal-body">
				<h4 class="scenecal-title">
					<?php $href = CalendarExport::link( $e ); // Tickets, else the source page (no-JS fallback). ?>
					<?php if ( '' !== $href ) : ?>
						<a href="<?php echo esc_url( $href ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $e['title'] ); ?></a>
					<?php else : ?>
						<button type="button" class="scenecal-title-btn"><?php echo esc_html( $e['title'] ); ?></button>
					<?php endif; ?>
				</h4>
				<?php if ( $lineup ) : ?>
					<p class="scenecal-lineup"><?php echo esc_html( implode( ' · ', $lineup ) ); ?></p>
				<?php endif; ?>
				<p class="scenecal-meta">
					<span class="scenecal-venue"><?php echo esc_html( $e['venue_name'] ); ?></span>
					<?php if ( $e['ticket_price'] ) : ?>
						<span class="scenecal-price"><?php echo esc_html( $e['ticket_price'] ); ?></span>
					<?php endif; ?>
					<?php if ( 'unknown' !== $genre['slug'] ) : ?>
						<span class="scenecal-genre"><?php echo esc_html( $genre['label'] ); ?></span>
					<?php endif; ?>
				</p>
			</div>
		</li>
		<?php
	}

	/**
	 * Per-event details for the modal, as one JSON block keyed by event id. All
	 * values are plain text; the script renders them with textContent.
	 */
	private static function modal_data( array $events ): string {
		$data = array();
		foreach ( $events as $e ) {
			$genre = Genres::for_event( $e['genre'] );
			$date  = wp_date( 'l, F j, Y', strtotime( $e['date'] . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
			$time  = $e['start_time'] ? self::format_time( $e['start_time'] ) . ( $e['end_time'] ? ' – ' . self::format_time( $e['end_time'] ) : '' ) : '';
			$link  = CalendarExport::link( $e );

			$data[ $e['id'] ] = array(
				'title'       => $e['title'],
				/* translators: 1: date, 2: time */
				'when'        => '' !== $time ? sprintf( __( '%1$s at %2$s', 'scene-calendar' ), $date, $time ) : $date,
				'venue'       => CalendarExport::location( $e ),
				'price'       => $e['ticket_price'],
				'lineup'      => array_values( $e['lineup'] ),
				'description' => $e['description'],
				'genre'       => 'unknown' === $genre['slug'] ? '' : $genre['label'],
				'color'       => $genre['color'],
				'link'        => esc_url_raw( $link ),
				'linkLabel'   => $e['ticket_link'] ? __( 'Get tickets / more info', 'scene-calendar' ) : __( 'More info', 'scene-calendar' ),
				// Google Calendar link parts; the URL is built in the browser on demand
				// (pre-building it for every event roughly doubled the page weight).
				'cal'         => CalendarExport::google_parts( $e ),
				'ics'         => CalendarExport::ics_url( $e ),
			);
		}
		return '<script type="application/json" class="scenecal-data">' . wp_json_encode( (object) $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
	}

	/** "20:00" -> site time format (e.g. "8:00 pm"). */
	private static function format_time( string $hhmm ): string {
		$dt = \DateTimeImmutable::createFromFormat( 'H:i', $hhmm, new \DateTimeZone( 'UTC' ) );
		return $dt ? wp_date( get_option( 'time_format', 'g:i a' ), $dt->getTimestamp(), new \DateTimeZone( 'UTC' ) ) : $hhmm;
	}

	/** schema.org MusicEvent markup for search engines. */
	private static function json_ld( array $events ): string {
		if ( ! $events || ! apply_filters( 'scene_calendar_output_json_ld', true ) ) {
			return '';
		}
		$tz    = wp_timezone();
		$items = array();
		foreach ( array_slice( $events, 0, 100 ) as $e ) {
			$start = $e['date'] . ( $e['start_time'] ? 'T' . $e['start_time'] . ':00' : '' );
			if ( $e['start_time'] ) {
				$start = ( new \DateTimeImmutable( $start, $tz ) )->format( 'c' );
			}
			$item = array(
				'@context'            => 'https://schema.org',
				'@type'               => 'MusicEvent',
				'name'                => $e['title'],
				'startDate'           => $start,
				'eventStatus'         => 'https://schema.org/EventScheduled',
				'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
				'location'            => array(
					'@type'   => 'Place',
					'name'    => $e['venue_name'],
					'address' => trim( implode( ', ', array_filter( array( $e['venue_city'], $e['venue_province'], $e['venue_country'] ) ) ) ),
				),
			);
			if ( $e['lineup'] ) {
				$item['performer'] = array_map(
					static fn( $a ) => array(
						'@type' => 'PerformingGroup',
						'name'  => $a,
					),
					$e['lineup']
				);
			}
			if ( $e['ticket_link'] ) {
				$item['offers'] = array(
					'@type' => 'Offer',
					'url'   => $e['ticket_link'],
				);
			}
			$items[] = $item;
		}
		return '<script type="application/ld+json">' . wp_json_encode( $items, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}
}
