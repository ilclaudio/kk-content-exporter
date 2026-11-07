<?php
/**
 * Contract test: PHPStan analyzes every first-party PHP file, at the agreed level, without a baseline.
 *
 * @package KKCE
 */

/**
 * Coverage of phpstan.neon.dist.
 */
class KKCE_PHPStan_Config_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Minimum PHPStan level of the project (AGENTS/CODING_STANDARDS.md).
	 */
	const MINIMUM_LEVEL = 6;

	/**
	 * Top-level folders that hold no first-party runtime or tooling PHP to analyze.
	 *
	 * @var string[]
	 */
	const NOT_ANALYZED = array( '.git', '.githooks', '.phpunit.cache', 'AGENTS', 'DEV', 'languages', 'node_modules', 'tests', 'vendor', 'build' );

	/**
	 * Entries of the `paths:` list of phpstan.neon.dist.
	 *
	 * @return string[]
	 */
	private function analyzed_paths() {
		$neon = $this->read_plugin_file( 'phpstan.neon.dist' );
		$this->assertSame( 1, preg_match( '/^  paths:\n((?:    - .+\n)+)/m', $neon, $matches ), 'paths: not found in phpstan.neon.dist' );
		preg_match_all( '/^    - (.+)$/m', $matches[1], $entries );
		return array_map( 'trim', $entries[1] );
	}

	/**
	 * The level is not lower than the agreed minimum.
	 */
	public function test_level_is_at_least_the_agreed_minimum() {
		$this->assertSame( 1, preg_match( '/^  level:\s*(\d+)\s*$/m', $this->read_plugin_file( 'phpstan.neon.dist' ), $matches ), 'level not found' );
		$this->assertGreaterThanOrEqual( self::MINIMUM_LEVEL, (int) $matches[1], 'The PHPStan level was lowered below the project minimum.' );
	}

	/**
	 * The PHPStan badge of README.md shows the configured level.
	 */
	public function test_readme_badge_shows_the_configured_level() {
		preg_match( '/^  level:\s*(\d+)\s*$/m', $this->read_plugin_file( 'phpstan.neon.dist' ), $matches );
		$this->assertStringContainsString( "badge/PHPStan-level%20{$matches[1]}-", $this->read_plugin_file( 'README.md' ), 'Update the PHPStan badge of README.md to the level of phpstan.neon.dist.' );
	}

	/**
	 * No baseline and no blanket error suppression.
	 */
	public function test_there_is_no_baseline_or_ignore_list() {
		$neon = $this->read_plugin_file( 'phpstan.neon.dist' );

		$this->assertStringNotContainsString( 'baseline', strtolower( preg_replace( '/^#.*$/m', '', $neon ) ) );
		$this->assertStringNotContainsString( 'ignoreErrors', $neon );
		$this->assertFileDoesNotExist( KKCE_TEST_PLUGIN_DIR . '/phpstan-baseline.neon' );
	}

	/**
	 * Every top-level PHP file and every top-level folder with PHP files is analyzed.
	 */
	public function test_every_first_party_php_location_is_analyzed() {
		$paths = $this->analyzed_paths();

		foreach ( new DirectoryIterator( KKCE_TEST_PLUGIN_DIR ) as $entry ) {
			$name = $entry->getFilename();
			if ( $entry->isDot() || in_array( $name, self::NOT_ANALYZED, true ) || str_starts_with( $name, '.' ) && $entry->isDir() ) {
				continue;
			}
			if ( $entry->isFile() && 'php' === $entry->getExtension() ) {
				$this->assertContains( $name, $paths, "{$name} is not analyzed by PHPStan" );
			}
			if ( $entry->isDir() && ! $entry->isLink() && $this->contains_php( $entry->getPathname() ) ) {
				$this->assertContains( $name, $paths, "{$name}/ contains PHP files but is not analyzed by PHPStan" );
			}
		}
	}

	/**
	 * Whether a folder contains PHP files.
	 *
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	private function contains_php( $dir ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				return true;
			}
		}
		return false;
	}
}
