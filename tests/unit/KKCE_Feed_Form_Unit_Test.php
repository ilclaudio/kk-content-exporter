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
				'slug'      => 'news',
				'post_type' => 'page',
				'access'    => 'authenticated',
				'active'    => true,
				'per_page'  => '20',
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
				'suggested_slug'     => 'my-title',
				'slug_is_taken'      => static fn( string $slug ): bool => 'taken' === $slug,
				'allowed_post_types' => array( 'post', 'page' ),
				'current'            => array(
					'slug'      => 'old-slug',
					'post_type' => 'post',
					'access'    => 'public',
					'active'    => false,
					'per_page'  => 50,
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
				'slug'      => 'news',
				'post_type' => 'page',
				'access'    => 'authenticated',
				'active'    => true,
				'per_page'  => 20,
			),
			$result['values']
		);
	}

	/**
	 * An empty slug is replaced by the one suggested from the title.
	 */
	public function test_empty_slug_uses_the_suggested_slug() {
		$result = Feed_Form::process( $this->input( array( 'slug' => '  ' ) ), $this->context() );

		$this->assertSame( 'my-title', $result['values']['slug'] );
		$this->assertArrayNotHasKey( 'slug', $result['errors'] );
	}

	/**
	 * An empty slug with nothing to suggest is an error and keeps the current slug.
	 */
	public function test_empty_slug_without_suggestion_is_an_error() {
		$result = Feed_Form::process( $this->input( array( 'slug' => '' ) ), $this->context( array( 'suggested_slug' => '' ) ) );

		$this->assertSame( 'empty', $result['errors']['slug'] );
		$this->assertSame( 'old-slug', $result['values']['slug'] );
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

		Feed_Form::process( $this->input( array( 'slug' => '' ) ), $context );

		$this->assertSame( array( 'my-title' ), $asked );
	}

	/**
	 * A post type outside the allow-list keeps the current one and reports the error.
	 *
	 * @dataProvider bad_post_type_provider
	 *
	 * @param string $post_type Submitted post type.
	 */
	public function test_bad_post_type_keeps_the_current_one( string $post_type ) {
		$result = Feed_Form::process( $this->input( array( 'post_type' => $post_type ) ), $this->context() );

		$this->assertSame( 'not_allowed', $result['errors']['post_type'] );
		$this->assertSame( 'post', $result['values']['post_type'] );
	}

	/**
	 * Data for test_bad_post_type_keeps_the_current_one().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function bad_post_type_provider(): array {
		return array(
			'attachment'  => array( 'attachment' ),
			'feed type'   => array( 'kkce_feed' ),
			'unknown'     => array( 'nothing' ),
			'wrong case'  => array( 'Page' ),
		);
	}

	/**
	 * An empty post type means "not set": it is saved empty, with no error, and the feed cannot be active.
	 */
	public function test_empty_post_type_clears_the_source_and_disables_the_feed() {
		$result = Feed_Form::process( $this->input( array( 'post_type' => '' ) ), $this->context() );

		$this->assertSame( '', $result['values']['post_type'] );
		$this->assertArrayNotHasKey( 'post_type', $result['errors'] );
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
	 * The feed is not activated while the slug is missing, and the error says why.
	 */
	public function test_a_feed_without_slug_cannot_be_active() {
		$context                    = $this->context( array( 'suggested_slug' => '' ) );
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
	 * A feed with a valid current slug can stay active even if the new slug is refused.
	 */
	public function test_active_is_kept_when_the_current_slug_and_source_are_valid() {
		$result = Feed_Form::process( $this->input( array( 'slug' => 'feeds' ) ), $this->context() );

		$this->assertSame( 'reserved', $result['errors']['slug'] );
		$this->assertTrue( $result['values']['active'] );
	}
}
