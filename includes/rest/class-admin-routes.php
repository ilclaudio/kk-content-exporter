<?php
/**
 * REST routes of the feed editor: catalog of the sources and preview of a mapping.
 *
 * @package KKCE
 */

namespace KKCE\Rest;

use KKCE\Admin\Feed_Editor;
use KKCE\Feed_Post_Type;
use KKCE\Output\Item_Exporter;
use KKCE\Output\Mapping;
use KKCE\Output\Mapping_Validator;
use KKCE\Sources\Catalog_Factory;
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
 *   post types can be previewed: the preview never shows what an endpoint would not export.
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
					'mapping'    => array(
						'description' => __( 'JSON text of the mapping to preview.', 'kk-content-exporter' ),
						'type'        => 'string',
						'required'    => true,
						'maxLength'   => Mapping::MAX_BYTES,
					),
					'post_types' => self::post_types_arg(),
					'post_id'    => array(
						'description' => __( 'Item to preview; without it, the latest published item of every post type.', 'kk-content-exporter' ),
						'type'        => 'integer',
						'minimum'     => 1,
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
		return new WP_REST_Response( self::describe_catalog( $request->get_param( 'post_types' ) ) );
	}

	/**
	 * Description of the catalog for the mapping editor: the route answers with it, and the mapping box
	 * prints it as the initial data, so the editor reads one form only.
	 *
	 * @param mixed $requested Requested post types (a list of keys).
	 * @return array{post_types: list<string>, unavailable: list<string>, labels: array<string, string>, sources: list<array<string, mixed>>, suggested_rows: list<array<string, mixed>>}
	 */
	public static function describe_catalog( $requested ): array {
		list( $post_types, $unavailable ) = self::split_post_types( $requested );
		$catalog                          = Catalog_Factory::for_post_types( $post_types );
		$selectable                       = Feed_Editor::selectable_post_types();

		$labels = array();
		foreach ( $post_types as $post_type ) {
			$labels[ $post_type ] = $selectable[ $post_type ] ?? $post_type;
		}

		return array(
			'post_types'     => $post_types,
			'unavailable'    => $unavailable,
			'labels'         => $labels,
			'sources'        => array_map( array( self::class, 'describe_source' ), $catalog->sources() ),
			'suggested_rows' => $catalog->suggested_rows(),
		);
	}

	/**
	 * Preview of a mapping on one item, or on the latest published item of every post type.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		$decoded = Mapping::decode( $request->get_param( 'mapping' ) );
		$errors  = null === $decoded
			? array(
				array(
					'row'   => Mapping_Validator::MAPPING_ROW,
					'field' => '',
					'code'  => 'json',
				),
			)
			: Mapping_Validator::validate( $decoded );

		if ( null === $decoded || array() !== $errors ) {
			return new WP_Error(
				'kkce_invalid_mapping',
				__( 'The mapping is not valid.', 'kk-content-exporter' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		list( $post_types ) = self::split_post_types( $request->get_param( 'post_types' ) );
		if ( array() === $post_types ) {
			return new WP_Error(
				'kkce_no_post_types',
				__( 'None of the requested post types can be exported.', 'kk-content-exporter' ),
				array( 'status' => 400 )
			);
		}

		$mapping = Mapping_Validator::normalize( $decoded );
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
					'items'       => array( self::preview_item( $post, $mapping ) ),
					'empty_types' => array(),
				)
			);
		}

		$items       = array();
		$empty_types = array();
		foreach ( $post_types as $post_type ) {
			$post = self::latest_item( $post_type );
			if ( null === $post ) {
				$empty_types[] = $post_type;
				continue;
			}
			$items[] = self::preview_item( $post, $mapping );
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'empty_types' => $empty_types,
			)
		);
	}

	/**
	 * Splits the requested post types into the selectable ones and the others, without duplicates.
	 *
	 * @param mixed $requested Validated `post_types` argument.
	 * @return array{0: list<string>, 1: list<string>} Selectable post types and the others, in the order of the request.
	 */
	private static function split_post_types( $requested ): array {
		$selectable  = Feed_Editor::selectable_post_types();
		$available   = array();
		$unavailable = array();

		foreach ( array_unique( array_map( 'strval', (array) $requested ) ) as $post_type ) {
			if ( isset( $selectable[ $post_type ] ) ) {
				$available[] = $post_type;
			} else {
				$unavailable[] = $post_type;
			}
		}

		return array( $available, $unavailable );
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
		$posts = get_posts(
			array(
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => 1,
				'orderby'             => array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$post = $posts[0] ?? null;

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Preview of one item: which item, and its exported object.
	 *
	 * @param WP_Post      $post    Item.
	 * @param array<mixed> $mapping Canonical mapping.
	 * @return array<string, mixed>
	 */
	private static function preview_item( WP_Post $post, array $mapping ): array {
		return array(
			'post' => array(
				'id'    => $post->ID,
				'type'  => $post->post_type,
				'title' => $post->post_title,
			),
			// An object, so that an item without values is `{}` in JSON, not `[]`.
			'data' => (object) Item_Exporter::export( $post, $mapping ),
		);
	}
}
