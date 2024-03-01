<?php
/**
 * Utility functions that are used throughout the plugin and/or debug functions that are used during development.
 */
namespace Cshp\pt;

/**
 * var_dump to the error log. Useful to get more information about what is being outputted such as the type of variable. Useful to see the contents of an array or especially a boolean to see if the bollean is true or false.
 *
 * @return void
 */
function var_dump_error_log( $object = null ) {
	// don't enable output buffering if it's already enabled, otherwise a fatal error will be thrown if output buffering is already on
	// error would be: "Fatal error:  ob_start(): Cannot use output buffering in output buffering display handlers"
	// don't end output buffering if it's already enabled, otherwise a fatal error will be thrown if output buffering is used in output buffering context
	// error would be "Fatal error: ob_end_clean(): Cannot use output buffering in output buffering display handlers"
	if ( empty( ob_get_level() ) || 0 === ob_get_level() ) {
		ob_start();
		// start buffer capture
		var_dump( $object );
		// dump the values
		$contents = ob_get_contents();
		// put the buffer into a variable
		ob_end_clean();
	} else {
		$contents = output_buffering_cast( $object );
	}

	error_log( $contents );
	// log contents of the result of var_dump( $object )
}

/**
 * print_r to the error long. Useful to get more information about what is being outputted to see things like the contents of an array.
 *
 * @return void.
 */
function print_r_error_log( $object = null ) {
	if ( empty( ob_get_level() ) || 0 === ob_get_level() ) {
		$contents = print_r( $object, true );
	} else {
		$contents = output_buffering_cast( $object );
	}

	error_log( $contents );
}

/**
 * debug_backtrace() to the error long. Better than the regular debug_backtrace() since the output is better for printing. Useful if you need to backtrace when something went wrong with the code.
 *
 * @return void.
 */
function debug_backtrace_error_log() {
	// don't enable output buffering if it's already enabled, otherwise a fatal error will be thrown if output buffering is already on
	// error would be: "Fatal error:  ob_start(): Cannot use output buffering in output buffering display handlers"
	// don't end output buffering if it's already enabled, otherwise a fatal error will be thrown if output buffering is used in output buffering context
	// error would be "Fatal error: ob_end_clean(): Cannot use output buffering in output buffering display handlers"
	if ( empty( ob_get_level() ) || 0 === ob_get_level() ) {
		ob_start();
		$contents = debug_print_backtrace();
		ob_end_clean();
	} else {
		$contents = var_dump_error_log( debug_backtrace() );
	}

	if ( is_null( $contents ) ) {
		$contents = '';
	}

	error_log( $contents );
}

/**
 * deal with situations where output buffering is turned on by some other code (maybe TranslatePress turned it on???) and we want to var_dump to the error.log. The bad news is that we really can't do this very well, so here are some things we can do to help, kinda.
 *
 * @return string A string we can use to wrote to the debug.log file instead of var_dump'ing.
 */
function output_buffering_cast( $object ) {
	if ( is_string( $object ) ) {
		return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is a string:) ' . $object;
	} elseif ( is_numeric( $object ) ) {
		return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is something that is numeric:) ' . $object;
	} elseif ( is_array( $object ) ) {
		$json = json_encode( $object );

		if ( empty( $json ) ) {
			$json = serialize( $object );
		}
		return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is a an array. We are converting it to a JSON string though for easier reading in the error_log:) ' . $json;
	} elseif ( is_object( $object ) ) {
		return '(NOTE: output buffering is on, so we cannot var_dump to the error log). This thing passed to the error_log function is a an array. We are converting it to a serialized string though for easier reading in the error_log:) ' . json_encode( $object );
	} elseif ( is_null( $object ) ) {
		return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is a null. Returning an empty string)';
	} elseif ( empty( $object ) ) {
		return '(NOTE: output buffering is on, so we cannot var_dump to the error log. This thing passed to the error_log function is something that evaluates to empty returning an empty string)';
	} else {
		return '(NOTE: output buffering is on, so we cannot var_dump to the error log. We have no idea what this thing passed is and we cannot convert it to a string, so we are just returning nothing. Try turning off output buffering and try var_dump again)';
	}
}
