<?php
/**
 * Integration tests of the export of one item with its mapping.
 *
 * @package KKCE
 */

use KKCE\Output\Item_Exporter;

/**
 * An item is exported with the values a visitor would see, and the current user is always restored.
 */
class KKCE_Item_Exporter_Integration_Test extends WP_UnitTestCase {

	/**
	 * Mapping used by the tests: title, a chain per post type, a fixed value, the author archive URL.
	 *
	 * @param array<int, array<string, mixed>> $extra Rows to add.
	 * @return array{version: int, rows: list<array<string, mixed>>}
	 */
	private function mapping( array $extra = array() ): array {
		return array(
			'version' => 1,
			'rows'    => array_values(
				array_merge(
					array(
						array(
							'key'     => 'id',
							'kind'    => 'field',
							'type'    => 'auto',
							'missing' => 'omit',
							'sources' => array( '*' => array( 'core.id' ) ),
						),
						array(
							'key'     => 'summary',
							'kind'    => 'field',
							'type'    => 'auto',
							'missing' => 'null',
							'sources' => array(
								'*'    => array( 'core.excerpt', 'core.title' ),
								'page' => array( 'core.title' ),
							),
						),
						array(
							'key'     => 'source',
							'kind'    => 'fixed',
							'type'    => 'auto',
							'missing' => 'omit',
							'values'  => array( '*' => 'site-a' ),
						),
					),
					$extra
				)
			),
		);
	}

	/**
	 * A post and a page are exported with their own chains.
	 */
	public function test_items_are_exported_with_the_chains_of_their_post_type() {
		$post = get_post(
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Post title',
					'post_excerpt' => '',
				)
			)
		);
		$page = get_post(
			self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Page title',
					'post_excerpt' => 'Ignored for pages',
				)
			)
		);

		$this->assertSame(
			array(
				'id'      => $post->ID,
				'summary' => 'Post title',
				'source'  => 'site-a',
			),
			Item_Exporter::export( $post, $this->mapping() )
		);
		$this->assertSame(
			array(
				'id'      => $page->ID,
				'summary' => 'Page title',
				'source'  => 'site-a',
			),
			Item_Exporter::export( $page, $this->mapping() )
		);
	}

	/**
	 * Values are computed as an anonymous visitor: a filter that depends on the current user gives what a
	 * visitor gets, also when an administrator exports; the user is restored afterwards.
	 */
	public function test_values_are_computed_as_an_anonymous_visitor() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post  = get_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
		$mark  = static fn( $url ) => $url . ( is_user_logged_in() ? '#logged-in' : '#visitor' );
		add_filter( 'post_link', $mark );

		$mapping = $this->mapping(
			array(
				array(
					'key'     => 'link',
					'kind'    => 'field',
					'type'    => 'auto',
					'missing' => 'omit',
					'sources' => array( '*' => array( 'core.permalink' ) ),
				),
			)
		);

		wp_set_current_user( $admin );
		$as_admin = Item_Exporter::export( $post, $mapping );
		$this->assertSame( $admin, get_current_user_id(), 'The administrator is restored.' );

		wp_set_current_user( 0 );
		$as_visitor = Item_Exporter::export( $post, $mapping );

		remove_filter( 'post_link', $mark );

		$this->assertStringEndsWith( '#visitor', $as_admin['link'] );
		$this->assertSame( $as_visitor, $as_admin );
	}

	/**
	 * The current user is restored even when the computation fails.
	 */
	public function test_the_user_is_restored_after_an_error() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post  = get_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
		$fail  = static function () {
			throw new RuntimeException( 'Failure inside a filter.' );
		};
		add_filter( 'post_link', $fail );
		wp_set_current_user( $admin );

		$mapping = $this->mapping(
			array(
				array(
					'key'     => 'link',
					'kind'    => 'field',
					'type'    => 'auto',
					'missing' => 'omit',
					'sources' => array( '*' => array( 'core.permalink' ) ),
				),
			)
		);

		try {
			Item_Exporter::export( $post, $mapping );
			$this->fail( 'The exception is not swallowed.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Failure inside a filter.', $e->getMessage() );
		} finally {
			remove_filter( 'post_link', $fail );
		}

		$this->assertSame( $admin, get_current_user_id() );
	}

	/**
	 * The author archive URL comes out only when the row confirms it; a mapping that was not validated
	 * cannot skip the confirmation.
	 */
	public function test_the_author_url_needs_its_confirmation_at_output() {
		$author = self::factory()->user->create( array( 'role' => 'author', 'user_nicename' => 'jane' ) );
		$post   = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $author ) ) );
		$row    = array(
			'key'     => 'author_page',
			'kind'    => 'field',
			'type'    => 'auto',
			'missing' => 'omit',
			'sources' => array( '*' => array( 'core.author.url' ) ),
		);

		$this->assertArrayNotHasKey( 'author_page', Item_Exporter::export( $post, $this->mapping( array( $row ) ) ) );

		$row['confirmed'] = array( 'core.author.url' );
		$this->assertSame( get_author_posts_url( $author ), Item_Exporter::export( $post, $this->mapping( array( $row ) ) )['author_page'] );
	}

	/**
	 * Sources of providers that do not exist yet, and unknown core fields, do not exist: the chain goes on.
	 */
	public function test_unknown_sources_do_not_exist() {
		$post = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'T' ) ) );
		$row  = array(
			'key'     => 'name',
			'kind'    => 'field',
			'type'    => 'auto',
			'missing' => 'omit',
			'sources' => array( '*' => array( 'meta.name', 'core.nothing', 'core.title' ) ),
		);

		$this->assertSame( 'T', Item_Exporter::export( $post, $this->mapping( array( $row ) ) )['name'] );
	}
}
