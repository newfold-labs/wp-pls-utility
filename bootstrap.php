<?php

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_loaded',
	function () {
		// initialize REST.
		new NewfoldLabs\WP\PLS\Rest\v1\Rest();
	}
);
