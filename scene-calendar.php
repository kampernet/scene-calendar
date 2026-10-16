<?php
/**
 * Plugin Name:       Scene Calendar
 * Plugin URI:        https://github.com/kampernet/scene-calendar
 * Description:       Aggregates local live music events from venue websites, ticketing platforms and Instagram into an editable calendar. Built for local scene magazines and community radio.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Joel Klaverkamp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scene-calendar
 * Update URI:        https://github.com/kampernet/scene-calendar
 * GitHub Plugin URI: kampernet/scene-calendar
 * Primary Branch:    main
 */

defined( 'ABSPATH' ) || exit;

define( 'SCENECAL_VERSION', '0.1.0' );
define( 'SCENECAL_DB_VERSION', '3' );
define( 'SCENECAL_FILE', __FILE__ );
define( 'SCENECAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCENECAL_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'SceneCalendar\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = SCENECAL_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'SceneCalendar\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SceneCalendar\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SceneCalendar\\Plugin', 'boot' ) );
