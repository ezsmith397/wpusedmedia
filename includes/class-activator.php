<?php
/**
 * Activation routines: create custom tables.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation.
 */
class Activator {

	/**
	 * Current DB schema version.
	 */
	const DB_VERSION = '1';

	/**
	 * Create/upgrade tables on activation.
	 */
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$usage           = $wpdb->prefix . 'ump_usage';

		$sql = "CREATE TABLE {$usage} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			source_id VARCHAR(50) NOT NULL DEFAULT '',
			object_id BIGINT UNSIGNED NOT NULL,
			context VARCHAR(50) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY object_id (object_id),
			KEY source_id (source_id)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'ump_db_version', self::DB_VERSION );
	}
}
