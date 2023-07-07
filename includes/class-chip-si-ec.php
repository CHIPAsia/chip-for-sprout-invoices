<?php

/**
 * CHIP offsite payment processor.
 *
 * These actions are fired for each checkout page.
 *
 * Payment page - 'si_checkout_action_'.SI_Checkouts::PAYMENT_PAGE
 * Review page - 'si_checkout_action_'.SI_Checkouts::REVIEW_PAGE
 * Confirmation page - 'si_checkout_action_'.SI_Checkouts::CONFIRMATION_PAGE
 *
 * Necessary methods:
 * get_instance -- duh
 * get_slug -- slug for the payment process
 * get_options -- used on the invoice payment dropdown
 * process_payment -- called when the checkout is complete before the confirmation page is shown. If a
 * payment fails than the user will be redirected back to the invoice.
 *
 * @package SI
 * @subpackage Payment Processing_Processor
 */
class SI_Chip_EC extends SI_Offsite_Processors {
  const PAYMENT_METHOD = 'CHIP';
  const PAYMENT_SLUG = 'chip';
  const API_BRAND_ID_OPTION = 'si_chip_brand_id';
  const API_SECRET_KEY_OPTION = 'si_chip_secret_key';
  const CURRENCY_CODE_OPTION = 'si_chip_currency';
  const CANCEL_URL_OPTION = 'si_chip_cancel_url';
  const TOKEN_KEY = 'si_chip_token_key'; // Combine with $blog_id to get the actual meta key

  protected static $instance;
  private static $currency_code = 'MYR';
  private static $api_brand_id;
  private static $api_secret_key;
  private static $cancel_url = '';

  public static function get_instance() {
    if ( ! ( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public function get_payment_method() {
    return self::PAYMENT_METHOD;
  }

  public function get_slug() {
    return self::PAYMENT_SLUG;
  }

  public static function register() {
    self::add_payment_processor( __CLASS__, __( 'CHIP', 'chip-for-sprout-invoices' ) );
  }

  public static function public_name() {
    return __( 'CHIP', 'chip-for-sprout-invoices' );
  }

  public static function checkout_options() {
    $option = array(
      'icons' => array( SI_URL . '/resources/front-end/img/paypal.png' ),
      'label' => __( 'CHIP', 'chip-for-sprout-invoices' ),
      'cc' => array(),
      );
    return apply_filters( 'si_chip_ec_checkout_options', $option );
  }

  protected function __construct() {
    parent::__construct();

    self::$api_brand_id = get_option( self::API_BRAND_ID_OPTION, '' );
    self::$api_secret_key = get_option( self::API_SECRET_KEY_OPTION, '' );
    self::$currency_code = get_option( self::CURRENCY_CODE_OPTION, 'MYR' );
    self::$cancel_url = get_option( self::CANCEL_URL_OPTION, add_query_arg( array( 'cancelled_chip_payment' => 1 ), home_url( '/' ) ) );

    add_action( 'si_checkout_action_'.SI_Checkouts::PAYMENT_PAGE, array( $this, 'send_offsite' ), 10, 1 );
    add_action( 'si_checkout_action_'.SI_Checkouts::REVIEW_PAGE, array( $this, 'back_from_chip' ), 10, 1 );
    add_action( 'checkout_completed', array( $this, 'post_checkout_redirect' ), 10, 2 );

    // Add Recurring button
    add_action( 'recurring_payments_profile_info', array( __CLASS__, 'chip_profile_link' ) );
  }

  /**
   * Hooked on init add the settings page and options.
   *
   */
  public static function register_settings( $settings = array() ) {
    // Settings
    $settings['payments'] = array(
      'si_chip_settings' => array(
      'title' => __( 'CHIP', 'chip-for-sprout-invoices' ),
        'weight' => 200,
        'settings' => array(
          self::API_BRAND_ID_OPTION => array(
            'label' => __( 'Brand ID', 'chip-for-sprout-invoices' ),
            'option' => array(
              'type' => 'text',
              'default' => self::$api_brand_id,
              ),
            ),
          self::API_SECRET_KEY_OPTION => array(
            'label' => __( 'Secret Key', 'chip-for-sprout-invoices' ),
            'option' => array(
              'type' => 'text',
              'default' => self::$api_secret_key,
              ),
            ),
          self::CURRENCY_CODE_OPTION => array(
            'label' => __( 'Currency Code', 'chip-for-sprout-invoices' ),
            'option' => array(
              'type' => 'text',
              'default' => self::$currency_code,
              'attributes' => array( 'class' => 'small-text' ),
              ),
            ),
          self::CANCEL_URL_OPTION => array(
            'label' => __( 'Cancel URL', 'chip-for-sprout-invoices' ),
            'option' => array(
              'type' => 'text',
              'default' => self::$cancel_url,
              ),
            ),
          ),
        ),
      );
    return $settings;
  }

  /**
   * Instead of redirecting to the SIcheckout page,
   * set up the Express Checkout transaction and redirect there
   *
   * @param SI_Carts $cart
   * @return void
   */
  public function send_offsite( SI_Checkouts $checkout ) {
    // Check to see if the payment processor being used is for this payment processor
    if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) { // FUTURE have parent class handle this smarter'r
      return;
    }

    // No form to validate
    remove_action( 'si_checkout_action_'.SI_Checkouts::PAYMENT_PAGE, array( $checkout, 'process_payment_page' ) );

    if ( ! isset( $_GET['token'] ) && $_REQUEST[ SI_Checkouts::CHECKOUT_ACTION ] == SI_Checkouts::PAYMENT_PAGE ) {

      $invoice = $checkout->get_invoice();

      $post_data = $this->set_array_data( $checkout );
      if ( ! $post_data ) {
        return; // paying for it some other way
      }

      do_action( 'si_log', __CLASS__ . '::' . __FUNCTION__ . ' - Filtered post_data', $post_data );

      $chip = new Chip_Sprout_Invoice_API( self::$api_secret_key, self::$api_brand_id, false );

      $payment = $chip->create_payment( $post_data );

      do_action( 'si_log', __CLASS__ . '::' . __FUNCTION__ . ' - CHIP Response', $payment );

      if ( !array_key_exists( 'id', $payment ) ) {
        self::set_message( print_r( $payment, true ), self::MESSAGE_STATUS_ERROR );
        return false;
      }

      $checkout->mark_page_complete( SI_Checkouts::PAYMENT_PAGE );

      $invoice->save_post_meta(['_chip_purchase_id' => $payment['id']]);

      wp_redirect( $payment['checkout_url'] );
      exit();
    }
  }

  public function back_from_chip( SI_Checkouts $checkout ) {
    // Check to see if the payment processor being used is for this payment processor
    if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) { // FUTURE have parent class handle this smarter'r
      return; 
    }

    $checkout->mark_page_complete( SI_Checkouts::PAYMENT_PAGE );
    // Starting over.
    self::unset_token();
  }

  public function post_checkout_redirect( SI_Checkouts $checkout, SI_Payment $payment ) {
    if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) {
      return;
    }
    wp_redirect( $checkout->checkout_confirmation_url( self::PAYMENT_SLUG ) );
    exit();
  }

  private function set_array_data( SI_Checkouts $checkout ) {
    $invoice = $checkout->get_invoice();
    $client = $invoice->get_client();

    $user = si_who_is_paying( $invoice );
    // User email or none
    $user_email = ( $user ) ? $user->user_email : 'noreply@chip-in.asia' ;

    $payment_amount = ( si_has_invoice_deposit( $invoice->get_id() ) ) ? $invoice->get_deposit() : $invoice->get_balance();

    $callback_url = $checkout->checkout_complete_url( $this->get_slug() );

    $params = [
      'success_callback' => $callback_url,
      'success_redirect' => $callback_url,
      'failure_redirect' => $callback_url,
      'cancel_redirect'  => self::$cancel_url,
      'send_receipt'     => true,
      'creator_agent'    => 'SproutInv: ' . SA_ADDON_CHIP_VERSION,
      'reference'        => $invoice->get_id(),
      'platform'         => 'api', // sproutinvoice
      // 'due'              => $this->get_due_timestamp(),
      'purchase' => [
        'timezone'       => 'Asia/Kuala_Lumpur',
        'currency'       => self::get_currency_code( $invoice->get_id() ),
        // 'language'       => $this->get_language(),
        'products'       => [[
          'name'     => substr( 'test product name', 0, 256),
          'price'    => round( $payment_amount * 100),
        ]],
      ],
      'brand_id' => self::$api_brand_id,
      'client' => [
        'email'     => $user_email,
        'full_name' => 'test name',
      ],
    ];

    $params = apply_filters( 'si_chip_ec_set_array_data', $params );
    do_action( 'si_log', __CLASS__ . '::' . __FUNCTION__ . ' - CHIP EC SetCheckout Data', $params );
    return apply_filters( 'si_set_array_data', $params, $checkout );
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
    if ( isset( $_REQUEST['token'] ) && $_REQUEST['token'] ) {
      return $_REQUEST['token'];
    }
    global $blog_id;
    return get_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, true );
  }

  public static function set_payerid( $get_payerid ) {
    global $blog_id;
    update_user_meta( get_current_user_id(), $blog_id.'_'.self::PAYER_ID, $get_payerid );
  }

  public static function get_payerid() {
    if ( isset( $_REQUEST['PayerID'] ) && $_REQUEST['PayerID'] ) {
      return $_REQUEST['PayerID'];
    }
    global $blog_id;
    return get_user_meta( get_current_user_id(), $blog_id.'_'.self::PAYER_ID, true );
  }

  public function offsite_payment_complete() {
    if ( self::get_token() && self::get_payerid() ) {
      return true;
    }
    return false;
  }

  public function process_payment( SI_Checkouts $checkout, SI_Invoice $invoice ) {

    $chip = new Chip_Sprout_Invoice_API( self::$api_secret_key, self::$api_brand_id, false );

    $purchase_id = $invoice->get_post_meta('_chip_purchase_id');

    $payment = $chip->get_payment($purchase_id);

    // create new payment
    $payment_id = SI_Payment::new_payment( array(
      'payment_method' => $this->get_payment_method(),
      'invoice' => $invoice->get_id(),
      'amount' => $payment['payment']['amount'] / 100,
      'data' => array(
        'live' => false,
        'api_response' => $payment,
        'payment_token' => 'notoken',
      ),
    ), SI_Payment::STATUS_AUTHORIZED );

    if ( ! $payment_id ) {
      return false;
    }

    $payment = SI_Payment::get_instance( $payment_id );
    do_action( 'payment_authorized', $payment );

    // $this->maybe_create_recurring_payment_profiles( $invoice, $payment );

    self::unset_token();
    return $payment;
  }

  private function get_currency_code( $invoice_id ) {
    return apply_filters( 'si_currency_code', self::$currency_code, $invoice_id, self::PAYMENT_METHOD );
  }
}
SI_Chip_EC::register();
