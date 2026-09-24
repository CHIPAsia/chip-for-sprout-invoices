<?php
/**
 * CHIP refund handling for Sprout Invoices payments.
 *
 * @package ChipForSproutInvoices
 */

if ( ! defined( 'ABSPATH' ) ) {
	die; // Cannot access directly.
}

/**
 * Refunds a CHIP payment from the Sprout Invoices payments screen.
 *
 * Sprout Invoices' own "Refund" row action only writes a local status; it
 * never talks to a gateway. Money movement therefore has to be an explicit
 * action of its own, offered on CHIP payments that the gateway will still
 * accept a refund for.
 */
class Chip_Sprout_Invoices_Refund {

	const NONCE = 'si_chip_refund_payment';

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_si_chip_refund', array( __CLASS__, 'handle_refund' ) );
	}

	/**
	 * Enqueues the refund script on the payments screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'sa_invoice_page_sprout-apps/invoice_payments' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'si-chip-refund',
			SA_ADDON_CHIP_URL . 'assets/refund.js',
			array( 'jquery' ),
			SA_ADDON_CHIP_VERSION,
			true
		);

		wp_localize_script(
			'si-chip-refund',
			'siChipRefund',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => 'si_chip_refund',
				'confirm'    => __( 'Refund this payment at CHIP? This cannot be undone.', 'chip-for-sprout-invoices' ),
				'partialHint' => __( 'Leave empty to refund the full amount, or enter an amount in minor units (sen).', 'chip-for-sprout-invoices' ),
				'working'    => __( 'Refunding...', 'chip-for-sprout-invoices' ),
				'failed'     => __( 'Refund failed. Check the logs for details.', 'chip-for-sprout-invoices' ),
			)
		);
	}

	/**
	 * Performs the refund.
	 *
	 * @return void
	 */
	public static function handle_refund() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_sprout_invoices_payments' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to refund payments.', 'chip-for-sprout-invoices' ) ), 403 );
		}

		$payment_id = isset( $_POST['payment_id'] ) ? absint( wp_unslash( $_POST['payment_id'] ) ) : 0;
		$amount     = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;

		if ( ! $payment_id ) {
			wp_send_json_error( array( 'message' => __( 'No payment was given.', 'chip-for-sprout-invoices' ) ), 400 );
		}

		$payment = SI_Payment::get_instance( $payment_id );

		if ( ! is_a( $payment, 'SI_Payment' ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment not found.', 'chip-for-sprout-invoices' ) ), 404 );
		}

		$result = self::refund_payment( $payment, $amount );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => $result ) );
	}

	/**
	 * Refunds a payment at CHIP and updates the local record.
	 *
	 * The local status only changes once CHIP confirms the refund, mirroring
	 * how the rest of this plugin treats the gateway as the source of truth.
	 *
	 * @param SI_Payment $payment Payment.
	 * @param int        $amount  Amount in minor units; 0 means the full amount.
	 * @return string|WP_Error Success message, or an error.
	 */
	public static function refund_payment( SI_Payment $payment, $amount = 0 ) {
		$purchase_id = $payment->get_post_meta( SI_Chip_EC::PURCHASE_ID_META );

		if ( ! $purchase_id ) {
			return new WP_Error( 'si_chip_no_purchase', __( 'This payment has no CHIP purchase attached.', 'chip-for-sprout-invoices' ) );
		}

		if ( SI_Payment::STATUS_COMPLETE !== $payment->get_status() ) {
			return new WP_Error( 'si_chip_not_refundable', __( 'Only completed payments can be refunded.', 'chip-for-sprout-invoices' ) );
		}

		// The gateway decides whether a purchase can be refunded, and asking
		// anyway earns an opaque `invalid enum for type Acquirer: None`. A
		// purchase settled without an acquirer (marked as paid, or a direct
		// debit that has not cleared) reports a refund_availability that is not
		// `all`, and the merchant gets the real reason instead of a gateway
		// error they cannot act on.
		$api      = SI_Chip_EC::api();
		$purchase = $api->get_payment( $purchase_id );

		if ( is_array( $purchase ) ) {
			$availability = isset( $purchase['refund_availability'] ) ? (string) $purchase['refund_availability'] : '';

			if ( '' !== $availability && 'all' !== $availability ) {
				return new WP_Error(
					'si_chip_not_refundable_here',
					sprintf(
						/* translators: %s: the gateway's refund availability value */
						__( 'CHIP cannot refund this payment (refund availability: %s). Refund it from the CHIP dashboard instead.', 'chip-for-sprout-invoices' ),
						$availability
					)
				);
			}
		}

		$paid_in_sen = Chip_Sprout_Invoices_Helper::to_minor_units( $payment->get_amount() );

		if ( $amount < 0 || $amount > $paid_in_sen ) {
			return new WP_Error(
				'si_chip_bad_amount',
				sprintf(
					/* translators: %s: the amount the payment was for */
					__( 'The refund cannot exceed the payment amount of %s.', 'chip-for-sprout-invoices' ),
					sa_get_formatted_money( $payment->get_amount() )
				)
			);
		}

		$params = array();

		// A full refund is asked for by sending no amount at all: sending the
		// exact total is the same thing but leaves no room for the gateway to
		// treat it as a partial refund of a marginally different total.
		if ( $amount > 0 && $amount < $paid_in_sen ) {
			$params['amount'] = $amount;
		} elseif ( $amount <= 0 ) {
			$amount = $paid_in_sen;
		}

		$api    = SI_Chip_EC::api();
		$result = $api->refund_payment( $purchase_id, $params );

		if ( ! is_array( $result ) ) {
			Chip_Sprout_Invoices_Helper::log(
				'refund failed',
				array(
					'payment_id'  => $payment->get_id(),
					'purchase_id' => $purchase_id,
					'error'       => $api->get_last_error(),
				)
			);

			return new WP_Error( 'si_chip_refund_failed', $api->get_last_error() );
		}

		// `pending_refund` means the acquirer has not finished yet. Recording
		// the payment as refunded here would be a lie, so it is left alone.
		$refund_status = isset( $result['status'] ) ? sanitize_text_field( (string) $result['status'] ) : '';

		if ( 'pending_refund' === $refund_status ) {
			Chip_Sprout_Invoices_Helper::log(
				'refund pending at the acquirer',
				array(
					'payment_id'  => $payment->get_id(),
					'purchase_id' => $purchase_id,
					'refund_id'   => isset( $result['id'] ) ? $result['id'] : '',
				)
			);

			return __( 'CHIP accepted the refund and is waiting on the acquirer. The payment will show as refunded once it completes.', 'chip-for-sprout-invoices' );
		}

		$payment->set_status( SI_Payment::STATUS_REFUND );
		$payment->set_data(
			wp_parse_args(
				array(
					'chip_refund' => $result,
					'updated'     => sprintf(
						/* translators: %1$s: user ID, %2$s: date and time, %3$s: refunded amount */
						__( 'Refunded %3$s at CHIP by User #%1$s on %2$s', 'chip-for-sprout-invoices' ),
						get_current_user_id(),
						date_i18n( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ) ),
						sa_get_formatted_money( Chip_Sprout_Invoices_Helper::from_minor_units( $amount ) )
					),
				),
				$payment->get_data()
			)
		);

		do_action( 'si_chip_payment_refunded', $payment, $result );

		Chip_Sprout_Invoices_Helper::log(
			'refunded',
			array(
				'payment_id'  => $payment->get_id(),
				'purchase_id' => $purchase_id,
				'amount_sen'  => $amount,
				'refund_id'   => isset( $result['id'] ) ? $result['id'] : '',
			)
		);

		return sprintf(
			/* translators: %s: refunded amount */
			__( 'Refunded %s at CHIP.', 'chip-for-sprout-invoices' ),
			sa_get_formatted_money( Chip_Sprout_Invoices_Helper::from_minor_units( $amount ) )
		);
	}
}
