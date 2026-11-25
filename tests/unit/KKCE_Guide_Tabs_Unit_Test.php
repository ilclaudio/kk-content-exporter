<?php
/**
 * Unit tests of the choice of the tab of the guide (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Admin\Guide_Tabs;

/**
 * A request shows one of the known tabs, the first one when the value is missing or unknown.
 */
class KKCE_Guide_Tabs_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * The guide has one tab per rule, between the overview and the output types and preview.
	 */
	public function test_the_tabs_and_their_order() {
		$this->assertSame(
			array( 'overview', 'field', 'fixed', 'template', 'lookup', 'condition', 'output', 'preview' ),
			Guide_Tabs::TABS
		);
	}

	/**
	 * Every known tab is shown when it is asked.
	 */
	public function test_a_known_tab_is_shown() {
		foreach ( Guide_Tabs::TABS as $tab ) {
			$this->assertSame( $tab, Guide_Tabs::choose( $tab ) );
		}
	}

	/**
	 * A missing, unknown or malformed value shows the overview.
	 */
	public function test_other_values_show_the_overview() {
		foreach ( array( null, '', 'unknown', 'LOOKUP', ' lookup', array( 'lookup' ), 3, true ) as $value ) {
			$this->assertSame( 'overview', Guide_Tabs::choose( $value ), var_export( $value, true ) );
		}
	}
}
