<?php
/**
 * Unit tests for Chip_Sprout_Invoices_Helper.
 *
 * @package ChipForSproutInvoices
 */

namespace ChipForSproutInvoices\Tests\Unit;

use Chip_Sprout_Invoices_Helper;
use SI_Invoice;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Chip_Sprout_Invoices_Helper
 */
class Chip_Sprout_Invoices_HelperTest extends TestCase {

	/**
	 * Set up WP_Mock.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
	}

	/**
	 * Tear down WP_Mock.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * A float multiply such as 19.99 * 100 is 1998.9999999999998 in IEEE-754.
	 * The gateway rejects that with "A valid integer is required", so the
	 * conversion must round instead of truncate — truncating undercharges by
	 * one sen on every amount whose cents are 9.
	 */
	public function test_to_minor_units_rounds_instead_of_truncating(): void {
		// 1998.9999999999998 raw -> truncation gives 1998.
		$this->assertSame( 1999, Chip_Sprout_Invoices_Helper::to_minor_units( 19.99 ) );
		// 28.999999999999996 raw -> truncation gives 28.
		$this->assertSame( 29, Chip_Sprout_Invoices_Helper::to_minor_units( 0.29 ) );
		// 7009.999999999999 raw -> truncation gives 7009.
		$this->assertSame( 7010, Chip_Sprout_Invoices_Helper::to_minor_units( 70.10 ) );
		$this->assertSame( 1, Chip_Sprout_Invoices_Helper::to_minor_units( 0.01 ) );
		$this->assertSame( 100, Chip_Sprout_Invoices_Helper::to_minor_units( 1.0 ) );
		$this->assertSame( 0, Chip_Sprout_Invoices_Helper::to_minor_units( 0 ) );
	}

	/**
	 * A negative amount must never reach the gateway as a negative charge.
	 */
	public function test_to_minor_units_clamps_negative_amounts_to_zero(): void {
		$this->assertSame( 0, Chip_Sprout_Invoices_Helper::to_minor_units( -5.00 ) );
	}

	/**
	 * Minor units round-trip back to the major-unit amount.
	 */
	public function test_from_minor_units_round_trips(): void {
		$this->assertSame( 28.99, Chip_Sprout_Invoices_Helper::from_minor_units( 2899 ) );
		$this->assertSame( 0.01, Chip_Sprout_Invoices_Helper::from_minor_units( 1 ) );
		$this->assertSame( 0.0, Chip_Sprout_Invoices_Helper::from_minor_units( 0 ) );
	}

	/**
	 * An empty timing must omit `due` entirely. Sending `time() + 0` makes the
	 * gateway reject every purchase with "due cannot be in the past!".
	 */
	public function test_resolve_due_timestamp_returns_null_when_timing_is_empty(): void {
		$this->assertNull( Chip_Sprout_Invoices_Helper::resolve_due_timestamp( '' ) );
		$this->assertNull( Chip_Sprout_Invoices_Helper::resolve_due_timestamp( null ) );
		$this->assertNull( Chip_Sprout_Invoices_Helper::resolve_due_timestamp( false ) );
		$this->assertNull( Chip_Sprout_Invoices_Helper::resolve_due_timestamp( '0' ) );
	}

	/**
	 * A configured timing resolves to a timestamp that far in the future.
	 */
	public function test_resolve_due_timestamp_returns_future_timestamp(): void {
		$before = time();
		$due    = Chip_Sprout_Invoices_Helper::resolve_due_timestamp( 60 );

		$this->assertIsInt( $due );
		$this->assertSame( $before + ( 60 * 60 ), $due );
	}

	/**
	 * truncate() must not split a multi-byte character: CHIP rejects an
	 * invalid UTF-8 value.
	 */
	public function test_truncate_keeps_multibyte_characters_intact(): void {
		$value = 'Pembayaran — invois 123';

		$result = Chip_Sprout_Invoices_Helper::truncate( $value, 12 );

		$this->assertSame( 'Pembayaran —', $result );
		$this->assertTrue( mb_check_encoding( $result, 'UTF-8' ) );
	}

	/**
	 * A value already within the limit is returned untouched.
	 */
	public function test_truncate_returns_short_values_unchanged(): void {
		$this->assertSame( 'Invois 12', Chip_Sprout_Invoices_Helper::truncate( 'Invois 12', 32 ) );
	}

	/**
	 * An invalid timezone string falls back to UTC instead of being sent to
	 * the gateway as-is.
	 */
	public function test_get_timezone_falls_back_to_utc(): void {
		putenv( 'SA_CHIP_TEST_TZ=not-a-timezone' );
		$this->assertSame( 'UTC', Chip_Sprout_Invoices_Helper::get_timezone() );

		putenv( 'SA_CHIP_TEST_TZ=Asia/Kuala_Lumpur' );
		$this->assertSame( 'Asia/Kuala_Lumpur', Chip_Sprout_Invoices_Helper::get_timezone() );

		putenv( 'SA_CHIP_TEST_TZ' );
	}

	/**
	 * The purchase label identifies the invoice, falling back to the post ID
	 * when the invoice carries no number.
	 */
	public function test_get_purchase_label_uses_the_invoice_number(): void {
		WP_Mock::userFunction( 'get_the_title' )->andReturn( '' );

		$label = Chip_Sprout_Invoices_Helper::get_purchase_label( new SI_Invoice( 'INV-1001' ) );

		$this->assertSame( 'Invoice INV-1001', $label );
	}

	/**
	 * With no invoice number the post ID is used so the label is never blank.
	 */
	public function test_get_purchase_label_falls_back_to_post_id(): void {
		WP_Mock::userFunction( 'get_the_title' )->andReturn( '' );

		$invoice = new class() extends SI_Invoice {
			/**
			 * @return int
			 */
			public function get_id() {
				return 4321;
			}
		};

		$this->assertSame( 'Invoice 4321', Chip_Sprout_Invoices_Helper::get_purchase_label( $invoice ) );
	}
}
