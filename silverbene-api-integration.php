<?php
/**
 * Plugin Name: Silverbene API Integration
 * Description: Integrasi API Silverbene untuk menambahkan produk dan mengelola pesanan di WooCommerce.
 * Version: 1.0.0
 * Author: Nama Anda
 * Text Domain: silverbene-api-integration
 */

// Mencegah akses langsung ke file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Menentukan path plugin
define( 'SILVERBENE_API_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// Memuat file kelas utama
require_once SILVERBENE_API_PLUGIN_PATH . 'includes/class-silverbene-api.php';
require_once SILVERBENE_API_PLUGIN_PATH . 'includes/class-silverbene-sync.php';
require_once SILVERBENE_API_PLUGIN_PATH . 'includes/class-silverbene-order.php';

// Hook untuk inisialisasi plugin
function silverbene_api_init() {
    // Inisialisasi kelas API
    $api = new Silverbene_API();
    $api->initialize();
}
add_action( 'plugins_loaded', 'silverbene_api_init' );
