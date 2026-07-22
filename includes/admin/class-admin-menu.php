<?php
/**
 * Admin menu, page rendering, and asset loading.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro\Admin;

use UsedMediaPro\Usage_Index;

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

		wp_enqueue_style( 'ump-admin', UMP_URL . 'assets/admin.css', array(), UMP_VERSION );
		wp_enqueue_script( 'ump-admin', UMP_URL . 'assets/admin.js', array( 'jquery' ), UMP_VERSION, true );
		wp_localize_script(
			'ump-admin',
			'UMP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ump_rebuild' ),
			)
		);
	}

	/**
	 * Render the plugin page with its tabs.
	 */
	public function render_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'used-media-pro' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'library';
		$tabs = array(
			'library'  => __( 'Library', 'used-media-pro' ),
			'settings' => __( 'Settings', 'used-media-pro' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'library';
		}

		echo '<div class="wrap ump-wrap">';
		echo '<h1>' . esc_html__( 'Used Media', 'used-media-pro' ) . '</h1>';

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
		} else {
			$this->render_library_tab();
		}

		echo '</div>';
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
		if ( isset( $_GET['tab'] ) ) {
			echo '<input type="hidden" name="tab" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['tab'] ) ) ) . '" />';
		}
		if ( isset( $_GET['usage'] ) ) {
			echo '<input type="hidden" name="usage" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['usage'] ) ) ) . '" />';
		}
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
