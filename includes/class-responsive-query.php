<?php
/**
 * Responsive Rows -> posts_per_page calculation and Elementor query hook.
 *
 * @package ResponsiveLoopGridRows
 */

namespace RLG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Responsive_Query
 *
 * This is the heart of the plugin. It:
 * 1. Reads a Loop Grid widget's own responsive column + Responsive Rows
 *    settings just before the widget renders.
 * 2. Works out, per breakpoint, how many posts should be requested.
 * 3. Hooks into Elementor's `elementor/query/{$query_id}` action (which
 *    always fires for Query Control powered widgets - Loop Grid included -
 *    using the widget's own element ID as the query id when no custom
 *    "Query ID" has been set) to set `posts_per_page` on the *actual*
 *    WP_Query object, without touching orderby/order/meta_query/tax_query/
 *    post__in or any other argument.
 *
 * Device resolution order for a given render:
 * 1. An explicit forced device (set by our AJAX correction endpoint, see
 *    Ajax::handle_render_grid()) - this is the authoritative, validated
 *    value for that one render.
 * 2. Elementor editor/preview mode always resolves to "desktop" (see the
 *    README for why true live per-device preview isn't practical).
 * 3. Otherwise, the site's configured fallback/default device (Settings ->
 *    Responsive Loop Grid Rows), "desktop" unless changed. This is what a
 *    fully cached page, or a request with JS disabled, will see - it is
 *    never allowed to vary per visitor within the same cached response,
 *    which is exactly what keeps this cache-safe. See README "Caching".
 */
class Responsive_Query {

	public const DEVICES = array( 'mobile', 'tablet', 'desktop' );

	/**
	 * Widget name this class targets.
	 */
	public const WIDGET_NAME = 'loop-grid';

	/**
	 * Device explicitly forced for the current PHP request (used only by
	 * our AJAX correction endpoint, for exactly one render_element() call).
	 *
	 * @var string|null
	 */
	public static ?string $forced_device = null;

	/**
	 * Guards against registering the same dynamic query action twice for
	 * the same widget/query-id combination within a single request.
	 *
	 * @var array<string, bool>
	 */
	private static array $registered_query_hooks = array();

	/**
	 * Wire up the hook that inspects each Loop Grid widget before it
	 * renders.
	 */
	public static function init(): void {
		add_action( 'elementor/frontend/widget/before_render', array( __CLASS__, 'before_render' ), 20 );
	}

	/**
	 * Fires for every widget on the page, right before it renders. We only
	 * act on Loop Grid widgets that have Responsive Rows enabled.
	 *
	 * @param \Elementor\Widget_Base $widget The widget about to render.
	 */
	public static function before_render( $widget ): void {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
			return;
		}

		if ( self::WIDGET_NAME !== $widget->get_name() ) {
			return;
		}

		$settings = $widget->get_settings_for_display();

		if ( empty( $settings['rlg_enable'] ) || 'yes' !== $settings['rlg_enable'] ) {
			return;
		}

		$device = self::resolve_device();
		$items  = self::calculate_items_for_device( $settings, $device );

		if ( $items < 1 ) {
			$items = 1;
		}

		$query_id = ! empty( $settings['post_query_query_id'] )
			? $settings['post_query_query_id']
			: $widget->get_id();

		self::register_query_override( $query_id, $widget->get_id(), $items );

		// Mark the wrapper element so our front-end JS can find it and so
		// visitors get a stable hook to detect/correct their own device.
		if ( method_exists( $widget, 'add_render_attribute' ) ) {
			$widget->add_render_attribute( '_wrapper', 'class', 'rlg-responsive-grid' );
			$widget->add_render_attribute( '_wrapper', 'data-rlg-widget-id', esc_attr( $widget->get_id() ) );
			$widget->add_render_attribute( '_wrapper', 'data-rlg-query-id', esc_attr( (string) $query_id ) );
			$widget->add_render_attribute( '_wrapper', 'data-rlg-device', esc_attr( $device ) );

			$document_id = self::get_current_document_id();
			if ( $document_id ) {
				$widget->add_render_attribute( '_wrapper', 'data-rlg-document-id', esc_attr( (string) $document_id ) );
			}
		}

		Debug::log(
			'before_render',
			array(
				'widget_id' => $widget->get_id(),
				'query_id'  => $query_id,
				'device'    => $device,
				'items'     => $items,
			)
		);
	}

	/**
	 * Register (once per widget/query-id per request) the action that will
	 * set posts_per_page on the real WP_Query object right before it runs.
	 *
	 * @param string $query_id  Elementor query id (custom or the widget id).
	 * @param string $widget_id Elementor widget element id.
	 * @param int    $items     Calculated posts_per_page for this render.
	 */
	private static function register_query_override( string $query_id, string $widget_id, int $items ): void {
		$hook_key = $query_id . '|' . $widget_id;

		// If we already registered a callback for this exact combination in
		// this request, just update the closure's captured value by
		// removing and re-adding - simplest safe way to avoid stacking
		// multiple callbacks that would each try to set posts_per_page.
		if ( isset( self::$registered_query_hooks[ $hook_key ] ) ) {
			return;
		}
		self::$registered_query_hooks[ $hook_key ] = true;

		add_action(
			"elementor/query/{$query_id}",
			static function ( $query ) use ( $items ) {
				if ( ! is_object( $query ) || ! method_exists( $query, 'set' ) ) {
					return;
				}
				$query->set( 'posts_per_page', $items );
			},
			20,
			1
		);
	}

	/**
	 * Work out which "device" this render should use.
	 *
	 * @return string One of self::DEVICES.
	 */
	public static function resolve_device(): string {
		if ( null !== self::$forced_device && in_array( self::$forced_device, self::DEVICES, true ) ) {
			return self::$forced_device;
		}

		if ( self::is_editor_or_preview() ) {
			return 'desktop';
		}

		$default = get_option( 'rlg_default_device', 'desktop' );

		return in_array( $default, self::DEVICES, true ) ? $default : 'desktop';
	}

	/**
	 * Whether we are currently rendering inside the Elementor editor or its
	 * preview iframe.
	 *
	 * @return bool
	 */
	public static function is_editor_or_preview(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
			return true;
		}

		if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the ID of the Elementor document currently being rendered (this is
	 * the template/page/archive that actually stores the widget's element
	 * tree - not necessarily the same as the currently displayed post when
	 * Theme Builder templates are involved).
	 *
	 * @return int
	 */
	public static function get_current_document_id(): int {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return 0;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( isset( $elementor->documents ) && method_exists( $elementor->documents, 'get_current' ) ) {
			$document = $elementor->documents->get_current();
			if ( $document && method_exists( $document, 'get_id' ) ) {
				return (int) $document->get_id();
			}
		}

		return (int) get_the_ID();
	}

	/**
	 * Calculate posts_per_page for a given device from a widget's settings
	 * array, using the widget's *actual* responsive column settings (never
	 * hard-coded) combined with the Responsive Rows control.
	 *
	 * Fallback chain per device, mirroring how Elementor's own responsive
	 * controls cascade from desktop down to mobile when a breakpoint value
	 * has not been explicitly set:
	 *   desktop -> columns,        rlg_rows
	 *   tablet  -> columns_tablet (falls back to columns),        rlg_rows_tablet (falls back to rlg_rows)
	 *   mobile  -> columns_mobile (falls back to columns_tablet, then columns), rlg_rows_mobile (falls back to rlg_rows_tablet, then rlg_rows)
	 *
	 * @param array<string, mixed> $settings Widget settings_for_display().
	 * @param string                $device   One of self::DEVICES.
	 * @return int
	 */
	public static function calculate_items_for_device( array $settings, string $device ): int {
		$columns = self::resolve_columns( $settings, $device );
		$rows    = self::resolve_rows( $settings, $device );

		$items = $columns * $rows;

		/**
		 * Filter the final calculated posts_per_page for a Responsive Rows
		 * enabled Loop Grid, before it is applied to the query.
		 *
		 * @param int                   $items    Calculated items for this device.
		 * @param string                $device   The resolved device.
		 * @param array<string, mixed>  $settings Full widget settings array.
		 */
		$items = (int) apply_filters( 'rlg_calculated_items', $items, $device, $settings );

		return max( 1, $items );
	}

	/**
	 * Resolve the effective column count for a device, using Elementor's
	 * own cascading fallback (desktop -> tablet -> mobile).
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @param string                $device   One of self::DEVICES.
	 * @return int
	 */
	private static function resolve_columns( array $settings, string $device ): int {
		$desktop = self::to_positive_int( $settings['columns'] ?? null, 3 );
		$tablet  = self::to_positive_int( $settings['columns_tablet'] ?? null, $desktop );
		$mobile  = self::to_positive_int( $settings['columns_mobile'] ?? null, $tablet );

		return match ( $device ) {
			'mobile' => $mobile,
			'tablet' => $tablet,
			default  => $desktop,
		};
	}

	/**
	 * Resolve the effective Responsive Rows value for a device, using the
	 * same cascading fallback pattern as Elementor's native responsive
	 * controls (add_responsive_control on a control named "rlg_rows"
	 * produces rlg_rows / rlg_rows_tablet / rlg_rows_mobile settings keys).
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @param string                $device   One of self::DEVICES.
	 * @return int
	 */
	private static function resolve_rows( array $settings, string $device ): int {
		$desktop = self::to_positive_int( $settings['rlg_rows'] ?? null, 2 );
		$tablet  = self::to_positive_int( $settings['rlg_rows_tablet'] ?? null, $desktop );
		$mobile  = self::to_positive_int( $settings['rlg_rows_mobile'] ?? null, $tablet );

		return match ( $device ) {
			'mobile' => $mobile,
			'tablet' => $tablet,
			default  => $desktop,
		};
	}

	/**
	 * Cast a raw, possibly-empty setting value to a positive integer,
	 * falling back to a provided default when empty, non-numeric, or less
	 * than 1 (handles "Rows = 0" and similar edge cases safely).
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $default Fallback when value is missing/invalid.
	 * @return int
	 */
	private static function to_positive_int( $value, int $default ): int {
		if ( '' === $value || null === $value || false === $value ) {
			return max( 1, $default );
		}

		$int = (int) $value;

		return $int > 0 ? $int : max( 1, $default );
	}

	/**
	 * Get Elementor's actual configured breakpoint values (not hard-coded
	 * 767/1024), for use both server-side (debug) and client-side (JS
	 * localisation) so the plugin stays correct if a site customises its
	 * breakpoints or enables additional ones (mobile extra, tablet extra,
	 * laptop, widescreen).
	 *
	 * @return array<string, int> Map of breakpoint name => max-width value in px.
	 */
	public static function get_breakpoint_values(): array {
		$defaults = array(
			'mobile' => 767,
			'tablet' => 1024,
		);

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $defaults;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( ! isset( $elementor->breakpoints ) || ! method_exists( $elementor->breakpoints, 'get_breakpoints' ) ) {
			return $defaults;
		}

		try {
			$breakpoints = $elementor->breakpoints->get_breakpoints();
		} catch ( \Throwable $e ) {
			Debug::log( 'breakpoints_error', array( 'message' => $e->getMessage() ) );
			return $defaults;
		}

		$values = array();
		foreach ( $breakpoints as $name => $breakpoint ) {
			if ( is_object( $breakpoint ) && method_exists( $breakpoint, 'get_value' ) && method_exists( $breakpoint, 'is_enabled' ) ) {
				if ( $breakpoint->is_enabled() ) {
					$values[ $name ] = (int) $breakpoint->get_value();
				}
			}
		}

		// Always guarantee mobile/tablet keys exist, since our device model
		// (mobile/tablet/desktop) only needs those two thresholds even if
		// the site has additional custom breakpoints active.
		if ( ! isset( $values['mobile'] ) ) {
			$values['mobile'] = $defaults['mobile'];
		}
		if ( ! isset( $values['tablet'] ) ) {
			$values['tablet'] = $defaults['tablet'];
		}

		return $values;
	}
}
