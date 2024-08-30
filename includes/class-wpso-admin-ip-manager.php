<?php
declare(strict_types=1);
namespace WPShieldon;
class WPSO_Admin_IP_Manager {
	public static array $settings          = [];
	public ?WPSO_Setting_API $settings_api = null;

	public function __construct() {
		if ( ! $this->settings_api ) {
			$this->settings_api = new WPSO_Setting_API();
		}
		add_action( 'admin_init', [ $this, 'setting_admin_init' ]);
	}

	public function setting_admin_init() {
		$this->settings_api->set_sections( $this->get_sections() );
		self::$settings = $this->get_fields();
		$this->settings_api->set_fields( self::$settings );
		$this->settings_api->admin_init();
	}

	public function get_sections(): array {
		return [
			[
				'id'    => 'shieldon_ip_global',
				'title' => __( 'Global', 'wp-shieldon' ),
			],
			[
				'id'    => 'shieldon_ip_login',
				'title' => __( 'Login', 'wp-shieldon' ),
			],
			[
				'id'    => 'shieldon_ip_signup',
				'title' => __( 'Signup', 'wp-shieldon' ),
			],
			[
				'id'    => 'shieldon_ip_xmlrpc',
				'title' => __( 'XML RPC', 'wp-shieldon' ),
			],
		];
	}

	public function get_fields(): array {
		return [
			'shieldon_ip_global' => [
				[
					'label'         => __( 'Whitelist', 'wp-shieldon' ),
					'section_title' => true,
					'desc'          => '<i class="far fa-thumbs-up"></i>',
				],
				[
					'name'        => 'ip_global_whitelist',
					'label'       => __( 'IP List', 'wp-shieldon' ),
					'desc'        => wpso_load_view( 'setting/ip-manager' ),
					'placeholder' => '',
					'type'        => 'textarea',
				],
				[
					'name'    => 'ip_global_deny_all',
					'label'   => __( 'Deny All', 'wp-shieldon' ),
					'desc'    => wpso_load_view( 'setting/ip-manager-strict' ),
					'type'    => 'toggle',
					'size'    => 'sm',
					'default' => 'no',
				],
				[
					'label'         => __( 'Blacklist', 'wp-shieldon' ),
					'section_title' => true,
					'desc'          => '<i class="fas fa-ban"></i>',
				],
				[
					'name'        => 'ip_global_blacklist',
					'label'       => __( 'IP List', 'wp-shieldon' ),
					'desc'        => wpso_load_view( 'setting/ip-manager' ),
					'placeholder' => '',
					'type'        => 'textarea',
				],
			],
			'shieldon_ip_login'  => [
				[
					'label'         => __( 'Whitelist', 'wp-shieldon' ),
					'section_title' => true,
					'desc'          => '<i class="far fa-thumbs-up"></i>',
				],
				[
					'name'        => 'ip_login_whitelist',
					'label'       => __( 'IP List', 'wp-shieldon' ),
					'desc'        => wpso_load_view( 'setting/ip-manager' ),
					'placeholder' => '',
					'type'        => 'textarea',
				],
				[
					'name'    => 'ip_login_deny_all',
					'label'   => __( 'Deny All', 'wp-shieldon' ),
					'desc'    => wpso_load_view( 'setting/ip-manager-strict-login' ),
					'type'    => 'toggle',
					'size'    => 'sm',
					'default' => 'no',
				],
				[
					'name'              => 'deny_all_passcode',
					'label'             => __( 'Passcode', 'wp-shieldon' ),
					'desc'              => wpso_load_view( 'setting/ip-manager-login-pass' ),
					'placeholder'       => '',
					'type'              => 'text',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'parent'            => 'enable_captcha_google',
				],
				[
					'label'         => __( 'Blacklist', 'wp-shieldon' ),
					'section_title' => true,
					'desc'          => '<i class="fas fa-ban"></i>',
				],
				[
					'name'        => 'ip_login_blacklist',
					'label'       => __( 'IP List', 'wp-shieldon' ),
					'desc'        => wpso_load_view( 'setting/ip-manager' ),
					'placeholder' => '',
					'type'        => 'textarea',
				],
			],
			'shieldon_ip_signup' => [
				[
					'label'         => __( 'Whitelist', 'wp-shieldon' ),
					'section_title' => true,
					'desc'          => '<i class="far fa-thumbs-up"></i>',
				],
				[
					'name'        => 'ip_signup_whitelist',
					'label'       => __( 'IP List', 'wp-shieldon' ),
					'desc'        => wpso_load_view( 'setting/ip-manager' ),
					'placeholder' => '',
					'type'        => 'textarea',
				],
				[
					'name'    => 'ip_signup_deny_all',
					'label'   => __( 'Deny All', 'wp-shieldon' ),
					'desc'    => wpso_load_view( 'setting/ip-manager-strict-signup' ),
					'type'    => 'toggle',
					'size'    => 'sm',
					'default' => 'no',
				],
				[
					'label'         => __( 'Blacklist', 'wp-shieldon' ),
					'section_title' => true,
					'desc'          => '<i class="fas fa-ban"></i>',
				],
				[
					'name'        => 'ip_signup_blacklist',
					'label'       => __( 'IP List', 'wp-shieldon' ),
					'desc'        => wpso_load_view( 'setting/ip-manager' ),
					'placeholder' => '',
					'type'        => 'textarea',
				],
			],
			'shieldon_ip_xmlrpc' => [
				[
					'label'         => __( 'Whitelist', 'wp-shieldon' ),
					'section_title' => true,
					'desc'          => '<i class="far fa-thumbs-up"></i>',
				],
				[
					'name'        => 'ip_xmlrpc_whitelist',
					'label'       => __( 'IP List', 'wp-shieldon' ),
					'desc'        => wpso_load_view( 'setting/ip-manager' ),
					'placeholder' => '',
					'type'        => 'textarea',
				],
				[
					'name'    => 'ip_xmlrpc_deny_all',
					'label'   => __( 'Deny All', 'wp-shieldon' ),
					'desc'    => wpso_load_view( 'setting/ip-manager-strict-xmlrpc' ),
					'type'    => 'toggle',
					'size'    => 'sm',
					'default' => 'no',
				],
				[
					'label'         => __( 'Blacklist', 'wp-shieldon' ),
					'section_title' => true,
					'desc'          => '<i class="fas fa-ban"></i>',
				],
				[
					'name'        => 'ip_xmlrpc_blacklist',
					'label'       => __( 'IP List', 'wp-shieldon' ),
					'desc'        => wpso_load_view( 'setting/ip-manager' ),
					'placeholder' => '',
					'type'        => 'textarea',
				],
			],
		];
	}
}
