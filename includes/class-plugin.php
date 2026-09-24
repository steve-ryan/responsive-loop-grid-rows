<?php
/**
 * Core plugin bootstrap.
 *
 * @package ResponsiveLoopGridRows
 */

namespace RLG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Singleton responsible for environment checks and wiring up all the other
 * classes. Nothing here ever calls a fatal error - every dependency check
 * degrades to an admin notice instead.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Codes for any failed environment checks (see get_environment_problems()).
	 * Codes rather than translated strings, because this is computed on
	 * `plugins_loaded`, which is too early to call translation functions
	 * without triggering just-in-time textdomain notices on WordPress 6.7+.
	 *
	 * @var string[]
	 */
	private array $problems = array();

	/**
	 * Get (and lazily create) the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor is private - use Plugin::instance().
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Run environment checks and, if they pass, load the rest of the plugin.
	 */
	private function init(): void {
		add_action( 'admin_notices', array( $this, 'maybe_print_admin_notices' ) );

		$this->problems = $this->get_environment_problems();

		if ( ! empty( $this->problems ) ) {
			// Fail gracefully: no fatals, no functionality registered.
			return;
		}

		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Check PHP, WordPress, Elementor and Elementor Pro against the plugin's
	 * minimum requirements.
	 *
	 * @return string[] Problem codes; empty when everything is fine.
	 */
	private function get_environment_problems(): array {
		$problems = array();

		if ( version_compare( PHP_VERSION, RLG_MIN_PHP_VERSION, '<' ) ) {
			$problems[] = 'php';
		}

		global $wp_version;
		if ( ! empty( $wp_version ) && version_compare( $wp_version, RLG_MIN_WP_VERSION, '<' ) ) {
			$problems[] = 'wp';
		}

		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			$problems[] = 'elementor';
		} elseif ( ! class_exists( '\ElementorPro\Plugin' ) ) {
			// Loop Grid is an Elementor Pro widget - Pro is required.
			$problems[] = 'elementor_pro';
		} else {
			if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, RLG_MIN_ELEMENTOR_VERSION, '<' ) ) {
				$problems[] = 'elementor_version';
			}
			if ( defined( 'ELEMENTOR_PRO_VERSION' ) && version_compare( ELEMENTOR_PRO_VERSION, RLG_MIN_ELEMENTOR_PRO_VERSION, '<' ) ) {
				$problems[] = 'elementor_pro_version';
			}
		}

		return $problems;
	}

	/**
	 * Require the remaining plugin classes. Only called when the
	 * environment checks above have already passed.
	 */
	private function load_dependencies(): void {
		require_once RLG_PLUGIN_DIR . 'includes/class-responsive-query.php';
		require_once RLG_PLUGIN_DIR . 'includes/class-elementor-controls.php';
		require_once RLG_PLUGIN_DIR . 'includes/class-pagination.php';
		require_once RLG_PLUGIN_DIR . 'includes/class-ajax.php';
		require_once RLG_PLUGIN_DIR . 'includes/class-settings.php';
	}

	/**
	 * Wire up all the hooks for the loaded classes.
	 */
	private function register_hooks(): void {
		Elementor_Controls::init();
		Responsive_Query::init();
		Ajax::init();
		Settings::init();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'wp_footer', array( 'RLG\\Debug', 'maybe_print_footer_comment' ), 999 );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'responsive-loop-grid-rows', false, dirname( RLG_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Register and enqueue the front-end script/style, and hand the script
	 * the static, site-wide data it needs.
	 *
	 * Everything passed to the script is identical for every visitor (no
	 * nonce, no per-user or per-request values), so it is safe to have this
	 * inline data baked into a full-page/CDN cached copy of the page.
	 *
	 * The script is skipped entirely inside the Elementor editor/preview
	 * (where swapping widget markup would break the editing UI) and when
	 * AJAX correction is switched off (nothing for it to do).
	 */
	public function enqueue_frontend_assets(): void {
		if ( Responsive_Query::is_editor_or_preview() ) {
			return;
		}

		$mode = get_option( 'rlg_ajax_correction_mode', 'auto' );
		if ( 'off' === $mode ) {
			return;
		}

		wp_register_script(
			'rlg-responsive-rows',
			RLG_PLUGIN_URL . 'assets/js/responsive-rows.js',
			array(),
			RLG_VERSION,
			true
		);

		wp_register_style(
			'rlg-responsive-rows',
			RLG_PLUGIN_URL . 'assets/css/responsive-rows.css',
			array(),
			RLG_VERSION
		);

		$data = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'breakpoints'    => Responsive_Query::get_breakpoint_values(),
			'fallbackDevice' => Responsive_Query::get_fallback_device(),
			'correctionMode' => $mode,
			'debug'          => (bool) RLG_DEBUG,
		);

		wp_add_inline_script(
			'rlg-responsive-rows',
			'window.RLG_Data = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);

		wp_enqueue_script( 'rlg-responsive-rows' );
		wp_enqueue_style( 'rlg-responsive-rows' );
	}

	/**
	 * Enqueue the small editor-only helper script that live-updates the
	 * "Calculated" helper text in the panel when columns/rows change.
	 */
	public function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'rlg-editor',
			RLG_PLUGIN_URL . 'assets/js/editor.js',
			array( 'jquery' ),
			RLG_VERSION,
			true
		);
	}

	/**
	 * Suggest wording for the site's Privacy Policy page (Settings -> Privacy),
	 * because the plugin sets one small functional cookie.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Responsive Loop Grid Rows stores one small, non-identifying functional cookie in the visitor\'s browser.', 'responsive-loop-grid-rows' ) . '</p>'
			. '<h3>' . esc_html__( 'Cookies', 'responsive-loop-grid-rows' ) . '</h3>'
			. '<p>' . esc_html__( 'When a page contains a product/post grid whose number of items depends on the screen size, we store a cookie named "rlg_device" containing only the visitor\'s screen-size category (mobile, tablet or desktop). It is used solely so that "next page" and "load more" requests return the right number of items for that screen size. It contains no personal data, is not used for tracking, and expires after 24 hours.', 'responsive-loop-grid-rows' ) . '</p>';

		wp_add_privacy_policy_content( 'Responsive Loop Grid Rows', wp_kses_post( $content ) );
	}

	/**
	 * Print admin notices for any failed environment checks. Only prints
	 * something when a check actually fails, and only to users who can
	 * manage plugins.
	 */
	public function maybe_print_admin_notices(): void {
		if ( empty( $this->problems ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		foreach ( $this->problems as $problem ) {
			$message = $this->get_problem_message( $problem );

			if ( '' !== $message ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
			}
		}
	}

	/**
	 * Translate a problem code into a human-readable message.
	 *
	 * @param string $problem Problem code from get_environment_problems().
	 * @return string Plain (unescaped) message; callers must escape on output.
	 */
	private function get_problem_message( string $problem ): string {
		switch ( $problem ) {
			case 'php':
				return sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'Responsive Loop Grid Rows requires PHP %1$s or higher. You are running PHP %2$s.', 'responsive-loop-grid-rows' ),
					RLG_MIN_PHP_VERSION,
					PHP_VERSION
				);

			case 'wp':
				global $wp_version;
				return sprintf(
					/* translators: 1: required WordPress version, 2: current WordPress version. */
					__( 'Responsive Loop Grid Rows requires WordPress %1$s or higher. You are running WordPress %2$s.', 'responsive-loop-grid-rows' ),
					RLG_MIN_WP_VERSION,
					(string) $wp_version
				);

			case 'elementor':
				return __( 'Responsive Loop Grid Rows requires the Elementor plugin to be installed and active.', 'responsive-loop-grid-rows' );

			case 'elementor_pro':
				return __( 'Responsive Loop Grid Rows requires Elementor Pro to be installed and active (the Loop Grid widget is an Elementor Pro feature).', 'responsive-loop-grid-rows' );

			case 'elementor_version':
				return sprintf(
					/* translators: 1: required Elementor version, 2: current Elementor version. */
					__( 'Responsive Loop Grid Rows requires Elementor %1$s or higher. You are running Elementor %2$s.', 'responsive-loop-grid-rows' ),
					RLG_MIN_ELEMENTOR_VERSION,
					defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : ''
				);

			case 'elementor_pro_version':
				return sprintf(
					/* translators: 1: required Elementor Pro version, 2: current Elementor Pro version. */
					__( 'Responsive Loop Grid Rows requires Elementor Pro %1$s or higher. You are running Elementor Pro %2$s.', 'responsive-loop-grid-rows' ),
					RLG_MIN_ELEMENTOR_PRO_VERSION,
					defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : ''
				);
		}

		return '';
	}
}
