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
 * See readme.txt "Caching strategy" for the full architecture. In short:
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
 *
 * Why there is no nonce: this endpoint is public and read-only - it returns
 * markup that is already visible on the public page - so a nonce would add
 * no security. Worse, a nonce embedded in a cached page expires after
 * 12-24 hours while the cached copy lives on, which would make the
 * correction silently stop working on any site whose page cache outlives a
 * nonce. Access is instead enforced by can_access_post() below.
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
	 * - document_id  (required) Elementor document id containing the widget.
	 * - widget_id    (required) Elementor element id of the Loop Grid.
	 * - device       (required) one of mobile|tablet|desktop.
	 * - post_id      (optional) the singular post being viewed, so a widget
	 *                inside a Theme Builder template renders in that context.
	 * - query_string (optional) the visitor's current location.search, used
	 *                only to let the widget's own "current page" detection
	 *                see the same values it would on a normal page load.
	 */
	public static function handle_render_grid(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public, read-only endpoint by design; see the class docblock.
		$document_id = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		$post_id     = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$widget_id   = isset( $_POST['widget_id'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_id'] ) ) : '';
		$device      = isset( $_POST['device'] ) ? Pagination::sanitize_device( wp_unslash( $_POST['device'] ) ) : 'desktop';
		$raw_query   = isset( $_POST['query_string'] ) ? sanitize_text_field( wp_unslash( $_POST['query_string'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			wp_send_json_error( array( 'message' => 'elementor_unavailable' ), 500 );
		}

		if ( $document_id <= 0 || '' === $widget_id || ! preg_match( '/^[a-zA-Z0-9]{1,32}$/', $widget_id ) ) {
			wp_send_json_error( array( 'message' => 'invalid_parameters' ), 400 );
		}

		// Fail closed: the document (and the context post, if any) must be
		// something the current visitor is allowed to see.
		if ( ! self::can_access_post( $document_id ) ) {
			wp_send_json_error( array( 'message' => 'not_permitted' ), 403 );
		}

		if ( $post_id > 0 && ! self::can_access_post( $post_id ) ) {
			$post_id = 0;
		}

		$document = \Elementor\Plugin::$instance->documents->get( $document_id );

		if ( ! $document || ( method_exists( $document, 'is_built_with_elementor' ) && ! $document->is_built_with_elementor() ) ) {
			wp_send_json_error( array( 'message' => 'document_not_found' ), 404 );
		}

		if ( ! method_exists( $document, 'render_element' ) || ! method_exists( $document, 'get_elements_data' ) ) {
			// Elementor's internal rendering API changed shape; fail
			// gracefully rather than fatally, and log for diagnosis.
			Debug::log( 'ajax_render_element_missing', array( 'widget_id' => $widget_id ) );
			wp_send_json_error( array( 'message' => 'render_unavailable' ), 500 );
		}

		try {
			$elements_data = $document->get_elements_data();
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

		$settings = isset( $widget_element['settings'] ) && is_array( $widget_element['settings'] ) ? $widget_element['settings'] : array();

		// Defence in depth: this endpoint only ever serves Responsive Rows
		// enabled widgets that can be corrected out-of-band, even if the id
		// of some other Loop Grid is supplied.
		if ( 'yes' !== ( $settings['rlg_enable'] ?? '' ) || ! Responsive_Query::settings_support_correction( $settings ) ) {
			wp_send_json_error( array( 'message' => 'not_enabled' ), 400 );
		}

		try {
			$html = self::render_widget(
				$document,
				$widget_element,
				$device,
				'' === $raw_query ? array() : Pagination::extract_safe_query_vars( $raw_query ),
				$post_id
			);
		} catch ( \Throwable $e ) {
			Debug::log( 'ajax_render_exception', array( 'message' => $e->getMessage() ) );
			wp_send_json_error( array( 'message' => 'render_failed' ), 500 );
			return;
		}

		if ( '' === trim( $html ) ) {
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
	 * Render one widget through Elementor's own single-element API, for a
	 * forced device, with the document/post context restored around it.
	 *
	 * Note: Document::render_element() RETURNS the rendered markup (it
	 * buffers internally) rather than printing it, so the return value is
	 * used first; captured output is only a fallback in case a future
	 * Elementor version prints instead.
	 *
	 * @param object               $document       Elementor document containing the widget.
	 * @param array<string, mixed> $widget_element Raw element data for the widget.
	 * @param string               $device         One of Responsive_Query::DEVICES.
	 * @param array<string, string> $query_vars    Sanitised query vars to expose via $_GET during the render.
	 * @param int                  $context_post_id Singular post to render in the context of, or 0.
	 * @return string Rendered HTML (may be empty on failure).
	 *
	 * @throws \Throwable Whatever Elementor throws while rendering; callers must catch.
	 */
	private static function render_widget( $document, array $widget_element, string $device, array $query_vars, int $context_post_id ): string {
		$elementor = \Elementor\Plugin::$instance;
		$documents = $elementor->documents;
		$db        = $elementor->db;

		$switched_document = false;
		$switched_post     = false;
		$buffer_level      = ob_get_level();

		Responsive_Query::$forced_device = $device;

		try {
			if ( is_object( $documents ) && method_exists( $documents, 'switch_to_document' ) && method_exists( $documents, 'restore_document' ) ) {
				$documents->switch_to_document( $document );
				$switched_document = true;
			}

			if ( $context_post_id > 0 && is_object( $db ) && method_exists( $db, 'switch_to_post' ) && method_exists( $db, 'restore_current_post' ) ) {
				$db->switch_to_post( $context_post_id );
				$switched_post = true;
			}

			$html = Pagination::with_merged_get(
				$query_vars,
				static function () use ( $document, $widget_element ) {
					ob_start();
					$returned = $document->render_element( $widget_element );
					$printed  = ob_get_clean();

					if ( is_string( $returned ) && '' !== trim( $returned ) ) {
						return $returned;
					}

					return is_string( $printed ) ? $printed : '';
				}
			);

			return is_string( $html ) ? $html : '';
		} finally {
			Responsive_Query::$forced_device = null;

			if ( $switched_post ) {
				$db->restore_current_post();
			}

			if ( $switched_document ) {
				$documents->restore_document();
			}

			// If rendering threw part-way, drop any output buffer we opened.
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Whether the current visitor may see a post/document. Fails closed.
	 *
	 * - Editors (edit_post) can always access it, including drafts.
	 * - Password-protected posts are refused for everyone else.
	 * - Elementor templates (Theme Builder headers, footers, single/archive
	 *   templates) are rendered publicly wherever they are applied, so a
	 *   published template is allowed.
	 * - Everything else must be publicly viewable (published, viewable type).
	 *
	 * @param int $post_id Post/document id.
	 * @return bool
	 */
	private static function can_access_post( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		if ( post_password_required( $post ) ) {
			return false;
		}

		if ( 'elementor_library' === $post->post_type ) {
			return 'publish' === $post->post_status;
		}

		return is_post_publicly_viewable( $post );
	}
}
