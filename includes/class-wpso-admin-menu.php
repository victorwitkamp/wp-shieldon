<?php
declare(strict_types=1);
namespace WPShieldon;
use Monolog\Logger;
use ReflectionObject;
use WPShieldon\Firewall\Driver\FileDriver;
use WPShieldon\Firewall\Driver\MysqlDriver;
use WPShieldon\Firewall\Driver\RedisDriver;
use WPShieldon\Firewall\Driver\SqliteDriver;
use WPShieldon\Firewall\Kernel\Enum;
use WPShieldon\Firewall\Log\ActionLogParsedCache;
use WPShieldon\Firewall\Log\ActionLogParser;
use function count;
use function get_class;

class WPSO_Admin_Menu {
	public Logger $psrlogger;
	public WPSO_Shieldon_Guardian $wpso;
	public ?WPSO_Admin_Settings $WPSO_Admin_Settings = null;

	public ?WPSO_Admin_IP_Manager $WPSO_Admin_IP_Manager = null;

	public function __construct( Logger $psrlogger, WPSO_Admin_Settings $WPSO_Admin_Settings, WPSO_Admin_IP_Manager $WPSO_Admin_IP_Manager ) {
		$this->psrlogger = $psrlogger;
		$this->psrlogger->warning( 'WPSO_Admin_Menu construct' );

		$this->WPSO_Admin_Settings = $WPSO_Admin_Settings;
		$this->WPSO_Admin_IP_Manager = $WPSO_Admin_IP_Manager;

		$this->wpso = new WPSO_Shieldon_Guardian();
		//      $this->wpso->set_driver();
		//      $this->wpso->set_logger();
		static $is_initialized = false;
		if ( ! $is_initialized ) {
			//          $this->wpso->set_client_current_ip();
			$this->wpso->set_driver();
			//          $this->wpso->reset_logs();
			$this->wpso->set_logger();
			$this->wpso->set_filters();
			$this->wpso->initComponents();
			$this->wpso->set_captcha();
			$this->wpso->set_session_limit();
			$this->wpso->set_authentication();
			$this->wpso->set_xss_protection();
			$is_initialized = true;
		}
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ]);
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_styles' ]);
		add_action( 'admin_menu', [ $this, 'setting_admin_menu' ]);
		add_filter( 'plugin_action_links_' . SHIELDON_PLUGIN_NAME, [ $this, 'plugin_action_links' ], 10, 5 );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_extend_links' ], 10, 2 );
	}

	/**
	 * @param string $hook_suffix The current admin page.
	 */
	public function admin_enqueue_styles( $hook_suffix ) {
		if ( ! str_contains( $hook_suffix, 'shieldon' ) ) {
			return;
		}
		wp_enqueue_style( 'custom_wp_admin_css', SHIELDON_PLUGIN_URL . 'includes/assets/css/admin-style.css', [], SHIELDON_PLUGIN_VERSION);
		wp_enqueue_style( 'custom_wp_admin_css_status', SHIELDON_PLUGIN_URL . 'includes/assets/css/admin-style-status.css', [], SHIELDON_PLUGIN_VERSION );
		wp_enqueue_style( 'custom_wp_admin_css_dashboard', SHIELDON_PLUGIN_URL . 'includes/assets/css/admin-style-dashboard.css', [], SHIELDON_PLUGIN_VERSION );
		wp_enqueue_style( 'custom_wp_admin_css_datatables', SHIELDON_PLUGIN_URL . 'includes/assets/css/admin-datatables.css', [], SHIELDON_PLUGIN_VERSION );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
	}

	/**
	 * @param string $hook_suffix The current admin page.
	 */
	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( ! str_contains( $hook_suffix, 'shieldon' ) ) {
			return;
		}
		wp_enqueue_script( 'wpso-fontawesome-5-js', SHIELDON_PLUGIN_URL . 'includes/assets/js/fontawesome-all.min.js', [ 'jquery' ], SHIELDON_PLUGIN_VERSION, true );
		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_script( 'wpso-apexcharts', SHIELDON_PLUGIN_URL . 'includes/assets/js/apexcharts.min.js', [], SHIELDON_PLUGIN_VERSION, false );
		wp_enqueue_script( 'wpso-datatables', SHIELDON_PLUGIN_URL . 'includes/assets/js/datatables.min.js', [], SHIELDON_PLUGIN_VERSION, true );
	}

	public function setting_admin_menu() {
		$separate = '<div style="margin: 0px -10px 10px -10px; background-color: #555566; height: 1px; overflow: hidden;"></div>';
		add_menu_page(
			__( 'WP Shieldon', 'wp-shieldon' ),
			__( 'WP Shieldon', 'wp-shieldon' ),
			'manage_options',
			'shieldon-settings',
			'__return_false',
			'dashicons-shield' );

		add_submenu_page(
			'shieldon-settings', __( 'Settings', 'wp-shieldon' ), __( 'Settings', 'wp-shieldon' ), 'manage_options', 'shieldon-settings',
			[ $this, 'setting_plugin_page' ]
		);
		add_submenu_page(
			'shieldon-settings', __( 'Overview', 'wp-shieldon' ), __( 'Overview', 'wp-shieldon' ), 'manage_options', 'shieldon-overview',
			[ $this, 'overview' ]
		);
		add_submenu_page(
			'shieldon-settings', __( 'Operation Status', 'wp-shieldon' ), __( 'Operation Status', 'wp-shieldon' ), 'manage_options', 'shieldon-operation-status',
			[ $this, 'operation_status' ]
		);
		if ( wpso_get_option( 'enable_action_logger', 'shieldon_daemon' ) === 'yes' ) {
			add_submenu_page(
				'shieldon-settings', __( 'Action Logs', 'wp-shieldon' ), __( 'Action Logs', 'wp-shieldon' ), 'manage_options', 'shieldon-action-logs',
				[ $this, 'action_logs' ]
			);
		}
		add_submenu_page(
			'shieldon-settings', __( 'Rule Table', 'wp-shieldon' ), $separate . __( 'Rule Table', 'wp-shieldon' ), 'manage_options', 'shieldon-rule-table',
			[ $this, 'rule_table' ]
		);
		add_submenu_page(
			'shieldon-settings', __( 'Filter Log Table', 'wp-shieldon' ), __( 'Filter Log Table', 'wp-shieldon' ), 'manage_options', 'shieldon-filter-log-table',
			[ $this, 'filter_log_table' ]
		);
		add_submenu_page(
			'shieldon-settings', __( 'Session Table', 'wp-shieldon' ), __( 'Session Table', 'wp-shieldon' ), 'manage_options', 'shieldon-session-table',
			[ $this, 'session_table' ]
		);
		add_submenu_page(
			'shieldon-settings', __( 'IP Manager', 'wp-shieldon' ), $separate . __( 'IP Manager', 'wp-shieldon' ), 'manage_options', 'shieldon-ip-manager',
			[ $this, 'ip_manager_setting_plugin_page' ]
		);
		add_submenu_page(
			'shieldon-settings', __( 'XSS Protection', 'wp-shieldon' ), __( 'XSS Protection', 'wp-shieldon' ), 'manage_options', 'shieldon-xss-protection',
			[ $this, 'xss_protection' ]
		);
		add_submenu_page(
			'shieldon-settings', __( 'Authentication', 'wp-shieldon' ), __( 'Authentication', 'wp-shieldon' ), 'manage_options', 'shieldon-authentication',
			[ $this, 'authentication' ]
		);
	}

//	function wpso_show_settings_header() {
//		$git_url_core   = 'https://github.com/terrylinooo/shieldon';
//		$git_url_plugin = 'https://github.com/terrylinooo/wp-shieldon';
//		echo '<div class="shieldon-info-bar">';
//		echo '	<div class="logo-info"><img src="' . SHIELDON_PLUGIN_URL . 'includes/assets/images/logo.png" class="shieldon-logo"></div>';
//		echo '	<div class="version-info">';
//		echo '    Core: <a href="' . $git_url_core . '" target="_blank">' . SHIELDON_CORE_VERSION . '</a>  ';
//		echo '    Plugin: <a href="' . $git_url_plugin . '" target="_blank">' . SHIELDON_PLUGIN_VERSION . '</a>  ';
//		echo '  </div>';
//		echo '</div>';
//		echo '<div class="wrap">';
//	}
//
//	public function wpso_show_settings_footer() {
//		echo '</div>';
//	}

	public function setting_plugin_page() {
//		$this->wpso_show_settings_header();
		settings_errors();
		$this->WPSO_Admin_Settings->settings_api->show_navigation();
		$this->WPSO_Admin_Settings->settings_api->show_forms();
//		$this->wpso_show_settings_footer();
	}

	public function ip_manager_setting_plugin_page() {
//		$this->wpso_show_settings_header();
		settings_errors();
		$this->WPSO_Admin_IP_Manager->settings_api->show_navigation();
		$this->WPSO_Admin_IP_Manager->settings_api->show_forms();
//		$this->wpso_show_settings_footer();
	}

	/**
	 * Filters the action links displayed for each plugin in the Network Admin Plugins list table.
	 * @param array  $links Original links.
	 * @param string $file File position.
	 * @return array Combined links.
	 */
	public function plugin_action_links( $links, $file ): array {
		if ( current_user_can( 'manage_options' ) ) {
			if ( $file === SHIELDON_PLUGIN_NAME ) {
				$links[] = '<a href="' . admin_url( 'admin.php?page=shieldon-settings' ) . '">' . __( 'Settings', 'wp-shieldon' ) . '</a>';
			}
		}
		return $links;
	}

	/**
	 * Add links to plugin meta information on plugin list page.
	 * @param array  $links Original links.
	 * @param string $file File position.
	 * @return array Combined links.
	 */
	public function plugin_extend_links( $links, $file ): array {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return $links;
		}
		if ( $file === SHIELDON_PLUGIN_NAME ) {
			$links[] = '<a href="https://github.com/terrylinooo/shieldon" target="_blank">' . __( 'View GitHub project', 'wp-shieldon' ) . '</a>';
			$links[] = '<a href="https://github.com/terrylinooo/shieldon/issues" target="_blank">' . __( 'Report issues', 'wp-shieldon' ) . '</a>';
		}
		return $links;
	}

	public function action_logs() {
		$parser = new ActionLogParser( wpso_get_logs_dir() );
		// To deal with large logs, we need to cahce the parsed results for saving time.
		$log_cache_handler = new ActionLogParsedCache( wpso_get_logs_dir() );
		$tab               = 'today';
		if ( ! empty( $_GET['tab'] ) ) {
			$tab = esc_html( $_GET['tab'] );
		}
		$type = match ( $tab ) {
			'yesterday', 'this_month', 'last_month', 'past_seven_days', 'today' => $tab,
			default => 'today',
		};
		$ip_details_cached_data = $log_cache_handler->get( $type );
		$last_cached_time       = '';
		// If we have cached data then we don't need to parse them again.
		// This will save a lot of time in parsing logs.
		if ( ! empty( $ip_details_cached_data ) ) {
			$data['ip_details']  = $ip_details_cached_data['ip_details'];
			$data['period_data'] = $ip_details_cached_data['period_data'];
			$last_cached_time    = wp_date( 'Y-m-d H:i:s', $ip_details_cached_data['time'] );
			if ( $type === 'today' ) {
				$ip_details_cached_data   = $log_cache_handler->get( 'past_seven_hours' );
				$data['past_seven_hours'] = $ip_details_cached_data['period_data'];
			}
		} else {
			$parser->prepare( $type );
			$data['ip_details']  = $parser->getIpData();
			$data['period_data'] = $parser->getParsedPeriodData();
			$log_cache_handler->save( $type, $data );
			if ( $type === 'today' ) {
				$parser->prepare( 'past_seven_hours' );
				$data['past_seven_hours'] = $parser->getParsedPeriodData();
				$log_cache_handler->save(
					'past_seven_hours',
					[
						'period_data' => $data['past_seven_hours'],
					]
				);
			}
		}
		$data['last_cached_time'] = $last_cached_time;
//		$this->wpso_show_settings_header();
		echo wpso_load_view( 'dashboard/dashboard-' . str_replace( '_', '-', $type ), $data );
//		$this->wpso_show_settings_footer();
	}

	/**
	 * Rule table for current cycle.
	 */
	public function rule_table() {
		//      $wpso = WPSO_Shieldon_Guardian::instance();
		if ( isset( $_POST['ip'] ) && check_admin_referer( 'check_form_for_ip_rule', 'wpso-rule-form' ) ) {
			$ip                             = sanitize_text_field( $_POST['ip'] );
			$action                         = sanitize_text_field( $_POST['action'] );
			$action_code['temporarily_ban'] = Enum::ACTION_TEMPORARILY_DENY;
			$action_code['permanently_ban'] = Enum::ACTION_DENY;
			$action_code['allow']           = Enum::ACTION_ALLOW;
			switch ( $action ) {
				case 'temporarily_ban':
				case 'permanently_ban':
				case 'allow':
					$log_data['log_ip']     = $ip;
					$log_data['ip_resolve'] = gethostbyaddr( $ip );
					$log_data['time']       = time();
					$log_data['type']       = $action_code[ $action ];
					$log_data['reason']     = Enum::REASON_MANUAL_BAN_DENIED;
					$this->wpso->kernel->driver->save( $ip, $log_data, 'rule' );
					break;
				case 'remove':
					$this->wpso->kernel->driver->delete( $ip, 'rule' );
					break;
			}
		}
		$reason_translation_mapping[99]  = __( 'Manually added by the administrator', 'wp-shieldon' );
		$reason_translation_mapping[100] = __( 'Search engine bot', 'wp-shieldon' );
		$reason_translation_mapping[101] = __( 'Google bot', 'wp-shieldon' );
		$reason_translation_mapping[102] = __( 'Bing bot', 'wp-shieldon' );
		$reason_translation_mapping[103] = __( 'Yahoo bot', 'wp-shieldon' );
		$reason_translation_mapping[1]   = __( 'Too many sessions', 'wp-shieldon' );
		$reason_translation_mapping[2]   = __( 'Too many accesses', 'wp-shieldon' );
		$reason_translation_mapping[3]   = __( 'Cannot create JS cookies', 'wp-shieldon' );
		$reason_translation_mapping[4]   = __( 'Empty referrer', 'wp-shieldon' );
		$reason_translation_mapping[11]  = __( 'Daily limit reached', 'wp-shieldon' );
		$reason_translation_mapping[12]  = __( 'Hourly limit reached', 'wp-shieldon' );
		$reason_translation_mapping[13]  = __( 'Minutely limit reached', 'wp-shieldon' );
		$reason_translation_mapping[14]  = __( 'Secondly limit reached', 'wp-shieldon' );
		$reason_translation_mapping[40]  = __( 'Invalid IP', 'wp-shieldon' );
		$reason_translation_mapping[41]  = __( 'Denied by IP manager', 'wp-shieldon' );
		$reason_translation_mapping[42]  = __( 'Allowed by IP manager', 'wp-shieldon' );
		$reason_translation_mapping[81]  = __( 'Denied by component - IP.', 'wp-shieldon' );
		$reason_translation_mapping[82]  = __( 'Denied by component - RDNS.', 'wp-shieldon' );
		$reason_translation_mapping[83]  = __( 'Denied by component - Header.', 'wp-shieldon' );
		$reason_translation_mapping[84]  = __( 'Denied by component - User Agent.', 'wp-shieldon' );
		$reason_translation_mapping[85]  = __( 'Denied by component - Trusted Robot.', 'wp-shieldon' );
		$type_translation_mapping[0]     = __( 'DENY', 'wp-shieldon' );
		$type_translation_mapping[1]     = __( 'ALLOW', 'wp-shieldon' );
		$type_translation_mapping[2]     = __( 'CAPTCHA', 'wp-shieldon' );
		$data['rule_list']               = $this->wpso->kernel->driver->getAll( 'rule' );
		$data['reason_mapping']          = $reason_translation_mapping;
		$data['type_mapping']            = $type_translation_mapping;
		$data['last_reset_time']         = get_option( 'wpso_last_reset_time' );
//		$this->wpso_show_settings_header();
		echo wpso_load_view( 'dashboard/rule-table', $data );
//		$this->wpso_show_settings_footer();
	}

	public function filter_log_table() {
		//      $wpso = new WPSO_Shieldon_Guardian();
		//      $wpso->set_driver();
		$data['ip_log_list']     = $this->wpso->kernel->driver->getAll( 'filter' );
		$data['last_reset_time'] = get_option( 'wpso_last_reset_time' );
//		$this->wpso_show_settings_header();
		echo wpso_load_view( 'dashboard/filter-log-table', $data );
//		$this->wpso_show_settings_footer();
	}

	public function session_table() {
		//      $wpso = WPSO_Shieldon_Guardian::instance();
		$data['session_list']         = $this->wpso->kernel->driver->getAll( 'session' );
		$data['is_session_limit']     = false;
		$data['session_limit_count']  = 0;
		$data['session_limit_period'] = 0;
		$data['online_count']         = 0;
		$data['expires']              = 0;
		if ( wpso_get_option( 'enable_online_session_limit', 'shieldon_daemon' ) === 'yes' ) {
			$data['is_session_limit']     = true;
			$data['session_limit_count']  = wpso_get_option( 'session_limit_count', 'shieldon_daemon' );
			$data['session_limit_period'] = wpso_get_option( 'session_limit_period', 'shieldon_daemon' );
			$data['online_count']         = count( $data['session_list'] );
			$data['expires']              = (int) $data['session_limit_period'] * 60;
		}
		$data['last_reset_time'] = get_option( 'wpso_last_reset_time' );
//		$this->wpso_show_settings_header();
		echo wpso_load_view( 'dashboard/session-table', $data );
//		$this->wpso_show_settings_footer();
	}

	/**
	 * WWW-Authenticate.
	 */
	public function authentication() {
		if ( isset( $_POST['action'] ) && check_admin_referer( 'check_form_authentication', 'wpso_authentication_form' ) ) {
			$authenticated_list = get_option( 'shieldon_authetication' );
			$action             = sanitize_text_field( $_POST['action'] );
			$order              = sanitize_text_field( $_POST['order'] );
			$url                = sanitize_text_field( $_POST['url'] );
			$user               = sanitize_text_field( $_POST['user'] );
			$pass               = sanitize_text_field( $_POST['pass'] );
			if ( empty( $authenticated_list ) ) {
				$authenticated_list = [];
				update_option( 'shieldon_authetication', $authenticated_list );
			}
			if ( $action === 'add' ) {
				$authenticated_list[] = [
					'url'  => $url,
					'user' => $user,
					'pass' => password_hash( $pass, PASSWORD_BCRYPT ),
				];
			} elseif ( $action === 'remove' ) {
				unset( $authenticated_list[ $order ] );
				$authenticated_list = array_values( $authenticated_list );
			}
			update_option( 'shieldon_authetication', $authenticated_list );
		} else {
			// Load the latest authenticated list.
			$authenticated_list = get_option( 'shieldon_authetication' );
		}
		$data                       = [];
		$data['authenticated_list'] = $authenticated_list;
//		$this->wpso_show_settings_header();
		echo wpso_load_view( 'security/authentication', $data );
//		$this->wpso_show_settings_footer();
	}

	public function xss_protection() {
		$default_xss_types  = [
			'get'    => 'no',
			'post'   => 'no',
			'cookie' => 'no',
		];
		$xss_protected_list = [];
		if ( isset( $_POST['xss_post'] ) && check_admin_referer( 'check_form_xss_type', 'wpso_xss_form' ) ) {
			$xss_type           = get_option( 'shieldon_xss_protected_type', $default_xss_types );
			$xss_type['get']    = sanitize_text_field( $_POST['xss_get'] );
			$xss_type['post']   = sanitize_text_field( $_POST['xss_post'] );
			$xss_type['cookie'] = sanitize_text_field( $_POST['xss_cookie'] );
			update_option( 'shieldon_xss_protected_type', $xss_type );
		}
		if ( isset( $_POST['variable'] ) && check_admin_referer( 'check_form_xss_single', 'wpso_xss_form' ) ) {
			$xss_protected_list = get_option( 'shieldon_xss_protected_list', []);
			$action             = sanitize_text_field( $_POST['action'] );
			$order              = sanitize_text_field( $_POST['order'] );
			$type               = sanitize_text_field( $_POST['type'] );
			$variable           = sanitize_text_field( $_POST['variable'] );
			if ( empty( $xss_protected_list ) ) {
				$xss_protected_list = [];
				update_option( 'shieldon_xss_protected_list', $xss_protected_list );
			}
			if ( $action === 'add' ) {
				$xss_protected_list[] = [
					'type'     => $type,
					'variable' => $variable,
				];
			} elseif ( $action === 'remove' ) {
				unset( $xss_protected_list[ $order ] );
				$xss_protected_list = array_values( $xss_protected_list );
			}
			update_option( 'shieldon_xss_protected_list', $xss_protected_list );
		} else {
			$xss_protected_list = get_option( 'shieldon_xss_protected_list', []);
		}
		$xss_type                   = get_option( 'shieldon_xss_protected_type', $default_xss_types );
		$data                       = [];
		$data['xss_protected_list'] = $xss_protected_list;
		$data['xss_type']           = $xss_type;
//		$this->wpso_show_settings_header();
		echo wpso_load_view( 'security/xss-protection', $data );
//		$this->wpso_show_settings_footer();
	}

	/**
	 * @throws \ReflectionException
	 */
	public function overview() {
		//      $shieldon = Container::get( 'shieldon' );
		if ( isset( $_POST['action_type'] ) && $_POST['action_type'] === 'reset_action_logs' ) {
			if ( check_admin_referer( 'check_form_reset_action_logger', 'wpso_reset_action_logger_form' ) ) {
				// Remove all action logs.
				$this->wpso->logger->purgeLogs();
			}
		}
		if ( isset( $_POST['action_type'] ) && $_POST['action_type'] === 'reset_data_circle' ) {
			if ( check_admin_referer( 'check_form_reset_data_circle', 'wpso_reset_data_circle_form' ) ) {
				$last_reset_time = strtotime( wp_date( 'Y-m-d 00:00:00' ) );
				// Record new reset time.
				update_option( 'wpso_last_reset_time', $last_reset_time );
				// Remove all data and rebuild data circle tables.
				$this->wpso->kernel->driver->rebuild();
			}
		}
		/* Logger - All logs were recorded by CustomActionLogger. Get the summary information from those logs. */
		$data['action_logger'] = false;
		if ( ! empty( $this->wpso->kernel->logger ) ) {
			$logger_info           = $this->wpso->kernel->logger->getCurrentLoggerInfo();
			$data['action_logger'] = true;
		}
		$data['logger_started_working_date'] = 'No record';
		$data['logger_work_days']            = '0 day';
		$data['logger_total_size']           = '0 MB';
		if ( ! empty( $logger_info ) ) {
			$i = 0;
			ksort( $logger_info );
			foreach ( $logger_info as $date => $size ) {
				$date = (string) $date;
				if ( ! str_contains( $date, '.json' ) ) {
					if ( $i === 0 ) {
						$data['logger_started_working_date'] = wp_date( 'Y-m-d', strtotime( $date ) );
					}
					$i += (int) $size;
				}
			}
			$data['logger_work_days']  = count( $logger_info );
			$data['logger_total_size'] = round( $i / ( 1024 * 1024 ), 5 ) . ' MB';
		}
		/* Data circle - A data circle includes the primary data tables of Shieldon. They are ip_log_table, ip_rule_table and session_table. */
		$data['rule_list']    = $this->wpso->kernel->driver->getAll( 'rule' );
		$data['ip_log_list']  = $this->wpso->kernel->driver->getAll( 'filter' );
		$data['session_list'] = $this->wpso->kernel->driver->getAll( 'session' );
		/* Shieldon status
			1. Components.
			2. Filters.
			3. Configuration.
			4. Captcha modules.
			5. Messenger modules.
		*/
		$data['components'] = [
			'Ip'         => ! empty( $this->wpso->kernel->component['Ip'] ),
			'TrustedBot' => ! empty( $this->wpso->kernel->component['TrustedBot'] ),
			'Header'     => ! empty( $this->wpso->kernel->component['Header'] ),
			'Rdns'       => ! empty( $this->wpso->kernel->component['Rdns'] ),
			'UserAgent'  => ! empty( $this->wpso->kernel->component['UserAgent'] ),
		];
		$reflection         = new ReflectionObject( $this->wpso->kernel );
		$t1                 = $reflection->getProperty( 'filterStatus' );
		$filter_status      = $t1->getValue( $this->wpso->kernel );
		$t5                 = $reflection->getProperty( 'properties' );
		$t6                 = $reflection->getProperty( 'captcha' );
		$t7                 = $reflection->getProperty( 'messenger' );
		$t1->setAccessible( true );
		$t5->setAccessible( true );
		$t6->setAccessible( true );
		$t7->setAccessible( true );
		$enable_cookie_check    = $filter_status['cookie'];
		$enable_session_check   = $filter_status['session'];
		$enable_frequency_check = $filter_status['frequency'];
		$enable_referer_check   = $filter_status['referer'];
		$properties             = $t5->getValue( $this->wpso->kernel );
		$captcha                = $t6->getValue( $this->wpso->kernel );
		$messengers             = $t7->getValue( $this->wpso->kernel );
		$data['filters']        = [
			'cookie'    => $enable_cookie_check,
			'session'   => $enable_session_check,
			'frequency' => $enable_frequency_check,
			'referer'   => $enable_referer_check,
		];
		$data['configuration']  = $properties;
		$data['driver']         = [
			'mysql'  => $this->wpso->kernel->driver instanceof MysqlDriver,
			'redis'  => $this->wpso->kernel->driver instanceof RedisDriver,
			'file'   => $this->wpso->kernel->driver instanceof FileDriver,
			'sqlite' => $this->wpso->kernel->driver instanceof SqliteDriver,
		];
		$data['captcha']        = [
			'ReCaptcha'    => isset( $captcha['ReCaptcha'] ),
			'ImageCaptcha' => isset( $captcha['ImageCaptcha'] ),
		];
		$operating_messengers   = [
			'telegram'   => false,
			'linenotify' => false,
			'sendgrid'   => false,
		];
		foreach ( $messengers as $messenger ) {
			$class = get_class( $messenger );
			$class = strtolower( substr( $class, strrpos( $class, '\\' ) + 1 ) );
			if ( isset( $operating_messengers[ $class ] ) ) {
				$operating_messengers[ $class ] = true;
			}
		}
		$data['messengers'] = $operating_messengers;
//		$this->wpso_show_settings_header();
		echo wpso_load_view( 'dashboard/overview', $data );
//		$this->wpso_show_settings_footer();
	}

	/* Operation status and real-time stats of current data circle. */
	/**
	 * @throws \ReflectionException
	 */
	public function operation_status() {
		//      $shieldon = Container::get( 'shieldon' );
		$data['components']     = [
			'Ip'         => ! empty( $this->wpso->kernel->component['Ip'] ),
			'TrustedBot' => ! empty( $this->wpso->kernel->component['TrustedBot'] ),
			'Header'     => ! empty( $this->wpso->kernel->component['Header'] ),
			'Rdns'       => ! empty( $this->wpso->kernel->component['Rdns'] ),
			'UserAgent'  => ! empty( $this->wpso->kernel->component['UserAgent'] ),
		];
		$reflection             = new ReflectionObject( $this->wpso->kernel );
		$t1                     = $reflection->getProperty( 'filterStatus' );
		$filter_status          = $t1->getValue( $this->wpso->kernel );
		$enable_cookie_check    = $filter_status['cookie'];
		$enable_session_check   = $filter_status['session'];
		$enable_frequency_check = $filter_status['frequency'];
		$enable_referer_check   = $filter_status['referer'];
		$data['filters']        = [
			'cookie'    => $enable_cookie_check,
			'session'   => $enable_session_check,
			'frequency' => $enable_frequency_check,
			'referer'   => $enable_referer_check,
		];
		$rule_list              = $this->wpso->kernel->driver->getAll( 'rule' );
		// Components.
		$data['component_ip']         = 0;
		$data['component_trustedbot'] = 0;
		$data['component_rdns']       = 0;
		$data['component_header']     = 0;
		$data['component_useragent']  = 0;
		// Filters.
		$data['filter_frequency'] = 0;
		$data['filter_referer']   = 0;
		$data['filter_cookie']    = 0;
		$data['filter_session']   = 0;
		// Components.
		$data['rule_list']['ip']         = [];
		$data['rule_list']['trustedbot'] = [];
		$data['rule_list']['rdns']       = [];
		$data['rule_list']['header']     = [];
		$data['rule_list']['useragent']  = [];
		// Filters.
		$data['rule_list']['frequency'] = [];
		$data['rule_list']['referer']   = [];
		$data['rule_list']['cookie']    = [];
		$data['rule_list']['session']   = [];
		foreach ( $rule_list as $rule_info ) {
			switch ( $rule_info['reason'] ) {
				case Enum::REASON_DENY_IP_DENIED:
				case Enum::REASON_COMPONENT_IP_DENIED:
					++$data['component_ip'];
					$data['rule_list']['ip'][] = $rule_info;
					break;
				case Enum::REASON_COMPONENT_RDNS_DENIED:
					++$data['component_rdns'];
					$data['rule_list']['rdns'][] = $rule_info;
					break;
				case Enum::REASON_COMPONENT_HEADER_DENIED:
					++$data['component_header'];
					$data['rule_list']['header'][] = $rule_info;
					break;
				case Enum::REASON_COMPONENT_USERAGENT_DENIED:
					++$data['component_useragent'];
					$data['rule_list']['useragent'][] = $rule_info;
					break;
				case Enum::REASON_COMPONENT_TRUSTED_ROBOT_DENIED:
					++$data['component_trustedbot'];
					$data['rule_list']['trustedbot'][] = $rule_info;
					break;
				case Enum::REASON_TOO_MANY_ACCESSE_DENIED:
				case Enum::REASON_REACH_DAILY_LIMIT_DENIED:
				case Enum::REASON_REACH_HOURLY_LIMIT_DENIED:
				case Enum::REASON_REACH_MINUTELY_LIMIT_DENIED:
				case Enum::REASON_REACH_SECONDLY_LIMIT_DENIED:
					++$data['filter_frequency'];
					$data['rule_list']['frequency'][] = $rule_info;
					break;
				case Enum::REASON_EMPTY_REFERER_DENIED:
					++$data['filter_referer'];
					$data['rule_list']['referer'][] = $rule_info;
					break;
				case Enum::REASON_EMPTY_JS_COOKIE_DENIED:
					++$data['filter_cookie'];
					$data['rule_list']['cookie'][] = $rule_info;
					break;
				case Enum::REASON_TOO_MANY_SESSIONS_DENIED:
					++$data['filter_session'];
					$data['rule_list']['session'][] = $rule_info;
					break;
			}
		}
//		$reasons                = [
//			Enum::REASON_MANUAL_BAN_DENIED              => __( 'Manually added by the administrator', 'wp-shieldon' ),
//			Enum::REASON_IS_SEARCH_ENGINE_ALLOWED       => __( 'Search engine bot', 'wp-shieldon' ),
//			Enum::REASON_IS_GOOGLE_ALLOWED              => __( 'Google bot', 'wp-shieldon' ),
//			Enum::REASON_IS_BING_ALLOWED                => __( 'Bing bot', 'wp-shieldon' ),
//			Enum::REASON_IS_YAHOO_ALLOWED               => __( 'Yahoo bot', 'wp-shieldon' ),
//			Enum::REASON_TOO_MANY_SESSIONS_DENIED       => __( 'Too many sessions', 'wp-shieldon' ),
//			Enum::REASON_TOO_MANY_ACCESSE_DENIED        => __( 'Too many accesses', 'wp-shieldon' ),
//			Enum::REASON_EMPTY_JS_COOKIE_DENIED         => __( 'Cannot create JS cookies', 'wp-shieldon' ),
//			Enum::REASON_EMPTY_REFERER_DENIED           => __( 'Empty referrer', 'wp-shieldon' ),
//			Enum::REASON_REACH_DAILY_LIMIT_DENIED       => __( 'Daily limit reached', 'wp-shieldon' ),
//			Enum::REASON_REACH_HOURLY_LIMIT_DENIED      => __( 'Hourly limit reached', 'wp-shieldon' ),
//			Enum::REASON_REACH_MINUTELY_LIMIT_DENIED    => __( 'Minutely limit reached', 'wp-shieldon' ),
//			Enum::REASON_REACH_SECONDLY_LIMIT_DENIED    => __( 'Secondly limit reached', 'wp-shieldon' ),
//			Enum::REASON_INVALID_IP_DENIED              => __( 'Invalid IP address.', 'wp-shieldon' ),
//			Enum::REASON_DENY_IP_DENIED                 => __( 'Denied by IP component.', 'wp-shieldon' ),
//			Enum::REASON_ALLOW_IP_DENIED                => __( 'Allowed by IP component.', 'wp-shieldon' ),
//			Enum::REASON_COMPONENT_IP_DENIED            => __( 'Denied by IP component.', 'wp-shieldon' ),
//			Enum::REASON_COMPONENT_RDNS_DENIED          => __( 'Denied by RDNS component.', 'wp-shieldon' ),
//			Enum::REASON_COMPONENT_HEADER_DENIED        => __( 'Denied by Header component.', 'wp-shieldon' ),
//			Enum::REASON_COMPONENT_USERAGENT_DENIED     => __( 'Denied by User-agent component.', 'wp-shieldon' ),
//			Enum::REASON_COMPONENT_TRUSTED_ROBOT_DENIED => __( 'Identified as a fake search engine.', 'wp-shieldon' ),
//		];
//		$types                  = [
//			Enum::ACTION_DENY             => 'DENY',
//			Enum::ACTION_ALLOW            => 'ALLOW',
//			Enum::ACTION_TEMPORARILY_DENY => 'CAPTCHA',
//		];
//		$data['reason_mapping'] = $reasons;
//		$data['type_mapping']   = $types;
		$data['panel_title']    = [
			'ip'         => __( 'IP', 'wp-shieldon' ),
			'trustedbot' => __( 'Trusted Bot', 'wp-shieldon' ),
			'header'     => __( 'Header', 'wp-shieldon' ),
			'rdns'       => __( 'RDNS', 'wp-shieldon' ),
			'useragent'  => __( 'User Agent', 'wp-shieldon' ),
			'frequency'  => __( 'Frequency', 'wp-shieldon' ),
			'referer'    => __( 'Referrer', 'wp-shieldon' ),
			'session'    => __( 'Session', 'wp-shieldon' ),
			'cookie'     => __( 'Cookie', 'wp-shieldon' ),
		];
//		$this->wpso_show_settings_header();
		echo wpso_load_view( 'dashboard/operation-status', $data );
//		$this->wpso_show_settings_footer();
	}
}
