<?php
/**
 * Contract test: the integration suite has no local path left (it runs only in Docker since 2026-11-15).
 *
 * @package KKCE
 */

/**
 * The configuration and the bootstrap of the integration suite do not fall back on a local
 * wordpress-develop checkout or on a local credentials file.
 */
class KKCE_Integration_Config_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * No default of the local path is left in the files of the suite, and the sample of the local
	 * credentials file is gone.
	 */
	public function test_no_local_path_is_left() {
		foreach ( array( 'tests/bootstrap-integration.php', 'tests/integration/wp-tests-config.php', 'tests/bin/integration.mjs' ) as $file ) {
			$content = $this->read_plugin_file( $file );
			$this->assertStringNotContainsString( 'WordpressDEV', $content, $file );
			$this->assertStringNotContainsString( 'wp-tests-config.local', $content, $file );
		}
		$this->assertFileDoesNotExist( KKCE_TEST_PLUGIN_DIR . '/tests/integration/wp-tests-config.local-sample.php' );
	}

	/**
	 * The test configuration keeps the dedicated database and reads its connection from the environment,
	 * with the defaults of the wp-env test environment.
	 */
	public function test_the_configuration_uses_the_wp_env_defaults() {
		$content = $this->read_plugin_file( 'tests/integration/wp-tests-config.php' );

		$this->assertStringContainsString( "define( 'KKCE_TEST_DB_NAME', 'kkce_wordpress_test' );", $content );
		foreach ( array( "'KKCE_TEST_DB_HOST', 'mysql'", "'KKCE_TEST_DB_USER', 'root'", "'KKCE_TEST_DB_PASSWORD', 'password'", "'KKCE_TEST_ABSPATH', '/var/www/html/'" ) as $default ) {
			$this->assertStringContainsString( $default, $content );
		}
	}
}
