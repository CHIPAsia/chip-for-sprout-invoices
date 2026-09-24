<?php
/**
 * PHPUnit bootstrap for CHIP for Sprout Invoices.
 *
 * WordPress and Sprout Invoices are not installed in the test environment, so
 * the handful of WordPress functions the tested code paths touch are stubbed
 * here. Tests assert behaviour (call the code, compare results) — never that a
 * source line exists.
 *
 * @package ChipForSproutInvoices
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test bootstrap stubs.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../vendor/wordpress/wordpress/' );
}

if ( ! defined( 'SA_ADDON_CHIP_VERSION' ) ) {
	define( 'SA_ADDON_CHIP_VERSION', '1.1.0' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	echo "Run composer install to install test dependencies.\n";
	exit( 1 );
}
require_once $autoload;

\WP_Mock::bootstrap();

// ─── WordPress stubs the plugin calls ───

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data    Data.
	 * @param int   $options Options.
	 * @param int   $depth   Depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	/**
	 * @return string
	 */
	function wp_timezone_string() {
		return getenv( 'SA_CHIP_TEST_TZ' ) ?: 'UTC';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {
		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->message = (string) $message;
		}

		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			return '';
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Thing.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/**
 * Sprout Invoices' invoice model, reduced to what the tested helper methods
 * actually call.
 */
if ( ! class_exists( 'SI_Invoice' ) ) {
	/**
	 * Test double for SI_Invoice.
	 */
	class SI_Invoice {

		/**
		 * Invoice number.
		 *
		 * @var string
		 */
		private $number;

		/**
		 * Client ID.
		 *
		 * @var int
		 */
		private $client_id;

		/**
		 * Balance.
		 *
		 * @var float
		 */
		private $balance;

		/**
		 * @param string $number    Invoice number.
		 * @param int    $client_id Client ID.
		 * @param float  $balance   Balance.
		 */
		public function __construct( $number = '', $client_id = 0, $balance = 0.0 ) {
			$this->number    = $number;
			$this->client_id = $client_id;
			$this->balance   = $balance;
		}

		/**
		 * @return string
		 */
		public function get_invoice_id() {
			return $this->number;
		}

		/**
		 * @return int
		 */
		public function get_client_id() {
			return $this->client_id;
		}

		/**
		 * @return float
		 */
		public function get_balance() {
			return $this->balance;
		}
	}
}

// The helper's log() fires `si_log`; WP_Mock records userFunction calls but
// do_action on an unmocked action is a no-op under WP_Mock, which is fine.

// ─── Sprout Invoices surface, reduced to what the plugin touches at load ───

if ( ! class_exists( 'SI_Checkouts' ) ) {
	/**
	 * Test double for SI_Checkouts.
	 */
	class SI_Checkouts {
		const CHECKOUT_ACTION   = 'si_checkout_action';
		const PAYMENT_PAGE      = 'payment';
		const REVIEW_PAGE       = 'review';
		const CONFIRMATION_PAGE = 'confirmation';
	}
}

if ( ! class_exists( 'SI_Payment' ) ) {
	/**
	 * Test double for SI_Payment.
	 */
	class SI_Payment {
		const POST_TYPE          = 'sa_payment';
		const STATUS_PENDING     = 'pending';
		const STATUS_AUTHORIZED  = 'authorized';
		const STATUS_COMPLETE    = 'publish';
		const STATUS_PARTIAL     = 'payment-partial';
		const STATUS_VOID        = 'void';
		const STATUS_REFUND      = 'refunded';
	}
}

if ( ! class_exists( 'SI_Client' ) ) {
	/**
	 * Test double for SI_Client.
	 */
	class SI_Client {
	}
}

if ( ! class_exists( 'SI_Controller' ) ) {
	/**
	 * Test double for SI_Controller.
	 */
	class SI_Controller {
	}
}

if ( ! class_exists( 'SI_Offsite_Processors' ) ) {
	/**
	 * Test double for Sprout Invoices' offsite processor base. Only the
	 * members the plugin calls are present.
	 */
	class SI_Offsite_Processors {

		/**
		 * Registers a processor. Sprout stores these in the
		 * `si_payment_processor` option; the double just records them.
		 *
		 * @param string $class Class name.
		 * @param string $name  Public name.
		 * @return void
		 */
		public static function add_payment_processor( $class, $name = '' ) {
			$GLOBALS['sa_chip_test_processors'][ $class ] = $name;
		}

		/**
		 * Processor slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return '';
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-chip-si-api.php';
require_once dirname( __DIR__ ) . '/includes/class-chip-si-helper.php';
