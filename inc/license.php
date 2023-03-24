<?php
/**
 * Premium licenses for plugins that Cornershop Creative has agency licenses for.
 */

// License Gravity Forms (key valid as of 11/2018)
// https://docs.gravityforms.com/wp-config-options/#gf-license-key
if ( ! defined( 'GF_LICENSE_KEY' ) ) {
	define( 'GF_LICENSE_KEY', '1de1b9e6fbb069926a8ac89bc20af8ea' );
}

// License Gravity Perks
// https://gravitywiz.com/documentation/license-faq/#can-i-register-my-license-key-in-wp-config-php
if ( ! defined( 'GPERKS_LICENSE_KEY' ) ) {
	define( 'GPERKS_LICENSE_KEY', 'f358051d7e38a2a7df9eb16273e54328' );
}

// License Advanced Custom Fields via a constant since version 5.11 of ACF (key valid as of 11/2022)
// https://www.advancedcustomfields.com/resources/how-to-activate/#wp-configphp
if ( ! defined( 'ACF_PRO_LICENSE' ) ) {
	define( 'ACF_PRO_LICENSE', 'b3JkZXJfaWQ9MzQxMjJ8dHlwZT1kZXZlbG9wZXJ8ZGF0ZT0yMDE0LTA3LTA5IDE3OjQ5OjEw' );
}

// License FacetWP
// https://facetwp.com/help-center/license-and-renewal/
if ( ! defined( 'FACETWP_LICENSE_KEY' ) ) {
	define( 'FACETWP_LICENSE_KEY', 'd69bc91a1ae07db38ef28fbb61b1e630' );
}

// License Imagify
// https://imagify.io/documentation/hide-api-key/
if ( ! defined( 'IMAGIFY_API_KEY' ) ) {
	define( 'IMAGIFY_API_KEY', '1d1a6ab98164301e300481d7360f34372114b73b' );
}

// License SearchWP
// https://searchwp.com/documentation/hooks/searchwp-license-key/
if ( function_exists( '\add_filter' ) ) {
	add_filter( 'searchwp\license\key', function( $key ) {
		return '0b26a39ee36007ec9a18a71fd47395d7';
	}, 2 );
}

// License WP Rocket
// https://docs.wp-rocket.me/article/100-resolving-problems-with-license-validation#staging
if ( ! defined( 'WP_ROCKET_KEY' ) ) {
	define( 'WP_ROCKET_KEY', 'bf69211b');
}

// License WP Rocket
// https://docs.wp-rocket.me/article/100-resolving-problems-with-license-validation#staging
if ( ! defined( 'WP_ROCKET_EMAIL' ) ) {
	define( 'WP_ROCKET_EMAIL', 'it@cornershopcreative.com' );
}