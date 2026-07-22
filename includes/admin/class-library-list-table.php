<?php
/**
 * Enhanced media library list table with a "Used in" column.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro\Admin;

use UsedMediaPro\Usage_Index;
use WP_List_Table;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists media attachments with usage information drawn from the usage index.
 */
class Library_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'attachment',
				'plural'   => 'attachments',
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
			'thumb'      => __( 'Preview', 'used-media-pro' ),
			'title'      => __( 'File', 'used-media-pro' ),
			'used_in'    => __( 'Used in', 'used-media-pro' ),
			'dimensions' => __( 'Dimensions', 'used-media-pro' ),
			'filesize'   => __( 'Size', 'used-media-pro' ),
			'mime'       => __( 'Type', 'used-media-pro' ),
			'date'       => __( 'Uploaded', 'used-media-pro' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title' => array( 'title', false ),
			'date'  => array( 'date', true ),
		);
	}

	/**
	 * Usage filter links (All / Used / No references found).
	 *
	 * @return array
	 */
	protected function get_views() {
		$current = $this->current_usage_filter();
		$base    = remove_query_arg( array( 'usage', 'paged' ) );

		$total  = (int) ( wp_count_posts( 'attachment' )->inherit );
		$used   = count( Usage_Index::used_attachment_ids() );
		$unused = max( 0, $total - $used );

		$make = function ( $key, $label, $count ) use ( $base, $current ) {
			$url   = 'all' === $key ? $base : add_query_arg( 'usage', $key, $base );
			$class = $current === $key ? ' class="current"' : '';
			return sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$class,
				esc_html( $label ),
				(int) $count
			);
		};

		return array(
			'all'    => $make( 'all', __( 'All', 'used-media-pro' ), $total ),
			'used'   => $make( 'used', __( 'Used', 'used-media-pro' ), $used ),
			'unused' => $make( 'unused', __( 'No references found', 'used-media-pro' ), $unused ),
		);
	}

	/**
	 * Current usage filter value.
	 *
	 * @return string
	 */
	private function current_usage_filter() {
		$usage = isset( $_GET['usage'] ) ? sanitize_key( wp_unslash( $_GET['usage'] ) ) : 'all';
		return in_array( $usage, array( 'all', 'used', 'unused' ), true ) ? $usage : 'all';
	}

	/**
	 * Build the query and populate items.
	 */
	public function prepare_items() {
		$per_page = 30;
		$paged    = $this->get_pagenum();

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date';
		$order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';
		$orderby = in_array( $orderby, array( 'title', 'date' ), true ) ? $orderby : 'date';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => $orderby,
			'order'          => $order,
		);

		if ( ! empty( $_REQUEST['s'] ) ) {
			$args['s'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}

		$usage = $this->current_usage_filter();
		if ( 'used' === $usage ) {
			$ids                = Usage_Index::used_attachment_ids();
			$args['post__in']   = ! empty( $ids ) ? $ids : array( 0 );
		} elseif ( 'unused' === $usage ) {
			$ids = Usage_Index::used_attachment_ids();
			if ( ! empty( $ids ) ) {
				$args['post__not_in'] = $ids;
			}
		}

		$query = new WP_Query( $args );

		$this->items = $query->posts;
		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Preview thumbnail column.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_thumb( $item ) {
		return wp_get_attachment_image( $item->ID, array( 60, 60 ), true, array( 'class' => 'ump-thumb' ) );
	}

	/**
	 * File / title column with row actions.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_title( $item ) {
		$edit  = get_edit_post_link( $item->ID );
		$title = get_the_title( $item->ID );
		if ( '' === $title ) {
			$title = __( '(no title)', 'used-media-pro' );
		}

		$name = $edit
			? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>'
			: esc_html( $title );

		$actions = array(
			'id' => sprintf( 'ID: %d', $item->ID ),
		);
		if ( $edit ) {
			$actions['edit'] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'used-media-pro' ) . '</a>';
		}
		$file_url = wp_get_attachment_url( $item->ID );
		if ( $file_url ) {
			$actions['view'] = '<a href="' . esc_url( $file_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View file', 'used-media-pro' ) . '</a>';
		}

		return '<strong>' . $name . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * "Used in" column — the plugin's whole reason for existing.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_used_in( $item ) {
		if ( ! Usage_Index::is_built() ) {
			return '<em>' . esc_html__( 'Index not built', 'used-media-pro' ) . '</em>';
		}

		$count = Usage_Index::count_for( $item->ID );
		if ( ! $count ) {
			return '<span class="ump-unused">&mdash; ' . esc_html__( 'No references found', 'used-media-pro' ) . '</span>';
		}

		$objects = Usage_Index::objects_for( $item->ID );
		$links   = array();
		$shown   = 0;
		foreach ( $objects as $object ) {
			if ( $shown >= 5 ) {
				$links[] = '&hellip;';
				break;
			}
			$title = get_the_title( $object->object_id );
			if ( '' === $title ) {
				$title = '#' . (int) $object->object_id;
			}
			$edit  = get_edit_post_link( $object->object_id );
			$label = esc_html( $title ) . ' <span class="ump-ctx">(' . esc_html( $object->context ) . ')</span>';
			$links[] = $edit ? '<a href="' . esc_url( $edit ) . '">' . $label . '</a>' : $label;
			$shown++;
		}

		return '<strong class="ump-count">' . (int) $count . '</strong>'
			. '<div class="ump-usage-list">' . implode( '<br>', $links ) . '</div>';
	}

	/**
	 * Dimensions column.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_dimensions( $item ) {
		$meta = wp_get_attachment_metadata( $item->ID );
		if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			return (int) $meta['width'] . ' &times; ' . (int) $meta['height'];
		}
		return '&mdash;';
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
	 * Mime type column.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_mime( $item ) {
		return esc_html( get_post_mime_type( $item->ID ) ?: '&mdash;' );
	}

	/**
	 * Upload date column.
	 *
	 * @param \WP_Post $item Attachment post.
	 * @return string
	 */
	public function column_date( $item ) {
		return esc_html( get_the_date( '', $item->ID ) );
	}

	/**
	 * Fallback column renderer.
	 *
	 * @param \WP_Post $item        Attachment post.
	 * @param string   $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Message when there are no items.
	 */
	public function no_items() {
		esc_html_e( 'No media found.', 'used-media-pro' );
	}
}
