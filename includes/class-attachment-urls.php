<?php
/**
 * Helpers for resolving attachment URLs <-> ids, accounting for sub-sizes.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single upload produces many URLs (full, -150x150, -scaled, .webp, custom
 * sizes). References in content may point at any of them, so matching by the
 * full-size URL alone under-counts usage. These helpers bridge that gap.
 */
class Attachment_Urls {

	/**
	 * All public URLs that resolve to a given attachment (full + every sub-size).
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string[]
	 */
	public static function all_urls( $attachment_id ) {
		$urls = array();
		$full = wp_get_attachment_url( $attachment_id );
		if ( $full ) {
			$urls[] = $full;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['file'] ) ) {
			$upload   = wp_get_upload_dir();
			$base_dir = trailingslashit( $upload['baseurl'] ) . trailingslashit( dirname( $meta['file'] ) );

			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$urls[] = $base_dir . $size['file'];
					}
				}
			}

			if ( ! empty( $meta['original_image'] ) ) {
				$urls[] = $base_dir . $meta['original_image'];
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Resolve a (possibly sub-sized) upload URL back to its attachment id.
	 *
	 * @param string $url Candidate URL.
	 * @return int Attachment id, or 0 if none.
	 */
	public static function url_to_id( $url ) {
		$id = attachment_url_to_postid( $url );
		if ( $id ) {
			return (int) $id;
		}

		// Strip a "-800x600" size suffix and retry.
		$stripped = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $url );
		if ( $stripped && $stripped !== $url ) {
			$id = attachment_url_to_postid( $stripped );
			if ( $id ) {
				return (int) $id;
			}
		}

		// Strip a "-scaled" suffix (WP big-image handling) and retry.
		$unscaled = preg_replace( '/-scaled(\.[A-Za-z0-9]+)$/', '$1', $url );
		if ( $unscaled && $unscaled !== $url ) {
			$id = attachment_url_to_postid( $unscaled );
			if ( $id ) {
				return (int) $id;
			}
		}

		return 0;
	}
}
