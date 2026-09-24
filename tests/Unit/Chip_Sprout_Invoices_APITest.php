<?php
/**
 * Unit tests for Chip_Sprout_Invoices_API.
 *
 * @package ChipForSproutInvoices
 */

namespace ChipForSproutInvoices\Tests\Unit;

use Chip_Sprout_Invoices_API;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Chip_Sprout_Invoices_API
 */
class Chip_Sprout_Invoices_APITest extends TestCase {

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
	 * An instance without credentials reports itself unconfigured, so the
	 * checkout never posts a request it cannot authenticate.
	 */
	public function test_is_configured_is_false_without_credentials(): void {
		$api = new Chip_Sprout_Invoices_API( '', '' );

		$this->assertFalse( $api->is_configured() );
	}

	/**
	 * A failed call on an unconfigured client sets an actionable message
	 * rather than issuing a request.
	 */
	public function test_unconfigured_client_reports_a_message(): void {
		$api = new Chip_Sprout_Invoices_API( '', '' );

		$this->assertNull( $api->create_payment( array( 'x' => 1 ) ) );
		$this->assertSame(
			'Brand ID or Secret Key is not configured.',
			$api->get_last_error()
		);
	}

	/**
	 * CHIP returns a validation failure as a bare field map at the top level,
	 * e.g. {"platform":[{"message":"\"sproutinvoices\" is not a valid choice."}]}.
	 * That must surface as a readable message, not an empty one.
	 */
	public function test_bare_field_map_error_is_described(): void {
		$body = wp_json_encode(
			array(
				'platform' => array(
					array(
						'message' => '"sproutinvoices" is not a valid choice.',
						'code'    => 'invalid_choice',
					),
				),
			)
		);

		$this->mock_http( 400, $body );

		$api = new Chip_Sprout_Invoices_API( 'test_secret', 'test_brand' );

		$this->assertNull( $api->create_payment( array( 'amount' => 100 ) ) );
		$this->assertStringContainsString( 'not a valid choice', $api->get_last_error() );
		$this->assertSame( 400, $api->get_last_response_code() );
	}

	/**
	 * A wrapped `errors` payload is described too.
	 */
	public function test_wrapped_errors_payload_is_described(): void {
		$body = wp_json_encode(
			array(
				'errors' => array(
					'amount' => array(
						array( 'message' => 'A valid integer is required.' ),
					),
				),
			)
		);

		$this->mock_http( 422, $body );

		$api = new Chip_Sprout_Invoices_API( 'test_secret', 'test_brand' );

		$this->assertNull( $api->create_payment( array( 'amount' => 1 ) ) );
		$this->assertStringContainsString( 'A valid integer is required.', $api->get_last_error() );
	}

	/**
	 * A successful payload must not be mistaken for an error map: its field
	 * values are scalars, not lists of error objects.
	 */
	public function test_successful_payload_is_returned_not_treated_as_an_error(): void {
		$payload = array(
			'id'       => 'abc-123',
			'status'   => 'created',
			'currency' => 'MYR',
			'purchase' => array(
				'total'    => 1000,
				'currency' => 'MYR',
			),
		);

		$this->mock_http( 200, wp_json_encode( $payload ) );

		$api    = new Chip_Sprout_Invoices_API( 'test_secret', 'test_brand' );
		$result = $api->create_payment( array( 'amount' => 1000 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'abc-123', $result['id'] );
		$this->assertSame( '', $api->get_last_error() );
	}

	/**
	 * A bare string body (the public key endpoint) is passed through
	 * untouched rather than decoded.
	 */
	public function test_public_key_endpoint_returns_the_bare_string(): void {
		$pem = '-----BEGIN PUBLIC KEY-----abc-----END PUBLIC KEY-----';

		$this->mock_http( 200, wp_json_encode( $pem ) );

		$api = new Chip_Sprout_Invoices_API( 'test_secret', 'test_brand' );

		$this->assertSame( $pem, $api->public_key() );
	}

	/**
	 * A 2xx response that still carries an error body was accepted but not
	 * acted on; treating it as success would lose the reason the gateway
	 * refused.
	 */
	public function test_2xx_with_error_body_is_not_treated_as_success(): void {
		$body = wp_json_encode(
			array(
				'amount' => array(
					array( 'message' => 'A valid integer is required.' ),
				),
			)
		);

		$this->mock_http( 200, $body );

		$api = new Chip_Sprout_Invoices_API( 'test_secret', 'test_brand' );

		$this->assertNull( $api->create_payment( array( 'amount' => 1 ) ) );
		$this->assertStringContainsString( 'A valid integer is required.', $api->get_last_error() );
	}

	/**
	 * A transport failure is reported as such instead of as an empty error.
	 */
	public function test_transport_failure_sets_a_message(): void {
		WP_Mock::userFunction( 'wp_remote_request' )->andReturn( new \WP_Error() );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );

		$api = new Chip_Sprout_Invoices_API( 'test_secret', 'test_brand' );

		$this->assertNull( $api->create_payment( array( 'amount' => 1 ) ) );
		$this->assertNotSame( '', $api->get_last_error() );
	}

	/**
	 * Stubs the HTTP layer for a given status and body.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 * @return void
	 */
	private function mock_http( $status, $body ) {
		WP_Mock::userFunction( 'wp_remote_request' )->andReturn(
			array(
				'body'     => $body,
				'response' => array( 'code' => $status ),
			)
		);

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
			}
		);

		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 200;
			}
		);

		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static function ( $tag, $value ) {
				return $value;
			}
		);
	}
}
