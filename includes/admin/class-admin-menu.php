<?php
/**
 * Admin menu, page rendering, and asset loading.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro\Admin;

use UsedMediaPro\Usage_Index;
use UsedMediaPro\Trash;
use UsedMediaPro\External;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Used Media" submenu under Media and renders its tabs.
 */
class Admin_Menu {

	/**
	 * Page hook suffix, for scoping asset loads.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the submenu page under Media.
	 */
	public function register_menu() {
		$this->hook = add_submenu_page(
			'upload.php',
			__( 'Used Media', 'used-media-pro' ),
			__( 'Used Media', 'used-media-pro' ),
			'upload_files',
			'used-media-pro',
			array( $this, 'render_page' )
		);

		// Process trash/restore/purge before any output so we can redirect.
		add_action( 'load-' . $this->hook, array( $this, 'handle_actions' ) );
	}

	/**
	 * Handle trash / restore / purge actions (bulk and per-row) on page load,
	 * then redirect back with a result notice. Runs before headers are sent.
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// The request is read to determine which action/nonce applies; the
		// matching nonce is verified below before any state is changed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

		// Resolve the requested action from either bulk dropdown.
		$action = '';
		foreach ( array( 'action', 'action2' ) as $key ) {
			$candidate = isset( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) : '';
			if ( '' !== $candidate && '-1' !== $candidate ) {
				$action = $candidate;
				break;
			}
		}
		if ( ! in_array( $action, array( 'trash', 'restore', 'purge' ), true ) ) {
			return;
		}

		// Collect target ids (bulk 'media[]' or single-row 'attachment').
		$is_bulk = isset( $_REQUEST['media'] );
		if ( $is_bulk ) {
			$ids = array_map( 'intval', (array) wp_unslash( $_REQUEST['media'] ) );
		} elseif ( isset( $_REQUEST['attachment'] ) ) {
			$ids = array( (int) $_REQUEST['attachment'] );
		} else {
			return;
		}
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

		// Verify the matching nonce: list-table bulk nonce, or per-row nonce.
		if ( $is_bulk ) {
			$plural = 'trash' === $action ? 'attachments' : 'trashed';
			check_admin_referer( 'bulk-' . $plural );
		} else {
			check_admin_referer( 'umedia-' . $action . '-' . $ids[0] );
		}

		switch ( $action ) {
			case 'trash':
				$count = count( Trash::trash( $ids, 'manual' ) );
				break;
			case 'restore':
				$count = count( Trash::restore( $ids ) );
				break;
			case 'purge':
				$purged = Trash::delete_permanently( $ids );
				Usage_Index::delete_for_attachments( $purged );
				$count = count( $purged );
				break;
			default:
				return;
		}

		$referer  = wp_get_referer();
		$redirect = $referer ? $referer : menu_page_url( 'used-media-pro', false );
		$redirect = remove_query_arg( array( 'action', 'action2', 'media', 'attachment', '_wpnonce', '_wp_http_referer' ), $redirect );
		$redirect = add_query_arg(
			array(
				'umedia_done'  => $action,
				'umedia_count' => $count,
			),
			$redirect
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Enqueue admin assets only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'ump-admin', UMEDIA_URL . 'assets/admin.css', array(), self::asset_version( 'assets/admin.css' ) );
		wp_enqueue_script( 'ump-admin', UMEDIA_URL . 'assets/admin.js', array( 'jquery' ), self::asset_version( 'assets/admin.js' ), true );
		wp_localize_script(
			'ump-admin',
			'UMP',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'umedia_rebuild' ),
				'externalNonce' => wp_create_nonce( 'umedia_external' ),
			)
		);
	}

	/**
	 * Version string for an asset, based on its file modification time so
	 * browser caches bust whenever the file actually changes. Falls back to
	 * the plugin version if the file is unreadable.
	 *
	 * @param string $relative_path Path under the plugin directory.
	 * @return string
	 */
	private static function asset_version( $relative_path ) {
		$mtime = @filemtime( UMEDIA_PATH . $relative_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? (string) $mtime : UMEDIA_VERSION;
	}

	/**
	 * Render the plugin page with its tabs.
	 */
	public function render_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'used-media-pro' ) );
		}

		// Read-only tab selector from the page URL; navigation only, so no nonce.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'library'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$trashed = Trash::count_trashed();
		$trash_l = $trashed
			? sprintf( /* translators: %d: number of trashed items. */ __( 'Trash (%d)', 'used-media-pro' ), $trashed )
			: __( 'Trash', 'used-media-pro' );
		$tabs    = array(
			'library'  => __( 'Library', 'used-media-pro' ),
			'external' => __( 'External images', 'used-media-pro' ),
			'trash'    => $trash_l,
			'settings' => __( 'Settings', 'used-media-pro' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'library';
		}

		echo '<div class="wrap ump-wrap">';
		echo '<h1>' . esc_html__( 'Used Media', 'used-media-pro' ) . '</h1>';

		$this->render_action_notice();

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url    = add_query_arg(
				array(
					'page' => 'used-media-pro',
					'tab'  => $key,
				),
				admin_url( 'upload.php' )
			);
			$active = $tab === $key ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'settings' === $tab ) {
			Settings::render();
		} elseif ( 'trash' === $tab ) {
			$this->render_trash_tab();
		} elseif ( 'external' === $tab ) {
			$this->render_external_tab();
		} else {
			$this->render_library_tab();
		}

		echo '</div>';
	}

	/**
	 * Show a success notice after a trash/restore/purge redirect.
	 */
	private function render_action_notice() {
		// Read-only result flags from our own redirect; no state change, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['umedia_done'] ) ) {
			return;
		}
		$done  = sanitize_key( wp_unslash( $_GET['umedia_done'] ) );
		$count = isset( $_GET['umedia_count'] ) ? absint( wp_unslash( $_GET['umedia_count'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $done ) {
			case 'trash':
				/* translators: %d: number of items. */
				$message = sprintf( _n( '%d item moved to the trash.', '%d items moved to the trash.', $count, 'used-media-pro' ), $count );
				break;
			case 'restore':
				/* translators: %d: number of items. */
				$message = sprintf( _n( '%d item restored.', '%d items restored.', $count, 'used-media-pro' ), $count );
				break;
			case 'purge':
				/* translators: %d: number of items. */
				$message = sprintf( _n( '%d item permanently deleted.', '%d items permanently deleted.', $count, 'used-media-pro' ), $count );
				break;
			default:
				return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Render the external-images scan + results table.
	 */
	private function render_external_tab() {
		$scanned = External::has_scanned();

		echo '<div class="ump-index-banner ' . ( $scanned ? 'notice notice-info inline' : 'notice notice-warning inline' ) . '">';
		if ( $scanned ) {
			echo '<p>' . esc_html__( 'Import an external image to download it into the media library and rewrite every reference to the local copy. Use Undo to reverse an import.', 'used-media-pro' );
			echo ' <button type="button" class="button ump-extscan">' . esc_html__( 'Rescan', 'used-media-pro' ) . '</button></p>';
		} else {
			echo '<p><strong>' . esc_html__( 'No external-image scan has run yet.', 'used-media-pro' ) . '</strong> ';
			echo esc_html__( 'Scan your content for images hosted on other domains. This is read-only.', 'used-media-pro' );
			echo ' <button type="button" class="button button-primary ump-extscan">' . esc_html__( 'Scan for external images', 'used-media-pro' ) . '</button></p>';
		}
		echo '<div class="ump-progress" style="display:none;"><div class="ump-progress-track"><div class="ump-progress-bar"></div></div><p class="ump-progress-text"></p></div>';
		echo '</div>';

		if ( $scanned ) {
			$table = new External_List_Table();
			$table->prepare_items();
			echo '<form method="get">';
			echo '<input type="hidden" name="page" value="used-media-pro" />';
			echo '<input type="hidden" name="tab" value="external" />';
			$table->display();
			echo '</form>';
		}
	}

	/**
	 * Render the trash list table.
	 */
	private function render_trash_tab() {
		echo '<p class="description">' . esc_html__( 'These attachments are in the trash: the files and posts are intact and can be restored exactly. Nothing is removed from disk until you delete it permanently here.', 'used-media-pro' ) . '</p>';

		$table = new Trash_List_Table();
		$table->prepare_items();

		echo '<form method="post">';
		$table->display();
		echo '</form>';
	}

	/**
	 * Render the index status banner + the library list table.
	 */
	private function render_library_tab() {
		$this->render_index_banner();

		$table = new Library_List_Table();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="used-media-pro" />';
		// Preserve current tab/usage in the search form; read-only nav args, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['tab'] ) ) {
			echo '<input type="hidden" name="tab" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['tab'] ) ) ) . '" />';
		}
		if ( isset( $_GET['usage'] ) ) {
			echo '<input type="hidden" name="usage" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['usage'] ) ) ) . '" />';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$table->views();
		$table->search_box( __( 'Search media', 'used-media-pro' ), 'ump-media-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Render the "build/rebuild index" banner and progress bar.
	 */
	private function render_index_banner() {
		$built = Usage_Index::is_built();
		$last  = Usage_Index::last_built();

		echo '<div class="ump-index-banner ' . ( $built ? 'notice notice-info inline' : 'notice notice-warning inline' ) . '">';

		if ( $built ) {
			echo '<p>' . sprintf(
				/* translators: %s: date/time of last index build. */
				esc_html__( 'Usage index last built: %s.', 'used-media-pro' ),
				'<strong>' . esc_html( $last ) . '</strong>'
			);
			echo ' <button type="button" class="button ump-rebuild">' . esc_html__( 'Rebuild index', 'used-media-pro' ) . '</button></p>';
		} else {
			echo '<p><strong>' . esc_html__( 'The usage index has not been built yet.', 'used-media-pro' ) . '</strong> ';
			echo esc_html__( 'Build it to see where each media item is used. This scans your content in the background and is safe to re-run anytime.', 'used-media-pro' );
			echo ' <button type="button" class="button button-primary ump-rebuild">' . esc_html__( 'Build index now', 'used-media-pro' ) . '</button></p>';
		}

		echo '<div class="ump-progress" style="display:none;">';
		echo '<div class="ump-progress-track"><div class="ump-progress-bar"></div></div>';
		echo '<p class="ump-progress-text"></p>';
		echo '</div>';

		echo '</div>';
	}
}
