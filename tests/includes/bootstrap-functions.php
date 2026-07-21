<?php
/**
 * Shared bootstrap logic.
 *
 * Kept separate from bootstrap.php so the `iu-active` suite can reuse it while loading a stub
 * Infinite_Uploads class first. See tests/bootstrap-iu-active.php.
 *
 * @package BigFileUploads
 */

/**
 * Locate the WordPress test library.
 *
 * Honours WP_TESTS_DIR (classic install-wp-tests.sh layout) and WP_PHPUNIT__DIR (what wp-env
 * exports inside the tests-cli container), then falls back to the vendored wp-phpunit copy.
 *
 * @return string
 */
function bfu_tests_dir() {
	foreach ( [ getenv( 'WP_TESTS_DIR' ), getenv( 'WP_PHPUNIT__DIR' ) ] as $dir ) {
		if ( $dir && file_exists( rtrim( $dir, '/' ) . '/includes/functions.php' ) ) {
			return rtrim( $dir, '/' );
		}
	}

	$vendored = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
	if ( file_exists( $vendored . '/includes/functions.php' ) ) {
		return $vendored;
	}

	fwrite(
		STDERR,
		"Could not find the WordPress test library.\n\n" .
		"Run the suite through wp-env:\n" .
		"  npx wp-env start\n" .
		"  npx wp-env run tests-cli --env-cwd=wp-content/plugins/big-file-uploads vendor/bin/phpunit\n\n" .
		"Or point WP_TESTS_DIR at an existing wordpress-develop tests directory.\n"
	);
	exit( 1 );
}

/**
 * Boot WordPress with the plugin loaded.
 *
 * @return void
 */
function bfu_tests_bootstrap() {
	$tests_dir = bfu_tests_dir();

	// PHPUnit 9 needs the polyfills; wp-env sets this itself, a local run may not.
	$polyfills = dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills';
	if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $polyfills ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills );
	}

	require_once $tests_dir . '/includes/functions.php';

	// Load the plugin directly rather than through activation, so it is present for every test.
	tests_add_filter(
		'muplugins_loaded',
		function () {
			require dirname( __DIR__, 2 ) . '/tuxedo_big_file_uploads.php';
		}
	);

	require $tests_dir . '/includes/bootstrap.php';

	require_once __DIR__ . '/class-bfu-testcase.php';
}
