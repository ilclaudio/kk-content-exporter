<?php
/**
 * REST routes of the feed editor: catalog of the sources and preview of a mapping.
 *
 * @package KKCE
 */

namespace KKCE\Rest;

use KKCE\Feed_Post_Type;
use KKCE\Output\Catalog_Validator;
use KKCE\Output\Item_Exporter;
use KKCE\Output\Mapping;
use KKCE\Sources\Catalog_Factory;
use KKCE\Sources\Exportable_Post_Types;
use KKCE\Sources\Meta_Keys;
use KKCE\Sources\Source;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only routes used by the mapping editor, in their own namespace (`kkce-admin/v1`).
 *
 * Only the users who can edit feeds reach them (capability `edit_posts` of the feed post type):
 * anonymous requests get 401, other users 403. The JavaScript of the editor authenticates with the
 * cookie and the REST nonce (`wp_rest`), checked by WordPress. The routes are kept out of the REST index.
 *
 * - `GET /catalog`: the sources a mapping can use for the given post types.
 * - `POST /preview`: the exported object of an item for a mapping, saved or not. The item is computed
 *   as an anonymous visitor (`Item_Exporter`), and only published items without a password of the given
 *   post types can be previewed: the preview never shows what an endpoint would not export. Beside the
 *   object, `notices` lists what the administrator should know about it (`author_login`: the object shows
 *   the login name of the author through the author archive URL).
 * - `GET /items`: the latest modified items of a post type that the preview can use (the same rules: published,
 *   without a password), so the editor can choose the item to preview.
 */
final class Admin_Routes {

	/**
	 * Namespace of the admin routes.
	 */
	public const NAMESPACE = 'kkce-admin/v1';

	/**
	 * Allowed form of a post type key in the requests (the keys WordPress accepts, up to 20 characters).
	 */
	private const POST_TYPE_PATTERN = '^[a-z0-9_-]{1,20}$';

	/**
	 * Most post types accepted in one request.
	 */
	private const MAX_POST_TYPES = 100;

	/**
	 * Most post meta keys accepted in one request of the catalog.
	 */
	private const MAX_META_KEYS = 100;

	/**
	 * Items listed by `GET /items` for a post type.
	 */
	public const RECENT_ITEMS = 20;

	/**
	 * Registers the routes. Hooked on `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/catalog',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'catalog' ),
				'permission_callback' => array( self::class, 'can_edit_feeds' ),
				'show_in_index'       => false,
				'args'                => array(
					'post_types' => self::post_types_arg(),
					'meta_keys'  => array(
						'description' => __( 'Post meta keys to list as well, for example keys that the administrator typed.', 'kk-content-exporter' ),
						'type'        => 'array',
						'maxItems'    => self::MAX_META_KEYS,
						'items'       => array(
							'type'      => 'string',
							'maxLength' => Meta_Keys::MAX_LENGTH,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'preview' ),
				'permission_callback' => array( self::class, 'can_edit_feeds' ),
				'show_in_index'       => false,
				'args'                => array(
					// No maxLength: the schema counts characters, while the limit of a mapping is in bytes; the
					// callback refuses a longer text before decoding it, with the error `too_large`.
					'mapping'    => array(
						'description' => __( 'JSON text of the mapping to preview.', 'kk-content-exporter' ),
						'type'        => 'string',
						'required'    => true,
					),
					'post_types' => self::post_types_arg(),
					'post_type'  => array(
						'description' => __( 'Post type to preview, one of the post types of the feed; without it, every post type. The mapping is always checked against every post type of the feed.', 'kk-content-exporter' ),
						'type'        => 'string',
						'pattern'     => self::POST_TYPE_PATTERN,
					),
					'post_id'    => array(
						'description' => __( 'Item to preview; without it, the latest published item of every post type.', 'kk-content-exporter' ),
						'type'        => 'integer',
						'minimum'     => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'items' ),
				'permission_callback' => array( self::class, 'can_edit_feeds' ),
				'show_in_index'       => false,
				'args'                => array(
					'post_type' => array(
						'description' => __( 'Post type whose latest items are listed.', 'kk-content-exporter' ),
						'type'        => 'string',
						'required'    => true,
						'pattern'     => self::POST_TYPE_PATTERN,
					),
				),
			)
		);
	}

	/**
	 * Schema of the `post_types` argument.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_types_arg(): array {
		return array(
			'description' => __( 'Post types of the feed.', 'kk-content-exporter' ),
			'type'        => 'array',
			'required'    => true,
			'minItems'    => 1,
			'maxItems'    => self::MAX_POST_TYPES,
			'items'       => array(
				'type'    => 'string',
				'pattern' => self::POST_TYPE_PATTERN,
			),
		);
	}

	/**
	 * Whether the current user can edit feeds: the same capability as the feed editor.
	 *
	 * @return true|WP_Error
	 */
	public static function can_edit_feeds() {
		$type = get_post_type_object( Feed_Post_Type::POST_TYPE );

		if ( null !== $type && current_user_can( $type->cap->edit_posts ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to edit feeds.', 'kk-content-exporter' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Catalog of the sources for the requested post types.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function catalog( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::describe_catalog( $request->get_param( 'post_types' ), (array) $request->get_param( 'meta_keys' ) ) );
	}

	/**
	 * Description of the catalog for the mapping editor: the route answers with it, and the mapping box
	 * prints it as the initial data, so the editor reads one form only.
	 *
	 * @param mixed $requested Requested post types (a list of keys).
	 * @param mixed $meta_keys Post meta keys to list as well (typed by the administrator); the ones that cannot be
	 *                         a source (internal, malformed) are not listed and are given back in `refused_meta_keys`.
	 * @return array{post_types: list<string>, unavailable: list<string>, labels: array<string, string>, sources: list<array<string, mixed>>, suggested_rows: list<array<string, mixed>>, refused_meta_keys: list<string>}
	 */
	public static function describe_catalog( $requested, $meta_keys = array() ): array {
		$selectable                       = Exportable_Post_Types::selectable();
		list( $post_types, $unavailable ) = Exportable_Post_Types::split( $requested, $selectable );

		$keys    = array();
		$refused = array();
		foreach ( array_unique( array_map( 'strval', (array) $meta_keys ) ) as $key ) {
			if ( Meta_Keys::is_selectable( $key ) ) {
				$keys[] = $key;
			} else {
				$refused[] = $key;
			}
		}

		$catalog = Catalog_Factory::for_post_types( $post_types, true, $keys );

		$labels = array();
		foreach ( $post_types as $post_type ) {
			$labels[ $post_type ] = $selectable[ $post_type ] ?? $post_type;
		}

		return array(
			'post_types'        => $post_types,
			'unavailable'       => $unavailable,
			'labels'            => $labels,
			'sources'           => array_map( array( self::class, 'describe_source' ), $catalog->sources() ),
			'suggested_rows'    => $catalog->suggested_rows(),
			'refused_meta_keys' => $refused,
		);
	}

	/**
	 * Preview of a mapping on one item, or on the latest published item of every post type (or of `post_type`
	 * only). The mapping belongs to the whole feed, so it is always checked against the catalog of every post type
	 * of the request, also when the editor asks one post type at a time.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		// The post types come first: without them the catalog is empty and every source would look unknown.
		list( $post_types ) = Exportable_Post_Types::split( $request->get_param( 'post_types' ), Exportable_Post_Types::selectable() );
		if ( array() === $post_types ) {
			return new WP_Error(
				'kkce_no_post_types',
				__( 'None of the requested post types can be exported.', 'kk-content-exporter' ),
				array( 'status' => 400 )
			);
		}

		// The post type to preview, when asked, must be one of the feed: the others would not be checked.
		$only = $request->get_param( 'post_type' );
		if ( null !== $only && ! in_array( $only, $post_types, true ) ) {
			return new WP_Error(
				'kkce_no_post_types',
				__( 'The post type to preview is not one of the post types of the feed.', 'kk-content-exporter' ),
				array( 'status' => 400 )
			);
		}

		$result  = Catalog_Validator::validate_text( $request->get_param( 'mapping' ), Catalog_Factory::for_post_types( $post_types ), 'wp_json_encode' );
		$mapping = $result['mapping'];
		if ( null === $mapping ) {
			return new WP_Error(
				'kkce_invalid_mapping',
				__( 'The mapping is not valid.', 'kk-content-exporter' ),
				array(
					'status' => 400,
					'errors' => $result['errors'],
				)
			);
		}
		$post_id = $request->get_param( 'post_id' );

		if ( null !== $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post || ! self::is_previewable( $post, $post_types ) ) {
				return new WP_Error(
					'kkce_item_not_found',
					__( 'No published item of the feed post types has this ID.', 'kk-content-exporter' ),
					array( 'status' => 404 )
				);
			}

			return new WP_REST_Response(
				array(
					'items'       => array( self::preview_item( $post, $mapping, $post_types ) ),
					'empty_types' => array(),
				)
			);
		}

		$items       = array();
		$empty_types = array();
		foreach ( null === $only ? $post_types : array( $only ) as $post_type ) {
			$post = self::latest_item( $post_type );
			if ( null === $post ) {
				$empty_types[] = $post_type;
				continue;
			}
			$items[] = self::preview_item( $post, $mapping, $post_types );
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'empty_types' => $empty_types,
			)
		);
	}

	/**
	 * Latest modified items of a selectable post type that the preview can use.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function items( WP_REST_Request $request ) {
		list( $post_types ) = Exportable_Post_Types::split( array( $request->get_param( 'post_type' ) ), Exportable_Post_Types::selectable() );
		if ( array() === $post_types ) {
			return new WP_Error(
				'kkce_no_post_types',
				__( 'None of the requested post types can be exported.', 'kk-content-exporter' ),
				array( 'status' => 400 )
			);
		}

		$items = array();
		foreach ( self::recent_items( $post_types[0], self::RECENT_ITEMS ) as $post ) {
			$items[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'modified' => str_replace( ' ', 'T', $post->post_modified_gmt ) . 'Z',
			);
		}

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * Description of a source for the mapping editor.
	 *
	 * @param Source $source Source.
	 * @return array<string, mixed>
	 */
	private static function describe_source( Source $source ): array {
		return array(
			'id'                 => $source->id,
			'label'              => $source->label,
			'group'              => $source->group(),
			'post_type'          => $source->post_type,
			'type'               => $source->type,
			'options'            => $source->options,
			'hidden'             => $source->is_hidden(),
			'needs_confirmation' => $source->needs_confirmation(),
			'suggested_key'      => Mapping::suggest_key( $source->id ),
		);
	}

	/**
	 * Whether an item can be previewed: published, without a password, of a post type of the feed.
	 *
	 * @param WP_Post  $post       Item.
	 * @param string[] $post_types Selectable post types of the request.
	 * @return bool
	 */
	private static function is_previewable( WP_Post $post, array $post_types ): bool {
		return 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, $post_types, true );
	}

	/**
	 * Latest modified item of a post type that is published and has no password.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Post|null
	 */
	private static function latest_item( string $post_type ): ?WP_Post {
		return self::recent_items( $post_type, 1 )[0] ?? null;
	}

	/**
	 * Latest modified items of a post type that are published and have no password, newest first.
	 *
	 * @param string $post_type Post type.
	 * @param int    $limit     Most items.
	 * @return list<WP_Post>
	 */
	private static function recent_items( string $post_type, int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => $limit,
				'orderby'             => array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		return array_values( $posts );
	}

	/**
	 * Preview of one item: which item, its exported object and the notices about it.
	 *
	 * @param WP_Post      $post       Item.
	 * @param array<mixed> $mapping    Canonical mapping.
	 * @param string[]     $post_types Post types of the feed (the type `auto` is decided on all of them).
	 * @return array<string, mixed>
	 *
	 * @phpstan-param list<string> $post_types
	 */
	private static function preview_item( WP_Post $post, array $mapping, array $post_types ): array {
		$data    = Item_Exporter::export( $post, $mapping, $post_types );
		$notices = array();
		if ( self::exports_author_login( $post, $data ) ) {
			$notices[] = array(
				'code'    => 'author_login',
				'message' => __( 'In this item the author archive URL contains the login name of the author: whoever reads the feed learns it.', 'kk-content-exporter' ),
			);
		}
		if ( self::exports_author_display_login( $post, $data ) ) {
			$notices[] = array(
				'code'    => 'author_display_login',
				'message' => __( 'In this item the display name of the author is the login name, as WordPress sets it for a new user: whoever reads the feed learns it. Ask the author to choose another display name in the profile.', 'kk-content-exporter' ),
			);
		}

		return array(
			'post'    => array(
				'id'    => $post->ID,
				'type'  => $post->post_type,
				'title' => $post->post_title,
			),
			// An object, so that an item without values is `{}` in JSON, not `[]`.
			'data'    => (object) $data,
			'notices' => $notices,
		);
	}

	/**
	 * Whether the exported object of an item shows the login name of its author: one of its texts contains the
	 * author archive URL, the URL contains the `user_nicename` and the `user_nicename` is the one WordPress
	 * derives from the login (`sanitize_title()` of its first 50 characters, as `wp_insert_user()` does). The
	 * login itself never leaves the server: the preview gets only the notice (decision of 2026-11-08).
	 *
	 * @param WP_Post              $post Item.
	 * @param array<string, mixed> $data Exported object of the item.
	 * @return bool
	 */
	private static function exports_author_login( WP_Post $post, array $data ): bool {
		$author = get_userdata( (int) $post->post_author );
		if ( false === $author || '' === $author->user_nicename ) {
			return false;
		}

		$url = get_author_posts_url( $author->ID, $author->user_nicename );
		if ( ! str_contains( $url, $author->user_nicename ) ) {
			// Plain permalinks: the URL carries the ID only.
			return false;
		}
		if ( sanitize_title( mb_substr( $author->user_login, 0, 50 ) ) !== $author->user_nicename ) {
			return false;
		}

		return self::contains_text( $data, $url );
	}

	/**
	 * Whether the exported object of an item shows the login name of its author through the display name: WordPress
	 * sets the display name of a new user to the login, and `core.author.name` needs no confirmation. The login itself
	 * never leaves the server: the preview gets only the notice.
	 *
	 * @param WP_Post              $post Item.
	 * @param array<string, mixed> $data Exported object.
	 * @return bool
	 */
	private static function exports_author_display_login( WP_Post $post, array $data ): bool {
		$author = get_userdata( (int) $post->post_author );
		if ( false === $author || '' === $author->user_login || $author->display_name !== $author->user_login ) {
			return false;
		}

		return self::contains_text( $data, $author->user_login );
	}

	/**
	 * Whether a text of an exported object, at any depth, contains a given text.
	 *
	 * @param array<string, mixed> $data   Exported object.
	 * @param string               $needle Text to look for.
	 * @return bool
	 */
	private static function contains_text( array $data, string $needle ): bool {
		$found = false;
		array_walk_recursive(
			$data,
			static function ( $value ) use ( $needle, &$found ) {
				$found = $found || ( is_string( $value ) && str_contains( $value, $needle ) );
			}
		);

		return $found;
	}
}
