<?php
/**
 * Registry of source adapters.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects and exposes the available source adapters.
 */
class Adapter_Registry {

	/**
	 * Registered adapters keyed by id.
	 *
	 * @var Source_Adapter[]
	 */
	private $adapters = array();

	/**
	 * Register the built-in adapters and let third parties add their own.
	 */
	public function boot() {
		$this->register( new Adapters\Core_Adapter() );

		// Phase 2 registers the Bricks adapter here. Third parties can add
		// adapters (ACF, WooCommerce, ...) via this filter.
		$adapters = apply_filters( 'ump_source_adapters', $this->adapters, $this );
		if ( is_array( $adapters ) ) {
			$this->adapters = $adapters;
		}
	}

	/**
	 * Register a single adapter.
	 *
	 * @param Source_Adapter $adapter Adapter instance.
	 */
	public function register( Source_Adapter $adapter ) {
		$this->adapters[ $adapter->id() ] = $adapter;
	}

	/**
	 * All adapters that report themselves available.
	 *
	 * @return Source_Adapter[]
	 */
	public function all() {
		return array_filter(
			$this->adapters,
			function ( $adapter ) {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * Fetch one adapter by id.
	 *
	 * @param string $id Adapter id.
	 * @return Source_Adapter|null
	 */
	public function get( $id ) {
		return isset( $this->adapters[ $id ] ) ? $this->adapters[ $id ] : null;
	}
}
