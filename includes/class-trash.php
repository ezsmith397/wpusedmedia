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
 * Trashing moves an attachment to an internal post status and remembers its
 * previous status. The post row and the file on disk are left completely
 * intact, so a restore is exact and lossless. Only delete_permanently()
 * actually removes anything, and only for items already in the trashed status.
 *
 * This deliberately uses its own status rather than WordPress's native 'trash'
 * status, so WP's EMPTY_TRASH_DAYS cron can never auto-purge these files.
 */
class Trash {

	const STATUS      = 'umedia_trashed';
	const META_PREV   = '_umedia_trash_prev_status';
	const META_AT     = '_umedia_trashed_at';
	const META_BY     = '_umedia_trashed_by';
	const META_REASON = '_umedia_trashed_reason';

	/**
	 * Register the internal post status. Hooked on init.
	 */
	public static function register_status() {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Trashed', 'post status', 'used-media-pro' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
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
			if ( ! $post || 'attachment' !== $post->post_type || self::STATUS === $post->post_status ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			update_post_meta( $id, self::META_PREV, $post->post_status );
			update_post_meta( $id, self::META_AT, current_time( 'mysql' ) );
			update_post_meta( $id, self::META_BY, get_current_user_id() );
			update_post_meta( $id, self::META_REASON, sanitize_key( $reason ) );
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => self::STATUS,
				)
			);
			$done[] = $id;
		}
		return $done;
	}

	/**
	 * Restore trashed attachments to their previous status.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return int[] Ids actually restored.
	 */
	public static function restore( array $ids ) {
		$done = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || self::STATUS !== $post->post_status ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			$prev = get_post_meta( $id, self::META_PREV, true );
			if ( '' === $prev ) {
				$prev = 'inherit';
			}
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $prev,
				)
			);
			self::clear_meta( $id );
			$done[] = $id;
		}
		return $done;
	}

	/**
	 * Permanently delete trashed attachments (files + post). Only operates on
	 * items already in the trashed status — a live attachment can never be
	 * deleted without first being moved to the trash.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return int[] Ids actually deleted.
	 */
	public static function delete_permanently( array $ids ) {
		$done = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || self::STATUS !== $post->post_status ) {
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
	 * Number of attachments currently in the trash.
	 *
	 * @return int
	 */
	public static function count_trashed() {
		$counts = wp_count_posts( 'attachment' );
		return isset( $counts->{self::STATUS} ) ? (int) $counts->{self::STATUS} : 0;
	}

	/**
	 * Remove all trash metadata from an attachment.
	 *
	 * @param int $id Attachment id.
	 */
	private static function clear_meta( $id ) {
		delete_post_meta( $id, self::META_PREV );
		delete_post_meta( $id, self::META_AT );
		delete_post_meta( $id, self::META_BY );
		delete_post_meta( $id, self::META_REASON );
	}
}
