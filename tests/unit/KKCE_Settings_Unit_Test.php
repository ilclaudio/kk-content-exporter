<?php
/**
 * Unit tests of the plugin settings: defaults, safe reading and processing of the form (no WordPress).
 *
 * @package KKCE
 */

use KKCE\Settings;

/**
 * Global settings of the plugin.
 */
class KKCE_Settings_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * The defaults of the settings table of the plan.
	 */
	public function test_defaults() {
		$this->assertSame(
			array(
				'enabled'      => true,
				'require_auth' => false,
				'max_per_page' => 100,
				'remove_data'  => false,
			),
			Settings::defaults()
		);
		$this->assertSame( 'kkce_settings', Settings::OPTION );
	}

	/**
	 * A missing or unusable stored option gives the defaults.
	 *
	 * @dataProvider unusable_option_provider
	 *
	 * @param mixed $stored Stored value.
	 */
	public function test_normalize_gives_the_defaults_for_an_unusable_option( $stored ) {
		$this->assertSame( Settings::defaults(), Settings::normalize( $stored ) );
	}

	/**
	 * Data for test_normalize_gives_the_defaults_for_an_unusable_option().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function unusable_option_provider(): array {
		return array(
			'not stored' => array( false ),
			'null'       => array( null ),
			'string'     => array( 'enabled' ),
			'number'     => array( 5 ),
			'empty'      => array( array() ),
		);
	}

	/**
	 * Valid stored values are kept.
	 */
	public function test_normalize_keeps_valid_values() {
		$stored = array(
			'enabled'      => false,
			'require_auth' => true,
			'max_per_page' => 250,
			'remove_data'  => true,
		);

		$this->assertSame( $stored, Settings::normalize( $stored ) );
		$this->assertSame( 250, Settings::normalize( array( 'max_per_page' => '250' ) )['max_per_page'] );
		$this->assertTrue( Settings::normalize( array( 'remove_data' => '1' ) )['remove_data'] );
	}

	/**
	 * A damaged switch goes to the safe side: endpoints off, authentication required, no data removal.
	 *
	 * @dataProvider damaged_switch_provider
	 *
	 * @param string $key      Setting.
	 * @param mixed  $damaged  Damaged value.
	 * @param bool   $expected Safe value.
	 */
	public function test_a_damaged_switch_goes_to_the_safe_side( string $key, $damaged, bool $expected ) {
		$this->assertSame( $expected, Settings::normalize( array( $key => $damaged ) )[ $key ] );
	}

	/**
	 * Data for test_a_damaged_switch_goes_to_the_safe_side().
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: bool}>
	 */
	public function damaged_switch_provider(): array {
		return array(
			'enabled text'       => array( 'enabled', 'maybe', false ),
			'enabled array'      => array( 'enabled', array( 1 ), false ),
			'auth text'          => array( 'require_auth', 'maybe', true ),
			'auth null'          => array( 'require_auth', null, true ),
			'remove data text'   => array( 'remove_data', 'yes please', false ),
			'remove data number' => array( 'remove_data', 7, false ),
		);
	}

	/**
	 * A damaged maximum goes back to the default; the other values are kept.
	 *
	 * @dataProvider damaged_maximum_provider
	 *
	 * @param mixed $damaged Damaged value.
	 */
	public function test_a_damaged_maximum_goes_back_to_the_default( $damaged ) {
		$result = Settings::normalize( array( 'max_per_page' => $damaged, 'require_auth' => true ) );

		$this->assertSame( 100, $result['max_per_page'] );
		$this->assertTrue( $result['require_auth'] );
	}

	/**
	 * Data for test_a_damaged_maximum_goes_back_to_the_default().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function damaged_maximum_provider(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
			'text'     => array( 'many' ),
			'decimal'  => array( 2.5 ),
			'array'    => array( array( 50 ) ),
			'null'     => array( null ),
		);
	}

	/**
	 * Previous values used by the form tests.
	 *
	 * @return array{enabled: bool, require_auth: bool, max_per_page: int, remove_data: bool}
	 */
	private function previous(): array {
		return array(
			'enabled'      => true,
			'require_auth' => false,
			'max_per_page' => 150,
			'remove_data'  => false,
		);
	}

	/**
	 * A checked box is "1", an unchecked box is not sent: the form is turned into booleans.
	 */
	public function test_process_reads_the_checkboxes_and_the_maximum() {
		$result = Settings::process(
			array(
				'require_auth' => '1',
				'max_per_page' => '250',
			),
			$this->previous()
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array(
				'enabled'      => false,
				'require_auth' => true,
				'max_per_page' => 250,
				'remove_data'  => false,
			),
			$result['values']
		);
	}

	/**
	 * A maximum that is not valid keeps the previous one, reports the error and still saves the rest.
	 *
	 * @dataProvider invalid_maximum_input_provider
	 *
	 * @param string $input    Submitted maximum.
	 * @param string $expected Error code.
	 */
	public function test_process_refuses_an_invalid_maximum( string $input, string $expected ) {
		$result = Settings::process( array( 'enabled' => '1', 'max_per_page' => $input, 'remove_data' => '1' ), $this->previous() );

		$this->assertSame( array( 'max_per_page' => $expected ), $result['errors'] );
		$this->assertSame( 150, $result['values']['max_per_page'] );
		$this->assertTrue( $result['values']['enabled'] );
		$this->assertTrue( $result['values']['remove_data'] );
	}

	/**
	 * Data for test_process_refuses_an_invalid_maximum().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function invalid_maximum_input_provider(): array {
		return array(
			'zero'     => array( '0', 'too_small' ),
			'negative' => array( '-3', 'too_small' ),
			'text'     => array( 'many', 'not_integer' ),
			'decimal'  => array( '2.5', 'not_integer' ),
			'empty'    => array( '', 'not_integer' ),
		);
	}

	/**
	 * There is no upper limit for the maximum: the administrator takes the responsibility.
	 */
	public function test_process_accepts_a_very_high_maximum() {
		$result = Settings::process( array( 'max_per_page' => '100000' ), $this->previous() );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 100000, $result['values']['max_per_page'] );
	}

	/**
	 * A request without the maximum keeps the previous one, with no error.
	 */
	public function test_process_keeps_the_maximum_when_it_is_not_sent() {
		$result = Settings::process( array( 'enabled' => '1' ), $this->previous() );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 150, $result['values']['max_per_page'] );
	}

	/**
	 * Unknown keys are ignored, and a value that is not an array changes nothing.
	 */
	public function test_process_ignores_unknown_keys_and_non_arrays() {
		$result = Settings::process( array( 'enabled' => '1', 'surprise' => 'x' ), $this->previous() );
		$this->assertSame( array( 'enabled', 'require_auth', 'max_per_page', 'remove_data' ), array_keys( $result['values'] ) );

		foreach ( array( 'text', null, 5 ) as $input ) {
			$result = Settings::process( $input, $this->previous() );
			$this->assertSame( $this->previous(), $result['values'] );
			$this->assertSame( array(), $result['errors'] );
		}
	}

	/**
	 * A checkbox is on only for the exact value "1".
	 */
	public function test_process_turns_a_checkbox_on_only_for_one() {
		$result = Settings::process( array( 'enabled' => 'on', 'require_auth' => 'yes', 'remove_data' => '1' ), $this->previous() );

		$this->assertFalse( $result['values']['enabled'] );
		$this->assertFalse( $result['values']['require_auth'] );
		$this->assertTrue( $result['values']['remove_data'] );
	}
}
