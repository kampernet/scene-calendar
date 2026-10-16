<?php
namespace SceneCalendar\Admin;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Plugin;

/**
 * Admin menu, asset loading and the admin-post action router.
 */
final class Menu {

	const SLUG = 'scene-calendar';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );

		foreach ( array(
			'scenecal_save_event'    => array( EventsPage::class, 'handle_save' ),
			'scenecal_event_action'  => array( EventsPage::class, 'handle_action' ),
			'scenecal_save_venue'    => array( VenuesPage::class, 'handle_save' ),
			'scenecal_venue_action'  => array( VenuesPage::class, 'handle_action' ),
			'scenecal_import_venues' => array( VenuesPage::class, 'handle_import' ),
			'scenecal_export_venues' => array( VenuesPage::class, 'handle_export' ),
			'scenecal_run_action'    => array( RunsPage::class, 'handle_action' ),
			'scenecal_save_settings' => array( SettingsPage::class, 'handle_save' ),
			'scenecal_test_llm'      => array( SettingsPage::class, 'handle_test_llm' ),
		) as $action => $callback ) {
			add_action( "admin_post_$action", $callback );
		}
		add_action( 'wp_ajax_scenecal_run_status', array( RunsPage::class, 'ajax_status' ) );
		add_action( 'wp_ajax_scenecal_test_parser', array( VenuesPage::class, 'ajax_test_parser' ) );
	}

	public static function menu(): void {
		$hook = add_menu_page(
			__( 'Scene Calendar', 'scene-calendar' ),
			__( 'Scene Calendar', 'scene-calendar' ),
			Plugin::CAP,
			self::SLUG,
			array( EventsPage::class, 'render' ),
			'dashicons-calendar-alt',
			26
		);
		add_action( "load-$hook", array( EventsPage::class, 'maybe_bulk' ) );
		add_submenu_page( self::SLUG, __( 'Events', 'scene-calendar' ), __( 'Events', 'scene-calendar' ), Plugin::CAP, self::SLUG, array( EventsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Add Event', 'scene-calendar' ), __( 'Add Event', 'scene-calendar' ), Plugin::CAP, self::SLUG . '-event', array( EventsPage::class, 'render_form' ) );
		add_submenu_page( self::SLUG, __( 'Venues', 'scene-calendar' ), __( 'Venues', 'scene-calendar' ), Plugin::CAP, self::SLUG . '-venues', array( VenuesPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Runs', 'scene-calendar' ), __( 'Runs', 'scene-calendar' ), Plugin::CAP, self::SLUG . '-runs', array( RunsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'scene-calendar' ), __( 'Settings', 'scene-calendar' ), Plugin::CAP, self::SLUG . '-settings', array( SettingsPage::class, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'scenecal-admin', SCENECAL_URL . 'assets/admin.css', array(), SCENECAL_VERSION );
		wp_enqueue_script( 'scenecal-admin', SCENECAL_URL . 'assets/admin.js', array(), SCENECAL_VERSION, true );
		wp_localize_script(
			'scenecal-admin',
			'SceneCalAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'scenecal_run_status' ),
				'testNonce' => wp_create_nonce( 'scenecal_test_parser' ),
				'i18n'    => array(
					'confirmDelete' => __( 'Delete this? Scraped events are hidden permanently so the next run does not re-add them.', 'scene-calendar' ),
					'testing'       => __( 'Fetching…', 'scene-calendar' ),
					'found'         => __( '%1$d events found via %2$s', 'scene-calendar' ),
					'showing'       => __( 'Showing the first %d.', 'scene-calendar' ),
					'none'          => __( 'No events found. Check the URL and parser settings; the log below may help.', 'scene-calendar' ),
					'failed'        => __( 'Test failed:', 'scene-calendar' ),
					'cols'          => array( __( 'Date', 'scene-calendar' ), __( 'Time', 'scene-calendar' ), __( 'Title', 'scene-calendar' ), __( 'Venue', 'scene-calendar' ), __( 'Price', 'scene-calendar' ) ),
				),
			)
		);
	}

	/** Show one-shot notices passed via the scenecal_notice query arg. */
	public static function notices(): void {
		if ( empty( $_GET['page'] ) || false === strpos( sanitize_key( $_GET['page'] ), self::SLUG ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$key = get_current_user_id() ? 'scenecal_notice_' . get_current_user_id() : '';
		if ( $key ) {
			$notice = get_transient( $key );
			if ( $notice ) {
				delete_transient( $key );
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $notice['type'] ),
					wp_kses_post( $notice['message'] )
				);
			}
		}
	}

	/** Queue a notice for the next admin page load. */
	public static function flash( string $message, string $type = 'success' ): void {
		set_transient(
			'scenecal_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
	}

	/** Capability + nonce check for admin-post handlers. */
	public static function guard( string $nonce_action ): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scene-calendar' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	public static function url( string $page = '', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG . ( '' !== $page ? "-$page" : '' ) ), $args ), admin_url( 'admin.php' ) );
	}

	public static function redirect( string $page = '', array $args = array() ): void {
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}
}
