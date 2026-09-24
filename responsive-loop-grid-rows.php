<?php
/**
 * Plugin Name:       Responsive Loop Grid Rows
 * Plugin URI:        https://whatsapp.com/+254756949393
 * Description:       Adds a "Responsive Rows" control to Elementor Pro's Loop Grid widget, letting you set rows per breakpoint instead of manually calculating Items Per Page. True server-side responsive pagination, cache-safe by design.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Requires Plugins:  elementor
 * Author:            Steve Wachira
 * Author URI:        https://whatsapp.com/+254756949393
 * Text Domain:       responsive-loop-grid-rows
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package ResponsiveLoopGridRows
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------
// Constants.
// -----------------------------------------------------------------------

define( 'RLG_VERSION', '1.0.2' );
define( 'RLG_PLUGIN_FILE', __FILE__ );
define( 'RLG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RLG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RLG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'RLG_MIN_PHP_VERSION', '8.1' );
define( 'RLG_MIN_WP_VERSION', '6.0' );
// The Loop Grid widget ships with Elementor Pro's Loop Builder (Pro 3.8+).
define( 'RLG_MIN_ELEMENTOR_VERSION', '3.8.0' );
define( 'RLG_MIN_ELEMENTOR_PRO_VERSION', '3.8.0' );

/*
 * Debug switch. Define RLG_DEBUG as true in wp-config.php to enable verbose,
 * non-sensitive debug logging (widget id, breakpoint, columns, rows,
 * calculated posts-per-page, current page, query id) via error_log() and,
 * for users who can manage_options, as an HTML comment in the page source.
 * Nothing is ever output to normal visitors when this is false.
 */
if ( ! defined( 'RLG_DEBUG' ) ) {
	define( 'RLG_DEBUG', false );
}

// -----------------------------------------------------------------------
// Explicit requires (the plugin is small enough that this is clearer and
// more robust than a PSR-4 autoloader).
// -----------------------------------------------------------------------

require_once RLG_PLUGIN_DIR . 'includes/class-debug.php';
require_once RLG_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Boot the plugin once all other plugins have loaded, so we can reliably
 * detect whether Elementor / Elementor Pro are active.
 *
 * Priority 20 (not the default 10) on purpose: Elementor and Elementor Pro
 * both bootstrap themselves on `plugins_loaded` at priority 10, and Elementor
 * Pro only defines its main class inside that callback. Running later makes
 * the environment check independent of plugin load order.
 *
 * @return \RLG\Plugin
 */
function rlg_run_plugin() {
	return \RLG\Plugin::instance();
}
add_action( 'plugins_loaded', 'rlg_run_plugin', 20 );

/**
 * Activation hook. No DB tables or rewrite rules are needed; we just seed
 * default options. Environment checks (Elementor/PHP/WP version) are handled
 * on every load via admin notices rather than at activation time, so the
 * plugin never fatals and never silently "half activates".
 */
function rlg_activate_plugin() {
	if ( false === get_option( 'rlg_default_device' ) ) {
		add_option( 'rlg_default_device', 'desktop' );
	}
	if ( false === get_option( 'rlg_ajax_correction_mode' ) ) {
		add_option( 'rlg_ajax_correction_mode', 'auto' );
	}
}
register_activation_hook( __FILE__, 'rlg_activate_plugin' );

/*
 * No deactivation hook: options are intentionally left in place (uninstall.php
 * handles full cleanup) so a temporary deactivation never loses settings, and
 * the plugin registers no rewrite rules, so there is nothing to flush.
 */
