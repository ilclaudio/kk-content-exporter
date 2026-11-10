<?php
/**
 * Description of a source that a feed can export.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

use InvalidArgumentException;
use KKCE\Output\Mapping;
use KKCE\Output\Sensitive_Keys;

defined( 'ABSPATH' ) || exit;

/**
 * A field that the mapping can read: a core field, a post meta, a taxonomy, an ACF or CMB2 field.
 *
 * Built by a provider, which also reads its values. Pure value object: no WordPress function is called.
 * The identifier is the one written in the mapping (`group.name`); the same identifier can exist for
 * several post types (`meta._price` of products and of events), and the mapping never merges them: it
 * names the post type of every source.
 */
final class Source {

	/**
	 * Builds the description of a source.
	 *
	 * @param string   $id        Identifier written in the mapping (`group.name`, group among `Mapping::SOURCE_GROUPS`).
	 * @param string   $label     Label shown to the administrator.
	 * @param string   $post_type Post type the source belongs to; empty for a source common to every post type.
	 * @param string   $type      Declared type of the value, among `Mapping::TYPES` except `auto`.
	 * @param string[] $options   Names of the options the source accepts.
	 *
	 * @phpstan-param list<string> $options
	 *
	 * @throws InvalidArgumentException When the identifier or the type are not valid: a programming error.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $post_type,
		public readonly string $type,
		public readonly array $options = array()
	) {
		$parts = explode( '.', $id, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[1] || ! in_array( $parts[0], Mapping::SOURCE_GROUPS, true ) ) {
			throw new InvalidArgumentException( 'Invalid source identifier.' );
		}

		if ( 'auto' === $type || ! in_array( $type, Mapping::TYPES, true ) ) {
			throw new InvalidArgumentException( 'Invalid declared type of a source.' );
		}
	}

	/**
	 * Group of the source: the first part of the identifier.
	 *
	 * @return string
	 */
	public function group(): string {
		return explode( '.', $this->id, 2 )[0];
	}

	/**
	 * Whether the source has the same meaning for every post type (the core fields).
	 *
	 * @return bool
	 */
	public function is_common(): bool {
		return '' === $this->post_type;
	}

	/**
	 * Whether the mapping must confirm the source before it is exported (sensitive name, author archive URL).
	 *
	 * @return bool
	 */
	public function needs_confirmation(): bool {
		return Sensitive_Keys::is_sensitive_source( $this->id );
	}
}
