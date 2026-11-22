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
	private static int $author;

	/**
	 * Creates the shared author once; posts and pages remain local to each test.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$author = $factory->user->create(
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
	 * Sets the permalink structure for each test independently.
	 */
	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );
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
				'post_author'   => self::$author,
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

		$this->assertSame( self::$author, Core_Provider::value( $post, 'core.author.id' ) );
		$this->assertSame( 'Jane Doe', Core_Provider::value( $post, 'core.author.name' ) );
		$this->assertSame( get_author_posts_url( self::$author ), Core_Provider::value( $post, 'core.author.url' ) );
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
	 * An attachment with the given file, without writing the file: WordPress builds its URL from the meta.
	 *
	 * @param string              $file     File relative to the uploads folder.
	 * @param string              $mime     MIME type.
	 * @param array<string,mixed> $metadata Attachment metadata.
	 * @return int
	 */
	private function attachment( string $file, string $mime = 'image/jpeg', array $metadata = array() ): int {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => $mime,
				'post_status'    => 'inherit',
			)
		);
		update_post_meta( $id, '_wp_attached_file', $file );
		if ( array() !== $metadata ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		return $id;
	}

	/**
	 * The featured image gives the absolute URL of the uploaded image.
	 */
	public function test_the_featured_image_is_the_absolute_url_of_the_image() {
		$post  = $this->content();
		$image = $this->attachment( '2026/11/concert.jpg' );
		set_post_thumbnail( $post, $image );

		$url = Core_Provider::value( $post, 'core.featured_image' );

		$this->assertSame( wp_get_upload_dir()['baseurl'] . '/2026/11/concert.jpg', $url );
		$this->assertMatchesRegularExpression( '#^https?://#', (string) $url );
	}

	/**
	 * A large image scaled down by WordPress gives the original file, not the scaled copy.
	 */
	public function test_the_featured_image_is_the_original_of_a_scaled_image() {
		$post  = $this->content();
		$image = $this->attachment(
			'2026/11/poster-scaled.jpg',
			'image/jpeg',
			array(
				'file'           => '2026/11/poster-scaled.jpg',
				'original_image' => 'poster.jpg',
			)
		);
		set_post_thumbnail( $post, $image );

		$this->assertSame( wp_get_upload_dir()['baseurl'] . '/2026/11/poster.jpg', Core_Provider::value( $post, 'core.featured_image' ) );
	}

	/**
	 * Without a usable image the featured image is null: no image, an attachment that is not an image, an
	 * attachment that no longer exists.
	 */
	public function test_the_featured_image_is_null_without_an_image() {
		$post = $this->content();
		$this->assertNull( Core_Provider::value( $post, 'core.featured_image' ), 'No featured image.' );

		update_post_meta( $post->ID, '_thumbnail_id', $this->attachment( '2026/11/programme.pdf', 'application/pdf' ) );
		$this->assertNull( Core_Provider::value( $post, 'core.featured_image' ), 'An attachment that is not an image.' );

		$image = $this->attachment( '2026/11/gone.jpg' );
		update_post_meta( $post->ID, '_thumbnail_id', $image );
		wp_delete_post( $image, true );
		update_post_meta( $post->ID, '_thumbnail_id', $image );
		$this->assertNull( Core_Provider::value( $post, 'core.featured_image' ), 'An attachment that no longer exists.' );
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
