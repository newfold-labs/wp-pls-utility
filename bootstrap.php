<?php

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_loaded',
	function () {
		if ( ! class_exists( 'NewfoldLabs\WP\PLS\Rest\v1\Rest' ) ) {
			return;
		}
		// initialize REST.
		new NewfoldLabs\WP\PLS\Rest\v1\Rest();
	}
);
