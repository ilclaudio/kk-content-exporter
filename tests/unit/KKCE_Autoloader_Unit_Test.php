<?php
/**
 * Unit tests of the plugin autoloader (pure logic, no WordPress).
 *
 * @package KKCE
 */

use KKCE\Autoloader;

/**
 * Class name to file name mapping.
 */
class KKCE_Autoloader_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Classes of the plugin namespace map to WordPress-style file names.
	 *
	 * @dataProvider data_class_names
	 *
	 * @param string $class_name Fully qualified class name.
	 * @param string $expected   Expected path relative to includes/.
	 */
	public function test_maps_plugin_classes_to_wordpress_file_names( $class_name, $expected ) {
		$this->assertSame( $expected, Autoloader::relative_path_for( $class_name ) );
	}

	/**
	 * Class names and the expected files.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function data_class_names() {
		return array(
			'root class'          => array( 'KKCE\\Plugin', 'class-plugin.php' ),
			'leading backslash'   => array( '\\KKCE\\Plugin', 'class-plugin.php' ),
			'underscored class'   => array( 'KKCE\\Feed_Repository', 'class-feed-repository.php' ),
			'sub-namespace'       => array( 'KKCE\\Admin\\Feed_Editor', 'admin/class-feed-editor.php' ),
			'nested sub-namespace' => array( 'KKCE\\Sources\\Acf_Field\\Simple_Value', 'sources/acf-field/class-simple-value.php' ),
		);
	}

	/**
	 * Classes outside the namespace, or with unsafe names, are ignored.
	 *
	 * @dataProvider data_foreign_class_names
	 *
	 * @param string $class_name Class name.
	 */
	public function test_ignores_classes_outside_the_plugin_namespace( $class_name ) {
		$this->assertSame( '', Autoloader::relative_path_for( $class_name ) );
		$this->assertSame( '', Autoloader::file_for( $class_name ) );
	}

	/**
	 * Class names the loader must not resolve.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function data_foreign_class_names() {
		return array(
			'global class'       => array( 'WP_Post' ),
			'other namespace'    => array( 'Brain\\Monkey\\Functions' ),
			'prefix look-alike'  => array( 'KKCEX\\Plugin' ),
			'namespace only'     => array( 'KKCE\\' ),
			'path traversal'     => array( 'KKCE\\..\\Plugin.' ),
			'parent folder part' => array( 'KKCE\\..\\Plugin' ),
			'deep traversal'     => array( 'KKCE\\..\\..\\..\\uploads\\Evil' ),
			'current folder part' => array( 'KKCE\\.\\Plugin' ),
			'empty part'         => array( 'KKCE\\Admin\\\\Feed_Editor' ),
		);
	}

	/**
	 * The resolved file of an existing class is the real file in includes/.
	 */
	public function test_resolves_an_existing_class_to_its_file() {
		$this->assertFileExists( Autoloader::file_for( 'KKCE\\Plugin' ) );
	}
}
