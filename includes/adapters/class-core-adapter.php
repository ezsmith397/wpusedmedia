<?php
/**
 * Core adapter: classic + block editor content, featured images, reusable
 * blocks, and FSE templates.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro\Adapters;

use UsedMediaPro\Source_Adapter;
use UsedMediaPro\Attachment_Urls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans WordPress-native content for media references.
 */
class Core_Adapter implements Source_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'core_content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Core content (classic + block)', 'used-media-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Post types + statuses this adapter walks.
	 *
	 * @return array{0:string[],1:string[]}
	 */
	private function object_query() {
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		$types = array_merge( $types, array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ) );
		$types = array_diff( array_unique( $types ), array( 'attachment' ) );

		/**
		 * Filter the post types scanned for core media references.
		 *
		 * @param string[] $types Post type slugs.
		 */
		$types = apply_filters( 'umedia_core_scanned_post_types', array_values( $types ) );

		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'inherit' );

		return array( $types, $statuses );
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_objects() {
		global $wpdb;
		list( $types, $statuses ) = $this->object_query();
		if ( empty( $types ) ) {
			return 0;
		}

		$type_ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($type_ph) AND post_status IN ($status_ph)";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( $types, $statuses ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Scan one batch of objects for media references.
	 *
	 * @param int $page     Zero-based batch index.
	 * @param int $per_page Objects per batch.
	 * @return array Reference rows plus scanned count and done flag.
	 */
	public function scan_references( $page, $per_page ) {
		global $wpdb;
		list( $types, $statuses ) = $this->object_query();

		if ( empty( $types ) ) {
			return array(
				'references' => array(),
				'scanned'    => 0,
				'done'       => true,
			);
		}

		$type_ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$offset    = max( 0, (int) $page ) * (int) $per_page;

		$sql = "SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_type IN ($type_ph) AND post_status IN ($status_ph)
			ORDER BY ID ASC LIMIT %d OFFSET %d";

		$params = array_merge( $types, $statuses, array( (int) $per_page, $offset ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB

		$references = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row->ID;

			foreach ( $this->extract_ids_from_content( (string) $row->post_content ) as $attachment_id ) {
				$references[] = array(
					'attachment_id' => $attachment_id,
					'object_id'     => $post_id,
					'context'       => 'content',
				);
			}

			$thumb = (int) get_post_thumbnail_id( $post_id );
			if ( $thumb ) {
				$references[] = array(
					'attachment_id' => $thumb,
					'object_id'     => $post_id,
					'context'       => 'thumbnail',
				);
			}
		}

		return array(
			'references' => $references,
			'scanned'    => count( $rows ),
			'done'       => count( $rows ) < (int) $per_page,
		);
	}

	/**
	 * Extract attachment ids referenced in a chunk of post content.
	 *
	 * Handles: wp-image-{ID} classes (classic + block <img>), block-comment
	 * "id":N attributes, [gallery ids="..."] shortcodes, and local <img>/srcset
	 * URLs resolved back to attachment ids.
	 *
	 * @param string $content Raw post content.
	 * @return int[]
	 */
	private function extract_ids_from_content( $content ) {
		if ( '' === $content ) {
			return array();
		}

		$ids = array();

		// wp-image-123 (both classic editor and block editor <img> class).
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}

		// Block attributes: "id":123 inside a <!-- wp:... --> opening comment.
		if ( preg_match_all( '/<!--\s+wp:[^>]*?"id":(\d+)/s', $content, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}

		// [gallery ids="1, 2, 3"] shortcode.
		if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([0-9,\s]+)["\']/', $content, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/[\s,]+/', $list ) as $gid ) {
					if ( '' !== $gid ) {
						$ids[] = $gid;
					}
				}
			}
		}

		$ids = array_map( 'intval', $ids );

		// Local URLs from src/href/srcset -> attachment ids.
		foreach ( $this->extract_local_urls( $content ) as $url ) {
			$resolved = Attachment_Urls::url_to_id( $url );
			if ( $resolved ) {
				$ids[] = $resolved;
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Pull candidate local upload URLs out of content (src, href, srcset).
	 *
	 * @param string $content Raw post content.
	 * @return string[]
	 */
	private function extract_local_urls( $content ) {
		$urls = array();

		if ( preg_match_all( '/(?:src|href)=["\']([^"\']+)["\']/i', $content, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		if ( preg_match_all( '/srcset=["\']([^"\']+)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $set ) {
				foreach ( explode( ',', $set ) as $candidate ) {
					$candidate = trim( $candidate );
					if ( '' === $candidate ) {
						continue;
					}
					$parts = preg_split( '/\s+/', $candidate );
					if ( ! empty( $parts[0] ) ) {
						$urls[] = $parts[0];
					}
				}
			}
		}

		$base = preg_replace( '#^https?://#i', '', wp_get_upload_dir()['baseurl'] );

		return array_values(
			array_filter(
				array_unique( $urls ),
				function ( $url ) use ( $base ) {
					$normalized = preg_replace( '#^https?://#i', '', $url );
					return $base && 0 === strpos( $normalized, $base );
				}
			)
		);
	}

	/**
	 * Scan one batch of objects for external image URLs.
	 *
	 * Phase 4 — external scanning is not wired into the UI yet.
	 *
	 * @param int $page     Zero-based batch index.
	 * @param int $per_page Objects per batch.
	 * @return array External-URL rows plus scanned count and done flag.
	 */
	public function scan_external( $page, $per_page ) {
		return array(
			'external' => array(),
			'scanned'  => 0,
			'done'     => true,
		);
	}

	/**
	 * Replace an external URL with a locally imported attachment.
	 *
	 * Phase 4 — implemented alongside the external-image feature.
	 *
	 * @param int    $object_id         Object holding the reference.
	 * @param string $old_url           External URL to replace.
	 * @param int    $new_attachment_id Imported local attachment id.
	 * @return bool
	 */
	public function replace( $object_id, $old_url, $new_attachment_id ) {
		return false;
	}
}
