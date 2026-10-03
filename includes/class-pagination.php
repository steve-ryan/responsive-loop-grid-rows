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
	private const MAX_FORWARDED_PARAMS = 40;

	/**
	 * Longest accepted value for a single forwarded parameter, and the most
	 * items accepted in an array parameter such as `filter[]=a&filter[]=b`.
	 */
	private const MAX_VALUE_LENGTH = 200;
	private const MAX_ARRAY_ITEMS  = 25;

	/**
	 * Longest raw query string accepted at all.
	 */
	private const MAX_QUERY_LENGTH = 4000;

	/**
	 * Parse a raw, user-supplied query string (e.g. from
	 * window.location.search) and return only the parameters that are safe
	 * to expose to the widget's own query while it re-renders.
	 *
	 * Kept: simple keys (letters, digits, underscore, hyphen) whose value is
	 * a short string, or a flat list of short strings (so `?brand[]=a&brand[]=b`
	 * and `?s=blue+shirt` style filters survive). Each string is passed
	 * through sanitize_text_field(), which strips tags and control characters.
	 *
	 * Dropped: anything nested deeper than one level, keys with other
	 * characters, and values over the length limits. Dropped rather than
	 * truncated, because a half-applied filter would show wrong results.
	 *
	 * @param string $raw_query_string Raw query string, with or without a leading "?".
	 * @return array<string, string|string[]>
	 */
	public static function extract_safe_query_vars( string $raw_query_string ): array {
		$raw_query_string = ltrim( $raw_query_string, '?' );

		if ( '' === $raw_query_string || strlen( $raw_query_string ) > self::MAX_QUERY_LENGTH ) {
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

			$key = (string) $key;

			if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean = self::clean_list( $value );

				if ( null === $clean ) {
					continue;
				}

				$safe[ $key ] = $clean;
				++$count;
				continue;
			}

			$clean = self::clean_value( $value );

			if ( null === $clean ) {
				continue;
			}

			$safe[ $key ] = $clean;
			++$count;
		}

		return $safe;
	}

	/**
	 * Sanitise one scalar value; null means "reject".
	 *
	 * @param mixed $value Raw parsed value.
	 * @return string|null
	 */
	private static function clean_value( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = (string) $value;

		if ( strlen( $value ) > self::MAX_VALUE_LENGTH ) {
			return null;
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Sanitise a flat list of scalars; null means "reject the whole list".
	 *
	 * @param array<mixed> $values Raw parsed array.
	 * @return string[]|null
	 */
	private static function clean_list( array $values ): ?array {
		if ( count( $values ) > self::MAX_ARRAY_ITEMS ) {
			return null;
		}

		$clean = array();

		foreach ( $values as $item_key => $item ) {
			$item = self::clean_value( $item );

			if ( null === $item ) {
				return null;
			}

			// Keep numeric keys (`a[]=x`) as a list, and simple named keys.
			if ( is_int( $item_key ) ) {
				$clean[] = $item;
			} elseif ( preg_match( '/^[a-zA-Z0-9_\-]+$/', (string) $item_key ) ) {
				$clean[ (string) $item_key ] = $item;
			} else {
				return null;
			}
		}

		return $clean;
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
	 * @param array<string, string|string[]> $safe_query_vars Sanitised key => value pairs.
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
	 * @return string One of Responsive_Query::get_devices(), defaulting to 'desktop'.
	 */
	public static function sanitize_device( $device ): string {
		$device = is_string( $device ) ? strtolower( trim( $device ) ) : '';

		return Responsive_Query::is_valid_device( $device ) ? $device : 'desktop';
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
