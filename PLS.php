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
	 * The plugin slug
	 *
	 * @var string
	 */
	protected $plugin_slug = '';

	/**
	 * Class additional arguments
	 *
	 * @var array
	 */
	protected $args = [];

	/**
	 * Class construct
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug The plugin slug.
	 * @param array  $args        (Optional) An array of additional class arguments
	 * @return void
	 */
	public function __construct( string $plugin_slug, array $args = [] ) {
		$this->plugin_slug = $plugin_slug;
		$this->args        = wp_parse_args(
			$args,
			[
				'environment' => 'production',
				'cache_ttl'	  => 12 * HOUR_IN_SECONDS,
				'timeout'	  => 5
			]
		);
	}

	/**
	 * Activate pls licence
	 *
	 * @since 1.0.0
	 * @param array $args (Optional) An array of arguments to send with the request.
	 * @return bool|\WP_Error
	 */
	public function activate( array $args = [] ): bool|\WP_Error {
		$args = wp_parse_args(
			$args,
			[
				'domain_name' => home_url(),
				'email'       => get_bloginfo( 'admin_email' ),
			]
		);

		// Get stored license ID.
		$license_id = $this->get_license_id();
		if ( is_wp_error( $license_id ) ) {
			return $license_id;
		}

		$response = $this->call(
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
		return $this->store_activation_key( $response['data']['activation_key'] );
	}

	/**
	 * Deactivate pls licence
	 *
	 * @since 1.0.0
	 * @return bool|\WP_Error
	 */
	public function deactivate(): bool|\WP_Error {

		// Get stored license ID.
		$license_id = $this->get_license_id();
		if ( is_wp_error( $license_id ) ) {
			return $license_id;
		}

		// If no activation key found for this license no further actions are required.
		$activation_key = $this->get_activation_key();
		if ( empty( $activation_key ) ) {
			return true;
		}

		$response = $this->call(
			"license/{$license_id}/deactivate",
			'POST',
			[
				'activationKey' => $activation_key,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->delete_activation_key();
	}

	/**
	 * Check pls licence activation status
	 *
	 * @since 1.0.0
	 * @param bool $force (Optional) True to force a remote check for license, false to use cached value if any. Default is false.
	 * @return bool|\WP_Error
	 */
	public function check( bool $force = false ): bool|\WP_Error {

		// Get stored license ID.
		$license_id = $this->get_license_id();
		if ( is_wp_error( $license_id ) ) {
			return $license_id;
		}

		// Get stored activation key
		$activation_key = $this->get_activation_key();
		if ( empty( $activation_key ) ) {
			return false;
		}

		$response = get_transient( "pls_license_status_valid_{$this->plugin_slug}" );
		if ( $force || false === $response ) {

			$response = $this->call(
				"license/{$license_id}/status",
				'GET',
				[
					'id' => urlencode( $activation_key ),
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! isset( $response['data'], $response['data']['valid'] ) ) {
				return new \WP_Error( 'malformed-response', 'Malformed PLS API response' );
			}

			// Store request response
			set_transient( "pls_license_status_valid_{$this->plugin_slug}", $response['data'], 12 * HOUR_IN_SECONDS );
		}

		return ! ! $response['valid'];
	}

	/**
	 * Get base API url for current environment
	 *
	 * @return string Base API url.
	 */
	protected function get_base_url(): string {
		$env = array_key_exists( $this->args['environment'], self::SERVERS ) ? $this->args['environment'] : 'production';
		return self::SERVERS[ $env ];
	}

	/**
	 * Returns url for a specific endpoint
	 *
	 * @param string $endpoint The endpoint.
	 * @return string Url to the endpoint.
	 */
	protected function get_endpoint_url( string $endpoint ): string {
		$base_url = $this->get_base_url();
		return "$base_url/$endpoint";
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
	protected function call( string $endpoint, string $method = 'GET', array $payload = [], array $args = [] ): array|\WP_Error {
		$url      = $this->get_endpoint_url( $endpoint );
		$defaults = [
			'timeout'            => $this->args['timeout'] ?? 5,
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
			'blocking'           => true,
		];

		$request_params = array_merge(
			$defaults,
			$args,
			[
				'method' => $method,
				'body'   => 'GET' !== $method ? wp_json_encode( $payload ) : $payload
			]
		);

		$response = wp_remote_request(
			$url,
			$request_params
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->process_response( $response );
	}

	/**
	 * Uses a request response as returned by wp_remote_request,
	 * to format an array of response that can be returned to the caller
	 *
	 * @param array $response Response of the request.
	 * @return array|\WP_Error Formatted array of response, or {@see \WP_Error} on failure.
	 */
	protected function process_response( array $response ): array|\WP_Error {
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
	 * @return string|\WP_Error
	 */
	protected function get_license_id(): string|\WP_Error {
		$license_id = get_option( "pls_license_id_{$this->plugin_slug}", '' );
		if ( empty( $license_id ) ) {
			return new \WP_Error( 'empty-license-id', sprintf( 'No license ID found for plugin slug %s', $this->plugin_slug ) );
		}

		return $license_id;
	}

	/**
	 * Get activation key option name
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function get_activation_key_option(): string {
		return "pls_activation_key_{$this->plugin_slug}";
	}

	/**
	 * Get stored activation key.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function get_activation_key(): string {
		return get_option( $this->get_activation_key_option(), '' );
	}

	/**
	 * Store given activation key in the database.
	 *
	 * @since 1.0.0
	 * @param string $activation_key The activation key to store.
	 * @return bool
	 */
	protected function store_activation_key( string $activation_key ): bool {
		return update_option( $this->get_activation_key_option(), $activation_key, false );
	}

	/**
	 * Delete stored activation key.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function delete_activation_key(): bool {
		return delete_option( $this->get_activation_key_option() );
	}
}
