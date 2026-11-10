<?php
/**
 * Unit tests of the recognition of sensitive names (pure logic, no WordPress, no mocks).
 *
 * @package KKCE
 */

use KKCE\Output\Sensitive_Keys;

/**
 * A name is sensitive when one of its words, or two adjacent words, are in the list.
 */
class KKCE_Sensitive_Keys_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Names that look like secrets or personal data.
	 *
	 * @dataProvider sensitive_provider
	 *
	 * @param string $name Name.
	 */
	public function test_sensitive_names( string $name ) {
		$this->assertTrue( Sensitive_Keys::is_sensitive( $name ), $name );
	}

	/**
	 * Data for test_sensitive_names().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function sensitive_provider(): array {
		$names = array(
			'password',
			'user_pass',
			'api_key',
			'apiKey',
			'APIKey',
			'API_KEY',
			'apikey',
			'auth_token',
			'session_tokens',
			'wp_capabilities',
			'user_level',
			'user_email',
			'user_login',
			'secret',
			'private_key',
			'nonce',
			'salt',
			'_ticket_api_key',
			'stripe-secret-key',
			'access.key',
			'activation_key',
			'pwd',
			'passwd',
			'password_hash',
			'credentials',
			'organizer_email',
			// Borderline names, sensitive on purpose (decision of 2026-11-09): only a warning follows.
			'token_count',
			'email_subject',
			'max_tokens',
			'login_count',
		);

		return array_combine( $names, array_map( static fn( $name ) => array( $name ), $names ) );
	}

	/**
	 * Names whose words are not in the list, even if a sensitive word is inside a longer one.
	 *
	 * @dataProvider plain_provider
	 *
	 * @param string $name Name.
	 */
	public function test_plain_names( string $name ) {
		$this->assertFalse( Sensitive_Keys::is_sensitive( $name ), $name );
	}

	/**
	 * Data for test_plain_names().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function plain_provider(): array {
		$names = array(
			'keyword',
			'passage',
			'description',
			'sort_key',
			'event_key',
			'difficulty_level',
			'title',
			'_price',
			'api',
			'key',
			'compass',
			'passenger_count',
			'saltwater',
			'tokenizer',
			'',
		);

		return array_combine(
			array_map( static fn( $name ) => '' === $name ? '(empty)' : $name, $names ),
			array_map( static fn( $name ) => array( $name ), $names )
		);
	}

	/**
	 * A source identifier is judged by its name, never by its group.
	 */
	public function test_sources_are_judged_by_their_name() {
		$this->assertTrue( Sensitive_Keys::is_sensitive_source( 'meta._ticket_api_key' ) );
		$this->assertTrue( Sensitive_Keys::is_sensitive_source( 'acf.organizer_email' ) );
		$this->assertTrue( Sensitive_Keys::is_sensitive_source( 'cmb2.group:password' ) );
		$this->assertFalse( Sensitive_Keys::is_sensitive_source( 'core.title' ) );
		$this->assertFalse( Sensitive_Keys::is_sensitive_source( 'meta._price' ) );
		$this->assertFalse( Sensitive_Keys::is_sensitive_source( 'taxonomy.category' ) );
		$this->assertFalse( Sensitive_Keys::is_sensitive_source( 'no-group' ) );
		$this->assertTrue( Sensitive_Keys::is_sensitive_source( 'core.author.url' ), 'The author archive URL contains the user nicename (decision of 2026-11-10).' );
		$this->assertFalse( Sensitive_Keys::is_sensitive_source( 'core.author.name' ) );
	}
}
