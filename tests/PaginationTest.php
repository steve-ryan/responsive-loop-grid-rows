<?php
use PHPUnit\Framework\TestCase;
use RLG\Pagination;

final class PaginationTest extends TestCase {

	protected function setUp(): void {
		Elementor_Fixture::all();
	}

	protected function tearDown(): void {
		Elementor_Fixture::remove();
	}

	public function test_simple_pagination_and_filter_params_are_kept(): void {
		$vars = Pagination::extract_safe_query_vars( '?e-page-abc123=2&orderby=price&min_price=10' );

		$this->assertSame(
			array(
				'e-page-abc123' => '2',
				'orderby'       => 'price',
				'min_price'     => '10',
			),
			$vars
		);
	}

	public function test_search_terms_with_spaces_and_unicode_survive(): void {
		$vars = Pagination::extract_safe_query_vars( 's=blue+shirt&brand=Caf%C3%A9' );

		$this->assertSame( 'blue shirt', $vars['s'] );
		$this->assertSame( 'Café', $vars['brand'] );
	}

	public function test_flat_arrays_are_kept_as_lists(): void {
		$vars = Pagination::extract_safe_query_vars( 'brand[]=nike&brand[]=adidas' );

		$this->assertSame( array( 'brand' => array( 'nike', 'adidas' ) ), $vars );
	}

	public function test_nested_arrays_are_dropped(): void {
		$vars = Pagination::extract_safe_query_vars( 'a[b][c]=1&ok=1' );

		$this->assertSame( array( 'ok' => '1' ), $vars );
	}

	public function test_tags_are_stripped_from_values(): void {
		$vars = Pagination::extract_safe_query_vars( 's=' . rawurlencode( '<script>alert(1)</script>shoes' ) );

		$this->assertSame( 'alert(1)shoes', $vars['s'] );
	}

	public function test_overlong_values_are_dropped_not_truncated(): void {
		$vars = Pagination::extract_safe_query_vars( 'long=' . str_repeat( 'a', 201 ) . '&ok=1' );

		$this->assertSame( array( 'ok' => '1' ), $vars );
	}

	public function test_oversized_arrays_are_dropped(): void {
		$query = implode( '&', array_map( static fn( $i ) => 'x[]=' . $i, range( 1, 26 ) ) );

		$this->assertSame( array(), Pagination::extract_safe_query_vars( $query ) );
	}

	public function test_a_list_with_one_bad_item_is_dropped_whole(): void {
		$vars = Pagination::extract_safe_query_vars( 'x[]=ok&x[]=' . str_repeat( 'a', 201 ) );

		$this->assertSame( array(), $vars );
	}

	public function test_parameter_count_is_capped(): void {
		$query = implode( '&', array_map( static fn( $i ) => 'p' . $i . '=1', range( 1, 60 ) ) );

		$this->assertCount( 40, Pagination::extract_safe_query_vars( $query ) );
	}

	public function test_oversized_or_empty_query_strings_return_nothing(): void {
		$this->assertSame( array(), Pagination::extract_safe_query_vars( '' ) );
		$this->assertSame( array(), Pagination::extract_safe_query_vars( '?' ) );
		$this->assertSame( array(), Pagination::extract_safe_query_vars( 'a=' . str_repeat( 'b', 4001 ) ) );
	}

	public function test_sanitize_device_accepts_enabled_extra_breakpoints(): void {
		$this->assertSame( 'tablet_extra', Pagination::sanitize_device( ' Tablet_Extra ' ) );
		$this->assertSame( 'widescreen', Pagination::sanitize_device( 'widescreen' ) );
	}

	public function test_sanitize_device_defaults_to_desktop(): void {
		$this->assertSame( 'desktop', Pagination::sanitize_device( 'watch' ) );
		$this->assertSame( 'desktop', Pagination::sanitize_device( null ) );
		$this->assertSame( 'desktop', Pagination::sanitize_device( array( 'mobile' ) ) );
	}

	public function test_with_merged_get_restores_get_even_when_the_callback_throws(): void {
		$_GET = array( 'keep' => '1' );

		try {
			Pagination::with_merged_get(
				array( 'added' => '2' ),
				static function () {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException $e ) {
			// Expected.
		}

		$this->assertSame( array( 'keep' => '1' ), $_GET );
	}

	public function test_find_widget_element_finds_nested_widgets_and_checks_type(): void {
		$tree = array(
			array(
				'id'       => 'sec1',
				'elements' => array(
					array(
						'id'         => 'w1',
						'widgetType' => 'loop-grid',
					),
					array(
						'id'         => 'w2',
						'widgetType' => 'heading',
					),
				),
			),
		);

		$this->assertSame( 'w1', Pagination::find_widget_element( $tree, 'w1', 'loop-grid' )['id'] );
		$this->assertNull( Pagination::find_widget_element( $tree, 'w2', 'loop-grid' ) );
		$this->assertNull( Pagination::find_widget_element( $tree, 'missing', 'loop-grid' ) );
	}
}
