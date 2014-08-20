<?php

	if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

	// Get functions
	include_once( trailingslashit( pp() ) . 'core/functions/store-core-functions.php' );




	// Testing function to run things. Delete Me.
	function jrr_run_thing(){

/*
		$address = array(
			'line_1'	=> '210 N Belmont St.',
			'line_2'	=> 'Apt 101',
			'city'		=> 'Glendale',
			'state'		=> 'Ca',
			'zip'		=> '91206'
		);
		store_save_address( $address, 3, false );
*/

		//var_dump( store_get_user_billing_address() ); exit;

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
	include_once( trailingslashit( pp() ) . 'core/store-user-meta.php' );

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