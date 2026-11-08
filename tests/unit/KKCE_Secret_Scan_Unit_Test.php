<?php
/**
 * Contract test of the secret scan of the pre-commit hook (.githooks/check-staged-secrets.sh).
 *
 * The script is run through Bash in its `--stdin` mode, so the real `grep -E` patterns are tested,
 * not a copy of them. The secret values are invented, and every fixture is assembled from pieces so
 * that this file does not contain a line that the scan itself would block.
 *
 * @package KKCE
 */

/**
 * Lines with a secret block the commit; ordinary lines do not.
 */
class KKCE_Secret_Scan_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Runs the secret scan on one line.
	 *
	 * @param string $line Line to scan.
	 * @return array{0: int, 1: string} Exit code and standard error.
	 */
	private function scan( string $line ): array {
		// Relative path, run from the plugin root: on Windows `bash` can be the WSL one, which does not
		// understand `C:/...` paths.
		$process = proc_open(
			array( 'bash', '.githooks/check-staged-secrets.sh', '--stdin' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			KKCE_TEST_PLUGIN_DIR
		);
		$this->assertIsResource( $process, 'Bash is needed to run the Git hooks and this test.' );

		fwrite( $pipes[0], $line . "\n" );
		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array( proc_close( $process ), $stderr );
	}

	/**
	 * A line with a secret blocks the commit, and the value is never printed.
	 *
	 * @dataProvider secret_provider
	 *
	 * @param string $line Line with an invented secret.
	 */
	public function test_a_line_with_a_secret_is_blocked( string $line ) {
		list( $code, $stderr ) = $this->scan( $line );

		$this->assertSame( 1, $code, 'Not blocked: ' . $line );
		$this->assertStringContainsString( 'added line 1', $stderr );
		$this->assertStringNotContainsString( 'FAKE', $stderr, 'The secret value must never be printed.' );
	}

	/**
	 * Lines with invented secrets, one per supported format.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function secret_provider(): array {
		$pw = 'pass' . 'word';

		return array(
			'single-quoted password'   => array( '$' . $pw . " = 'FAKEfakeFAKE1234';" ),
			'double-quoted password'   => array( '$' . $pw . ' = "FAKEfakeFAKE1234";' ),
			'single-quoted api key'    => array( "'api" . "_key' => 'FAKEfakeFAKE1234'," ),
			'WordPress DB password'    => array( "define( 'DB_" . "PASSWORD', 'FAKEfakeFAKE1234' );" ),
			'WordPress secret key'     => array( "define( 'AUTH_" . "KEY', 'FAKEfakeFAKE1234+-/' );" ),
			'Application Password'     => array( implode( ' ', array( 'FAKE', 'ab12', 'CD34', 'ef56', 'GH78', 'ij90' ) ) ),
			'Basic authorization'      => array( 'Author' . 'ization: Basic RkFLRTpGQUtFRkFLRUZBS0VGQUtF' ),
			'Anthropic key'            => array( 'sk-' . 'ant-api03-FAKEfakeFAKEfakeFAKEfake0000' ),
			'private key'              => array( '-----BEGIN ' . 'RSA PRIVATE KEY-----FAKE' ),
			'GitHub token'             => array( 'gh' . 'p_FAKE' . str_repeat( 'a', 36 ) ),
			'credentials in a URL'     => array( 'https://' . 'FAKEuser:FAKEpass' . '@example.com/' ),
		);
	}

	/**
	 * Ordinary lines do not block the commit.
	 *
	 * @dataProvider clean_provider
	 *
	 * @param string $line Line without a secret.
	 */
	public function test_an_ordinary_line_is_not_blocked( string $line ) {
		list( $code ) = $this->scan( $line );

		$this->assertSame( 0, $code, 'Blocked by mistake: ' . $line );
	}

	/**
	 * Lines that look close to a secret but are not one.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function clean_provider(): array {
		$pw = 'pass' . 'word';

		return array(
			'empty DB password'        => array( "define( 'DB_" . "PASSWORD', '' );" ),
			'password read at runtime' => array( '$' . $pw . " = get_option( 'kkce_option' );" ),
			'documentation sentence'   => array( 'Authenticated feeds use Application Passwords over HTTPS.' ),
			'six short words'          => array( 'when this test runs from here' ),
			'plugin code'              => array( "\$title = 'Latest News';" ),
			'per page setting'         => array( "'per_page' => 50," ),
		);
	}
}
