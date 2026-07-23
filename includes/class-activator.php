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
 * Handles plugin activation and schema upgrades.
 */
class Activator {

	/**
	 * Current DB schema version.
	 */
	const DB_VERSION = '2';

	/**
	 * Create/upgrade tables on activation.
	 */
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$usage           = $wpdb->prefix . 'umedia_usage';
		$external        = $wpdb->prefix . 'umedia_external';

		$usage_sql = "CREATE TABLE {$usage} (
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

		$external_sql = "CREATE TABLE {$external} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id VARCHAR(50) NOT NULL DEFAULT '',
			object_id BIGINT UNSIGNED NOT NULL,
			url TEXT NOT NULL,
			url_hash CHAR(40) NOT NULL DEFAULT '',
			context VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'found',
			new_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			message VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY url_hash (url_hash),
			KEY object_id (object_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $usage_sql );
		dbDelta( $external_sql );

		update_option( 'umedia_db_version', self::DB_VERSION );
	}

	/**
	 * Run the schema routine when the stored version is behind the code. Lets
	 * existing installs pick up new tables without a manual re-activation.
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( 'umedia_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}
}
