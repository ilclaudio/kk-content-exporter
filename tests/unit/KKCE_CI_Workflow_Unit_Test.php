<?php
/**
 * Contract test of the GitHub Actions workflows.
 *
 * @package KKCE
 */

/**
 * Every action used by a workflow is pinned to a full commit SHA, with its tag in a comment.
 *
 * A tag (`@v2`) can be moved by whoever controls the action repository; a commit SHA cannot. The
 * comment, with the exact tag name of that commit (`v5.1.0`, or `2.40.0` for repositories that tag
 * without the `v`), keeps the version readable for whoever updates the pin.
 */
class KKCE_CI_Workflow_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Workflow files of the repository.
	 *
	 * @return list<string> Paths relative to the plugin root.
	 */
	private function workflows(): array {
		$files = glob( KKCE_TEST_PLUGIN_DIR . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE );
		$this->assertNotEmpty( $files, 'No workflow found in .github/workflows/.' );

		return array_map(
			static fn( string $file ): string => substr( str_replace( '\\', '/', $file ), strlen( KKCE_TEST_PLUGIN_DIR ) + 1 ),
			(array) $files
		);
	}

	/**
	 * Every `uses:` line names an action at a 40-character commit SHA, followed by `# v<version>`.
	 */
	public function test_every_action_is_pinned_to_a_commit_sha() {
		$checked = 0;

		foreach ( $this->workflows() as $workflow ) {
			foreach ( explode( "\n", $this->read_plugin_file( $workflow ) ) as $number => $line ) {
				if ( 1 !== preg_match( '/^\s*-?\s*uses:\s*(\S+)(.*)$/', $line, $match ) ) {
					continue;
				}
				++$checked;
				$where = $workflow . ':' . ( $number + 1 );

				$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[0-9a-f]{40}$/', $match[1], "{$where}: pin the action to a full commit SHA." );
				$this->assertMatchesRegularExpression( '/^\s+#\s+v?\d+(\.\d+){0,2}\s*$/', $match[2], "{$where}: add the exact tag of the commit as a comment, for example `# v5.1.0`." );
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No `uses:` line found: the test would check nothing.' );
	}
}
