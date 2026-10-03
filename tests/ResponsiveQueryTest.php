<?php
use PHPUnit\Framework\TestCase;
use RLG\Responsive_Query;

final class ResponsiveQueryTest extends TestCase {

	protected function setUp(): void {
		Elementor_Fixture::core();
	}

	protected function tearDown(): void {
		Elementor_Fixture::remove();
	}

	// ---- device list ------------------------------------------------------

	public function test_core_devices_are_mobile_tablet_desktop(): void {
		$this->assertSame( array( 'mobile', 'tablet', 'desktop' ), Responsive_Query::get_devices() );
	}

	public function test_extra_breakpoints_are_included_when_enabled(): void {
		Elementor_Fixture::all();

		$this->assertSame(
			array( 'mobile', 'mobile_extra', 'tablet', 'tablet_extra', 'laptop', 'widescreen', 'desktop' ),
			Responsive_Query::get_devices()
		);
	}

	public function test_disabled_breakpoints_are_left_out(): void {
		Elementor_Fixture::install(
			array(
				'mobile' => new Elementor\Fake_Breakpoint( 767 ),
				'tablet' => new Elementor\Fake_Breakpoint( 1024 ),
				'laptop' => new Elementor\Fake_Breakpoint( 1366, false ),
			)
		);

		$this->assertFalse( Responsive_Query::is_valid_device( 'laptop' ) );
	}

	public function test_core_breakpoints_exist_even_without_elementor(): void {
		Elementor_Fixture::remove();

		$this->assertSame( array( 'mobile', 'tablet', 'desktop' ), Responsive_Query::get_devices() );
	}

	public function test_is_valid_device_rejects_unknown_and_non_strings(): void {
		$this->assertTrue( Responsive_Query::is_valid_device( 'tablet' ) );
		$this->assertFalse( Responsive_Query::is_valid_device( 'watch' ) );
		$this->assertFalse( Responsive_Query::is_valid_device( 7 ) );
		$this->assertFalse( Responsive_Query::is_valid_device( null ) );
		$this->assertFalse( Responsive_Query::is_valid_device( array( 'mobile' ) ) );
	}

	public function test_display_order_is_widest_first(): void {
		Elementor_Fixture::all();

		$this->assertSame(
			array( 'widescreen', 'desktop', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile' ),
			Responsive_Query::get_devices_display_order()
		);
	}

	// ---- inheritance chain ------------------------------------------------

	public function test_chain_for_core_devices(): void {
		$this->assertSame( array( 'desktop' ), Responsive_Query::get_inheritance_chain( 'desktop' ) );
		$this->assertSame( array( 'tablet', 'desktop' ), Responsive_Query::get_inheritance_chain( 'tablet' ) );
		$this->assertSame( array( 'mobile', 'tablet', 'desktop' ), Responsive_Query::get_inheritance_chain( 'mobile' ) );
	}

	public function test_chain_walks_every_larger_enabled_breakpoint(): void {
		Elementor_Fixture::all();

		$this->assertSame(
			array( 'mobile', 'mobile_extra', 'tablet', 'tablet_extra', 'laptop', 'desktop' ),
			Responsive_Query::get_inheritance_chain( 'mobile' )
		);
		$this->assertSame(
			array( 'tablet_extra', 'laptop', 'desktop' ),
			Responsive_Query::get_inheritance_chain( 'tablet_extra' )
		);
	}

	public function test_widescreen_falls_straight_back_to_desktop(): void {
		Elementor_Fixture::all();

		$this->assertSame( array( 'widescreen', 'desktop' ), Responsive_Query::get_inheritance_chain( 'widescreen' ) );
	}

	public function test_unknown_device_uses_desktop_only(): void {
		$this->assertSame( array( 'desktop' ), Responsive_Query::get_inheritance_chain( 'watch' ) );
	}

	// ---- items per page ---------------------------------------------------

	public function test_items_are_columns_times_rows_per_device(): void {
		$settings = array(
			'columns'        => 5,
			'columns_tablet' => 3,
			'columns_mobile' => 2,
			'rlg_rows'        => 2,
			'rlg_rows_tablet' => 2,
			'rlg_rows_mobile' => 2,
		);

		$this->assertSame( 10, Responsive_Query::calculate_items_for_device( $settings, 'desktop' ) );
		$this->assertSame( 6, Responsive_Query::calculate_items_for_device( $settings, 'tablet' ) );
		$this->assertSame( 4, Responsive_Query::calculate_items_for_device( $settings, 'mobile' ) );
	}

	public function test_empty_values_inherit_from_the_next_larger_device(): void {
		$settings = array(
			'columns'         => 4,
			'columns_tablet'  => '',
			'columns_mobile'  => '',
			'rlg_rows'        => 3,
			'rlg_rows_mobile' => 1,
		);

		$this->assertSame( 12, Responsive_Query::calculate_items_for_device( $settings, 'tablet' ) );
		$this->assertSame( 4, Responsive_Query::calculate_items_for_device( $settings, 'mobile' ) );
	}

	public function test_zero_and_junk_values_count_as_not_set(): void {
		$settings = array(
			'columns'         => 4,
			'columns_mobile'  => 0,
			'rlg_rows'        => 2,
			'rlg_rows_mobile' => 'abc',
		);

		$this->assertSame( 8, Responsive_Query::calculate_items_for_device( $settings, 'mobile' ) );
	}

	public function test_array_values_are_ignored(): void {
		$settings = array(
			'columns'        => 3,
			'columns_tablet' => array( 'size' => 9 ),
			'rlg_rows'       => 2,
		);

		$this->assertSame( 6, Responsive_Query::calculate_items_for_device( $settings, 'tablet' ) );
	}

	public function test_defaults_apply_when_nothing_is_set(): void {
		$this->assertSame( 6, Responsive_Query::calculate_items_for_device( array(), 'desktop' ) );
	}

	public function test_items_use_extra_breakpoint_values(): void {
		Elementor_Fixture::all();

		$settings = array(
			'columns'              => 5,
			'columns_laptop'       => 4,
			'columns_tablet_extra' => 3,
			'columns_tablet'       => 2,
			'columns_mobile'       => 1,
			'rlg_rows'             => 2,
			'rlg_rows_mobile'      => 4,
		);

		$this->assertSame( 8, Responsive_Query::calculate_items_for_device( $settings, 'laptop' ) );
		$this->assertSame( 6, Responsive_Query::calculate_items_for_device( $settings, 'tablet_extra' ) );
		$this->assertSame( 4, Responsive_Query::calculate_items_for_device( $settings, 'tablet' ) );
		// mobile_extra has no value of its own, so it inherits tablet (2 x 2).
		$this->assertSame( 4, Responsive_Query::calculate_items_for_device( $settings, 'mobile_extra' ) );
		$this->assertSame( 4, Responsive_Query::calculate_items_for_device( $settings, 'mobile' ) );
		// widescreen has no value of its own, so it uses desktop.
		$this->assertSame( 10, Responsive_Query::calculate_items_for_device( $settings, 'widescreen' ) );
	}

	// ---- render count (front end vs editor) -------------------------------

	public function test_front_end_render_uses_the_resolved_device(): void {
		$settings = array(
			'columns'        => 4,
			'columns_mobile' => 1,
			'rlg_rows'       => 3,
		);

		$this->assertSame( 3, Responsive_Query::calculate_items_for_render( $settings, 'mobile' ) );
		$this->assertSame( 12, Responsive_Query::calculate_items_for_render( $settings, 'desktop' ) );
	}

	public function test_editor_render_returns_the_largest_count(): void {
		Elementor_Fixture::editing( true );

		$settings = array(
			'columns'        => 4,
			'columns_mobile' => 1,
			'rlg_rows'       => 3,
		);

		$this->assertSame( 12, Responsive_Query::calculate_items_for_render( $settings, 'mobile' ) );
	}

	public function test_forced_device_beats_editor_mode(): void {
		Elementor_Fixture::editing( true );
		Responsive_Query::$forced_device = 'mobile';

		$settings = array(
			'columns'        => 4,
			'columns_mobile' => 1,
			'rlg_rows'       => 3,
		);

		$this->assertSame( 3, Responsive_Query::calculate_items_for_render( $settings, 'mobile' ) );
	}
}
