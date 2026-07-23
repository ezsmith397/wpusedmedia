<?php
/**
 * External-image scanning, import (download + re-attach), and undo.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro;

use UsedMediaPro\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds off-site image URLs referenced in content, downloads them into the
 * media library, and rewrites every reference to the new local copy through
 * the source adapters. Records enough to undo an import.
 */
class External {

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'umedia_external';
	}

	/**
	 * Whether a URL points off-site (not the site, its uploads host, or a
	 * configured trusted/CDN domain).
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_external( $url ) {
		$url = trim( $url );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) || 0 === strpos( $url, '#' ) ) {
			return false;
		}
		// Normalize protocol-relative URLs so parsing finds the host.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false; // Relative URL -> local.
		}
		$host  = strtolower( $host );
		$known = array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_HOST ) ),
		);
		foreach ( (array) Settings::get( 'trusted_domains', array() ) as $trusted ) {
			$known[] = strtolower( trim( (string) $trusted ) );
		}
		return ! in_array( $host, array_filter( $known ), true );
	}

	/**
	 * Empty the external-scan results.
	 */
	public static function truncate() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Insert scan-result rows for one source.
	 *
	 * @param string $source_id Source adapter id.
	 * @param array  $rows      Rows of {object_id, url, context}.
	 */
	public static function insert_found( $source_id, array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}
		$table        = self::table();
		$placeholders = array();
		$values       = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['url'] ) ) {
				continue;
			}
			$placeholders[] = '(%s,%d,%s,%s,%s,%s)';
			$values[]       = $source_id;
			$values[]       = (int) $row['object_id'];
			$values[]       = (string) $row['url'];
			$values[]       = sha1( (string) $row['url'] );
			$values[]       = isset( $row['context'] ) ? (string) $row['context'] : '';
			$values[]       = 'found';
		}
		if ( empty( $placeholders ) ) {
			return;
		}
		$sql = "INSERT INTO {$table} (source_id, object_id, url, url_hash, context, status) VALUES " . implode( ',', $placeholders );
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Whether a scan has ever been run.
	 *
	 * @return bool
	 */
	public static function has_scanned() {
		return (bool) get_option( 'umedia_external_scanned', 0 );
	}

	/**
	 * Fetch a batch of not-yet-checked unique URLs (status = found).
	 *
	 * @param int $limit Max URLs.
	 * @return array<int,object> Rows with url_hash, url.
	 */
	public static function fetch_unchecked( $limit ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_hash, MIN(url) AS url FROM {$table} WHERE status = 'found' GROUP BY url_hash LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Count of unique URLs still awaiting a health check.
	 *
	 * @return int
	 */
	public static function unchecked_count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT url_hash) FROM {$table} WHERE status = 'found'" );
	}

	/**
	 * Set the status (and message) for every row of one URL hash.
	 *
	 * @param string $url_hash sha1 of the URL.
	 * @param string $status   New status.
	 * @param string $message  Optional detail.
	 */
	public static function set_status( $url_hash, $status, $message = '' ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'  => $status,
				'message' => substr( $message, 0, 250 ),
			),
			array( 'url_hash' => $url_hash )
		);
	}

	/**
	 * Health-check a URL: is it reachable and does it return an image?
	 *
	 * @param string $url URL to check.
	 * @return array{ok:bool,reason:string}
	 */
	public static function check_url( $url ) {
		$args = array(
			'timeout'     => 6,
			'redirection' => 3,
			'user-agent'  => 'UsedMediaPro/health-check',
		);

		$response = wp_remote_head( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// Some hosts reject HEAD; retry with a tiny ranged GET.
		if ( is_wp_error( $response ) || 405 === $code || 403 === $code ) {
			$response = wp_remote_get( $url, array_merge( $args, array( 'headers' => array( 'Range' => 'bytes=0-2047' ) ) ) );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'reason' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 0 === $code || $code >= 400 ) {
			/* translators: %d: HTTP status code. */
			$reason = sprintf( __( 'HTTP %d', 'used-media-pro' ), $code );
			return array(
				'ok'     => false,
				'reason' => $reason,
			);
		}

		$ctype = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' !== $ctype ) {
			if ( 0 === strpos( $ctype, 'image/' ) ) {
				return array(
					'ok'     => true,
					'reason' => '',
				);
			}
			$label = explode( ';', $ctype );
			/* translators: %s: returned content type. */
			$reason = sprintf( __( 'not an image (%s)', 'used-media-pro' ), $label[0] );
			return array(
				'ok'     => false,
				'reason' => $reason,
			);
		}

		// No content-type header: fall back to the URL's extension.
		if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|bmp|tiff?|avif)(\?|$)/i', $url ) ) {
			return array(
				'ok'     => true,
				'reason' => '',
			);
		}
		return array(
			'ok'     => false,
			'reason' => __( 'unknown content type', 'used-media-pro' ),
		);
	}

	/**
	 * Counts of unique URLs by state, for the filter views.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(DISTINCT url_hash) AS n FROM {$table} GROUP BY status" );
		$out   = array(
			'all'      => 0,
			'ok'       => 0,
			'broken'   => 0,
			'found'    => 0,
			'imported' => 0,
			'failed'   => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ $row->status ] = (int) $row->n;
			$out['all']         += (int) $row->n;
		}
		return $out;
	}

	/**
	 * One page of unique external URLs, grouped, with reference counts.
	 *
	 * @param int    $per_page Rows per page.
	 * @param int    $page     1-based page number.
	 * @param string $status   Filter: all|working|broken|imported|unchecked.
	 * @return array{items:array,total:int}
	 */
	public static function grouped( $per_page, $page, $status = 'all' ) {
		global $wpdb;
		$table  = self::table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		$map    = array(
			'working'   => 'ok',
			'broken'    => 'broken',
			'imported'  => 'imported',
			'unchecked' => 'found',
		);
		$where  = '';
		$params = array();
		if ( isset( $map[ $status ] ) ) {
			$where    = ' WHERE status = %s';
			$params[] = $map[ $status ];
		}

		$total = (int) $wpdb->get_var(
			empty( $params )
				? "SELECT COUNT(DISTINCT url_hash) FROM {$table}"
				: $wpdb->prepare( "SELECT COUNT(DISTINCT url_hash) FROM {$table}{$where}", $params )
		);

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_hash, MIN(url) AS url, COUNT(DISTINCT object_id) AS refs,
					MAX(status) AS status, MAX(new_attachment_id) AS new_attachment_id, MAX(message) AS message
				FROM {$table}{$where}
				GROUP BY url_hash
				ORDER BY refs DESC
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			)
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Rows (object references) for one external URL hash.
	 *
	 * @param string $url_hash sha1 of the URL.
	 * @return array<int,object>
	 */
	private static function rows_for( $url_hash ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s", $url_hash )
		);
	}

	/**
	 * Download one external URL into the media library and rewrite every
	 * reference to it across all objects that use it.
	 *
	 * @param string $url_hash sha1 of the URL to import.
	 * @return array Result: {ok, message, new_attachment_id, replaced}.
	 */
	public static function import( $url_hash ) {
		global $wpdb;
		$rows = self::rows_for( $url_hash );
		if ( empty( $rows ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Nothing to import.', 'used-media-pro' ),
			);
		}

		if ( 'broken' === $rows[0]->status ) {
			return array(
				'ok'      => false,
				'message' => __( 'This image is broken (unreachable or not an image) and cannot be imported.', 'used-media-pro' ),
			);
		}

		$url = $rows[0]->url;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = self::sideload( $url );
		if ( is_wp_error( $attachment_id ) ) {
			$message = $attachment_id->get_error_message();
			$table   = self::table();
			$wpdb->update(
				$table,
				array(
					'status'  => 'failed',
					'message' => substr( $message, 0, 250 ),
				),
				array( 'url_hash' => $url_hash )
			);
			return array(
				'ok'      => false,
				'message' => $message,
			);
		}

		$new_url  = wp_get_attachment_url( $attachment_id );
		$registry = Plugin::instance()->registry;
		$replaced = 0;
		foreach ( $rows as $row ) {
			$adapter = $registry->get( $row->source_id );
			if ( $adapter && $adapter->replace_url( (int) $row->object_id, $url, $new_url, (int) $attachment_id ) ) {
				++$replaced;
			}
		}

		$table = self::table();
		$wpdb->update(
			$table,
			array(
				'status'            => 'imported',
				'new_attachment_id' => (int) $attachment_id,
				'message'           => '',
			),
			array( 'url_hash' => $url_hash )
		);

		// The new local attachment is now referenced; keep the usage index fresh.
		Usage_Index::delete_for_attachments( array( (int) $attachment_id ) );

		return array(
			'ok'                => true,
			'new_attachment_id' => (int) $attachment_id,
			'replaced'          => $replaced,
			/* translators: %d: number of references rewritten. */
			'message'           => sprintf( _n( 'Imported and rewrote %d reference.', 'Imported and rewrote %d references.', $replaced, 'used-media-pro' ), $replaced ),
		);
	}

	/**
	 * Undo an import: rewrite references back to the external URL and delete
	 * the imported attachment.
	 *
	 * @param string $url_hash sha1 of the original external URL.
	 * @return array Result: {ok, message}.
	 */
	public static function undo( $url_hash ) {
		global $wpdb;
		$rows = self::rows_for( $url_hash );
		if ( empty( $rows ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Nothing to undo.', 'used-media-pro' ),
			);
		}

		$attachment_id = (int) $rows[0]->new_attachment_id;
		if ( ! $attachment_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'This URL has not been imported.', 'used-media-pro' ),
			);
		}

		$url      = $rows[0]->url;
		$new_url  = wp_get_attachment_url( $attachment_id );
		$registry = Plugin::instance()->registry;
		if ( $new_url ) {
			foreach ( $rows as $row ) {
				$adapter = $registry->get( $row->source_id );
				if ( $adapter ) {
					$adapter->replace_url( (int) $row->object_id, $new_url, $url, 0 );
				}
			}
		}

		wp_delete_attachment( $attachment_id, true );
		Usage_Index::delete_for_attachments( array( $attachment_id ) );

		$table = self::table();
		$wpdb->update(
			$table,
			array(
				'status'            => 'found',
				'new_attachment_id' => 0,
				'message'           => '',
			),
			array( 'url_hash' => $url_hash )
		);

		return array(
			'ok'      => true,
			'message' => __( 'Import undone; the imported file was deleted.', 'used-media-pro' ),
		);
	}

	/**
	 * Download a URL and create a media-library attachment from it, with
	 * basic validation.
	 *
	 * @param string $url External image URL.
	 * @return int|\WP_Error Attachment id or error.
	 */
	private static function sideload( $url ) {
		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$type = wp_check_filetype( $tmp );
		$mime = mime_content_type( $tmp );
		if ( $mime && 0 !== strpos( $mime, 'image/' ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'not_image', __( 'The URL did not return an image.', 'used-media-pro' ) );
		}

		$name = wp_basename( wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name || false === strpos( $name, '.' ) ) {
			$ext  = $type['ext'] ? $type['ext'] : 'jpg';
			$name = 'external-image-' . substr( sha1( $url ), 0, 8 ) . '.' . $ext;
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
		}
		return $attachment_id;
	}
}
