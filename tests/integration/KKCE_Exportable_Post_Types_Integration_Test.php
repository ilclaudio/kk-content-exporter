<?php
/**
 * Integration tests of the post types a feed can export.
 *
 * @package KKCE
 */

use KKCE\Sources\Exportable_Post_Types;

/**
 * The exportable post types are the public ones, `attachment` excluded, sorted by label.
 */
class KKCE_Exportable_Post_Types_Integration_Test extends WP_UnitTestCase {

	/**
	 * Post types registered by a test.
	 *
	 * @var string[]
	 */
	private array $registered = array();

	/**
	 * Unregisters the post types of the test.
	 */
	public function tear_down() {
		foreach ( $this->registered as $post_type ) {
			unregister_post_type( $post_type );
		}

		parent::tear_down();
	}

	/**
	 * Registers a post type for the test.
	 *
	 * @param string               $name Post type key.
	 * @param array<string, mixed> $args Arguments of register_post_type().
	 * @return void
	 */
	private function register( string $name, array $args ): void {
		$this->registered[] = $name;
		register_post_type( $name, $args );
	}

	/**
	 * Public post types are exportable; attachments, feeds, revisions and other non-public types are not.
	 */
	public function test_selectable_post_types() {
		$this->register( 'kkce_hidden', array( 'public' => false, 'label' => 'Hidden' ) );

		$types = Exportable_Post_Types::selectable();

		$this->assertArrayHasKey( 'post', $types );
		$this->assertArrayHasKey( 'page', $types );
		$this->assertArrayNotHasKey( 'attachment', $types );
		$this->assertArrayNotHasKey( 'kkce_feed', $types );
		$this->assertArrayNotHasKey( 'revision', $types );
		$this->assertArrayNotHasKey( 'kkce_hidden', $types );
	}

	/**
	 * Keys give their label, sorted by label without regard to case and with numbers in natural order.
	 */
	public function test_selectable_post_types_are_sorted_by_label() {
		$this->register( 'kkce_type_b', array( 'public' => true, 'label' => 'type 10' ) );
		$this->register( 'kkce_type_a', array( 'public' => true, 'label' => 'Type 9' ) );

		$types = Exportable_Post_Types::selectable();

		$this->assertSame( 'Posts', $types['post'] );
		$this->assertSame( 'Pages', $types['page'] );

		$labels = array_values( $types );
		$sorted = $labels;
		usort( $sorted, 'strnatcasecmp' );
		$this->assertSame( $sorted, $labels );
		$this->assertLessThan(
			array_search( 'kkce_type_b', array_keys( $types ), true ),
			array_search( 'kkce_type_a', array_keys( $types ), true )
		);
	}
}
