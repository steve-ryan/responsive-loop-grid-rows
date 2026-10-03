<?php
/**
 * Helper for building a fake Elementor breakpoint setup in tests.
 */

use Elementor\Fake_Breakpoint;
use Elementor\Fake_Breakpoints;
use Elementor\Fake_Editor;
use Elementor\Plugin;
use RLG\Responsive_Query;

final class Elementor_Fixture {

	/**
	 * Default Elementor breakpoints: only Mobile and Tablet enabled.
	 */
	public static function core(): void {
		self::install(
			array(
				'mobile' => new Fake_Breakpoint( 767 ),
				'tablet' => new Fake_Breakpoint( 1024 ),
			)
		);
	}

	/**
	 * Every optional breakpoint enabled, in Elementor's own order.
	 */
	public static function all(): void {
		self::install(
			array(
				'mobile'       => new Fake_Breakpoint( 767 ),
				'mobile_extra' => new Fake_Breakpoint( 880 ),
				'tablet'       => new Fake_Breakpoint( 1024 ),
				'tablet_extra' => new Fake_Breakpoint( 1200 ),
				'laptop'       => new Fake_Breakpoint( 1366 ),
				'widescreen'   => new Fake_Breakpoint( 2400, true, 'min' ),
			)
		);
	}

	/**
	 * @param array<string, Fake_Breakpoint> $list Breakpoints keyed by name.
	 */
	public static function install( array $list ): void {
		$breakpoints       = new Fake_Breakpoints();
		$breakpoints->list = $list;

		$plugin              = new Plugin();
		$plugin->breakpoints = $breakpoints;
		$plugin->editor      = new Fake_Editor();
		Plugin::$instance    = $plugin;

		Responsive_Query::reset_breakpoints_cache();
		Responsive_Query::$forced_device = null;
	}

	public static function editing( bool $on ): void {
		Plugin::$instance->editor->editing = $on;
	}

	public static function remove(): void {
		Plugin::$instance = null;
		Responsive_Query::reset_breakpoints_cache();
		Responsive_Query::$forced_device = null;
	}
}
