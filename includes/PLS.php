<?php

namespace NewfoldLabs\WP\PLS;

defined( 'ABSPATH' ) || exit;

final class PLS {

	/**
	 * List of available servers, for each environment available
	 *
	 * @const array
	 */
	const SERVERS = array(
		'staging'    => 'https://licensing-stg.hiive.cloud',
		'production' => 'https://licensing.hiive.cloud',
	);

	/**
	 * Class config array
	 *
	 * @var array
	 */
	protected static $config = array(
		'environment' => 'production',
		'cache_ttl'   => 12 * HOUR_IN_SECONDS,
		'timeout'     => 5,
		'network'     => false,
	);

	/**
	 * Class construct
	 *
	 * @since 1.0.0
	 * @param array $config (Optional) An array of additional class arguments.
	 * @return void
	 */
	public static function config( $config = array() ) {
		self::$config = wp_parse_args(
			$config,
			self::$config
		);
	}

	/**
	 * Activate PLS licence
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug to activate.
	 * @param string $license_id  (Optional) The license ID to activate. If empty it reads the options for plugin slug if any.
	 * @param array  $args        (Optional) An array of arguments to send with the request.
	 * @return bool|\WP_Error
	 */
	public static function activate( $plugin_slug, $license_id = '', $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'domain_name' => home_url(),
				'email'       => get_bloginfo( 'admin_email' ),
			)
		);

		if ( empty( $license_id ) ) {
			// Try to get the stored license ID if empty.
			$license_id = self::get_license_id( $plugin_slug );
			if ( is_wp_error( $license_id ) ) {
				return $license_id;
			}
		} else {
			// Always store given license ID for future request.
			self::store_license_id( $plugin_slug, $license_id );
		}

		$response = self::call(
			"license/{$license_id}/activate",
			'POST',
			$args
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['data'], $response['data']['activation_key'] ) ) {
			return new \WP_Error( 'malformed-response', 'Malformed PLS API response' );
		}

		// Store activation key.
		return self::store_activation_key( $license_id, $response['data']['activation_key'] );
	}

	/**
	 * Deactivate PLS licence
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug to deactivate.
	 * @return bool|\WP_Error
	 */
	public static function deactivate( $plugin_slug ) {

		// Get stored license ID.
		$license_id = self::get_license_id( $plugin_slug );
		if ( is_wp_error( $license_id ) ) {
			return $license_id;
		}

		// If no activation key found for this license no further actions are required.
		$activation_key = self::get_activation_key( $license_id );
		if ( empty( $activation_key ) ) {
			return true;
		}

		$response = self::call(
			"license/{$license_id}/deactivate",
			'POST',
			array(
				'activationKey' => $activation_key,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::delete_activation_key( $license_id );
	}

	/**
	 * Check PLS licence activation status
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug to check.
	 * @param bool   $force       (Optional) True to force a remote check for license, false to use cached value if any. Default is false.
	 * @return bool|\WP_Error
	 */
	public static function check( $plugin_slug, $force = false ) {

		// Get stored license ID.
		$license_id = self::get_license_id( $plugin_slug );
		if ( is_wp_error( $license_id ) ) {
			return $license_id;
		}

		// Get stored activation key.
		$activation_key = self::get_activation_key( $license_id );
		if ( empty( $activation_key ) ) {
			return false;
		}

		$transient_name = 'pls_license_status_valid_' . md5( $plugin_slug . '|' . $activation_key . '|' . self::$config['environment'] );
		$response       = self::get_transient( $transient_name );

		if ( $force || false === $response ) {
			$response = self::call( "license/{$activation_key}/status" );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! isset( $response['data'], $response['data']['valid'] ) ) {
				return new \WP_Error( 'malformed-response', 'Malformed PLS API response' );
			}

			$response = $response['data'];
			// Store request response.
			self::set_transient( $transient_name, $response, 12 * HOUR_IN_SECONDS );
		}

		return ! ! $response['valid'];
	}

	/**
	 * Get base API url for current environment
	 *
	 * @return string Base API url.
	 */
	protected static function get_base_url() {
		$env = array_key_exists( self::$config['environment'], self::SERVERS ) ? self::$config['environment'] : 'production';
		return self::SERVERS[ $env ];
	}

	/**
	 * Returns url for a specific endpoint
	 *
	 * @param string $endpoint The endpoint.
	 * @return string Url to the endpoint.
	 */
	protected static function get_endpoint_url( $endpoint ) {
		return self::get_base_url() . "/$endpoint";
	}

	/**
	 * Method that performs API calls against PLS server
	 *
	 * @param string $endpoint Endpoint to call.
	 * @param string $method   (Optional) HTTP method to use for the call. Default is GET.
	 * @param array  $payload  (Optional) An array of argument to sens as request payload.
	 * @param array  $args     (Optional) An array of additional arguments to pass to {@see wp_remote_request} function.
	 *
	 * @return array|\WP_Error Returns decoded body of the response on success, or a {@see \WP_Error} object on failure
	 */
	protected static function call( $endpoint, $method = 'GET', $payload = array(), $args = array() ) {
		$url      = self::get_endpoint_url( $endpoint );
		$defaults = array(
			'timeout'            => self::$config['timeout'] ?? 5,
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'blocking'           => true,
		);

		$request_params = array_merge(
			$defaults,
			$args,
			array(
				'method' => $method,
				'body'   => 'GET' !== $method ? wp_json_encode( $payload ) : $payload,
			)
		);

		$response = wp_remote_request(
			$url,
			$request_params
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::process_response( $response );
	}

	/**
	 * Uses a request response as returned by wp_remote_request,
	 * to format an array of response that can be returned to the caller
	 *
	 * @param array $response Response of the request.
	 * @return array|\WP_Error Formatted array of response, or {@see \WP_Error} on failure.
	 */
	protected static function process_response( $response ) {
		$code = $response['response']['code'] ?? 200;
		$body = $response['body'] ?? '';
		$body = @json_decode( $body, true );

		if ( 200 !== (int) $code ) {
			return new \WP_Error(
				$code,
				'Server responded with a failure HTTP status',
				array_merge(
					$body,
					array(
						'status' => $code,
					)
				)
			);
		}

		return $body;
	}

	/**
	 * Get stored license ID
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug of the license.
	 * @return string|\WP_Error
	 */
	public static function get_license_id( $plugin_slug ) {
		$license_id = self::get_option( self::get_license_id_option_name( $plugin_slug ), '' );
		if ( empty( $license_id ) ) {
			return new \WP_Error( 'empty-license-id', sprintf( 'No license ID found for plugin slug %s', $plugin_slug ) );
		}

		return $license_id;
	}

	/**
	 * Get stored license ID
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug of the license.
	 * @param string $license_id  The license ID to store.
	 * @return bool
	 */
	public static function store_license_id( $plugin_slug, $license_id ) {
		self::update_option( self::get_license_id_option_name( $plugin_slug ), $license_id, false );
		return true;
	}


	/**
	 * Get stored license ID
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug of the license.
	 * @return bool
	 */
	public static function delete_license_id( $plugin_slug ) {
		return self::delete_option( self::get_license_id_option_name( $plugin_slug ) );
	}

	/**
	 * Get option name for license ID
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug of the license.
	 * @return string
	 */
	protected static function get_license_id_option_name( $plugin_slug ) {
		$environment = self::$config['environment'];
		return "pls_license_id_{$environment}_{$plugin_slug}";
	}

	/**
	 * Get option name for activation key
	 *
	 * @since 1.0.0
	 * @param string $license_id The license ID.
	 * @return string
	 */
	protected static function get_activation_key_option_name( $license_id ) {
		$environment = self::$config['environment'];
		return "pls_activation_key_{$environment}_{$license_id}";
	}

	/**
	 * Get stored activation key.
	 *
	 * @since 1.0.0
	 * @param string $license_id The license ID.
	 * @return string
	 */
	public static function get_activation_key( $license_id ) {
		return self::get_option( self::get_activation_key_option_name( $license_id ), '' );
	}

	/**
	 * Store given activation key in the database.
	 *
	 * @since 1.0.0
	 * @param string $license_id The license ID.
	 * @param string $activation_key The activation key to store.
	 * @return bool
	 */
	protected static function store_activation_key( $license_id, $activation_key ) {
		return self::update_option( self::get_activation_key_option_name( $license_id ), $activation_key );
	}

	/**
	 * Delete stored activation key.
	 *
	 * @since 1.0.0
	 * @param string $license_id The license ID.
	 * @return bool
	 */
	protected static function delete_activation_key( $license_id ) {
		return self::delete_option( self::get_activation_key_option_name( $license_id ) );
	}

	/**
	 * Read an option from the DB.
	 *
	 * @since 1.0.0
	 * @param string $option_name   The option name to read.
	 * @param mixed  $default_value (Optional) The default value.
	 * @return mixed
	 */
	protected static function get_option( $option_name, $default_value = '' ) {
		$method = empty( self::$config['network'] ) ? 'get_option' : 'get_site_option';
		return $method( $option_name, $default_value );
	}

	/**
	 * Update an option to the DB.
	 *
	 * @since 1.0.0
	 * @param string $option_name  The option name to read.
	 * @param mixed  $option_value Option value.
	 * @return bool True if the value was updated, false otherwise.
	 */
	protected static function update_option( $option_name, $option_value ) {
		$method = empty( self::$config['network'] ) ? 'update_option' : 'update_site_option';
		return $method( $option_name, $option_value );
	}

	/**
	 * Delete an option from the DB.
	 *
	 * @since 1.0.0
	 * @param string $option_name The option name to read.
	 * @return bool True if the option was deleted, false otherwise.
	 */
	protected static function delete_option( $option_name ) {
		$method = empty( self::$config['network'] ) ? 'delete_option' : 'delete_site_option';
		return $method( $option_name );
	}

	/**
	 * Read a transient from the DB.
	 *
	 * @since 1.0.0
	 * @param string $transient Transient name. Expected to not be SQL-escaped.
	 * @return mixed Value of transient.
	 */
	protected static function get_transient( $transient ) {
		$method = empty( self::$config['network'] ) ? 'get_transient' : 'get_site_transient';
		return $method( $transient );
	}

	/**
	 * Set a transient to the DB.
	 *
	 * @since 1.0.0
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration (Optional) Time until expiration in seconds. Default 0 (no expiration).
	 * @return bool True if the value was set, false otherwise.
	 */
	protected static function set_transient( $transient, $value, $expiration = 0 ) {
		$method = empty( self::$config['network'] ) ? 'set_transient' : 'set_site_transient';
		return $method( $transient, $value, $expiration );
	}
}
