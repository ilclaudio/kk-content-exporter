<?php
/**
 * Contract test: local, private and generated files are ignored by Git.
 *
 * @package KKCE
 */

/**
 * Required entries of .gitignore.
 */
class KKCE_Git_Ignore_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Patterns that must appear as lines of .gitignore, with the reason.
	 *
	 * @var array<string, string>
	 */
	const REQUIRED_PATTERNS = array(
		'tests/integration/wp-tests-config.local.php' => 'local database credentials',
		'tests/routes/routes.config.json'             => 'Application Passwords of the manual route checks',
		'/build/'                                      => 'generated release folder, ZIP and reports',
		'/vendor/'                                     => 'Composer dependencies',
		'.phpunit.cache/'                              => 'tool caches',
		'DEV'                                          => 'private working material',
		'AGENTS/ISSUES_TODO.md'                        => 'private backlog',
		'AGENTS/ISSUES_RESOLVED.md'                    => 'private archive',
		'AGENTS/CODE_REVIEW_PROGRESS.md'               => 'local review state',
		'node_modules/'                                => 'npm dependencies of the test tools',
		'/playwright-report/'                          => 'E2E report',
		'/test-results/'                               => 'E2E traces and screenshots of failed tests',
		'tests/e2e/.auth/'                             => 'session of the E2E administrator',
		'.wp-env.override.json'                        => 'local overrides of the wp-env environments',
		'.wp-env.tests.override.json'                  => 'local overrides of the wp-env test environment',
		'.wp-env.e2etest.override.json'                => 'local overrides of the named E2E environment',
		'.wp-env.inttest.override.json'                => 'local overrides of the named integration environment',
	);

	/**
	 * Every required pattern is present in .gitignore.
	 */
	public function test_required_patterns_are_ignored() {
		$lines = array_map( 'trim', explode( "\n", $this->read_plugin_file( '.gitignore' ) ) );
		foreach ( self::REQUIRED_PATTERNS as $pattern => $reason ) {
			$this->assertContains( $pattern, $lines, ".gitignore must contain {$pattern} ({$reason})" );
		}
	}

	/**
	 * composer.lock is versioned, so every clone and CI use the same tool versions.
	 */
	public function test_composer_lock_is_versioned() {
		$lines = array_map( 'trim', explode( "\n", $this->read_plugin_file( '.gitignore' ) ) );
		$this->assertNotContains( 'composer.lock', $lines );
		$this->assertNotContains( '/composer.lock', $lines );
	}
}
