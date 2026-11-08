<?php
/**
 * Uninstall routine of the plugin.
 *
 * Runs only when an administrator deletes the plugin from the Plugins screen. Nothing is removed
 * unless the setting "Delete all the data of the plugin when the plugin is deleted" is on: then the
 * feeds with their meta, the settings and the capability `kkce_read_export` are deleted. The plugin
 * is not loaded at this point, so the classes are loaded here.
 *
 * @package KKCE
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';

KKCE\Autoloader::register();
KKCE\Uninstaller::run();
