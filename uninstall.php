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

$umedia_table = $wpdb->prefix . 'umedia_usage';
$wpdb->query( "DROP TABLE IF EXISTS {$umedia_table}" ); // phpcs:ignore WordPress.DB

$umedia_options = array(
	'umedia_settings',
	'umedia_db_version',
	'umedia_index_built',
	'umedia_index_last_built',
	'umedia_rebuild_state',
);
foreach ( $umedia_options as $umedia_option ) {
	delete_option( $umedia_option );
}
