<?php
declare(strict_types=1);
namespace WPShieldon;
use Exception;
use Monolog\Logger;
use PDO;
use PDOException;
use Redis;
use RedisException;
use Shieldon\Psr15\RequestHandler;
use Shieldon\Security\Xss;
use WPShieldon\Firewall\Captcha\ImageCaptcha;
use WPShieldon\Firewall\Captcha\ReCaptcha;
use WPShieldon\Firewall\Component\Header;
use WPShieldon\Firewall\Component\Ip;
use WPShieldon\Firewall\Component\Rdns;
use WPShieldon\Firewall\Component\TrustedBot;
use WPShieldon\Firewall\Component\UserAgent;
use WPShieldon\Firewall\Driver\FileDriver;
use WPShieldon\Firewall\Driver\MysqlDriver;
use WPShieldon\Firewall\Driver\RedisDriver;
use WPShieldon\Firewall\Driver\SqliteDriver;
use WPShieldon\Firewall\GlobalHelper;
use WPShieldon\Firewall\Helpers;
use WPShieldon\Firewall\HttpResolver;
use WPShieldon\Firewall\Kernel;
use WPShieldon\Firewall\Kernel\Enum;
use WPShieldon\Firewall\Log\CustomActionLogger;
use WPShieldon\Firewall\Middleware\HttpAuthentication;
use WPShieldon\Firewall\SessionHelper;

class WPSO_Shieldon_Guardian {
	public Kernel $kernel;
	public Logger $psrlogger;
	public SessionHelper $sessionHelper;
	public GlobalHelper $globalHelper;
	private string $current_url;
	private array $middlewares = [];

	public function __construct() {
		$this->kernel      = new Kernel();
		$this->current_url = $_SERVER['REQUEST_URI'];
	}

	public function init( Logger $psrlogger, SessionHelper $sessionHelper, GlobalHelper $globalHelper ) {
		$this->psrlogger       = $psrlogger;
		$this->sessionHelper   = $sessionHelper;
		$this->globalHelper    = $globalHelper;
		static $is_initialized = false;
		if ( $is_initialized ) {
			return;
		}
		$this->set_client_current_ip();
		$this->set_driver();
		$this->reset_logs();
		$this->set_logger();
		$this->set_filters();
		$this->initComponents();
		$this->set_captcha();
		$this->set_session_limit();
		$this->set_authentication();
		$this->set_xss_protection();
		$is_initialized = true;
	}

	private function set_client_current_ip() {
		$ip_source = wpso_get_option( 'ip_source', 'shieldon_daemon' );
		switch ( $ip_source ) {
			case 'HTTP_CF_CONNECTING_IP':
				if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
					$this->kernel->setIp( $_SERVER['HTTP_CF_CONNECTING_IP'], true );
				}
				break;
			case 'HTTP_X_FORWARDED_FOR':
				if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
					$this->kernel->setIp( $_SERVER['HTTP_X_FORWARDED_FOR'], true );
				}
				break;
			case 'HTTP_X_FORWARDED_HOST':
				if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
					$this->kernel->setIp( $_SERVER['HTTP_X_FORWARDED_HOST'], true );
				}
				break;
			case 'REMOTE_ADDR':
			default:
				$this->kernel->setIp( $_SERVER['REMOTE_ADDR'], true );
		}
	}

	public function set_driver() {
		$driver_type = wpso_get_option( 'data_driver_type', 'shieldon_daemon' );
		// Set Channel, for WordPress multisite network.
		$this->kernel->setChannel( wpso_get_channel_id() );
		switch ( $driver_type ) {
			case 'reids':
				try {
					$redis_instance = new Redis();
					$redis_instance->connect( '127.0.0.1' );
					$this->kernel->setDriver( new RedisDriver( $redis_instance ) );
				} catch ( RedisException $e ) {
					$this->psrlogger->warning( 'Shieldon - Error setting driver redis: ' . $e->getMessage() );
					$this->psrlogger->warning( 'Shieldon - ' . $e->getTraceAsString() );
					return;
				}
				break;
			case 'file':
				try {
					$this->kernel->setDriver( new FileDriver( wpso_get_upload_dir() ) );
				} catch ( Exception $e ) {
					$this->psrlogger->warning( 'Shieldon - Error setting driver file: ' . $e->getMessage() );
					$this->psrlogger->warning( 'Shieldon - ' . $e->getTraceAsString() );
					return;
				}
				break;
			case 'sqlite':
				try {
					$sqlite_location = wpso_get_upload_dir() . '/shieldon.sqlite3';
					// phpcs:ignore
					$pdo_instance = new PDO('sqlite:' . $sqlite_location);
					$this->kernel->setDriver( new SqliteDriver( $pdo_instance ) );
				} catch ( PDOException $e ) {
					$this->psrlogger->warning( 'Shieldon - Error setting driver sqlite: ' . $e->getMessage() );
					$this->psrlogger->warning( 'Shieldon - ' . $e->getTraceAsString() );
					return;
				}
				break;
			case 'mysql':
			default:
				// Read database settings from wp-config.php
				$db = [
					'host'    => DB_HOST,
					'dbname'  => DB_NAME,
					'user'    => DB_USER,
					'pass'    => DB_PASSWORD,
					'charset' => DB_CHARSET,
				];
				try {
					$pdo_conn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'] . ';charset=' . $db['charset'];
					// phpcs:ignore
					$pdo_instance = new PDO($pdo_conn, $db['user'], $db['pass']);
					$this->kernel->setDriver( new MysqlDriver( $pdo_instance ) );
				} catch ( PDOException $e ) {
					$this->psrlogger->warning( 'Shieldon - Error setting driver mysql: ' . $e->getMessage() );
					$this->psrlogger->warning( 'Shieldon - ' . $e->getTraceAsString() );
					return;
				}
		}
	}

	/**
	 * Clear all logs from Data driver.
	 * @return void
	 */
	private function reset_logs() {
		if ( wpso_get_option( 'reset_data_circle', 'shieldon_daemon' ) !== 'yes' ) {
			return;
		}
		$now_time        = time();
		$last_reset_time = get_option( 'wpso_last_reset_time' );
		if ( empty( $last_reset_time ) ) {
			$last_reset_time = strtotime( wp_date( 'Y-m-d 00:00:00' ) );
		} else {
			$last_reset_time = (int) $last_reset_time;
		}
		if ( ( $now_time - $last_reset_time ) > 86400 ) {
			$last_reset_time = strtotime( wp_date( 'Y-m-d 00:00:00' ) );
			// Record new reset time.
			update_option( 'wpso_last_reset_time', $last_reset_time );
			// Remove all data.
			$this->kernel->driver->rebuild();
		}
	}

	/**
	 * Set Action Logger.
	 * @return void
	 */
	public function set_logger() {
		if ( wpso_get_option( 'enable_action_logger', 'shieldon_daemon' ) === 'yes' ) {
			$this->kernel->setLogger( new CustomActionLogger( wpso_get_logs_dir() ) );
		}
	}

	public function set_filters() {
		$filter_config = [
			'session'   => wpso_get_option( 'enable_filter_session', 'shieldon_filter' ) === 'yes',
			'cookie'    => wpso_get_option( 'enable_filter_cookie', 'shieldon_filter' ) === 'yes',
			'referer'   => wpso_get_option( 'enable_filter_referer', 'shieldon_filter' ) === 'yes',
			'frequency' => wpso_get_option( 'enable_filter_frequency', 'shieldon_filter' ) === 'yes',
		];
		$this->kernel->setFilters( $filter_config );
		if ( $filter_config['frequency'] ) {
			$time_unit_quota_s = wpso_get_option( 'time_unit_quota_s', 'shieldon_filter' );
			$time_unit_quota_m = wpso_get_option( 'time_unit_quota_m', 'shieldon_filter' );
			$time_unit_quota_h = wpso_get_option( 'time_unit_quota_h', 'shieldon_filter' );
			$time_unit_quota_d = wpso_get_option( 'time_unit_quota_d', 'shieldon_filter' );
			$time_unit_quota   = [
				's' => is_numeric( $time_unit_quota_s ) && ! empty( $time_unit_quota_s ) ? (int) $time_unit_quota_s : 2,
				'm' => is_numeric( $time_unit_quota_m ) && ! empty( $time_unit_quota_m ) ? (int) $time_unit_quota_m : 10,
				'h' => is_numeric( $time_unit_quota_h ) && ! empty( $time_unit_quota_h ) ? (int) $time_unit_quota_h : 30,
				'd' => is_numeric( $time_unit_quota_d ) && ! empty( $time_unit_quota_d ) ? (int) $time_unit_quota_d : 60,
			];
			$this->kernel->setProperty( 'time_unit_quota', $time_unit_quota );
		}
		if ( $filter_config['cookie'] ) {
			add_action( 'wp_print_footer_scripts', [ $this, 'front_print_footer_scripts' ]);
		}
	}

	public function initComponents() {
		$this->kernel->setComponent( new Ip() );
		$this->ip_manager();
		if ( wpso_get_option( 'enable_component_trustedbot', 'shieldon_component' ) === 'yes' ) {
			$this->kernel->setComponent( new TrustedBot() );
		}
		if ( wpso_get_option( 'enable_component_header', 'shieldon_component' ) === 'yes' ) {
			if ( wpso_get_option( 'header_strict_mode', 'shieldon_component' ) === 'yes' ) {
				$this->kernel->setComponent( new Header( true ) );
			}
			$this->kernel->setComponent( new Header( false ) );
		}
		if ( wpso_get_option( 'enable_component_agent', 'shieldon_component' ) === 'yes' ) {
			if ( wpso_get_option( 'agent_strict_mode', 'shieldon_component' ) === 'yes' ) {
				$this->kernel->setComponent( new UserAgent( true ) );
			}
			$this->kernel->setComponent( new UserAgent( false ) );
		}
		if ( wpso_get_option( 'enable_component_rdns', 'shieldon_component' ) === 'yes' ) {
			if ( wpso_get_option( 'rdns_strict_mode', 'shieldon_component' ) === 'yes' ) {
				$this->kernel->setComponent( new Rdns( true ) );
			} else {
				$this->kernel->setComponent( new Rdns( false ) );
			}
		}
	}

	private function ip_manager() {
		if ( str_starts_with( $this->current_url, '/wp-login.php' ) ) {
			// Login page.
			$login_whitelist = wpso_get_option( 'ip_login_whitelist', 'shieldon_ip_login' );
			$login_blacklist = wpso_get_option( 'ip_login_blacklist', 'shieldon_ip_login' );
			$login_deny_all  = wpso_get_option( 'ip_login_deny_all', 'shieldon_ip_login' );
			if ( ! empty( $login_whitelist ) ) {
				$whitelist = explode( PHP_EOL, $login_whitelist );
				$this->kernel->component['Ip']->setAllowedItems( $whitelist );
			}
			if ( ! empty( $login_blacklist ) ) {
				$blacklist = explode( PHP_EOL, $login_blacklist );
				$this->kernel->component['Ip']->setDeniedItems( $blacklist );
			}
			$passcode         = wpso_get_option( 'deny_all_passcode', 'shieldon_ip_login' );
			$passcode_confirm = '';
			if ( ! empty( $_COOKIE['wp_shieldon_passcode'] ) ) {
				$passcode_confirm = $_COOKIE['wp_shieldon_passcode'];
			}
			if ( ! empty( $passcode ) && isset( $_GET[ $passcode ] ) ) {
				if ( empty( $_COOKIE['wp_shieldon_passcode'] ) ) {
					setcookie( 'wp_shieldon_passcode', $passcode, time() + 86400 );
				}
				$passcode_confirm = $passcode;
			}
			if ( $login_deny_all === 'yes' ) {
				if ( $passcode_confirm !== $passcode ) {
					$this->kernel->component['Ip']->denyAll();
				}
			}
		} elseif ( str_starts_with( $this->current_url, '/wp-signup.php' ) ) {
			// Signup page.
			$signup_whitelist = wpso_get_option( 'ip_signup_whitelist', 'shieldon_ip_signup' );
			$signup_blacklist = wpso_get_option( 'ip_signup_blacklist', 'shieldon_ip_signup' );
			$signup_deny_all  = wpso_get_option( 'ip_signup_deny_all', 'shieldon_ip_signup' );
			if ( ! empty( $signup_whitelist ) ) {
				$whitelist = explode( PHP_EOL, $signup_whitelist );
				$this->kernel->component['Ip']->setAllowedItems( $whitelist );
			}
			if ( ! empty( $signup_blacklist ) ) {
				$blacklist = explode( PHP_EOL, $signup_blacklist );
				$this->kernel->component['Ip']->setDeniedItems( $blacklist );
			}
			if ( $signup_deny_all === 'yes' ) {
				$this->kernel->component['Ip']->denyAll();
			}
		} elseif ( str_starts_with( $this->current_url, '/xmlrpc.php' ) ) {
			// XML RPC.
			$xmlrpc_whitelist = wpso_get_option( 'ip_xmlrpc_whitelist', 'shieldon_ip_xmlrpc' );
			$xmlrpc_blacklist = wpso_get_option( 'ip_xmlrpc_blacklist', 'shieldon_ip_xmlrpc' );
			$xmlrpc_deny_all  = wpso_get_option( 'ip_xmlrpc_deny_all', 'shieldon_ip_xmlrpc' );
			if ( ! empty( $xmlrpc_whitelist ) ) {
				$whitelist = explode( PHP_EOL, $xmlrpc_whitelist );
				$this->kernel->component['Ip']->setAllowedItems( $whitelist );
			}
			if ( ! empty( $xmlrpc_blacklist ) ) {
				$blacklist = explode( PHP_EOL, $xmlrpc_blacklist );
				$this->kernel->component['Ip']->setDeniedItems( $blacklist );
			}
			if ( $xmlrpc_deny_all === 'yes' ) {
				$this->kernel->component['Ip']->denyAll();
			}
		} else {
			// Global.
			$global_whitelist = wpso_get_option( 'ip_global_whitelist', 'shieldon_ip_global' );
			$global_blacklist = wpso_get_option( 'ip_global_blacklist', 'shieldon_ip_global' );
			$global_deny_all  = wpso_get_option( 'ip_global_deny_all', 'shieldon_ip_global' );
			if ( ! empty( $global_whitelist ) ) {
				$whitelist = explode( PHP_EOL, $global_whitelist );
				$this->kernel->component['Ip']->setAllowedItems( $whitelist );
			}
			if ( ! empty( $global_blacklist ) ) {
				$blacklist = explode( PHP_EOL, $global_blacklist );
				$this->kernel->component['Ip']->setDeniedItems( $blacklist );
			}
			if ( $global_deny_all === 'yes' ) {
				$this->kernel->component['Ip']->denyAll();
			}
		}
	}

	public function set_captcha() {
		if ( wpso_get_option( 'enable_captcha_google', 'shieldon_captcha' ) === 'yes' ) {
			$google_captcha_config = [
				'key'     => wpso_get_option( 'google_recaptcha_key', 'shieldon_captcha' ),
				'secret'  => wpso_get_option( 'google_recaptcha_secret', 'shieldon_captcha' ),
				'version' => wpso_get_option( 'google_recaptcha_version', 'shieldon_captcha' ),
				'lang'    => wpso_get_option( 'google_recaptcha_version', 'shieldon_captcha' ),
			];
			$this->kernel->setCaptcha( new ReCaptcha( $google_captcha_config ) );
		}
		if ( wpso_get_option( 'enable_captcha_image', 'shieldon_captcha' ) === 'yes' ) {
			$image_captcha_type = wpso_get_option( 'image_captcha_type', 'shieldon_captcha' );
			switch ( $image_captcha_type ) {
				case 'numeric':
					$image_captcha_config['pool'] = '0123456789';
					break;
				case 'alpha':
					$image_captcha_config['pool'] = '0123456789abcdefghijklmnopqrstuvwxyz';
					break;
				case 'alnum':
				default:
					$image_captcha_config['pool'] = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			}
			$image_captcha_config['word_length'] = wpso_get_option( 'image_captcha_length', 'shieldon_captcha' );
			$this->kernel->setCaptcha( new ImageCaptcha( $image_captcha_config ) );
		}
	}

	/**
	 * Set online session limit.
	 * @return void
	 */
	public function set_session_limit() {
		if ( wpso_get_option( 'enable_online_session_limit', 'shieldon_daemon' ) === 'yes' ) {
			$online_users = wpso_get_option( 'session_limit_count', 'shieldon_daemon' );
			$alive_period = wpso_get_option( 'session_limit_period', 'shieldon_daemon' );
			$online_users = ( is_numeric( $online_users ) && ! empty( $online_users ) ) ? ( (int) $online_users ) : 100;
			$alive_period = ( is_numeric( $alive_period ) && ! empty( $alive_period ) ) ? ( (int) $alive_period * 60 ) : 300;
			$this->kernel->limitSession( $online_users, $alive_period );
		}
	}

	/**
	 * Set the URLs that are protected by WWW-Authenticate protocol.
	 * @return void
	 */
	public function set_authentication() {
		$authenticated_list = get_option( 'shieldon_authetication' );
		if ( ! empty( $authenticated_list ) ) {
			$this->middlewares[] = new HttpAuthentication( $authenticated_list );
		}
	}

	/**
	 * Set Xss Protection.
	 * @return void
	 */
	public function set_xss_protection() {
		$xss_protection_options = get_option( 'shieldon_xss_protection' );
		$xss_filter             = new Xss();
		if ( ! empty( $xss_protection_options['post'] ) ) {
			$this->kernel->setClosure('xss_post',
				function () use ( $xss_filter ) {
					if ( ! empty( $_POST ) ) {
						foreach ( array_keys( $_POST ) as $k ) {
							$_POST[ $k ] = $xss_filter->clean( $_POST[ $k ] );
						}
					}
				}
			);
		}
		if ( ! empty( $xss_protection_options['get'] ) ) {
			$this->kernel->setClosure(
				'xss_get',
				function () use ( $xss_filter ) {
					if ( ! empty( $_GET ) ) {
						foreach ( array_keys( $_GET ) as $k ) {
							$_GET[ $k ] = $xss_filter->clean( $_GET[ $k ] );
						}
					}
				}
			);
		}
		if ( ! empty( $xss_protection_options['cookie'] ) ) {
			$this->kernel->setClosure(
				'xss_cookie',
				function () use ( $xss_filter ) {
					if ( ! empty( $_COOKIE ) ) {
						foreach ( array_keys( $_COOKIE ) as $k ) {
							$_COOKIE[ $k ] = $xss_filter->clean( $_COOKIE[ $k ] );
						}
					}
				}
			);
		}
		$xss_protected_list = get_option( 'shieldon_xss_protected_list' );
		if ( ! empty( $xss_protected_list ) ) {
			$this->kernel->setClosure(
				'xss_protection',
				function () use ( $xss_filter, $xss_protected_list ) {
					foreach ( $xss_protected_list as $v ) {
						$k = $v['variable'] ?? 'undefined';
						switch ( $v['type'] ) {
							case 'get':
								if ( ! empty( $_GET[ $k ] ) ) {
									$_GET[ $k ] = $xss_filter->clean( $_GET[ $k ] );
								}
								break;
							case 'post':
								if ( ! empty( $_POST[ $k ] ) ) {
									$_POST[ $k ] = $xss_filter->clean( $_POST[ $k ] );
								}
								break;
							case 'cookie':
								if ( ! empty( $_COOKIE[ $k ] ) ) {
									$_COOKIE[ $k ] = $xss_filter->clean( $_COOKIE[ $k ] );
								}
								break;
							default:
						}
					}
				}
			);
		}
	}

	public function run() {
		if ( $this->is_excluded_list() ) {
			return;
		}
		$is_driver_reset = get_option( 'wpso_driver_reset' );
		if ( $is_driver_reset === 'no' ) {
			$this->kernel->createDatabase( false );
		}
		$request_handler = new RequestHandler();
		$http_resolver   = new HttpResolver();
		$response        = Helpers::get_request();

		# Middlewares is used for the HttpAuthentication feature
		foreach ( $this->middlewares as $middleware ) {
			$request_handler->add( $middleware );
		}

		$response = $request_handler->handle( $response );
		if ( $response->getStatusCode() !== Enum::HTTP_STATUS_OK ) {
			$http_resolver( $response );
		}
		$result = $this->kernel->run();
		if ( Enum::RESPONSE_ALLOW !== $result ) {
			$this->psrlogger->warning( 'run - Result = ' . $result );
			if ( $is_driver_reset === 'yes' ) {
				update_option( 'wpso_driver_reset', 'no' );
			}
			$captchaResponse = $this->kernel->captchaResponse();
			if ( $captchaResponse ) {
				$this->kernel->unban();
				return;
			}
			$response      = $this->kernel->respond();
			$http_resolver = new HttpResolver();
			$http_resolver( $response );
		}
	}

	private function is_excluded_list(): bool {
		// Prevent blocking server IP.
		$actual_link = ( empty( $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
		if ( str_starts_with( $this->kernel->getIp(), '127.0.' ) ) {
			$this->psrlogger->warning( 'is_excluded_list 127.0. true: ' . $this->kernel->getIp() . ' - URL: ' . $actual_link );
			return true;
		}
		if ( str_starts_with( $this->kernel->getIp(), '192.168.' ) ) {
			$this->psrlogger->warning( 'is_excluded_list 192.168. true: ' . $this->kernel->getIp() . ' - URL: ' . $actual_link );
			return true;
		}
		if ( isset( $_SERVER['SERVER_ADDR'] ) && $this->kernel->getIp() === $_SERVER['SERVER_ADDR'] ) {
			$this->psrlogger->warning( 'is_excluded_list matches SERVER_ADDR true' );
			return true;
		}
		$list = wpso_get_option( 'excluded_urls', 'shieldon_exclusion' );
		$urls = [];
		if ( ! empty( $list ) ) {
			$urls = explode( PHP_EOL, $list );
		}
		$blog_install_dir = parse_url( get_site_url(), PHP_URL_PATH );
		if ( $blog_install_dir === '/' ) {
			$blog_install_dir = '';
		}
		// `Save draft` will use this path.
		if ( wpso_get_option( 'ignore_wp_json', 'shieldon_exclusion' ) === 'yes' ) {
			$urls[] = $blog_install_dir . '/wp-json/';
		}
		// Customer preview
		if ( wpso_get_option( 'ignore_wp_theme_customizer', 'shieldon_exclusion' ) === 'yes' ) {
			$urls[] = $blog_install_dir . '/?customize_changeset_uuid=';
		}
		foreach ( $urls as $url ) {
			if ( str_starts_with( $this->current_url, $url ) ) {
				$this->psrlogger->warning( 'is_excluded_list true url excluded' );
				return true;
			}
		}
		// Login page.
		if ( wpso_get_option( 'ignore_page_login', 'shieldon_exclusion' ) === 'yes' ) {
			if ( str_starts_with( $this->current_url, '/wp-login.php' ) ) {
				$this->psrlogger->warning( 'is_excluded_list true login page' );
				return true;
			}
		}
		// Signup page.
		if ( wpso_get_option( 'ignore_page_signup', 'shieldon_exclusion' ) === 'yes' ) {
			if ( str_starts_with( $this->current_url, '/wp-signup.php' ) ) {
				$this->psrlogger->warning( 'is_excluded_list true signup page' );
				return true;
			}
		}
		// XML RPC.
		if ( wpso_get_option( 'ignore_wp_xmlrpc', 'shieldon_exclusion' ) === 'yes' ) {
			if ( str_starts_with( $this->current_url, '/xmlrpc.php' ) ) {
				$this->psrlogger->warning( 'is_excluded_list true xmlrpc' );
				return true;
			}
		}
		$this->psrlogger->warning( 'is_excluded_list false' );
		return false;
	}

	public function front_print_footer_scripts() {
		//      $this->psrlogger->warning('front_print_footer_scripts');
		echo $this->kernel->getJavascript();
	}
}
