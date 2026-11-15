/**
 * Paths shared by the configuration and the setup of the E2E suite.
 *
 * @package KKCE
 */

const path = require( 'node:path' );

module.exports = {
	// Session of the administrator of the disposable environment (public wp-env credentials, no real data).
	AUTH_FILE: path.resolve( __dirname, '..', '.auth', 'admin.json' ),
};
