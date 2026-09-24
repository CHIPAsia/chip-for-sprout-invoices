<?php
/**
 * Plugin Name: CHIP for Sprout Invoices
 * Plugin URI: https://github.com/CHIPAsia/chip-for-sprout-invoices
 * Description: CHIP - Better Payment & Business Solutions
 * Version: 1.1.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 * Text Domain: chip-for-sprout-invoices
 *
 * Copyright: © 2026 CHIP
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package ChipForSproutInvoices
 */

if ( ! defined( 'ABSPATH' ) ) {
	die; // Cannot access directly.
}

/**
 * Main plugin class.
 */
class Chip_Sprout_Invoices {

	/**
	 * Single instance of the class.
	 *
	 * @var Chip_Sprout_Invoices|null
	 */
	private static $instance;

	/**
	 * Gets the single instance of the class.
	 *
	 * @return Chip_Sprout_Invoices
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
		$this->define();
		$this->includes();
		$this->add_filters();
	}

	/**
	 * Defines plugin constants.
	 *
	 * @return void
	 */
	public function define() {
		define( 'SA_ADDON_CHIP_VERSION', '1.1.0' );
		define( 'SA_ADDON_CHIP_FILE', __FILE__ );
		define( 'SA_ADDON_CHIP_BASENAME', plugin_basename( SA_ADDON_CHIP_FILE ) );
		define( 'SA_ADDON_CHIP_URL', plugin_dir_url( SA_ADDON_CHIP_FILE ) );
		define( 'SA_ADDON_CHIP_PATH', plugin_dir_path( SA_ADDON_CHIP_FILE ) );
	}

	/**
	 * Loads the plugin.
	 *
	 * Hooked on `si_payment_processors_loaded`, which Sprout Invoices fires
	 * after its own processor classes are loaded but before `init`, so the
	 * processor can register itself and the listener can still hook `init`.
	 *
	 * @return void
	 */
	public static function load() {
		$instance = static::get_instance();

		Chip_Sprout_Invoices_Listener::get_instance();

		if ( is_admin() ) {
			Chip_Sprout_Invoices_Refund::init();
		}

		$instance->add_actions();
	}

	/**
	 * Includes plugin files.
	 *
	 * @return void
	 */
	public static function includes() {
		$includes_dir = SA_ADDON_CHIP_PATH . 'includes/';

		include_once $includes_dir . 'class-chip-si-api.php';
		include_once $includes_dir . 'class-chip-si-helper.php';
		include_once $includes_dir . 'class-chip-si-listener.php';
		include_once $includes_dir . 'class-chip-si-refund.php';
		include_once $includes_dir . 'class-chip-si-ec.php';
	}

	/**
	 * Registers filters.
	 *
	 * @return void
	 */
	public function add_filters() {
		add_filter( 'plugin_action_links_' . SA_ADDON_CHIP_BASENAME, array( $this, 'setting_link' ) );
	}

	/**
	 * Registers actions.
	 *
	 * @return void
	 */
	public function add_actions() {
	}

	/**
	 * Adds a settings link on the plugin list row.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function setting_link( $links ) {
		$settings_url  = admin_url( 'admin.php?page=sprout-invoices-payments' );
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Settings', 'chip-for-sprout-invoices' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
}

add_action( 'si_payment_processors_loaded', array( 'Chip_Sprout_Invoices', 'load' ) );
