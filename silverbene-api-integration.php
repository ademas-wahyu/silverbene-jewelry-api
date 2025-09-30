<?php
/**
 * Plugin Name: Silverbene API Integration
 * Description: Integrasi API Silverbene untuk sinkronisasi produk dan pesanan WooCommerce secara otomatis.
 * Version: 1.1.0
 * Author: Wahyu (Vodeco Dev Core)
 * Text Domain: silverbene-api-integration
 */

// Mencegah akses langsung ke file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Menentukan path plugin
define( 'SILVERBENE_API_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

define( 'SILVERBENE_API_VERSION', '1.1.0' );

define( 'SILVERBENE_API_SETTINGS_OPTION', 'silverbene_api_settings' );

// Memuat file kelas utama
require_once SILVERBENE_API_PLUGIN_PATH . 'includes/class-silverbene-api-client.php';
require_once SILVERBENE_API_PLUGIN_PATH . 'includes/class-silverbene-api.php';
require_once SILVERBENE_API_PLUGIN_PATH . 'includes/class-silverbene-sync.php';
require_once SILVERBENE_API_PLUGIN_PATH . 'includes/class-silverbene-order.php';

/**
 * Bootstrap instance helper.
 *
 * @return array
 */
function silverbene_api_container() {
    static $container = null;

    if ( null === $container ) {
        $client = new Silverbene_API_Client();
        $sync   = new Silverbene_Sync( $client );
        $order  = new Silverbene_Order( $client );
        $api    = new Silverbene_API( $client, $sync );

        $container = array(
            'api'    => $api,
            'client' => $client,
            'sync'   => $sync,
            'order'  => $order,
        );
    }

    return $container;
}

// Hook untuk inisialisasi plugin
function silverbene_api_init() {
    $container = silverbene_api_container();
    $container['api']->initialize();
    $container['order']->initialize();
}
add_action( 'plugins_loaded', 'silverbene_api_init' );

/**
 * Jalankan sinkronisasi produk ketika hook cron dipanggil.
 */
add_action( 'silverbene_api_sync_products', function () {
    $container = silverbene_api_container();
    $container['sync']->sync_products();
} );

/**
 * Aktivasi plugin - jadwalkan cron.
 */
function silverbene_api_activate() {
    if ( ! wp_next_scheduled( 'silverbene_api_sync_products' ) ) {
        wp_schedule_event( time(), 'hourly', 'silverbene_api_sync_products' );
    }
}
register_activation_hook( __FILE__, 'silverbene_api_activate' );

/**
 * Deaktivasi plugin - hapus jadwal cron.
 */
function silverbene_api_deactivate() {
    $timestamp = wp_next_scheduled( 'silverbene_api_sync_products' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'silverbene_api_sync_products' );
    }
}
register_deactivation_hook( __FILE__, 'silverbene_api_deactivate' );
