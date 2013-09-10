<?php
/**
 * Amazon FPS offsite payment processor.
 *
 * @package GBS
 * @subpackage Payment Processing_Processor
 */
class Group_Buying_Amazon_FPS extends Group_Buying_Offsite_Processors  {
	const DEBUG = GBS_DEV;
	const PAYMENT_METHOD = 'Amazon Payments';

	// Endpoints
	const PROD_ENDPOINT_URL = "https://fps.amazonaws.com";
	const SDBX_ENDPOINT_URL = "https://fps.sandbox.amazonaws.com";
	const CBUI_PROD_ENDPOINT_URL = "https://authorize.payments.amazon.com/cobranded-ui/actions/start";
	const CBUI_SDBX_ENDPOINT_URL = "https://authorize.payments-sandbox.amazon.com/cobranded-ui/actions/start";

	const API_MODE_OPTION = "gb_amazon_mode";
	const API_MODE_SANDBOX = 'sandbox';
	const API_MODE_PRODUCTION = 'production';

	// API config data
	const API_ACCESS_KEY_OPTION = 'gb_amazon_aws_access_key';
	const API_AWS_SECRET_KEY_OPTION = "gb_amazon_aws_secret_key";
	const API_CURRENCY_CODE_OPTION = 'gb_amazon_fps_currency_code';

	// Used by amazon to reference the incoming request
	const CALLER_REFERENCE_PREFIX = "gbs_";

	const TOKEN_KEY = 'gb_amazon_fps_token';
	const REFERENCE_KEY = 'gb_amazon_fps_reference';

	protected static $api_mode = self::API_MODE_SANDBOX;

	private $aws_secret_key = '';
	private $currency_code;

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	public static function returned_from_offsite() {
		if ( isset( $_GET['tokenID'] ) ) {
			// validate that we have a valid token
			$valid = TRUE;
			$ref = explode('_', $_GET['callerReference']);
			if ( count($ref) != 3 || $ref[0] != rtrim(self::CALLER_REFERENCE_PREFIX, '_') || $ref[1] != get_current_user_id() ) {
				$valid = FALSE;
			}

			if ( !self::DEBUG ) { // this will never validate for local dev, as it requires a valid domain for the return URL
				require_once('Amazon/IpnReturnUrlValidation/SignatureUtilsForOutbound.php');
				$validator = new Amazon_FPS_SignatureUtilsForOutbound();
				try{
					// validateRequest should either return TRUE or throw an exception
					if ( !$validator->validateRequest($_GET, self::get_return_url(), 'GET') ) {
						$valid = FALSE;
					}
				} catch ( Amazon_FPS_SignatureException $e ) {
					$valid = FALSE;
				}
			}

			if ( !$valid ) {
				self::set_message(self::__('We were unable to validate your request. Please try again.'), 'error');
			}

			return $valid;
		} else {
			return FALSE;
		}
	}

	public function __construct() {
		parent::__construct();

		$this->aws_access_key = get_option( self::API_ACCESS_KEY_OPTION, '' );
		$this->aws_secret_key = get_option( self::API_AWS_SECRET_KEY_OPTION, '' );
		$this->currency_code = get_option( self::API_CURRENCY_CODE_OPTION, 'USD' );
		self::$api_mode = get_option( self::API_MODE_OPTION, self::API_MODE_SANDBOX );

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );

		add_filter( 'gb_checkout_payment_controls', array( $this, 'payment_controls' ), 20, 2 );

		add_action( 'gb_send_offsite_for_payment', array( $this, 'send_offsite' ), 10, 1 );
		add_action( 'gb_load_cart', array( $this, 'back_from_amazon' ), 10, 0 );
		
		add_action( 'purchase_completed', array( $this, 'complete_purchase' ), 10, 1 );

		add_action( self::CRON_HOOK, array( $this, 'request_status_updates' ), 10, 0 );
	}

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

			require_once('GBS_Amazon_FPS_CBUISingleUsePipeline.php');
			$pipeline = new GBS_Amazon_FPS_CBUISingleUsePipeline($this->aws_access_key, $this->aws_secret_key);
			if ( self::$api_mode == self::API_MODE_SANDBOX ) {
				$pipeline->set_sandbox(TRUE);
			}
			// setup authorization
			if ( !$this->setup_pipeline( $pipeline, $checkout ) ) {
				return;
			}

			$cbui_url = $pipeline->getURL();

			if ( !empty( $cbui_url ) ) {
				wp_redirect ( $cbui_url );
				exit();
			} else { // If an error occurred, with $url than redirect back to the checkout page and provide a message
				self::set_message( "An error occurred connecting to Amazon. Please try again." );
				wp_redirect( Group_Buying_Checkouts::get_url(), 303 );
				exit();
			}
		}
	}

	/**
	 * We're on the checkout page, just back from PayPal.
	 * Store the token and payer ID that PayPal gives us
	 *
	 * "The URI contains not only the endpoint that you specified in returnURL, but also a reference to the payment token, such as a tokenId, and the status of the authorization."
	 *
	 *
	 * @return void
	 */
	public function back_from_amazon() {
		if ( self::returned_from_offsite() ) {
			self::set_token( urldecode( $_GET['tokenID'] ) );
			self::set_reference( $_GET['callerReference'] );
			// let the checkout know that this isn't a fresh start
			// Note: This is where the magic happens so that GBS doesn't restart checkout 
			// and knows to land the user on the payment review page and
			// the process_payment is then fired after the customer lands on the payment review page.
			$_REQUEST['gb_checkout_action'] = 'back_from_amazon';
		} elseif ( !isset( $_REQUEST['gb_checkout_action'] ) ) {
			// this is a new checkout. clear the token so we don't give things away for free
			self::unset_token();
			self::unset_reference();
		}
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total( self::get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		self::load_fps_library();

		$aws_client_config = array();
		if ( self::$api_mode = self::API_MODE_SANDBOX ) {
			$aws_client_config['ServiceURL'] = self::SDBX_ENDPOINT_URL;
		}
		$client = new Amazon_FPS_Client( $this->aws_access_key, $this->aws_secret_key, $aws_client_config );
		$request = new Amazon_FPS_Model_PayRequest();
		$request->setSenderTokenId( self::get_token() );
		$request->setCallerReference( self::get_reference() );
		$amount = new Amazon_FPS_Model_Amount();
		$amount->setCurrencyCode( $this->get_currency_code() );
		$amount->setValue( $purchase->get_total( self::get_payment_method() ) );
		$request->setTransactionAmount($amount);
		$response = $client->pay( $request );

		/** @var Amazon_FPS_Model_PayResult $result */
		$result = $response->getPayResult();
		$transaction_id = $result->getTransactionId();
		$status = $result->getTransactionStatus();
		if ( !in_array( $status, array('Pending', 'Success') ) ) {
			self::set_message(self::__('Unable to complete your transaction with Amazon Payments.'), 'error');
			return FALSE;
		}

		// create loop of deals for the payment post
		$deal_info = array();
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][self::get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		$shipping_address = array();
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		// create new payment
		$payment_id = Group_Buying_Payment::new_payment( array(
			'payment_method' => self::get_payment_method(),
			'purchase' => $purchase->get_id(),
			'amount' => $amount->getValue(),
			'data' => array(
				'api_response' => $response->toXML(),
			),
			'transaction_id' => $transaction_id,
			'deals' => $deal_info,
			'shipping_address' => $shipping_address,
		), Group_Buying_Payment::STATUS_PENDING ); // Credit card transactions complete asynchronously. We'll update status on a cron
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );
		self::unset_token();
		self::unset_reference();

		return $payment;
	}

	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$this->request_payment_status_update($payment_id);
		}
	}

	private function setup_pipeline( Amazon_FPS_CBUIPipeline $pipeline, Group_Buying_Checkouts $checkout ) {
		$cart = $checkout->get_cart();

		$filtered_total = $this->get_payment_request_total( $checkout );
		if ( $filtered_total < 0.01 ) {
			return FALSE;
		}

		$pipeline->addParameter('currencyCode', $this->get_currency_code());
		$pipeline->addParameter('returnURL', self::get_return_url());

		if ( isset( $checkout->cache['shipping'] ) ) {
			$cache = $checkout->cache['shipping'];
			$pipeline->addParameter('addressLine1', $cache['street']);
			$pipeline->addParameter('city', $cache['city']);
			$pipeline->addParameter('state', $cache['zone']);
			$pipeline->addParameter('country', $cache['country']);
			$pipeline->addParameter('zip', $cache['postal_code']);
		}

		$pipeline->addParameter('callerReference', self::CALLER_REFERENCE_PREFIX.get_current_user_id().'_'.time()); // $user_id:$timestamp

		// If there's only one item, the payment reason should be the deal title, otherwise just the site title
		if ( $cart->item_count() == 1 ) {
			$items = $cart->get_items();
			$item = reset($items);
			$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
			$order_summary = html_entity_decode( strip_tags( $deal->get_title( $item['data'] ) ), ENT_QUOTES, 'UTF-8' );
		} else {
			$order_summary = get_bloginfo('name');
		}
		$pipeline->addParameter('paymentReason', $order_summary);

		$pipeline->addParameter( 'transactionAmount', gb_get_number_format( $filtered_total ) );
		$pipeline->addParameter( 'itemTotal', gb_get_number_format( $cart->get_subtotal() ) );
		$pipeline->addParameter( 'shipping', gb_get_number_format( $cart->get_shipping_total() ) );
		$pipeline->addParameter( 'tax', gb_get_number_format( $cart->get_tax_total() ) );
		// TODO: Do we need a discount param if $filtered_total < $cart->get_total() ?

		do_action( 'gb_amazon_fps_pipeline', $pipeline, $checkout );
		return TRUE;
	}

	private function get_currency_code() {
		return apply_filters( 'gb_amazon_fps_currency_code', $this->currency_code );
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_amazon_settings';
		add_settings_section( $section, self::__( 'Amazon Payments' ), array( $this, 'display_settings_section' ), $page );

		register_setting( $page, self::API_ACCESS_KEY_OPTION );
		register_setting( $page, self::API_AWS_SECRET_KEY_OPTION );
		register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::API_CURRENCY_CODE_OPTION );

		add_settings_field( self::API_ACCESS_KEY_OPTION, self::__( 'AWS Access Key' ), array( $this, 'display_api_access_key_field' ), $page, $section );
		add_settings_field( self::API_AWS_SECRET_KEY_OPTION, self::__( 'AWS Secret Key' ), array( $this, 'display_api_secret_key_field' ), $page, $section );
		add_settings_field( self::API_CURRENCY_CODE_OPTION, self::__( 'Currency Code' ), array( $this, 'display_currency_code_field' ), $page, $section );
		add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
 	}

	public function display_api_access_key_field() {
		echo '<input type="text" name="'.self::API_ACCESS_KEY_OPTION.'" value="'.$this->aws_access_key.'" size="80" />';
	}

	public function display_api_secret_key_field() {
		echo '<input type="text" name="'.self::API_AWS_SECRET_KEY_OPTION.'" value="'.$this->aws_secret_key.'" size="80" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::API_MODE_PRODUCTION.'" '.checked( self::API_MODE_PRODUCTION, self::$api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::API_MODE_SANDBOX.'" '.checked( self::API_MODE_SANDBOX, self::$api_mode, FALSE ).'/> '.self::__( 'Sandbox' ).'</label>';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::API_CURRENCY_CODE_OPTION.'" value="'.$this->currency_code.'" size="10" />';
		echo ' <span class="description">'.gb__( 'e.g., "USD"' ).'</span>';
	}

	/**
	 * Request status updates from amazon for any payments that are still pending
	 *
	 * @return void
	 */
	public function request_status_updates() {
		$payment_ids = $this->get_incomplete_payments();
		foreach ( $payment_ids as $pid ) {
			$this->request_payment_status_update($pid);
		}
	}

	private function request_payment_status_update( $payment_id ) {
		$payment = Group_Buying_Payment::get_instance($payment_id);
		self::load_fps_library();
		$aws_client_config = array();
		if ( self::$api_mode = self::API_MODE_SANDBOX ) {
			$aws_client_config['ServiceURL'] = self::SDBX_ENDPOINT_URL;
		}
		$client = new Amazon_FPS_Client( $this->aws_access_key, $this->aws_secret_key, $aws_client_config );
		$request = new Amazon_FPS_Model_GetTransactionStatusRequest();
		$request->setTransactionId($payment->get_transaction_id());
		try {
			$response = $client->getTransactionStatus( $request );

			/** @var Amazon_FPS_Model_PayResult $result */
			$result = $response->getGetTransactionStatusResult();
			$status = $result->getTransactionStatus();
			$this->handle_status_update($payment, $status);
		} catch ( Amazon_FPS_Exception $e ) {
			// nothing to do here
		}
	}

	/**
	 * @return array IDs of payments that need status updates, with newest first
	 */
	private function get_incomplete_payments() {
		$args = array(
			'post_type' => Group_Buying_Payment::POST_TYPE,
			'post_status' => Group_Buying_Payment::STATUS_PENDING,
			'posts_per_page' => -1,
			'fields' => 'ids',
			'gb_bypass_filter' => TRUE,
			'suppress_filters' => TRUE,
		);
		$args['meta_query'] = array(
			array(
				'key' => '_payment_method',
				'value' => $this->get_payment_method(),
			),
		);
		$posts = get_posts($args);
		return $posts;
	}

	/**
	 * @param Group_Buying_Payment|string $payment The Payment object, or the transaction ID associated with it
	 * @param string $status
	 *
	 * @return void
	 */
	private function handle_status_update( $payment, $status ) {
		$status = strtoupper($status);
		if ( !is_object($payment) ) {
			$payment = $this->get_payment_by_transaction_id( $payment );
		}
		if ( empty($payment) ) {
			return; // can't find a matching payment
		}

		if ( $status == 'FAILURE' || $status == 'CANCELLED' ) {
			$payment->set_status(Group_Buying_Payment::STATUS_VOID);
			return;
		}

		if ( $status == 'SUCCESS' ) {
			$deals = $payment->get_deals();
			$deal_ids = array_keys($deals);
			do_action( 'payment_captured', $payment, $deal_ids );
			do_action( 'payment_complete', $payment );
			$payment->set_status(Group_Buying_Payment::STATUS_COMPLETE);
			return;
		}
	}

	private function get_payment_by_transaction_id( $tid ) {
		$payments = Group_Buying_Payment::find_by_meta( Group_Buying_Payment::POST_TYPE, array('_trans_id' => $tid));
		if ( empty($payments) ) {
			return NULL;
		}
		$payment = Group_Buying_Payment::get_instance(reset($payments));
		return $payment;
	}

	public static function set_token( $token ) {
		update_user_option( get_current_user_id(), self::TOKEN_KEY, $token );
	}

	public static function unset_token() {
		delete_user_option( get_current_user_id(), self::TOKEN_KEY );
	}

	public static function get_token() {
		return get_user_option( self::TOKEN_KEY, get_current_user_id() );
	}

	public static function set_reference( $reference ) {
		update_user_option( get_current_user_id(), self::REFERENCE_KEY, $reference );
	}

	public static function unset_reference() {
		delete_user_option( get_current_user_id(), self::REFERENCE_KEY );
	}

	public static function get_reference() {
		return get_user_option( self::REFERENCE_KEY, get_current_user_id() );
	}

	/** Singleton Pattern */

	protected static $instance;

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private static function get_return_url() {
		return Group_Buying_Checkouts::get_url();
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Amazon' ) );
	}

	private static function load_fps_library() {
		static $loaded = FALSE;
		if ( !$loaded ) {
			set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));
			spl_autoload_register(array(__CLASS__, 'fps_autoloader'));
			$loaded = TRUE;
		}
	}

	public static function fps_autoloader( $className ) {
		if ( strpos($className, 'Amazon') !== 0 ) {
			return;
		}
		$filePath = str_replace('_', DIRECTORY_SEPARATOR, $className) . '.php';
		if ( file_exists(dirname(__FILE__).DIRECTORY_SEPARATOR.$filePath) ) {
			include_once($filePath);
			return;
		}
	}
}
