<?php
/**
 * Unit tests of the release helpers (bin/release-functions.php) and of the real .distignore.
 *
 * @package KKCE
 */

require_once KKCE_TEST_PLUGIN_DIR . '/bin/release-functions.php';

/**
 * Package selection and verification.
 */
class KKCE_Release_Build_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Patterns of the real .distignore.
	 *
	 * @return list<string>
	 */
	private function distignore() {
		return kkce_release_parse_distignore( $this->read_plugin_file( '.distignore' ) );
	}

	/**
	 * Comments and blank lines are ignored.
	 */
	public function test_parse_skips_comments_and_blank_lines() {
		$this->assertSame( array( '/tests', '*.log' ), kkce_release_parse_distignore( "# comment\n\n/tests\r\n  *.log  \n" ) );
	}

	/**
	 * Anchored patterns exclude a root path and its content only; unanchored ones match any segment.
	 */
	public function test_pattern_semantics() {
		$patterns = array( '/tests', '*.log' );

		$this->assertTrue( kkce_release_is_excluded( 'tests', $patterns ) );
		$this->assertTrue( kkce_release_is_excluded( 'tests/unit/A_Test.php', $patterns ) );
		$this->assertFalse( kkce_release_is_excluded( 'includes/tests-helper.php', $patterns ) );
		$this->assertFalse( kkce_release_is_excluded( 'includes/tests/file.php', $patterns ) );
		$this->assertTrue( kkce_release_is_excluded( 'includes/debug.log', $patterns ) );
		$this->assertTrue( kkce_release_is_excluded( 'includes\\debug.log', $patterns ) );
	}

	/**
	 * The real .distignore leaves out the development material.
	 *
	 * @dataProvider data_development_paths
	 *
	 * @param string $path Path relative to the plugin root.
	 */
	public function test_distignore_excludes_development_files( $path ) {
		$this->assertTrue( kkce_release_is_excluded( $path, $this->distignore() ), "{$path} would be shipped" );
	}

	/**
	 * Development paths.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function data_development_paths() {
		$paths = array( '.git/config', '.githooks/pre-commit', '.gitignore', '.distignore', '.editorconfig', 'AGENTS/PROJECT.md', 'AGENTS.md', 'CLAUDE.md', 'DEV/TODO/README.md', 'bin/build-zip.php', 'build/x.zip', 'tests/bootstrap-unit.php', 'tests/integration/wp-tests-config.local.php', 'vendor/autoload.php', 'composer.json', 'composer.lock', 'phpcs.xml.dist', 'phpstan.neon.dist', 'phpunit.xml', 'phpunit-integration.xml', 'README.md', 'VERSION.txt', 'languages/kk-content-exporter.pot~', '.phpunit.cache/x', '.env', '.idea/workspace.xml', 'includes/desktop.ini', 'includes/class-plugin.php~' );
		return array_combine( $paths, array_map( static fn( $path ) => array( $path ), $paths ) );
	}

	/**
	 * The real .distignore keeps the runtime files.
	 *
	 * @dataProvider data_runtime_paths
	 *
	 * @param string $path Path relative to the plugin root.
	 */
	public function test_distignore_keeps_runtime_files( $path ) {
		$this->assertFalse( kkce_release_is_excluded( $path, $this->distignore() ), "{$path} would be left out" );
	}

	/**
	 * Runtime paths.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function data_runtime_paths() {
		$paths = array( 'kk-content-exporter.php', 'uninstall.php', 'includes/class-plugin.php', 'includes/admin/class-feed-editor.php', 'assets/js/feed-editor.js', 'assets/js/feed-mapping.js', 'assets/css/feed-mapping.css', 'languages/kk-content-exporter-it_IT.mo', 'languages/kk-content-exporter.pot', 'readme.txt', 'LICENSE', 'CHANGELOG.md' );
		return array_combine( $paths, array_map( static fn( $path ) => array( $path ), $paths ) );
	}

	/**
	 * The safety net reports development files even without .distignore.
	 */
	public function test_forbidden_files_are_reported() {
		$files = array( 'kk-content-exporter.php', 'tests/a.php', '.githooks/pre-commit', 'composer.json', 'AGENTS.md', 'includes/wp-tests-config.local.php', 'includes/class-plugin.php', 'vendor/autoload.php' );

		$this->assertSame(
			array( 'tests/a.php', '.githooks/pre-commit', 'composer.json', 'AGENTS.md', 'includes/wp-tests-config.local.php', 'vendor/autoload.php' ),
			kkce_release_forbidden_files( $files )
		);
	}

	/**
	 * Missing required files are reported, a compiled translation included.
	 */
	public function test_missing_required_files_are_reported() {
		$complete = array( 'kk-content-exporter.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'includes/class-plugin.php', 'includes/class-autoloader.php', 'assets/js/feed-editor.js', 'assets/js/feed-mapping.js', 'assets/css/feed-mapping.css', 'languages/kk-content-exporter-it_IT.mo' );

		$this->assertSame( array(), kkce_release_missing_files( $complete ) );
		$without_license_and_mo = array_values( array_diff( $complete, array( 'LICENSE', 'languages/kk-content-exporter-it_IT.mo' ) ) );
		$this->assertSame( array( 'LICENSE', 'languages/kk-content-exporter-<locale>.mo' ), kkce_release_missing_files( $without_license_and_mo ) );
		$without_script = array_values( array_diff( $complete, array( 'assets/js/feed-editor.js' ) ) );
		$this->assertSame( array( 'assets/js/feed-editor.js' ), kkce_release_missing_files( $without_script ) );
		$without_mapping_assets = array_values( array_diff( $complete, array( 'assets/js/feed-mapping.js', 'assets/css/feed-mapping.css' ) ) );
		$this->assertSame( array( 'assets/js/feed-mapping.js', 'assets/css/feed-mapping.css' ), kkce_release_missing_files( $without_mapping_assets ) );
	}

	/**
	 * Different or malformed versions do not agree.
	 */
	public function test_versions_disagree() {
		$this->assertFalse( kkce_release_versions_agree( array( 'a' => '1.0.0', 'b' => '1.0.1' ) ) );
		$this->assertFalse( kkce_release_versions_agree( array( 'a' => '1.0', 'b' => '1.0' ) ) );
		$this->assertFalse( kkce_release_versions_agree( array( 'a' => '', 'b' => '' ) ) );
	}

	/**
	 * A tag listed by `svn list <url>/tags` is detected, without partial or file matches.
	 *
	 * Regression: svn:prepare checked only the local tags/ folder, which the working copy keeps at depth
	 * "empty", so a tag that existed only on the server was not detected.
	 */
	public function test_svn_tag_exists_in_the_server_listing() {
		$listing = array( '0.9.0/', "1.0.0/\r", '  1.0.1/  ', '1.0.10/', 'README.txt' );

		$this->assertTrue( kkce_release_svn_tag_exists( $listing, '1.0.0' ) );
		$this->assertTrue( kkce_release_svn_tag_exists( $listing, '1.0.1' ) );
		$this->assertFalse( kkce_release_svn_tag_exists( $listing, '1.0.2' ) );
		$this->assertFalse( kkce_release_svn_tag_exists( $listing, '1.0' ) );
		$this->assertFalse( kkce_release_svn_tag_exists( array( '1.0.0' ), '1.0.0' ), 'a file is not a tag' );
		$this->assertFalse( kkce_release_svn_tag_exists( array(), '1.0.0' ), 'empty tags/ on the first release' );
	}
}
