<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package ChipForSproutInvoices
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

$si_chip_options = array(
	'si_chip_brand_id',
	'si_chip_secret_key',
	'si_chip_currency',
	'si_chip_cancel_url',
	'si_chip_due_strict',
	'si_chip_due_strict_timing',
	'si_chip_payment_method_whitelist',
	'si_chip_log_enabled',
	'si_chip_callback_passphrase',
	'si_chip_public_key',
);

foreach ( $si_chip_options as $si_chip_option ) {
	delete_option( $si_chip_option );
}

// Payment method lookups are cached per currency and amount.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's own transients on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_si_chip_pm_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_si_chip_pm_' ) . '%'
	)
);
