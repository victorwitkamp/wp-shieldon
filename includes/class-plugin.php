<?php
declare(strict_types=1);
namespace WPShieldon;
use FilesystemIterator;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use RecursiveDirectoryIterator;
use WPShieldon\Firewall\GlobalHelper;
use WPShieldon\Firewall\SessionHelper;

class Plugin {
	public Logger $psrlogger;
	public SessionHelper $sessionHelper;
	public GlobalHelper $globalHelper;

	public function __construct() {
		$this->psrlogger = new Logger( 'name' );
		$this->psrlogger->pushHandler( new StreamHandler( SHIELDON_PLUGIN_DIR . '/logs/Shieldon.log', Level::Warning ) );
		$this->psrlogger->warning( 'Shieldon Plugin construct ' . $_SERVER['REQUEST_URI'] );
		$this->sessionHelper = new SessionHelper();
		$this->globalHelper  = new GlobalHelper();

		$guardian            = new WPSO_Shieldon_Guardian();
		$guardian->init( $this->psrlogger, $this->sessionHelper, $this->globalHelper );
		$guardian->run();

		register_deactivation_hook( __FILE__, 'wpso_deactivate_plugin' );
		register_activation_hook( __FILE__, 'wpso_activate_plugin' );
	}

	public function wpso_deactivate_plugin() {
		$dir = wpso_get_upload_dir();
		if ( file_exists( $dir ) ) {
			$it    = new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS );
			$files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getRealPath() );
				} else {
					unlink( $file->getRealPath() );
				}
			}
			unset( $it, $files );
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		update_option( 'wpso_driver_hash', '' );
	}

	public function wpso_activate_plugin() {
		wpso_set_channel_id();
		update_option( 'wpso_lang_code', substr( get_locale(), 0, 2 ) );
		update_option( 'wpso_last_reset_time', time() );
		update_option( 'wpso_version', SHIELDON_PLUGIN_VERSION );
		// Add default setting. Only execute this action at the first time activation.
		if ( wpso_is_driver_hash() === false ) {
			if ( ! file_exists( wpso_get_upload_dir() ) ) {
				wp_mkdir_p( wpso_get_upload_dir() );
				update_option( 'wpso_driver_hash', wpso_get_driver_hash() );
				$files = [
					[
						'base'    => WP_CONTENT_DIR . '/uploads/wp-shieldon',
						'file'    => 'index.html',
						'content' => '',
					],
					[
						'base'    => WP_CONTENT_DIR . '/uploads/wp-shieldon',
						'file'    => '.htaccess',
						'content' => 'deny from all',
					],
					[
						'base'    => wpso_get_logs_dir(),
						'file'    => 'index.html',
						'content' => '',
					],
				];
				foreach ( $files as $file ) {
					if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
						// phpcs:ignore
						@file_put_contents(trailingslashit($file['base']) . $file['file'], $file['content']);
					}
				}
			}
		}
	}
}
