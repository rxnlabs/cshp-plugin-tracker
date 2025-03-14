<?php

// Autoload everything for unit tests.
$ds = DIRECTORY_SEPARATOR;
require_once dirname(__FILE__, 2) . $ds . 'vendor' . $ds . 'autoload.php';

/**
 * Include core bootstrap for an integration test suite
 *
 * This will only work if you run the tests from the command line.
 * Running the tests from IDE such as PhpStorm will require you to
 * add additional argument to the test run command if you want to run
 * integration tests.
 */
if (isset($GLOBALS['argv']) && in_array('--group=integration', $GLOBALS['argv'], true)) {
	if (!file_exists(dirname(__FILE__, 2) . '/wp/tests/phpunit/wp-tests-config.php')) {
		// We need to set up core config details and test details
		copy(
			dirname(__FILE__, 2) . '/wp/wp-tests-config-sample.php',
			dirname(__FILE__, 2) . '/wp/tests/phpunit/wp-tests-config.php'
		);

		// Change certain constants from the test's config file.
		$testConfigPath = dirname(__FILE__, 2) . '/wp/tests/phpunit/wp-tests-config.php';
		$testConfigContents = file_get_contents($testConfigPath);

		$testConfigContents = str_replace(
			"dirname( __FILE__ ) . '/src/'",
			"dirname(__FILE__, 3) . '/src/'",
			$testConfigContents
		);
		$testConfigContents = str_replace("youremptytestdbnamehere", $_SERVER['DB_NAME'], $testConfigContents);
		$testConfigContents = str_replace("yourusernamehere", $_SERVER['DB_USER'], $testConfigContents);
		$testConfigContents = str_replace("yourpasswordhere", $_SERVER['DB_PASSWORD'], $testConfigContents);
		$testConfigContents = str_replace("localhost", $_SERVER['DB_HOST'], $testConfigContents);

		file_put_contents($testConfigPath, $testConfigContents);
	}

	// Give access to tests_add_filter() function.
	require_once dirname(__FILE__, 2) . '/wp/tests/phpunit/includes/functions.php';

	/**
	 * Manually load the plugin being tested.
	 */
	function _manually_load_plugin()
	{
		require dirname(dirname(__FILE__)) . '/cshp-plugin-tracker.php';
	}

	tests_add_filter('muplugins_loaded', '_manually_load_plugin');

	require_once dirname(__FILE__, 2) . '/wp/tests/phpunit/includes/bootstrap.php';
} else {
	// only run this code when unit testing alone
	// load the ABSPATH constant when Unit testing since the plugin has references to ABSPATH
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/wp/src/' );
	}

	// load the WP_CONTENT_DIR constant when Unit testing since the file.php file used to load the WP_Filesystem class needs this constant
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
		define( 'WP_LANG_DIR', WP_CONTENT_DIR );
	}

	// require the main plugin file in Unit tests so we can load our shared Trait
	require_once dirname(dirname(__FILE__)) . '/cshp-plugin-tracker.php';
}
