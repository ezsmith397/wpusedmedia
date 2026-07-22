<?php
/**
 * The usage index: which objects reference which attachments.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/write access to the ump_usage table.
 */
class Usage_Index {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ump_usage';
	}

	/**
	 * Whether a full index has been built at least once.
	 *
	 * @return bool
	 */
	public static function is_built() {
		return (bool) get_option( 'ump_index_built', 0 );
	}

	/**
	 * Timestamp (mysql, local) of the last completed build.
	 *
	 * @return string
	 */
	public static function last_built() {
		return (string) get_option( 'ump_index_last_built', '' );
	}

	/**
	 * Empty the index.
	 */
	public static function truncate() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Bulk-insert reference rows for one source.
	 *
	 * @param string $source_id  Source adapter id.
	 * @param array  $references Rows of {attachment_id, object_id, context}.
	 */
	public static function insert_refs( $source_id, array $references ) {
		global $wpdb;
		if ( empty( $references ) ) {
			return;
		}

		$table        = self::table();
		$placeholders = array();
		$values       = array();

		foreach ( $references as $ref ) {
			if ( empty( $ref['attachment_id'] ) ) {
				continue;
			}
			$placeholders[] = '(%d,%s,%d,%s)';
			$values[]       = (int) $ref['attachment_id'];
			$values[]       = $source_id;
			$values[]       = (int) $ref['object_id'];
			$values[]       = isset( $ref['context'] ) ? (string) $ref['context'] : '';
		}

		if ( empty( $placeholders ) ) {
			return;
		}

		$sql = "INSERT INTO {$table} (attachment_id, source_id, object_id, context) VALUES " . implode( ',', $placeholders );
		$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Number of distinct objects that reference an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return int
	 */
	public static function count_for( $attachment_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT object_id) FROM {$table} WHERE attachment_id = %d", $attachment_id )
		);
	}

	/**
	 * The distinct object/context rows referencing an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<int,object> Rows with object_id, context.
	 */
	public static function objects_for( $attachment_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT object_id, context FROM {$table} WHERE attachment_id = %d ORDER BY object_id ASC",
				$attachment_id
			)
		);
	}

	/**
	 * Distinct attachment ids that are referenced somewhere.
	 *
	 * @return int[]
	 */
	public static function used_attachment_ids() {
		global $wpdb;
		$table = self::table();
		return array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$table}" ) );
	}
}
