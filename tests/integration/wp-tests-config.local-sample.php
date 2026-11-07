<?php
/**
 * Sample of the local connection settings of the integration suite.
 *
 * Copy it to wp-tests-config.local.php (ignored by Git) and set the values of the MariaDB/MySQL
 * server that hosts the empty test database `kkce_wordpress_test`. With Local, the port is in the
 * Database tab of the site. Environment variables KKCE_TEST_DB_* take precedence over this file.
 *
 * @package KKCE
 */

return array(
	'host'     => '127.0.0.1:10000',
	'user'     => 'root',
	'password' => 'root',
);
