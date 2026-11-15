<?php
/**
 * Contract test: the list of the end-to-end tests in tests/e2e/README.md matches the tests in tests/e2e/specs/.
 *
 * @package KKCE
 */

/**
 * Every Playwright test is listed, with its file and its exact title, in the section "Test list" of the
 * README of the suite, and the list names no test that does not exist.
 */
class KKCE_E2E_Catalog_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Titles of the tests of every spec file, as `file › title`.
	 *
	 * @return list<string>
	 */
	private function spec_tests(): array {
		$tests = array();
		foreach ( glob( KKCE_TEST_PLUGIN_DIR . '/tests/e2e/specs/*.spec.js' ) as $file ) {
			preg_match_all( "/^test\(\s*'((?:[^'\\\\]|\\\\.)+)'/m", (string) file_get_contents( $file ), $matches );
			foreach ( $matches[1] as $title ) {
				$tests[] = basename( $file ) . ' › ' . stripslashes( $title );
			}
		}
		sort( $tests );

		return $tests;
	}

	/**
	 * Tests named in the list of the README, as `file › title`: the rows of the table of the section
	 * "Test list", whose first two cells are the file and the title between backticks.
	 *
	 * @return list<string>
	 */
	private function listed_tests(): array {
		$readme = $this->read_plugin_file( 'tests/e2e/README.md' );
		$this->assertSame( 1, preg_match( '/^## Test list\n(.*?)(?=^## |\z)/ms', $readme, $section ), 'tests/e2e/README.md has no "## Test list" section.' );

		preg_match_all( '/^\|\s*`([^`]+\.spec\.js)`\s*\|\s*`([^`]+)`\s*\|/m', $section[1], $rows, PREG_SET_ORDER );
		$tests = array_map( static fn( array $row ): string => $row[1] . ' › ' . $row[2], $rows );
		sort( $tests );

		return $tests;
	}

	/**
	 * The list and the spec files name the same tests.
	 */
	public function test_every_e2e_test_is_listed_in_the_readme() {
		$specs = $this->spec_tests();
		$this->assertNotEmpty( $specs, 'No test found in tests/e2e/specs/: the check would compare nothing.' );

		$listed = $this->listed_tests();
		$this->assertSame( array(), array_values( array_diff( $specs, $listed ) ), 'Tests missing from the list of tests/e2e/README.md (section "Test list"): add a row with the file, the title and what the test does.' );
		$this->assertSame( array(), array_values( array_diff( $listed, $specs ) ), 'Rows of the list of tests/e2e/README.md that match no test: update or remove them.' );
	}
}
