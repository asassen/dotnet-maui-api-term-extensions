<?php
/**
 * Handles all REST API routes and callbacks for the MAUI App.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Maui_REST_API_Endpoints {

    /**
     * Namespace for the API endpoints
     */
    private $namespace = 'my-app/v1';

    /**
     * Hook into the WordPress REST API initialization
     */
    public function init() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register GET and POST routes
     */
    public function register_routes() {
        // Handle GET and POST requests on: /wp-json/my-app/v1/term-meta/{id}
        register_rest_route( $this->namespace, '/term-meta/(?P<id>\\d+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE, // 'GET'
                'callback'            => array( $this, 'get_term_meta_callback' ),
                'permission_callback' => '__return_true', // Open to public viewing
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE, // 'POST'
                'callback'            => array( $this, 'update_term_meta_callback' ),
                'permission_callback' => array( $this, 'check_write_permissions' ),
            ),
        ) );
		// Create Loyverse order with backdated timestamps:
		// /wp-json/my-app/v1/loyverse-order
		register_rest_route( $this->namespace, '/loyverse-order', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST
				'callback'            => array( $this, 'create_loyverse_order_callback' ),
				'permission_callback' => array( $this, 'check_write_permissions' ),
				),
		) );
    }

     /**
     * Callback for GET requests - Fetches ALL meta for the term
     */
     public function get_term_meta_callback( WP_REST_Request $request ) {
        $term_id = (int) $request['id'];
        
        // 1. Diagnostic Check: Does the term actually exist?
        $term_exists = get_term( $term_id );
        if ( is_wp_error( $term_exists ) || null === $term_exists ) {
            return new WP_REST_Response( array(
                'error'   => 'invalid_term_id',
                'message' => "WordPress cannot find any taxonomy term (category/tag) with ID: " . $term_id
            ), 404 ); // Sends back a clear 404 error instead of a fake 200
        }

        // 2. Fetch all meta
        $all_meta = get_term_meta( $term_id );
        
        if ( empty( $all_meta ) ) {
            return new WP_REST_Response( array(
                'term_id'   => $term_id,
                'term_name' => $term_exists->name,
                'message'   => 'The term exists, but it has no metadata saved in wp_termmeta yet.',
                'meta'      => new stdClass() 
            ), 200 );
        }

        // 3. Clean up the response formatting
        $flattened_meta = array();
        foreach ( $all_meta as $key => $values ) {
            $flattened_meta[$key] = ( count( $values ) === 1 ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
        }
        
        return new WP_REST_Response( array(
            'term_id'   => $term_id,
            'term_name' => $term_exists->name,
            'meta'      => $flattened_meta
        ), 200 );
    }
	public function create_loyverse_order_callback( WP_REST_Request $request ) {
    if ( ! function_exists( 'wc_create_order' ) ) {
        return new WP_Error( 'woocommerce_missing', 'WooCommerce is not active.', array( 'status' => 500 ) );
    }

    $p = $request->get_json_params();
    if ( ! is_array( $p ) ) {
        return new WP_Error( 'invalid_payload', 'Invalid JSON payload.', array( 'status' => 400 ) );
    }

    try {
        $order = wc_create_order();

        // Basic fields
        $status   = isset($p['status']) ? sanitize_text_field($p['status']) : 'completed';
        $currency = isset($p['currency']) ? sanitize_text_field($p['currency']) : get_woocommerce_currency();
        $total    = isset($p['total']) ? (float) $p['total'] : 0.0;

        $order->set_status( $status );
        $order->set_currency( $currency );

        // Billing/shipping passthrough (optional)
        if ( ! empty( $p['billing'] ) && is_array( $p['billing'] ) ) {
            $order->set_address( array_map( 'wc_clean', $p['billing'] ), 'billing' );
        }
        if ( ! empty( $p['shipping'] ) && is_array( $p['shipping'] ) ) {
            $order->set_address( array_map( 'wc_clean', $p['shipping'] ), 'shipping' );
        }

        // Line items
        if ( empty( $p['line_items'] ) || ! is_array( $p['line_items'] ) ) {
            return new WP_Error( 'missing_line_items', 'line_items is required.', array( 'status' => 400 ) );
        }

        foreach ( $p['line_items'] as $li ) {
            $product_id = isset($li['product_id']) ? (int) $li['product_id'] : 0;
            $qty        = isset($li['quantity']) ? (float) $li['quantity'] : 0;
            if ( $product_id <= 0 || $qty <= 0 ) {
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $item = new WC_Order_Item_Product();
            $item->set_product( $product );
            $item->set_quantity( $qty );

            if ( isset( $li['subtotal'] ) ) $item->set_subtotal( wc_format_decimal( $li['subtotal'] ) );
            if ( isset( $li['total'] ) )    $item->set_total( wc_format_decimal( $li['total'] ) );

            // Optional per-line meta
            if ( ! empty( $li['meta_data'] ) && is_array( $li['meta_data'] ) ) {
                foreach ( $li['meta_data'] as $m ) {
                    if ( empty( $m['key'] ) ) continue;
                    $item->add_meta_data( sanitize_text_field( $m['key'] ), wc_clean( $m['value'] ?? '' ), true );
                }
            }

            $order->add_item( $item );
        }

        // Optional fee lines
        if ( ! empty( $p['fee_lines'] ) && is_array( $p['fee_lines'] ) ) {
            foreach ( $p['fee_lines'] as $f ) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name( sanitize_text_field( $f['name'] ?? 'Fee' ) );
                $fee->set_total( wc_format_decimal( $f['total'] ?? 0 ) );
                $order->add_item( $fee );
            }
        }

        // Order meta_data
        if ( ! empty( $p['meta_data'] ) && is_array( $p['meta_data'] ) ) {
            foreach ( $p['meta_data'] as $m ) {
                if ( empty( $m['key'] ) ) continue;
                $order->update_meta_data( sanitize_text_field( $m['key'] ), wc_clean( $m['value'] ?? '' ) );
            }
        }

        // Totals
        $order->set_total( $total );

        // Force timestamps from Loyverse
        // expected: receipt_date_utc => 2025-11-30T12:42:04Z
        if ( ! empty( $p['receipt_date_utc'] ) ) {
            $ts = sanitize_text_field( $p['receipt_date_utc'] );
            $dt = new WC_DateTime( $ts );

            $order->set_date_created( $dt );
            $order->set_date_paid( $dt );
            $order->set_date_completed( $dt );
        }

        $order->save();

        // Force underlying post dates too (for non-HPOS / compatibility)
        if ( ! empty( $p['receipt_date_utc'] ) && 'shop_order' === get_post_type( $order->get_id() ) ) {
            $ts    = sanitize_text_field( $p['receipt_date_utc'] );
            $gmt   = gmdate( 'Y-m-d H:i:s', strtotime( $ts ) );
            $local = get_date_from_gmt( $gmt, 'Y-m-d H:i:s' );

            wp_update_post( array(
                'ID'            => $order->get_id(),
                'post_date'     => $local,
                'post_date_gmt' => $gmt,
            ) );
        }

        return new WP_REST_Response( array(
            'success'           => true,
            'order_id'          => $order->get_id(),
            'date_created'      => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'date_paid'         => $order->get_date_paid() ? $order->get_date_paid()->date('c') : null,
            'date_completed'    => $order->get_date_completed() ? $order->get_date_completed()->date('c') : null,
        ), 201 );

    } catch ( Exception $e ) {
        return new WP_Error( 'order_create_failed', $e->getMessage(), array( 'status' => 500 ) );
    }
}

	 /**
     * Callback for POST requests - Adds or Updates multiple meta fields at once
     */
    public function update_term_meta_callback( WP_REST_Request $request ) {
        $term_id = (int) $request['id'];
        $params  = $request->get_json_params();
        
        // Ensure the term actually exists before trying to modify metadata
        $term_exists = get_term( $term_id );
        if ( is_wp_error( $term_exists ) || null === $term_exists ) {
            return new WP_Error( 'invalid_term', 'Cannot update meta because this term ID does not exist.', array( 'status' => 404 ) );
        }

        // Check if the 'meta' object was provided in the body request
        if ( ! isset( $params['meta'] ) || ! is_array( $params['meta'] ) ) {
            return new WP_Error( 'missing_meta_object', 'The request body must contain a \"meta\" object.', array( 'status' => 400 ) );
        }

        $updated_fields = array();

        // Loop through all incoming key-value pairs
        foreach ( $params['meta'] as $meta_key => $meta_value ) {
            // Sanitize both key and value for security
            $sanitized_key   = sanitize_key( $meta_key );
            $sanitized_value = is_array( $meta_value ) ? array_map( 'sanitize_text_field', $meta_value ) : sanitize_text_field( $meta_value );

            // update_term_meta handles creating a new row OR updating an existing row
            update_term_meta( $term_id, $sanitized_key, $sanitized_value );
            
            $updated_fields[$sanitized_key] = $sanitized_value;
        }

        // Optional: Force WordPress to drop cache index to reflect updates immediately
        clean_term_cache( $term_id );

        return new WP_REST_Response( array(
            'success'   => true,
            'message'   => 'All term metadata processed successfully.',
            'term_id'   => $term_id,
            'term_name' => $term_exists->name,
            'updated'   => $updated_fields
        ), 200 );
    }

    /**
     * Authorization gatekeeper for POST modifications
     */
    public function check_write_permissions( WP_REST_Request $request ) {
        // Open for testing. Change to current_user_can('edit_terms') for live auth.
        return current_user_can( 'edit_shop_orders' ); 
    }
}
