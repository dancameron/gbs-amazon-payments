<?php
/**
 * Amazon FPS offsite payment processor.
 *
 * @package GBS
 * @subpackage Payment Processing_Processor
 */
class Group_Buying_Amazon_FPS extends Group_Buying_Offsite_Processors  {

	// Endpoints
	const PROD_ENDPOINT_URL = "https://fps.amazonaws.com";
	const SDBX_ENDPOINT_URL = "https://fps.sandbox.amazonaws.com";
	const CBUI_PROD_ENDPOINT_URL = "https://authorize.payments.amazon.com/cobranded-ui/actions/start";
	const CBUI_SDBX_ENDPOINT_URL = "https://authorize.payments-sandbox.amazon.com/cobranded-ui/actions/start";

	const API_MODE_OPTION = "gb_amazon_mode";
	const API_MODE_SANDBOX = 'sandbox';
	const API_MODE_PRODUCTION = 'production';

	// API config data
	const API_AWS_USERNAME_OPTION = "gb_amazon_aws_username";
	const API_AWS_SECRET_KEY_OPTION = "gb_amazon_aws_secret_key";
	const API_SIGNATURE_OPTION = "gb_amazon_signature";

	// Used by amazon to reference the incoming request
	const CALLER_REFERENCE_PREFIX = "gbs_";
	const CANCEL_URL_OPTION = 'gb_amazon_cancel_url';
	const RETURN_URL_OPTION = 'gb_amazon_return_url';

	protected static $api_mode = self::API_MODE_SANDBOX;
	private static $authorization_pipeline = FALSE;
	private static $token;

	private static $aws_username;
	private static $aws_secret_key;
	private static $currency_code;
	private static $cancel_url = '';
	private static $return_url = '';

	private function get_api_url() {
		switch( self::$api_mode ) {
			case "production":
			case "prod":
				return trailingslashit( self::PROD_ENDPOINT_URL );
				break;
			case "sandbox":
			case "sdbx":
			return trailingslashit( self::SDBX_ENDPOINT_URL );
				break;
		}

		return "";
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	public static function returned_from_offsite() {
		return ( isset( $_GET['token'] ) );
	}

	public function __construct() {
		parent::__construct();

		self::$aws_username = get_option( self::API_AWS_USERNAME_OPTION, '' );
		self::$aws_secret_key = get_option( self::API_AWS_SECRET_KEY_OPTION, '' );
		self::$api_mode = get_option( self::API_MODE_OPTION, self::API_MODE_SANDBOX );
		self::$currency_code = get_option(self::API_CC_OPTION, 'USD');
		self::$cancel_url = get_option(self::CANCEL_URL_OPTION, Group_Buying_Carts::get_url());
		self::$return_url = get_option(self::RETURN_URL_OPTION, Group_Buying_Checkouts::get_url());

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );

		add_filter( 'gb_checkout_payment_controls', array( $this, 'payment_controls' ), 20, 2 );
		
		add_action( 'gb_send_offsite_for_payment', array( $this, 'send_offsite' ), 10, 1 );
		add_action( 'gb_load_cart', array( $this, 'back_from_amazon' ), 10, 0 );
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Amazon' ) );
	}

	private function build_cbui_data( Group_Buying_Checkouts $checkout ) {
		/* Mismatches between amazon and paypal
			$nvpData['USER'] = self::$api_username;
			$nvpData['PWD'] = self::$api_password;
			$nvpData['SIGNATURE'] = self::$api_signature;
			$nvpData['VERSION'] = self::$version;
	/**
	 * Instead of redirecting to the GBS checkout page,
	 * set up the transaction and redirect to amazon
	 *
	 * @param Group_Buying_Carts $cart
	 * @return void
	 */
	public function send_offsite( Group_Buying_Checkouts $checkout ) {

		$cart = $checkout->get_cart();
		if ( $cart->get_total() < 0.01 ) { // for free deals.
			return;
		}

		// Check for a token just in case the customer is coming back from amazon.
		if ( !self::returned_from_offsite() && $_REQUEST['gb_checkout_action'] == Group_Buying_Checkouts::PAYMENT_PAGE ) {

			// setup authorization
			$authorization_data = $this->build_cbui_data( $checkout );
			$cbui_pipeline = $this->get_cbui_pipeline( $authorization_data );

			if ( !empty( $cbui_url ) ) {
				wp_redirect ( $cbui_url );
				exit();
			} 
			else { // If an error occurred, with $url than redirect back to the cart and provide a message
				self::set_error_messages( $redirect['error_message_from_return'] );
				wp_redirect( Group_Buying_Carts::get_url(), 303 );
				exit();
			}
		}
	}

			$nvpData['TOKEN'] = self::get_token();
			$nvpData['PAYERID'] = self::get_payerid();

			$nvpData['METHOD'] = 'DoExpressCheckoutPayment';
			$nvpData['PAYMENTREQUEST_0_PAYMENTACTION'] = 'Authorization';
			$nvpData['IPADDRESS'] = $_SERVER ['REMOTE_ADDR'];

			$nvpData['PAYMENTREQUEST_0_INVNUM'] = $purchase->get_id();
			$nvpData['BUTTONSOURCE'] = self::PLUGIN_NAME;

			$nvpData['NOSHIPPING'] = 2;
			$nvpData['ADDROVERRIDE'] = 1;
			$nvpData['PAYMENTREQUEST_0_SHIPTONAME'] = $checkout->cache['shipping']['first_name'].' '.$checkout->cache['shipping']['last_name'];
			$nvpData['PAYMENTREQUEST_0_SHIPTOSTATE'] = $checkout->cache['shipping']['zone'];
		 */

		$cbui_data = array();
		$cart = $checkout->get_cart();
		if ( $cart->get_total() < 0.01 ) { // for free deals.
			return array();
		}

		$user = get_userdata( get_current_user_id() );
		$filtered_total = $this->get_payment_request_total( $checkout );
		if ( $filtered_total < 0.01 ) {
			return array();
		}

		$cbui_data['currency_code'] = self::get_currency_code();
		$cbui_data['total'] = gb_get_number_format( $filtered_total );
		$cbui_data['subtotal'] = gb_get_number_format( $cart->get_subtotal() );
		$cbui_data['shipping'] = gb_get_number_format( $cart->get_shipping_total() );
		$cbui_data['tax'] = gb_get_number_format( $cart->get_tax_total() );

		if ( isset( $checkout->cache['shipping'] ) ) {
			$cache = $checkout->cache['shipping'];
			$cbui_data['shipping_local'] = array(
				'street' => $cache['street'],
				'city' => $cache['city'],
				'postal_code' => $cache['postal_code'],
				'country' => $cache['country'],
			);
		}

		$i = 0;
		if (
			$cbui_data['subtotal'] == gb_get_number_format( 0 ) ||
			( $filtered_total < $cart->get_total()
			&& ( $cart->get_subtotal() + $filtered_total - $cart->get_total() ) == 0
			)
		) {
			// handle free/credit purchases (paypal requires minimum 0.01 item amount)
			if ( $cbui_data['shipping'] != gb_get_number_format( 0 ) ) {
				$cbui_data['subtotal'] = $cbui_data['shipping'];
				$cbui_data['shipping'] = gb_get_number_format( 0 );
			} elseif ( $cbui_data['tax'] != gb_get_number_format( 0 ) ) {
				$cbui_data['subtotal'] = $cbui_data['tax'];
				$cbui_data['tax'] = gb_get_number_format( 0 );
			}
		} else {
			/* I don't think we can set line by line items in Amazon, only total charge amounts

			foreach ( $cart->get_items() as $key => $item ) {
				$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
				$cbui_data['L_PAYMENTREQUEST_0_NAME'.$i] = $deal->get_title( $item['data'] );
				$cbui_data['L_PAYMENTREQUEST_0_AMT'.$i] = gb_get_number_format( $deal->get_price( NULL, $item['data'] ) );
				$cbui_data['L_PAYMENTREQUEST_0_NUMBER'.$i] = $item['deal_id'];
				$cbui_data['L_PAYMENTREQUEST_0_QTY'.$i] = $item['quantity'];
				$i++;
			}
			if ( $filtered_total < $cart->get_total() ) {
				$cbui_data['L_PAYMENTREQUEST_0_NAME'.$i] = self::__( 'Applied Credit' );
				$cbui_data['L_PAYMENTREQUEST_0_AMT'.$i] = gb_get_number_format( $filtered_total - $cart->get_total() );
				$cbui_data['L_PAYMENTREQUEST_0_QTY'.$i] = '1';
				$cbui_data['PAYMENTREQUEST_0_ITEMAMT'] = gb_get_number_format( $cart->get_subtotal() + $filtered_total - $cart->get_total() );
			}
			*/
		}

		$cbui_data['return_url'] = self::$return_url;
		$cbui_data['cancel_url'] = self::$cancel_url; // Needed?
		$cbui_data['signature'] = $this->generate_signature();
		$cbui_data = apply_filters( 'gb_amazon_cbui_data', $cbui_data, $checkout );

		return $cbui_data;
	}

	/**
	 * Takes pre-defined authorization data and retrieves a pipeline from
	 * the amazon api for redirecting a user.
	 *
	 * @param array $auth_data
	 * @param bool  $_re_cache
	 *
	 * @return Amazon_FPS_CBUISingleUsePipeline|bool
	 */
	private function get_cbui_pipeline( $auth_data = array(), $_re_cache = FALSE ) {
		if ( !isset(self::$authorization_pipeline) || $_re_cache ) {
			// Build cbui token from scratch
			$defaults = array(
				'caller_reference' => 'gbsCREFSingleUse',
				'return_url' => get_site_url(),
				'currency_code' => $this->currency_code,
				'payment_reason' => '',
				'shipping_local' => '', // string
				'subtotal' => 0, // int|float
				'shipping' => 0, // int|float
				'tax' => 0, // int|float
				'total' => 0, // int|float
			);

			wp_parse_args( $auth_data, $defaults );

			if (
				empty( $auth_data['caller_reference'] ) ||
				empty( $auth_data['return_url'] ) ||
				empty( $auth_data['total'] )
			) {
				return FALSE;
			}

			$pipeline = new Amazon_FPS_CBUISingleUsePipeline($this->aws_username, $this->aws_secret_key);
			$pipeline->setMandatoryParameters(
				$auth_data['caller_reference'],
				$auth_data['return_url'],
				$auth_data['total']
			);

			// Multi-use pipeline parameters, not necessary for single-use
			/*
			$pipeline->addParameter('usageLimitType1', 'Amount');

			$t_tl = $auth_data['token_time_limit'];
			if ( isset($t_tl) ) return FALSE;
			$pipeline->addParameter('usageLimitPeriod1', $auth_data['token_time_limit']);

			$t_ma = $auth_data['token_max_amount'];
			if ( !isset($t_ma) ) return FALSE;
			$pipeline->addParameter('usageLimitValue', $auth_data['token_max_amount']);
			*/

			// Add parameters to be displayed on the CBUI
			$cc = $auth_data['currency_code'];
			if ( isset($cc) )
				$pipeline->addParameter('currencyCode', $auth_data['currency_code']);

			$pr = $auth_data['payment_reason'];
			if ( isset($pr) )
				$pipeline->addParameter('paymentReason', $auth_data['payment_reason']);

			// Shipping address etc.
			$sh_t = $auth_data['shipping_local'];
			if ( isset($sh_t) && !empty($auth_data['shipping']) ) {
				$pipeline->addParameter('addressLine1', $auth_data['shipping']['street']);
				$pipeline->addParameter('city', $auth_data['shipping']['city']);
				$pipeline->addParameter('state', $auth_data['shipping']['state']);
				$pipeline->addParameter('country', $auth_data['shipping']['country']);
				$pipeline->addParameter('zip', $auth_data['shipping']['postal_code']);
			}

			// The cost of shipping
			$sh_l = $auth_data['shipping'];
			if ( isset($sh_l) )
				$pipeline->addParameter('shipping', $auth_data['shipping']);

			$su_t = $auth_data['subtotal'];
			if ( isset($su_t) )
				$pipeline->addParameter('itemTotal', $auth_data['subtotal']);

			$ta_t = $auth_data['tax'];
			if ( isset($ta_t) )
				$pipeline->addParameter('tax', $auth_data['tax']);

			$auth_data = apply_filters( 'gb_amazon_auth_data', $auth_data );
			if ( self::DEBUG ) {
				error_log( '----------Amazon CBUI Pipline Data----------' );
				error_log( print_r( $auth_data, TRUE ) );
			}

			// Static cache and return the pipeline
			self::$authorization_pipeline = $pipeline;
			return self::$authorization_pipeline;
		} else {
			// if we're rebuilding from a cached token, just update the mandatory parameters
			if (
				empty( $auth_data['caller_reference'] ) ||
				empty( $auth_data['return_url'] ) ||
				empty( $auth_data['total'] )
			) {
				return FALSE;
			}

			self::$authorization_pipeline->setMandatoryParameters(
				$auth_data['caller_reference'],
				$auth_data['return_url'],
				$auth_data['total']
			);

			return self::$authorization_pipeline;
		}
	}

	/**
	 * We're on the checkout page, just back from Amazon.
	 * Store the token and payer ID that Amazon gives us
	 *
	 * @return void
	 */
	public function back_from_amazon() {
		// TODO: rebuild for amazon
		if ( isset( $_GET['token'] ) && isset( $_GET['PayerID'] ) ) {
			self::set_token( urldecode( $_GET['token'] ) );
			self::set_payerid( urldecode( $_GET['PayerID'] ) );
			// let the checkout know that this isn't a fresh start
			$_REQUEST['gb_checkout_action'] = 'back_from_amazon';
		} elseif ( !isset( $_REQUEST['gb_checkout_action'] ) ) {
			// this is a new checkout. clear the token so we don't give things away for free
			self::unset_token();
		}
	}

	/**
	 * General a signature to be used on payment
	 */
	public function generate_signature() {

	}

	private function get_currency_code() {
		return apply_filters( 'gb_amazon_ec_currency_code', self::$currency_code );
	}

	public static function set_token( $token ) {
		global $blog_id;
		update_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, $token );
	}

	public static function unset_token() {
		global $blog_id;
		delete_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY );
	}

	public static function get_token() {
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, TRUE );
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase, $threeD_pass = FALSE ) {
		$this->send_offsite( $checkout );
	}

	/** Singleton Pattern */

	protected static $instance;

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
} Group_Buying_Amazon_Gateway::register();
