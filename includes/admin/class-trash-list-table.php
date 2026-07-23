<?php
/**
 * Trash list table: attachments soft-deleted and awaiting restore or deletion.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro\Admin;

use UsedMediaPro\Trash;
use WP_List_Table;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists trashed attachments with restore and permanent-delete actions.
 */
class Trash_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'trashed_item',
				'plural'   => 'trashed',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'thumb'      => __( 'Preview', 'used-media-pro' ),
			'title'      => __( 'File', 'used-media-pro' ),
			'trashed_at' => __( 'Trashed', 'used-media-pro' ),
			'trashed_by' => __( 'By', 'used-media-pro' ),
			'filesize'   => __( 'Size', 'used-media-pro' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'restore' => __( 'Restore', 'used-media-pro' ),
			'purge'   => __( 'Delete permanently', 'used-media-pro' ),
		);
	}

	/**
	 * Build the query and populate items.
	 */
	public function prepare_items() {
		$per_page = 30;
		$paged    = $this->get_pagenum();

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'orderby'        => 'meta_value',
				'meta_key'       => Trash::META_AT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Trash::META_FLAG,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$this->items = $query->posts;
		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Checkbox column.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="media[]" value="%d" />', (int) $item->ID );
	}

	/**
	 * Preview column.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_thumb( $item ) {
		return wp_get_attachment_image( $item->ID, array( 60, 60 ), true, array( 'class' => 'ump-thumb' ) );
	}

	/**
	 * File column with restore / delete-permanently row actions.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_title( $item ) {
		$title = get_the_title( $item->ID );
		if ( '' === $title ) {
			$title = __( '(no title)', 'used-media-pro' );
		}

		$base = add_query_arg( 'tab', 'trash', menu_page_url( 'used-media-pro', false ) );

		$restore_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'restore',
					'attachment' => $item->ID,
				),
				$base
			),
			'umedia-restore-' . $item->ID
		);
		$purge_url   = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'purge',
					'attachment' => $item->ID,
				),
				$base
			),
			'umedia-purge-' . $item->ID
		);

		$actions = array(
			'restore' => '<a href="' . esc_url( $restore_url ) . '">' . esc_html__( 'Restore', 'used-media-pro' ) . '</a>',
			'purge'   => '<a href="' . esc_url( $purge_url ) . '" class="ump-confirm-purge" style="color:#b32d2e;">' . esc_html__( 'Delete permanently', 'used-media-pro' ) . '</a>',
		);

		return '<strong>' . esc_html( $title ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * When the item was trashed.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_trashed_at( $item ) {
		$at = get_post_meta( $item->ID, Trash::META_AT, true );
		if ( ! $at ) {
			return '&mdash;';
		}
		$reason = get_post_meta( $item->ID, Trash::META_REASON, true );
		$label  = 'no_references' === $reason ? __( 'no references', 'used-media-pro' ) : __( 'manual', 'used-media-pro' );
		return esc_html( $at ) . '<br><span class="ump-ctx">(' . esc_html( $label ) . ')</span>';
	}

	/**
	 * Who trashed the item.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_trashed_by( $item ) {
		$uid  = (int) get_post_meta( $item->ID, Trash::META_BY, true );
		$user = $uid ? get_userdata( $uid ) : false;
		return $user ? esc_html( $user->display_name ) : '&mdash;';
	}

	/**
	 * File size column.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_filesize( $item ) {
		$path = get_attached_file( $item->ID );
		if ( $path && file_exists( $path ) ) {
			return esc_html( size_format( (int) filesize( $path ) ) );
		}
		return '&mdash;';
	}

	/**
	 * Fallback column.
	 *
	 * @param \WP_Post $item        Attachment post.
	 * @param string   $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		esc_html_e( 'The trash is empty. Items you move to the trash from the Library tab appear here, where you can restore them or delete them permanently.', 'used-media-pro' );
	}
}
