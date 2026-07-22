<?php
/**
 * Main plugin bootstrap.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton that wires the plugin's pieces together.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Source-adapter registry.
	 *
	 * @var Adapter_Registry
	 */
	public $registry;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin. Called on plugins_loaded.
	 */
	public function boot() {
		$this->registry = new Adapter_Registry();
		$this->registry->boot();

		if ( is_admin() ) {
			( new Admin\Admin_Menu() )->hooks();
			( new Ajax() )->hooks();
		}
	}
}
