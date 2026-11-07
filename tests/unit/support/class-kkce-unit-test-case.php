<?php
/**
 * Base class of the unit tests.
 *
 * @package KKCE
 */

use Brain\Monkey;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Sets up and tears down Brain Monkey around every test.
 *
 * Inside a test, mock WordPress functions with `Brain\Monkey\Functions\when()` /
 * `expect()` and check hooks with `has_action()` / `has_filter()` or
 * `Brain\Monkey\Actions\expectAdded()`. The translation and escaping functions
 * (`__()`, `esc_html()`, `esc_html__()`, ...) are stubbed to return their input.
 */
abstract class KKCE_Unit_Test_Case extends TestCase {

	/**
	 * Start Brain Monkey and stub translation and escaping functions.
	 */
	protected function set_up() {
		parent::set_up();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
	}

	/**
	 * Verify Mockery expectations and reset Brain Monkey.
	 */
	protected function tear_down() {
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Read a file of the plugin.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	protected function read_plugin_file( $relative_path ) {
		$content = file_get_contents( KKCE_TEST_PLUGIN_DIR . '/' . $relative_path );
		$this->assertNotFalse( $content, "Cannot read {$relative_path}" );
		return str_replace( "\r\n", "\n", $content );
	}
}
