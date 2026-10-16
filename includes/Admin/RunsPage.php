<?php
namespace SceneCalendar\Admin;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Plugin;
use SceneCalendar\Runner;
use SceneCalendar\Settings;

/**
 * Run status, history and logs; start/cancel/step controls.
 */
final class RunsPage {

	public static function render(): void {
		$active   = Runner::active_run();
		$selected = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : ( $active['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run      = $selected ? Runner::get_run( $selected ) : null;
		$runs     = Runner::recent_runs();
		$next     = wp_next_scheduled( Plugin::WEEKLY_HOOK );
		$issues   = Settings::readiness();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Runs', 'scene-calendar' ); ?></h1>
			<?php if ( ! $active ) : ?>
				<a href="<?php echo esc_url( self::action_url( 'start' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Run now', 'scene-calendar' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php foreach ( $issues as $issue ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $issue ); ?></p></div>
			<?php endforeach; ?>

			<p>
				<?php
				if ( $next ) {
					/* translators: %s: date and time */
					printf( esc_html__( 'Next scheduled run: %s', 'scene-calendar' ), esc_html( wp_date( 'l, M j Y, H:i', $next ) ) );
				} else {
					esc_html_e( 'Scheduled runs are off (see Settings).', 'scene-calendar' );
				}
				?>
			</p>

			<?php if ( $active ) : ?>
				<div class="scenecal-active" data-run="<?php echo (int) $active['id']; ?>">
					<h2>
						<?php
						/* translators: %d: run id */
						printf( esc_html__( 'Run #%d in progress', 'scene-calendar' ), (int) $active['id'] );
						?>
					</h2>
					<p class="scenecal-progress"><?php echo esc_html( self::progress_text( $active ) ); ?></p>
					<p>
						<a class="button" href="<?php echo esc_url( self::action_url( 'step', $active['id'] ) ); ?>"><?php esc_html_e( 'Process next step now', 'scene-calendar' ); ?></a>
						<a class="button button-link-delete" href="<?php echo esc_url( self::action_url( 'cancel', $active['id'] ) ); ?>"><?php esc_html_e( 'Cancel run', 'scene-calendar' ); ?></a>
					</p>
					<p class="description"><?php esc_html_e( 'Runs advance in the background, one small step at a time. If progress stalls, your host may block loopback requests: the run still continues every few minutes via WP-Cron, or add a real cron job (see Settings).', 'scene-calendar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $run ) : ?>
				<h2>
					<?php
					/* translators: 1: run id, 2: status */
					printf( esc_html__( 'Run #%1$d log (%2$s)', 'scene-calendar' ), (int) $run['id'], esc_html( $run['status'] ) );
					?>
				</h2>
				<pre class="scenecal-log" id="scenecal-log"><?php echo esc_html( $run['log'] ); ?></pre>
			<?php endif; ?>

			<h2><?php esc_html_e( 'History', 'scene-calendar' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>#</th>
						<th><?php esc_html_e( 'Started', 'scene-calendar' ); ?></th>
						<th><?php esc_html_e( 'Trigger', 'scene-calendar' ); ?></th>
						<th><?php esc_html_e( 'Status', 'scene-calendar' ); ?></th>
						<th><?php esc_html_e( 'Venues', 'scene-calendar' ); ?></th>
						<th><?php esc_html_e( 'New', 'scene-calendar' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'scene-calendar' ); ?></th>
						<th><?php esc_html_e( 'Duplicates', 'scene-calendar' ); ?></th>
						<th><?php esc_html_e( 'Errors', 'scene-calendar' ); ?></th>
						<th><?php esc_html_e( 'AI calls / tokens', 'scene-calendar' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $runs ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No runs yet.', 'scene-calendar' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $runs as $r ) : ?>
					<?php $st = $r['stats']; ?>
					<tr>
						<td><a href="<?php echo esc_url( Menu::url( 'runs', array( 'run' => $r['id'] ) ) ); ?>"><?php echo (int) $r['id']; ?></a></td>
						<td><?php echo esc_html( get_date_from_gmt( $r['started_at'], 'M j, H:i' ) ); ?></td>
						<td><?php echo esc_html( $r['run_trigger'] ); ?></td>
						<td><?php echo esc_html( $r['status'] ); ?></td>
						<td><?php echo (int) ( $st['venues_done'] ?? 0 ); ?></td>
						<td><?php echo (int) ( $st['inserted'] ?? 0 ); ?></td>
						<td><?php echo (int) ( $st['enriched'] ?? 0 ); ?></td>
						<td><?php echo (int) ( ( $st['duplicates'] ?? 0 ) + ( $st['marked_duplicate'] ?? 0 ) ); ?></td>
						<td><?php echo (int) ( $st['errors'] ?? 0 ); ?></td>
						<td><?php echo (int) ( $st['llm_calls'] ?? 0 ); ?> / <?php echo esc_html( number_format_i18n( ( $st['tokens_in'] ?? 0 ) + ( $st['tokens_out'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function progress_text( array $run ): string {
		$s     = $run['state'];
		$total = count( $s['venue_ids'] ?? array() );
		switch ( $run['phase'] ) {
			case 'venues':
				return sprintf(
					/* translators: 1: venue number, 2: total venues, 3: stage */
					__( 'Collecting events: venue %1$d of %2$d (%3$s)', 'scene-calendar' ),
					min( $total, (int) $s['venue_index'] + 1 ),
					$total,
					$s['stage']
				);
			case 'dedup':
				return sprintf(
					/* translators: 1: date number, 2: total dates */
					__( 'Finding duplicates: date %1$d of %2$d', 'scene-calendar' ),
					(int) $s['dedup_index'],
					count( $s['dedup_dates'] ?? array() )
				);
			case 'lineups':
				return __( 'Extracting lineups', 'scene-calendar' );
			case 'genres':
				return __( 'Classifying genres', 'scene-calendar' );
			default:
				return $run['phase'];
		}
	}

	private static function action_url( string $do, int $id = 0 ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'scenecal_run_action',
					'do'     => $do,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'scenecal_run_action'
		);
	}

	public static function handle_action(): void {
		Menu::guard( 'scenecal_run_action' );
		$do = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $do ) {
			case 'start':
				$result = Runner::start( 'manual' );
				if ( is_wp_error( $result ) ) {
					Menu::flash( esc_html( $result->get_error_message() ), 'error' );
				} else {
					Menu::flash( __( 'Run started. It continues in the background; this page refreshes while it runs.', 'scene-calendar' ) );
					$id = $result;
				}
				break;
			case 'cancel':
				Runner::cancel( $id );
				Menu::flash( __( 'Run cancelled.', 'scene-calendar' ) );
				break;
			case 'step':
				// Run one short tick in this request (useful when loopbacks are blocked).
				$more = Runner::tick( 20 );
				if ( null === $more ) {
					Menu::flash( __( 'A step is already being processed in the background.', 'scene-calendar' ), 'warning' );
					break;
				}
				if ( $more ) {
					Runner::dispatch();
				}
				Menu::flash( $more ? __( 'Processed a step.', 'scene-calendar' ) : __( 'Run finished.', 'scene-calendar' ) );
				break;
		}
		Menu::redirect( 'runs', $id ? array( 'run' => $id ) : array() );
	}

	/** Polled by admin.js while a run is active. */
	public static function ajax_status(): void {
		check_ajax_referer( 'scenecal_run_status' );
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$id  = absint( $_GET['run'] ?? 0 );
		$run = $id ? Runner::get_run( $id ) : null;
		if ( ! $run ) {
			wp_send_json_error( null, 404 );
		}
		wp_send_json_success(
			array(
				'status'   => $run['status'],
				'progress' => self::progress_text( $run ),
				'log'      => mb_substr( $run['log'], -20000 ),
			)
		);
	}
}
