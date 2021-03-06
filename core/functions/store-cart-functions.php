<?php

	if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
 * @Description: Return the ID of a users active cart
 *
 * @Param: None
 * @Returns: MIXED, integer value of quantity on success, bool false on failure. Will return false if active cart no longer exists (been deleted or expired)
 */
	function store_get_active_cart_id(){
		
		// Declare vars
		$active_cart_id;

		// First, check if logged in
		if ( is_user_logged_in() ) {
			// Get active cart ID from user meta
			$active_cart_id = get_user_meta( get_current_user_id(), '_store_active_cart_id', true );

		} else {
			// If not logged in, check cookie for saved ID
			if( isset($_COOKIE['store_active_cart_id']) ) {

				$active_cart_id = $_COOKIE['store_active_cart_id'];
			}

		}

		// Is it empty?
		if( empty($active_cart_id) ) {
			$active_cart_id = false;
		}

		// Check to see that cart still exsits (it may have been deleted)
		if( store_is_cart_available($active_cart_id) ) {
			// Retrun ID
			return intval($active_cart_id);

		} else {
			return false;

		}

	};


/*
 * @Description: Create a cart, set it as active to logged in user, or to a cookie for guests
 *
 * @Param: INT, a user ID to attribute the cart to. Not required.
 * @Returns: INT, integer value of new cart ID or 0 (same vaule as wp_insert_post).
 *
 * @Todo: remove hard-coded user ID
 */
	function store_create_active_cart($user_id = null){

		// If no user ID set, try to figure one out
		if( empty($user_id) || !is_int($user_id) ) {

			// Get logged in user ID
			if ( is_user_logged_in() ) {
				$user_id = get_current_user_id();

			} else {
				// Attribute to guest user
				$guest = store_get_guest();
				$user_id = $guest->ID; // Guest user made by Drew. Should get from settings in future.

			}
		}

		// Create a cart post
		$args = array(
			'post_status'    => 'publish',
			'post_type'      => 'cart',
			'ping_status'    => 'closed', // This will need to be a custom status
			'comment_status' => 'closed',
			'post_author'	 => $user_id,
			'post_title'	 => 'Cart #?',
			'post_content'	 => ''
		);
		$cart_id = wp_insert_post($args, true);

		// Update post if it was created
		if( $cart_id ) {
			$updated_cart = array(
				'ID'           		=> $cart_id,
				'post_title' 		=> 'Cart #'.$cart_id,
				'post_name'			=> 'cart-'.$cart_id
			);
			wp_update_post( $updated_cart );

			// Set as active
			store_set_active_cart_id($user_id, $cart_id);

			// Set status to started
			store_set_order_status();

		}

		// Return cart post ID number
		return $cart_id;
	};

/*
 * @Description: Save a given cart ID to a user or to a cookie for not logged in guests. This can only be run before headers are sent.
 *
 * @Param: INT, user ID to save to, if not set uses current logged in user. 
 * @Param: INT, cart post ID to set as active. Required.
 * @Returns: Returns setcookie() value (true if set, false if not)
 */

 	function store_set_active_cart_id($user_id = null, $cart_id = null) {

		// If no cart ID set then return false
		if( empty($cart_id) ) {
			return false;
		}

		if ( is_user_logged_in() ) {

			// If no user ID, use logged in user
			if( empty($user_id) ) {
				$user_id = get_current_user_id();

			} else {

				// Verify user exsits
				$user = get_userdata($user_id);
				if(!$user) {
					// No user exists, abort
					return false;
				}

			}

			// Save cart id to user, set cookie too
			update_user_meta( $user_id, '_store_active_cart_id', $cart_id);			
			return setcookie('store_active_cart_id', $cart_id, time()+3600*24*30, '/', store_get_cookie_url(), false, false);  /* expire in 30 days */

		} else {

			// Not logged in, save to cookie
			return setcookie('store_active_cart_id', $cart_id, time()+3600*24*30, '/', store_get_cookie_url(), false, false);  /* expire in 30 days */

		}
	}	

/*
 * @Description: Reset currently active cart for a given user
 *
 * @Param: INT, user ID to reset, if not set uses current logged in user. Optional.
 * @Return: MIXED, returns update_user_meta() value if logged in, else setcookie() value
 */
 	function store_unset_active_cart( $customer = null ) {

	 	// if user is logged in...
	 	if ( is_user_logged_in() ) {

		 	// verify customer
		 	$customer = store_get_customer( $customer );

		 	// No customer? abort.
		 	if ( ! $customer ) return false;
		 	
		 	if (isset($_COOKIE['store_active_cart_id'])) {
	            unset($_COOKIE['store_active_cart_id']);			 	

			 	// Remove cookie just in case
				setcookie('store_active_cart_id', '', time()-3600, '/', store_get_cookie_url(), false, false);	            
			}

			// Save cart id to user
			return update_user_meta( $customer->ID, '_store_active_cart_id', '');

		// User not logged in...
	 	} else {

		 	// Set empty cookie that expires yesterday
		 	if (isset($_COOKIE['store_active_cart_id'])) {
	            unset($_COOKIE['store_active_cart_id']);

			 	// Remove cookie just in case
			 	return setcookie('store_active_cart_id', '', time()-3600, '/', store_get_cookie_url(), false, false);
			}
	 	}
 	}


/*
 * @Description: Save a given product ID to a cart
 *
 * @Param: INT, product ID to add to cart. Required.
 * @Param: INT, quantity of product to add to cart. Defaults to 1.
 * @Param: INT, cart post ID to add product too. If not set, then uses active cart.
 *
 * @Returns: BOOL, true if saved, false if not saved
 */
	function store_add_product_to_cart($product_id = null, $cart_id = null, $quantity = 1){

		// init output
		$output = false;

		// If no product ID set, or not an INT, then return false.
		if( empty($product_id) || !is_int($product_id) ) {
			return $output;
		}

		// Check product is available to add to cart, report if not
		if( ! store_is_product_available($product_id) ) {
			$output = store_get_json_template(array(
				'code' => 'NOT_AVAILABLE',
				'message' => 'This product is not available, please choose a different option.'
			));
			return $output;
		}

		// Attempt to get a valid cart
		$cart = store_get_cart();
		$cart_id = $cart->ID;

		// If still no cart, make one!
		if( empty($cart_id) ) {
			$cart_id = store_create_active_cart($cart_id);
		}

		// Get cart product meta as array
		$products = get_post_meta($cart_id, '_store_cart_products', true);
		if ( ! $products ) $products = array();

		// if product is already in cart, increment
		if ( isset($products[$product_id]) ) {

			// Add quantity to current quantity val
			$products[$product_id] = intval($products[$product_id]) + $quantity;

		} else {

			// Add product ID to array
			$products[$product_id] = $quantity;

		}

		// Save meta array, add output to var
		$result = update_post_meta($cart_id, '_store_cart_products', $products);

		// run this so date_modified gets updated
		wp_update_post( array('ID' => $cart_id ));

		// if meta was added, log success
		if ( $result ) {
			$output = store_get_json_template(array(
				'success'			=> true,
				'code'				=> 'OK',
				'message'			=> get_the_title($product_id) . ' successfully added to cart.',
				'vendor_response'	=> $resut
			));
		}

		return $output;
	};


/*
 * @Description: Remove a given product by ID from a cart (by ID)
 *
 * @Param 1: INT, product ID to remove from cart. Required.
 * @Param 2: INT, cart post ID to set as active. If not set, then use active cart. Optional.
 * @Param 3: INT, quantity of items to reduce cart qty by. Optional.
 * @Returns: BOOL, true if saved, false if not saved
 */
	function store_remove_product_from_cart($product_id = null, $cart = null, $quantity = -1){

		// If product_id is not integer, abort
		if ( ! is_int($product_id) ) return false;

		// Get full cart object
		$cart = store_get_cart($cart);

		// Get cart product meta as array
		$products = get_post_meta($cart->ID, '_store_cart_products', true);

		// If specified product is not in cart, return false
		if ( ! isset( $products[$product_id] ) ) return false;

		// If qty set to -1, or qty is more than currently in cart...
		if ( $quantity == -1 || intval($products[$product_id]) <= $quantity ) {

			// Remove item completely
			unset($products[$product_id]);

		} else {

			// Reduce quantity in cart by quantity parameter
			$products[$product_id] = intval($products[$product_id]) - $quantity;

		}

		// Update cart, return
		wp_update_post( array('ID' => $cart->ID ));
		return update_post_meta($cart->ID, '_store_cart_products', $products);
	};


/*
 * @Description: Check if cart (by ID) has been converted into an order
 *
 * @Param: INT, cart ID. If none provided, the default is the current cart.
 * @Returns: MIXED, corresponding order ID if found, false on failure.
 */
	function store_cart_is_order($cart = null) {

		// Get cart object
		$cart = store_get_cart($cart);

		// Set query args
	    $args = array(
			'posts_per_page'	=> 1,
			'meta_key'			=> '_store_source_cart',
			'meta_value'		=> $cart->ID,
			'post_type'			=> 'orders',
			'fields'			=> 'id'
		);
		$found_order = get_posts( $args );

		// no found orders? abort
		if ( ! $found_order ) return false;

		// If post was found, set to be ID
		if ( ! empty($found_order) ) $found_order = reset($found_order);

		return $found_order;
	};


/*
 * @Description: Remove all items from a cart
 *
 * @Param: INT, post ID of cart to empty. Optional.
 * @Returns: BOOL, true if emptied, false if nothing accomplished
 */
	function store_empty_cart($cart = null) {

		// get cart object
		$cart = store_get_cart($cart);

		// Get products in cart
		$products = get_post_meta($cart->ID, '_store_cart_products', true);

		// If no products, abort
		if ( ! $products ) return false;

		return update_post_meta($cart->ID, '_store_cart_products', false);

	};


/*
 * @Description: get shipping options for a cart
 *
 * @Param: MIXED, post ID or object of cart to get shipping quote for. Defaults to active cart. Optional.
 * @Returns: MIXED, shipping array on success, false on failure
 */
	function store_get_cart_shipping( $cart = null ){

		// Get full cart object
		$cart = store_get_cart($cart);

		// Get shipping options from shipwire
		return store_shipwire_request_order_shipping($cart);
	}


/*
 * @Description: Calculate the total of a given cart. If none provided, the active cart will be used.
 *
 * @Param: MIXED, cart ID or object. Defaults to currently active cart. Optional.
 * @Returns: MIXED, integer of total in cents, false on failure
 */
 	function store_calculate_cart_total( $cart = null ) {

	 	// Get cart object
	 	$cart = store_get_cart($cart);

	 	// Get cart items
	 	$items = store_get_cart_items($cart);

	 	// set output
	 	$total = false;

	 	// if items found in cart, loop through them
	 	if ( $items ) {
	 		$total = 0;
		 	foreach ( $items as $id => $qty ) {

			 	// if price comes back, add into total
			 	if ( $price = store_get_product_price($id) ) $total += ($price * $qty);

		 	}

		 	// if cart shipping is available, add it to the total
		 	if ( $shipping = store_get_cart_shipping($cart) ) $total += (int) $shipping[0]['cost'];

	 	}

	 	return $total;
 	}

/*
 * @Description: 
 *
 * @Param: 
 * @Returns: 
 */
 	function store_get_cart_tax($subtotal = null){

 		// set percentage here
 		$percentage = 8;

 		if ( is_int($subtotal) ) {

 			// calculate based on subtotal
 			$tax = $subtotal * ($percentage / 100);

		} else {

			// default to 0
			$tax = 0;

		}

		// round to 2 decimals
		return round(($tax / 100), 2);
	}


/*
 * @Description: Get all items in cart by ID or object. Wrapper for store_get_order_items(), but with active cart as default.
 *
 * @Param: MIXED, cart ID or object. Optional.
 * @Returns: MIXED, returns an array of cart items (value of _store_cart_products ), or false on failure
 */
 	function store_get_cart_items($cart = null) {

	 	// Get cart object
	 	$cart = store_get_cart($cart);

	 	// return
	 	return store_get_order_items($cart);
 	}


/*
 * @Description: Get total count of items in a cart
 *
 * @Param: MIXED, ID or object of cart. Defaults to active cart. Optional.
 * @Returns: INT, quantity of items on success, 0 on failure
 */
 	function store_cart_item_count($cart = null) {

	 	// get items in cart
	 	$items = store_get_cart_items($cart);

	 	if ( ! $items ) return 0;

	 	return array_sum($items);
 	}


/*
 * @Description: Helper function used to set the cookie directory URL.
 *
 * @Returns: STRING, a parsed version of home_url().
 */
 	function store_get_cookie_url() {
	 	$url = parse_url( home_url() );
	 	return $url['host'];
 	}


/*
 * @Description: Get the cart post object (uses get_post).
 *
 * @Param: INT, cart ID, if not valid the current active cart will be used. Optional.
 * @Returns: MIXED, returns a WP_Post object, or null. Just like get_post().
 */
 	function store_get_cart($cart = null) {

	 	// Default to active cart
		if ( ! $cart ) $cart = store_get_active_cart_id();

		// get full post object
		$cart = get_post($cart);

		// Not an object? abort
		if ( ! is_object($cart) ) return false;

		// not a cart? set to false
		if ( $cart->post_type !== 'cart' ) $cart = false;

		return $cart;
 	}

?>