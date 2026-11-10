<?php
/**
 * Unit tests of the list of the core sources (translations stubbed by Brain Monkey).
 *
 * @package KKCE
 */

use KKCE\Sources\Core_Provider;
use KKCE\Sources\Source;

/**
 * The core fields of WordPress, common to every post type.
 */
class KKCE_Core_Provider_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * The core sources, with their declared types, in a fixed order.
	 */
	public function test_the_core_sources_and_their_types() {
		$types = array();
		foreach ( Core_Provider::sources() as $source ) {
			$types[ $source->id ] = $source->type;
		}

		$this->assertSame(
			array(
				'core.id'          => 'number',
				'core.slug'        => 'string',
				'core.title'       => 'string',
				'core.content'     => 'html',
				'core.excerpt'     => 'html',
				'core.permalink'   => 'url',
				'core.shortlink'   => 'url',
				'core.date'        => 'datetime',
				'core.modified'    => 'datetime',
				'core.author.id'   => 'number',
				'core.author.name' => 'string',
				'core.author.url'  => 'url',
				'core.type'        => 'string',
				'core.type_label'  => 'string',
			),
			$types
		);
	}

	/**
	 * Every core source is common, has a label, and only content and excerpt have options.
	 */
	public function test_the_core_sources_are_common_with_labels_and_options() {
		foreach ( Core_Provider::sources() as $source ) {
			$this->assertTrue( $source->is_common(), $source->id );
			$this->assertNotSame( '', $source->label, $source->id );
			$expected = in_array( $source->id, array( 'core.content', 'core.excerpt' ), true ) ? array( 'shortcodes', 'raw' ) : array();
			$this->assertSame( $expected, $source->options, $source->id );
		}
	}

	/**
	 * Only the author archive URL needs a confirmation; no core source exposes the e-mail or the login.
	 */
	public function test_only_the_author_url_needs_a_confirmation() {
		$confirm = array_values(
			array_map(
				static fn( Source $source ): string => $source->id,
				array_filter( Core_Provider::sources(), static fn( Source $source ): bool => $source->needs_confirmation() )
			)
		);

		$this->assertSame( array( 'core.author.url' ), $confirm );
		foreach ( Core_Provider::sources() as $source ) {
			$this->assertStringNotContainsString( 'email', $source->id );
			$this->assertStringNotContainsString( 'login', $source->id );
		}
	}
}
