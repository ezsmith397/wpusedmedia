<?php
/**
 * AJAX endpoints (batched index rebuild).
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

use UsedMediaPro\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin-ajax requests.
 */
class Ajax {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_ajax_umedia_rebuild_index', array( $this, 'rebuild_index' ) );
		add_action( 'wp_ajax_umedia_scan_external', array( $this, 'scan_external' ) );
		add_action( 'wp_ajax_umedia_import_external', array( $this, 'import_external' ) );
		add_action( 'wp_ajax_umedia_undo_external', array( $this, 'undo_external' ) );
	}

	/**
	 * Rebuild the usage index one batch per request, so large sites never
	 * time out. The browser calls this repeatedly until `done` is true.
	 */
	public function rebuild_index() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'umedia_rebuild', 'nonce' );

		$registry = Plugin::instance()->registry;
		$adapters = array_values( $registry->all() );
		$per_page = max( 5, (int) Settings::get( 'batch_size', 40 ) );
		$step     = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'continue';
		$state    = get_option( 'umedia_rebuild_state' );

		if ( 'start' === $step || ! is_array( $state ) ) {
			Usage_Index::truncate();
			update_option( 'umedia_index_built', 0 );

			$total = 0;
			foreach ( $adapters as $adapter ) {
				$total += (int) $adapter->count_objects();
			}

			$state = array(
				'ai'        => 0,
				'page'      => 0,
				'processed' => 0,
				'total'     => $total,
			);
		}

		$adapter_index = (int) $state['ai'];

		// No adapters left to process -> finish.
		if ( ! isset( $adapters[ $adapter_index ] ) ) {
			$this->finish();
			wp_send_json_success(
				array(
					'processed' => (int) $state['processed'],
					'total'     => (int) $state['total'],
					'done'      => true,
				)
			);
		}

		$adapter = $adapters[ $adapter_index ];
		$result  = $adapter->scan_references( (int) $state['page'], $per_page );

		if ( ! empty( $result['references'] ) ) {
			Usage_Index::insert_refs( $adapter->id(), $result['references'] );
		}

		$state['processed'] += (int) $result['scanned'];

		if ( ! empty( $result['done'] ) ) {
			$state['ai']   = $adapter_index + 1;
			$state['page'] = 0;
		} else {
			$state['page'] = (int) $state['page'] + 1;
		}

		$done = ( (int) $state['ai'] >= count( $adapters ) );

		if ( $done ) {
			$this->finish();
		} else {
			update_option( 'umedia_rebuild_state', $state, false );
		}

		wp_send_json_success(
			array(
				'processed' => (int) $state['processed'],
				'total'     => (int) $state['total'],
				'done'      => $done,
			)
		);
	}

	/**
	 * Mark the index complete and clear transient rebuild state.
	 */
	private function finish() {
		update_option( 'umedia_index_built', 1 );
		update_option( 'umedia_index_last_built', current_time( 'mysql' ) );
		delete_option( 'umedia_rebuild_state' );
	}

	/**
	 * Scan for external images one batch per request (same batching pattern as
	 * the index rebuild).
	 */
	public function scan_external() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'umedia_rebuild', 'nonce' );

		$adapters = array_values( Plugin::instance()->registry->all() );
		$per_page = max( 5, (int) Settings::get( 'batch_size', 40 ) );
		$step     = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'continue';
		$state    = get_option( 'umedia_extscan_state' );

		if ( 'start' === $step || ! is_array( $state ) ) {
			External::truncate();
			update_option( 'umedia_external_scanned', 0 );

			$total = 0;
			foreach ( $adapters as $adapter ) {
				$total += (int) $adapter->count_objects();
			}
			$state = array(
				'ai'        => 0,
				'page'      => 0,
				'processed' => 0,
				'total'     => $total,
			);
		}

		$adapter_index = (int) $state['ai'];
		if ( ! isset( $adapters[ $adapter_index ] ) ) {
			$this->finish_external();
			wp_send_json_success(
				array(
					'processed' => (int) $state['processed'],
					'total'     => (int) $state['total'],
					'done'      => true,
				)
			);
		}

		$adapter = $adapters[ $adapter_index ];
		$result  = $adapter->scan_external( (int) $state['page'], $per_page );

		if ( ! empty( $result['external'] ) ) {
			External::insert_found( $adapter->id(), $result['external'] );
		}

		$state['processed'] += (int) $result['scanned'];
		if ( ! empty( $result['done'] ) ) {
			$state['ai']   = $adapter_index + 1;
			$state['page'] = 0;
		} else {
			$state['page'] = (int) $state['page'] + 1;
		}

		$done = ( (int) $state['ai'] >= count( $adapters ) );
		if ( $done ) {
			$this->finish_external();
		} else {
			update_option( 'umedia_extscan_state', $state, false );
		}

		wp_send_json_success(
			array(
				'processed' => (int) $state['processed'],
				'total'     => (int) $state['total'],
				'done'      => $done,
			)
		);
	}

	/**
	 * Mark the external scan complete.
	 */
	private function finish_external() {
		update_option( 'umedia_external_scanned', 1 );
		delete_option( 'umedia_extscan_state' );
	}

	/**
	 * Import (download + re-attach) one external URL.
	 */
	public function import_external() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'umedia_external', 'nonce' );

		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $hash ) ) {
			wp_send_json_error( array( 'message' => 'bad hash' ), 400 );
		}

		$result = External::import( $hash );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Undo the import of one external URL.
	 */
	public function undo_external() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'umedia_external', 'nonce' );

		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $hash ) ) {
			wp_send_json_error( array( 'message' => 'bad hash' ), 400 );
		}

		$result = External::undo( $hash );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}
}
