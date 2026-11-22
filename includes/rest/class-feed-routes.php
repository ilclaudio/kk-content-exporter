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
use WP_HTTP_Response;
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
					self::SLUG        => array(
						'description' => __( 'Slug of the feed.', 'kk-content-exporter' ),
						'type'        => 'string',
					),
					'page'            => array(
						'description' => __( 'Page of the items, from 1.', 'kk-content-exporter' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_PAGE,
						'default'     => 1,
					),
					'per_page'        => array(
						'description'       => __( 'Items per page; by default the value of the feed, at most the maximum of the settings.', 'kk-content-exporter' ),
						'type'              => 'integer',
						'minimum'           => 1,
						'validate_callback' => array( self::class, 'validate_per_page' ),
					),
					'modified_after'  => self::date_filter_arg( __( 'Items modified at or after this moment (included), for example 2026-11-01T10:00:00Z.', 'kk-content-exporter' ) ),
					'modified_before' => self::date_filter_arg( __( 'Items modified before this moment (excluded), for example 2026-11-01T10:00:00Z.', 'kk-content-exporter' ) ),
					'published_after' => self::date_filter_arg( __( 'Items published at or after this moment (included), for example 2026-11-01T10:00:00Z.', 'kk-content-exporter' ) ),
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
	 * Adds an available HTTP Basic challenge to 401 responses of KKCE-owned REST endpoints only.
	 *
	 * Runs after authentication as well as dispatch, so errors from WordPress's Application Password
	 * handler receive the challenge too, even on public feeds. Existing challenges and non-KKCE
	 * responses are preserved; WordPress's authentication availability is never changed.
	 * Hooked on `rest_post_dispatch`.
	 *
	 * @param mixed           $response REST response.
	 * @param WP_REST_Server  $server   REST server with the registered endpoint callbacks.
	 * @param WP_REST_Request $request  Request being served.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed Response, with a challenge when applicable.
	 */
	public static function authentication_challenge( $response, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( ! $response instanceof WP_HTTP_Response || 401 !== $response->get_status()
			|| ( 0 !== strncasecmp( $request->get_route(), '/' . Plugin::REST_NAMESPACE . '/', strlen( Plugin::REST_NAMESPACE ) + 2 )
				&& 0 !== strncasecmp( $request->get_route(), '/' . Admin_Routes::NAMESPACE . '/', strlen( Admin_Routes::NAMESPACE ) + 2 ) )
		) {
			return $response;
		}

		// Resolve the first matching method exactly as dispatch does. Another plugin can own a route
		// even inside our namespace, so a prefix alone is not proof that its response belongs to us.
		$owned = false;
		foreach ( $server->get_routes() as $route => $handlers ) {
			if ( 1 !== preg_match( '@^' . $route . '$@i', $request->get_route() ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$method = $request->get_method();
				if ( 'HEAD' === $method && empty( $handler['methods']['HEAD'] ) ) {
					$method = 'GET';
				}
				if ( empty( $handler['methods'][ $method ] ) ) {
					continue;
				}
				$callback = $handler['callback'] ?? null;
				$owned    = is_array( $callback ) && isset( $callback[0] )
					&& in_array( $callback[0], array( self::class, Admin_Routes::class ), true );
				break 2;
			}
		}

		if ( ! $owned || ! wp_is_application_passwords_available() ) {
			return $response;
		}

		foreach ( array_keys( $response->get_headers() ) as $name ) {
			if ( 0 === strcasecmp( $name, 'WWW-Authenticate' ) ) {
				return $response;
			}
		}

		$response->header( 'WWW-Authenticate', 'Basic realm="KK Content Exporter"' );
		return $response;
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
	 * Schema of a date filter: a text, checked by `validate_date_filter()`.
	 *
	 * @param string $description Description of the filter.
	 * @return array<string, mixed>
	 */
	private static function date_filter_arg( string $description ): array {
		return array(
			'description'       => $description,
			'type'              => 'string',
			'validate_callback' => array( self::class, 'validate_date_filter' ),
		);
	}

	/**
	 * Checks a date filter: turned on in the settings, and a date with a time zone (`Date_Filters`).
	 *
	 * @param mixed           $value   Value of the parameter.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Name of the parameter.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public static function validate_date_filter( $value, WP_REST_Request $request, string $param ) {
		$filter = Date_Filters::FILTERS[ $param ] ?? null;
		if ( null === $filter ) {
			return true;
		}

		$code = Date_Filters::check_value( $value, Settings::get()[ $filter['setting'] ] );
		if ( Date_Filters::VALID === $code ) {
			return true;
		}

		$message = Date_Filters::ERROR_DISABLED === $code
			/* translators: %s: name of a parameter of the feed endpoint. */
			? sprintf( __( '%s is turned off on this site.', 'kk-content-exporter' ), $param )
			/* translators: 1: name of a parameter of the feed endpoint, 2: example of a date. */
			: sprintf( __( '%1$s must be a date with a time zone, for example %2$s.', 'kk-content-exporter' ), $param, '2026-11-01T10:00:00Z' );

		return new WP_Error( 'rest_invalid_param', $message, array( 'status' => 400 ) );
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

		$dates = array();
		foreach ( array_keys( Date_Filters::FILTERS ) as $name ) {
			$date = Date_Filters::parse( $request->get_param( $name ) );
			if ( null !== $date ) {
				$dates[ $name ] = $date;
			}
		}
		if ( Date_Filters::empty_interval( $dates['modified_after'] ?? null, $dates['modified_before'] ?? null ) ) {
			$message = __( 'modified_after must be earlier than modified_before.', 'kk-content-exporter' );

			return new WP_Error(
				'rest_invalid_param',
				$message,
				array(
					'status' => 400,
					'params' => array( 'modified_after' => $message ),
				)
			);
		}

		list( $post_types ) = Exportable_Post_Types::split( Feed_Meta::post_types( $feed->ID ), Exportable_Post_Types::selectable() );

		$items = array();
		$total = 0;
		if ( array() !== $post_types ) {
			$date_query = Date_Filters::date_query( $dates );
			$query      = self::query( $post_types, $page, $per_page, $date_query );
			$posts      = array_values( array_filter( $query->posts, static fn( $post ): bool => $post instanceof WP_Post ) );

			// The query loads meta and terms for the whole page; the authors and the featured images (attachments
			// with their meta) are loaded here, all at once, so that their sources do not cost queries per item.
			update_post_author_caches( $posts );
			update_post_thumbnail_cache( $query );

			foreach ( $posts as $post ) {
				$items[] = Item_Exporter::export( $post, $mapping );
			}
			$total = (int) $query->found_posts;

			// WordPress does not count the items when the page is empty: count them for a page after the last one.
			if ( array() === $items && $page > 1 ) {
				$total = (int) self::query( $post_types, 1, 1, $date_query, true )->found_posts;
			}
		}

		return new WP_REST_Response( Feed_Response::build( $items, $page, $per_page, $total, $feed->post_name, time() ) );
	}

	/**
	 * Query of a page of the items of a feed: published, without a password, in the order of the feeds.
	 * Selection and counting use anonymous visibility; the caller's user is restored even on an exception.
	 *
	 * @param string[]                 $post_types Post types of the feed that can be exported.
	 * @param int                      $page       Page, from 1.
	 * @param int                      $per_page   Items per page.
	 * @param array<int|string, mixed> $date_query Clauses of the date filters (`Date_Filters::date_query()`), or empty.
	 * @param bool                     $ids_only   Whether to load the IDs only (to count the items).
	 * @return WP_Query
	 *
	 * @phpstan-param list<string> $post_types
	 */
	private static function query( array $post_types, int $page, int $per_page, array $date_query = array(), bool $ids_only = false ): WP_Query {
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
		if ( array() !== $date_query ) {
			$args['date_query'] = $date_query;
		}
		if ( $ids_only ) {
			$args['fields'] = 'ids';
		}

		$previous_user = get_current_user_id();
		$order         = array( self::class, 'order_by_modified_gmt' );
		add_filter( 'posts_orderby', $order, 10, 2 );
		try {
			wp_set_current_user( 0 );

			return new WP_Query( $args );
		} finally {
			remove_filter( 'posts_orderby', $order, 10 );
			wp_set_current_user( $previous_user );
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
