<?php
/*
 * This file contains all the PHP functions that get run using an AJAX request
 *
 *	The difference between wp_ajax and wp_ajax_nopriv is as simple as being logged in vs not
 *	
 *	wp_ajax – Use when you require the user to be logged in.	
 *		add_action( 'wp_ajax_<ACTION NAME>', <YOUR FUNCTION> );
 *	
 *	wp_ajax_nopriv – Use when you do not require the user to be logged in.
 *		add_action( 'wp_ajax_nopriv_<ACTION NAME>', <YOUR FUNCTION> );
 *	
 *	The one trick to this is that if you want to handle BOTH cases (i.e. the user is logged in as well as not), you need to implement both action hooks.
 */

/*
 * @Description: Get a standardized template to build json responses on. Defaults to a general error.
 * @Returns: ARRAY, response template in the form of an associative array 
 */
	function store_get_json_template($data = null){

		$template = array();

		$template['success'] = false;
		$template['code'] = 'ERROR';
		$template['vendor_response'] = false;
		$template['message'] = 'An error occurred, please try again.';

		if ( is_array($data) ) {
			foreach ( $data as $prop => $val ) {
				if ( array_key_exists($prop, $template) ) $template[$prop] = $val;
			}
			if ( $data['options'] ) $template['options'] = $data['options'];
		}

		return $template;
	}


/*
 * @Description: The AJAX wrapper for store_add_product_to_cart(). Must POST an array of parameters that store_add_product_to_cart() requires 
 */
	add_action( 'wp_ajax_nopriv_add_to_cart', 'store_ajax_add_product_to_cart' );
	add_action( 'wp_ajax_add_to_cart', 'store_ajax_add_product_to_cart' );
	function store_ajax_add_product_to_cart() {

		// Import vars from the AJAX array if set
		if( isset($_REQUEST['product_id']) ) {
			$product_id = (int) $_REQUEST['product_id'];
		}
		if( isset($_REQUEST['quantity']) ) {
			$quantity = (int) $_REQUEST['quantity'];
		}
		if( isset($_REQUEST['options']) ) {
			$options = (array) $_REQUEST['options'];
		}

		$cart_id = store_get_cart();

		// Find variant based on options and parent
		if ( $options ) {
			$product_id = store_get_variant_id($options, $product_id);
		}

		// Set default qty
		if ( ! $quantity ) $quantity = 1;

		// Pass into PHP function, echo results and die.
		$output = store_add_product_to_cart($product_id, $cart_id, $quantity);

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode(store_get_json_template($output));
		die;
	}
	
/*
 * @Description: Test AJAX function
 */	
	add_action( 'wp_ajax_nopriv_test_ajax_call', 'store_test_ajax_call' );
	add_action( 'wp_ajax_test_ajax_call', 'store_test_ajax_call' );
	function store_test_ajax_call() {
		
		
		die;
	}	


/*
 * @Description: The AJAX wrapper for store_remove_product_from_cart(). Must POST an array of parameters that store_remove_product_from_cart() requires
 */
	add_action( 'wp_ajax_nopriv_remove_from_cart', 'store_ajax_remove_product_from_cart' );
	add_action( 'wp_ajax_remove_from_cart', 'store_ajax_remove_product_from_cart' );
	function store_ajax_remove_product_from_cart() {

		// Import vars from the AJAX array if set
		if( isset($_REQUEST['product_id']) ) {
			$product_id = (int) $_REQUEST['product_id'];
		}
		if( isset($_REQUEST['quantity']) ) {
			$quantity = (int) $_REQUEST['quantity'];
		} else {
			$quantity = -1;
		}

		// Pass into PHP function, echo results and die.
		$removed = store_remove_product_from_cart($product_id, null, $quantity);

		// Set api logging
		$output = array();
		if ( $removed ) {
			$output['success'] = true;
			$output['code'] = 'OK';
			$output['message'] = 'Product successfully removed from cart.';
		}

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode(store_get_json_template($output));
		die;
	}


/*
 * @Description: Ajax wrapper for store_ajax_empty_cart(). defaults to currently active cart.
 */
	add_action( 'wp_ajax_nopriv_empty_cart', 'store_ajax_empty_cart' );
	add_action( 'wp_ajax_empty_cart', 'store_ajax_empty_cart' );
	function store_ajax_empty_cart() {

		// attempt to empty cart
		$result = store_empty_cart();

		// Set api logging
		$output = array();
		if ( $result ) {
			$output['success'] = true;
			$output['code'] = 'OK';
			$output['message'] = 'Cart successfully emptied.';
		}

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode(store_get_json_template($output));
		die;
	}


/*
 * @Description: Run the build cart function as defined by theme author. 
 *
 * @Returns: MIXED, either result of defined function, or JSON object
 * @Todo: make a default json response
 */
	add_action( 'wp_ajax_nopriv_get_mini_cart', 'store_ajax_get_mini_cart' );
	add_action( 'wp_ajax_get_mini_cart', 'store_ajax_get_mini_cart' );
	function store_ajax_get_mini_cart() {

		// if theme author has defined a cart, return it
		if( locate_template('store/store-mini-cart.php') ) {
			get_template_part('store/store-mini-cart');

		} elseif( locate_template('store-mini-cart.php') ) {
			get_template_part('store-mini-cart');

		// Otherwise return json data
		} else {

			// Set output
			$output = array();

			// Get current cart
			$items = store_get_cart_items();

			// No items? abort
			if ( empty($items) ) return false;

			// Add total to cart
			$output['total'] = store_calculate_cart_total();

			// Add quantity of unique items in cart
			$output['count'] = count($items);

			// Add non-unique quantity
			$output['quantity'] = array_sum($items);

			// loop through items
			foreach ( $items as $id => $qty ) {

				$product = store_get_product($id);

				// add each product post object
				$output['items'][$id]['name'] = get_the_title($product);
				$output['items'][$id]['sku'] = store_get_sku($product);
				$output['items'][$id]['qty'] = $qty;
				$output['items'][$id]['price'] = store_get_product_price($product);

			}

			// Set header and output JSON
			header('Content-Type: application/json');
			echo json_encode( $output );
		}

		die;
	}


/*
 * @Description: Run the build cart function as defined by theme author. 
 *
 * @Returns: MIXED, either result of defined function, or JSON object
 * @Todo: make a default json response
 */
	add_action( 'wp_ajax_nopriv_get_template', 'store_ajax_template_part' );
	add_action( 'wp_ajax_get_template', 'store_ajax_template_part' );
	function store_ajax_template_part() {

		$template = $_REQUEST['template'];

		// if theme author has defined a cart, return it
		if( locate_template('store/' . $template . '.php') ) {
			get_template_part('store/' . $template);

		} elseif( locate_template($template . '.php') ) {
			get_template_part($template);

		// Otherwise return json data
		} else {

			// Set output
			$output = array();

			$output['code'] = 'NO_TEMPLATE';
			$output['message'] = 'No matching template found, please re-check your theme folder.';

			// Set header and output JSON
			header('Content-Type: application/json');
			echo json_encode( $output );
		}

		die;
	}


/*
 * @Description: 
 *
 * @Returns: 
 * @Todo: 
 */
	add_action( 'wp_ajax_nopriv_sign_user_on', 'store_ajax_sign_on' );
	add_action( 'wp_ajax_sign_user_on', 'store_ajax_sign_on' );
	function store_ajax_sign_on() {

		// First check the nonce, if it fails the function will break
		check_ajax_referer( 'ajax-login-nonce', 'security' );

		// Nonce is checked, get the POST data and sign user on
		$info = array();
		$info['user_email'] = $_REQUEST['email'];
		$info['user_password'] = $_REQUEST['password'];
		$info['remember'] = isset($_REQUEST['remember']) ? $_REQUEST['remember']: false;

		$output = array();
		$user_signon = store_login( $info );
		if ( ! is_wp_error($user_signon) ){

			// Set api logging
			$output['success'] = true;
			$output['code'] = 'OK';
			$output['message'] = 'Login Successful';

		} else {
			$output['message'] = 'Wrong username or password';

		}

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode(store_get_json_template($output));
		die;
	}


/*
 * @Description: 
 *
 * @Returns: 
 * @Todo: 
 */
	add_action( 'wp_ajax_nopriv_create_customer', 'store_ajax_create_customer' );
	add_action( 'wp_ajax_create_customer', 'store_ajax_create_customer' );
	function store_ajax_create_customer() {

		// First check the nonce, if it fails the function will break
		$referrer = check_ajax_referer( 'signup_nonce', 'nonce_code', false );

		// If nonce is cleared...
		if ( $referrer ){

			// forward all request data into create_customer
			$userData = $_REQUEST;

			// remove nonce code and action
			unset($userData['nonce_code']);
			unset($userData['action']);

			// Create the user
			$output = store_create_customer($userData);

		} else {

			// nonce failed, report
			$output['message'] = 'Customer not created, failed to validate nonce.';
			$output['code'] = 'FAILED_NONCE';

		}

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode(store_get_json_template($output));
		die;
	}


/*
 * @Description: 
 *
 * @Returns: 
 */
	add_action( 'wp_ajax_customer_address', 'store_ajax_save_customer_address' );
	function store_ajax_save_customer_address(){

		// init output
		$output;

		// set vars
		$address = $_REQUEST['address'];
		$shipping = (bool) $_REQUEST['shipping'];
		$billing = (bool) $_REQUEST['billing'];

		$result = store_save_customer_address( $address, null, $shipping, $billing );

		if ( $result ) {
			$output['success'] = true;
			$output['code'] = 'OK';
			$output['message'] = 'Address successfully added.';
		} else {
			$output['message'] = 'Failed to save address.';
		}

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode(store_get_json_template($output));
		die;
	}


/*
 * @Description: Sync inventory with shipwire
 *
 * @Returns: MIXED, result of store_update_shipwire_inventory
 */
	add_action( 'wp_ajax_nopriv_update_inventory', 'store_ajax_update_inventory' );
	add_action( 'wp_ajax_update_inventory', 'store_ajax_update_inventory' );
	function store_ajax_update_inventory() {

		// Import vars from the AJAX array if set
		$product_id = false;
		if( isset($_REQUEST['product_id']) ) {
			$product_id = (int) $_REQUEST['product_id'];
		}

		// attempt to update inventory
		$updated = store_update_shipwire_inventory($product_id);

		// Set api logging
		$output = array();
		if ( $updated ) {
			$output['success'] = true;
			$output['code'] = 'OK';
			$output['message'] = 'All inventory updated.';
		}

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode($output);
		die;
	}


/*
 * @Description: Sync inventory with shipwire
 *
 * @Returns: MIXED, result of store_update_shipwire_inventory
 */
	add_action( 'wp_ajax_nopriv_shipwire_quote', 'store_ajax_shipwire_quote' );
	add_action( 'wp_ajax_shipwire_quote', 'store_ajax_shipwire_quote' );
	function store_ajax_shipwire_quote() {

		$address = array();
		if( isset($_REQUEST['address']) ){
			$address = $_REQUEST['address'];
		}

		// Get quote from shipwire
		$response = store_shipwire_request_cart_shipping( $address );

		// Set api logging
		$output = array();
		if ( $response['warnings'] ) {
			$output['success'] = true;
			$output['code'] = strtoupper( $response['warnings'][0]['code'] );
			$output['message'] = $response['warnings'][0]['message'];
		} elseif ( $response && $response['status'] == 200 ) {
			$output['success'] = true;
			$output['code'] = 'OK';
			$output['message'] = 'This is a useable address.';
		}

		$output['vendor_response'] = (array) $response;
		$output['vendor_response']['vendor'] = 'shipwire';
		$output['options'] = store_shipwire_retrieve_shipping($response);

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode( store_get_json_template($output) );
		die;
	}


/*
 * @Description:
 *
 * @Returns:
 */
	add_action( 'wp_ajax_nopriv_stripe_charge', 'store_ajax_stripe_charge' );
	add_action( 'wp_ajax_stripe_charge', 'store_ajax_stripe_charge' );
	function store_ajax_stripe_charge() {

		// Import vars from the AJAX array if set
		$token = false;
		if( isset($_REQUEST['token']) ) {
			$token = (string) $_REQUEST['token'];
		}

		// run stripe charge and get response
		$charge = store_stripe_run_charge($token);

		// Set response var
		$response = array();

		// charge was successful, set response
		if ( $charge['id'] ) {
			$response['success'] = true;
			$response['code'] = 'OK';
			$response['message'] = 'Card xxxxxxxxxxxx' . $charge['card']['last4'] . ' successfully charged for $' . number_format($charge['amount'] / 100, 2, '.', '');
		}

		// Charge was unsuccessful, set response
		if ( $charge['error'] ) {
			$response['success'] = false;
			$response['code'] = strtoupper($charge['error']['code']);
			$response['message'] = $charge['error']['message'];
		}

		// forward raw response into output
		$response['vendor_response'] = $charge;
		$response['vendor_response']['vendor'] = 'stripe';

		// make sure response is properly formatted
		$output = store_get_json_template($response);

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode($output);
		die;
	}


/*
 * @Description:
 */
	add_action( 'wp_ajax_nopriv_submit_order', 'store_ajax_submit_order' );
	add_action( 'wp_ajax_submit_order', 'store_ajax_submit_order' );
	function store_ajax_submit_order() {

		// init address, get from request if available
		$args = array();
		if( isset($_REQUEST['shipping_address']) ){
			$args['shipping_address'] = $_REQUEST['shipping_address'];
		}
		if( isset($_REQUEST['shipping_method']) ){
			$args['shipping_method'] = $_REQUEST['shipping_method'];
		}
		if( isset($_REQUEST['billing_address']) ){
			$args['billing_address'] = $_REQUEST['billing_address'];
		}
		if( isset($_REQUEST['stripe_token']) ){
			$args['stripe_token'] = $_REQUEST['stripe_token'];
		}

		// comes back pre-formatted for JSON
		$results = store_submit_order($args);

		// Set proper header, output
		header('Content-Type: application/json');
		echo json_encode( $results );
		die;
	}

?>