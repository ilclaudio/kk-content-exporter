<?php
/**
 * Removal of the plugin data when the plugin is deleted.
 *
 * @package KKCE
 */

namespace KKCE;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes what the plugin stores, but only when the administrator turned on "Delete all the data of
 * the plugin when the plugin is deleted" in the settings.
 *
 * Called by `uninstall.php`, the only place where data is removed. The plugin is not loaded at that
 * time, so the post type of the feeds is not registered: the feeds are found and deleted by their
 * post type name. A setting that is missing or damaged means "keep the data".
 */
final class Uninstaller {

	/**
	 * Feeds deleted in one round, so that a site with many feeds does not load them all at once.
	 */
	private const BATCH = 100;

	/**
	 * Removes the feeds with their meta, the settings and the capability, if the setting asks for it.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! Settings::get()['remove_data'] ) {
			return;
		}

		self::delete_feeds();
		delete_option( Settings::OPTION );
		self::remove_capability();
	}

	/**
	 * Deletes every feed, in every status (the trash included), with its meta.
	 *
	 * @return void
	 */
	private static function delete_feeds(): void {
		do {
			$feeds = get_posts(
				array(
					'post_type'     => Feed_Post_Type::POST_TYPE,
					'post_status'   => get_post_stati(),
					'numberposts'   => self::BATCH,
					'fields'        => 'ids',
					'no_found_rows' => true,
				)
			);

			$deleted = 0;
			foreach ( $feeds as $feed_id ) {
				// Returns the deleted post, or false or null when it could not be deleted.
				if ( wp_delete_post( $feed_id, true ) ) {
					++$deleted;
				}
			}
			// A round that deleted nothing would repeat forever.
		} while ( array() !== $feeds && $deleted > 0 );
	}

	/**
	 * Removes the export capability from every role and from every user who received it directly.
	 *
	 * @return void
	 */
	private static function remove_capability(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( Plugin::READ_EXPORT_CAPABILITY );
		}

		$user_ids = get_users(
			array(
				'capability' => Plugin::READ_EXPORT_CAPABILITY,
				'fields'     => 'ID',
			)
		);

		foreach ( $user_ids as $user_id ) {
			( new WP_User( (int) $user_id ) )->remove_cap( Plugin::READ_EXPORT_CAPABILITY );
		}
	}
}
