<?php

	if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

	// Get functions
	include_once( trailingslashit( pp() ) . 'core/functions/store-core-functions.php' );




	// Testing function to run things. Delete Me.
	function jrr_run_thing(){

		$address = array(
			'line_1'		=> '134 Example way',
			'line_2'		=> '',
			'city'			=> 'Los angeles',
			'state'			=> 'Ca',
			'zip'			=> '11102'
		);
		//store_save_address( $address );
		//var_dump( store_is_billing_address(65) ); exit;

		//var_dump( store_create_order() ); exit;

	}
	add_action('init', 'jrr_run_thing');




	// Setup product post types
	include_once( trailingslashit( pp() ) . 'core/setup/store-post-types.php' );

	// Setup taxonomies
	include_once( trailingslashit( pp() ) . 'core/setup/store-taxonomy.php' );

	// Run activation functions
	include_once( trailingslashit( pp() ) . 'core/setup/store-activate.php' );

	// Hook saving functionality
	include_once( trailingslashit( pp() ) . 'core/store-save-products.php' );

	// Add cart AJAX functions
	include_once( trailingslashit( pp() ) . 'core/store-ajax-api.php' );

	// Setup user meta functions
	include_once( trailingslashit( pp() ) . 'core/store-customer-meta.php' );

	/*
	 * Enqueue JavaScript API Scripts
	 */
		function store_api_scripts() {
			wp_register_script( 'store_api_js', plugins_url( 'core/js/store.api.js', dirname(__FILE__) ));
			wp_enqueue_script( 'store_api_js');

			// Setup JS variables in scripts
			wp_localize_script('store_api_js', 'store_api_vars',
				array(
					'homeURL'	=> home_url(),
					'ajaxURL'	=> admin_url( 'admin-ajax.php' )
				)
			);

		}
		add_action( 'wp_enqueue_scripts', 'store_api_scripts' );

?>