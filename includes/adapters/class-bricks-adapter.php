<?php
/**
 * Bricks Builder adapter: scans Bricks layout data stored in postmeta.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro\Adapters;

use UsedMediaPro\Source_Adapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans Bricks Builder content (stored as arrays in postmeta) for attachment
 * references, feeding the same usage index as the core adapter.
 */
class Bricks_Adapter implements Source_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'bricks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Bricks Builder', 'used-media-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return defined( 'BRICKS_VERSION' );
	}

	/**
	 * Postmeta keys where Bricks stores builder content (page, header, footer).
	 * Templates (bricks_template) also use the page-content key.
	 *
	 * @return string[]
	 */
	private function meta_keys() {
		$keys = array();
		foreach ( array( 'BRICKS_DB_PAGE_CONTENT', 'BRICKS_DB_PAGE_HEADER', 'BRICKS_DB_PAGE_FOOTER' ) as $const ) {
			if ( defined( $const ) ) {
				$keys[] = constant( $const );
			}
		}
		if ( empty( $keys ) ) {
			$keys = array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' );
		}
		return $keys;
	}

	/**
	 * Ordered post ids that carry Bricks content, for one batch.
	 *
	 * @param int $page     Zero-based batch index.
	 * @param int $per_page Objects per batch.
	 * @return int[]
	 */
	private function post_ids( $page, $per_page ) {
		global $wpdb;
		$keys   = $this->meta_keys();
		$key_ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$offset = max( 0, (int) $page ) * (int) $per_page;

		$sql = "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key IN ($key_ph)
			ORDER BY post_id ASC LIMIT %d OFFSET %d";

		$params = array_merge( $keys, array( (int) $per_page, $offset ) );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_objects() {
		global $wpdb;
		$keys   = $this->meta_keys();
		$key_ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql    = "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ($key_ph)";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $keys ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Scan one batch of Bricks-powered objects for media references.
	 *
	 * @param int $page     Zero-based batch index.
	 * @param int $per_page Objects per batch.
	 * @return array Reference rows plus scanned count and done flag.
	 */
	public function scan_references( $page, $per_page ) {
		$post_ids   = $this->post_ids( $page, $per_page );
		$keys       = $this->meta_keys();
		$references = array();

		foreach ( $post_ids as $post_id ) {
			$ids = array();
			foreach ( $keys as $key ) {
				$content = $this->normalize( get_post_meta( $post_id, $key, true ) );
				if ( is_array( $content ) ) {
					$this->collect_image_ids( $content, $ids );
				}
			}
			foreach ( array_unique( array_filter( $ids ) ) as $attachment_id ) {
				$references[] = array(
					'attachment_id' => (int) $attachment_id,
					'object_id'     => (int) $post_id,
					'context'       => 'bricks',
				);
			}
		}

		return array(
			'references' => $references,
			'scanned'    => count( $post_ids ),
			'done'       => count( $post_ids ) < (int) $per_page,
		);
	}

	/**
	 * Bricks stores builder data as a PHP array, but be defensive about a
	 * JSON-string representation too.
	 *
	 * @param mixed $content Raw meta value.
	 * @return mixed Array when decodable, otherwise the input unchanged.
	 */
	private function normalize( $content ) {
		if ( is_string( $content ) && '' !== $content ) {
			$decoded = json_decode( $content, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return $content;
	}

	/**
	 * Recursively collect attachment ids from Bricks image objects.
	 *
	 * Bricks image references are associative arrays carrying both an 'id'
	 * (attachment id) and a 'url'. Walking generically catches image elements,
	 * background images, logos, and gallery/carousel items alike. For the
	 * usage index, over-inclusion is the safe direction.
	 *
	 * @param array $data Bricks element/settings data.
	 * @param int[] $ids  Collected ids, passed by reference.
	 */
	private function collect_image_ids( array $data, array &$ids ) {
		if ( isset( $data['id'], $data['url'] ) && is_numeric( $data['id'] ) ) {
			$ids[] = (int) $data['id'];
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_image_ids( $value, $ids );
			}
		}
	}

	/**
	 * Scan one batch of Bricks objects for external image URLs.
	 *
	 * Phase 4 — not wired into the UI yet.
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
	 * Phase 4 — Bricks re-attach updates the image object's id + url in place
	 * within the postmeta array, then busts Bricks' cached CSS/HTML.
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
