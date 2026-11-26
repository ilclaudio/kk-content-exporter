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
	 * Parses configuration with the YAML reader already supplied by the WP-CLI development dependency.
	 *
	 * @param string $path Repository-relative YAML file.
	 * @return array<string, mixed>
	 */
	private function configuration( string $path ): array {
		require_once KKCE_TEST_PLUGIN_DIR . '/vendor/wp-cli/mustangostang-spyc/Spyc.php';

		return Spyc::YAMLLoadString( $this->read_plugin_file( $path ) );
	}

	/**
	 * Every job is bounded and every checkout leaves no authentication token available to later steps.
	 */
	public function test_workflows_use_read_only_permissions_bounded_jobs_and_uncredentialed_checkouts() {
		foreach ( $this->workflows() as $workflow ) {
			$config = $this->configuration( $workflow );
			$this->assertSame( array( 'contents' => 'read' ), $config['permissions'] );
			foreach ( $config['jobs'] as $name => $job ) {
				$this->assertIsInt( $job['timeout-minutes'] ?? null, $workflow . ':' . $name );
				$this->assertGreaterThan( 0, $job['timeout-minutes'] );
				foreach ( $job['steps'] as $step ) {
					if ( str_starts_with( $step['uses'] ?? '', 'actions/checkout@' ) ) {
						$this->assertFalse( $step['with']['persist-credentials'] ?? null, $workflow . ':' . $name );
					}
				}
			}
		}
	}

	/**
	 * CI remains automatic on the release branches and can be restarted manually; E2E remains manual.
	 */
	public function test_ci_preserves_its_triggers_and_cancels_superseded_runs() {
		$config = $this->configuration( '.github/workflows/ci.yml' );
		$this->assertArrayHasKey( 'workflow_dispatch', $config['on'] );
		foreach ( array( 'push', 'pull_request' ) as $event ) {
			$branches = $config['on'][ $event ]['branches'];
			sort( $branches );
			$this->assertSame( array( 'dev', 'main' ), $branches );
		}
		$this->assertSame( 'ci-${{ github.workflow }}-${{ github.ref }}', $config['concurrency']['group'] );
		$this->assertTrue( $config['concurrency']['cancel-in-progress'] );
		$this->assertSame( array( '8.3', '8.4' ), $config['jobs']['check']['strategy']['matrix']['php'] );
		$e2e = $this->configuration( '.github/workflows/functional.yml' );
		$this->assertSame( array( 'workflow_dispatch' ), array_keys( $e2e['on'] ) );
	}

	/**
	 * Composer validates its manifest/lock before installation, and workflow validation uses an immutable image.
	 */
	public function test_ci_validates_composer_and_its_own_workflows() {
		$config = $this->configuration( '.github/workflows/ci.yml' );
		$commands = array_column( $config['jobs']['check']['steps'], 'run' );
		$validate = array_search( 'composer validate --no-check-publish', $commands, true );
		$install = array_search( 'composer install --no-interaction --no-progress --prefer-dist', $commands, true );
		$this->assertNotFalse( $validate );
		$this->assertNotFalse( $install );
		$this->assertLessThan( $install, $validate, 'Validate before Composer installs dependencies.' );
		$lint = implode( "\n", array_column( $config['jobs']['actionlint']['steps'], 'run' ) );
		$this->assertMatchesRegularExpression( '#rhysd/actionlint:[0-9.]+@sha256:[a-f0-9]{64}(?:\s|$)#', $lint );
	}

	/**
	 * Monthly dependency PRs target dev, stay bounded and group only compatible Composer/npm updates.
	 */
	public function test_dependabot_updates_the_three_ecosystems_without_grouping_majors() {
		$config = $this->configuration( '.github/dependabot.yml' );
		$this->assertSame( 2, $config['version'] );
		$ecosystems = array_column( $config['updates'], 'package-ecosystem' );
		sort( $ecosystems );
		$this->assertSame( array( 'composer', 'github-actions', 'npm' ), $ecosystems );
		foreach ( $config['updates'] as $update ) {
			$this->assertSame( '/', $update['directory'] );
			$this->assertSame( 'dev', $update['target-branch'] );
			$this->assertSame( 'monthly', $update['schedule']['interval'] );
			$this->assertSame( 3, $update['open-pull-requests-limit'] );
			if ( 'github-actions' !== $update['package-ecosystem'] ) {
				$this->assertCount( 1, $update['groups'] );
				foreach ( $update['groups'] as $group ) {
					$types = $group['update-types'];
					sort( $types );
					$this->assertSame( array( 'minor', 'patch' ), $types );
				}
			}
		}
	}

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
