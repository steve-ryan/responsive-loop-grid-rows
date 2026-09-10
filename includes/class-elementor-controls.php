<?php
/**
 * Registers the "Responsive Rows" controls on Elementor Pro's Loop Grid widget.
 *
 * @package ResponsiveLoopGridRows
 */

namespace RLG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Elementor_Controls
 *
 * Adds a new, self-contained "Responsive Rows" section to the Loop Grid
 * widget. We deliberately register our OWN section (rather than trying to
 * inject controls into one of Elementor Pro's existing sections) because
 * Elementor Pro's internal section IDs for the Loop Grid widget are not
 * part of any stable public API and can change between versions. Adding a
 * new section is fully supported, version-safe, and keeps our controls
 * clearly labelled as coming from this plugin.
 */
class Elementor_Controls {

	/**
	 * Wire up control registration.
	 */
	public static function init(): void {
		add_action( 'elementor/element/loop-grid/section_query/after_section_end', array( __CLASS__, 'register_controls' ), 10, 2 );
	}

	/**
	 * Add the Responsive Rows section + controls to the Loop Grid widget.
	 *
	 * @param \Elementor\Widget_Base $element    The Loop Grid widget instance.
	 * @param string                  $section_id The section that just ended (unused, kept for hook signature).
	 */
	public static function register_controls( $element, $section_id = '' ): void {
		if ( ! is_object( $element ) || ! method_exists( $element, 'get_name' ) || 'loop-grid' !== $element->get_name() ) {
			return;
		}

		$element->start_controls_section(
			'rlg_section_responsive_rows',
			array(
				'label' => esc_html__( 'Responsive Rows', 'responsive-loop-grid-rows' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$element->add_control(
			'rlg_enable',
			array(
				'label'        => esc_html__( 'Enable Responsive Rows', 'responsive-loop-grid-rows' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'responsive-loop-grid-rows' ),
				'label_off'    => esc_html__( 'No', 'responsive-loop-grid-rows' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'When enabled, Items Per Page is calculated automatically from Columns x Rows for each breakpoint, and true server-side responsive pagination is used. When disabled, the Loop Grid behaves exactly as it does natively - existing sites are never changed unexpectedly.', 'responsive-loop-grid-rows' ),
			)
		);

		$element->add_responsive_control(
			'rlg_rows',
			array(
				'label'          => esc_html__( 'Rows', 'responsive-loop-grid-rows' ),
				'type'           => \Elementor\Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 50,
				'step'           => 1,
				'default'        => 2,
				'tablet_default' => 2,
				'mobile_default' => 2,
				'condition'      => array(
					'rlg_enable' => 'yes',
				),
				'description'    => esc_html__( 'Number of product rows to show at this breakpoint. Items Per Page = this value x the Columns setting above, for the same breakpoint.', 'responsive-loop-grid-rows' ),
			)
		);

		$element->add_control(
			'rlg_calculated_info',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => self::render_calculated_html_placeholder(),
				'content_classes' => 'rlg-calculated-info',
				'condition'       => array(
					'rlg_enable' => 'yes',
				),
			)
		);

		$element->end_controls_section();
	}

	/**
	 * Build the static placeholder markup for the "Calculated items per
	 * page" helper box shown beneath the Rows control in the editor panel.
	 *
	 * IMPORTANT: this must never call $element->get_settings_for_display()
	 * (or anything that touches the widget's settings) here. Control
	 * registration - register_controls() - runs in contexts where the
	 * widget has no settings yet at all (most notably, when Elementor Pro
	 * builds its internal "blank" widget instance for the editor's global
	 * config/localisation on every admin page load). Calling
	 * get_settings_for_display() at that point recurses into
	 * sanitize_settings( null ) and throws a fatal TypeError. The real,
	 * per-widget numbers are instead computed and written into this same
	 * markup entirely client-side by assets/js/editor.js, which runs only
	 * once an actual widget instance with real settings is open in the
	 * panel (see "Editor mode" in readme.txt).
	 *
	 * @return string Escaped HTML.
	 */
	public static function render_calculated_html_placeholder(): string {
		$rows = array(
			'desktop' => esc_html__( 'Desktop', 'responsive-loop-grid-rows' ),
			'tablet'  => esc_html__( 'Tablet', 'responsive-loop-grid-rows' ),
			'mobile'  => esc_html__( 'Mobile', 'responsive-loop-grid-rows' ),
		);

		$html  = '<div class="rlg-calculated-box" data-rlg-calculated-box="1">';
		$html .= '<strong>' . esc_html__( 'Calculated items per page:', 'responsive-loop-grid-rows' ) . '</strong><br />';

		foreach ( $rows as $key => $label ) {
			$html .= sprintf(
				'<span class="rlg-calculated-row" data-rlg-device="%1$s">%2$s: <b data-rlg-value>&#8211;</b> ' . esc_html__( 'items/page', 'responsive-loop-grid-rows' ) . '</span><br />',
				esc_attr( $key ),
				esc_html( $label )
			);
		}

		$html .= '</div>';

		return $html;
	}
}
