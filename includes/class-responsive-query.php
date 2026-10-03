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

	/**
	 * The three core devices. The full, live list (which also includes any
	 * extra breakpoints enabled in Elementor - Laptop, Tablet Extra, Mobile
	 * Extra, Widescreen) comes from get_devices().
	 */
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
	 * Per-request cache of get_breakpoints_config(). Only filled once
	 * Elementor's breakpoint manager has actually been read.
	 *
	 * @var array<int, array{name: string, value: int, direction: string}>|null
	 */
	private static ?array $breakpoints_cache = null;

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
		$items  = self::calculate_items_for_render( $settings, $device );

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

			// Inside the editor the query returns the largest count, and
			// assets/js/preview.js trims the grid to the device being
			// previewed, using these per-device counts.
			if ( self::is_editor_or_preview() ) {
				$counts = array();
				foreach ( self::get_devices() as $candidate ) {
					$counts[ $candidate ] = self::calculate_items_for_device( $settings, $candidate );
				}
				$widget->add_render_attribute( '_wrapper', 'data-rlg-counts', wp_json_encode( $counts ) );
			}

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

		$query_args['posts_per_page'] = self::calculate_items_for_render( $settings, self::resolve_device( $settings ) );

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

		return self::is_valid_device( $default ) ? $default : 'desktop';
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

		return self::is_valid_device( $raw ) ? $raw : null;
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
		if ( null !== self::$forced_device && self::is_valid_device( self::$forced_device ) ) {
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
	 * Items to request for the current render.
	 *
	 * On the front end this is simply the calculated count for the resolved
	 * device. Inside the Elementor editor/preview there is only one preview
	 * query for all device modes, so it returns the LARGEST count across every
	 * device; assets/js/preview.js then hides the surplus items for the device
	 * mode being previewed. (Hiding can only remove items, so the query must
	 * return at least as many as any device needs.)
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @param string               $device   The resolved device for this render.
	 * @return int
	 */
	public static function calculate_items_for_render( array $settings, string $device ): int {
		if ( null === self::$forced_device && self::is_editor_or_preview() ) {
			$max = 1;
			foreach ( self::get_devices() as $candidate ) {
				$max = max( $max, self::calculate_items_for_device( $settings, $candidate ) );
			}
			return $max;
		}

		return self::calculate_items_for_device( $settings, $device );
	}

	/**
	 * Calculate posts_per_page for a given device from a widget's settings
	 * array, using the widget's *actual* responsive column settings (never
	 * hard-coded) combined with the Responsive Rows control.
	 *
	 * Each value cascades exactly as Elementor's own responsive controls do:
	 * a device with no value of its own uses the next larger enabled
	 * breakpoint, ending at desktop (see get_inheritance_chain()).
	 *
	 * @param array<string, mixed> $settings Widget settings_for_display().
	 * @param string               $device   One of get_devices().
	 * @return int
	 */
	public static function calculate_items_for_device( array $settings, string $device ): int {
		$columns = self::resolve_setting( $settings, 'columns', $device, 3 );
		$rows    = self::resolve_setting( $settings, 'rlg_rows', $device, 2 );

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
	 * Resolve a responsive control's effective positive-integer value for a
	 * device, walking the inheritance chain until a usable value is found.
	 * Empty, non-numeric and zero values count as "not set".
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @param string               $base     Base control name, e.g. "columns" or "rlg_rows".
	 * @param string               $device   One of get_devices().
	 * @param int                  $default  Used when nothing in the chain is set.
	 * @return int
	 */
	private static function resolve_setting( array $settings, string $base, string $device, int $default ): int {
		foreach ( self::get_inheritance_chain( $device ) as $candidate ) {
			$key = 'desktop' === $candidate ? $base : $base . '_' . $candidate;
			$raw = $settings[ $key ] ?? null;

			if ( '' === $raw || null === $raw || false === $raw || is_array( $raw ) ) {
				continue;
			}

			$int = (int) $raw;

			if ( $int > 0 ) {
				return $int;
			}
		}

		return max( 1, $default );
	}

	/**
	 * The order in which a device looks for a value: itself first, then each
	 * next larger enabled breakpoint, then desktop. Mirrors how Elementor
	 * cascades responsive controls (mobile -> mobile_extra -> tablet ->
	 * tablet_extra -> laptop -> desktop). Widescreen is a min-width
	 * breakpoint above desktop, so it falls straight back to desktop.
	 *
	 * @param string $device One of get_devices().
	 * @return string[] Device names, starting with $device and ending with "desktop".
	 */
	public static function get_inheritance_chain( string $device ): array {
		if ( 'desktop' === $device ) {
			return array( 'desktop' );
		}

		$max_names = array();
		$min_names = array();

		foreach ( self::get_breakpoints_config() as $breakpoint ) {
			if ( 'min' === $breakpoint['direction'] ) {
				$min_names[] = $breakpoint['name'];
			} else {
				$max_names[] = $breakpoint['name'];
			}
		}

		if ( in_array( $device, $min_names, true ) ) {
			return array( $device, 'desktop' );
		}

		$index = array_search( $device, $max_names, true );

		if ( false === $index ) {
			return array( 'desktop' );
		}

		// $max_names is ordered smallest -> largest, so everything from the
		// device's own position onwards is "itself, then larger".
		$chain   = array_slice( $max_names, (int) $index );
		$chain[] = 'desktop';

		return $chain;
	}

	/**
	 * Every device name the plugin currently understands: each enabled
	 * Elementor breakpoint plus "desktop".
	 *
	 * @return string[]
	 */
	public static function get_devices(): array {
		$devices = array();

		foreach ( self::get_breakpoints_config() as $breakpoint ) {
			$devices[] = $breakpoint['name'];
		}

		$devices[] = 'desktop';

		return $devices;
	}

	/**
	 * Whether a value is a device name the plugin knows about right now.
	 *
	 * @param mixed $device Candidate value.
	 * @return bool
	 */
	public static function is_valid_device( $device ): bool {
		return is_string( $device ) && in_array( $device, self::get_devices(), true );
	}

	/**
	 * Elementor's enabled breakpoints, with their real widths and directions
	 * (never hard-coded 767/1024), so the plugin stays correct when a site
	 * customises its breakpoints or enables Laptop, Tablet Extra, Mobile
	 * Extra or Widescreen.
	 *
	 * Ordered: max-width breakpoints from smallest to largest, then any
	 * min-width breakpoint (Widescreen). "desktop" is the implicit default
	 * and is not listed. "mobile" and "tablet" are always present.
	 *
	 * @return array<int, array{name: string, value: int, direction: string}>
	 */
	public static function get_breakpoints_config(): array {
		if ( null !== self::$breakpoints_cache ) {
			return self::$breakpoints_cache;
		}

		$found  = array();
		$loaded = false;

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::$instance;

			if ( isset( $elementor->breakpoints ) && method_exists( $elementor->breakpoints, 'get_breakpoints' ) ) {
				try {
					foreach ( $elementor->breakpoints->get_breakpoints() as $name => $breakpoint ) {
						if ( ! is_object( $breakpoint ) || ! method_exists( $breakpoint, 'get_value' ) || ! method_exists( $breakpoint, 'is_enabled' ) || ! $breakpoint->is_enabled() ) {
							continue;
						}

						$direction = method_exists( $breakpoint, 'get_direction' ) ? (string) $breakpoint->get_direction() : 'max';
						$key       = sanitize_key( (string) $name );

						$found[ $key ] = array(
							'name'      => $key,
							'value'     => (int) $breakpoint->get_value(),
							'direction' => 'min' === $direction ? 'min' : 'max',
						);
					}
					$loaded = true;
				} catch ( \Throwable $e ) {
					Debug::log( 'breakpoints_error', array( 'message' => $e->getMessage() ) );
					$found = array();
				}
			}
		}

		// Guarantee the two core breakpoints exist even if Elementor's
		// breakpoint manager is unavailable.
		if ( ! isset( $found['mobile'] ) ) {
			$found['mobile'] = array(
				'name'      => 'mobile',
				'value'     => 767,
				'direction' => 'max',
			);
		}
		if ( ! isset( $found['tablet'] ) ) {
			$found['tablet'] = array(
				'name'      => 'tablet',
				'value'     => 1024,
				'direction' => 'max',
			);
		}

		$max = array();
		$min = array();

		foreach ( $found as $breakpoint ) {
			if ( 'min' === $breakpoint['direction'] ) {
				$min[] = $breakpoint;
			} else {
				$max[] = $breakpoint;
			}
		}

		$by_value = static function ( array $a, array $b ): int {
			return $a['value'] <=> $b['value'];
		};
		usort( $max, $by_value );
		usort( $min, $by_value );

		$config = array_merge( $max, $min );

		// Only cache a result built from Elementor's real data; before
		// Elementor has booted we return the safe defaults without caching.
		if ( $loaded ) {
			self::$breakpoints_cache = $config;
		}

		return $config;
	}

	/**
	 * Forget the cached breakpoint list. Used by tests.
	 */
	public static function reset_breakpoints_cache(): void {
		self::$breakpoints_cache = null;
	}

	/**
	 * Devices in the order a person expects to see them listed: widescreen,
	 * desktop, then the max-width breakpoints from largest to smallest.
	 *
	 * @return string[]
	 */
	public static function get_devices_display_order(): array {
		$wide = array();
		$max  = array();

		foreach ( self::get_breakpoints_config() as $breakpoint ) {
			if ( 'min' === $breakpoint['direction'] ) {
				$wide[] = $breakpoint['name'];
			} else {
				$max[] = $breakpoint['name'];
			}
		}

		return array_merge( array_reverse( $wide ), array( 'desktop' ), array_reverse( $max ) );
	}

	/**
	 * Human-readable label for a device name, e.g. "tablet_extra" -> "Tablet Extra".
	 *
	 * @param string $device Device name.
	 * @return string
	 */
	public static function get_device_label( string $device ): string {
		$labels = array(
			'desktop'      => __( 'Desktop', 'responsive-loop-grid-rows' ),
			'laptop'       => __( 'Laptop', 'responsive-loop-grid-rows' ),
			'tablet_extra' => __( 'Tablet Extra', 'responsive-loop-grid-rows' ),
			'tablet'       => __( 'Tablet', 'responsive-loop-grid-rows' ),
			'mobile_extra' => __( 'Mobile Extra', 'responsive-loop-grid-rows' ),
			'mobile'       => __( 'Mobile', 'responsive-loop-grid-rows' ),
			'widescreen'   => __( 'Widescreen', 'responsive-loop-grid-rows' ),
		);

		return $labels[ $device ] ?? ucwords( str_replace( '_', ' ', $device ) );
	}
}
