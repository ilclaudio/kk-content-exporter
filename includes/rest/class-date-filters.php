<?php
/**
 * Date filters of the endpoint of a feed.
 *
 * @package KKCE
 */

namespace KKCE\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * The date filters of a feed: `modified_after`, `modified_before` and `published_after`.
 *
 * Pure logic: no WordPress function is called. A date is written as the dates of the feed are: ISO 8601 with
 * a time zone, `2026-11-01T10:00:00Z` or `2026-11-01T11:00:00+01:00`; fractions of a second are allowed and
 * cut. A date without a time zone is refused, because its moment is unknown. The interval is half-open:
 * `after` included, `before` excluded, so consecutive readings neither skip nor repeat a moment; an interval
 * that cannot contain anything is refused. Each filter has a switch in the settings; a filter turned off is
 * refused, never ignored, so that an importer never reads more items than it asked for.
 */
final class Date_Filters {

	/**
	 * The filters: setting that switches each one, column of the posts table, and bound.
	 *
	 * @var array<string, array{setting: string, column: string, bound: string}>
	 */
	public const FILTERS = array(
		'modified_after'  => array(
			'setting' => 'filter_modified_after',
			'column'  => 'post_modified_gmt',
			'bound'   => 'after',
		),
		'modified_before' => array(
			'setting' => 'filter_modified_before',
			'column'  => 'post_modified_gmt',
			'bound'   => 'before',
		),
		'published_after' => array(
			'setting' => 'filter_published_after',
			'column'  => 'post_date_gmt',
			'bound'   => 'after',
		),
	);

	/**
	 * A valid value of a filter turned on.
	 */
	public const VALID = '';

	/**
	 * A date that is not in the accepted form, or that does not exist.
	 */
	public const ERROR_FORMAT = 'format';

	/**
	 * A filter turned off in the settings.
	 */
	public const ERROR_DISABLED = 'disabled';

	/**
	 * Accepted form: date, `T`, time with seconds, optional fraction, `Z` or an offset `+hh:mm` / `-hh:mm`.
	 */
	private const PATTERN = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

	/**
	 * Largest offset of a time zone, in hours.
	 */
	private const MAX_OFFSET_HOURS = 14;

	/**
	 * Checks the value of a filter: first its switch, then its form.
	 *
	 * @param mixed $value   Value of the parameter.
	 * @param bool  $enabled Whether the filter is turned on in the settings.
	 * @return string `VALID` or an error code.
	 */
	public static function check_value( $value, bool $enabled ): string {
		if ( ! $enabled ) {
			return self::ERROR_DISABLED;
		}

		return null === self::parse( $value ) ? self::ERROR_FORMAT : self::VALID;
	}

	/**
	 * The date of a parameter in UTC, as WordPress stores it in the GMT columns.
	 *
	 * @param mixed $value Value of the parameter.
	 * @return string|null `Y-m-d H:i:s` in UTC, or null when the value is not an accepted date.
	 */
	public static function parse( $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( self::PATTERN, $value, $parts ) ) {
			return null;
		}

		list( , $year, $month, $day, $hour, $minute, $second ) = array_map( 'intval', array_slice( $parts, 0, 7 ) );
		if ( $year < 1000 || ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			return null;
		}

		// The time zone: `Z`, or `+hh:mm` / `-hh:mm`.
		$zone   = $parts[7];
		$offset = 0;
		if ( 'Z' !== $zone ) {
			$offset_hours   = (int) substr( $zone, 1, 2 );
			$offset_minutes = (int) substr( $zone, 4, 2 );
			if ( $offset_hours > self::MAX_OFFSET_HOURS || $offset_minutes > 59 ) {
				return null;
			}
			$offset = ( '-' === $zone[0] ? -1 : 1 ) * ( $offset_hours * 3600 + $offset_minutes * 60 );
		}

		$utc = gmdate( 'Y-m-d H:i:s', gmmktime( $hour, $minute, $second, $month, $day, $year ) - $offset );

		// The columns of WordPress hold years from 1000 to 9999.
		return $utc >= '1000-01-01 00:00:00' && $utc <= '9999-12-31 23:59:59' ? $utc : null;
	}

	/**
	 * Whether the interval of the modified date can contain nothing: `after` equal to or later than `before`.
	 *
	 * @param string|null $after  `modified_after` in UTC, or null.
	 * @param string|null $before `modified_before` in UTC, or null.
	 * @return bool
	 */
	public static function empty_interval( ?string $after, ?string $before ): bool {
		return null !== $after && null !== $before && $after >= $before;
	}

	/**
	 * Clauses of a `date_query` of WordPress for the given filters.
	 *
	 * @param array<string, string> $dates Filter => date in UTC (`Y-m-d H:i:s`), only the filters of the request.
	 * @return array<int|string, mixed> Empty without filters.
	 */
	public static function date_query( array $dates ): array {
		$clauses = array();

		foreach ( self::FILTERS as $name => $filter ) {
			if ( ! isset( $dates[ $name ] ) ) {
				continue;
			}
			$clauses[] = array(
				'column'         => $filter['column'],
				$filter['bound'] => $dates[ $name ],
				'inclusive'      => 'after' === $filter['bound'],
			);
		}

		return array() === $clauses ? array() : array_merge( array( 'relation' => 'AND' ), $clauses );
	}
}
