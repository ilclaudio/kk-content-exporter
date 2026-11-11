<?php
/**
 * Unit tests of the reading of a template (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Output\Template;

/**
 * A template is text with placeholders of the catalog sources, with fallbacks and literal braces.
 */
class KKCE_Template_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Text and placeholders become parts of one shape, in order; doubled braces are literal braces.
	 */
	public function test_text_and_placeholders_become_parts() {
		$this->assertSame(
			array(
				'parts' => array(
					array( 'text' => 'News: ', 'alternatives' => array() ),
					array( 'text' => '', 'alternatives' => array( array( 'source' => 'core.title' ) ) ),
					array( 'text' => ' – {at} ', 'alternatives' => array() ),
					array( 'text' => '', 'alternatives' => array( array( 'source' => 'meta.place' ), array( 'source' => 'meta.city' ), array( 'literal' => 'to be decided' ) ) ),
				),
				'error' => '',
			),
			Template::parse( 'News: {core.title} – {{at}} { meta.place | meta.city | "to be decided" }' )
		);
	}

	/**
	 * A template without placeholders is one text part; an empty template has no parts.
	 */
	public function test_text_only_and_empty_templates() {
		$this->assertSame( array( 'parts' => array( array( 'text' => 'Site A', 'alternatives' => array() ) ), 'error' => '' ), Template::parse( 'Site A' ) );
		$this->assertSame( array( 'parts' => array(), 'error' => '' ), Template::parse( '' ) );
	}

	/**
	 * A literal can be empty and keeps its inner spaces.
	 */
	public function test_literals() {
		$this->assertSame(
			array( array( 'source' => 'core.excerpt' ), array( 'literal' => '' ) ),
			Template::parse( '{core.excerpt|""}' )['parts'][0]['alternatives']
		);
		$this->assertSame( array( array( 'literal' => ' a  b ' ) ), Template::parse( '{" a  b "}' )['parts'][0]['alternatives'] );
	}

	/**
	 * Malformed templates give the format error.
	 *
	 * @dataProvider malformed_provider
	 *
	 * @param string $template Template.
	 */
	public function test_malformed_templates( string $template ) {
		$this->assertSame( array( 'parts' => array(), 'error' => 'format' ), Template::parse( $template ) );
	}

	/**
	 * Data for test_malformed_templates().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function malformed_provider(): array {
		return array(
			'unclosed placeholder'   => array( 'News {core.title' ),
			'closing brace alone'    => array( 'News } more' ),
			'nested brace'           => array( '{core.{title}}' ),
			'empty placeholder'      => array( 'News {}' ),
			'blank placeholder'      => array( 'News {  }' ),
			'empty alternative'      => array( '{core.title||core.slug}' ),
			'trailing pipe'          => array( '{core.title|}' ),
			'name without group'     => array( '{title}' ),
			'upper case group'       => array( '{Core.title}' ),
			'space inside a name'    => array( '{core.ti tle}' ),
			'unclosed literal'       => array( '{core.title|"n.d.}' ),
			'quote inside a literal' => array( '{"a"b"}' ),
			'pipe inside a literal'  => array( '{"a|b"}' ),
		);
	}

	/**
	 * The text is limited to 1,000 characters, counted as characters and not as bytes.
	 */
	public function test_the_length_is_limited_in_characters() {
		$this->assertSame( '', Template::parse( str_repeat( 'è', Template::MAX_LENGTH ) )['error'] );
		$this->assertSame( 'too_long', Template::parse( str_repeat( 'è', Template::MAX_LENGTH + 1 ) )['error'] );
		$this->assertSame( 'format', Template::parse( "\xC3" )['error'], 'Text that is not UTF-8 is refused.' );
		$this->assertSame( 'format', Template::parse( 5 )['error'], 'A template is a text.' );
	}

	/**
	 * At most 20 placeholders.
	 */
	public function test_the_number_of_placeholders_is_limited() {
		$this->assertSame( '', Template::parse( str_repeat( '{core.title}', Template::MAX_PLACEHOLDERS ) )['error'] );
		$this->assertSame( 'too_many', Template::parse( str_repeat( '{core.title}', Template::MAX_PLACEHOLDERS + 1 ) )['error'] );
	}

	/**
	 * The sources of a template, once each, in order of appearance.
	 */
	public function test_sources() {
		$this->assertSame(
			array( 'core.title', 'meta.place', 'meta.city' ),
			Template::sources( Template::parse( '{core.title} {meta.place|meta.city|"x"} {core.title}' )['parts'] )
		);
		$this->assertSame( array(), Template::sources( Template::parse( 'plain {{text}}' )['parts'] ) );
	}
}
