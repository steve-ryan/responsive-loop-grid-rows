<?php
/**
 * Minimal stand-ins for the WordPress and Elementor pieces the plugin's pure
 * logic touches, so the unit tests run without loading WordPress.
 */

namespace Elementor {

	class Fake_Breakpoint {
		public function __construct( private int $value, private bool $enabled = true, private string $direction = 'max' ) {}

		public function get_value() {
			return $this->value;
		}

		public function is_enabled() {
			return $this->enabled;
		}

		public function get_direction() {
			return $this->direction;
		}
	}

	class Fake_Breakpoints {
		/** @var array<string, Fake_Breakpoint> */
		public array $list = array();

		public function get_breakpoints() {
			return $this->list;
		}
	}

	class Fake_Editor {
		public bool $editing = false;

		public function is_edit_mode() {
			return $this->editing;
		}
	}

	class Plugin {
		public static $instance;
		public $breakpoints;
		public $editor;
	}
}

namespace {

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text ) {
			return $text;
		}
	}

	if ( ! function_exists( 'wp_parse_str' ) ) {
		function wp_parse_str( $string, &$result ) {
			parse_str( $string, $result );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return json_encode( $data );
		}
	}
}
