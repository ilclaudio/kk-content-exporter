<?php
/**
 * Response of the endpoint of a feed.
 *
 * @package KKCE
 */

namespace KKCE\Output;

defined( 'ABSPATH' ) || exit;

/**
 * The fixed container of a feed response: only the keys inside `items` depend on the mapping.
 *
 * Pure logic: no WordPress function is called.
 *
 * ```json
 * {
 *   "items": [ { ... } ],
 *   "pagination": { "page": 1, "per_page": 50, "total": 1, "total_pages": 1 },
 *   "meta": { "feed": "news", "generated_at": "2026-11-06T12:00:00Z" }
 * }
 * ```
 */
final class Feed_Response {

	/**
	 * The response of a page of a feed.
	 *
	 * @param list<array<string, mixed>> $items        Exported objects of the items of the page.
	 * @param int                        $page         Number of the page, from 1.
	 * @param int                        $per_page     Items per page, at least 1.
	 * @param int                        $total        Items of the feed on all the pages.
	 * @param string                     $feed         Slug of the feed.
	 * @param int                        $generated_at Time of the response (Unix timestamp).
	 * @return array{items: list<object>, pagination: array{page: int, per_page: int, total: int, total_pages: int}, meta: array{feed: string, generated_at: string}}
	 */
	public static function build( array $items, int $page, int $per_page, int $total, string $feed, int $generated_at ): array {
		return array(
			// Objects, so that an item without values is `{}` in JSON, not `[]`.
			'items'      => array_map( static fn( array $item ): object => (object) $item, $items ),
			'pagination' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => self::total_pages( $total, $per_page ),
			),
			'meta'       => array(
				'feed'         => $feed,
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z', $generated_at ),
			),
		);
	}

	/**
	 * Items per page of a feed: its own value, never above the global maximum.
	 *
	 * A feed saved with more items than a maximum lowered later is limited, not refused.
	 *
	 * @param int $feed_per_page Items per page of the feed.
	 * @param int $max_per_page  Global maximum of the settings.
	 * @return int
	 */
	public static function per_page( int $feed_per_page, int $max_per_page ): int {
		return max( 1, min( $feed_per_page, $max_per_page ) );
	}

	/**
	 * Pages needed for the items: none without items.
	 *
	 * @param int $total    Items of the feed.
	 * @param int $per_page Items per page.
	 * @return int
	 */
	private static function total_pages( int $total, int $per_page ): int {
		return $total > 0 ? intdiv( $total + max( 1, $per_page ) - 1, max( 1, $per_page ) ) : 0;
	}
}
