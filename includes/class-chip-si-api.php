<?php
/**
 * CHIP API client.
 *
 * @package ChipForSproutInvoices
 */

if ( ! defined( 'ABSPATH' ) ) {
	die; // Cannot access directly.
}

// CHIP API host, as documented at https://docs.chip-in.asia.
if ( ! defined( 'SI_CHIP_ROOT_URL' ) ) {
	define( 'SI_CHIP_ROOT_URL', 'https://gate.chip-in.asia' );
}

/**
 * Thin CHIP Collect API client.
 *
 * `call()` returns null for every failure shape (transport error, non-2xx
 * status, unparseable body, or an errors payload), so callers must treat a
 * response as "not guaranteed to be an array" and guard every read. The last
 * failure reason is kept in `get_last_error()` so the admin log can explain
 * what actually went wrong instead of a generic failure message.
 */
class Chip_Sprout_Invoice_API {

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Brand ID.
	 *
	 * @var string
	 */
	private $brand_id;

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Last HTTP response code.
	 *
	 * @var int
	 */
	private $last_response_code = 0;

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $brand_id   Brand ID.
	 */
	public function __construct( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
	}

	/**
	 * Sets credentials.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $brand_id   Brand ID.
	 * @return void
	 */
	public function set_key( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
	}

	/**
	 * Creates a purchase.
	 *
	 * @param array $params Purchase body.
	 * @return array|null
	 */
	public function create_payment( $params ) {
		// time() is to force fresh instead of cache.
		return $this->call( 'POST', '/purchases/?time=' . time(), $params );
	}

	/**
	 * Gets a single purchase.
	 *
	 * @param string $payment_id Purchase ID.
	 * @return array|null
	 */
	public function get_payment( $payment_id ) {
		// time() is to force fresh instead of cache.
		return $this->call( 'GET', '/purchases/' . rawurlencode( (string) $payment_id ) . '/?time=' . time() );
	}

	/**
	 * Refunds a purchase (full or partial).
	 *
	 * @param string $payment_id Purchase ID.
	 * @param array  $params     Refund body, `amount` in minor units.
	 * @return array|null
	 */
	public function refund_payment( $payment_id, $params = array() ) {
		return $this->call( 'POST', '/purchases/' . rawurlencode( (string) $payment_id ) . '/refund/', $params );
	}

	/**
	 * Captures a pre-authorized purchase.
	 *
	 * @param string $payment_id Purchase ID.
	 * @param array  $params     Capture body.
	 * @return array|null
	 */
	public function capture_payment( $payment_id, $params = array() ) {
		return $this->call( 'POST', '/purchases/' . rawurlencode( (string) $payment_id ) . '/capture/', $params );
	}

	/**
	 * Marks a purchase as paid.
	 *
	 * CHIP exposes this for settling a purchase without an acquirer round-trip
	 * (its test-mode equivalent). Only ever reached from the E2E harness, never
	 * from the checkout or the callback: a real payment reports `paid` on its
	 * own and this would otherwise let an unpaid invoice be settled.
	 *
	 * @param string $payment_id Purchase ID.
	 * @param array  $params     Body, e.g. `paid_on`.
	 * @return array|null
	 */
	public function mark_as_paid( $payment_id, $params = array() ) {
		return $this->call( 'POST', '/purchases/' . rawurlencode( (string) $payment_id ) . '/mark_as_paid/', $params );
	}

	/**
	 * Lists the payment methods available for the brand.
	 *
	 * @param string $currency Currency code.
	 * @param int    $amount   Amount in minor units.
	 * @return array|null
	 */
	public function payment_methods( $currency, $amount = 0 ) {
		$route = sprintf(
			'/payment_methods/?brand_id=%s&currency=%s&amount=%d',
			rawurlencode( (string) $this->brand_id ),
			rawurlencode( (string) $currency ),
			(int) $amount
		);

		return $this->call( 'GET', $route );
	}

	/**
	 * Fetches the account public key used to verify callback payloads.
	 *
	 * @return string|null PEM encoded public key, or null on failure.
	 */
	public function public_key() {
		$result = $this->call( 'GET', '/public_key/' );

		if ( is_string( $result ) && '' !== trim( $result ) ) {
			return str_replace( '\n', "\n", $result );
		}

		if ( is_array( $result ) && isset( $result['public_key'] ) ) {
			return str_replace( '\n', "\n", (string) $result['public_key'] );
		}

		return null;
	}

	/**
	 * Whether credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->secret_key ) && '' !== trim( (string) $this->brand_id );
	}

	/**
	 * Last error message, if any.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Last HTTP response code.
	 *
	 * @return int
	 */
	public function get_last_response_code() {
		return $this->last_response_code;
	}

	/**
	 * Makes an API call.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  API route, relative to /api/v1.
	 * @param array  $params Request body.
	 * @return array|string|null Decoded body, or null on failure.
	 */
	private function call( $method, $route, $params = array() ) {
		$this->last_error         = '';
		$this->last_response_code = 0;

		if ( ! $this->is_configured() ) {
			$this->last_error = __( 'Brand ID or Secret Key is not configured.', 'chip-for-sprout-invoices' );
			return null;
		}

		$body = '';
		if ( ! empty( $params ) ) {
			$body = wp_json_encode( $params );
		}

		$response = $this->request(
			$method,
			sprintf( '%s/api/v1%s', SI_CHIP_ROOT_URL, $route ),
			$body,
			array(
				'Content-type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->secret_key,
			)
		);

		if ( null === $response ) {
			if ( '' === $this->last_error ) {
				$this->last_error = __( 'No response from the payment gateway.', 'chip-for-sprout-invoices' );
			}
			return null;
		}

		$result = json_decode( $response, true );

		if ( null === $result ) {
			// The public key endpoint answers with a bare PEM string, not JSON.
			return $response;
		}

		if ( is_array( $result ) && ! empty( $result['errors'] ) ) {
			$this->last_error = self::describe_errors( $result['errors'] );
			return null;
		}

		return $result;
	}

	/**
	 * Turns a CHIP error payload into one readable sentence.
	 *
	 * CHIP returns a validation failure either wrapped in an `errors` key or as
	 * a bare field map at the top level of the body, so both shapes are
	 * handled: `{"platform":[{"message":"...","code":"invalid_choice"}]}`.
	 *
	 * @param array $body Decoded response body.
	 * @return string Empty string when the body carries no error.
	 */
	private static function describe_error_body( $body ) {
		if ( ! is_array( $body ) ) {
			return '';
		}

		if ( ! empty( $body['errors'] ) ) {
			return self::describe_errors( $body['errors'] );
		}

		if ( ! empty( $body['__all__'] ) ) {
			return self::describe_errors( array( '__all__' => $body['__all__'] ) );
		}

		// A bare field map: every value must be a non-empty list of error
		// objects, otherwise this is a successful payload we must not mistake
		// for a failure.
		$looks_like_errors = ! empty( $body );

		foreach ( $body as $field_errors ) {
			if ( ! is_array( $field_errors ) || empty( $field_errors ) ) {
				$looks_like_errors = false;
				break;
			}

			foreach ( $field_errors as $error ) {
				if ( ! is_array( $error ) || ! isset( $error['message'] ) ) {
					$looks_like_errors = false;
					break 2;
				}
			}
		}

		return $looks_like_errors ? self::describe_errors( $body ) : '';
	}

	/**
	 * Sends an HTTP request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Full URL.
	 * @param string $body    Request body.
	 * @param array  $headers Headers.
	 * @return string|null Response body, or null when the request failed.
	 */
	private function request( $method, $url, $body = '', $headers = array() ) {
		$wp_request = wp_remote_request(
			$url,
			array(
				'method'    => $method,
				'sslverify' => ! defined( 'SA_ADDON_CHIP_SSLVERIFY_FALSE' ),
				'headers'   => $headers,
				'body'      => $body,
				'timeout'   => 30,
			)
		);

		if ( is_wp_error( $wp_request ) ) {
			$this->last_error = $wp_request->get_error_message();
			return null;
		}

		$this->last_response_code = (int) wp_remote_retrieve_response_code( $wp_request );
		$response                 = wp_remote_retrieve_body( $wp_request );

		if ( $this->last_response_code < 200 || $this->last_response_code >= 300 ) {
			$decoded          = json_decode( $response, true );
			$described        = self::describe_error_body( $decoded );
			$this->last_error = ( '' !== $described )
				? $described
				/* translators: %d: HTTP status code returned by the payment gateway */
				: sprintf( __( 'Payment gateway returned HTTP %d.', 'chip-for-sprout-invoices' ), $this->last_response_code );
			return null;
		}

		$decoded   = json_decode( $response, true );
		$described = self::describe_error_body( $decoded );

		if ( '' !== $described ) {
			// A 2xx carrying an error body: the request was accepted but not
			// acted on, so treating it as success would lose the reason.
			$this->last_error = $described;
			return null;
		}

		return $response;
	}

	/**
	 * Flattens a CHIP errors payload into one readable sentence.
	 *
	 * @param array $errors Errors payload.
	 * @return string
	 */
	private static function describe_errors( $errors ) {
		$messages = array();

		if ( is_array( $errors ) ) {
			foreach ( $errors as $field => $field_errors ) {
				if ( ! is_array( $field_errors ) ) {
					continue;
				}
				foreach ( $field_errors as $error ) {
					if ( is_array( $error ) && isset( $error['message'] ) ) {
						$messages[] = $field . ': ' . $error['message'];
					}
				}
			}
		}

		if ( empty( $messages ) ) {
			return __( 'Payment gateway rejected the request.', 'chip-for-sprout-invoices' );
		}

		return implode( '; ', $messages );
	}
}
