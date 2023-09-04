<?php declare( strict_types = 1 );

namespace NewfoldLabs\WP\PLS;

class Rest {
	/**
	 * Common path for all the endpoints supported by this API
	 *
	 * @const string
	 */
	const REST_BASE = 'pls';

	/**
	 * Paths available under base path
	 */
	const ROUTES = [
		'store-license' => [
			'methods' => \WP_REST_Server::EDITABLE,
			'args'    => [
				'license_id' => [
					'description'       => 'License ID to store',
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				],
			],
		],
		'activate'      => [
			'methods' => \WP_REST_Server::EDITABLE,
			'args'    => [
				'license_id'  => [
					'description'       => 'License ID to activate',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				],
				'domain_name' => [
					'description'       => 'Domain of the site to active',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				],
				'email'       => [
					'description'       => 'Email address to register with activation',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => 'rest_validate_request_arg',
				],
			],
		],
		'deactivate'    => [
			'methods' => \WP_REST_Server::EDITABLE,
		],
		'check'         => [
			'methods' => \WP_REST_Server::READABLE,
			'args'    => [
				'force' => [
					'description'       => 'Whether to ignore cache and execute a new check against the server',
					'type'              => 'boolean',
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				],
			],
		],
	];

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
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'rest_authentication_errors', [ $this, 'check_authentication' ] );
	}

	/**
	 * Registers routes available for this API, and their callback
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		foreach ( array_keys( self::ROUTES ) as $route ) {
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
		if ( ! isset( self::ROUTES[ $route ] ) ) {
			return;
		}

		register_rest_route(
			self::REST_BASE,
			'(?P<plugin_slug>[\w-]+)/' . $route,
			array_merge_recursive(
				self::ROUTES[ $route ],
				[
					'callback'            => [ $this, 'serve_request' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'environment' => [
							'description'       => 'Environment for current request',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
							'enum'              => [
								'staging',
								'production',
							],
						],
					],
				]
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
	public function serve_request( $request ): \WP_REST_Response|\WP_Error {
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
		PLS::config( [
			'environment' => $this->request->get_param( 'environment' ) ?? '',
		] );
	}

	/**
	 * Activate license key.
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function activate(): \WP_REST_Response|\WP_Error {
		$plugin_slug = $this->request->get_param( 'plugin_slug' );
		$license_id  = $this->request->get_param( 'license_id' ) ?? '';
		$response    = PLS::activate( $plugin_slug, $license_id, $this->get_formatted_params() );

		return rest_ensure_response( $response );
	}

	/**
	 * Store a license key.
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function store_license(): \WP_REST_Response|\WP_Error {
		$plugin_slug = $this->request->get_param( 'plugin_slug' );
		$license_id  = $this->request->get_param( 'license_id' );
		$response    = PLS::store_license_id( $plugin_slug, $license_id );

		return rest_ensure_response( $response );
	}

	/**
	 * Deactivate license key.
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function deactivate(): \WP_REST_Response|\WP_Error {
		$plugin_slug = $this->request->get_param( 'plugin_slug' );
		$response    = PLS::deactivate( $plugin_slug );

		return rest_ensure_response( $response );
	}

	/**
	 * Check if a license jey
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function check(): \WP_REST_Response|\WP_Error {
		$plugin_slug = $this->request->get_param( 'plugin_slug' );
		$force       = ! ! $this->request->get_param( 'force' );
		$response    = PLS::check( $plugin_slug, $force );

		return rest_ensure_response( $response );
	}

	/**
	 * Check authentication
	 *
	 * @since 1.0.0
	 * @param mixed $value The current filter value
	 * @return bool|null
	 */
	public function check_authentication( $value ): bool|null {
		return $this->permission_check() ? true : $value;
	}

	/**
	 * Permission check
	 * Checks that current user has correct capability to perform API request.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get current request handler.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request The request.
	 * @return string
	 */
	protected function get_handler( \WP_REST_Request $request ) {
		return basename( $request->get_route() );
	}

	/**
	 * Format request params.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_formatted_params(): array {
		$handler   = $this->get_handler( $this->request );
		$schema    = self::ROUTES[ $handler ]['args'] ?? [];
		$formatted = [];

		foreach ( array_keys( $schema ) as $field_key ) {
			$formatted[ $field_key ] = $this->request->get_param( $field_key );
		}

		return array_filter( $formatted );
	}
}
