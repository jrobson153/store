<?php

	if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

	// Get functions
	include_once( trailingslashit( pp() ) . 'core/functions/store-core-functions.php' );




	// Testing function to run things. Delete Me eventually.
	function jrr_run_thing(){

		global $post;

		$address = array(
			'line_1'		=> '1923 Noship Ave',
			'line_2'		=> '',
			'city'			=> 'Los angeles',
			'state'			=> 'Ca',
			'zip'			=> '11102'
		);

		// Set args defaults
		$args = array(
			'options'	=> true,
			'title'		=> true,
			'price'		=> true,
			'content'	=> true,
			'excerpt'	=> true,
			'images'	=> 'all',
			'sku'		=> true,
			'slug'		=> true
		);

		//var_dump(store_run_hourly()); exit;
		//var_dump( store_shipwire_request_cart_shipping($address) ); exit;
		//print_r( store_get_product_matrix($args, 9, 'array') ); exit;
		//var_dump( store_add_order_history(83, 'Billing address added successfully') ); exit;
		//var_dump( store_stripe_run_charge('TOKEN') ); exit;
		//var_dump( store_update_shipwire_inventory() ); exit;

	}
	add_action('init', 'jrr_run_thing', 60);




	// Setup product post types
	include_once( trailingslashit( pp() ) . 'core/setup/store-post-types.php' );

	// Setup taxonomies
	include_once( trailingslashit( pp() ) . 'core/setup/store-taxonomy.php' );

	// Run activation functions
	include_once( trailingslashit( pp() ) . 'core/setup/store-activate.php' );

	// Include term meta plugin
	include_once( trailingslashit( pp() ) . 'core/plugins/simple-term-meta.php' );

	// Load stripe classes
	include_once( trailingslashit( pp() ) . 'core/stripe-php/Stripe.php' );

	// Hook saving functionality
	include_once( trailingslashit( pp() ) . 'core/store-save-products.php' );

	// Add cart AJAX functions
	include_once( trailingslashit( pp() ) . 'core/apis/store-ajax-api.php' );

	// Add js product matrix
	include_once( trailingslashit( pp() ) . 'core/store-product-matrix.php' );

	// Add shipwire AJAX functions
	include_once( trailingslashit( pp() ) . 'core/apis/store-shipwire-api.php' );

	// Add functions for init
	include_once( trailingslashit( pp() ) . 'core/store-init.php' );

	// Add stripe setup AJAX functions
	include_once( trailingslashit( pp() ) . 'core/apis/store-stripe-api.php' );

	// Setup user meta functions
	include_once( trailingslashit( pp() ) . 'core/store-customer-meta.php' );

	// Set crons
	include_once( trailingslashit( pp() ) . 'core/store-set-crons.php' );

	/*
	 * Enqueue JavaScript API Scripts
	 */
		function store_api_scripts() {
			wp_register_script( 'store_api_js', plugins_url( 'core/js/store.api.js', dirname(__FILE__) ));
			wp_enqueue_script( 'store_api_js');

			// Setup JS variables in scripts
			wp_localize_script('store_api_js', 'store_api_vars',
				array(
					'homeURL'		=> home_url(),
					'ajaxURL'		=> admin_url( 'admin-ajax.php' )
				)
			);

		}
		add_action( 'wp_enqueue_scripts', 'store_api_scripts' );

?>