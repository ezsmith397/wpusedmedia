<?php
/**
 * Settings tab and storage.
 *
 * @package UsedMediaPro
 */

namespace UsedMediaPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings: trusted domains (for CDN-aware external detection) and
 * scan batch size.
 */
class Settings {

	/**
	 * Option key.
	 */
	const OPTION = 'umedia_settings';

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'trusted_domains' => array(),
				'batch_size'      => 40,
			)
		);
	}

	/**
	 * Fetch a single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value to return when the key is absent.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Render (and handle submission of) the settings tab.
	 */
	public static function render() {
		if ( isset( $_POST['umedia_settings_submit'] ) ) {
			check_admin_referer( 'umedia_save_settings' );
			self::handle_save();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'used-media-pro' ) . '</p></div>';
		}

		$settings = self::all();
		$domains  = implode( "\n", (array) $settings['trusted_domains'] );
		?>
		<form method="post">
			<?php wp_nonce_field( 'umedia_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="umedia_trusted_domains"><?php esc_html_e( 'Trusted domains', 'used-media-pro' ); ?></label>
					</th>
					<td>
						<textarea name="umedia_trusted_domains" id="umedia_trusted_domains" rows="5" class="large-text code" placeholder="cdn.example.com"><?php echo esc_textarea( $domains ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One host per line. Images served from these hosts count as local (e.g. your CDN), so they are not flagged as external. Your own site domain is always trusted.', 'used-media-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="umedia_batch_size"><?php esc_html_e( 'Scan batch size', 'used-media-pro' ); ?></label>
					</th>
					<td>
						<input type="number" min="5" max="500" name="umedia_batch_size" id="umedia_batch_size" value="<?php echo esc_attr( (int) $settings['batch_size'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Objects processed per background request. Lower this if scans hit timeouts on a slow host.', 'used-media-pro' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'used-media-pro' ), 'primary', 'umedia_settings_submit' ); ?>
		</form>
		<?php
	}

	/**
	 * Sanitize and persist submitted settings.
	 */
	private static function handle_save() {
		check_admin_referer( 'umedia_save_settings' );

		$raw_domains = isset( $_POST['umedia_trusted_domains'] )
			? sanitize_textarea_field( wp_unslash( $_POST['umedia_trusted_domains'] ) )
			: '';
		$domains     = array();
		foreach ( preg_split( '/[\r\n]+/', $raw_domains ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// Normalize to a bare host.
			$host = wp_parse_url( $line, PHP_URL_HOST );
			$host = $host ? $host : preg_replace( '#^https?://#i', '', $line );
			$host = sanitize_text_field( trim( $host, '/' ) );
			if ( '' !== $host ) {
				$domains[] = $host;
			}
		}

		$batch = isset( $_POST['umedia_batch_size'] ) ? absint( wp_unslash( $_POST['umedia_batch_size'] ) ) : 40;
		$batch = max( 5, min( 500, $batch ) );

		update_option(
			self::OPTION,
			array(
				'trusted_domains' => array_values( array_unique( $domains ) ),
				'batch_size'      => $batch,
			)
		);
	}
}
