<?php
/**
 * Pagination helper utilities.
 *
 * @package ResponsiveLoopGridRows
 */

namespace RLG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pagination
 *
 * WP_Query already computes max_num_pages / found_posts / offsets correctly
 * on its own once posts_per_page has been set correctly (see
 * Responsive_Query::register_query_override()). So this class does not
 * re-implement pagination maths; instead it provides the supporting
 * utilities needed to make Elementor's OWN pagination (Numbers, Prev/Next,
 * Load More) keep working correctly across our AJAX correction call:
 *
 * - Safely replaying the visitor's real page query string when we re-render
 *   a widget out-of-band via Elementor's Document::render_element(), so
 *   whatever mechanism the Loop Grid widget itself uses to read "current
 *   page" from the URL sees the same values it would on a normal request.
 * - Locating a specific widget's element data inside an Elementor
 *   document's element tree, by widget id.
 */
class Pagination {

	/**
	 * Maximum number of query string parameters we will forward. Well
	 * beyond anything a normal pagination URL would need; exists purely as
	 * a defensive cap.
	 */
	private const MAX_FORWARDED_PARAMS = 15;

	/**
	 * Parse a raw, user-supplied query string (e.g. from
	 * window.location.search) and return only the subset of parameters
	 * that look like simple pagination/filter values: alphanumeric,
	 * underscore or hyphen keys, and short scalar values. Everything else
	 * (arrays, deeply nested params, anything containing suspicious
	 * characters) is dropped rather than sanitized-and-kept, since we only
	 * need this for benign query vars like `paged`, `e-page-<id>`, or
	 * WooCommerce filter params - never for arbitrary input.
	 *
	 * @param string $raw_query_string Raw query string, without a leading "?".
	 * @return array<string, string>
	 */
	public static function extract_safe_query_vars( string $raw_query_string ): array {
		$raw_query_string = ltrim( $raw_query_string, '?' );

		if ( '' === $raw_query_string || strlen( $raw_query_string ) > 2000 ) {
			return array();
		}

		$parsed = array();
		wp_parse_str( $raw_query_string, $parsed );

		$safe  = array();
		$count = 0;

		foreach ( $parsed as $key => $value ) {
			if ( $count >= self::MAX_FORWARDED_PARAMS ) {
				break;
			}

			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $key ) ) {
				continue;
			}

			$value = (string) $value;

			if ( strlen( $value ) > 100 || ! preg_match( '/^[a-zA-Z0-9_\-,.]*$/', $value ) ) {
				continue;
			}

			$safe[ $key ] = $value;
			++$count;
		}

		return $safe;
	}

	/**
	 * Temporarily merge safe query vars into $_GET, run a callback, then
	 * always restore the original $_GET - even if the callback throws.
	 *
	 * This lets Elementor's own internal "what page am I on" logic (which
	 * reads directly from the request) behave identically during our
	 * out-of-band AJAX re-render as it would on a normal page load with
	 * that query string.
	 *
	 * @param array<string, string> $safe_query_vars Sanitised key => value pairs.
	 * @param callable               $callback        Callback to run with $_GET temporarily augmented.
	 * @return mixed Whatever $callback returns.
	 */
	public static function with_merged_get( array $safe_query_vars, callable $callback ) {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- read-only snapshot, restored below.

		try {
			foreach ( $safe_query_vars as $key => $value ) {
				$_GET[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification -- values already whitelisted/sanitised by extract_safe_query_vars().
			}

			return $callback();
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification -- restoring original state.
		}
	}

	/**
	 * Recursively search an Elementor element-data tree (as returned by
	 * Document::get_elements_data()) for a widget with a specific element
	 * id and widget type.
	 *
	 * @param array<int, array<string, mixed>> $elements   Elements array to search.
	 * @param string                             $widget_id  Elementor element id to find.
	 * @param string                             $widget_type Expected widgetType value, for an extra safety check.
	 * @return array<string, mixed>|null The matching element data, or null if not found.
	 */
	public static function find_widget_element( array $elements, string $widget_id, string $widget_type ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'] ) && $element['id'] === $widget_id ) {
				if ( isset( $element['widgetType'] ) && $element['widgetType'] === $widget_type ) {
					return $element;
				}
				// Same id but wrong type - treat as not found for safety.
				return null;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find_widget_element( $element['elements'], $widget_id, $widget_type );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Validate a device string against the known whitelist.
	 *
	 * @param mixed $device Raw, untrusted value.
	 * @return string One of Responsive_Query::DEVICES, defaulting to 'desktop'.
	 */
	public static function sanitize_device( $device ): string {
		$device = is_string( $device ) ? strtolower( trim( $device ) ) : '';

		return in_array( $device, Responsive_Query::DEVICES, true ) ? $device : 'desktop';
	}

	/**
	 * Validate/clamp a page number.
	 *
	 * @param mixed $page Raw, untrusted value.
	 * @return int A sane page number, minimum 1.
	 */
	public static function sanitize_page( $page ): int {
		$page = absint( $page );

		return $page > 0 ? $page : 1;
	}
}
