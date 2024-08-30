<?php
declare(strict_types=1);
namespace WPShieldon;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use WPShieldon\Firewall\GlobalHelper;
use WPShieldon\Firewall\SessionHelper;

class PluginAdmin {
	public WPSO_Admin_Menu $WPSO_Admin_Menu;
	public WPSO_Admin_Settings $WPSO_Admin_Settings;
	public WPSO_Admin_IP_Manager $WPSO_Admin_IP_Manager;
	public Logger $psrlogger;
	public SessionHelper $sessionHelper;
	public GlobalHelper $globalHelper;

	public function __construct() {
		$this->psrlogger = new Logger( 'name' );
		$this->psrlogger->pushHandler( new StreamHandler( SHIELDON_PLUGIN_DIR . '/logs/Shieldon_PluginAdmin.log', Level::Warning ) );
		$this->psrlogger->warning( 'Shieldon Plugin construct ' . $_SERVER['REQUEST_URI'] );
		$this->WPSO_Admin_Settings   = new WPSO_Admin_Settings();
		$this->WPSO_Admin_IP_Manager = new WPSO_Admin_IP_Manager();
		$this->WPSO_Admin_Menu       = new WPSO_Admin_Menu( $this->psrlogger, $this->WPSO_Admin_Settings, $this->WPSO_Admin_IP_Manager );


		add_action( 'admin_init', [ $this, 'init_shieldon_admin' ]);
	}

	public function init_shieldon_admin() {
		$this->maybe_reset_driver();
		$this->check_and_update_breaking_changes();
		//      $guardian = WPSO_Shieldon_Guardian::instance();
		//      $guardian->init();
	}

	private function maybe_reset_driver() {
		if ( ! empty( $_POST['shieldon_daemon[data_driver_type]'] ) ) {
			update_option( 'wpso_driver_reset', 'yes' );
		}
	}

	private function check_and_update_breaking_changes() {
		$wpso_version = get_option( 'wpso_version' );
		if ( $wpso_version === SHIELDON_PLUGIN_VERSION ) {
			return;
		}
		wpso_set_option( 'enable_daemon', 'shieldon_daemon', 'no' );
		update_option( 'wpso_version', SHIELDON_PLUGIN_VERSION );
		// Turn off strict mode in components, make sure user will review the settings again.
		$component_settings = get_option( 'shieldon_component' );
		if ( ! empty( $component_settings ) && is_array( $component_settings ) ) {
			$remove_strict_settings = [];
			foreach ( $component_settings as $k => $v ) {
				$remove_strict_settings[ $k ] = $v;
				if ( str_contains( $k, 'strict_mode' ) ) {
					$remove_strict_settings[ $k ] = 'no';
				}
			}
			update_option( 'shieldon_component', $remove_strict_settings );
		}
		add_action( 'admin_notices', [ $this, 'update_completed_notice' ]);
	}

	public function update_completed_notice() {
		echo wpso_load_view( 'message/update-notice' );
	}
}
