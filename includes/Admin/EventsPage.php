<?php
namespace SceneCalendar\Admin;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;
use SceneCalendar\Pipeline\Normalize;
use SceneCalendar\Settings;
use SceneCalendar\Venues;

/**
 * Events list and add/edit form.
 */
final class EventsPage {

	public static function render(): void {
		$table = new EventsTable();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Events', 'scene-calendar' ); ?></h1>
			<a href="<?php echo esc_url( Menu::url( 'event' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Event', 'scene-calendar' ); ?></a>
			<hr class="wp-header-end">
			<?php $table->views(); ?>
			<?php // One GET form, like core's Posts screen: search, filters, pagination and bulk actions. ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( sanitize_key( $_GET['view'] ?? 'upcoming' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
				<?php $table->search_box( __( 'Search events', 'scene-calendar' ), 'scenecal-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public static function render_form(): void {
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event = $id ? Events::get( $id ) : null;
		if ( $id && ! $event ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Event not found.', 'scene-calendar' ) . '</p></div>';
			return;
		}
		$draft = get_transient( 'scenecal_event_draft_' . get_current_user_id() );
		if ( $draft ) {
			delete_transient( 'scenecal_event_draft_' . get_current_user_id() );
		}
		$e = array_merge(
			array(
				'title'          => '',
				'date'           => '',
				'start_time'     => '',
				'end_time'       => '',
				'venue_name'     => '',
				'venue_city'     => Settings::get( 'default_city' ),
				'venue_province' => Settings::get( 'default_province' ),
				'venue_country'  => Settings::get( 'default_country' ),
				'lineup'         => array(),
				'description'    => '',
				'ticket_price'   => '',
				'ticket_link'    => '',
				'source_urls'    => array(),
				'genre'          => '',
				'status'         => 'publish',
				'locked'         => false,
			),
			$event ?? array(),
			is_array( $draft ) ? $draft : array()
		);
		$venues = Venues::all();
		?>
		<div class="wrap scenecal-form">
			<h1><?php echo $event ? esc_html__( 'Edit Event', 'scene-calendar' ) : esc_html__( 'Add Event', 'scene-calendar' ); ?></h1>
			<?php if ( $event && 'manual' !== $event['source'] ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: 1: source label, 2: found-by label, 3: last seen date */
						esc_html__( 'Found by the scraper (%1$s: %2$s), last seen %3$s.', 'scene-calendar' ),
						esc_html( $event['source'] ),
						esc_html( $event['found_by'] ),
						esc_html( $event['last_seen_at'] ? get_date_from_gmt( $event['last_seen_at'] ) : '—' )
					);
					echo ' ';
					esc_html_e( 'Saving your changes locks this event so future runs will not overwrite it.', 'scene-calendar' );
					?>
				</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scenecal_save_event">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'scenecal_save_event' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sc-title"><?php esc_html_e( 'Title', 'scene-calendar' ); ?> *</label></th>
						<td><input name="title" id="sc-title" type="text" class="large-text" required value="<?php echo esc_attr( $e['title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sc-date"><?php esc_html_e( 'Date & time', 'scene-calendar' ); ?> *</label></th>
						<td>
							<input name="date" id="sc-date" type="date" required value="<?php echo esc_attr( $e['date'] ); ?>">
							<label><?php esc_html_e( 'Start', 'scene-calendar' ); ?> <input name="start_time" type="time" value="<?php echo esc_attr( $e['start_time'] ); ?>"></label>
							<label><?php esc_html_e( 'End', 'scene-calendar' ); ?> <input name="end_time" type="time" value="<?php echo esc_attr( $e['end_time'] ); ?>"></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sc-venue"><?php esc_html_e( 'Venue', 'scene-calendar' ); ?> *</label></th>
						<td>
							<input name="venue_name" id="sc-venue" type="text" class="regular-text" required list="sc-venues" value="<?php echo esc_attr( $e['venue_name'] ); ?>">
							<datalist id="sc-venues">
								<?php foreach ( $venues as $v ) : ?>
									<?php if ( 'venue' === $v['type'] ) : ?>
										<option value="<?php echo esc_attr( $v['name'] ); ?>"></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</datalist>
							<p class="description"><?php esc_html_e( 'Known venue aliases are converted to the venue\'s main name when saved.', 'scene-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Location', 'scene-calendar' ); ?></th>
						<td>
							<input name="venue_city" type="text" placeholder="<?php esc_attr_e( 'City', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $e['venue_city'] ); ?>">
							<input name="venue_province" type="text" size="6" placeholder="<?php esc_attr_e( 'Prov./State', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $e['venue_province'] ); ?>">
							<input name="venue_country" type="text" placeholder="<?php esc_attr_e( 'Country', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $e['venue_country'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sc-lineup"><?php esc_html_e( 'Lineup', 'scene-calendar' ); ?></label></th>
						<td><textarea name="lineup" id="sc-lineup" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'One artist per line', 'scene-calendar' ); ?>"><?php echo esc_textarea( implode( "\n", $e['lineup'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="sc-desc"><?php esc_html_e( 'Description', 'scene-calendar' ); ?></label></th>
						<td><textarea name="description" id="sc-desc" rows="4" class="large-text"><?php echo esc_textarea( $e['description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="sc-price"><?php esc_html_e( 'Tickets', 'scene-calendar' ); ?></label></th>
						<td>
							<input name="ticket_price" id="sc-price" type="text" size="14" placeholder="<?php esc_attr_e( '$15, PWYC, Free', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $e['ticket_price'] ); ?>">
							<input name="ticket_link" type="url" class="regular-text" placeholder="https://" value="<?php echo esc_attr( $e['ticket_link'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sc-genre"><?php esc_html_e( 'Genre', 'scene-calendar' ); ?></label></th>
						<td>
							<select name="genre" id="sc-genre">
								<option value=""><?php esc_html_e( 'Auto (classify on next run)', 'scene-calendar' ); ?></option>
								<?php foreach ( Normalize::GENRES as $g ) : ?>
									<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $e['genre'], $g ); ?>><?php echo esc_html( $g ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sc-sources"><?php esc_html_e( 'Source URLs', 'scene-calendar' ); ?></label></th>
						<td><textarea name="source_urls" id="sc-sources" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'One URL per line', 'scene-calendar' ); ?>"><?php echo esc_textarea( implode( "\n", $e['source_urls'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="sc-status"><?php esc_html_e( 'Status', 'scene-calendar' ); ?></label></th>
						<td>
							<select name="status" id="sc-status">
								<?php
								$labels = array(
									'publish' => __( 'Published', 'scene-calendar' ),
									'pending' => __( 'Pending review', 'scene-calendar' ),
									'hidden'  => __( 'Hidden', 'scene-calendar' ),
								);
								// Duplicate/deleted are only offered for rows already in that state, so saving keeps it.
								if ( in_array( $e['status'], array( 'duplicate', 'deleted' ), true ) ) {
									$labels[ $e['status'] ] = 'duplicate' === $e['status'] ? __( 'Duplicate', 'scene-calendar' ) : __( 'Deleted', 'scene-calendar' );
								}
								foreach ( $labels as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $e['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( $event && $event['locked'] && 'manual' !== $event['source'] ) : ?>
								<label class="scenecal-inline"><input type="checkbox" name="unlock" value="1"> <?php esc_html_e( 'Unlock: let future runs fill in missing details', 'scene-calendar' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( $event ? __( 'Update Event', 'scene-calendar' ) : __( 'Add Event', 'scene-calendar' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save(): void {
		Menu::guard( 'scenecal_save_event' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by Normalize::event().
		$raw = array(
			'title'          => wp_unslash( $_POST['title'] ?? '' ),
			'date'           => wp_unslash( $_POST['date'] ?? '' ),
			'start_time'     => wp_unslash( $_POST['start_time'] ?? '' ),
			'end_time'       => wp_unslash( $_POST['end_time'] ?? '' ),
			'venue_name'     => wp_unslash( $_POST['venue_name'] ?? '' ),
			'venue_city'     => wp_unslash( $_POST['venue_city'] ?? '' ),
			'venue_province' => wp_unslash( $_POST['venue_province'] ?? '' ),
			'venue_country'  => wp_unslash( $_POST['venue_country'] ?? '' ),
			'lineup'         => preg_split( '/\r?\n/', (string) wp_unslash( $_POST['lineup'] ?? '' ) ),
			'description'    => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'ticket_price'   => wp_unslash( $_POST['ticket_price'] ?? '' ),
			'ticket_link'    => wp_unslash( $_POST['ticket_link'] ?? '' ),
			'source_urls'    => preg_split( '/\r?\n/', (string) wp_unslash( $_POST['source_urls'] ?? '' ) ),
			'genre'          => wp_unslash( $_POST['genre'] ?? '' ),
		);
		$status = sanitize_key( $_POST['status'] ?? 'publish' );
		// phpcs:enable

		$event                = Normalize::event( $raw );
		$event['description'] = $raw['description']; // Keep line breaks.
		$event['venue_name']  = Venues::canonicalize( $event['venue_name'] );
		$errors               = Normalize::validate( $event );
		if ( $errors ) {
			set_transient( 'scenecal_event_draft_' . get_current_user_id(), $event, 300 );
			Menu::flash( esc_html( implode( '; ', $errors ) ), 'error' );
			Menu::redirect( 'event', $id ? array( 'id' => $id ) : array() );
		}

		// Editors may choose publish/pending/hidden; duplicate/deleted only persist if already set.
		$existing = $id ? Events::get( $id ) : null;
		$allowed  = array( 'publish', 'pending', 'hidden' );
		if ( $existing ) {
			$allowed[] = $existing['status'];
		}
		$result = Events::save_manual( $event, $id ?: null, in_array( $status, $allowed, true ) ? $status : 'publish' );
		if ( is_wp_error( $result ) ) {
			set_transient( 'scenecal_event_draft_' . get_current_user_id(), $event, 300 );
			Menu::flash( esc_html( $result->get_error_message() ), 'error' );
			Menu::redirect( 'event', $id ? array( 'id' => $id ) : array() );
		}
		if ( $existing && 'duplicate' === $existing['status'] && 'publish' === $status ) {
			// Same as the Publish action: the editor says these are different shows.
			Events::set_fields( (int) $result, array( 'dedup_exempt' => 1 ) );
		}
		if ( ! empty( $_POST['unlock'] ) ) {
			global $wpdb;
			$wpdb->update( \SceneCalendar\Db::events(), array( 'locked' => 0 ), array( 'id' => $result ) );
		}
		Menu::flash( $id ? __( 'Event updated.', 'scene-calendar' ) : __( 'Event added.', 'scene-calendar' ) );
		Menu::redirect( 'event', array( 'id' => $result ) );
	}

	/** Row actions (GET links from the list table). */
	public static function handle_action(): void {
		Menu::guard( 'scenecal_event_action' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified in guard().
		$do  = sanitize_key( $_REQUEST['do'] ?? '' );
		$ids = array_filter( array_map( 'absint', (array) ( $_REQUEST['ids'] ?? array() ) ) );
		// phpcs:enable
		$count = self::apply( $do, $ids );
		/* translators: %d: number of events */
		Menu::flash( sprintf( _n( '%d event updated.', '%d events updated.', $count, 'scene-calendar' ), $count ) );
		wp_safe_redirect( wp_get_referer() ?: Menu::url() );
		exit;
	}

	/** Bulk actions: runs on the page's load- hook, before output. */
	public static function maybe_bulk(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( empty( $_REQUEST['ids'] ) ) {
			return;
		}
		$do = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '-1' ) );
		if ( '-1' === $do || '' === $do ) {
			$do = sanitize_key( wp_unslash( $_REQUEST['action2'] ?? '-1' ) );
		}
		// phpcs:enable
		if ( '-1' === $do || '' === $do ) {
			return;
		}
		if ( ! current_user_can( \SceneCalendar\Plugin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scene-calendar' ), 403 );
		}
		check_admin_referer( 'bulk-events' );
		$count = self::apply( $do, array_filter( array_map( 'absint', (array) $_REQUEST['ids'] ) ) );
		/* translators: %d: number of events */
		Menu::flash( sprintf( _n( '%d event updated.', '%d events updated.', $count, 'scene-calendar' ), $count ) );
		wp_safe_redirect( remove_query_arg( array( '_wpnonce', '_wp_http_referer', 'action', 'action2', 'ids', 'filter_action' ), wp_get_referer() ?: Menu::url() ) );
		exit;
	}

	private static function apply( string $do, array $ids ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			$current = Events::get( $id );
			if ( ! $current ) {
				continue;
			}
			switch ( $do ) {
				// Editor decisions lock the event so later runs (dedup) don't undo them.
				case 'hide':
					Events::set_status( $id, 'hidden', null, true );
					break;
				case 'publish':
					Events::set_status( $id, 'publish', null, true );
					if ( 'duplicate' === $current['status'] ) {
						// The editor says these are different shows: keep dedup away from it.
						Events::set_fields( $id, array( 'dedup_exempt' => 1 ) );
					}
					break;
				case 'delete':
					Events::delete( $id );
					break;
				case 'purge':
					Events::delete( $id, true );
					break;
				case 'reclassify':
					Events::set_fields( $id, array( 'genre' => '' ) );
					break;
				default:
					continue 2;
			}
			++$count;
		}
		return $count;
	}
}
