<?php
/**
 * Contract test: version and requirements are the same in every file that declares them.
 *
 * A failure means a release file was updated and another one forgotten: fix the data, not the test.
 *
 * @package KKCE
 */

/**
 * Release version and requirement consistency.
 */
class KKCE_Release_Version_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Extract a value with a regular expression and fail with a clear message.
	 *
	 * @param string $pattern       Regular expression with one capture group.
	 * @param string $relative_path File to search, relative to the plugin root.
	 * @return string
	 */
	private function extract( $pattern, $relative_path ) {
		$this->assertSame( 1, preg_match( $pattern, $this->read_plugin_file( $relative_path ), $matches ), "Value not found in {$relative_path} ({$pattern})" );
		return trim( $matches[1] );
	}

	/**
	 * The versions found in the release files.
	 *
	 * @return array<string, string> Version by source.
	 */
	private function versions() {
		return array(
			'VERSION.txt'                => trim( $this->read_plugin_file( 'VERSION.txt' ) ),
			'plugin header'              => $this->extract( '/^ \* Version:\s*(\S+)/m', 'kk-content-exporter.php' ),
			'KKCE_VERSION'               => $this->extract( "/define\( 'KKCE_VERSION', '([^']+)' \);/", 'kk-content-exporter.php' ),
			'readme.txt Stable tag'      => $this->extract( '/^Stable tag:\s*(\S+)/m', 'readme.txt' ),
			'CHANGELOG.md (first entry)' => $this->extract( '/^## \[(\d+\.\d+\.\d+)\]/m', 'CHANGELOG.md' ),
			'readme.txt changelog'       => $this->extract( '/^== Changelog ==\s*\n\s*= (\S+) =/m', 'readme.txt' ),
		);
	}

	/**
	 * Every release file declares the same version.
	 */
	public function test_version_is_the_same_in_every_release_file() {
		$versions = $this->versions();
		$expected = $versions['VERSION.txt'];
		foreach ( $versions as $source => $version ) {
			$this->assertSame( $expected, $version, "Version in {$source} differs from VERSION.txt ({$expected})" );
		}
	}

	/**
	 * The version is a plain MAJOR.MINOR.PATCH number.
	 */
	public function test_version_has_semantic_format() {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $this->versions()['VERSION.txt'] );
	}

	/**
	 * The minimum WordPress version is the same in the header, readme.txt and phpcs.xml.dist.
	 */
	public function test_minimum_wordpress_version_is_consistent() {
		$header = $this->extract( '/^ \* Requires at least:\s*(\S+)/m', 'kk-content-exporter.php' );

		$this->assertSame( $header, $this->extract( '/^Requires at least:\s*(\S+)/m', 'readme.txt' ), 'readme.txt' );
		$this->assertSame( $header, $this->extract( '/<config name="minimum_wp_version" value="([^"]+)"\/>/', 'phpcs.xml.dist' ), 'phpcs.xml.dist' );
	}

	/**
	 * The minimum PHP version is the same in the header, readme.txt, composer.json, PHPCS and PHPStan.
	 */
	public function test_minimum_php_version_is_consistent() {
		$header   = $this->extract( '/^ \* Requires PHP:\s*(\S+)/m', 'kk-content-exporter.php' );
		$composer = json_decode( $this->read_plugin_file( 'composer.json' ), true );

		$this->assertSame( $header, $this->extract( '/^Requires PHP:\s*(\S+)/m', 'readme.txt' ), 'readme.txt' );
		$this->assertSame( '>=' . $header, $composer['require']['php'], 'composer.json require.php' );
		$this->assertStringStartsWith( $header . '.', $composer['config']['platform']['php'], 'composer.json config.platform.php' );
		$this->assertSame( $header . '-', $this->extract( '/<config name="testVersion" value="([^"]+)"\/>/', 'phpcs.xml.dist' ), 'phpcs.xml.dist testVersion' );

		list( $major, $minor ) = array_map( 'intval', explode( '.', $header ) );
		$this->assertSame( (string) ( $major * 10000 + $minor * 100 ), $this->extract( '/^\s*phpVersion:\s*(\d+)/m', 'phpstan.neon.dist' ), 'phpstan.neon.dist phpVersion' );
	}

	/**
	 * The text domain is the plugin slug everywhere.
	 */
	public function test_text_domain_is_the_slug() {
		$this->assertSame( 'kk-content-exporter', $this->extract( '/^ \* Text Domain:\s*(\S+)/m', 'kk-content-exporter.php' ) );
		$this->assertSame( 'kk-content-exporter', $this->extract( '/<element value="(kk-content-exporter)"\/>/', 'phpcs.xml.dist' ) );
		$this->assertSame( 'kk-content-exporter', \KKCE\Plugin::TEXT_DOMAIN );
	}
}
