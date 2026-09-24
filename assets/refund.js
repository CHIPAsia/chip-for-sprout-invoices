/* global jQuery, siChipRefund */
/**
 * Refund action for CHIP payments on the Sprout Invoices payments screen.
 *
 * @package ChipForSproutInvoices
 */
jQuery( function ( $ ) {
	$( document ).on( 'click', '.si_chip_refund', function ( event ) {
		event.preventDefault();

		var $link = $( this );
		var paymentId = $link.data( 'payment-id' );
		var nonce = $link.data( 'nonce' );

		if ( ! paymentId || ! nonce ) {
			return;
		}

		if ( ! window.confirm( siChipRefund.confirm ) ) {
			return;
		}

		var rawAmount = window.prompt( siChipRefund.partialHint, '' );

		// Cancelling the prompt means "don't refund", not "refund in full".
		if ( null === rawAmount ) {
			return;
		}

		var amount = parseInt( rawAmount, 10 );
		if ( isNaN( amount ) || amount < 0 ) {
			amount = 0;
		}

		var originalText = $link.text();
		$link.text( siChipRefund.working ).css( 'pointer-events', 'none' );

		$.post(
			siChipRefund.ajaxUrl,
			{
				action: siChipRefund.action,
				payment_id: paymentId,
				amount: amount,
				nonce: nonce
			}
		)
			.done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
					return;
				}

				var message = response && response.data && response.data.message
					? response.data.message
					: siChipRefund.failed;

				window.alert( message );
				$link.text( originalText ).css( 'pointer-events', '' );
			} )
			.fail( function () {
				window.alert( siChipRefund.failed );
				$link.text( originalText ).css( 'pointer-events', '' );
			} );
	} );
} );
