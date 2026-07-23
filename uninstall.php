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

// Restore any attachments still in the trash back to their previous status,
// so they don't get stranded in an unregistered status once the plugin
// (which registers 'umedia_trashed') is gone.
$wpdb->query(
	"UPDATE {$wpdb->posts} p
	JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_umedia_trash_prev_status'
	SET p.post_status = m.meta_value
	WHERE p.post_status = 'umedia_trashed'"
);
// Any left in the trashed status without prev-status meta fall back to inherit.
$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'inherit' WHERE post_type = 'attachment' AND post_status = 'umedia_trashed'" );
foreach ( array( '_umedia_trash_prev_status', '_umedia_trashed_at', '_umedia_trashed_by', '_umedia_trashed_reason' ) as $umedia_meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $umedia_meta_key ) );
}

$umedia_table = $wpdb->prefix . 'umedia_usage';
$wpdb->query( "DROP TABLE IF EXISTS {$umedia_table}" );

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
