<?php
/**
 * Dependency-free test runner, for machines where PHPUnit is not installed
 * (`php tests/run.php`). With PHPUnit installed, use `vendor/bin/phpunit`
 * instead; the same test classes run under both.
 *
 * It provides just enough of PHPUnit\Framework\TestCase for these tests.
 */

namespace PHPUnit\Framework {

	class AssertionFailedError extends \Exception {}

	abstract class TestCase {

		protected function setUp(): void {}

		protected function tearDown(): void {}

		public function runSetUp(): void {
			$this->setUp();
		}

		public function runTearDown(): void {
			$this->tearDown();
		}

		private function fail( string $message ): void {
			throw new AssertionFailedError( $message );
		}

		private function show( $value ): string {
			return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		}

		public static function assertSame( $expected, $actual, string $message = '' ): void {
			if ( $expected !== $actual ) {
				throw new AssertionFailedError( trim( $message . ' Expected ' . json_encode( $expected, JSON_UNESCAPED_UNICODE ) . ' but got ' . json_encode( $actual, JSON_UNESCAPED_UNICODE ) ) );
			}
		}

		public static function assertTrue( $actual, string $message = '' ): void {
			self::assertSame( true, $actual, $message );
		}

		public static function assertFalse( $actual, string $message = '' ): void {
			self::assertSame( false, $actual, $message );
		}

		public static function assertNull( $actual, string $message = '' ): void {
			self::assertSame( null, $actual, $message );
		}

		public static function assertCount( int $expected, $actual, string $message = '' ): void {
			self::assertSame( $expected, count( $actual ), $message );
		}
	}
}

namespace {

	require_once __DIR__ . '/bootstrap.php';

	$files   = glob( __DIR__ . '/*Test.php' );
	$passed  = 0;
	$failed  = 0;
	$reports = array();

	foreach ( $files as $file ) {
		$before = get_declared_classes();
		require_once $file;
		$classes = array_diff( get_declared_classes(), $before );

		foreach ( $classes as $class ) {
			if ( ! is_subclass_of( $class, \PHPUnit\Framework\TestCase::class ) ) {
				continue;
			}

			foreach ( get_class_methods( $class ) as $method ) {
				if ( 0 !== strpos( $method, 'test_' ) ) {
					continue;
				}

				$test = new $class();

				try {
					$test->runSetUp();
					$test->$method();
					++$passed;
					echo '.';
				} catch ( \Throwable $e ) {
					++$failed;
					echo 'F';
					$reports[] = $class . '::' . $method . "\n    " . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
				} finally {
					try {
						$test->runTearDown();
					} catch ( \Throwable $e ) {
						// A failing teardown must not hide the test result.
					}
				}
			}
		}
	}

	echo "\n\n";

	foreach ( $reports as $report ) {
		echo "FAIL " . $report . "\n";
	}

	echo sprintf( "%d passed, %d failed\n", $passed, $failed );

	exit( $failed ? 1 : 0 );
}
