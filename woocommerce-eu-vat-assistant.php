<?php if(!defined('ABSPATH')) exit; // Exit if accessed directly
/*
Plugin Name: WooCommerce EU VAT Assistant
Plugin URI: https://aelia.co/shop/eu-vat-assistant-woocommerce/
Description: Assists with EU VAT compliance, for the new VAT regime beginning 1st January 2015.
Author: Aelia
Author URI: https://aelia.co
Version: 1.7.10.170711
Text Domain: wc-aelia-eu-vat-assistant
Domain Path: /languages
*/

require_once(dirname(__FILE__) . '/src/lib/classes/install/aelia-wc-eu-vat-assistant-requirementscheck.php');
// If requirements are not met, deactivate the plugin
if(Aelia_WC_EU_VAT_Assistant_RequirementsChecks::factory()->check_requirements()) {
	require_once dirname(__FILE__) . '/src/plugin-main.php';
}
