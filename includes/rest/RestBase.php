<?php

namespace NewfoldLabs\WP\PLS\Rest;

defined( 'ABSPATH' ) || exit;

use NewfoldLabs\WP\PLS\PLS;

abstract class RestBase {

	/**
	 * Common path for all the endpoints supported by this API
	 *
	 * @var string
	 */
	protected $rest_base = 'pls';

	/**
	 * Rest version.
	 *
	 * @var string
	 */
	protected $version = '';

	/**
	 * Paths available under base path
	 *
	 * @var array
	 */
	protected $routes = array();

	/**
	 * Current request.
	 *
	 * @var \WP_REST_Request|null
	 */
	protected $request;

	/**
	 * Constructor method
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'check_authentication' ) );
	}

	/**
	 * Get REST base
	 *
	 * @since 1.0.0
	 */
	public function get_base() {
		return apply_filters( 'pls_rest_api_base', trim( "{$this->rest_base}/{$this->version}", '/' ), $this->rest_base, $this->version );
	}

	/**
	 * Get REST routes
	 *
	 * @since 1.0.0
	 */
	public function get_routes() {
		return apply_filters( 'pls_rest_api_routes', $this->routes, $this->version );
	}

	/**
	 * Registers routes available for this API, and their callback
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		foreach ( array_keys( $this->get_routes() ) as $route ) {
			$this->register_route( $route );
		}
	}

	/**
	 * Register single API route
	 *
	 * @since 1.0.0
	 * @param string $route The route to register.
	 */
	protected function register_route( $route ) {
		$routes = $this->get_routes();
		if ( empty( $routes[ $route ] ) ) {
			return;
		}

		register_rest_route(
			$this->get_base(),
			'(?P<plugin_slug>[\w-]+)/' . $route,
			array_merge_recursive(
				$routes[ $route ],
				array(
					'callback'            => array( $this, 'serve_request' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'environment' => array(
							'description'       => 'Environment for current request',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
							'enum'              => array(
								'staging',
								'production',
							),
						),
						'network'     => array(
							'description'       => 'If enable network options for the current request',
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				)
			)
		);
	}

	/**
	 * Serve a PLS request
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function serve_request( $request ) {
		$action = str_replace( '-', '_', basename( $request->get_route() ) );
		if ( ! $action || ! method_exists( $this, $action ) ) {
			return new \WP_Error( 'invalid-rest-action', 'No action found to serve API request' );
		}

		$this->request = $request;
		// Init PLS.
		$this->config_pls();
		// Now serve the request.
		return $this->$action();
	}

	/**
	 * Get PLS class instance.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function config_pls() {
		PLS::config(
			array(
				'environment' => $this->request->get_param( 'environment' ) ?? '',
				'network'     => $this->request->get_param( 'network' ) ?? false,
			)
		);
	}

	/**
	 * Check if is request to our REST API.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function is_request_to_rest_api() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		// Check if the request is to the PLS endpoints.
		return false !== strpos( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), $this->get_base() );
	}

	/**
	 * Check authentication
	 *
	 * @since 1.0.0
	 * @param \WP_Error|null|bool $error Error data.
	 * @return \WP_Error|null|bool
	 */
	public function check_authentication( $error ) {
		// Another plugin has already declared a failure or is not a PLS request.
		if ( ! empty( $error ) || ! $this->is_request_to_rest_api() ) {
			return $error;
		}

		return $this->permission_check();
	}

	/**
	 * Permission check
	 * Checks that current user has correct capability to perform API request.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get current request handler.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request The request.
	 * @return string
	 */
	protected function get_handler( $request ) {
		return basename( $request->get_route() );
	}

	/**
	 * Format request params.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_formatted_params() {
		$handler = $this->get_handler( $this->request );
		$routes  = $this->get_routes();
		// Get route schema.
		$schema    = $routes[ $handler ]['args'] ?? array();
		$formatted = array();

		foreach ( array_keys( $schema ) as $field_key ) {
			$formatted[ $field_key ] = $this->request->get_param( $field_key );
		}

		return array_filter( $formatted );
	}

	/**
	 * Send REST response
	 *
	 * @since 1.0.0
	 * @param mixed $response The REST response.
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function send_response( $response ) {
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			if ( empty( $error_data['status'] ) && 'empty-license-id' === $response->get_error_code() ) {
				$response->add_data( array( 'status' => 400 ) );
			}
		}

		return rest_ensure_response( $response );
	}
}
