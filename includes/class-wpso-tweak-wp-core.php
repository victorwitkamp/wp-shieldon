<?php
declare(strict_types=1);
namespace WPShieldon;
use WP_Error;

class WPSO_Tweak_WP_Core {
	public function __construct() {
		if ( wpso_get_option( 'only_authorised_rest_access', 'shieldon_wp_tweak' ) === 'yes' ) {
			add_filter( 'rest_authentication_errors', [ $this, 'only_authorised_rest_access' ]);
		}
		if ( wpso_get_option( 'disable_xmlrpc', 'shieldon_wp_tweak' ) === 'yes' ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}
	}

	/**
	 * Filters REST API authentication errors.
	 * @param WP_Error|null|true $errors If authentication error, null if authentication method wasn't used, true if authentication succeeded.
	 * @return WP_Error|null|true
	 */
	public function only_authorised_rest_access( $errors ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_unauthorised', __( 'Restrict access to the REST API to authenticated users only.', 'wp-shieldon' ), [ 'status' => rest_authorization_required_code() ]);
		}
		return $errors;
	}
}
