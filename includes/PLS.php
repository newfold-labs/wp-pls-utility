<?php declare( strict_types = 1 );

namespace NewfoldLabs\WP\PLS;

final class PLS {

	/**
	 * List of available servers, for each environment available
	 *
	 * @const array
	 */
	const SERVERS = [
		'staging'    => 'https://licensing-stg.hiive.cloud',
		'production' => 'https://licensing.hiive.cloud',
	];

	/**
	 * Class config array
	 *
	 * @var array
	 */
	protected static $config = [
		'environment' => 'production',
		'cache_ttl'   => 12 * HOUR_IN_SECONDS,
		'timeout'     => 5,
	];

	/**
	 * Class construct
	 *
	 * @since 1.0.0
	 * @param array $args (Optional) An array of additional class arguments
	 * @return void
	 */
	public static function config( array $config = [] ) {
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
	public static function activate( string $plugin_slug, string $license_id = '', array $args = [] ): bool|\WP_Error {
		$args = wp_parse_args(
			$args,
			[
				'domain_name' => home_url(),
				'email'       => get_bloginfo( 'admin_email' ),
			]
		);

		if ( empty( $license_id ) ) {
			// Try to get the stored license ID if empty
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

		// Store activation key
		return self::store_activation_key( $plugin_slug, $response['data']['activation_key'] );
	}

	/**
	 * Deactivate PLS licence
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug to deactivate.
	 * @return bool|\WP_Error
	 */
	public static function deactivate( string $plugin_slug ): bool|\WP_Error {

		// Get stored license ID.
		$license_id = self::get_license_id( $plugin_slug );
		if ( is_wp_error( $license_id ) ) {
			return $license_id;
		}

		// If no activation key found for this license no further actions are required.
		$activation_key = self::get_activation_key( $plugin_slug );
		if ( empty( $activation_key ) ) {
			return true;
		}

		$response = self::call(
			"license/{$license_id}/deactivate",
			'POST',
			[
				'activationKey' => $activation_key,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::delete_activation_key( $plugin_slug );
	}

	/**
	 * Check PLS licence activation status
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug to check.
	 * @param bool   $force       (Optional) True to force a remote check for license, false to use cached value if any. Default is false.
	 * @return bool|\WP_Error
	 */
	public static function check( string $plugin_slug, bool $force = false ): bool|\WP_Error {

		// Get stored license ID.
		$license_id = self::get_license_id( $plugin_slug );
		if ( is_wp_error( $license_id ) ) {
			return $license_id;
		}

		// Get stored activation key
		$activation_key = self::get_activation_key( $plugin_slug );
		if ( empty( $activation_key ) ) {
			return false;
		}

		$response = get_transient( "pls_license_status_valid_{$plugin_slug}" );
		if ( $force || false === $response ) {
			$response = self::call( "license/{$activation_key}/status" );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! isset( $response['data'], $response['data']['valid'] ) ) {
				return new \WP_Error( 'malformed-response', 'Malformed PLS API response' );
			}

			$response = $response['data'];
			// Store request response
			set_transient( "pls_license_status_valid_{$plugin_slug}", $response, 12 * HOUR_IN_SECONDS );
		}

		return ! ! $response['valid'];
	}

	/**
	 * Get base API url for current environment
	 *
	 * @return string Base API url.
	 */
	protected static function get_base_url(): string {
		$env = array_key_exists( self::$config['environment'], self::SERVERS ) ? self::$config['environment'] : 'production';
		return self::SERVERS[ $env ];
	}

	/**
	 * Returns url for a specific endpoint
	 *
	 * @param string $endpoint The endpoint.
	 * @return string Url to the endpoint.
	 */
	protected static function get_endpoint_url( string $endpoint ): string {
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
	protected static function call( string $endpoint, string $method = 'GET', array $payload = [], array $args = [] ): array|\WP_Error {
		$url      = self::get_endpoint_url( $endpoint );
		$defaults = [
			'timeout'            => self::$config['timeout'] ?? 5,
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'blocking'           => true,
		];

		$request_params = array_merge(
			$defaults,
			$args,
			[
				'method' => $method,
				'body'   => 'GET' !== $method ? wp_json_encode( $payload ) : $payload,
			]
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
	protected static function process_response( array $response ): array|\WP_Error {
		$code = $response['response']['code'] ?? 200;
		$body = $response['body'] ?? '';
		$body = @ json_decode( $body, true );

		if ( 200 !== (int) $code ) {
			return new \WP_Error( $code, 'Server responded with a failure HTTP status', array_merge(
				$body,
				[
					'status' => $code,
				]
			) );
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
	public static function get_license_id( $plugin_slug ): string|\WP_Error {
		$license_id = get_option( self::get_license_id_option_name( $plugin_slug ), '' );
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
	 * @return bool
	 */
	public static function store_license_id( string $plugin_slug, string $license_id ): bool {
		update_option( self::get_license_id_option_name( $plugin_slug ), $license_id, false );
		return true;
	}


	/**
	 * Get stored license ID
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug of the license.
	 * @return bool
	 */
	public static function delete_license_id( string $plugin_slug ): bool {
		return delete_option( self::get_license_id_option_name( $plugin_slug ) );
	}

	/**
	 * Get option name for license ID
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug of the license.
	 * @return string
	 */
	protected static function get_license_id_option_name( string $plugin_slug ): string {
		$environment = self::$config['environment'];
		return "pls_license_id_{$environment}_{$plugin_slug}";
	}

	/**
	 * Get option name for activation key
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug of the license.
	 * @return string
	 */
	protected static function get_activation_key_option_name( string $plugin_slug ): string {
		$environment = self::$config['environment'];
		return "pls_activation_key_{$environment}_{$plugin_slug}";
	}

	/**
	 * Get stored activation key.
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug.
	 * @return string
	 */
	public static function get_activation_key( string $plugin_slug ): string {
		return get_option( self::get_activation_key_option_name( $plugin_slug ), '' );
	}

	/**
	 * Store given activation key in the database.
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug    The plugin slug.
	 * @param string $activation_key The activation key to store.
	 * @return bool
	 */
	protected static function store_activation_key( string $plugin_slug, string $activation_key ): bool {
		return update_option( self::get_activation_key_option_name( $plugin_slug ), $activation_key, false );
	}

	/**
	 * Delete stored activation key.
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug.
	 * @return bool
	 */
	protected static function delete_activation_key( string $plugin_slug ): bool {
		return delete_option( self::get_activation_key_option_name( $plugin_slug ) );
	}
}
