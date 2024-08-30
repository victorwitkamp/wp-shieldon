<?php
declare(strict_types=1);
/**
 * Global helper functions.
 *
 * @author Terry Lin
 * @link https://terryl.in/
 *
 * @package Shieldon
 * @since 1.0.0
 * @version 1.2.0
 */

/**
 * Get the value of a settings field.
 *
 * @param string $option  Settings field name.
 * @param string $section The section name this field belongs to.
 * @param string $default Default text if it's not found.
 * @return mixed
 */
function wpso_get_option( string $option, string $section, string $default = '' ) {
	$options = get_option( $section );

	if ( isset( $options[ $option ] ) ) {
		return $options[ $option ];
	}
	return $default;
}

/**
 * Update a field of a setting array.
 *
 * @param string $option  Setting field name.
 * @param string $section The section name this field belongs to.
 * @param string $value   Set option value.
 * @return void
 */
function wpso_set_option( string $option, string $section, string $value ):void {
	$options = get_option( $section );

	$options[ $option ] = $value;

	update_option( $section, $options );
}

/**
 * Load view files.
 *
 * @param string $template_path The specific template's path.
 * @param array  $data          Data is being passed to.
 * @return string
 */
function wpso_load_view( string $template_path, array $data = array() ): string {
	$view_file_path = SHIELDON_PLUGIN_DIR . 'includes/views/' . $template_path . '.php';
	if ( ! empty( $data ) ) {
		// phpcs:ignore
		extract( $data );
	}

	if ( file_exists( $view_file_path ) ) {

		ob_start();
		require $view_file_path;
		return ob_get_clean();
	}
	return '';
}

/**
 * Get driver hash.
 *
 * @return string
 */
function wpso_get_driver_hash(): string {
	$hash = get_option( 'wpso_driver_hash' );

	if ( empty( $hash ) ) {
		return wpso_set_driver_hash();
	}
	return $hash;
}

/**
 * Check driver hash exists or not.
 *
 * @return bool
 */
function wpso_is_driver_hash(): bool {
	$hash = get_option( 'wpso_driver_hash' );

	if ( empty( $hash ) ) {
		return false;
	}
	return true;
}

/**
 * Set driver hash.
 *
 * @return string
 */
function wpso_set_driver_hash(): string {
	$wpso_driver_hash = wp_hash( wp_date( 'ymdhis' ) . wp_rand( 1, 86400 ) );
	$wpso_driver_hash = substr( $wpso_driver_hash, 0, 8 );

	update_option( 'wpso_driver_hash', $wpso_driver_hash );

	return $wpso_driver_hash;
}

/**
 * Get upload dir.
 *
 * @return string
 */
function wpso_get_upload_dir(): string {
	return WP_CONTENT_DIR . '/uploads/wp-shieldon/' . wpso_get_driver_hash();
}

/**
 * Get logs dir.
 *
 * @return string
 */
function wpso_get_logs_dir(): string {
	return wpso_get_upload_dir() . '/' . wpso_get_channel_id() . '_logs';
}

/**
 * Set channel Id.
 *
 * @return void
 */
function wpso_set_channel_id() {
	update_option( 'wpso_channel_id', get_current_blog_id() );
}

/**
 * Get channel Id.
 *
 * @return string
 */
function wpso_get_channel_id(): string {
	return get_option( 'wpso_channel_id' );
}

/**
 * Test if specific data driver is available or not.
 *
 * @param string $type Data driver.
 *
 * @return bool
 */
function wpso_test_driver( string $type = null ): bool {

	$drivers = PDO::getAvailableDrivers();
	if ( $type === 'mysql' || $type === 'sqlite' ) {
		if ( ! class_exists( 'PDO' ) ) {
			error_log( 'Shieldon - PDO class does not exist' );
			return false;
		}
		if ( ! in_array( $type, $drivers, true ) ) {
			return false;
		}
	}

	if ( 'mysql' === $type ) {
		$db = array(
			'host'    => DB_HOST,
			'dbname'  => DB_NAME,
			'user'    => DB_USER,
			'pass'    => DB_PASSWORD,
			'charset' => DB_CHARSET,
		);

		try {
			// phpcs:ignore
			new \PDO(
				'mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'] . ';charset=' . $db['charset'],
				$db['user'],
				$db['pass']
			);
			return true;
		} catch ( Exception $e ) {
			error_log( 'Shieldon - Error - Test driver mysql failed:' . $e->getMessage() );
			error_log( 'Shieldon - ' . $e->getTraceAsString() );
		}
	}

	if ( 'sqlite' === $type ) {

		$sqlite_file_path = wpso_get_upload_dir() . '/shieldon.sqlite3';
		$sqlite_dir       = wpso_get_upload_dir();

		if ( ! file_exists( $sqlite_file_path ) ) {
			if ( ! is_dir( $sqlite_dir ) ) {
				$original_umask = umask( 0 );
				if ( ! mkdir( $sqlite_dir, 0777, true ) && ! is_dir( $sqlite_dir ) ) {
					throw new RuntimeException( sprintf( 'Directory "%s" was not created', $sqlite_dir ) );
				}
				umask( $original_umask );
			}
		}

		try {
			// phpcs:ignore
			new \PDO( 'sqlite:' . $sqlite_file_path );
			return true;
		} catch ( Exception $e ) {
			error_log( 'Shieldon - Error - Test driver sqlite failed:' . $e->getMessage() );
			error_log( 'Shieldon - ' . $e->getTraceAsString() );
		}
	}

	if ( 'file' === $type ) {
		$file_dir = wpso_get_upload_dir();

		if ( ! is_dir( $file_dir ) ) {
			$original_umask = umask( 0 );
			if ( ! mkdir( $file_dir, 0777, true ) && ! is_dir( $file_dir ) ) {
				throw new RuntimeException( sprintf( 'Directory "%s" was not created', $file_dir ) );
			}
			umask( $original_umask );
		}

		if ( wp_is_writable( $file_dir ) ) {
			return true;
		}
	}

	if ( 'redis' === $type ) {
		if ( class_exists( 'Redis' ) ) {
			try {
				$redis = new \Redis();
				$redis->connect( '127.0.0.1' );
				return true;
			} catch ( Exception $e ) {
				error_log( 'Shieldon - Error - Test driver redis failed:' . $e->getMessage() );
				error_log( 'Shieldon - ' . $e->getTraceAsString() );
			}
		}
	}

	return false;
}



/**
 * Make the date to be displayed with the blog's timezone setting.
 *
 * @return string
 */
function wpso_apply_blog_timezone(): string {
	$timezone_string = get_option( 'timezone_string' );

	//  if ( $timezone_string ) {
	//		// phpcs:ignore
	//		date_default_timezone_set( $timezone_string );

	//  } else {
	//      $offset = (int) get_option( 'gmt_offset' );
	//      if ( $offset ) {
	//          $seconds = (int) round( $offset ) * 3600;

	//          $timezone_string = timezone_name_from_abbr( '', $seconds, 1 );

	//          if ( false === $timezone_string ) {
	//              $timezone_string = timezone_name_from_abbr( '', $seconds, 0 );
	//          }
	//			// phpcs:ignore
	//			date_default_timezone_set( $timezone_string );
	//      }
	//  }
	return $timezone_string;
}
