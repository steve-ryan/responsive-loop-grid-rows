<?php
/**
 * Uninstall handler.
 *
 * Runs only when the plugin is deleted from Plugins -> Installed Plugins
 * (never on simple deactivation). Removes the two options this plugin
 * creates. It never touches any Elementor widget data/settings - a Loop
 * Grid's "Responsive Rows" values live inside Elementor's own page/template
 * content (post meta), exactly like every other Elementor control, and are
 * left untouched. If you reinstall the plugin later, any Loop Grid that had
 * Responsive Rows configured will simply pick that configuration back up.
 *
 * @package ResponsiveLoopGridRows
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rlg_default_device' );
delete_option( 'rlg_ajax_correction_mode' );

// Multisite: also clean up per-site options on every site of the network.
if ( is_multisite() ) {
	$rlg_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $rlg_site_ids as $rlg_site_id ) {
		switch_to_blog( (int) $rlg_site_id );
		delete_option( 'rlg_default_device' );
		delete_option( 'rlg_ajax_correction_mode' );
		restore_current_blog();
	}
}
