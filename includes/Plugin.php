<?php
namespace SceneCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * Wires hooks: activation, cron, admin, front end, WP-CLI.
 */
final class Plugin {

	const CAP          = 'manage_scene_calendar';
	const WEEKLY_HOOK  = 'scenecal_weekly';
	const HEALTH_HOOK  = 'scenecal_healthcheck';
	const HEALTH_SCHED = 'scenecal_five_minutes';

	public static function boot(): void {
		if ( get_option( 'scenecal_db_version' ) !== SCENECAL_DB_VERSION ) {
			Db::install();
		}

		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::WEEKLY_HOOK, array( Runner::class, 'weekly' ) );
		add_action( self::HEALTH_HOOK, array( Runner::class, 'healthcheck' ) );
		add_action( 'wp_ajax_scenecal_tick', array( Runner::class, 'ajax_tick' ) );
		add_action( 'wp_ajax_nopriv_scenecal_tick', array( Runner::class, 'ajax_tick' ) );
		add_action( 'init', array( self::class, 'ensure_schedules' ) );

		Frontend\Shortcode::register();

		if ( is_admin() ) {
			Admin\Menu::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'scene-calendar', Cli::class );
		}
	}

	public static function activate(): void {
		Db::install();
		foreach ( array( 'administrator' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::CAP );
			}
		}
		if ( ! get_option( Settings::OPTION ) ) {
			Settings::save( Settings::defaults() );
		}
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		self::schedule_weekly( true );
		if ( ! wp_next_scheduled( self::HEALTH_HOOK ) ) {
			wp_schedule_event( time() + 300, self::HEALTH_SCHED, self::HEALTH_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		wp_clear_scheduled_hook( self::HEALTH_HOOK );
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules[ self::HEALTH_SCHED ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Scene Calendar)', 'scene-calendar' ),
		);
		return $schedules;
	}

	/** Re-create cron events if they went missing (e.g. a cron table reset). */
	public static function ensure_schedules(): void {
		if ( ! wp_next_scheduled( self::HEALTH_HOOK ) ) {
			wp_schedule_event( time() + 300, self::HEALTH_SCHED, self::HEALTH_HOOK );
		}
		if ( Settings::get( 'schedule_enabled' ) && ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			self::schedule_weekly( true );
		}
	}

	/** Timestamp of the next configured weekday/hour in the site timezone. */
	public static function next_weekly_timestamp( ?int $after = null ): int {
		$tz   = wp_timezone();
		$now  = ( new \DateTimeImmutable( '@' . ( $after ?? time() ) ) )->setTimezone( $tz );
		$day  = (int) Settings::get( 'schedule_day' );
		$hour = (int) Settings::get( 'schedule_hour' );
		$next = $now->setTime( $hour, 0 );
		$diff = ( $day - (int) $now->format( 'w' ) + 7 ) % 7;
		$next = $next->modify( "+$diff days" );
		if ( $next <= $now ) {
			$next = $next->modify( '+7 days' );
		}
		return $next->getTimestamp();
	}

	/** (Re)schedule the weekly run according to settings. */
	public static function schedule_weekly( bool $force = false ): void {
		if ( $force ) {
			wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		}
		if ( Settings::get( 'schedule_enabled' ) && ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			wp_schedule_single_event( self::next_weekly_timestamp(), self::WEEKLY_HOOK );
		}
	}
}
