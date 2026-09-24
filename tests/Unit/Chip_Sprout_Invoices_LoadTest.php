<?php
/**
 * Smoke tests: every plugin class must load and expose the members the rest of
 * the plugin (and Sprout Invoices) calls.
 *
 * A rename or a changed signature is invisible to `php -l`, which only parses
 * one file at a time. These tests load the files together against a minimal
 * Sprout Invoices surface, so a class renamed in one file but referenced by the
 * old name in another — or a method the plugin calls on the processor — fails
 * here instead of on a merchant's checkout.
 *
 * @package ChipForSproutInvoices
 */

namespace ChipForSproutInvoices\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class Chip_Sprout_Invoices_LoadTest extends TestCase {

	/**
	 * Loads the plugin classes once per process.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		foreach ( array( 'listener', 'refund', 'ec' ) as $file ) {
			require_once dirname( __DIR__, 2 ) . "/includes/class-chip-si-{$file}.php";
		}
	}

	/**
	 * The processor class must exist and extend Sprout Invoices' offsite
	 * processor base, which is what registers it on the payment page.
	 */
	public function test_processor_class_loads_and_extends_the_sprout_base(): void {
		$this->assertTrue( class_exists( 'SI_Chip_EC' ) );
		$this->assertTrue( is_subclass_of( 'SI_Chip_EC', 'SI_Offsite_Processors' ) );
	}

	/**
	 * The processor must register itself with Sprout Invoices at load time and
	 * under its own class name: Sprout persists that name in the
	 * `si_payment_processor` option, so a rename silently unregisters CHIP.
	 */
	public function test_processor_registers_itself_with_sprout(): void {
		$this->assertArrayHasKey(
			'SI_Chip_EC',
			$GLOBALS['sa_chip_test_processors'] ?? array(),
			'SI_Chip_EC did not register itself on load'
		);

		$this->assertSame( 'CHIP', $GLOBALS['sa_chip_test_processors']['SI_Chip_EC'] );
	}

	/**
	 * Every class the entry point includes must resolve. The rename of the API
	 * class is exactly the kind of change that breaks this.
	 */
	public function test_every_plugin_class_resolves(): void {
		$classes = array(
			'Chip_Sprout_Invoices_API',
			'Chip_Sprout_Invoices_Helper',
			'Chip_Sprout_Invoices_Listener',
			'Chip_Sprout_Invoices_Refund',
			'SI_Chip_EC',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue( class_exists( $class ), "{$class} did not load" );
		}
	}

	/**
	 * The processor must expose the API factory and the constants the rest of
	 * the plugin reads from it.
	 */
	public function test_processor_exposes_its_public_surface(): void {
		$methods = array(
			'get_payment_method',
			'get_slug',
			'register',
			'public_name',
			'checkout_options',
			'register_settings',
			'get_whitelist_options',
			'send_offsite',
			'back_from_chip',
			'post_checkout_redirect',
			'process_payment',
			'record_purchase',
			'find_payment_for_purchase',
			'find_invoice_id_for_purchase',
			'api',
			'capture_payment',
			'manually_capture_purchase',
			'warn_on_void',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'SI_Chip_EC', $method ),
				"SI_Chip_EC::{$method}() is missing"
			);
		}

		$this->assertSame( 'CHIP', \SI_Chip_EC::PAYMENT_METHOD );
		$this->assertSame( 'chip', \SI_Chip_EC::PAYMENT_SLUG );
		$this->assertSame( '_chip_purchase_id', \SI_Chip_EC::PURCHASE_ID_META );
	}

	/**
	 * The API class must expose the operations the processor and listener call.
	 */
	public function test_api_exposes_the_gateway_operations(): void {
		$methods = array(
			'create_payment',
			'get_payment',
			'refund_payment',
			'capture_payment',
			'mark_as_paid',
			'payment_methods',
			'public_key',
			'is_configured',
			'get_last_error',
			'get_last_response_code',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'Chip_Sprout_Invoices_API', $method ),
				"Chip_Sprout_Invoices_API::{$method}() is missing"
			);
		}
	}

	/**
	 * The listener must keep the callback and redirect entry points that Sprout
	 * Invoices and CHIP reach by URL.
	 */
	public function test_listener_exposes_its_entry_points(): void {
		foreach ( array( 'handle_callback', 'handle_redirect', 'get_passphrase' ) as $method ) {
			$this->assertTrue(
				method_exists( 'Chip_Sprout_Invoices_Listener', $method ),
				"Chip_Sprout_Invoices_Listener::{$method}() is missing"
			);
		}
	}

	/**
	 * The helper must keep the conversion and resolution methods the processor
	 * depends on, including the one that owns currency.
	 */
	public function test_helper_exposes_its_shared_operations(): void {
		$methods = array(
			'is_logging_enabled',
			'log',
			'to_minor_units',
			'from_minor_units',
			'truncate',
			'get_purchase_label',
			'get_products',
			'resolve_whitelist',
			'resolve_due_timestamp',
			'get_timezone',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'Chip_Sprout_Invoices_Helper', $method ),
				"Chip_Sprout_Invoices_Helper::{$method}() is missing"
			);
		}
	}

	/**
	 * The refund class must keep its registration and AJAX entry points.
	 */
	public function test_refund_exposes_its_entry_points(): void {
		foreach ( array( 'init', 'enqueue_scripts', 'handle_refund', 'refund_payment' ) as $method ) {
			$this->assertTrue(
				method_exists( 'Chip_Sprout_Invoices_Refund', $method ),
				"Chip_Sprout_Invoices_Refund::{$method}() is missing"
			);
		}
	}
}
