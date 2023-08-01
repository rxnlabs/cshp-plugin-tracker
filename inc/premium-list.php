<?php
/**
 * Keep an ever-changing list of known premium plugins and premium themes.
 */

namespace Cshp\pt;

/**
 * Store a list of plugins that we know are premium plugins (or custom plugins that we developed), so we don't have
 * to ping the WordPress API
 *
 * @return array List of premium plugins using the plugin folder name.
 */
function premium_plugins_list() {
	return array_merge(
		[
			'advanced-custom-fields-pro',
			'backupbuddy',
			'betterdocs-pro',
			'blocksy-companion-pro',
			'bulk-actions-pro-for-gravity-forms',
			'cshp-kinsta',
			'cshp-plugin-updater',
			'cshp-support',
			'donation-for-woocommerce',
			'elementor-pro',
			'essential-addons-elementor',
			'events-calendar-pro',
			'event-tickets-plus',
			'facetwp',
			'facetwp-cache',
			'facetwp-conditional-logic',
			'facetwp-hierarchy-select',
			'facetwp-i18n',
			'facetwp-map-facet',
			'facetwp-range-list',
			'facetwp-time-since',
			'facetwp-submit',
			'gf-bulk-add-fields',
			'gf-collapsible-sections',
			'gf-color-picker',
			'gf-image-choices',
			'gf-salesforce-crm-perks-pro',
			'gf-tooltips',
			'gp-multi-page-navigation',
			'gravityforms',
			'gravityformsactivecampaign',
			'gravityformsauthorizenet',
			'gravityformsconstantcontact',
			'gravityformsmailchimp',
			'gravityformspaypal',
			'gravityformspaypalpaymentspro',
			'gravityformspolls',
			'gravityformsppcp',
			'gravityformsrecaptcha',
			'gravityformsstripe',
			'gravityformstwilio',
			'gravityformsuserregistration',
			'gravityformszapier',
			'gravityperks',
			'gravityview',
			'latepoint',
			'mapsvg',
			'memberpress',
			'media-deduper-pro',
			'restrict-content-pro',
			'rcp-group-accounts',
			'rcp-per-level-emails',
			'searchwp',
			'searchwp-custom-results-order',
			'searchwp-redirects',
			'searchwp-related',
			'simply-schedule-appointments',
			'sitepress-multilingual-cms',
			'stackable-ultimate-gutenberg-blocks-premium',
			'sugar-calendar',
			'the-events-calendar-filterbar',
			'translatepress-developer',
			'ubermenu',
			'woocommerce-bookings',
			'woocommerce-memberships',
			'woocommerce-product-bundles',
			'woocommerce-subscriptions',
			'wordpress-seo-premium',
			'wpai-acf-add-on',
			'wp-all-export-pro',
			'wp-all-import-pro',
			'wpbot-pro',
			'wp-rocket',
		],
		[ get_this_plugin_folder() ]
	);
}

/**
 * Store a list of themes that we know are premium themes (or custom themes that we developed), so we don't have
 * to ping the WordPress API
 *
 * @return array List of themes plugins using the theme folder name.
 */
function premium_themes_list() {
	return [
		'bjork',
		'blocksy-child',
		'crate',
		'crate-child',
		'impacto-patronus',
		'impacto-patronus-child',
		'jupiter',
		'jupiter-child',
		'jupiterx',
		'jupiterx-child',
		'lekker',
		'lekker-child',
		'minerva',
		'phlox-pro',
		'phlox-pro-child',
		'thegem',
		'thegem-child',
		'thepascal',
		'thepascal-child',
		'wihelp',
	];
}
