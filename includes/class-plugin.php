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

		if ( ! $this->environment_is_valid() ) {
			// Fail gracefully: no fatals, no functionality registered.
			return;
		}

		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Whether PHP, WordPress, Elementor and Elementor Pro all meet the
	 * plugin's minimum requirements.
	 *
	 * @return bool
	 */
	private function environment_is_valid(): bool {
		if ( version_compare( PHP_VERSION, RLG_MIN_PHP_VERSION, '<' ) ) {
			return false;
		}

		global $wp_version;
		if ( ! empty( $wp_version ) && version_compare( $wp_version, RLG_MIN_WP_VERSION, '<' ) ) {
			return false;
		}

		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
			// Loop Grid is an Elementor Pro widget - Pro is required.
			return false;
		}

		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, RLG_MIN_ELEMENTOR_VERSION, '<' ) ) {
			return false;
		}

		return true;
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

		add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_frontend_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_assets' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'wp_footer', array( 'RLG\\Debug', 'maybe_print_footer_comment' ), 999 );

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'responsive-loop-grid-rows', false, dirname( RLG_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Register (but do not necessarily enqueue) the front-end script/style.
	 * Registering early via elementor/frontend/after_register_scripts keeps
	 * us aligned with Elementor's own asset pipeline/versioning.
	 */
	public function register_frontend_assets(): void {
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
	}

	/**
	 * Enqueue the front-end script/style and localize the data it needs
	 * (breakpoints, ajax url, nonce). We enqueue unconditionally but the
	 * script itself is a no-op if it finds no `.rlg-responsive-grid`
	 * elements on the page, so the cost on pages without a Responsive Rows
	 * enabled Loop Grid is negligible (one tiny, cached, deferred file).
	 */
	public function maybe_enqueue_frontend_assets(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script( 'rlg-responsive-rows' );
		wp_enqueue_style( 'rlg-responsive-rows' );

		wp_localize_script(
			'rlg-responsive-rows',
			'RLG_Data',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'rlg_ajax' ),
				'breakpoints'    => Responsive_Query::get_breakpoint_values(),
				'debug'          => (bool) RLG_DEBUG,
				'correctionMode' => get_option( 'rlg_ajax_correction_mode', 'auto' ),
			)
		);
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
	 * Print admin notices for any failed environment checks. This runs on
	 * every admin_notices call, but only prints something when a check
	 * actually fails, and only to users who can manage plugins.
	 */
	public function maybe_print_admin_notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$messages = array();

		if ( version_compare( PHP_VERSION, RLG_MIN_PHP_VERSION, '<' ) ) {
			$messages[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				esc_html__( 'Responsive Loop Grid Rows requires PHP %1$s or higher. You are running PHP %2$s.', 'responsive-loop-grid-rows' ),
				esc_html( RLG_MIN_PHP_VERSION ),
				esc_html( PHP_VERSION )
			);
		}

		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			$messages[] = esc_html__( 'Responsive Loop Grid Rows requires the Elementor plugin to be installed and active.', 'responsive-loop-grid-rows' );
		} elseif ( ! class_exists( '\ElementorPro\Plugin' ) ) {
			$messages[] = esc_html__( 'Responsive Loop Grid Rows requires Elementor Pro to be installed and active (the Loop Grid widget is an Elementor Pro feature).', 'responsive-loop-grid-rows' );
		} elseif ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, RLG_MIN_ELEMENTOR_VERSION, '<' ) ) {
			$messages[] = sprintf(
				/* translators: 1: required Elementor version, 2: current Elementor version. */
				esc_html__( 'Responsive Loop Grid Rows requires Elementor %1$s or higher. You are running Elementor %2$s.', 'responsive-loop-grid-rows' ),
				esc_html( RLG_MIN_ELEMENTOR_VERSION ),
				esc_html( ELEMENTOR_VERSION )
			);
		}

		if ( empty( $messages ) ) {
			return;
		}

		foreach ( $messages as $message ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
		}
	}
}
