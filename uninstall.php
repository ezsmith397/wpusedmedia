<?php
/**
 * Uninstall cleanup: drop custom tables and options.
 *
 * @package UsedMediaPro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'ump_usage';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

$options = array(
	'ump_settings',
	'ump_db_version',
	'ump_index_built',
	'ump_index_last_built',
	'ump_rebuild_state',
);
foreach ( $options as $option ) {
	delete_option( $option );
}
