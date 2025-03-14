<?php

/**
 * Prevent the integration tests from running when the don't pass a group flag to the pest argument.
 *
 * By default, not passing an argument will try to run PHPUnit tests and Integration tests. This prevents that from happening.
 *
 * @See https://github.com/dingo-d/wp-pest?tab=readme-ov-file#running-unit-tests-alongside-integration-tests
 *
 * @return bool
 */
function isUnitTest() {
	$isUnitTest = false;
	if(!isset($GLOBALS['argv'][1])) {
		$isUnitTest = true;
	} elseif(!empty($GLOBALS['argv']) && isset($GLOBALS['argv'][1]) && $GLOBALS['argv'][1] === '--group=unit') {
		$isUnitTest = true;
	} elseif(!empty($GLOBALS['argv']) && isset($GLOBALS['argv'][1]) && $GLOBALS['argv'][1] !== '--group=unit' && $GLOBALS['argv'][1] !== '--group=integration') {
		$isUnitTest = true;
	}

	return $isUnitTest;
}