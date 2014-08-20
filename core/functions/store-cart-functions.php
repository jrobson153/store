<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

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
			if( isset($_COOKIE['_store_active_cart_id']) ) {
				$active_cart_id = $_COOKIE['_store_active_cart_id'];
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
				$user_id = 2; // Guest user made by Drew. Should get from settings in future.

			}
		}

		// Create a cart post
		$args = array(
			'post_status'    => 'publish',
			'post_type'      => 'orders',
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
 * @Description: Sets the order status of a given cart.
 *
 * @Param 1: INT, ID or object of cart to set status for, if none provided active cart will be used. Optional
 * @Param 2: MIXED, string of status slug, or tag ID of status to set cart to
 * @Returns: BOOL, returns true on success, or false on failure
 */
	function store_set_order_status( $cart_id = null, $status = 'active' ) {

		// If no proper cart ID or object provided, get current cart ID
		if ( ! is_int($cart_id) ) $cart_id = store_get_active_cart_id();

		// Still no ID? abort
		if ( ! $cart_id ) return false;

		// If null or false given for status, set to default
		if ( ! $status ) $status = 'active';

		$field = false;

		// If status is a string, field is slug
		if ( is_string($status) ) $field = 'slug';

		// If status is a integer, field is id
		if ( is_int($status) ) $field = 'id';

		// If field is still false, abort
		if ( ! $field ) return false;

		// Set cart meta to be that status, return result
		$output = false;
		$existing_term = get_term_by( $field, $status, 'store_status' );
		if ( $existing_term ) $output = wp_set_post_terms( $cart_id, $existing_term->name, 'store_status' );

		return $output;

	}

/*
 * @Description: Set a custom order status
 *
 * @Param: STRING, desired title of your status
 * @Returns: BOOL, returns true on success, or false on failure
 */
	function store_add_custom_order_status( $status = null ) {

		if ( ! is_string( $status ) ) return false;

		$term_exists = get_term_by( 'slug', $status, 'store_status' );

		if ( $term_exists ) return false;

		return wp_insert_term( $store_status, 'store_status' );

	}

/*
 * @Description: Save a given cart ID to a user or to a cookie for not logged in guests. This can only be run before headers are sent.
 *
 * @Param: INT, user ID to save to, if not set uses current logged in user. 
 * @Param: INT, cart post ID to set as active. Required.
 * @Returns: MIXED, returns update_user_meta() value if logged in, else setcookie() value
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

			// Save cart id to user
			return update_user_meta( $user_id, '_store_active_cart_id', $cart_id);

	 	} else {
		 	// Not logged in, save to cookie
			return setcookie('_store_active_cart_id', $cart_id, time()+3600*24*30, '/', store_get_cookie_url(), false);  /* expire in 30 days */

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

		// If no product ID set, or not an INT, then return false.
		if( empty($product_id) || !is_int($product_id) ) {
			return false;
		}

		// Check product is avaible to add to cart
		if( !store_is_product_available($product_id) ) {
			return false;			
		}

		// Test cart is set and is still avaible
		if( !empty($cart_id) && store_is_cart_available($cart_id) ) {
			// Cart is set, and is avaible, use it!
			$cart_id = $cart_id;

		} else {
			// Fallback to active cart
			$cart_id = store_get_active_cart_id();						
		}

		// If still no cart, make one!
		if( empty($cart_id) ) {
			$cart_id = store_create_active_cart($cart_id);
		}

		// Get cart product meta as array
		$products = get_post_meta($cart_id, '_store_cart_products', true);

		if ( isset($products[$product_id]) ) {

			$products[$product_id] = intval($products[$product_id]) + $quantity;

		} else {

			// Add product ID to array
			$products[$product_id] = $quantity;

		}

		// Save meta array, return result
		return update_post_meta($cart_id, '_store_cart_products', $products);
		die;
	};


/*
 * @Description: Remove a given product by ID from a cart (by ID)
 *
 * @Param 1: INT, product ID to remove from cart. Required.
 * @Param 2: INT, cart post ID to set as active. If not set, then use active cart. Optional.
 * @Param 3: INT, quantity of items to reduce cart qty by. Optional.
 * @Returns: BOOL, true if saved, false if not saved
 */
	function store_remove_product_from_cart($product_id = null, $cart_id = null, $quantity = -1){

		// If product_id is not integer, abort
		if ( ! is_int($product_id) ) return false;

		// if no proper cart_id, set to be active ID
		if ( ! is_int($cart_id) ) $cart_id = store_get_active_cart_id();

		// Get cart product meta as array
		$products = get_post_meta($cart_id, '_store_cart_products', true);

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
		return update_post_meta($cart_id, '_store_cart_products', $products);

	};

/*
 * @Description: Remove all items from a cart
 *
 * @Param: INT, post ID of cart to empty. Optional.
 * @Returns: BOOL, true if emptied, false if nothing accomplished
 */
	function store_empty_cart($cart_id = null) {

		// If no proper ID provided, get active cart
		if ( ! is_int($cart_id) ) $cart_id = store_get_active_cart_id();

		// Get products in cart
		$products = get_post_meta($cart_id, '_store_cart_products', true);

		// If no products, abort
		if ( ! $products ) return false;

		return update_post_meta($cart_id, '_store_cart_products', false);

	};


/*
 * @Description: Get the cart post object (uses get_post).
 *
 * @Param: INT, cart ID.
 * @Returns: MIXED, returns a WP_Post object, or null. Just like get_post().
 */
 	function store_get_cart($cart_id = null) {
	 	return get_post($cart_id);
 	}

/*
 * @Description: Get all items in cart by ID
 *
 * @Param: INT, cart ID. Optional.
 * @Returns: MIXED, returns an array of cart items (value of _store_cart_products ), or false on failure
 */
 	function store_get_cart_items($cart_id = null) {

 		// If no proper cart ID provided, use active cart
 		if ( ! is_int() ) $cart_id = store_get_active_cart_id();

 		// Set output
 		$output = false;
 		if ( $cart_id )
 			$output = get_post_meta($cart_id, '_store_cart_products', true);

	 	return $output;

 	}

/*
 * @Description: Get shipping address for this cart/order
 *
 * @Param: INT, cart ID. If none provided, the active cart will be used. Optional.
 * @Returns: MIXED, returns an array of address properties on success, or false on failure
 */
 	function store_get_cart_shipping_address( $cart_id = null ){

	 	// Set default cart to be current cart
	 	if ( ! $cart_id ) $cart_id = store_get_active_cart_id();

	 	// set output and args
	 	$output = false;
	    $args = array(
			'posts_per_page'	=> 1,
			'meta_query'		=> array(
				'_store_address_parent'		=> $cart_id,
				'_store_address_shipping'	=> '1'
			),
			'post_type'			=> 'address'
		);

		// Query for address
		$result = get_posts($args);

		// if anything came back, set output
		if ( ! empty($result) ) {
			$address = reset($result);
		}

		// Loop through all address fields
		foreach ( store_get_address_fields() as $field ) {

			// Set each field into output array
			$output[$field] = get_post_meta( $address->ID, '_store_address_' . $field, true );

		}

		return $output;

 	}

/*
 * @Description: Get billing address for this cart/order
 *
 * @Param: INT, cart ID. If none provided, the active cart will be used. Optional.
 * @Returns: MIXED, returns an array of address properties on success, or false on failure
 */
 	function store_get_cart_billing_address( $cart_id = null ){

	 	// Set default cart to be current cart
	 	if ( ! $cart_id ) $cart_id = store_get_active_cart_id();

	 	// Still no cart? abort.
	 	if ( ! $cart_id ) return false;

	 	// set output and args
	 	$output = false;
	    $args = array(
			'posts_per_page'	=> 1,
			'meta_key'			=> '_store_address_billing',
			'meta_value'		=> '1',
			'post_parent'		=> $cart_id,
			'post_type'			=> 'address'
		);

		// Query for address
		$result = get_posts($args);

		// if anything came back, set output
		if ( ! empty($result) ) {
			$address = reset($result);
		}

		// Loop through all address fields
		foreach ( store_get_address_fields() as $field ) {

			// Set each field into output array
			$output[$field] = get_post_meta( $address->ID, '_store_address_' . $field, true );

		}

		return $output;

 	}

/*
 * @Description: Detach an address from its cart
 *
 * @Param: INT, address ID. Required.
 * @Return: Bool, true on success or false on failure
 */
 	function store_remove_address_from_cart( $address_id = null ) {

	 	if ( ! $address_id ) return false;

	 	$address = get_post( $address_id, ARRAY_A );

	 	$address['post_parent'] = 0;

	 	return wp_update_post( $address );

 	}

/*
 * @Description: Check if a given address is a shipping address
 *
 * @Param: INT, address ID. Required.
 * @Return: Bool, true if address is a shipping address, false if not
 */
 	function store_is_shipping_address( $address_id = null ) {

	 	if ( ! $address_id ) return false;

	 	$shipping = get_post_meta( $address_id, '_store_address_shipping', true );
	 	$shipping = intval($shipping);

	 	$output = false;
	 	if ( $shipping ) $output = true;

	 	return $output;

 	}

/*
 * @Description: Check if a given address is a billing address
 *
 * @Param: INT, address ID. Required.
 * @Return: Bool, true if address is a billing address, false if not
 */
 	function store_is_billing_address( $address_id = null ) {

	 	if ( ! $address_id ) return false;

	 	$shipping = get_post_meta( $address_id, '_store_address_billing', true );
	 	$shipping = intval($shipping);

	 	$output = false;
	 	if ( $shipping ) $output = true;

	 	return $output;

 	}

/*
 * @Description: Delete an address from the database
 *
 * @Param: INT, address ID. Required.
 * @Return: Bool, true on success or false on failure
 */
 	function store_delete_address( $address_id = null ) {

	 	if ( ! $address_id ) return false;

	 	return wp_delete_post($address_id, true);

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

?>