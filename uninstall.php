<?php
/**
 * Uninstall routine of the plugin.
 *
 * Runs only when an administrator deletes the plugin from the Plugins screen. Nothing is removed
 * yet: the feeds (post type `kkce_feed` and their meta) and the capability `kkce_read_export` stay
 * on the site until the optional removal of the configuration is implemented.
 *
 * @package KKCE
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
