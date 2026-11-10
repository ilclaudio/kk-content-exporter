<?php
/**
 * Unit tests of the pure processing of the feed form (no WordPress, no mocks).
 *
 * @package KKCE
 */

use KKCE\Feed_Form;

/**
 * Turns the submitted values into the values to save and the errors to show.
 */
class KKCE_Feed_Form_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Submitted values that are all valid.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private function input( array $override = array() ): array {
		return array_merge(
			array(
				'slug'       => 'news',
				'post_types' => array( 'page' ),
				'access'     => 'authenticated',
				'active'     => true,
				'per_page'   => '20',
			),
			$override
		);
	}

	/**
	 * Context of the form.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private function context( array $override = array() ): array {
		return array_merge(
			array(
				'slug_is_taken'=> static fn( string $slug ): bool => 'taken' === $slug,
				'allowed_post_types' => array( 'post', 'page' ),
				'max_per_page'       => 100,
				'current'            => array(
					'slug'       => 'old-slug',
					'post_types' => array( 'post' ),
					'access'     => 'public',
					'active'     => false,
					'per_page'   => 50,
				),
			),
			$override
		);
	}

	/**
	 * Valid values are returned as they are, with no error.
	 */
	public function test_valid_input_gives_the_values_and_no_errors() {
		$result = Feed_Form::process( $this->input(), $this->context() );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array(
				'slug'       => 'news',
				'post_types' => array( 'page' ),
				'access'     => 'authenticated',
				'active'     => true,
				'per_page'   => 20,
			),
			$result['values']
		);
	}

	/**
	 * The slug is required: an empty slug (also made of spaces) is an error, never taken from the
	 * title, and keeps the current slug; the other fields are still saved.
	 *
	 * @dataProvider empty_slug_provider
	 *
	 * @param string $slug Submitted slug.
	 */
	public function test_empty_slug_is_an_error_and_keeps_the_current_slug( string $slug ) {
		$result = Feed_Form::process( $this->input( array( 'slug' => $slug ) ), $this->context() );

		$this->assertSame( 'empty', $result['errors']['slug'] );
		$this->assertSame( 'old-slug', $result['values']['slug'] );
		$this->assertSame( 20, $result['values']['per_page'], 'The other fields are still saved.' );
	}

	/**
	 * Data for test_empty_slug_is_an_error_and_keeps_the_current_slug().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function empty_slug_provider(): array {
		return array(
			'empty'  => array( '' ),
			'spaces' => array( '   ' ),
		);
	}

	/**
	 * Invalid, reserved and taken slugs keep the current slug and report the error code.
	 *
	 * @dataProvider bad_slug_provider
	 *
	 * @param string $slug     Submitted slug.
	 * @param string $expected Error code.
	 */
	public function test_bad_slugs_keep_the_current_slug( string $slug, string $expected ) {
		$result = Feed_Form::process( $this->input( array( 'slug' => $slug ) ), $this->context() );

		$this->assertSame( $expected, $result['errors']['slug'] );
		$this->assertSame( 'old-slug', $result['values']['slug'] );
		$this->assertSame( 20, $result['values']['per_page'], 'The other fields are still saved.' );
	}

	/**
	 * Data for test_bad_slugs_keep_the_current_slug().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function bad_slug_provider(): array {
		return array(
			'uppercase' => array( 'News', 'format' ),
			'reserved'  => array( 'feeds', 'reserved' ),
			'wordpress' => array( 'feed', 'reserved' ),
			'taken'     => array( 'taken', 'taken' ),
			'too long'  => array( str_repeat( 'a', 65 ), 'too_long' ),
		);
	}

	/**
	 * The slug of the feed itself is not "taken": the caller excludes it.
	 */
	public function test_the_check_is_asked_for_the_candidate_slug_only() {
		$asked   = array();
		$context = $this->context(
			array(
				'slug_is_taken' => static function ( string $slug ) use ( &$asked ): bool {
					$asked[] = $slug;
					return false;
				},
			)
		);

		Feed_Form::process( $this->input( array( 'slug' => 'fresh' ) ), $context );

		$this->assertSame( array( 'fresh' ), $asked );
	}

	/**
	 * Several allowed post types are saved, in the submitted order, and the feed can be active.
	 */
	public function test_several_post_types_are_saved_and_the_feed_can_be_active() {
		$result = Feed_Form::process( $this->input( array( 'post_types' => array( 'page', 'post' ) ) ), $this->context() );

		$this->assertSame( array( 'page', 'post' ), $result['values']['post_types'] );
		$this->assertArrayNotHasKey( 'post_types', $result['errors'] );
		$this->assertTrue( $result['values']['active'] );
	}

	/**
	 * A post type submitted twice is saved once, with no error.
	 */
	public function test_duplicate_post_types_are_saved_once() {
		$result = Feed_Form::process( $this->input( array( 'post_types' => array( 'page', 'post', 'page' ) ) ), $this->context() );

		$this->assertSame( array( 'page', 'post' ), $result['values']['post_types'] );
		$this->assertArrayNotHasKey( 'post_types', $result['errors'] );
	}

	/**
	 * A list with a post type outside the allow-list, or a value that is not a list, keeps the whole
	 * current list and reports the error; the other fields are still saved.
	 *
	 * @dataProvider bad_post_types_provider
	 *
	 * @param mixed $post_types Submitted post types.
	 */
	public function test_bad_post_types_keep_the_current_list( $post_types ) {
		$result = Feed_Form::process( $this->input( array( 'post_types' => $post_types ) ), $this->context() );

		$this->assertSame( 'not_allowed', $result['errors']['post_types'] );
		$this->assertSame( array( 'post' ), $result['values']['post_types'] );
		$this->assertSame( 20, $result['values']['per_page'], 'The other fields are still saved.' );
	}

	/**
	 * Data for test_bad_post_types_keep_the_current_list().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function bad_post_types_provider(): array {
		return array(
			'attachment'        => array( array( 'attachment' ) ),
			'feed type'         => array( array( 'kkce_feed' ) ),
			'unknown'           => array( array( 'nothing' ) ),
			'wrong case'        => array( array( 'Page' ) ),
			'one bad among good' => array( array( 'page', 'attachment' ) ),
			'empty value'       => array( array( 'page', '' ) ),
			'string not a list' => array( 'page' ),
		);
	}

	/**
	 * With a refused list, the feed stays active only if the current list is not empty.
	 */
	public function test_a_refused_list_with_an_empty_current_list_disables_the_feed() {
		$context                          = $this->context();
		$context['current']['post_types'] = array();

		$result = Feed_Form::process( $this->input( array( 'post_types' => array( 'attachment' ) ) ), $context );

		$this->assertSame( array(), $result['values']['post_types'] );
		$this->assertFalse( $result['values']['active'] );
		$this->assertSame( 'incomplete', $result['errors']['active'] );
	}

	/**
	 * At least one post type is required: an empty list is an error and keeps the current list; the
	 * other fields are still saved.
	 */
	public function test_no_post_type_is_an_error_and_keeps_the_current_list() {
		$result = Feed_Form::process( $this->input( array( 'post_types' => array(), 'per_page' => '30' ) ), $this->context() );

		$this->assertSame( 'empty', $result['errors']['post_types'] );
		$this->assertSame( array( 'post' ), $result['values']['post_types'] );
		$this->assertSame( 30, $result['values']['per_page'], 'The other fields are still saved.' );
	}

	/**
	 * A new feed saved without post types has none and cannot be active.
	 */
	public function test_a_new_feed_without_post_types_cannot_be_active() {
		$context                          = $this->context();
		$context['current']['post_types'] = array();

		$result = Feed_Form::process( $this->input( array( 'post_types' => array() ) ), $context );

		$this->assertSame( 'empty', $result['errors']['post_types'] );
		$this->assertSame( array(), $result['values']['post_types'] );
		$this->assertFalse( $result['values']['active'] );
		$this->assertSame( 'incomplete', $result['errors']['active'] );
	}

	/**
	 * A tampered access value never becomes public: the current one is kept.
	 */
	public function test_bad_access_keeps_the_current_one() {
		$context = $this->context();
		$context['current']['access'] = 'authenticated';

		$result = Feed_Form::process( $this->input( array( 'access' => 'everyone' ) ), $context );

		$this->assertSame( 'not_allowed', $result['errors']['access'] );
		$this->assertSame( 'authenticated', $result['values']['access'] );
	}

	/**
	 * A wrong items per page keeps the current number.
	 *
	 * @dataProvider bad_per_page_provider
	 *
	 * @param string $value    Submitted value.
	 * @param string $expected Error code.
	 */
	public function test_bad_per_page_keeps_the_current_number( string $value, string $expected ) {
		$result = Feed_Form::process( $this->input( array( 'per_page' => $value ) ), $this->context() );

		$this->assertSame( $expected, $result['errors']['per_page'] );
		$this->assertSame( 50, $result['values']['per_page'] );
	}

	/**
	 * Data for test_bad_per_page_keeps_the_current_number().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function bad_per_page_provider(): array {
		return array(
			'zero'     => array( '0', 'too_small' ),
			'negative' => array( '-3', 'too_small' ),
			'text'     => array( 'many', 'not_integer' ),
			'decimal'  => array( '2.5', 'not_integer' ),
			'empty'    => array( '', 'not_integer' ),
		);
	}

	/**
	 * Items per page above the global maximum keep the current number and report the error.
	 */
	public function test_items_per_page_above_the_global_maximum_is_refused() {
		$result = Feed_Form::process( $this->input( array( 'per_page' => '101' ) ), $this->context() );

		$this->assertSame( 'too_large', $result['errors']['per_page'] );
		$this->assertSame( 50, $result['values']['per_page'] );
	}

	/**
	 * The maximum itself is accepted, and a different global maximum changes the limit.
	 */
	public function test_items_per_page_equal_to_the_global_maximum_is_accepted() {
		$result = Feed_Form::process( $this->input( array( 'per_page' => '100' ) ), $this->context() );
		$this->assertArrayNotHasKey( 'per_page', $result['errors'] );
		$this->assertSame( 100, $result['values']['per_page'] );

		$result = Feed_Form::process( $this->input( array( 'per_page' => '101' ) ), $this->context( array( 'max_per_page' => 500 ) ) );
		$this->assertArrayNotHasKey( 'per_page', $result['errors'] );
		$this->assertSame( 101, $result['values']['per_page'] );
	}

	/**
	 * The feed is not activated while the slug is missing, and the error says why.
	 */
	public function test_a_feed_without_slug_cannot_be_active() {
		$context                    = $this->context();
		$context['current']['slug'] = '';

		$result = Feed_Form::process( $this->input( array( 'slug' => '' ) ), $context );

		$this->assertFalse( $result['values']['active'] );
		$this->assertSame( 'incomplete', $result['errors']['active'] );
	}

	/**
	 * An unchecked box disables the feed with no error.
	 */
	public function test_unchecked_active_disables_the_feed_without_error() {
		$result = Feed_Form::process( $this->input( array( 'active' => false ) ), $this->context() );

		$this->assertFalse( $result['values']['active'] );
		$this->assertArrayNotHasKey( 'active', $result['errors'] );
	}

	/**
	 * When the new slug is refused and the current one is used by another feed (for example after a
	 * restore from the trash), the feed cannot be active.
	 */
	public function test_a_taken_fallback_slug_cannot_be_active() {
		$context = $this->context(
			array( 'slug_is_taken' => static fn( string $slug ): bool => 'old-slug' === $slug )
		);

		$result = Feed_Form::process( $this->input( array( 'slug' => 'feeds' ) ), $context );

		$this->assertSame( 'reserved', $result['errors']['slug'] );
		$this->assertSame( 'old-slug', $result['values']['slug'], 'The stored slug is not renamed.' );
		$this->assertFalse( $result['values']['active'] );
		$this->assertSame( 'incomplete', $result['errors']['active'] );
	}

	/**
	 * A current slug that is reserved (for example generated by WordPress from the title) cannot make
	 * the feed active.
	 */
	public function test_a_reserved_fallback_slug_cannot_be_active() {
		$context                    = $this->context();
		$context['current']['slug'] = 'settings';

		$result = Feed_Form::process( $this->input( array( 'slug' => 'settings' ) ), $context );

		$this->assertSame( 'reserved', $result['errors']['slug'] );
		$this->assertFalse( $result['values']['active'] );
		$this->assertSame( 'incomplete', $result['errors']['active'] );
	}

	/**
	 * A malformed current slug cannot make the feed active.
	 */
	public function test_a_malformed_fallback_slug_cannot_be_active() {
		$context                    = $this->context();
		$context['current']['slug'] = 'old-slug-2__trashed';

		$result = Feed_Form::process( $this->input( array( 'slug' => 'Bad Slug' ) ), $context );

		$this->assertFalse( $result['values']['active'] );
		$this->assertSame( 'incomplete', $result['errors']['active'] );
	}

	/**
	 * A feed with a valid current slug can stay active even if the new slug is refused.
	 */
	public function test_active_is_kept_when_the_current_slug_and_source_are_valid() {
		$result = Feed_Form::process( $this->input( array( 'slug' => 'feeds' ) ), $this->context() );

		$this->assertSame( 'reserved', $result['errors']['slug'] );
		$this->assertTrue( $result['values']['active'] );
	}
}
