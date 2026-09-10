<?php
/**
 * Plugin settings page.
 *
 * @package ResponsiveLoopGridRows
 */

namespace RLG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * A minimal Settings API page under Settings -> Responsive Loop Grid Rows.
 * Only two options exist, both with safe defaults, so a fresh install needs
 * no configuration at all for the plugin to behave sensibly.
 */
class Settings {

	private const OPTION_GROUP = 'rlg_settings_group';
	private const PAGE_SLUG    = 'rlg-settings';

	/**
	 * Wire up the settings page.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add the settings page under Settings.
	 */
	public static function add_menu(): void {
		add_options_page(
			esc_html__( 'Responsive Loop Grid Rows', 'responsive-loop-grid-rows' ),
			esc_html__( 'Loop Grid Rows', 'responsive-loop-grid-rows' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the two plugin options.
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			'rlg_default_device',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( 'RLG\\Pagination', 'sanitize_device' ),
				'default'           => 'desktop',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'rlg_ajax_correction_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_correction_mode' ),
				'default'           => 'auto',
			)
		);

		add_settings_section(
			'rlg_main_section',
			esc_html__( 'General Settings', 'responsive-loop-grid-rows' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'rlg_default_device',
			esc_html__( 'Fallback / cached-page device', 'responsive-loop-grid-rows' ),
			array( __CLASS__, 'render_default_device_field' ),
			self::PAGE_SLUG,
			'rlg_main_section'
		);

		add_settings_field(
			'rlg_ajax_correction_mode',
			esc_html__( 'AJAX correction', 'responsive-loop-grid-rows' ),
			array( __CLASS__, 'render_correction_mode_field' ),
			self::PAGE_SLUG,
			'rlg_main_section'
		);
	}

	/**
	 * Sanitize the correction-mode option to a known value.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public static function sanitize_correction_mode( $value ): string {
		$value = is_string( $value ) ? $value : 'auto';

		return in_array( $value, array( 'auto', 'off' ), true ) ? $value : 'auto';
	}

	/**
	 * Render the "fallback device" select field.
	 */
	public static function render_default_device_field(): void {
		$current = get_option( 'rlg_default_device', 'desktop' );
		?>
		<select name="rlg_default_device">
			<option value="desktop" <?php selected( $current, 'desktop' ); ?>><?php esc_html_e( 'Desktop', 'responsive-loop-grid-rows' ); ?></option>
			<option value="tablet" <?php selected( $current, 'tablet' ); ?>><?php esc_html_e( 'Tablet', 'responsive-loop-grid-rows' ); ?></option>
			<option value="mobile" <?php selected( $current, 'mobile' ); ?>><?php esc_html_e( 'Mobile', 'responsive-loop-grid-rows' ); ?></option>
		</select>
		<p class="description">
			<?php esc_html_e( 'This is the device used for every server-rendered page load - including the very first, uncached one and any full-page/CDN cached copy. It must be identical for every visitor, which is what makes caching safe. Visitors on a different breakpoint are corrected via one lightweight AJAX call (see "AJAX correction" below). Desktop is recommended for most sites.', 'responsive-loop-grid-rows' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "AJAX correction" mode field.
	 */
	public static function render_correction_mode_field(): void {
		$current = get_option( 'rlg_ajax_correction_mode', 'auto' );
		?>
		<label>
			<input type="radio" name="rlg_ajax_correction_mode" value="auto" <?php checked( $current, 'auto' ); ?> />
			<?php esc_html_e( 'Auto (recommended) - correct visitors whose real breakpoint differs from the fallback device above', 'responsive-loop-grid-rows' ); ?>
		</label><br />
		<label>
			<input type="radio" name="rlg_ajax_correction_mode" value="off" <?php checked( $current, 'off' ); ?> />
			<?php esc_html_e( 'Off - always show the fallback device\'s item count to everyone (simplest, but tablet/mobile visitors may see the wrong number of products/rows)', 'responsive-loop-grid-rows' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Responsive Loop Grid Rows', 'responsive-loop-grid-rows' ); ?></h1>
			<p>
				<?php esc_html_e( 'Configure how Responsive Rows behaves on cached/first-render page loads. Per-widget Rows and Columns are configured in the Elementor editor, on the Loop Grid widget itself, under "Responsive Rows".', 'responsive-loop-grid-rows' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
