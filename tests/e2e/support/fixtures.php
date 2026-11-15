<?php
/**
 * Test data of the E2E suite, run with WP-CLI inside the wp-env development environment:
 *
 *     wp eval-file wp-content/plugins/kk-content-exporter/tests/e2e/support/fixtures.php create <name> <json>
 *     wp eval-file wp-content/plugins/kk-content-exporter/tests/e2e/support/fixtures.php cleanup
 *
 * `create` makes the posts, pages and feed described by the JSON options and prints their IDs as JSON.
 * Every object it creates has a slug that starts with `kkce-e2e-`, and `cleanup` deletes exactly those
 * objects (any status, without the trash), so nothing else of the environment is touched.
 *
 * Development only: never loaded by the plugin and never shipped (tests/ is left out of the ZIP).
 *
 * @package KKCE
 */

const KKCE_E2E_PREFIX = 'kkce-e2e-';

/**
 * Creates a published item of a post type.
 *
 * @param string $post_type Post type.
 * @param string $slug      Slug, with the prefix.
 * @param array  $item      Title, excerpt and content.
 * @return int
 */
function kkce_e2e_item( $post_type, $slug, array $item ) {
	return (int) wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => isset( $item['title'] ) ? $item['title'] : $slug,
			'post_excerpt' => isset( $item['excerpt'] ) ? $item['excerpt'] : '',
			'post_content' => isset( $item['content'] ) ? $item['content'] : '<p>Content of ' . $slug . '.</p>',
		),
		true
	);
}

/**
 * Creates the data of a test.
 *
 * @param string $name    Short name of the test (letters, digits, dashes).
 * @param array  $options `post_types` of the feed, `posts` and `pages` (lists of title, excerpt, content),
 *                        `rows` of a mapping to save (canonical rows).
 * @return array<string, mixed> IDs of the feed and of the items.
 */
function kkce_e2e_create( $name, array $options ) {
	$slug   = KKCE_E2E_PREFIX . $name;
	$result = array(
		'posts' => array(),
		'pages' => array(),
	);

	foreach ( array( 'posts' => 'post', 'pages' => 'page' ) as $key => $post_type ) {
		foreach ( isset( $options[ $key ] ) ? $options[ $key ] : array() as $index => $item ) {
			$result[ $key ][] = kkce_e2e_item( $post_type, $slug . '-' . $post_type . '-' . $index, $item );
		}
	}

	$feed = (int) wp_insert_post(
		array(
			'post_type'   => 'kkce_feed',
			'post_status' => 'publish',
			'post_name'   => $slug,
			'post_title'  => 'E2E ' . $name,
		),
		true
	);
	foreach ( isset( $options['post_types'] ) ? $options['post_types'] : array( 'post' ) as $post_type ) {
		add_post_meta( $feed, '_kkce_post_type', $post_type );
	}
	update_post_meta( $feed, '_kkce_access', 'public' );
	update_post_meta( $feed, '_kkce_active', false );
	update_post_meta( $feed, '_kkce_per_page', 50 );
	if ( isset( $options['rows'] ) && is_array( $options['rows'] ) ) {
		\KKCE\Feed_Meta::save_mapping(
			$feed,
			(string) wp_json_encode(
				array(
					'version' => 1,
					'rows'    => $options['rows'],
				)
			)
		);
	}

	$result['feed'] = $feed;
	$result['slug'] = $slug;

	return $result;
}

/**
 * Deletes every object created by the suite.
 *
 * @return int Number of objects deleted.
 */
function kkce_e2e_cleanup() {
	global $wpdb;

	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE %s", $wpdb->esc_like( KKCE_E2E_PREFIX ) . '%' ) );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	return count( $ids );
}

$kkce_e2e_command = isset( $args[0] ) ? $args[0] : '';
if ( 'create' === $kkce_e2e_command && isset( $args[1] ) && 1 === preg_match( '/\A[a-z0-9-]{1,40}\z/', $args[1] ) ) {
	$kkce_e2e_options = isset( $args[2] ) ? json_decode( $args[2], true ) : array();
	echo wp_json_encode( kkce_e2e_create( $args[1], is_array( $kkce_e2e_options ) ? $kkce_e2e_options : array() ) );
} elseif ( 'cleanup' === $kkce_e2e_command ) {
	echo wp_json_encode( array( 'deleted' => kkce_e2e_cleanup() ) );
} else {
	fwrite( STDERR, "Usage: fixtures.php create <name> [json] | cleanup\n" );
	exit( 1 );
}
