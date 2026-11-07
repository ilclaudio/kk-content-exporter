<?php
/**
 * Plugin Name:       KK Content Exporter
 * Plugin URI:        https://github.com/ilclaudio/kk-content-exporter/
 * Description:       Exposes published content of a chosen post type as configurable, read-only JSON feeds.
 * Version:           0.0.1
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            IoClaudio
 * Author URI:        https://www.claudiobattaglino.it
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       kk-content-exporter
 * Domain Path:       /languages
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

define( 'KKCE_VERSION', '0.0.1' );
define( 'KKCE_PLUGIN_FILE', __FILE__ );
define( 'KKCE_PLUGIN_DIR', __DIR__ . '/' );

require_once KKCE_PLUGIN_DIR . 'includes/class-autoloader.php';

Autoloader::register();

register_activation_hook( KKCE_PLUGIN_FILE, array( Plugin::class, 'activate' ) );
register_deactivation_hook( KKCE_PLUGIN_FILE, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
