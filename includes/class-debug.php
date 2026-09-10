<?php
/**
 * Lightweight debug logger.
 *
 * @package ResponsiveLoopGridRows
 */

namespace RLG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Debug
 *
 * Centralises all debug output so that:
 * - Nothing is ever logged/output unless RLG_DEBUG is true.
 * - No sensitive data (settings values beyond widget config, user data,
 *   request payloads) is ever included.
 * - Front-end HTML debug comments are only shown to users who can
 *   manage_options, never to anonymous/regular visitors.
 */
class Debug {

	/**
	 * In-memory collection of debug entries for the current request, used to
	 * build a single HTML comment per page instead of one per widget.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $entries = array();

	/**
	 * Record a debug entry and optionally send it to the PHP error log.
	 *
	 * @param string               $context Short context label, e.g. 'query', 'ajax'.
	 * @param array<string, mixed> $data    Associative array of safe, non-sensitive debug data.
	 */
	public static function log( string $context, array $data ): void {
		if ( ! RLG_DEBUG ) {
			return;
		}

		$entry = array_merge( array( 'context' => $context ), $data );
		self::$entries[] = $entry;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional, gated by RLG_DEBUG.
		error_log( '[Responsive Loop Grid Rows] ' . $context . ': ' . wp_json_encode( $data ) );
	}

	/**
	 * Output a single HTML comment with all collected debug entries for this
	 * request. Hooked to wp_footer; only prints for users who can manage
	 * options, and only when RLG_DEBUG is enabled.
	 */
	public static function maybe_print_footer_comment(): void {
		if ( ! RLG_DEBUG || empty( self::$entries ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo "\n<!-- Responsive Loop Grid Rows debug:\n";
		foreach ( self::$entries as $entry ) {
			echo esc_html( wp_json_encode( $entry ) ) . "\n";
		}
		echo "-->\n";
	}
}
