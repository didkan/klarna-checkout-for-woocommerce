<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klarna_Checkout_For_WooCommerce_API class.
 *
 * Class that talks to KCO API, wrapper for V2 and V3.
 */
class Klarna_Checkout_For_WooCommerce_API {

	/**
	 * Klarna Checkout for WooCommerce settings.
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Klarna Checkout api URL base.
	 *
	 * @var string
	 */
	private $api_url_base = '';

	/**
	 * Klarna Checkout merchant ID.
	 *
	 * @var string
	 */
	private $merchant_id = '';

	/**
	 * Klarna Checkout shared secret.
	 *
	 * @var string
	 */
	private $shared_secret = '';

	/**
	 * Klarna_Checkout_For_WooCommerce_API constructor.
	 */
	public function __construct() {
		$this->settings = get_option( 'woocommerce_klarna_checkout_for_woocommerce_settings' );

		add_action( 'woocommerce_init', array( $this, 'load_credentials' ) );
		add_action( 'woocommerce_init', array( $this, 'set_api_url_base' ) );
	}

	/**
	 * Creates Klarna Checkout order.
	 *
	 * @return array|mixed|object|WP_Error
	 */
	public function request_pre_create_order() {
		$request_url  = $this->api_url_base . 'checkout/v3/orders';
		$request_args = array(
			'headers' => $this->get_request_headers(),
			'body'    => $this->get_request_body( 'create' ),
		);

		$response = wp_safe_remote_post( $request_url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $this->extract_error_messages( $response );
		}

		if ( $response['response']['code'] >= 200 && $response['response']['code'] <= 299 ) {
			$klarna_order = json_decode( $response['body'] );
			$this->save_order_id_to_session( $klarna_order->order_id );

			return $klarna_order;
		} else {
			$error = $this->extract_error_messages( $response );

			return $error;
		}
	}

	/**
	 * Retrieve ongoing Klarna order.
	 *
	 * @param  string $klarna_order_id Klarna order ID.
	 *
	 * @return object $klarna_order    Klarna order.
	 */
	public function request_pre_retrieve_order( $klarna_order_id ) {
		$request_url  = $this->api_url_base . 'checkout/v3/orders/' . $klarna_order_id;
		$request_args = array(
			'headers' => $this->get_request_headers(),
		);

		$response = wp_safe_remote_get( $request_url, $request_args );

		if ( $response['response']['code'] >= 200 && $response['response']['code'] <= 299 ) {
			$klarna_order = json_decode( $response['body'] );

			return $klarna_order;
		} else {
			$error = $this->extract_error_messages( $response );

			return $error;
		}
	}

	/**
	 * Update ongoing Klarna order.
	 *
	 * @return object $klarna_order Klarna order.
	 */
	public function request_pre_update_order() {
		$klarna_order_id = $this->get_order_id_from_session();
		$request_url     = $this->api_url_base . 'checkout/v3/orders/' . $klarna_order_id;
		$request_args    = array(
			'headers' => $this->get_request_headers(),
			'body'    => $this->get_request_body(),
		);

		// No update if nothing changed in data being sent to Klarna.
		if ( WC()->session->get( 'kco_wc_update_md5' ) && WC()->session->get( 'kco_wc_update_md5' ) === md5( serialize( $request_args ) ) ) {
			return;
		}

		$response = wp_safe_remote_post( $request_url, $request_args );

		if ( $response['response']['code'] >= 200 && $response['response']['code'] <= 299 ) {
			WC()->session->set( 'kco_wc_update_md5', md5( serialize( $request_args ) ) );

			$klarna_order = json_decode( $response['body'] );

			return $klarna_order;
		} else {
			WC()->session->__unset( 'kco_wc_update_md5' );
			WC()->session->__unset( 'kco_wc_order_id' );
			$error = $this->extract_error_messages( $response );

			return $error;
		}

	}


	/**
	 * Acknowledges Klarna Checkout order.
	 *
	 * @param  string $klarna_order_id Klarna order ID.
	 *
	 * @return WP_Error|array $response
	 */
	public function request_post_get_order( $klarna_order_id ) {
		$request_url  = $this->api_url_base . 'ordermanagement/v1/orders/' . $klarna_order_id;
		$request_args = array(
			'headers' => $this->get_request_headers(),
		);

		$response = wp_safe_remote_get( $request_url, $request_args );

		return $response;
	}

	/**
	 * Acknowledges Klarna Checkout order.
	 *
	 * @param  string $klarna_order_id Klarna order ID.
	 *
	 * @return WP_Error|array $response
	 */
	public function request_post_acknowledge_order( $klarna_order_id ) {
		$request_url  = $this->api_url_base . 'ordermanagement/v1/orders/' . $klarna_order_id . '/acknowledge';
		$request_args = array(
			'headers' => $this->get_request_headers(),
		);

		$response = wp_safe_remote_post( $request_url, $request_args );

		return $response;
	}

	/**
	 * Adds WooCommerce order ID to Klarna order as merchant_reference. And clear Klarna order ID value from WC session.
	 *
	 * @param  string $klarna_order_id Klarna order ID.
	 * @param  array  $merchant_references Array of merchant references.
	 *
	 * @return WP_Error|array $response
	 */
	public function request_post_set_merchant_reference( $klarna_order_id, $merchant_references ) {
		$request_url  = $this->api_url_base . 'ordermanagement/v1/orders/' . $klarna_order_id . '/merchant-references';
		$request_args = array(
			'headers' => $this->get_request_headers(),
			'method'  => 'PATCH',
			'body'    => wp_json_encode( array(
				'merchant_reference1' => $merchant_references['merchant_reference1'],
				'merchant_reference2' => $merchant_references['merchant_reference2'],
			) ),
		);

		$response = wp_safe_remote_request( $request_url, $request_args );

		return $response;
	}

	/**
	 * Loads Klarna API credentials.
	 */
	public function load_credentials() {
		$credentials = KCO_WC()->credentials->get_credentials_from_session();
		$this->set_merchant_id( $credentials['merchant_id'] );
		$this->set_shared_secret( $credentials['shared_secret'] );
	}

	/**
	 * Set Klarna Checkout API URL base.
	 */
	public function set_api_url_base() {
		$base_location  = wc_get_base_location();
		$country_string = 'US' === $base_location['country'] ? '-na' : '';

		$test_string = 'yes' === $this->settings['testmode'] ? '.playground' : '';

		$this->api_url_base = 'https://api' . $country_string . $test_string . '.klarna.com/';
	}

	/**
	 * Set Klarna Checkout merchant ID.
	 *
	 * @param string $merchant_id Klarna Checkout merchant ID.
	 */
	public function set_merchant_id( $merchant_id ) {
		$this->merchant_id = $merchant_id;
	}

	/**
	 * Set Klarna Checkout shared secret.
	 *
	 * @param string $shared_secret Klarna Checkout shared secret.
	 */
	public function set_shared_secret( $shared_secret ) {
		$this->shared_secret = $shared_secret;
	}

	/**
	 * Gets Klarna order from WC_Session
	 *
	 * @return array|string
	 */
	public function get_order_id_from_session() {
		return WC()->session->get( 'kco_wc_order_id' );
	}

	/**
	 * Saves Klarna order ID to WooCommerce session.
	 *
	 * @param string $order_id Klarna order ID.
	 */
	public function save_order_id_to_session( $order_id ) {
		WC()->session->set( 'kco_wc_order_id', $order_id );
	}

	/**
	 * Gets Klarna Checkout order.
	 *
	 * If WC_Session value for Klarna order ID exists, attempt to retrieve that order.
	 * If this fails, create a new one and retrieve it.
	 * If WC_Session value for Klarna order ID does not exist, create a new order and retrieve it.
	 */
	public function get_order() {
		$order_id = $this->get_order_id_from_session();

		if ( $order_id ) {
			$order = $this->request_pre_retrieve_order( $order_id );

			if ( ! $order || is_wp_error( $order ) ) {
				$order = $this->request_pre_create_order();
			} elseif ( 'checkout_incomplete' === $order->status ) {
				// Only update order if its status is incomplete.
				$this->request_pre_update_order();
			}
		} else {
			$order = $this->request_pre_create_order();
		}

		return $order;
	}

	/**
	 * Gets KCO iframe snippet from KCO order.
	 *
	 * @param Klarna_Order $order Klarna Checkout order.
	 *
	 * @return mixed
	 */
	public function get_snippet( $order ) {
		if ( ! is_wp_error( $order ) ) {
			$this->maybe_clear_session_values( $order );

			return $order->html_snippet;
		}

		return $order->get_error_message();
	}

	/**
	 * Clear WooCommerce session values if Klarna Checkout order is completed.
	 *
	 * @param Klarna_Order $order Klarna Checkout order.
	 */
	public function maybe_clear_session_values( $order ) {
		if ( 'checkout_complete' === $order->status ) {
			WC()->session->__unset( 'kco_wc_update_md5' );
			WC()->session->__unset( 'kco_wc_order_id' );
			WC()->session->__unset( 'kco_wc_order_notes' );
		}
	}

	/**
	 * Gets Klarna merchant ID.
	 *
	 * @return string
	 */
	public function get_merchant_id() {
		return $this->merchant_id;
	}

	/**
	 * Gets Klarna shared secret.
	 *
	 * @return string
	 */
	public function get_shared_secret() {
		return $this->shared_secret;
	}

	/**
	 * Gets country for Klarna purchase.
	 *
	 * @return string
	 */
	public function get_purchase_country() {
		return WC()->checkout()->get_value( 'billing_country' );
	}

	/**
	 * Gets currency for Klarna purchase.
	 *
	 * @return string
	 */
	public function get_purchase_currency() {
		return get_woocommerce_currency();
	}

	/**
	 * Gets locale for Klarna purchase.
	 *
	 * @return string
	 */
	public function get_purchase_locale() {
		return str_replace( '_', '-', get_locale() );
	}

	/**
	 * Gets merchant URLs for Klarna purchase.
	 *
	 * @return array
	 */
	public function get_merchant_urls() {
		return KCO_WC()->merchant_urls->get_urls();
	}

	/**
	 * Gets Klarna API request headers.
	 *
	 * @return array
	 */
	public function get_request_headers() {
		$request_headers = array(
			'Authorization' => 'Basic ' . base64_encode( $this->get_merchant_id() . ':' . $this->get_shared_secret() ),
			'Content-Type'  => 'application/json',
		);

		return $request_headers;
	}

	/**
	 * Gets Klarna API request body.
	 *
	 * @param  string $request_type Type of request.
	 *
	 * @return false|string
	 */
	public function get_request_body( $request_type = null ) {
		KCO_WC()->order_lines->process_data();

		$request_args = array(
			'purchase_country'   => $this->get_purchase_country(),
			'purchase_currency'  => $this->get_purchase_currency(),
			'locale'             => $this->get_purchase_locale(),
			'merchant_urls'      => $this->get_merchant_urls(),
			'order_amount'       => KCO_WC()->order_lines->get_order_amount(),
			'order_tax_amount'   => KCO_WC()->order_lines->get_order_tax_amount(),
			'order_lines'        => KCO_WC()->order_lines->get_order_lines(),
			'shipping_countries' => $this->get_shipping_countries()
		);

		if ( 'create' === $request_type ) {
			$request_args['billing_address'] = array(
				'email'       => WC()->checkout()->get_value( 'billing_email' ),
				'postal_code' => WC()->checkout()->get_value( 'billing_postcode' ),
				'country'     => $this->get_purchase_country()
			);

			if ( $this->get_iframe_colors() ) {
				$request_args['options'] = $this->get_iframe_colors();
			}
		}

		$request_body = wp_json_encode( $request_args );

		return $request_body;
	}

	/**
	 * Gets shipping countries formatted for Klarna.
	 *
	 * @return array
	 */
	public function get_shipping_countries() {
		$wc_countries = new WC_Countries();

		return array_keys( $wc_countries->get_shipping_countries() );
	}

	private function get_iframe_colors() {
		$color_settings = array();

		if ( $this->check_option_field( 'color_button' ) ) {
			$color_settings['color_button'] = $this->check_option_field( 'color_button' );
		}

		if ( $this->check_option_field( 'color_button_text' ) ) {
			$color_settings['color_button_text'] = $this->check_option_field( 'color_button_text' );
		}

		if ( $this->check_option_field( 'color_checkbox' ) ) {
			$color_settings['color_checkbox'] = $this->check_option_field( 'color_checkbox' );
		}

		if ( $this->check_option_field( 'color_checkbox_checkmark' ) ) {
			$color_settings['color_checkbox_checkmark'] = $this->check_option_field( 'color_checkbox_checkmark' );
		}

		if ( $this->check_option_field( 'color_header' ) ) {
			$color_settings['color_header'] = $this->check_option_field( 'color_header' );
		}

		if ( $this->check_option_field( 'color_link' ) ) {
			$color_settings['color_link'] = $this->check_option_field( 'color_link' );
		}

		if ( $this->check_option_field( 'radius_border' ) ) {
			$color_settings['radius_border'] = $this->check_option_field( 'radius_border' );
		}

		if ( count( $color_settings ) > 0 ) {
			return $color_settings;
		}

		return false;
	}

	private function check_option_field( $field ) {
		if ( array_key_exists( $field, $this->settings ) && '' !== $this->settings[ $field ] ) {
			return $this->settings[ $field ];
		}

		return false;
	}

	/**
	 * @param $response
	 *
	 * @return mixed
	 */
	private function extract_error_messages( $response ) {
		$response_body = json_decode( $response['body'] );
		$error         = new WP_Error();

		if ( ! empty( $response_body->error_messages ) && is_array( $response_body->error_messages ) ) {
			KCO_WC()->logger->log( var_export( $response_body, true ) );

			foreach ( $response_body->error_messages as $error_message ) {
				$error->add( 'kco', $error_message );
			}
		}

		return $error;
	}

}
