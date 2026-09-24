<?php

/**
 * CHIP offsite payment processor for Sprout Invoices.
 *
 * Sprout Invoices fires these actions for each checkout page:
 *   payment page      - 'si_checkout_action_' . SI_Checkouts::PAYMENT_PAGE
 *   review page       - 'si_checkout_action_' . SI_Checkouts::REVIEW_PAGE
 *   confirmation page - 'si_checkout_action_' . SI_Checkouts::CONFIRMATION_PAGE
 *
 * Required processor methods: get_instance, get_slug, get_payment_method,
 * process_payment.
 *
 * @package ChipForSproutInvoices
 */

if ( ! defined( 'ABSPATH' ) ) {
	die; // Cannot access directly.
}

/**
 * CHIP offsite processor.
 */
class SI_Chip_EC extends SI_Offsite_Processors {

	const PAYMENT_METHOD = 'CHIP';
	const PAYMENT_SLUG   = 'chip';

	const API_BRAND_ID_OPTION      = 'si_chip_brand_id';
	const API_SECRET_KEY_OPTION    = 'si_chip_secret_key';
	const CURRENCY_CODE_OPTION     = 'si_chip_currency';
	const CANCEL_URL_OPTION        = 'si_chip_cancel_url';
	const DUE_STRICT_OPTION        = 'si_chip_due_strict';
	const DUE_STRICT_TIMING_OPTION = 'si_chip_due_strict_timing';
	const PAYMENT_METHOD_WHITELIST = 'si_chip_payment_method_whitelist';

	/**
	 * Invoice meta key holding the CHIP purchase ID.
	 */
	const PURCHASE_ID_META = '_chip_purchase_id';

	/**
	 * How long a request waits for the purchase lock before proceeding.
	 *
	 * Long enough for a callback to finish writing its payment, short enough
	 * that a stuck request cannot hold the checkout.
	 */
	const LOCK_TIMEOUT_SECONDS = 10;

	/**
	 * Instance.
	 *
	 * @var SI_Chip_EC|null
	 */
	protected static $instance;

	/**
	 * Gets the single instance.
	 *
	 * @return SI_Chip_EC
	 */
	public static function get_instance() {
		if ( ! ( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Payment method label stored on each payment record.
	 *
	 * @return string
	 */
	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	/**
	 * Checkout slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return self::PAYMENT_SLUG;
	}

	/**
	 * Registers the processor with Sprout Invoices.
	 *
	 * @return void
	 */
	public static function register() {
		self::add_payment_processor( __CLASS__, __( 'CHIP', 'chip-for-sprout-invoices' ) );
	}

	/**
	 * Public name.
	 *
	 * @return string
	 */
	public static function public_name() {
		return __( 'CHIP', 'chip-for-sprout-invoices' );
	}

	/**
	 * Options used on the invoice payment selector.
	 *
	 * @return array
	 */
	public static function checkout_options() {
		$option = array(
			'icons' => array( SA_ADDON_CHIP_URL . 'assets/logo.svg' ),
			'label' => __( 'CHIP', 'chip-for-sprout-invoices' ),
			'cc'    => array(),
		);

		return apply_filters( 'si_chip_ec_checkout_options', $option );
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		parent::__construct();

		add_action( 'si_checkout_action_' . SI_Checkouts::PAYMENT_PAGE, array( $this, 'send_offsite' ), 10, 1 );
		add_action( 'si_checkout_action_' . SI_Checkouts::REVIEW_PAGE, array( $this, 'back_from_chip' ), 10, 1 );
		add_action( 'checkout_completed', array( $this, 'post_checkout_redirect' ), 10, 2 );
		add_action( 'si_manually_capture_purchase', array( $this, 'manually_capture_purchase' ), 10 );
		add_action( 'si_payment_voided', array( __CLASS__, 'warn_on_void' ), 10 );

		add_filter( 'si_mngt_payments_columns', array( __CLASS__, 'register_payment_column' ) );
		add_filter( 'si_mngt_payments_column_chip_refund', array( __CLASS__, 'render_payment_column' ) );
	}

	/**
	 * Adds the CHIP column to the payments table.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function register_payment_column( $columns ) {
		$columns['chip_refund'] = __( 'CHIP', 'chip-for-sprout-invoices' );

		return $columns;
	}

	/**
	 * Renders the CHIP column, offering a refund for refundable payments.
	 *
	 * Sprout Invoices has no refund hook of its own: its admin row action only
	 * sets a local status and moves no money. Refunding at the gateway
	 * therefore needs its own explicit control, which this column provides.
	 *
	 * @param WP_Post $item Payment post.
	 * @return string
	 */
	public static function render_payment_column( $item ) {
		if ( ! current_user_can( 'manage_sprout_invoices_payments' ) ) {
			return '';
		}

		$payment = SI_Payment::get_instance( $item->ID );

		if ( ! is_a( $payment, 'SI_Payment' ) ) {
			return '';
		}

		$purchase_id = $payment->get_post_meta( self::PURCHASE_ID_META );

		if ( ! $purchase_id ) {
			return '<span class="description">' . esc_html__( 'No gateway record.', 'chip-for-sprout-invoices' ) . '</span>';
		}

		if ( SI_Payment::STATUS_COMPLETE !== $payment->get_status() ) {
			return '<span class="description">' . esc_html__( 'Not refundable.', 'chip-for-sprout-invoices' ) . '</span>';
		}

		return sprintf(
			'<a href="#" class="si_chip_refund" data-payment-id="%1$s" data-nonce="%2$s">%3$s</a>',
			esc_attr( $payment->get_id() ),
			esc_attr( wp_create_nonce( Chip_Sprout_Invoices_Refund::NONCE ) ),
			esc_html(
				sprintf(
					/* translators: %s: payment amount */
					__( 'Refund %s', 'chip-for-sprout-invoices' ),
					sa_get_formatted_money( $payment->get_amount() )
				)
			)
		);
	}

	/**
	 * Registers the settings page and options.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function register_settings( $settings = array() ) {
		$whitelist = get_option( self::PAYMENT_METHOD_WHITELIST, array() );
		if ( ! is_array( $whitelist ) ) {
			$whitelist = array();
		}

		$settings['payments'] = array(
			'si_chip_settings' => array(
				'title'       => __( 'CHIP', 'chip-for-sprout-invoices' ),
				'weight'      => 200,
				'description' => sprintf(
					/* translators: %s: URL of the CHIP merchant portal */
					__( 'Accept payments through CHIP. Brand ID and Secret Key are found in your <a href="%s" target="_blank" rel="noopener noreferrer">CHIP merchant dashboard</a>.', 'chip-for-sprout-invoices' ),
					'https://portal.chip-in.asia/collect/developers'
				),
				'settings'    => array(
					self::API_BRAND_ID_OPTION               => array(
						'label'  => __( 'Brand ID', 'chip-for-sprout-invoices' ),
						'option' => array(
							'type'        => 'text',
							'default'     => get_option( self::API_BRAND_ID_OPTION, '' ),
							'description' => __( 'CHIP Brand ID (a UUID).', 'chip-for-sprout-invoices' ),
						),
					),
					self::API_SECRET_KEY_OPTION             => array(
						'label'  => __( 'Secret Key', 'chip-for-sprout-invoices' ),
						'option' => array(
							'type'        => 'text',
							'default'     => get_option( self::API_SECRET_KEY_OPTION, '' ),
							'description' => __( 'Server-side only. Never share this key.', 'chip-for-sprout-invoices' ),
						),
					),
					self::CURRENCY_CODE_OPTION              => array(
						'label'  => __( 'Currency Code', 'chip-for-sprout-invoices' ),
						'option' => array(
							'type'        => 'text',
							'default'     => get_option( self::CURRENCY_CODE_OPTION, 'MYR' ),
							'attributes'  => array( 'class' => 'small-text' ),
							'description' => __( 'CHIP supports MYR only.', 'chip-for-sprout-invoices' ),
						),
					),
					self::CANCEL_URL_OPTION                 => array(
						'label'  => __( 'Cancel URL', 'chip-for-sprout-invoices' ),
						'option' => array(
							'type'        => 'text',
							'default'     => get_option( self::CANCEL_URL_OPTION, '' ),
							'description' => __( 'Where a customer who cancels on the CHIP page is sent. Defaults to the invoice.', 'chip-for-sprout-invoices' ),
						),
					),
					self::DUE_STRICT_OPTION                 => array(
						'label'  => __( 'Due Strict', 'chip-for-sprout-invoices' ),
						'option' => array(
							'type'        => 'checkbox',
							'default'     => get_option( self::DUE_STRICT_OPTION, '' ),
							'value'       => 'yes',
							'description' => __( 'Block payment once the Due Strict Timing below has passed.', 'chip-for-sprout-invoices' ),
						),
					),
					self::DUE_STRICT_TIMING_OPTION          => array(
						'label'  => __( 'Due Strict Timing (minutes)', 'chip-for-sprout-invoices' ),
						'option' => array(
							'type'        => 'text',
							'default'     => get_option( self::DUE_STRICT_TIMING_OPTION, '' ),
							'attributes'  => array( 'class' => 'small-text' ),
							'description' => __( 'Leave empty to keep invoices payable indefinitely.', 'chip-for-sprout-invoices' ),
						),
					),
					self::PAYMENT_METHOD_WHITELIST          => array(
						'label'  => __( 'Payment Method Whitelist', 'chip-for-sprout-invoices' ),
						'option' => array(
							'type'        => 'select',
							'options'     => self::get_whitelist_options(),
							'default'     => array_values( $whitelist ),
							'attributes'  => array( 'multiple' => 'multiple', 'size' => 8 ),
							'description' => __( 'Restrict which payment methods are offered. Leave empty to offer everything the brand supports.', 'chip-for-sprout-invoices' ),
						),
					),
					Chip_Sprout_Invoices_Helper::LOG_OPTION  => array(
						'label'  => __( 'Save Logs', 'chip-for-sprout-invoices' ),
						'option' => array(
							'type'        => 'checkbox',
							'default'     => Chip_Sprout_Invoices_Helper::is_logging_enabled(),
							'value'       => 'yes',
							'description' => __( 'Record gateway requests and callbacks under Tools &rarr; Sprout Invoices records. Troubleshooting only.', 'chip-for-sprout-invoices' ),
						),
					),
				),
			),
		);

		return $settings;
	}

	/**
	 * The selectable payment method identifiers.
	 *
	 * DuitNow QR and ShopeePay appear once each even though CHIP has a legacy
	 * and a modern identifier for both: sending a group's members together
	 * makes CHIP drop the method, so exactly one is resolved per group at
	 * checkout time.
	 *
	 * @return array
	 */
	public static function get_whitelist_options() {
		return array(
			'fpx'             => __( 'FPX B2C', 'chip-for-sprout-invoices' ),
			'fpx_b2b1'        => __( 'FPX B2B1', 'chip-for-sprout-invoices' ),
			'visa'            => __( 'Visa', 'chip-for-sprout-invoices' ),
			'mastercard'      => __( 'Mastercard', 'chip-for-sprout-invoices' ),
			'maestro'         => __( 'Maestro', 'chip-for-sprout-invoices' ),
			'duitnow_qr'      => __( 'DuitNow QR', 'chip-for-sprout-invoices' ),
			'shopee_pay'      => __( 'ShopeePay', 'chip-for-sprout-invoices' ),
			'razer_atome'     => __( 'Atome', 'chip-for-sprout-invoices' ),
			'razer_grabpay'   => __( 'GrabPay', 'chip-for-sprout-invoices' ),
			'razer_maybankqr' => __( 'Maybank QR', 'chip-for-sprout-invoices' ),
			'razer_tng'       => __( 'Touch & Go eWallet', 'chip-for-sprout-invoices' ),
			'crypto_coin'     => __( 'Crypto Coin', 'chip-for-sprout-invoices' ),
		);
	}

	/**
	 * Creates the purchase and sends the customer to CHIP.
	 *
	 * Runs on the checkout payment page.
	 *
	 * @param SI_Checkouts $checkout Checkout.
	 * @return void
	 */
	public function send_offsite( SI_Checkouts $checkout ) {
		if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) {
			return;
		}

		// There is no form on the payment page to validate.
		remove_action( 'si_checkout_action_' . SI_Checkouts::PAYMENT_PAGE, array( $checkout, 'process_payment_page' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check; the checkout nonce is verified by SI_Checkouts::handle_action() before this action fires.
		if ( isset( $_GET['token'] ) || ! isset( $_REQUEST[ SI_Checkouts::CHECKOUT_ACTION ] ) ) {
			return;
		}

		if ( SI_Checkouts::PAYMENT_PAGE !== sanitize_text_field( wp_unslash( $_REQUEST[ SI_Checkouts::CHECKOUT_ACTION ] ) ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$invoice = $checkout->get_invoice();

		if ( ! is_a( $invoice, 'SI_Invoice' ) ) {
			return;
		}

		$params = $this->build_purchase_params( $checkout, $invoice );

		if ( empty( $params ) ) {
			return;
		}

		$api      = self::api();
		$purchase = $api->create_payment( $params );

		if ( ! is_array( $purchase ) || empty( $purchase['id'] ) || empty( $purchase['checkout_url'] ) ) {
			$message = $api->get_last_error();

			Chip_Sprout_Invoices_Helper::log(
				'failed to create purchase',
				array(
					'invoice_id' => $invoice->get_id(),
					'error'      => $message,
					'params'     => $params,
				)
			);

			self::set_message(
				sprintf(
					/* translators: %s: error message returned by the payment gateway */
					__( 'Unable to start the payment: %s', 'chip-for-sprout-invoices' ),
					$message
				),
				self::MESSAGE_STATUS_ERROR
			);

			return;
		}

		// Link invoice -> purchase so every later step (callback, redirect,
		// refund) resolves the pair server-side instead of trusting a request.
		$invoice->save_post_meta( array( self::PURCHASE_ID_META => sanitize_text_field( (string) $purchase['id'] ) ) );

		$checkout->mark_page_complete( SI_Checkouts::PAYMENT_PAGE );

		Chip_Sprout_Invoices_Helper::log(
			'purchase created',
			array(
				'invoice_id' => $invoice->get_id(),
				'payment_id' => $purchase['id'],
				'is_test'    => isset( $purchase['is_test'] ) ? $purchase['is_test'] : null,
				'total_sen'  => isset( $purchase['purchase']['total'] ) ? $purchase['purchase']['total'] : null,
			)
		);

		// The URL comes from the gateway over HTTPS, never from user input.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( esc_url_raw( apply_filters( 'si_chip_checkout_url', $purchase['checkout_url'], $purchase, $invoice ) ) );
		exit;
	}

	/**
	 * Marks the payment page complete when the customer returns from CHIP.
	 *
	 * With the payment page marked complete, Sprout Invoices advances to the
	 * review page and then runs `process_payment()` on the confirmation page.
	 *
	 * @param SI_Checkouts $checkout Checkout.
	 * @return void
	 */
	public function back_from_chip( SI_Checkouts $checkout ) {
		if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) {
			return;
		}

		$checkout->mark_page_complete( SI_Checkouts::PAYMENT_PAGE );
	}

	/**
	 * Redirects to the confirmation page once the checkout completes.
	 *
	 * @param SI_Checkouts $checkout Checkout.
	 * @param SI_Payment   $payment  Payment record.
	 * @return void
	 */
	public function post_checkout_redirect( SI_Checkouts $checkout, SI_Payment $payment ) {
		if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) {
			return;
		}

		wp_safe_redirect( $checkout->checkout_confirmation_url( self::PAYMENT_SLUG ) );
		exit;
	}

	/**
	 * Builds the CHIP create-purchase body.
	 *
	 * Field placement is deliberate: `success_callback`, `success_redirect`,
	 * `failure_redirect`, `cancel_redirect`, `reference`, `creator_agent`,
	 * `platform`, `due`, `send_receipt`, `brand_id` and
	 * `payment_method_whitelist` are TOP-LEVEL siblings of `client` and
	 * `purchase`. Nested inside `purchase` the gateway still answers HTTP 201
	 * but silently stores empty strings, so the customer is never redirected
	 * back and no callback ever fires.
	 *
	 * @param SI_Checkouts $checkout Checkout.
	 * @param SI_Invoice   $invoice  Invoice.
	 * @return array Empty array when the payment must not be started.
	 */
	private function build_purchase_params( SI_Checkouts $checkout, SI_Invoice $invoice ) {
		$currency = $this->get_currency_code( $invoice );

		if ( 'MYR' !== $currency ) {
			Chip_Sprout_Invoices_Helper::log(
				'unsupported currency, payment not started',
				array(
					'invoice_id' => $invoice->get_id(),
					'currency'   => $currency,
				)
			);

			self::set_message(
				sprintf(
					/* translators: %s: currency code */
					__( 'CHIP supports MYR only. This invoice is in %s.', 'chip-for-sprout-invoices' ),
					$currency
				),
				self::MESSAGE_STATUS_ERROR
			);

			return array();
		}

		$amount = si_has_invoice_deposit( $invoice->get_id() )
			? (float) $invoice->get_deposit()
			: (float) $invoice->get_balance();

		$amount_in_sen = Chip_Sprout_Invoices_Helper::to_minor_units( $amount );

		if ( $amount_in_sen < 1 ) {
			self::set_message(
				__( 'There is nothing left to pay on this invoice.', 'chip-for-sprout-invoices' ),
				self::MESSAGE_STATUS_ERROR
			);

			return array();
		}

		$params = array(
			'success_callback' => Chip_Sprout_Invoices_Listener::get_callback_url(),
			'success_redirect' => Chip_Sprout_Invoices_Listener::get_redirect_url( $invoice->get_id(), 'paid' ),
			'failure_redirect' => Chip_Sprout_Invoices_Listener::get_redirect_url( $invoice->get_id(), 'error' ),
			'cancel_redirect'  => $this->get_cancel_url( $invoice ),
			'send_receipt'     => true,
			'creator_agent'    => Chip_Sprout_Invoices_Helper::truncate( 'SproutInvoices: ' . SA_ADDON_CHIP_VERSION, 32 ),
			'reference'        => (string) $invoice->get_id(),
			// `platform` is a gateway-side allow-list (web, api, ios, android,
			// and a few named e-commerce modules). There is no Sprout Invoices
			// entry, so anything else is rejected with
			// `"x" is not a valid choice.` — the integration identifies itself
			// through `creator_agent` instead.
			'platform'         => 'api',
			'brand_id'         => (string) get_option( self::API_BRAND_ID_OPTION, '' ),
			'client'           => $this->build_client( $invoice ),
			'purchase'         => array(
				'timezone' => Chip_Sprout_Invoices_Helper::get_timezone(),
				'currency' => $currency,
				'products' => Chip_Sprout_Invoices_Helper::get_products( $invoice, $amount_in_sen ),
				'notes'    => Chip_Sprout_Invoices_Helper::truncate(
					sprintf(
						/* translators: %s: invoice number */
						__( 'Invoice %s', 'chip-for-sprout-invoices' ),
						$invoice->get_invoice_id()
					),
					10000
				),
			),
		);

		// Only send `due` when a timing is actually configured. An empty timing
		// means "no expiry": sending `time() + 0` makes the gateway reject
		// every purchase with "`due` cannot be in the past!".
		$due = Chip_Sprout_Invoices_Helper::resolve_due_timestamp( get_option( self::DUE_STRICT_TIMING_OPTION, '' ) );

		if ( null !== $due ) {
			$params['due'] = $due;

			if ( 'yes' === get_option( self::DUE_STRICT_OPTION, '' ) ) {
				$params['purchase']['due_strict'] = true;
			}
		}

		$whitelist = get_option( self::PAYMENT_METHOD_WHITELIST, array() );

		if ( is_array( $whitelist ) && ! empty( $whitelist ) ) {
			$resolved = Chip_Sprout_Invoices_Helper::resolve_whitelist(
				$whitelist,
				$currency,
				$amount_in_sen,
				$invoice,
				self::api()
			);

			if ( ! empty( $resolved ) ) {
				$params['payment_method_whitelist'] = $resolved;
			}
		}

		$params = apply_filters( 'si_chip_ec_set_array_data', $params, $checkout, $invoice );

		return apply_filters( 'si_set_array_data', $params, $checkout );
	}

	/**
	 * Builds the CHIP client object from whoever is paying.
	 *
	 * @param SI_Invoice $invoice Invoice.
	 * @return array
	 */
	private function build_client( SI_Invoice $invoice ) {
		$user = si_who_is_paying( $invoice );

		$email     = '';
		$full_name = '';

		if ( is_a( $user, 'WP_User' ) ) {
			$email     = (string) $user->user_email;
			$full_name = trim( $user->first_name . ' ' . $user->last_name );

			if ( '' === $full_name ) {
				$full_name = (string) $user->display_name;
			}
		}

		if ( ! is_email( $email ) ) {
			// Sprout Invoices has no client email field, so a client without an
			// associated user account falls back to the site admin. Log it: the
			// receipt and the gateway's own notifications go to that address.
			Chip_Sprout_Invoices_Helper::log(
				'no customer email available, falling back to the site admin address',
				array( 'invoice_id' => $invoice->get_id() )
			);

			$email = (string) get_option( 'admin_email' );
		}

		$client = array(
			'email'     => $email,
			'full_name' => Chip_Sprout_Invoices_Helper::truncate( $full_name, 128 ),
		);

		$client = array_merge( $client, $this->get_client_address( $invoice ) );

		return array_filter( $client );
	}

	/**
	 * Maps the Sprout Invoices client address onto CHIP client fields.
	 *
	 * @param SI_Invoice $invoice Invoice.
	 * @return array
	 */
	private function get_client_address( SI_Invoice $invoice ) {
		$client = $invoice->get_client();

		if ( ! is_a( $client, 'SI_Client' ) ) {
			return array();
		}

		$address = $client->get_address();

		if ( ! is_array( $address ) ) {
			return array();
		}

		$mapped = array(
			'street_address' => isset( $address['street'] ) ? $address['street'] : '',
			'city'           => isset( $address['city'] ) ? $address['city'] : '',
			'state'          => isset( $address['zone'] ) ? $address['zone'] : '',
			'zip_code'       => isset( $address['postal_code'] ) ? $address['postal_code'] : '',
			'country'        => isset( $address['country'] ) ? $address['country'] : '',
		);

		$mapped = array_map(
			static function ( $value ) {
				return Chip_Sprout_Invoices_Helper::truncate( trim( (string) $value ), 128 );
			},
			$mapped
		);

		return array_filter( $mapped );
	}

	/**
	 * Resolves the customer cancel URL.
	 *
	 * @param SI_Invoice $invoice Invoice.
	 * @return string
	 */
	private function get_cancel_url( SI_Invoice $invoice ) {
		$configured = trim( (string) get_option( self::CANCEL_URL_OPTION, '' ) );

		if ( '' !== $configured && filter_var( $configured, FILTER_VALIDATE_URL ) ) {
			return $configured;
		}

		return add_query_arg(
			array(
				'invoice_payment' => self::PAYMENT_SLUG,
				'si_chip_status'  => 'cancelled',
			),
			SI_Controller::get_doc_permalink( $invoice->get_id() )
		);
	}

	/**
	 * Currency code for the invoice.
	 *
	 * The invoice's own currency is what the amounts are denominated in. The
	 * Currency Code setting is only a fallback for an invoice that carries
	 * none, because charging a USD invoice as MYR would take the wrong amount
	 * of money: CHIP treats the minor units as MYR sen regardless of what the
	 * invoice says.
	 *
	 * @param SI_Invoice $invoice Invoice.
	 * @return string Uppercase currency code.
	 */
	private function get_currency_code( SI_Invoice $invoice ) {
		$invoice_currency = trim( (string) $invoice->get_currency() );

		if ( '' === $invoice_currency ) {
			$invoice_currency = trim( (string) get_option( self::CURRENCY_CODE_OPTION, 'MYR' ) );
		}

		if ( '' === $invoice_currency ) {
			$invoice_currency = 'MYR';
		}

		return strtoupper(
			(string) apply_filters( 'si_currency_code', $invoice_currency, $invoice->get_id(), self::PAYMENT_METHOD )
		);
	}

	/**
	 * Records the payment on the confirmation page.
	 *
	 * @param SI_Checkouts $checkout Checkout.
	 * @param SI_Invoice   $invoice  Invoice.
	 * @return SI_Payment|false
	 */
	public function process_payment( SI_Checkouts $checkout, SI_Invoice $invoice ) {
		$purchase_id = $invoice->get_post_meta( self::PURCHASE_ID_META );

		if ( ! $purchase_id ) {
			Chip_Sprout_Invoices_Helper::log(
				'no purchase recorded for this invoice, nothing to confirm',
				array( 'invoice_id' => $invoice->get_id() )
			);

			return false;
		}

		// Idempotency: the server-to-server callback may already have recorded
		// this purchase while the customer was still on CHIP's page.
		$existing = self::find_payment_for_purchase( $purchase_id );

		if ( $existing ) {
			return $existing;
		}

		$api      = self::api();
		$purchase = $api->get_payment( $purchase_id );

		if ( ! is_array( $purchase ) || empty( $purchase['id'] ) ) {
			Chip_Sprout_Invoices_Helper::log(
				'could not retrieve the purchase',
				array(
					'invoice_id' => $invoice->get_id(),
					'payment_id' => $purchase_id,
					'error'      => $api->get_last_error(),
				)
			);

			return false;
		}

		$payment = self::record_purchase( $purchase, $invoice );

		if ( ! $payment ) {
			self::set_message(
				__( 'Your payment could not be confirmed. Please contact us before trying again.', 'chip-for-sprout-invoices' ),
				self::MESSAGE_STATUS_ERROR
			);

			return false;
		}

		return $payment;
	}

	/**
	 * Records a verified purchase as a Sprout Invoices payment.
	 *
	 * Shared by the checkout confirmation path and the server-to-server
	 * callback, so an invoice is settled even when the customer closes the
	 * browser before returning. Safe to call repeatedly: an existing payment
	 * for the same purchase is returned rather than duplicated.
	 *
	 * @param array           $purchase CHIP purchase payload.
	 * @param SI_Invoice|null $invoice  Invoice; resolved from the purchase when omitted.
	 * @return SI_Payment|false
	 */
	public static function record_purchase( $purchase, $invoice = null ) {
		if ( ! is_array( $purchase ) || empty( $purchase['id'] ) ) {
			return false;
		}

		$purchase_id = sanitize_text_field( (string) $purchase['id'] );
		$status      = isset( $purchase['status'] ) ? sanitize_text_field( (string) $purchase['status'] ) : '';

		if ( ! in_array( $status, array( 'paid', 'hold' ), true ) ) {
			Chip_Sprout_Invoices_Helper::log(
				'purchase is not payable, no payment recorded',
				array(
					'payment_id' => $purchase_id,
					'status'     => $status,
				)
			);

			return false;
		}

		// Reading the existing payment and then creating one is not atomic. CHIP
		// retries callbacks and the browser redirect can arrive at the same
		// moment as the server-to-server callback, so two requests for one
		// purchase can both find nothing and both write a payment — the invoice
		// is then reconciled twice against money that was taken once. A named
		// database lock serializes the pair, so the second request waits and
		// then sees the payment the first one wrote.
		$lock_name = 'chip_si_purchase_' . md5( $purchase_id );
		$locked    = self::acquire_lock( $lock_name );

		if ( ! $locked ) {
			Chip_Sprout_Invoices_Helper::log( 'could not acquire the purchase lock', array( 'payment_id' => $purchase_id ) );
		}

		try {
			$existing = self::find_payment_for_purchase( $purchase_id );

			if ( $existing ) {
				return $existing;
			}

			return self::create_payment_for_purchase( $purchase, $invoice, $purchase_id, $status );
		} finally {
			if ( $locked ) {
				self::release_lock( $lock_name );
			}
		}
	}

	/**
	 * Takes a named database lock so only one request records a purchase.
	 *
	 * @param string $lock_name Lock name (max 64 characters).
	 * @return bool True when the lock was taken.
	 */
	private static function acquire_lock( $lock_name ) {
		global $wpdb;

		// GET_LOCK returns 1 when taken, 0 on timeout and NULL on error.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock_name, self::LOCK_TIMEOUT_SECONDS ) );

		return '1' === (string) $result;
	}

	/**
	 * Releases a named database lock.
	 *
	 * @param string $lock_name Lock name.
	 * @return void
	 */
	private static function release_lock( $lock_name ) {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
	}

	/**
	 * Writes the payment row for a gateway purchase.
	 *
	 * Only ever reached with the purchase lock held.
	 *
	 * @param array          $purchase    CHIP purchase payload.
	 * @param SI_Invoice|null $invoice    Invoice, resolved when omitted.
	 * @param string         $purchase_id CHIP purchase ID.
	 * @param string         $status      Gateway status.
	 * @return SI_Payment|false
	 */
	private static function create_payment_for_purchase( $purchase, $invoice, $purchase_id, $status ) {
		if ( ! is_a( $invoice, 'SI_Invoice' ) ) {
			$invoice_id = self::find_invoice_id_for_purchase( $purchase_id );
			$invoice    = $invoice_id ? SI_Invoice::get_instance( $invoice_id ) : null;
		}

		if ( ! is_a( $invoice, 'SI_Invoice' ) ) {
			Chip_Sprout_Invoices_Helper::log( 'no invoice found for purchase', array( 'payment_id' => $purchase_id ) );

			return false;
		}

		if ( isset( $purchase['payment']['amount'] ) ) {
			$amount_in_sen = (int) $purchase['payment']['amount'];
		} elseif ( isset( $purchase['purchase']['total'] ) ) {
			$amount_in_sen = (int) $purchase['purchase']['total'];
		} else {
			$amount_in_sen = 0;
		}

		$amount = Chip_Sprout_Invoices_Helper::from_minor_units( $amount_in_sen );

		if ( $amount < 0.01 ) {
			Chip_Sprout_Invoices_Helper::log( 'purchase reports a non-positive amount, skipping', array( 'payment_id' => $purchase_id ) );

			return false;
		}

		// What the gateway collected must equal what the invoice asked for,
		// otherwise the invoice would be reconciled against a wrong figure.
		// Compared in minor units so no float formatting can cause a false
		// mismatch. A pre-authorized (hold) payment is settled on capture.
		$expected_in_sen = Chip_Sprout_Invoices_Helper::to_minor_units( $invoice->get_balance() );

		if ( 'hold' !== $status && $amount_in_sen !== $expected_in_sen ) {
			Chip_Sprout_Invoices_Helper::log(
				'amount mismatch, payment not recorded',
				array(
					'payment_id'   => $purchase_id,
					'paid_sen'     => $amount_in_sen,
					'expected_sen' => $expected_in_sen,
				)
			);

			return false;
		}

		$payment_status = ( 'hold' === $status ) ? SI_Payment::STATUS_AUTHORIZED : SI_Payment::STATUS_COMPLETE;

		$payment_id = SI_Payment::new_payment(
			array(
				'payment_method' => self::PAYMENT_METHOD,
				'invoice'        => $invoice->get_id(),
				'amount'         => $amount,
				'data'           => array(
					'api_response' => $purchase,
				),
			),
			$payment_status
		);

		if ( ! $payment_id ) {
			Chip_Sprout_Invoices_Helper::log( 'failed to create the payment record', array( 'payment_id' => $purchase_id ) );

			return false;
		}

		$payment = SI_Payment::get_instance( $payment_id );

		if ( ! is_a( $payment, 'SI_Payment' ) ) {
			return false;
		}

		// The gateway purchase ID is also the transaction ID, so refunds,
		// support and reconciliation can always trace a payment back to the
		// record the gateway holds.
		$payment->set_purchase( $purchase_id );
		$payment->set_transaction_id( $purchase_id );
		$payment->save_post_meta( array( self::PURCHASE_ID_META => $purchase_id ) );

		if ( 'hold' === $status ) {
			do_action( 'payment_authorized', $payment );
		} else {
			do_action( 'payment_complete', $payment );
		}

		Chip_Sprout_Invoices_Helper::log(
			'payment recorded',
			array(
				'invoice_id' => $invoice->get_id(),
				'payment_id' => $payment_id,
				'amount'     => $amount,
				'status'     => $status,
			)
		);

		return $payment;
	}

	/**
	 * Finds the payment already made against a gateway purchase.
	 *
	 * @param string $purchase_id CHIP purchase ID.
	 * @return SI_Payment|false
	 */
	public static function find_payment_for_purchase( $purchase_id ) {
		if ( '' === (string) $purchase_id ) {
			return false;
		}

		$payment_ids = SI_Payment::find_by_meta(
			SI_Payment::POST_TYPE,
			array( self::PURCHASE_ID_META => (string) $purchase_id )
		);

		if ( ! is_array( $payment_ids ) || empty( $payment_ids ) ) {
			return false;
		}

		$payment = SI_Payment::get_instance( reset( $payment_ids ) );

		return is_a( $payment, 'SI_Payment' ) ? $payment : false;
	}

	/**
	 * Resolves the invoice a gateway purchase belongs to.
	 *
	 * @param string $purchase_id CHIP purchase ID.
	 * @return int Invoice ID, or 0 when unknown.
	 */
	public static function find_invoice_id_for_purchase( $purchase_id ) {
		$purchase_id = (string) $purchase_id;

		if ( '' === $purchase_id ) {
			return 0;
		}

		$invoice_ids = SI_Invoice::find_by_meta(
			SI_Invoice::POST_TYPE,
			array( self::PURCHASE_ID_META => $purchase_id )
		);

		if ( is_array( $invoice_ids ) && ! empty( $invoice_ids ) ) {
			return (int) reset( $invoice_ids );
		}

		// Fallback for a payment record written before the invoice meta was set.
		$payment = self::find_payment_for_purchase( $purchase_id );

		if ( $payment ) {
			return (int) $payment->get_invoice_id();
		}

		return 0;
	}

	/**
	 * API client built from the configured credentials.
	 *
	 * @return Chip_Sprout_Invoice_API
	 */
	public static function api() {
		return new Chip_Sprout_Invoice_API(
			(string) get_option( self::API_SECRET_KEY_OPTION, '' ),
			(string) get_option( self::API_BRAND_ID_OPTION, '' )
		);
	}

	/**
	 * Captures an authorized payment from the payments admin screen.
	 *
	 * @param SI_Payment $payment Payment.
	 * @return void
	 */
	public function manually_capture_purchase( SI_Payment $payment ) {
		$purchase_id = $payment->get_post_meta( self::PURCHASE_ID_META );

		if ( ! $purchase_id ) {
			return;
		}

		$this->capture_payment( $payment, $purchase_id );
	}

	/**
	 * Captures a pre-authorized gateway purchase.
	 *
	 * @param SI_Payment $payment     Payment.
	 * @param string     $purchase_id Gateway purchase ID.
	 * @return bool
	 */
	public function capture_payment( SI_Payment $payment, $purchase_id = '' ) {
		if ( SI_Payment::STATUS_AUTHORIZED !== $payment->get_status() ) {
			return false;
		}

		if ( '' === (string) $purchase_id ) {
			$purchase_id = $payment->get_post_meta( self::PURCHASE_ID_META );
		}

		if ( ! $purchase_id ) {
			return false;
		}

		$api    = self::api();
		$result = $api->capture_payment( $purchase_id, array() );

		if ( ! is_array( $result ) || ! isset( $result['status'] ) || 'paid' !== $result['status'] ) {
			Chip_Sprout_Invoices_Helper::log(
				'capture failed',
				array(
					'payment_id' => $purchase_id,
					'error'      => $api->get_last_error(),
				)
			);

			return false;
		}

		$payment->set_status( SI_Payment::STATUS_COMPLETE );
		do_action( 'payment_complete', $payment );

		return true;
	}

	/**
	 * Warns when a CHIP payment is voided from the admin.
	 *
	 * Sprout Invoices' "Void Payment" only changes a local status; it moves no
	 * money. Recording that explicitly avoids a silent reconciliation gap.
	 *
	 * @param int $payment_id Payment ID.
	 * @return void
	 */
	public static function warn_on_void( $payment_id ) {
		$payment = SI_Payment::get_instance( $payment_id );

		if ( ! is_a( $payment, 'SI_Payment' ) ) {
			return;
		}

		if ( ! $payment->get_post_meta( self::PURCHASE_ID_META ) ) {
			return;
		}

		Chip_Sprout_Invoices_Helper::log(
			'CHIP payment voided locally: no money was refunded. Use the Refund action in the CHIP column to refund at the gateway.',
			array( 'payment_id' => $payment_id )
		);
	}
}

SI_Chip_EC::register();
