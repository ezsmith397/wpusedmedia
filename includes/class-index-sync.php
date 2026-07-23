<?php
/**
 * Incremental usage-index maintenance so the index stays fresh without a
 * full rebuild.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the usage index current as content changes: re-indexes a single post
 * on save, and prunes rows when posts or attachments are deleted.
 */
class Index_Sync {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'save_post', array( $this, 'on_save' ), 20, 1 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 1 );
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ), 10, 1 );
	}

	/**
	 * Re-index a single post after it is saved.
	 *
	 * @param int $post_id Saved post id.
	 */
	public function on_save( $post_id ) {
		// Only maintain the index once a full one exists; skip revisions/autosaves.
		if ( ! Usage_Index::is_built() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $post->post_type ) {
			return;
		}

		Usage_Index::delete_for_object( $post_id );

		foreach ( Plugin::instance()->registry->all() as $adapter ) {
			$references = $adapter->scan_object( $post_id );
			if ( ! empty( $references ) ) {
				Usage_Index::insert_refs( $adapter->id(), $references );
			}
		}
	}

	/**
	 * Prune index rows for a deleted post.
	 *
	 * @param int $post_id Deleted post id.
	 */
	public function on_deleted_post( $post_id ) {
		Usage_Index::delete_for_object( $post_id );
	}

	/**
	 * Prune index rows referencing a deleted attachment.
	 *
	 * @param int $post_id Deleted attachment id.
	 */
	public function on_delete_attachment( $post_id ) {
		Usage_Index::delete_for_attachments( array( (int) $post_id ) );
	}
}
