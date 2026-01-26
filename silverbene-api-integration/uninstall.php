<?php
/**
 * Uninstall routines for the Silverbene API Integration plugin.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options.
delete_option('silverbene_api_settings');
delete_option('silverbene_last_sync_status');

// Remove transient notices.
delete_transient('silverbene_sync_admin_notice');

// Clear scheduled cron event.
wp_clear_scheduled_hook('silverbene_api_sync_products');

// Remove custom meta keys created by the plugin.
delete_post_meta_by_key('_silverbene_order_id');
delete_post_meta_by_key('_silverbene_option_id');
delete_post_meta_by_key('_silverbene_product_id');

