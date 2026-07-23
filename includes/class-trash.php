<?php
/**
 * Reversible trash for attachments: a plugin-controlled soft delete.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trashing flags an attachment with postmeta and leaves the post row, its
 * status, and the file on disk completely intact — so a restore is exact and
 * lossless. Only delete_permanently() actually removes anything, and only for
 * items already carrying the trash flag.
 *
 * We use a meta flag rather than a post status because WordPress core forces
 * attachment post_status onto a fixed whitelist (inherit/private/trash/
 * auto-draft) in wp_insert_post(), so a custom status silently reverts to
 * 'inherit'. A meta flag is not coerced, and it deliberately avoids WP's
 * native 'trash' status so EMPTY_TRASH_DAYS can never auto-purge these files.
 */
class Trash {

	const META_FLAG   = '_umedia_trashed';
	const META_AT     = '_umedia_trashed_at';
	const META_BY     = '_umedia_trashed_by';
	const META_REASON = '_umedia_trashed_reason';

	/**
	 * Whether an attachment is currently trashed.
	 *
	 * @param int $id Attachment id.
	 * @return bool
	 */
	public static function is_trashed( $id ) {
		return '' !== (string) get_post_meta( (int) $id, self::META_FLAG, true );
	}

	/**
	 * Move attachments to the trash (reversible).
	 *
	 * @param int[]  $ids    Attachment ids.
	 * @param string $reason Why they were trashed ('manual' or 'no_references').
	 * @return int[] Ids actually trashed.
	 */
	public static function trash( array $ids, $reason = 'manual' ) {
		$done = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type || self::is_trashed( $id ) ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			update_post_meta( $id, self::META_FLAG, '1' );
			update_post_meta( $id, self::META_AT, current_time( 'mysql' ) );
			update_post_meta( $id, self::META_BY, get_current_user_id() );
			update_post_meta( $id, self::META_REASON, sanitize_key( $reason ) );
			$done[] = $id;
		}
		return $done;
	}

	/**
	 * Restore attachments from the trash (just clears the flag metadata).
	 *
	 * @param int[] $ids Attachment ids.
	 * @return int[] Ids actually restored.
	 */
	public static function restore( array $ids ) {
		$done = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( ! self::is_trashed( $id ) ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			self::clear_meta( $id );
			$done[] = $id;
		}
		return $done;
	}

	/**
	 * Permanently delete trashed attachments (files + post). Only operates on
	 * items already carrying the trash flag — a live attachment can never be
	 * deleted without first being moved to the trash.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return int[] Ids actually deleted.
	 */
	public static function delete_permanently( array $ids ) {
		$done = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( ! self::is_trashed( $id ) ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			if ( wp_delete_attachment( $id, true ) ) {
				$done[] = $id;
			}
		}
		return $done;
	}

	/**
	 * Ids of all attachments currently in the trash.
	 *
	 * @return int[]
	 */
	public static function trashed_ids() {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_FLAG,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Number of attachments currently in the trash.
	 *
	 * @return int
	 */
	public static function count_trashed() {
		return count( self::trashed_ids() );
	}

	/**
	 * Remove all trash metadata from an attachment.
	 *
	 * @param int $id Attachment id.
	 */
	private static function clear_meta( $id ) {
		delete_post_meta( $id, self::META_FLAG );
		delete_post_meta( $id, self::META_AT );
		delete_post_meta( $id, self::META_BY );
		delete_post_meta( $id, self::META_REASON );
	}
}
