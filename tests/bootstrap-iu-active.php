<?php
/**
 * PHPUnit bootstrap for the `iu-active` suite.
 *
 * A handful of the plugin's branches key off `class_exists( 'Infinite_Uploads' )`. A class cannot be
 * undefined once declared, so those branches can't be toggled within a single PHP process. This
 * bootstrap declares the stub up front and runs the affected tests in their own process instead.
 *
 * @package BigFileUploads
 */

if ( ! class_exists( 'Infinite_Uploads' ) ) {
	/**
	 * Stand-in for the real Infinite Uploads plugin's main class.
	 *
	 * The plugin only ever asks whether this class exists, never calls into it, so an empty class
	 * is a faithful stub.
	 */
	class Infinite_Uploads {}
}

require_once dirname( __DIR__ ) . '/tests/includes/bootstrap-functions.php';

bfu_tests_bootstrap();
