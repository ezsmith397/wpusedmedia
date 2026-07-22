<?php
/**
 * Source adapter contract.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every content source (Core content, Bricks, ACF, WooCommerce, ...) that can
 * reference media implements this interface. The rest of the plugin only ever
 * reads and writes references through adapters — never via global find/replace,
 * which would corrupt serialized/JSON data.
 */
interface Source_Adapter {

	/**
	 * Stable machine id, e.g. 'core_content', 'bricks'.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether this adapter can run (dependency present, etc.).
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Total number of objects this adapter will scan. Used for progress.
	 *
	 * @return int
	 */
	public function count_objects();

	/**
	 * Scan one batch of objects for media references.
	 *
	 * @param int $page     Zero-based batch index.
	 * @param int $per_page Objects per batch.
	 * @return array{references:array<int,array{attachment_id:int,object_id:int,context:string}>,scanned:int,done:bool}
	 */
	public function scan_references( $page, $per_page );

	/**
	 * Scan one batch of objects for external (off-site) image URLs.
	 *
	 * Phase 4. Returns rows of {object_id, url, context}.
	 *
	 * @param int $page     Zero-based batch index.
	 * @param int $per_page Objects per batch.
	 * @return array{external:array<int,array{object_id:int,url:string,context:string}>,scanned:int,done:bool}
	 */
	public function scan_external( $page, $per_page );

	/**
	 * Replace an external URL on a specific object with a freshly imported,
	 * locally hosted attachment — re-attaching the id where the source supports it.
	 *
	 * Phase 4.
	 *
	 * @param int    $object_id         The object holding the reference.
	 * @param string $old_url           The external URL to replace.
	 * @param int    $new_attachment_id The imported local attachment id.
	 * @return bool True on success.
	 */
	public function replace( $object_id, $old_url, $new_attachment_id );
}
