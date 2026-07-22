<?php
/**
 * Plugin Name:       Used Media Pro
 * Plugin URI:        https://github.com/ezsmith397/wpusedmedia
 * Description:        A more powerful media manager: see where every library item is used, find & re-attach external images, and safely clean up unused media. Extensible source-adapter architecture (Core + Bricks).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            397 Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       used-media-pro
 *
 * @package UsedMediaPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UMP_VERSION', '0.1.0' );
define( 'UMP_FILE', __FILE__ );
define( 'UMP_PATH', plugin_dir_path( __FILE__ ) );
define( 'UMP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lightweight PSR-4-ish autoloader for the UsedMediaPro namespace.
 *
 * UsedMediaPro\Foo\Bar_Baz  ->  includes/foo/class-bar-baz.php
 * UsedMediaPro\Some_Iface   ->  includes/interface-some-iface.php (fallback)
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'UsedMediaPro\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$dir      = UMP_PATH . 'includes/';
		foreach ( $parts as $segment ) {
			$dir .= strtolower( $segment ) . '/';
		}
		$file_base = strtolower( str_replace( '_', '-', $name ) );
		foreach ( array( 'class-', 'interface-' ) as $kind ) {
			$file = $dir . $kind . $file_base . '.php';
			if ( is_readable( $file ) ) {
				require $file;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'UsedMediaPro\\Activator', 'activate' ) );

add_action(
	'plugins_loaded',
	function () {
		\UsedMediaPro\Plugin::instance()->boot();
	}
);
