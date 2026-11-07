<?php
/**
 * PHPStan bootstrap: runtime constants that the analysis cannot infer.
 *
 * The plugin constants are defined in kk-content-exporter.php when WordPress loads the plugin;
 * PHPStan does not execute that file, so they are declared here with their types.
 *
 * @package KKCE
 */

define( 'KKCE_VERSION', '0.0.0' );
define( 'KKCE_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/kk-content-exporter.php' );
define( 'KKCE_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
