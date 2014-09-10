<?php

	if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
 * @Description: Simple check if shipwire is enabled
 *
 * @Returns: BOOL, if shipping is enabled, false on failure
 */
	function store_is_shipping_enabled(){
		$sw_settings = get_option('store_sw_settings');
		return $sw_settings['enabled'];
	}


/*
 * @Description: Send a full inventory request to shipwire
 *
 * @Param: MIXED, ID of product to retrieve quantity for, or $post object
 * @Returns: MIXED, shipwire xml response on success, bool false on failure
 */
	function store_shipwire_request_inventory(){

		// Set unique ID by session
		// Use md5 hash to gaurantee 16 char length
		$session = md5(session_id(), true);

		// if transient is set, return it
		if ( $output = get_transient( $session . '_sw_inventory' ) ) {

			$output = simplexml_load_string( $output );
			return $output;

		} else {

			// Get user options
			$options = get_option('store_sw_settings');

			// Not enabled in settings? abort
			if ( ! $options['enabled'] ) return false;

			// Set URL to send request to
			$url = 'https://api.shipwire.com/exec/InventoryServices.php';

			// Set XML request
			$_ = array('<?xml version="1.0" encoding="UTF-8"?>');
			$_[] = '<!DOCTYPE InventoryUpdate SYSTEM "http://www.shipwire.com/exec/download/InventoryUpdate.dtd">';
			$_[] = '<InventoryUpdate>';
				$_[] = '<Username>' . $options['usnm'] . '</Username>';
				$_[] = '<Password>' . $options['pswd'] . '</Password>';
				$_[] = '<Server>Production</Server>';
			$_[] = '</InventoryUpdate>';
			$request = join( "\n", $_ );

			// Send request
			$response = wp_remote_post(
			    $url,
			    array(
			        'method' => 'POST',
			        'timeout' => 45,
			        'redirection' => 5,
			        'httpversion' => '1.0',
			        'headers' => array(
						'Content-Type' => 'application/xml',
			        ),
			        'body' => trim( $request ),
			        'sslverify' => false
			    )
			);
			$body = wp_remote_retrieve_body( $response );

			// Parse XML into usable object
			$output = simplexml_load_string( $body );

			// Cache raw shipwire API response for 10 seconds
			set_transient( $session . '_sw_inventory', $body, 10 );

		}

		// Return object of response
		return $output;
	}


/*
 * @Description: Get current shipwire inventory for a specific product. 
 * This function is optimized, feel free to run it within a loop
 *
 * @Param: MIXED, ID or obj of product to retrieve quantity for, or $post object. If string provided, function will assume it is the SKU
 * @Returns: MIXED, integer value of quantity on success, bool false on failure
 */
	function store_get_shipwire_qty( $product = null ){

		$sku = false;

		// If prod is string, assume it's the SKU
		if ( is_string($product) ) {
			$sku = $product;

		// Otherwise get full post object
		} else {
			// get product object
			$product = store_get_product( $product );
			$sku = $product->_store_sku;
		}

		// still no product? abort.
		if ( ! $sku ) return false;

		// Get full inventory
		$inventory = store_shipwire_request_inventory();

		// loop through inventory and find target product
		$output = false;
		foreach ( $inventory as $item ) {

			foreach ( $item->attributes() as $atts ) {
				if ( $atts == $sku ) {
					// Set output to be integer value of quantity
					$output = intval( $item->attributes()->quantity );
					break;
				}
			}

		}

		return $output;
	}


/*
 * @Description: Update inventory for one or all products
 *
 * @Param: MIXED, ID or object of product to retrieve quantity for. If none, all will be updated. Optional.
 * @Returns: MIXED, integer value of quantity changed on success, bool false on failure
 */
	function store_update_shipwire_inventory( $product = null ){

		$output = 0;

		// Set product to default, unless product was left empty
		if ( $product ) {

			$output = store_update_shipwire_inventory_single($product);

		// If product was left empty, update all products
		} else {

		    $args = array(
				'posts_per_page'   => -1,
				'post_type'        => 'product',
				'post_parent'      => 0
			);
			$products = get_posts($args);

			// Get all top-level products, loop through them
			if ( $products ) {
				foreach ( $products as $target_product ) {

					// update inventory for this product
					$inv = store_update_shipwire_inventory_single($target_product);

					// on success, add count to output
					if ( $inv ) $output += $inv;

				}

				// If output is 0, set to false
				if ( ! $output ) $output = false;

			}

		}

		return $output;
	}


/*
 * @Description: Update inventory of a single product (from shipwire)
 *
 * @Param: MIXED, ID or object or SKU of product to retrieve quantity for. Required.
 * @Returns: MIXED, integer value of quantity on success, bool false on failure
 */
	function store_update_shipwire_inventory_single( $product = null ){

		// get full post object
		$product = get_post( $product );

		// Guarantee that this is a product post object
		if ( ! is_object($product) ) {
			return false;
		} else {
			if ( $product->post_type !== 'product' ) return false;
		}

		// Start counter
		$count = 0;

		// If there are variants for this product...
		if ( $variants = store_get_product_variants($product) ) {

			// loop through variants
			foreach ( $variants as $variant ) {

				// get quantity from shipwire for this variant
				$qty = store_get_shipwire_qty($variant);

				if ( $qty ) {
					$count++;
					update_post_meta($variant->ID, '_store_qty', $qty);
					update_post_meta($variant->ID, '_store_shipwire_synced', true);
				} else {
					update_post_meta($variant->ID, '_store_shipwire_synced', false);
				}

			}

		// No variants? attempt to update qty for just this product
		} else {

			// Get qty from shipwire
			$qty = store_get_shipwire_qty($product);

			// if there is inventory, update and mark as synced
			if ( $qty ) {
				$count++;
				update_post_meta($product->ID, '_store_qty', $qty);
				update_post_meta($product->ID, '_store_shipwire_synced', true);

			// No inventory? mark as unsynced
			} else {
				update_post_meta($product->ID, '_store_shipwire_synced', false);

			}

		}

		// if count is 0, set to output false
		if ( ! $count ) $count = false;

		return $count;
	}


/*
 * @Description: submit order to shipwire
 *
 * @Param: MIXED, ID or object of order to submit
 * @Returns: MIXED, XML object of shipwire response on success, false on failure
 */
	function store_shipwire_request_order( $order = null, $shipping_method = null ){

		if ( empty($shipping_method) ) $shipping_method = 'GD';

		// Get user options
		$options = get_option('store_sw_settings');

		// Not enabled in settings? abort
		if ( ! $options['enabled'] ) return false;

		// if order has already been submitted to shipwire, abort.
		if ( get_post_meta($order->ID, '_store_shipwire_receipt', true ) ) return false;

		// Get order items, return false if none exist.
		$items = store_get_order_items($order);

		// Get shipping address for this order
		$ship_address = store_get_order_shipping_address($order);

		// If all not all data is available, abort
		if ( ! $items || ! $ship_address ) return false;

		// Set customer email if available
		if ( $order->post_author ) $customer = store_get_customer( $order->post_author );
		$email = $customer->user_email ? $customer->user_email : '';

		// Set URL to send request to
		$url = 'https://api.shipwire.com/exec/FulfillmentServices.php';

		// Set XML request
		$_ = array('<?xml version="1.0" encoding="UTF-8"?>');
		$_[] = '<!DOCTYPE OrderList SYSTEM "http://www.shipwire.com/exec/download/OrderList.dtd">';
		$_[] = '<OrderList>';
			$_[] = '<Username>' . $options['usnm'] . '</Username>';
			$_[] = '<Password>' . $options['pswd'] . '</Password>';
			$_[] = '<Server>Production</Server>';
			$_[] = '<AffiliateId>10852</AffiliateId>';
			$_[] = '<Order id="order-' . $order->ID . '">';
				$_[] = '<Warehouse>00</Warehouse>';
				$_[] = '<AddressInfo type="ship">';
					// NAME STUFF GOES HERE
					$_[] = '<Address1>' . $ship_address['line_1'] . '</Address1>';
					$_[] = '<Address2>' . $ship_address['line_2'] . '</Address2>';
					$_[] = '<City>' . $ship_address['city'] . '</City>';
					$_[] = '<State>' . $ship_address['state'] . '</State>';
					$_[] = '<Country>us</Country>';
					$_[] = '<Zip>' . $ship_address['zip'] . '</Zip>';
					$_[] = '<Phone></Phone>';
					$_[] = '<Email>' . $email . '</Email>';
				$_[] = '</AddressInfo>';
				$_[] = '<Shipping>' . $shipping_method . '</Shipping>';

				$count = 0;
				// Loop through order products and add to xml
				foreach ( $items as $id => $qty ) {

					$product = store_get_product($id);
					if ( ! $product || ! $product->_store_sku ) continue;

					$_[] = '<Item num="' . $count . '">';
						$_[] = '<Code>' . $product->_store_sku . '</Code>';
						$_[] = '<Quantity>' . $qty . '</Quantity>';
					$_[] = '</Item>';

					$count++;

				};

			$_[] = '</Order>';
		$_[] = '</OrderList>';
		$request = join( "\n", $_ );

		// Set output
		$output = false;

		// Send request
		$response = wp_remote_post(
		    $url,
		    array(
		        'method' => 'POST',
		        'timeout' => 45,
		        'redirection' => 5,
		        'httpversion' => '1.0',
		        'headers' => array(
					'Content-Type' => 'application/xml',
		        ),
		        'body' => trim( $request ),
		        'sslverify' => false
		    )
		);
		$body = wp_remote_retrieve_body( $response );

		// Parse XML into usable object
		$output = simplexml_load_string( $body );

		// Set output to be status of request
		$status = store_shipwire_retrieve_status($output);

		// If order was successful, save receipt
		if ( $status ) update_post_meta($order->ID, '_store_shipwire_receipt', $body );

		return $output;
	}


/*
 * @Description: get receipt from a completed order
 *
 * @Param: MIXED, ID or object of order.
 * @Returns: MIXED, XML object of shipwire receipt on success, false on failure
 */
	function store_get_shipwire_receipt( $order = null ){

		// Get valid object
		$order = get_post($order);

		// attempt to get receipt from meta
		$xml = get_post_meta($order->ID, '_store_shipwire_receipt', true );

		$output = false;
		if ( $xml ) 
			$output = simplexml_load_string($xml);

		return $output;

	}


/*
 * @Description: get shipping quote from shipwire based on order
 *
 * @Param: MIXED, ID or object of order to quote.
 * @Returns: MIXED, array of shipping options on success, false on failure
 */
	function store_shipwire_request_order_shipping( $order = null ){

		// Get user options
		$options = get_option('store_sw_settings');

		// Not enabled in settings? abort
		if ( ! $options['enabled'] ) return false;

		// get full order object
		$order = get_post( $order );

		// Get order items, return false if none exist.
		$items = store_get_order_items($order);

		// Get shipping address for this order
		$ship_address = store_get_order_shipping_address($order);

		// If all not all data is available, abort
		if ( ! $order || ! $items || ! $ship_address ) return false;

		// Set URL to send request to
		$url = 'https://api.shipwire.com/exec/RateServices.php';

		// Set XML request
		$_ = array('<?xml version="1.0" encoding="UTF-8"?>');
		$_[] = '<!DOCTYPE RateRequest SYSTEM "http://www.shipwire.com/exec/download/RateRequest.dtd">';
		$_[] = '<RateRequest>';
			$_[] = '<Username>' . $options['usnm'] . '</Username>';
			$_[] = '<Password>' . $options['pswd'] . '</Password>';
			$_[] = '<Order id="order-' . $order->ID . '">';
				$_[] = '<Warehouse>00</Warehouse>'; // leave this as 0, shipwire will decide
				$_[] = '<AddressInfo type="ship">';
					$_[] = '<Address1>' . $ship_address['line_1'] . '</Address1>';
					$_[] = '<Address2>' . $ship_address['line_2'] . '</Address2>';
					$_[] = '<City>' . $ship_address['city'] . '</City>';
					$_[] = '<State>' . $ship_address['state'] . '</State>';
					$_[] = '<Country>us</Country>';
					$_[] = '<Zip>' . $ship_address['zip'] . '</Zip>';
				$_[] = '</AddressInfo>';

				$count = 0;
				// Loop through order products and add to xml
				foreach ( $items as $id => $qty ) {

					$product = store_get_product($id);
					if ( ! $product || ! $product->_store_sku ) continue;

					$_[] = '<Item num="' . $count . '">';
						$_[] = '<Code>' . $product->_store_sku . '</Code>';
						$_[] = '<Quantity>' . $qty . '</Quantity>';
					$_[] = '</Item>';

					$count++;
				};

			$_[] = '</Order>';
		$_[] = '</RateRequest>';
		$request = join( "\n", $_ );

		// Set output
		$output = false;

		// Send request
		$response = wp_remote_post(
			$url,
			array(
				'method' => 'POST',
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'headers' => array(
					'Content-Type' => 'application/xml',
				),
				'body' => trim( $request ),
				'sslverify' => false
			)
		);
		$body = wp_remote_retrieve_body( $response );

		// Parse XML into usable object
		$output = simplexml_load_string( $body );

		// Return useful output of shipping info.
		return $output;
	}


/*
 * @Description: get shipping quote from shipwire based on address and cart
 *
 * @Param: MIXED, address object to quote shipping for. Required.
 * @Param: MIXED, ID or object of cart to quote for. Defaults to active cart. Optional.
 * @Returns: MIXED, array of shipping options on success, false on failure
 */
	function store_shipwire_request_cart_shipping( $address = null, $cart = null ){

		// Get user options
		$options = get_option('store_sw_settings');

		// Not enabled in settings? abort
		if ( ! $options['enabled'] ) return false;

		// get full cart object
		$cart = store_get_cart($cart);

		// Get order items, return false if none exist.
		$items = store_get_cart_items($cart);

		// If all not all data is available, abort
		if ( ! $cart || ! $items || ! $address ) return false;

		// Set URL to send request to
		$url = 'https://api.shipwire.com/exec/RateServices.php';

		// Set XML request
		$_ = array('<?xml version="1.0" encoding="UTF-8"?>');
		$_[] = '<!DOCTYPE RateRequest SYSTEM "http://www.shipwire.com/exec/download/RateRequest.dtd">';
		$_[] = '<RateRequest>';
			$_[] = '<Username>' . $options['usnm'] . '</Username>';
			$_[] = '<Password>' . $options['pswd'] . '</Password>';
			$_[] = '<Order id="order-' . $order->ID . '">';
				$_[] = '<Warehouse>00</Warehouse>'; // leave this as 0, shipwire will decide
				$_[] = '<AddressInfo type="ship">';
					$_[] = '<Address1>' . $address['line_1'] . '</Address1>';
					$_[] = '<Address2>' . $address['line_2'] . '</Address2>';
					$_[] = '<City>' . $address['city'] . '</City>';
					$_[] = '<State>' . $address['state'] . '</State>';
					$_[] = '<Country>' . $address['country'] . '</Country>';
					$_[] = '<Zip>' . $address['zip'] . '</Zip>';
				$_[] = '</AddressInfo>';

				$count = 0;
				// Loop through order products and add to xml
				foreach ( $items as $id => $qty ) {

					$product = store_get_product($id);
					if ( ! $product || ! $product->_store_sku ) continue;

					$_[] = '<Item num="' . $count . '">';
						$_[] = '<Code>' . $product->_store_sku . '</Code>';
						$_[] = '<Quantity>' . $qty . '</Quantity>';
					$_[] = '</Item>';

					$count++;
				};

			$_[] = '</Order>';
		$_[] = '</RateRequest>';
		$request = join( "\n", $_ );

		// Set output
		$output = false;

		// Send request
		$response = wp_remote_post(
			$url,
			array(
				'method' => 'POST',
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'headers' => array(
					'Content-Type' => 'application/xml',
				),
				'body' => trim( $request ),
				'sslverify' => false
			)
		);
		$body = wp_remote_retrieve_body( $response );

		// Parse XML into usable object
		$output = simplexml_load_string( $body );

		return $output;
	}


/*
 * @Description: check if a shipwire request was successful, works in tandem with any function that starts with store_shipwire_request_
 *
 * @Param: MIXED, simpleXML object (shipwire api response), or false if request function has failed
 * @Returns: BOOL, true if request was successful, false on failure
 */
	function store_shipwire_retrieve_status( $response = false ){

		if ( ! is_object($response) ) return false;

		$output = false;
		if ( $response->Status ) $output = true;

		return $output;
	}


/*
 * @Description: get shipping options from a shipping request
 *
 * @Param: MIXED, simpleXML object (output of store_shipwire_request_shipping()), or false if request function has failed
 * @Returns: MIXED, array of shippng options if successful, false on failure
 */
	function store_shipwire_retrieve_shipping( $response = false ){

		// If response came back with errors, abort
		if ( ! store_shipwire_retrieve_status($response) ) return $response;

		$i = 0;
		$output = false;
		foreach( $response->Order->Quotes->Quote as $quote ){

			// Format relevant figures into output
			$output[$i]['service'] = (string) $quote->CarrierCode;
			$output[$i]['method'] = (string) $quote->attributes()->method;
			$output[$i]['cost'] = (string) $quote->Cost;
			$output[$i]['delivery']['min'] = (string) $quote->DeliveryEstimate->Minimum;
			$output[$i]['delivery']['max'] = (string) $quote->DeliveryEstimate->Maximum;

			$i++;

		}

		return $output;
	}

?>