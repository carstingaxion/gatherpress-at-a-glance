<?php
/**
 * Plugin Name:       GatherPress At a Glance
 * Plugin URI:        https://github.com/carstingaxion/gatherpress-at-a-glance
 * Description:       Adds event, venue, and RSVP counts to the WordPress "At a Glance" dashboard widget.
 * Version:           0.1.1
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Requires plugins:  gatherpress
 * Author:            carstenbach
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gatherpress-at-a-glance
 *
 * @package GatherPress_At_A_Glance
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

define( 'GATHERPRESS_AT_A_GLANCE_VERSION', current( get_file_data( __FILE__, array( 'Version' ), 'plugin' ) ) );
define( 'GATHERPRESS_AT_A_GLANCE_CORE_PATH', __DIR__ );

/**
 * Adds the GatherPress_At_A_Glance namespace to the GatherPress autoloader.
 *
 * @param array<string, string> $namespaces An associative array of namespaces and their paths.
 * @return array<string, string> Modified array of namespaces and their paths.
 */
function gatherpress_at_a_glance_autoloader( array $namespaces ): array {
	$namespaces['GatherPress_At_A_Glance'] = GATHERPRESS_AT_A_GLANCE_CORE_PATH;

	return $namespaces;
}
add_filter( 'gatherpress_autoloader', 'gatherpress_at_a_glance_autoloader' );

/**
 * Initialize the plugin once GatherPress core is loaded.
 *
 * @since 0.1.0
 * @return void
 */
function gatherpress_at_a_glance_setup(): void {
	if ( defined( 'GATHERPRESS_VERSION' ) ) {
		\GatherPress_At_A_Glance\Dashboard::get_instance();
	}
}
add_action( 'plugins_loaded', 'gatherpress_at_a_glance_setup' );
