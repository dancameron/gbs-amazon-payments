<?php
/**
 * Sets up the amazon payments gateway for GBS
 */
class Group_Buying_Amazon_Gateway extends Group_Buying_Credit_Card_Processors  {

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

	// TODO: Dan should fill these in
	const API_CC_OPTION = "";
	const PAYMENT_METHOD_OPTION = "";

	// Used by amazon to reference the incoming request
	const CALLER_REFERENCE_PREFIX = "gbs_";

	private $api_mode = self::API_MODE_SANDBOX;
	private static $authorized = FALSE;

	private $aws_username;
	private $aws_secret_key;
	private $currency_code;

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

	public function __construct() {
		parent::__construct();

		$this->aws_username = get_option( self::API_AWS_USERNAME_OPTION, '' );
		$this->aws_secret_key = get_option( self::API_AWS_SECRET_KEY_OPTION, '' );
		$this->api_mode = get_option( self::API_MODE_OPTION, self::API_MODE_SANDBOX );
		$this->currency_code = get_option(self::API_CC_OPTION, 'USD');

		// TODO: Dan should add construct actions/filters here so I don't break something and explode customers' servers
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Amazon' ) );
	}

	private function authorize_payment( $auth_data = array() ) {
		if ( self::$authorized ) return TRUE;

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

		// TODO: Update to MultiUse
		$pipeline = new Amazon_FPS_CBUISingleUsePipeline($this->aws_username, $this->aws_secret_key);
		$pipeline->setMandatoryParameters(
			$auth_data['caller_reference'],
			$auth_data['return_url'],
			$auth_data['total']
		);

		// TODO: setup an endpoint URL with router for calling process_payment

		// Add parameters to be displayed on the CBUI
		$cc = $auth_data['currency_code'];
		if ( isset($cc) )
			$pipeline->addParameter('currencyCode', $auth_data['currency_code']);

		$pr = $auth_data['payment_reason'];
		if ( isset($pr) )
			$pipeline->addParameter('paymentReason', $auth_data['payment_reason']);

		$sh_l = $auth_data['shipping_local'];
		if ( isset($sh_l) )
			$pipeline->addParameter('addressName', $auth_data['shipping_local']);

		$sh_t = $auth_data['shipping'];
		if ( isset($sh_t) )
			$pipeline->addParameter('shipping', $auth_data['shipping']);

		$su_t = $auth_data['subtotal'];
		if ( isset($su_t) )
			$pipeline->addParameter('itemTotal', $auth_data['subtotal']);

		$ta_t = $auth_data['tax'];
		if ( isset($ta_t) )
			$pipeline->addParameter('tax', $auth_data['tax']);

		// TODO: No idea if this causes problems with WP_Router
		wp_redirect( $pipeline->getURL() );
		exit;
	}

	/**
	 * General a signature to be used on payment
	 */
	public function generate_signature() {

	}

	/**
	 * Capture a payment for processing later
	 *
	 * @param $amount
	 * @param $payment_title
	 * @param $payment_reason
	 * @param int $time
	 */
	public function capture_payment( $amount, $payment_title, $payment_reason, $time = 0 ) {
		if ( $time === 0 ) $time = time();
		if ( $amount <= 0 ) return false;
		$caller_reference = "";
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase, $threeD_pass = FALSE ) {

	}

	private function get_api_cc_option() {
		// TODO: Dan
	}

	public function get_payment_method() {
		// TODO: Dan
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
