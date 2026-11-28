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
	 * Translations come from translate.wordpress.org: there is no script that writes translation files.
	 */
	public function test_there_is_no_translation_script() {
		$scripts = json_decode( $this->read_plugin_file( 'composer.json' ), true )['scripts'];

		foreach ( array( 'i18n', 'make-pot', 'update-po', 'make-mo', 'make-json' ) as $name ) {
			$this->assertArrayNotHasKey( $name, $scripts );
		}
	}

	/**
	 * Every test can be run with Composer: the scripts of the Docker environments and of the end-to-end suite
	 * call the npm scripts, without the time limit of Composer (a first start or the whole suite last longer
	 * than five minutes), and pass on the arguments.
	 */
	public function test_docker_and_e2e_scripts_call_npm() {
		$composer = json_decode( $this->read_plugin_file( 'composer.json' ), true );
		$npm      = json_decode( $this->read_plugin_file( 'package.json' ), true )['scripts'];
		$expected = array(
			'test:integration'        => 'test:integration',
			'test:e2e'                => 'test:e2e',
			'env:start'               => 'env:start',
			'env:stop'                => 'env:stop',
			'env:destroy'             => 'env:destroy',
			'env:tests:start'         => 'env:tests:start',
			'env:tests:stop'          => 'env:tests:stop',
			'env:tests:destroy'       => 'env:tests:destroy',
			'test:e2e:headed'         => 'test:e2e:headed',
			'test:e2e:ui'             => 'test:e2e:ui',
			'test:e2e:report'         => 'test:e2e:report',
		);

		foreach ( $expected as $name => $target ) {
			$this->assertArrayHasKey( $target, $npm, "npm script {$target}" );
			$this->assertSame( array( 'Composer\Config::disableProcessTimeout', "npm run {$target} --" ), $composer['scripts'][ $name ], $name );
			$this->assertArrayHasKey( $name, $composer['scripts-descriptions'], "Description of {$name}" );
		}
	}

	/**
	 * One command stops, and one deletes, both Docker environments (end-to-end and integration).
	 */
	public function test_all_the_environments_can_be_stopped_and_deleted_at_once() {
		$composer = json_decode( $this->read_plugin_file( 'composer.json' ), true );

		$this->assertSame( array( '@env:stop', '@env:tests:stop' ), $composer['scripts']['env:stop:all'] );
		$this->assertSame( array( '@env:destroy', '@env:tests:destroy' ), $composer['scripts']['env:destroy:all'] );
		$this->assertArrayHasKey( 'env:stop:all', $composer['scripts-descriptions'] );
		$this->assertArrayHasKey( 'env:destroy:all', $composer['scripts-descriptions'] );
	}

	/**
	 * The integration suite runs only in Docker (decision of 2026-11-15): `test:all` is unit then integration,
	 * and the provisional name of the Docker script is gone.
	 */
	public function test_the_integration_suite_runs_in_docker() {
		$scripts = json_decode( $this->read_plugin_file( 'composer.json' ), true )['scripts'];

		$this->assertSame( array( '@test:unit', '@test:integration' ), $scripts['test:all'] );
		$this->assertArrayNotHasKey( 'test:integration:docker', $scripts );
	}

	/**
	 * Lifecycle commands, runners, fixtures and CI must target the same isolated environment for each suite.
	 */
	public function test_named_environments_are_used_consistently() {
		$scripts = json_decode( $this->read_plugin_file( 'package.json' ), true )['scripts'];
		$environments = array(
			'e2etest' => array( 'prefix' => 'env', 'port' => 8890, 'runner' => 'tests/bin/e2e.mjs', 'workflow' => '.github/workflows/functional.yml' ),
			'inttest' => array( 'prefix' => 'env:tests', 'port' => 8891, 'runner' => 'tests/bin/integration.mjs', 'workflow' => '.github/workflows/ci.yml' ),
		);

		foreach ( $environments as $variant => $environment ) {
			$config_file = '.wp-env.' . $variant . '.json';
			$config = json_decode( $this->read_plugin_file( $config_file ), true );
			$this->assertSame( $environment['port'], $config['port'], $variant );
			$this->assertFalse( $config['testsEnvironment'], $variant );
			$this->assertContains( '.', $config['plugins'], $variant );

			foreach ( array( 'start', 'stop', 'destroy' ) as $action ) {
				$this->assertStringContainsString( '--config=' . $config_file, $scripts[ $environment['prefix'] . ':' . $action ] );
			}
			$this->assertStringContainsString( '--config=' . $config_file, $this->read_plugin_file( $environment['runner'] ) );
			$this->assertStringContainsString( 'run: npm run ' . $environment['prefix'] . ':stop', $this->read_plugin_file( $environment['workflow'] ) );
		}

		$this->assertStringContainsString( '--config=.wp-env.e2etest.json', $this->read_plugin_file( 'tests/e2e/support/wp.js' ) );
	}
}
