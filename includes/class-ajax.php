<?php
/**
 * AJAX endpoint used to correct a Loop Grid's content to the visitor's real
 * breakpoint, without ever baking a device-specific product count into a
 * page that could be served from a shared HTML/CDN cache.
 *
 * @package ResponsiveLoopGridRows
 */

namespace RLG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ajax
 *
 * See README.md "Caching strategy" for the full architecture. In short:
 *
 * - The normal, cacheable page request always renders the Loop Grid using
 *   the site's configured *default/fallback* device (Settings page,
 *   "desktop" out of the box). That output is identical for every visitor
 *   and is therefore perfectly safe to cache.
 * - Our front-end script (assets/js/responsive-rows.js) detects the
 *   visitor's *actual* breakpoint using Elementor's own breakpoint values
 *   via matchMedia(). If, and only if, it differs from the fallback
 *   device, it calls this AJAX endpoint to fetch the correct HTML and
 *   swaps it in.
 * - This endpoint is served by admin-ajax.php, which full-page-cache and
 *   CDN setups never cache, so it is always executed fresh, per visitor.
 * - We re-render exactly one widget (via Elementor's own
 *   Document::render_element()), not the whole page, so this stays cheap.
 */
class Ajax {

	/**
	 * Wire up the AJAX actions, for logged-in and logged-out users alike.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_rlg_render_grid', array( __CLASS__, 'handle_render_grid' ) );
		add_action( 'wp_ajax_nopriv_rlg_render_grid', array( __CLASS__, 'handle_render_grid' ) );
	}

	/**
	 * Handle the `rlg_render_grid` AJAX action.
	 *
	 * Expected POST parameters:
	 * - nonce        (required) created via wp_create_nonce( 'rlg_ajax' ).
	 * - document_id  (required) Elementor document id containing the widget.
	 * - widget_id    (required) Elementor element id of the Loop Grid.
	 * - device       (required) one of mobile|tablet|desktop.
	 * - query_string (optional) the visitor's current location.search, used
	 *                only to let the widget's own "current page" detection
	 *                see the same values it would on a normal page load.
	 */
	public static function handle_render_grid(): void {
		if ( ! check_ajax_referer( 'rlg_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			wp_send_json_error( array( 'message' => 'elementor_unavailable' ), 500 );
		}

		$document_id = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		$widget_id   = isset( $_POST['widget_id'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_id'] ) ) : '';
		$device      = isset( $_POST['device'] ) ? Pagination::sanitize_device( wp_unslash( $_POST['device'] ) ) : 'desktop';
		$raw_query   = isset( $_POST['query_string'] ) ? sanitize_text_field( wp_unslash( $_POST['query_string'] ) ) : '';

		if ( $document_id <= 0 || '' === $widget_id || ! preg_match( '/^[a-zA-Z0-9]{1,32}$/', $widget_id ) ) {
			wp_send_json_error( array( 'message' => 'invalid_parameters' ), 400 );
		}

		$document = \Elementor\Plugin::$instance->documents->get( $document_id );

		if ( ! $document || ( method_exists( $document, 'is_built_with_elementor' ) && ! $document->is_built_with_elementor() ) ) {
			wp_send_json_error( array( 'message' => 'document_not_found' ), 404 );
		}

		if ( method_exists( $document, 'is_publish' ) && ! $document->is_publish() && ! current_user_can( 'edit_post', $document_id ) ) {
			wp_send_json_error( array( 'message' => 'not_permitted' ), 403 );
		}

		try {
			$elements_data = method_exists( $document, 'get_elements_data' ) ? $document->get_elements_data() : array();
		} catch ( \Throwable $e ) {
			Debug::log( 'ajax_elements_data_error', array( 'message' => $e->getMessage() ) );
			wp_send_json_error( array( 'message' => 'render_failed' ), 500 );
			return;
		}

		if ( ! is_array( $elements_data ) ) {
			wp_send_json_error( array( 'message' => 'document_not_found' ), 404 );
		}

		$widget_element = Pagination::find_widget_element( $elements_data, $widget_id, Responsive_Query::WIDGET_NAME );

		if ( null === $widget_element ) {
			wp_send_json_error( array( 'message' => 'widget_not_found' ), 404 );
		}

		$settings = $widget_element['settings'] ?? array();
		if ( empty( $settings['rlg_enable'] ) || 'yes' !== $settings['rlg_enable'] ) {
			// Defence in depth: this endpoint only ever serves Responsive
			// Rows enabled widgets, even if a widget id for some other
			// Loop Grid is supplied.
			wp_send_json_error( array( 'message' => 'not_enabled' ), 400 );
		}

		if ( ! method_exists( $document, 'render_element' ) ) {
			// Elementor's internal rendering API changed shape; fail
			// gracefully rather than fatally, and log for diagnosis.
			Debug::log( 'ajax_render_element_missing', array( 'widget_id' => $widget_id ) );
			wp_send_json_error( array( 'message' => 'render_unavailable' ), 500 );
		}

		$safe_query_vars = self::maybe_extract_query_vars( $raw_query );

		Responsive_Query::$forced_device = $device;

		try {
			$html = Pagination::with_merged_get(
				$safe_query_vars,
				static function () use ( $document, $widget_element ) {
					ob_start();
					$document->render_element( $widget_element );
					return ob_get_clean();
				}
			);
		} catch ( \Throwable $e ) {
			Debug::log( 'ajax_render_exception', array( 'message' => $e->getMessage() ) );
			Responsive_Query::$forced_device = null;
			wp_send_json_error( array( 'message' => 'render_failed' ), 500 );
			return;
		}

		Responsive_Query::$forced_device = null;

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			wp_send_json_error( array( 'message' => 'empty_render' ), 500 );
		}

		Debug::log(
			'ajax_render_success',
			array(
				'widget_id'   => $widget_id,
				'document_id' => $document_id,
				'device'      => $device,
			)
		);

		wp_send_json_success(
			array(
				'html'   => $html,
				'device' => $device,
			)
		);
	}

	/**
	 * Small wrapper so an empty/missing query string never reaches
	 * Pagination::extract_safe_query_vars() with unexpected input types.
	 *
	 * @param string $raw_query Raw query string from the request.
	 * @return array<string, string>
	 */
	private static function maybe_extract_query_vars( string $raw_query ): array {
		if ( '' === $raw_query ) {
			return array();
		}

		return Pagination::extract_safe_query_vars( $raw_query );
	}
}
