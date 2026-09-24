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
 * 3. Applies that number as `posts_per_page` on the *actual* query, without
 *    touching orderby/order/meta_query/tax_query/post__in or any other
 *    argument. Two independent paths are used, each sufficient on its own:
 *      a. The `elementor/query/query_args` filter, which Elementor Pro runs
 *         on the query arguments of Query-Control widgets. It receives the
 *         widget, so it works whether or not a custom "Query ID" is set.
 *      b. Elementor's documented `elementor/query/{$query_id}` action, which
 *         only fires when the widget has a custom Query ID set. It runs late
 *         (priority 20) so it also wins over a site's own snippet on the
 *         same hook, and only for the widget it was registered for.
 *
 * Device resolution order for a given render:
 * 1. An explicit forced device (set by our AJAX correction endpoint, see
 *    Ajax::handle_render_grid()) - this is the authoritative, validated
 *    value for that one render.
 * 2. Elementor editor/preview mode always resolves to "desktop" (see the
 *    README for why true live per-device preview isn't practical).
 * 3. The visitor's `rlg_device` cookie - but ONLY on paginated requests
 *    (URLs carrying an `e-page-*` argument, i.e. Numbers/Load More pages 2+),
 *    only for grids that AJAX correction can also correct (so page 1 and
 *    page N always agree), and only together with no-cache headers so the
 *    device-specific response is never stored by a page cache or CDN.
 * 4. Otherwise, the site's configured fallback/default device (Settings ->
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
	 * Name of the cookie written by assets/js/responsive-rows.js.
	 */
	public const COOKIE_NAME = 'rlg_device';

	/**
	 * Device explicitly forced for the current PHP request (used only by
	 * our AJAX correction endpoint, for exactly one render_element() call).
	 *
	 * @var string|null
	 */
	public static ?string $forced_device = null;

	/**
	 * Guards against registering the same dynamic query action twice for
	 * the same widget/query-id/item-count combination within one request.
	 *
	 * @var array<string, bool>
	 */
	private static array $registered_query_hooks = array();

	/**
	 * Wire up the hooks that act on Loop Grid widgets.
	 */
	public static function init(): void {
		add_action( 'elementor/frontend/widget/before_render', array( __CLASS__, 'before_render' ), 20 );
		add_filter( 'elementor/query/query_args', array( __CLASS__, 'filter_query_args' ), 20, 2 );
		add_action( 'send_headers', array( __CLASS__, 'maybe_send_nocache_headers' ) );
	}

	/**
	 * Return a widget's settings if - and only if - it is a Loop Grid with
	 * Responsive Rows enabled; otherwise null.
	 *
	 * @param mixed $widget Candidate widget.
	 * @return array<string, mixed>|null
	 */
	private static function get_active_settings( $widget ): ?array {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || self::WIDGET_NAME !== $widget->get_name() ) {
			return null;
		}

		$settings = $widget->get_settings_for_display();

		if ( ! is_array( $settings ) || 'yes' !== ( $settings['rlg_enable'] ?? '' ) ) {
			return null;
		}

		return $settings;
	}

	/**
	 * Fires for every widget on the page, right before it renders. We only
	 * act on Loop Grid widgets that have Responsive Rows enabled.
	 *
	 * @param \Elementor\Widget_Base $widget The widget about to render.
	 */
	public static function before_render( $widget ): void {
		$settings = self::get_active_settings( $widget );

		if ( null === $settings ) {
			return;
		}

		$device = self::resolve_device( $settings );
		$items  = self::calculate_items_for_device( $settings, $device );

		$widget_id = (string) $widget->get_id();
		$query_id  = ! empty( $settings['post_query_query_id'] ) ? (string) $settings['post_query_query_id'] : $widget_id;

		self::register_query_override( $query_id, $widget_id, $items );

		// Mark the wrapper element so our front-end JS can find it. Elementor
		// escapes render-attribute values itself when printing them, so the
		// raw values are passed here (escaping twice would corrupt them).
		if ( method_exists( $widget, 'add_render_attribute' ) ) {
			$widget->add_render_attribute( '_wrapper', 'class', 'rlg-responsive-grid' );
			$widget->add_render_attribute( '_wrapper', 'data-rlg-widget-id', $widget_id );
			$widget->add_render_attribute( '_wrapper', 'data-rlg-query-id', $query_id );
			$widget->add_render_attribute( '_wrapper', 'data-rlg-device', $device );

			// Grids that use "Current Query" (archives) depend on the page's
			// main query, which cannot be reproduced inside admin-ajax.php.
			// Those are deliberately left uncorrected (they keep the
			// fallback device's item count) rather than risk swapping in the
			// wrong products, so they get no document id for the JS to use.
			if ( self::settings_support_correction( $settings ) ) {
				$document_id = self::get_current_document_id();
				if ( $document_id ) {
					$widget->add_render_attribute( '_wrapper', 'data-rlg-document-id', (string) $document_id );
				}

				// Theme Builder templates (e.g. a Single Product template) are
				// rendered in the context of the currently viewed post; pass
				// it along so the AJAX re-render can restore that context.
				$post_id = is_singular() ? (int) get_queried_object_id() : 0;
				if ( $post_id > 0 ) {
					$widget->add_render_attribute( '_wrapper', 'data-rlg-post-id', (string) $post_id );
				}
			}
		}

		Debug::log(
			'before_render',
			array(
				'widget_id' => $widget_id,
				'query_id'  => $query_id,
				'device'    => $device,
				'items'     => $items,
			)
		);
	}

	/**
	 * `elementor/query/query_args` callback: set posts_per_page in the query
	 * arguments of a Responsive Rows enabled Loop Grid. Works with or without
	 * a custom Query ID, and is scoped to the widget it is called for.
	 *
	 * @param mixed $query_args Query arguments.
	 * @param mixed $widget     The widget the query is being built for.
	 * @return mixed
	 */
	public static function filter_query_args( $query_args, $widget = null ) {
		if ( ! is_array( $query_args ) ) {
			return $query_args;
		}

		$settings = self::get_active_settings( $widget );

		if ( null === $settings ) {
			return $query_args;
		}

		$query_args['posts_per_page'] = self::calculate_items_for_device( $settings, self::resolve_device( $settings ) );

		return $query_args;
	}

	/**
	 * Register (once per widget/query-id/count per request) the action that
	 * sets posts_per_page on the real WP_Query object right before it runs.
	 * Only effective when the widget has a custom Query ID; see the class
	 * docblock for why the query_args filter above exists as well.
	 *
	 * @param string $query_id  Elementor query id (custom or the widget id).
	 * @param string $widget_id Elementor widget element id.
	 * @param int    $items     Calculated posts_per_page for this render.
	 */
	private static function register_query_override( string $query_id, string $widget_id, int $items ): void {
		$hook_key = $query_id . '|' . $widget_id . '|' . $items;

		if ( isset( self::$registered_query_hooks[ $hook_key ] ) ) {
			return;
		}
		self::$registered_query_hooks[ $hook_key ] = true;

		add_action(
			"elementor/query/{$query_id}",
			static function ( $query, $query_widget = null ) use ( $items, $widget_id ) {
				// Several grids may share one Query ID (e.g. "sale_products").
				// Elementor passes the widget as the 2nd argument; when it is
				// present, only act for the widget this callback belongs to.
				if ( is_object( $query_widget ) && method_exists( $query_widget, 'get_id' ) && (string) $query_widget->get_id() !== $widget_id ) {
					return;
				}

				if ( ! is_object( $query ) || ! method_exists( $query, 'set' ) ) {
					return;
				}

				$query->set( 'posts_per_page', $items );
			},
			20,
			2
		);
	}

	/**
	 * Whether the AJAX correction (and therefore the cookie) can be applied
	 * to a widget with these settings. False for "Current Query" grids.
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @return bool
	 */
	public static function settings_support_correction( array $settings ): bool {
		return 'current_query' !== ( $settings['post_query_post_type'] ?? '' );
	}

	/**
	 * The site-wide fallback device used for every cacheable render.
	 *
	 * @return string One of self::DEVICES.
	 */
	public static function get_fallback_device(): string {
		$default = get_option( 'rlg_default_device', 'desktop' );

		return in_array( $default, self::DEVICES, true ) ? $default : 'desktop';
	}

	/**
	 * Whether the current request is a Loop Grid pagination request (Numbers
	 * or Load More, page 2+). Elementor's Loop Grid carries the page in an
	 * `e-page-<widget id>` query argument.
	 *
	 * @return bool
	 */
	public static function is_paginated_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only inspects key names; nothing is read, stored or output.
		foreach ( array_keys( $_GET ) as $key ) {
			if ( is_string( $key ) && 0 === strpos( $key, 'e-page-' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The device stored in the visitor's cookie, if it may be honoured for
	 * this request: correction is on, this is a paginated request, and the
	 * cookie holds a known device. Never used for a plain page load, which
	 * must stay identical for every visitor so it can be cached.
	 *
	 * @return string|null One of self::DEVICES, or null.
	 */
	public static function get_cookie_device(): ?string {
		if ( 'off' === get_option( 'rlg_ajax_correction_mode', 'auto' ) ) {
			return null;
		}

		if ( ! self::is_paginated_request() ) {
			return null;
		}

		$raw = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';

		return in_array( $raw, self::DEVICES, true ) ? $raw : null;
	}

	/**
	 * `send_headers` callback: when a paginated response will differ from the
	 * fallback because of the visitor's cookie, tell page caches/CDNs not to
	 * store it, so one visitor's device-specific page can never be served to
	 * another. Requests that resolve to the fallback device are unaffected.
	 */
	public static function maybe_send_nocache_headers(): void {
		if ( is_admin() ) {
			return;
		}

		$device = self::get_cookie_device();

		if ( null === $device || self::get_fallback_device() === $device ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
	}

	/**
	 * Work out which "device" this render should use.
	 *
	 * @param array<string, mixed> $settings Widget settings (used to decide whether the cookie may apply).
	 * @return string One of self::DEVICES.
	 */
	public static function resolve_device( array $settings = array() ): string {
		if ( null !== self::$forced_device && in_array( self::$forced_device, self::DEVICES, true ) ) {
			return self::$forced_device;
		}

		if ( self::is_editor_or_preview() ) {
			return 'desktop';
		}

		if ( self::settings_support_correction( $settings ) ) {
			$cookie_device = self::get_cookie_device();

			if ( null !== $cookie_device ) {
				return $cookie_device;
			}
		}

		return self::get_fallback_device();
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
