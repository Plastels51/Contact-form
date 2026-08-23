<?php
/**
 * Shared assertion runner for the plugin's test scripts.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CFS_Test_Runner' ) ) {

	/**
	 * Minimal assertion runner — the WordPress CLI image has no PHPUnit.
	 */
	class CFS_Test_Runner {

		/**
		 * Failure messages for the test being run.
		 *
		 * @var string[]
		 */
		private $failures = array();

		/**
		 * Passed test count.
		 *
		 * @var int
		 */
		private $passed = 0;

		/**
		 * Failed test names.
		 *
		 * @var string[]
		 */
		private $failed = array();

		/**
		 * Run one test case.
		 *
		 * @param string   $name Test name.
		 * @param callable $body Test body, receives $this.
		 */
		public function test( string $name, callable $body ): void {
			$this->failures = array();

			try {
				$body( $this );
			} catch ( Throwable $e ) {
				$this->failures[] = 'exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
			}

			if ( empty( $this->failures ) ) {
				++$this->passed;
				echo "  ok  {$name}\n";
				return;
			}

			$this->failed[] = $name;
			echo "FAIL  {$name}\n";
			foreach ( $this->failures as $failure ) {
				echo "        {$failure}\n";
			}
		}

		/**
		 * Assert strict equality.
		 *
		 * @param mixed  $expected Expected value.
		 * @param mixed  $actual   Actual value.
		 * @param string $label    Description.
		 */
		public function same( $expected, $actual, string $label = '' ): void {
			if ( $expected !== $actual ) {
				$this->failures[] = sprintf(
					'%sexpected %s, got %s',
					'' !== $label ? $label . ': ' : '',
					$this->dump( $expected ),
					$this->dump( $actual )
				);
			}
		}

		/**
		 * Assert truthiness.
		 *
		 * @param mixed  $value Value.
		 * @param string $label Description.
		 */
		public function ok( $value, string $label = '' ): void {
			if ( ! $value ) {
				$this->failures[] = ( '' !== $label ? $label . ': ' : '' ) . 'expected truthy, got ' . $this->dump( $value );
			}
		}

		/**
		 * Assert falsiness.
		 *
		 * @param mixed  $value Value.
		 * @param string $label Description.
		 */
		public function not( $value, string $label = '' ): void {
			if ( $value ) {
				$this->failures[] = ( '' !== $label ? $label . ': ' : '' ) . 'expected falsy, got ' . $this->dump( $value );
			}
		}

		/**
		 * Assert that a string contains a substring.
		 *
		 * @param string $needle   Substring.
		 * @param string $haystack String to search.
		 * @param string $label    Description.
		 */
		public function contains( string $needle, string $haystack, string $label = '' ): void {
			if ( false === strpos( $haystack, $needle ) ) {
				$this->failures[] = sprintf(
					'%sexpected to find %s in %s',
					'' !== $label ? $label . ': ' : '',
					$this->dump( $needle ),
					$this->dump( $haystack )
				);
			}
		}

		/**
		 * Assert that a string does not contain a substring.
		 *
		 * @param string $needle   Substring.
		 * @param string $haystack String to search.
		 * @param string $label    Description.
		 */
		public function lacks( string $needle, string $haystack, string $label = '' ): void {
			if ( false !== strpos( $haystack, $needle ) ) {
				$this->failures[] = sprintf(
					'%sexpected NOT to find %s in %s',
					'' !== $label ? $label . ': ' : '',
					$this->dump( $needle ),
					$this->dump( $haystack )
				);
			}
		}

		/**
		 * Print the summary and return the process exit code.
		 *
		 * @return int
		 */
		public function summary(): int {
			$total = $this->passed + count( $this->failed );
			echo "\n";
			echo str_repeat( '─', 60 ) . "\n";
			printf( "%d/%d passed\n", $this->passed, $total );

			if ( ! empty( $this->failed ) ) {
				echo "failed:\n";
				foreach ( $this->failed as $name ) {
					echo "  · {$name}\n";
				}
				return 1;
			}

			return 0;
		}

		/**
		 * Compact value dump for failure messages.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		private function dump( $value ): string {
			if ( is_string( $value ) ) {
				return '"' . $value . '"';
			}
			if ( is_bool( $value ) ) {
				return $value ? 'true' : 'false';
			}
			if ( is_null( $value ) ) {
				return 'null';
			}
			if ( is_array( $value ) ) {
				return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			}
			if ( is_object( $value ) ) {
				return get_class( $value );
			}
			return (string) $value;
		}
	}
}
