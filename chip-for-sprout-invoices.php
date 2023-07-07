<?php

/**
 * Plugin Name: CHIP for Sprout Invoices
 * Plugin URI: https://wordpress.org/plugins/chip-for-sprout-invoices/
 * Description: CHIP - Better Payment & Business Solutions
 * Version: 1.0.0
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 *
 * Copyright: © 2023 CHIP
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) { die; } // Cannot access directly.

class Chip_Sprout_Invoices {
  private static $_instance;

  public static function get_instance() {
    if ( static::$_instance == null ) {
      static::$_instance = new static();
    }

    return static::$_instance;
  }

  public function __construct() {
    $this->define();
    $this->includes();
    $this->add_filters();
    $this->add_actions();
  }

  public function define() {
    define( 'SA_ADDON_CHIP_VERSION', '1.0.0' );
    define( 'SA_ADDON_CHIP_FILE', __FILE__ );
    define( 'SA_ADDON_CHIP_BASENAME', plugin_basename( SA_ADDON_CHIP_FILE ) );
    define( 'SA_ADDON_CHIP_URL', plugin_dir_url( SA_ADDON_CHIP_FILE ) );
  }

  public static function load() {
    static::get_instance();
  }

  public static function includes() {
    $includes_dir = plugin_dir_path( SA_ADDON_CHIP_FILE ) . 'includes/';
    include $includes_dir . 'class-chip-si-api.php';
    include $includes_dir . 'class-chip-si-ec.php';
  }

  public function add_filters() {
    add_filter( 'plugin_action_links_' . SA_ADDON_CHIP_FILE, array( $this, 'setting_link' ) );
  }

  public function add_actions() {
  }
}

add_action( 'si_payment_processors_loaded', array( 'Chip_Sprout_Invoices', 'load' ) );
 