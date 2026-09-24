<?php
/**
 * Shared helpers: logging, amount math, payment method groups.
 *
 * @package ChipForSproutInvoices
 */

if ( ! defined( 'ABSPATH' ) ) {
	die; // Cannot access directly.
}

/**
 * Helper utilities shared by the processor, listener and refund handler.
 */
class Chip_Sprout_Invoices_Helper {

	const LOG_OPTION = 'si_chip_log_enabled';

	/**
	 * DuitNow QR group: legacy (`dnqr`) and modern (`duitnow_qr`) identifiers.
	 * Exposed to the merchant as a single checkbox, resolved at runtime.
	 *
	 * @var array
	 */
	const DUITNOW_GROUP = array( 'duitnow_qr', 'dnqr' );

	/**
	 * ShopeePay group: legacy (`razer_shopeepay`) and modern (`shopee_pay`).
	 *
	 * @var array
	 */
	const SHOPEE_GROUP = array( 'razer_shopeepay', 'shopee_pay' );

	/**
	 * Whether logging is enabled.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled() {
		return (bool) get_option( self::LOG_OPTION, false );
	}

	/**
	 * Logs a message.
	 *
	 * Writes to the Sprout Invoices developer log when the merchant enabled
	 * "Save Logs", and always forwards to the `si_log` action so other
	 * listeners (and WP_DEBUG error logs) see it.
	 *
	 * @param string $message Message.
	 * @param mixed  $context Optional context.
	 * @return void
	 */
	public static function log( $message, $context = null ) {
		$subject = 'CHIP: ' . $message;

		if ( self::is_logging_enabled() ) {
			do_action( 'si_log', $subject, $context );
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			do_action( 'si_log', $subject, $context );
		}
	}

	/**
	 * Converts a currency amount into minor units (sen) as an integer.
	 *
	 * Uses string-based rounding rather than a raw `$amount * 100` float
	 * multiply: e.g. 28.99 * 100 is 2898.9999999999995 in IEEE-754, which the
	 * gateway rejects with "A valid integer is required".
	 *
	 * @param float|string $amount Amount in major units.
	 * @return int
	 */
	public static function to_minor_units( $amount ) {
		$amount = (float) $amount;

		if ( $amount < 0 ) {
			$amount = 0;
		}

		return (int) round( $amount * 100, 0, PHP_ROUND_HALF_UP );
	}

	/**
	 * Converts minor units back into a major-unit amount.
	 *
	 * @param int $minor Amount in minor units.
	 * @return float
	 */
	public static function from_minor_units( $minor ) {
		return round( ( (int) $minor ) / 100, 2 );
	}

	/**
	 * Trims a value to a maximum length in bytes without splitting a
	 * multi-byte character.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	public static function truncate( $value, $length ) {
		$value = (string) $value;

		if ( strlen( $value ) <= $length ) {
			return $value;
		}

		return (string) mb_substr( $value, 0, $length );
	}

	/**
	 * Builds a human-readable purchase product name for an invoice.
	 *
	 * @param SI_Invoice $invoice Invoice.
	 * @return string
	 */
	public static function get_purchase_label( SI_Invoice $invoice ) {
		$number = $invoice->get_invoice_id();
		if ( '' === (string) $number ) {
			$number = $invoice->get_id();
		}

		$client_id = $invoice->get_client_id();
		$client    = $client_id ? get_the_title( $client_id ) : '';

		$label = sprintf(
			/* translators: %s: invoice number */
			__( 'Invoice %s', 'chip-for-sprout-invoices' ),
			$number
		);

		if ( '' !== trim( (string) $client ) ) {
			$label .= ' - ' . $client;
		}

		return self::truncate( $label, 256 );
	}

	/**
	 * Builds the line-item product list for a purchase.
	 *
	 * Falls back to a single total-priced line when the invoice has no usable
	 * line items, and always sums to exactly the amount being charged so the
	 * gateway's own total matches what Sprout Invoices expects to reconcile.
	 *
	 * @param SI_Invoice $invoice        Invoice.
	 * @param int        $amount_in_sen  Amount being charged, in minor units.
	 * @return array
	 */
	public static function get_products( SI_Invoice $invoice, $amount_in_sen ) {
		$products   = array();
		$line_items = $invoice->get_line_items();

		if ( is_array( $line_items ) && ! empty( $line_items ) ) {
			foreach ( $line_items as $position => $item ) {
				// Only top level items, children are part of their parent's price.
				if ( ! is_int( $position ) ) {
					continue;
				}

				$description = isset( $item['desc'] ) ? wp_strip_all_tags( (string) $item['desc'] ) : '';
				$description = trim( preg_replace( '/\s+/', ' ', $description ) );

				if ( '' === $description ) {
					continue;
				}

				$rate     = isset( $item['rate'] ) ? (float) $item['rate'] : 0.0;
				$qty      = ( isset( $item['qty'] ) && (float) $item['qty'] > 0 ) ? (float) $item['qty'] : 1.0;
				$tax      = isset( $item['tax'] ) ? (float) $item['tax'] : 0.0;
				$subtotal = $rate * $qty;
				if ( $tax ) {
					$subtotal += $subtotal * ( $tax / 100 );
				}

				$line_minor = self::to_minor_units( $subtotal );

				if ( $line_minor <= 0 ) {
					continue;
				}

				$products[] = array(
					'name'     => self::truncate( $description, 256 ),
					'price'    => $line_minor,
					'quantity' => '1',
				);
			}
		}

		if ( empty( $products ) ) {
			return array(
				array(
					'name'     => self::get_purchase_label( $invoice ),
					'price'    => max( 0, (int) $amount_in_sen ),
					'quantity' => '1',
				),
			);
		}

		// Deposit / partial payments rarely match the line item sum. Scale the
		// last product so the products total exactly what is being charged.
		$sum = 0;
		foreach ( $products as $product ) {
			$sum += (int) $product['price'];
		}

		if ( $sum !== (int) $amount_in_sen ) {
			$last_index = count( $products ) - 1;
			$new_last   = (int) $products[ $last_index ]['price'] + ( (int) $amount_in_sen - $sum );

			if ( $new_last > 0 ) {
				$products[ $last_index ]['price'] = $new_last;
			} else {
				// The adjustment would make a line negative: collapse to one
				// product carrying the exact charge.
				$products = array(
					array(
						'name'     => self::get_purchase_label( $invoice ),
						'price'    => max( 0, (int) $amount_in_sen ),
						'quantity' => '1',
					),
				);
			}
		}

		return $products;
	}

	/**
	 * Resolves the payment method whitelist, expanding the DuitNow QR and
	 * ShopeePay groups against the methods the brand actually offers.
	 *
	 * Sending both names of a group is what breaks the method on CHIP's side,
	 * so exactly one identifier per configured group is sent. Falls back to a
	 * preference order when the availability lookup fails.
	 *
	 * @param array                    $whitelist  Configured identifiers.
	 * @param string                   $currency   Currency code.
	 * @param int                      $amount     Amount in minor units.
	 * @param SI_Invoice               $invoice    Invoice, for cache/context.
	 * @param Chip_Sprout_Invoices_API $api API client.
	 * @return array
	 */
	public static function resolve_whitelist( $whitelist, $currency, $amount, SI_Invoice $invoice, $api ) {
		$whitelist = array_values( array_filter( array_map( 'strval', (array) $whitelist ) ) );

		if ( empty( $whitelist ) ) {
			return array();
		}

		// In-memory migration: legacy razer_shopeepay -> modern shopee_pay,
		// unless the merchant already configured both.
		if ( in_array( 'razer_shopeepay', $whitelist, true ) && ! in_array( 'shopee_pay', $whitelist, true ) ) {
			$whitelist = array_values(
				array_map(
					static function ( $method ) {
						return 'razer_shopeepay' === $method ? 'shopee_pay' : $method;
					},
					$whitelist
				)
			);
		}

		$groups = array(
			'duitnow_qr' => self::DUITNOW_GROUP,
			'shopee_pay' => self::SHOPEE_GROUP,
		);

		$all_members = array_values( array_unique( array_merge( ...array_values( $groups ) ) ) );

		// Short-circuit when neither group is configured.
		if ( 0 === count( array_intersect( $whitelist, $all_members ) ) ) {
			return array_values( array_unique( $whitelist ) );
		}

		$expanded = array_values( array_unique( array_merge( $whitelist, $all_members ) ) );

		$cache_key = 'si_chip_pm_' . md5( $currency . '|' . intval( $amount / 100 ) );

		$available = get_transient( $cache_key );
		if ( false === $available ) {
			$response  = $api->payment_methods( $currency, $amount );
			$available = ( is_array( $response ) && isset( $response['available_payment_methods'] ) )
				? (array) $response['available_payment_methods']
				: false;

			if ( false === $available ) {
				self::log(
					sprintf(
						'payment method lookup failed, using preference order. configured=%s',
						implode( ',', $whitelist )
					),
					array( 'error' => $api->get_last_error() )
				);
			} else {
				set_transient( $cache_key, $available, 30 * MINUTE_IN_SECONDS );
			}
		}

		$resolved = array();

		foreach ( $groups as $preferred => $members ) {
			if ( 0 === count( array_intersect( $whitelist, $members ) ) ) {
				continue;
			}

			if ( false === $available ) {
				// Unknown availability: send only the preferred identifier so a
				// group never goes out twice.
				$resolved[] = $preferred;
				continue;
			}

			$resolved_group = array_values( array_intersect( $members, $available ) );

			if ( in_array( $preferred, $resolved_group, true ) ) {
				$resolved_group = array( $preferred );
			}

			$resolved = array_merge( $resolved, $resolved_group );
		}

		// Original non-group entries first, then one identifier per group.
		$final = array_values( array_diff( $expanded, $all_members ) );
		$final = array_values( array_unique( array_merge( $final, $resolved ) ) );

		self::log(
			sprintf(
				'whitelist resolved: configured=%s available=%s sent=%s',
				implode( ',', $whitelist ),
				is_array( $available ) ? implode( ',', $available ) : 'unknown',
				implode( ',', $final )
			)
		);

		return $final;
	}

	/**
	 * Resolves a `due` timestamp.
	 *
	 * Returns null when the timing is empty, so the `due` parameter is omitted
	 * entirely. Sending `time() + 0` makes every purchase fail with
	 * "`due` cannot be in the past!".
	 *
	 * @param mixed $timing_minutes Configured timing in minutes.
	 * @return int|null
	 */
	public static function resolve_due_timestamp( $timing_minutes ) {
		if ( '' === $timing_minutes || null === $timing_minutes || false === $timing_minutes ) {
			return null;
		}

		$minutes = absint( $timing_minutes );

		if ( 0 === $minutes ) {
			return null;
		}

		return time() + ( $minutes * 60 );
	}

	/**
	 * Site timezone string, falling back to UTC.
	 *
	 * @return string
	 */
	public static function get_timezone() {
		$timezone = wp_timezone_string();

		if ( preg_match( '/^[A-Za-z]+\/[A-Za-z_\/\-]+$/', $timezone ) ) {
			return $timezone;
		}

		return 'UTC';
	}
}
