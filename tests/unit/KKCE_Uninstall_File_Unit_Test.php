<?php
/**
 * Contract test of uninstall.php.
 *
 * @package KKCE
 */

/**
 * The uninstall file can run only from the uninstall screen of WordPress.
 */
class KKCE_Uninstall_File_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * The first statement exits when WordPress is not uninstalling the plugin, before anything runs.
	 */
	public function test_the_file_exits_unless_wordpress_is_uninstalling_the_plugin() {
		$code  = $this->read_plugin_file( 'uninstall.php' );
		$guard = strpos( $code, "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;" );

		$this->assertNotFalse( $guard, 'The WP_UNINSTALL_PLUGIN guard is missing.' );
		$this->assertStringNotContainsString( 'require', substr( $code, 0, (int) $guard ), 'Nothing is loaded before the guard.' );
		$this->assertStringNotContainsString( '::run', substr( $code, 0, (int) $guard ), 'Nothing runs before the guard.' );
		$this->assertStringContainsString( 'KKCE\Uninstaller::run();', substr( $code, (int) $guard ) );
	}
}
