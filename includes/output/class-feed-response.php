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
	 * Items per page of a response: the requested value, otherwise the one of the feed within the global maximum.
	 *
	 * A feed saved with more items than a maximum lowered later is limited, not refused. A requested value
	 * above the maximum never reaches this point: the route refuses it.
	 *
	 * @param int      $feed_per_page Items per page of the feed.
	 * @param int      $max_per_page  Global maximum of the settings.
	 * @param int|null $requested     Items per page asked by the request, or null.
	 * @return int
	 */
	public static function per_page( int $feed_per_page, int $max_per_page, ?int $requested = null ): int {
		return max( 1, $requested ?? min( $feed_per_page, $max_per_page ) );
	}

	/**
	 * Pages needed for the items: none without items.
	 *
	 * @param int $total    Items of the feed.
	 * @param int $per_page Items per page.
	 * @return int
	 */
	private static function total_pages( int $total, int $per_page ): int {
		$per_page = max( 1, $per_page );

		// Without `$total + $per_page - 1`, which overflows with the largest page sizes (no ceiling in the settings).
		return $total > 0 ? intdiv( $total, $per_page ) + ( 0 === $total % $per_page ? 0 : 1 ) : 0;
	}

	/**
	 * Whether a page can be read: its offset, (page - 1) x per page, must be a whole number of PHP. The maximum items
	 * per page has no ceiling, so a large page of a large page size can go beyond; such a page is after the last
	 * one anyway.
	 *
	 * @param int $page     Page, from 1.
	 * @param int $per_page Items per page, from 1.
	 * @return bool
	 */
	public static function offset_fits( int $page, int $per_page ): bool {
		return $page - 1 <= intdiv( PHP_INT_MAX, max( 1, $per_page ) );
	}
}
