<?php
/**
 * Public REST endpoint of the feeds.
 *
 * @package KKCE
 */

namespace KKCE\Rest;

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Feed_Validator;
use KKCE\Output\Feed_Response;
use KKCE\Output\Item_Exporter;
use KKCE\Plugin;
use KKCE\Settings;
use KKCE\Sources\Exportable_Post_Types;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /kkce/v1/<slug>`: the read-only endpoint of a feed.
 *
 * The route is registered only while the endpoints are switched on in the settings, and listed in the REST index
 * unless the settings hide it (it answers in both cases). A feed answers when
 * it is published, enabled and has mapping rows; otherwise the answer is the same 404, so a response
 * never tells whether a feed exists. The items are the published ones without a password of the post
 * types of the feed that can still be exported, computed as an anonymous visitor (`Item_Exporter`), also
 * for an authenticated request.
 *
 * A feed that needs authentication (its access, or the setting that requires it for every feed) is read only
 * with an Application Password of a user with the capability of the feeds (`Feed_Access`): 401 without an
 * Application Password, 403 without the capability.
 *
 * Pages: `page` (from 1; a page after the last one is empty, with the real total) and `per_page` (the value of
 * the feed by default; above the global maximum the request is refused). The order is stable across pages and
 * post types: modified date in GMT, then ID, set by a `posts_orderby` filter that only the query of a feed uses.
 */
final class Feed_Routes {

	/**
	 * Error code of a feed that cannot be served (missing, unpublished, disabled, without mapping rows).
	 */
	public const ERROR_NOT_FOUND = 'kkce_feed_not_found';

	/**
	 * Error code of a request without an Application Password to a feed that needs authentication.
	 */
	public const ERROR_AUTHENTICATION = 'kkce_authentication_required';

	/**
	 * Name of the argument that carries the slug.
	 */
	private const SLUG = 'slug';

	/**
	 * Highest page accepted, so that the offset of a page stays a whole number.
	 */
	private const MAX_PAGE = 2147483647;

	/**
	 * Query variable that marks the queries of the feeds, for the order filter.
	 */
	private const ORDER_FLAG = 'kkce_feed_order';

	/**
	 * Registers the route when the endpoints are switched on. Hooked on `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register(): void {
		$settings = Settings::get();
		if ( ! $settings['enabled'] ) {
			return;
		}

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/(?P<' . self::SLUG . '>' . Feed_Validator::SLUG_PATTERN . ')',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'serve' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'show_in_index'       => $settings['show_in_index'],
				'args'                => array(
					self::SLUG => array(
						'description' => __( 'Slug of the feed.', 'kk-content-exporter' ),
						'type'        => 'string',
					),
					'page'     => array(
						'description' => __( 'Page of the items, from 1.', 'kk-content-exporter' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_PAGE,
						'default'     => 1,
					),
					'per_page' => array(
						'description'       => __( 'Items per page; by default the value of the feed, at most the maximum of the settings.', 'kk-content-exporter' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'validate_callback' => array( self::class, 'validate_per_page' ),
					),
				),
			)
		);
	}

	/**
	 * Whether the feed can be read: 404 when it cannot be served, 401 when it needs an Application Password
	 * that the request does not have, 403 when the user of the Application Password lacks the capability.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public static function can_read( WP_REST_Request $request ) {
		$feed = self::find_feed( $request->get_param( self::SLUG ) );
		if ( null === $feed ) {
			return self::not_found();
		}

		// The UUID of the Application Password that authenticated the request, null for any other authentication.
		$app_password = rest_get_authenticated_app_password();
		$access       = Feed_Meta::sanitize_access( get_post_meta( $feed->ID, Feed_Meta::META_ACCESS, true ) );
		$decision     = Feed_Access::decide(
			Feed_Meta::ACCESS_PUBLIC === $access,
			Settings::get()['require_auth'],
			null !== $app_password && is_user_logged_in(),
			current_user_can( Plugin::READ_EXPORT_CAPABILITY )
		);

		if ( Feed_Access::ALLOWED === $decision ) {
			return true;
		}

		if ( Feed_Access::FORBIDDEN === $decision ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to read this feed.', 'kk-content-exporter' ),
				array( 'status' => 403 )
			);
		}

		return new WP_Error(
			self::ERROR_AUTHENTICATION,
			__( 'Authentication with an Application Password is required to read this feed.', 'kk-content-exporter' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Checks the requested items per page: a whole number of at least 1, not above the maximum of the settings.
	 *
	 * @param mixed           $value   Value of the parameter.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Name of the parameter.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public static function validate_per_page( $value, WP_REST_Request $request, string $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $valid ) {
			return $valid;
		}

		$max = Settings::get()['max_per_page'];
		if ( (int) $value > $max ) {
			return new WP_Error(
				'rest_invalid_param',
				/* translators: %d: maximum items per page set in the settings of the plugin. */
				sprintf( __( 'per_page must be at most %d.', 'kk-content-exporter' ), $max ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Order of the queries of the feeds: modified date in GMT, then ID.
	 *
	 * WordPress can order by the local modified date only, which repeats or goes back when daylight saving
	 * time ends and changes with the time zone of the site. Hooked only around the query of a feed, and
	 * applied only to the queries that carry the flag of the feeds.
	 *
	 * @param mixed $orderby ORDER BY clause.
	 * @param mixed $query   The query.
	 * @return mixed
	 */
	public static function order_by_modified_gmt( $orderby, $query ) {
		if ( ! $query instanceof WP_Query || true !== $query->get( self::ORDER_FLAG ) ) {
			return $orderby;
		}

		global $wpdb;

		return "{$wpdb->posts}.post_modified_gmt ASC, {$wpdb->posts}.ID ASC";
	}

	/**
	 * A page of the feed.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function serve( WP_REST_Request $request ) {
		$feed = self::find_feed( $request->get_param( self::SLUG ) );
		if ( null === $feed ) {
			return self::not_found();
		}

		$mapping   = Feed_Meta::mapping( $feed->ID );
		$requested = $request->get_param( 'per_page' );
		$page      = (int) $request->get_param( 'page' );
		$per_page  = Feed_Response::per_page(
			Feed_Meta::sanitize_per_page( get_post_meta( $feed->ID, Feed_Meta::META_PER_PAGE, true ) ),
			Settings::get()['max_per_page'],
			null === $requested ? null : (int) $requested
		);

		list( $post_types ) = Exportable_Post_Types::split( Feed_Meta::post_types( $feed->ID ), Exportable_Post_Types::selectable() );

		$items = array();
		$total = 0;
		if ( array() !== $post_types ) {
			$query = self::query( $post_types, $page, $per_page );

			foreach ( $query->posts as $post ) {
				if ( $post instanceof WP_Post ) {
					$items[] = Item_Exporter::export( $post, $mapping );
				}
			}
			$total = (int) $query->found_posts;

			// WordPress does not count the items when the page is empty: count them for a page after the last one.
			if ( array() === $items && $page > 1 ) {
				$total = (int) self::query( $post_types, 1, 1, true )->found_posts;
			}
		}

		return new WP_REST_Response( Feed_Response::build( $items, $page, $per_page, $total, $feed->post_name, time() ) );
	}

	/**
	 * Query of a page of the items of a feed: published, without a password, in the order of the feeds.
	 *
	 * @param string[] $post_types Post types of the feed that can be exported.
	 * @param int      $page       Page, from 1.
	 * @param int      $per_page   Items per page.
	 * @param bool     $ids_only   Whether to load the IDs only (to count the items).
	 * @return WP_Query
	 *
	 * @phpstan-param list<string> $post_types
	 */
	private static function query( array $post_types, int $page, int $per_page, bool $ids_only = false ): WP_Query {
		$args = array(
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			// Fallback order, replaced by order_by_modified_gmt().
			'orderby'             => array(
				'modified' => 'ASC',
				'ID'       => 'ASC',
			),
			'ignore_sticky_posts' => true,
			self::ORDER_FLAG      => true,
		);
		if ( $ids_only ) {
			$args['fields'] = 'ids';
		}

		$order = array( self::class, 'order_by_modified_gmt' );
		add_filter( 'posts_orderby', $order, 10, 2 );
		try {
			return new WP_Query( $args );
		} finally {
			remove_filter( 'posts_orderby', $order, 10 );
		}
	}

	/**
	 * The feed that the slug serves: published, enabled and with mapping rows.
	 *
	 * The slug must be exactly a valid one: the route matches without regard to case, and WordPress would
	 * find a post by a slug in capitals.
	 *
	 * @param mixed $slug Slug of the address.
	 * @return WP_Post|null
	 */
	private static function find_feed( $slug ): ?WP_Post {
		if ( ! is_string( $slug ) || Feed_Validator::VALID !== Feed_Validator::validate_slug( $slug, array() ) ) {
			return null;
		}

		$found = get_posts(
			array(
				'post_type'      => Feed_Post_Type::POST_TYPE,
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		$feed  = $found[0] ?? null;

		if ( ! $feed instanceof WP_Post
			|| $slug !== $feed->post_name
			|| ! Feed_Meta::sanitize_active( get_post_meta( $feed->ID, Feed_Meta::META_ACTIVE, true ) )
			|| array() === Feed_Meta::mapping( $feed->ID )['rows']
		) {
			return null;
		}

		return $feed;
	}

	/**
	 * The error of a feed that cannot be served.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error(
			self::ERROR_NOT_FOUND,
			__( 'No feed is available at this address.', 'kk-content-exporter' ),
			array( 'status' => 404 )
		);
	}
}
