<?php
/**
 * CHIP callback and redirect listener.
 *
 * @package ChipForSproutInvoices
 */

if ( ! defined( 'ABSPATH' ) ) {
	die; // Cannot access directly.
}

/**
 * Handles CHIP's server-to-server callback and the customer's browser return.
 *
 * Two endpoints are registered on `init`:
 *
 *  - `si_chip_callback=<passphrase>` receives CHIP's POST webhook. The raw body
 *    is verified against the account public key (X-Signature, RSA PKCS#1 v1.5
 *    over SHA-256); when that cannot be verified the purchase is re-read from
 *    the API instead, so a signature-format change never silently drops a paid
 *    invoice.
 *  - `si_chip_redirect=<passphrase>&invoice_id=<id>&status=<paid|error|cancel>`
 *    receives the customer after payment.
 *
 * Both are idempotent. CHIP retries a callback up to 8 times over 36 hours and
 * may deliver the same event twice, so recording a purchase is guarded by a
 * named MySQL lock plus an existing-payment lookup.
 */
class Chip_Sprout_Invoices_Listener {

	const CALLBACK_KEY = 'si_chip_callback';

	const REDIRECT_KEY = 'si_chip_redirect';

	/**
	 * Public key option, fetched from the gateway once and then cached.
	 */
	const PUBLIC_KEY_OPTION = 'si_chip_public_key';

	const CALLBACK_PASSPHRASE = 'si_chip_callback_passphrase';

	/**
	 * Static passphrase for the browser return URL: it is not a secret, the
	 * purchase itself is re-read from the API before any payment is recorded.
	 */
	const REDIRECT_PASSPHRASE = 'chip-for-sprout-invoices-redirect';

	/**
	 * Single instance.
	 *
	 * @var Chip_Sprout_Invoices_Listener|null
	 */
	private static $instance;

	/**
	 * Gets the single instance.
	 *
	 * @return Chip_Sprout_Invoices_Listener
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'handle_callback' ) );
		add_action( 'init', array( $this, 'handle_redirect' ) );
	}

	/**
	 * Callback passphrase, generated on first use and never displayed.
	 *
	 * @return string
	 */
	public static function get_passphrase() {
		$passphrase = get_option( self::CALLBACK_PASSPHRASE, false );

		if ( ! is_string( $passphrase ) || '' === $passphrase ) {
			$passphrase = wp_generate_password( 32, false, false );
			update_option( self::CALLBACK_PASSPHRASE, $passphrase, false );
		}

		return $passphrase;
	}

	/**
	 * Builds CHIP's server-to-server callback URL.
	 *
	 * No purchase ID is carried in the URL: CHIP posts the signed purchase
	 * snapshot, which is where the ID is read from.
	 *
	 * @return string
	 */
	public static function get_callback_url() {
		return add_query_arg(
			array( self::CALLBACK_KEY => self::get_passphrase() ),
			home_url( '/' )
		);
	}

	/**
	 * Builds the customer-facing return URL.
	 *
	 * The invoice ID is part of the URL because the browser arrives with
	 * nothing else: the purchase ID does not exist yet when the redirect URLs
	 * are sent to the gateway.
	 *
	 * @param int    $invoice_id Invoice ID.
	 * @param string $status     One of `paid`, `error`, `cancel`.
	 * @return string
	 */
	public static function get_redirect_url( $invoice_id, $status ) {
		return add_query_arg(
			array(
				self::REDIRECT_KEY => self::REDIRECT_PASSPHRASE,
				'invoice_id'       => absint( $invoice_id ),
				'status'           => $status,
			),
			home_url( '/' )
		);
	}

	/**
	 * Handles CHIP's server-to-server callback.
	 *
	 * @return void
	 */
	public function handle_callback() {
		// External gateway callback: no nonce exists; authenticated by passphrase + signature.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::CALLBACK_KEY ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$provided = sanitize_text_field( wp_unslash( $_GET[ self::CALLBACK_KEY ] ) );

		if ( ! hash_equals( self::get_passphrase(), $provided ) ) {
			Chip_Sprout_Invoices_Helper::log( 'callback rejected: invalid passphrase' );
			status_header( 403 );
			exit;
		}

		$raw_body = file_get_contents( 'php://input' );
		$purchase = $this->verified_purchase_from_body( $raw_body );

		if ( ! $purchase ) {
			Chip_Sprout_Invoices_Helper::log( 'callback rejected: body could not be verified or retrieved' );
			status_header( 403 );
			exit;
		}

		Chip_Sprout_Invoices_Helper::log(
			'callback verified',
			array(
				'purchase_id' => $purchase['id'],
				'status'      => isset( $purchase['status'] ) ? $purchase['status'] : '',
			)
		);

		$this->process_purchase( $purchase );

		status_header( 200 );
		echo 'OK';
		exit;
	}

	/**
	 * Verifies a callback body against the account public key.
	 *
	 * @param string $raw_body Raw request body.
	 * @return array|false Verified purchase payload, or false.
	 */
	private function verified_purchase_from_body( $raw_body ) {
		$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) )
			: '';

		if ( '' !== $signature && $this->verify_signature( $raw_body, $signature ) ) {
			$decoded = json_decode( $raw_body, true );

			if ( is_array( $decoded ) && ! empty( $decoded['id'] ) ) {
				return $decoded;
			}

			Chip_Sprout_Invoices_Helper::log( 'signature was valid but the body carried no purchase id' );
		}

		// Fall back to an authenticated read so an unverifiable body does not
		// lose a payment. The purchase ID is only ever taken from the signed
		// body when the signature held; otherwise the ID is read from the body
		// as a lookup key and nothing is trusted from it.
		$decoded = json_decode( $raw_body, true );
		$lookup  = is_array( $decoded ) && ! empty( $decoded['id'] )
			? sanitize_text_field( (string) $decoded['id'] )
			: '';

		if ( '' === $lookup ) {
			return false;
		}

		Chip_Sprout_Invoices_Helper::log(
			'signature verification failed, falling back to API lookup',
			array( 'purchase_id' => $lookup )
		);

		$purchase = SI_Chip_EC::api()->get_payment( $lookup );

		return ( is_array( $purchase ) && ! empty( $purchase['id'] ) ) ? $purchase : false;
	}

	/**
	 * Handles the customer's browser return from CHIP.
	 *
	 * @return void
	 */
	public function handle_redirect() {
		// External gateway redirect; validated by static passphrase.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::REDIRECT_KEY ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$provided = sanitize_text_field( wp_unslash( $_GET[ self::REDIRECT_KEY ] ) );

		if ( ! hash_equals( self::REDIRECT_PASSPHRASE, $provided ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$invoice_id = isset( $_GET['invoice_id'] ) ? absint( wp_unslash( $_GET['invoice_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		if ( ! $invoice_id ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		Chip_Sprout_Invoices_Helper::log(
			'customer returned from checkout',
			array(
				'invoice_id' => $invoice_id,
				'status'     => $status,
			)
		);

		// The browser's word for what happened is never trusted: the purchase
		// is read back from the gateway before any payment is recorded.
		if ( 'cancel' !== $status ) {
			$invoice = SI_Invoice::get_instance( $invoice_id );

			if ( is_a( $invoice, 'SI_Invoice' ) ) {
				$purchase_id = $invoice->get_post_meta( SI_Chip_EC::PURCHASE_ID_META );

				if ( $purchase_id ) {
					$api      = SI_Chip_EC::api();
					$purchase = $api->get_payment( $purchase_id );

					if ( is_array( $purchase ) && ! empty( $purchase['id'] ) ) {
						$this->process_purchase( $purchase );
					} else {
						Chip_Sprout_Invoices_Helper::log(
							'redirect could not retrieve the purchase',
							array(
								'purchase_id' => $purchase_id,
								'error'       => $api->get_last_error(),
							)
						);
					}
				}
			}
		}

		$this->redirect_to_invoice( $invoice_id, $status );
	}

	/**
	 * Creates the Sprout Invoices payment for a verified purchase.
	 *
	 * @param array $purchase CHIP purchase payload.
	 * @return SI_Payment|false
	 */
	private function process_purchase( $purchase ) {
		$purchase_id = isset( $purchase['id'] ) ? sanitize_text_field( (string) $purchase['id'] ) : '';

		if ( '' === $purchase_id ) {
			return false;
		}

		$invoice_id = SI_Chip_EC::find_invoice_id_for_purchase( $purchase_id );

		if ( ! $invoice_id && ! empty( $purchase['reference'] ) ) {
			// `reference` is set to the invoice ID when the purchase is
			// created, and is the only link available if the invoice meta was
			// never written (e.g. the customer abandoned the checkout redirect).
			$candidate = absint( $purchase['reference'] );

			if ( $candidate && SI_Invoice::POST_TYPE === get_post_type( $candidate ) ) {
				$invoice_id = $candidate;
			}
		}

		if ( ! $invoice_id ) {
			Chip_Sprout_Invoices_Helper::log( 'no invoice found for purchase', array( 'purchase_id' => $purchase_id ) );

			return false;
		}

		// No lock is taken here: record_purchase() serializes on the purchase
		// itself, which covers this path, the browser redirect and any retry
		// with a single lock instead of two nested ones.
		return SI_Chip_EC::record_purchase( $purchase );
	}

	/**
	 * Verifies the X-Signature header against the account public key.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Base64 encoded signature.
	 * @return bool
	 */
	private function verify_signature( $body, $signature ) {
		if ( '' === trim( (string) $body ) || '' === trim( (string) $signature ) ) {
			return false;
		}

		// Decoding the gateway signature, not obfuscating code.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $signature, true );

		if ( false === $decoded ) {
			return false;
		}

		$public_key = self::get_public_key();

		if ( '' === $public_key ) {
			return false;
		}

		$key = openssl_pkey_get_public( $public_key );

		if ( false === $key ) {
			Chip_Sprout_Invoices_Helper::log( 'stored public key is not usable' );
			return false;
		}

		return 1 === openssl_verify( $body, $decoded, $key, 'sha256WithRSAEncryption' );
	}

	/**
	 * The account public key, fetched from the gateway once and cached.
	 *
	 * @return string
	 */
	public static function get_public_key() {
		$cached = get_option( self::PUBLIC_KEY_OPTION, '' );

		if ( is_string( $cached ) && '' !== trim( $cached ) ) {
			return $cached;
		}

		$api        = SI_Chip_EC::api();
		$public_key = $api->public_key();

		if ( ! is_string( $public_key ) || '' === trim( $public_key ) ) {
			return '';
		}

		$public_key = trim( $public_key );

		update_option( self::PUBLIC_KEY_OPTION, $public_key, false );

		return $public_key;
	}

	/**
	 * Sends the customer back to their invoice.
	 *
	 * @param int    $invoice_id Invoice ID.
	 * @param string $status     Status slug.
	 * @return void
	 */
	private function redirect_to_invoice( $invoice_id, $status ) {
		$url = SI_Controller::get_doc_permalink( $invoice_id );

		if ( '' === $url ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// The invoice page renders Sprout Invoices' own messages, so the
		// customer is told what happened instead of landing on an invoice that
		// looks untouched. Without this the payment is recorded but the page
		// gives no sign of it.
		$this->set_return_message( $invoice_id, $status );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Sets the message shown on the invoice the customer returns to.
	 *
	 * @param int    $invoice_id Invoice ID.
	 * @param string $status     Status slug from the redirect.
	 * @return void
	 */
	private function set_return_message( $invoice_id, $status ) {
		$invoice = SI_Invoice::get_instance( $invoice_id );

		if ( ! is_a( $invoice, 'SI_Invoice' ) ) {
			return;
		}

		$balance = (float) $invoice->get_balance();

		if ( 'cancel' === $status ) {
			SI_Controller::set_message(
				__( 'You cancelled the payment. Nothing has been charged.', 'chip-for-sprout-invoices' ),
				SI_Controller::MESSAGE_STATUS_INFO
			);

			return;
		}

		if ( 'error' === $status ) {
			SI_Controller::set_message(
				__( 'The payment was not completed. Nothing has been charged.', 'chip-for-sprout-invoices' ),
				SI_Controller::MESSAGE_STATUS_ERROR
			);

			return;
		}

		if ( $balance < 0.01 ) {
			SI_Controller::set_message(
				__( 'Payment received & invoice paid!', 'chip-for-sprout-invoices' ),
				SI_Controller::MESSAGE_STATUS_INFO
			);

			return;
		}

		SI_Controller::set_message(
			sprintf(
				/* translators: %s: the invoice balance that is still outstanding */
				__( 'Payment received. Outstanding balance: %s', 'chip-for-sprout-invoices' ),
				sa_get_formatted_money( $balance )
			),
			SI_Controller::MESSAGE_STATUS_INFO
		);
	}
}
