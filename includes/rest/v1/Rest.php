<?php

namespace NewfoldLabs\WP\PLS\Rest\v1;

defined( 'ABSPATH' ) || exit;

use NewfoldLabs\WP\PLS\PLS;
use NewfoldLabs\WP\PLS\Rest\RestBase;

class Rest extends RestBase {

	/**
	 * Rest version.
	 *
	 * @var string
	 */
	protected $version = 'v1';

	/**
	 * Paths available under base path
	 *
	 * @var array
	 */
	protected $routes = array(
		'store-license' => array(
			'methods' => \WP_REST_Server::EDITABLE,
			'args'    => array(
				'license_id' => array(
					'description'       => 'License ID to store',
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
		),
		'activate'      => array(
			'methods' => \WP_REST_Server::EDITABLE,
			'args'    => array(
				'license_id'  => array(
					'description'       => 'License ID to activate',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'domain_name' => array(
					'description'       => 'Domain of the site to active',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'email'       => array(
					'description'       => 'Email address to register with activation',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
		),
		'deactivate'    => array(
			'methods' => \WP_REST_Server::EDITABLE,
		),
		'check'         => array(
			'methods' => \WP_REST_Server::READABLE,
			'args'    => array(
				'force' => array(
					'description'       => 'Whether to ignore cache and execute a new check against the server',
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
		),
	);

	/**
	 * Activate license key.
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function activate() {
		$plugin_slug = $this->request->get_param( 'plugin_slug' );
		$license_id  = $this->request->get_param( 'license_id' ) ?? '';
		$response    = PLS::activate( $plugin_slug, $license_id, $this->get_formatted_params() );

		return $this->send_response( $response );
	}

	/**
	 * Store a license key.
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function store_license() {
		$plugin_slug = $this->request->get_param( 'plugin_slug' );
		$license_id  = $this->request->get_param( 'license_id' );
		$response    = PLS::store_license_id( $plugin_slug, $license_id );

		return $this->send_response( $response );
	}

	/**
	 * Deactivate license key.
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function deactivate() {
		$plugin_slug = $this->request->get_param( 'plugin_slug' );
		$response    = PLS::deactivate( $plugin_slug );

		return $this->send_response( $response );
	}

	/**
	 * Check if a license jey
	 *
	 * @since 1.0.0
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function check() {
		$plugin_slug = $this->request->get_param( 'plugin_slug' );
		$force       = ! ! $this->request->get_param( 'force' );
		$response    = PLS::check( $plugin_slug, $force );

		return $this->send_response( $response );
	}
}
