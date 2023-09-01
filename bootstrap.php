<?php

add_action(
	'wp_loaded',
	function () {
		// initialize REST.
		new \NewfoldLabs\WP\PLS\Rest();
	}
);
