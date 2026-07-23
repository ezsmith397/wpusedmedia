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
	 * @param int $page     Zero-based batch index.
	 * @param int $per_page Objects per batch.
	 * @return array External-URL rows plus scanned count and done flag.
	 */
	public function scan_external( $page, $per_page ) {
		$post_ids = $this->post_ids( $page, $per_page );
		$keys     = $this->meta_keys();
		$external = array();

		foreach ( $post_ids as $post_id ) {
			$urls = array();
			foreach ( $keys as $key ) {
				$content = $this->normalize( get_post_meta( $post_id, $key, true ) );
				if ( is_array( $content ) ) {
					$this->collect_urls( $content, $urls );
				}
			}
			foreach ( array_unique( $urls ) as $url ) {
				if ( \UsedMediaPro\External::is_external( $url ) ) {
					$external[] = array(
						'object_id' => (int) $post_id,
						'url'       => $url,
						'context'   => 'bricks',
					);
				}
			}
		}

		return array(
			'external' => $external,
			'scanned'  => count( $post_ids ),
			'done'     => count( $post_ids ) < (int) $per_page,
		);
	}

	/**
	 * Recursively collect 'url' values from Bricks image objects.
	 *
	 * @param array    $data Bricks data.
	 * @param string[] $urls Collected URLs, by reference.
	 */
	private function collect_urls( array $data, array &$urls ) {
		if ( isset( $data['url'] ) && is_string( $data['url'] ) ) {
			$urls[] = $data['url'];
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_urls( $value, $urls );
			}
		}
	}

	/**
	 * Replace a URL inside Bricks data, natively re-attaching the id on image
	 * objects (id + url), then clearing Bricks' render cache.
	 *
	 * @param int    $object_id         Object holding the reference.
	 * @param string $old_url           URL to replace.
	 * @param string $new_url           Replacement URL.
	 * @param int    $new_attachment_id Attachment id to wire in, or 0.
	 * @return bool
	 */
	public function replace_url( $object_id, $old_url, $new_url, $new_attachment_id ) {
		$changed = false;
		foreach ( $this->meta_keys() as $key ) {
			$content = get_post_meta( $object_id, $key, true );
			if ( ! is_array( $content ) ) {
				continue;
			}
			$this->replace_in_tree( $content, $old_url, $new_url, (int) $new_attachment_id, $changed );
			if ( $changed ) {
				update_post_meta( $object_id, $key, wp_slash( $content ) );
			}
		}

		if ( $changed && class_exists( '\\Bricks\\Assets' ) && method_exists( '\\Bricks\\Assets', 'regenerate_css_file' ) ) {
			// Best-effort: drop this post's cached Bricks CSS so backgrounds refresh.
			delete_post_meta( $object_id, '_bricks_inline_css' );
		}

		return $changed;
	}

	/**
	 * Recursively rewrite a URL in Bricks data and re-attach ids on image nodes.
	 *
	 * @param array  $data    Bricks data (by reference).
	 * @param string $old_url URL to replace.
	 * @param string $new_url Replacement URL.
	 * @param int    $id      Attachment id to set (0 to reset).
	 * @param bool   $changed Whether anything changed (by reference).
	 */
	private function replace_in_tree( array &$data, $old_url, $new_url, $id, &$changed ) {
		foreach ( $data as $key => &$value ) {
			if ( is_array( $value ) ) {
				$this->replace_in_tree( $value, $old_url, $new_url, $id, $changed );
			} elseif ( is_string( $value ) && $value === $old_url ) {
				$value   = $new_url;
				$changed = true;
			}
		}
		unset( $value );

		// This node is the image object that now points at the new URL.
		if ( isset( $data['url'] ) && $data['url'] === $new_url && array_key_exists( 'id', $data ) ) {
			if ( (int) $data['id'] !== $id ) {
				$data['id'] = $id;
				$changed    = true;
			}
		}
	}
}
