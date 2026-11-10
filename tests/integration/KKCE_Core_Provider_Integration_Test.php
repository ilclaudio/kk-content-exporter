<?php
/**
 * Integration tests of the values of the core sources, read from real content.
 *
 * @package KKCE
 */

use KKCE\Sources\Core_Provider;

/**
 * Values of the core fields of posts and pages: GMT dates, public author attributes only.
 */
class KKCE_Core_Provider_Integration_Test extends WP_UnitTestCase {

	/**
	 * Author of the content, with an e-mail and a login that must never be exported.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * Creates the author.
	 */
	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );
		$this->author = self::factory()->user->create(
			array(
				'role'          => 'author',
				'user_login'    => 'jdoe',
				'user_email'    => 'jdoe@example.org',
				'display_name'  => 'Jane Doe',
				'user_nicename' => 'jane-doe',
			)
		);
	}

	/**
	 * A published post with every core field filled.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Post
	 */
	private function content( string $post_type = 'post' ): WP_Post {
		$id = self::factory()->post->create(
			array(
				'post_type'     => $post_type,
				'post_status'   => 'publish',
				'post_title'    => 'Concert "Christmas" & more',
				'post_name'     => 'christmas-concert',
				'post_content'  => '<!-- wp:paragraph --><p>Hello [gallery]</p><!-- /wp:paragraph -->',
				'post_excerpt'  => 'A short summary',
				'post_author'   => $this->author,
				'post_date'     => '2026-07-01 10:30:00',
				'post_date_gmt' => '2026-07-01 08:30:00',
			)
		);

		return get_post( $id );
	}

	/**
	 * Values of every core source of a post.
	 */
	public function test_values_of_a_post() {
		$post = $this->content();

		$this->assertSame( $post->ID, Core_Provider::value( $post, 'core.id' ) );
		$this->assertSame( 'christmas-concert', Core_Provider::value( $post, 'core.slug' ) );
		$this->assertSame( $post->post_title, Core_Provider::value( $post, 'core.title' ), 'The stored title, without the filters of the theme.' );
		$this->assertStringContainsString( 'Concert', (string) Core_Provider::value( $post, 'core.title' ) );
		$this->assertSame( $post->post_content, Core_Provider::value( $post, 'core.content', array( 'raw' => true ) ), 'The raw variant is the stored content.' );
		$this->assertMatchesRegularExpression( '#<p[^>]*>Hello#', (string) Core_Provider::value( $post, 'core.content' ) );
		$this->assertStringNotContainsString( '<!--', (string) Core_Provider::value( $post, 'core.content' ), 'The rendered content has no block comments.' );
		$this->assertSame( 'A short summary', Core_Provider::value( $post, 'core.excerpt' ) );
		$this->assertSame( get_permalink( $post ), Core_Provider::value( $post, 'core.permalink' ) );
		$this->assertStringEndsWith( '/christmas-concert/', (string) Core_Provider::value( $post, 'core.permalink' ) );
		$this->assertSame( home_url( '?p=' . $post->ID ), Core_Provider::value( $post, 'core.shortlink' ) );
		$this->assertSame( 'post', Core_Provider::value( $post, 'core.type' ) );
		$this->assertSame( get_post_type_object( 'post' )->labels->singular_name, Core_Provider::value( $post, 'core.type_label' ) );
	}

	/**
	 * Dates are the GMT ones, whatever the time zone of the site.
	 */
	public function test_dates_are_in_gmt() {
		update_option( 'timezone_string', 'Europe/Rome' );
		$post = $this->content();

		$this->assertSame( '2026-07-01 08:30:00', Core_Provider::value( $post, 'core.date' ) );
		$this->assertSame( $post->post_modified_gmt, Core_Provider::value( $post, 'core.modified' ) );
	}

	/**
	 * The author gives only its ID, its display name and, as a separate source, its archive URL.
	 */
	public function test_author_values() {
		$post = $this->content();

		$this->assertSame( $this->author, Core_Provider::value( $post, 'core.author.id' ) );
		$this->assertSame( 'Jane Doe', Core_Provider::value( $post, 'core.author.name' ) );
		$this->assertSame( get_author_posts_url( $this->author ), Core_Provider::value( $post, 'core.author.url' ) );
		$this->assertStringContainsString( 'jane-doe', (string) Core_Provider::value( $post, 'core.author.url' ) );
	}

	/**
	 * No core value contains the e-mail or the login of the author.
	 */
	public function test_no_value_exposes_the_email_or_the_login() {
		$post = $this->content();

		foreach ( Core_Provider::sources() as $source ) {
			$value = (string) wp_json_encode( Core_Provider::value( $post, $source->id ) );
			$this->assertStringNotContainsString( 'jdoe@example.org', $value, $source->id );
			$this->assertStringNotContainsString( 'jdoe', $value, $source->id );
		}
	}

	/**
	 * A page gives the same sources, with its own type.
	 */
	public function test_values_of_a_page() {
		$page = $this->content( 'page' );

		$this->assertSame( 'page', Core_Provider::value( $page, 'core.type' ) );
		$this->assertSame( get_post_type_object( 'page' )->labels->singular_name, Core_Provider::value( $page, 'core.type_label' ) );
		$this->assertSame( get_permalink( $page ), Core_Provider::value( $page, 'core.permalink' ) );
	}

	/**
	 * Fields without a value give null: no excerpt, no author.
	 */
	public function test_missing_values_are_null() {
		$id   = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_author'  => 0,
			)
		);
		$post = get_post( $id );

		$this->assertSame( '', Core_Provider::value( $post, 'core.excerpt' ), 'An empty excerpt is an empty value, not a missing one.' );
		$this->assertNull( Core_Provider::value( $post, 'core.author.id' ) );
		$this->assertNull( Core_Provider::value( $post, 'core.author.name' ) );
		$this->assertNull( Core_Provider::value( $post, 'core.author.url' ) );
	}

	/**
	 * An identifier that is not a core source gives null.
	 */
	public function test_an_unknown_source_gives_null() {
		$post = $this->content();

		$this->assertNull( Core_Provider::value( $post, 'core.nothing' ) );
		$this->assertNull( Core_Provider::value( $post, 'meta._price' ) );
		$this->assertNull( Core_Provider::value( $post, 'core.author.email' ) );
	}
}
