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

// This whole file is one-time teardown of the plugin's own data via direct
// $wpdb calls (trusted table names, no caching needed, intentional schema
// change). Disable the WordPress.DB checks for the teardown section.
// phpcs:disable WordPress.DB

// Remove the plugin's trash flags. Attachments themselves are never touched;
// trashing only ever added postmeta, so clearing it fully restores them.
// '_umedia_trash_prev_status' is a legacy key from an earlier status-based
// approach and is cleared here too.
foreach ( array( '_umedia_trashed', '_umedia_trashed_at', '_umedia_trashed_by', '_umedia_trashed_reason', '_umedia_trash_prev_status' ) as $umedia_meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $umedia_meta_key ) );
}

foreach ( array( $wpdb->prefix . 'umedia_usage', $wpdb->prefix . 'umedia_external' ) as $umedia_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$umedia_table}" );
}

$umedia_options = array(
	'umedia_settings',
	'umedia_db_version',
	'umedia_index_built',
	'umedia_index_last_built',
	'umedia_rebuild_state',
	'umedia_external_scanned',
	'umedia_extscan_state',
	'umedia_extcheck_state',
);
foreach ( $umedia_options as $umedia_option ) {
	delete_option( $umedia_option );
}
