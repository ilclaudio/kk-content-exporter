<?php
/**
 * Contract test: the Composer scripts work on a fresh clone.
 *
 * @package KKCE
 */

/**
 * Consistency between composer.json scripts and the tool configuration files.
 */
class KKCE_Composer_Scripts_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * The PHPCS scripts create the cache directory set in phpcs.xml.dist before running.
	 *
	 * PHPCS refuses a cache file in a missing directory, and on a fresh clone the directory
	 * does not exist until PHPUnit creates it (composer run check runs phpcs first).
	 */
	public function test_phpcs_scripts_create_the_cache_directory() {
		$this->assertMatchesRegularExpression( '/<arg name="cache" value="([^"]+)"/', $this->read_plugin_file( 'phpcs.xml.dist' ) );
		preg_match( '/<arg name="cache" value="([^"]+)"/', $this->read_plugin_file( 'phpcs.xml.dist' ), $match );
		$directory = dirname( $match[1] );

		$scripts = json_decode( $this->read_plugin_file( 'composer.json' ), true )['scripts'];
		foreach ( array( 'phpcs', 'phpcbf' ) as $name ) {
			$this->assertIsArray( $scripts[ $name ], "The {$name} script must be a list that creates {$directory} first" );
			$this->assertStringContainsString( "mkdir('{$directory}')", $scripts[ $name ][0], "The {$name} script must create {$directory} first" );
			$this->assertSame( $name, end( $scripts[ $name ] ), "The {$name} script must end with {$name}" );
		}
	}

	/**
	 * The translation script also writes the JSON translations of the admin scripts, after the `.mo` (see AGENTS/I18N.md).
	 */
	public function test_i18n_script_writes_the_javascript_translations() {
		$scripts = json_decode( $this->read_plugin_file( 'composer.json' ), true )['scripts'];

		$this->assertSame( array( '@make-pot', '@update-po', '@make-mo', '@make-json' ), $scripts['i18n'] );
		$this->assertSame( 'wp i18n make-json languages', $scripts['make-json'] );
	}
}
