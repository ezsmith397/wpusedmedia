<?php
/**
 * External-images list table: unique off-site URLs with import/undo actions.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro\Admin;

use UsedMediaPro\External;
use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists unique external image URLs found by the scan, grouped, with per-URL
 * import and undo controls (wired to AJAX in admin.js).
 */
class External_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'external_image',
				'plural'   => 'external',
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
			'preview' => __( 'Preview', 'used-media-pro' ),
			'url'     => __( 'External URL', 'used-media-pro' ),
			'refs'    => __( 'References', 'used-media-pro' ),
			'status'  => __( 'Status', 'used-media-pro' ),
			'actions' => __( 'Action', 'used-media-pro' ),
		);
	}

	/**
	 * Current status filter.
	 *
	 * @return string
	 */
	private function current_filter() {
		// Read-only display filter from the URL; no state change, so no nonce.
		$status = isset( $_GET['estatus'] ) ? sanitize_key( wp_unslash( $_GET['estatus'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $status, array( 'all', 'working', 'broken', 'imported', 'unchecked' ), true ) ? $status : 'all';
	}

	/**
	 * Working / Broken / Imported filter links with counts.
	 *
	 * @return array
	 */
	protected function get_views() {
		$stats   = External::stats();
		$current = $this->current_filter();
		$base    = remove_query_arg( array( 'estatus', 'paged' ) );

		$make = function ( $key, $label, $count ) use ( $base, $current ) {
			$url   = 'all' === $key ? $base : add_query_arg( 'estatus', $key, $base );
			$class = $current === $key ? ' class="current"' : '';
			return sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), (int) $count );
		};

		$views = array(
			'all'     => $make( 'all', __( 'All', 'used-media-pro' ), $stats['all'] ),
			'working' => $make( 'working', __( 'Working', 'used-media-pro' ), $stats['ok'] ),
			'broken'  => $make( 'broken', __( 'Broken', 'used-media-pro' ), $stats['broken'] ),
		);
		if ( $stats['imported'] ) {
			$views['imported'] = $make( 'imported', __( 'Imported', 'used-media-pro' ), $stats['imported'] );
		}
		if ( $stats['found'] ) {
			$views['unchecked'] = $make( 'unchecked', __( 'Unchecked', 'used-media-pro' ), $stats['found'] );
		}
		return $views;
	}

	/**
	 * Populate items from the grouped scan results.
	 */
	public function prepare_items() {
		$per_page = 25;
		$paged    = $this->get_pagenum();
		$data     = External::grouped( $per_page, $paged, $this->current_filter() );

		$this->items = $data['items'];
		$this->set_pagination_args(
			array(
				'total_items' => (int) $data['total'],
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Preview column: the external image, or the local thumbnail once imported.
	 *
	 * @param object $item Grouped row.
	 * @return string
	 */
	public function column_preview( $item ) {
		if ( 'imported' === $item->status && $item->new_attachment_id ) {
			return wp_get_attachment_image( (int) $item->new_attachment_id, array( 60, 60 ), true, array( 'class' => 'ump-thumb' ) );
		}
		return '<img src="' . esc_url( $item->url ) . '" class="ump-thumb" loading="lazy" referrerpolicy="no-referrer" alt="" />';
	}

	/**
	 * URL column.
	 *
	 * @param object $item Grouped row.
	 * @return string
	 */
	public function column_url( $item ) {
		$display = strlen( $item->url ) > 70 ? substr( $item->url, 0, 67 ) . '&hellip;' : $item->url;
		return '<a href="' . esc_url( $item->url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $display ) . '</code></a>';
	}

	/**
	 * Reference-count column.
	 *
	 * @param object $item Grouped row.
	 * @return string
	 */
	public function column_refs( $item ) {
		return '<strong>' . (int) $item->refs . '</strong>';
	}

	/**
	 * Status column.
	 *
	 * @param object $item Grouped row.
	 * @return string
	 */
	public function column_status( $item ) {
		$detail = $item->message ? '<br><span class="ump-ctx">' . esc_html( $item->message ) . '</span>' : '';
		switch ( $item->status ) {
			case 'imported':
				return '<span style="color:#2271b1;">' . esc_html__( 'Imported', 'used-media-pro' ) . '</span>';
			case 'ok':
				return '<span style="color:#1a7f37;font-weight:600;">' . esc_html__( 'Working', 'used-media-pro' ) . '</span>';
			case 'broken':
				return '<span class="ump-unused" style="font-weight:600;">' . esc_html__( 'Broken', 'used-media-pro' ) . '</span>' . $detail;
			case 'failed':
				return '<span class="ump-unused">' . esc_html__( 'Failed', 'used-media-pro' ) . '</span>' . $detail;
			default:
				return '<span class="ump-ctx">' . esc_html__( 'Unchecked', 'used-media-pro' ) . '</span>';
		}
	}

	/**
	 * Action column: import / undo buttons (handled by admin.js).
	 *
	 * @param object $item Grouped row.
	 * @return string
	 */
	public function column_actions( $item ) {
		$hash = esc_attr( $item->url_hash );
		if ( 'imported' === $item->status ) {
			$edit = $item->new_attachment_id ? get_edit_post_link( (int) $item->new_attachment_id ) : '';
			$link = $edit ? '<a href="' . esc_url( $edit ) . '">#' . (int) $item->new_attachment_id . '</a> ' : '';
			return $link . '<button type="button" class="button ump-undo" data-hash="' . $hash . '">' . esc_html__( 'Undo', 'used-media-pro' ) . '</button>';
		}
		if ( 'broken' === $item->status ) {
			return '<span class="ump-ctx">' . esc_html__( 'Not importable', 'used-media-pro' ) . '</span>';
		}
		return '<button type="button" class="button button-primary ump-import" data-hash="' . $hash . '">' . esc_html__( 'Import & re-attach', 'used-media-pro' ) . '</button>';
	}

	/**
	 * Fallback column.
	 *
	 * @param object $item        Grouped row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		esc_html_e( 'No external images found. Every image referenced in your content is hosted locally (or on a trusted domain).', 'used-media-pro' );
	}
}
