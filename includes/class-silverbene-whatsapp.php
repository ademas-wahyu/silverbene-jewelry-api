<?php
/**
 * WhatsApp Buy Button Handler
 *
 * Handles the WhatsApp Buy Button functionality for WooCommerce products.
 *
 * @package Silverbene_API_Integration
 * @since 1.2.0
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Silverbene_WhatsApp
 *
 * Manages WhatsApp buy button display and message generation.
 */
class Silverbene_WhatsApp
{

    /**
     * Plugin settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->settings = get_option(SILVERBENE_API_SETTINGS_OPTION, array());
    }

    /**
     * Initialize hooks.
     */
    public function initialize()
    {
        if (!$this->is_enabled()) {
            return;
        }

        // Display WhatsApp button on single product page.
        $position = $this->get_button_position();

        if ('replace_add_to_cart' === $position) {
            // Add CSS to hide Add to Cart and Buy it now buttons.
            add_action('wp_head', array($this, 'add_hide_buttons_css'));
            // Add WhatsApp button after variations.
            add_action('woocommerce_single_product_summary', array($this, 'render_whatsapp_button'), 35);
        } else {
            // Add WhatsApp button after add to cart.
            add_action('woocommerce_single_product_summary', array($this, 'render_whatsapp_button'), 35);
        }

        // Enqueue frontend assets.
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Add AJAX handler for variable products.
        add_action('wp_ajax_silverbene_get_whatsapp_url', array($this, 'ajax_get_whatsapp_url'));
        add_action('wp_ajax_nopriv_silverbene_get_whatsapp_url', array($this, 'ajax_get_whatsapp_url'));
    }

    /**
     * Check if WhatsApp button is enabled.
     *
     * @return bool
     */
    public function is_enabled()
    {
        return !empty($this->settings['whatsapp_enabled']) && !empty($this->settings['whatsapp_number']);
    }

    /**
     * Get button position setting.
     *
     * @return string
     */
    private function get_button_position()
    {
        return isset($this->settings['whatsapp_button_position'])
            ? $this->settings['whatsapp_button_position']
            : 'after_add_to_cart';
    }

    /**
     * Get button text.
     *
     * @return string
     */
    private function get_button_text()
    {
        $default_text = __('Buy via WhatsApp', 'silverbene-api-integration');
        return !empty($this->settings['whatsapp_button_text'])
            ? $this->settings['whatsapp_button_text']
            : $default_text;
    }

    /**
     * Get WhatsApp number.
     *
     * @return string
     */
    private function get_whatsapp_number()
    {
        return preg_replace('/[^0-9]/', '', $this->settings['whatsapp_number']);
    }

    /**
     * Add CSS to hide Add to Cart and Buy it now buttons.
     */
    public function add_hide_buttons_css()
    {
        if (!is_product()) {
            return;
        }
        ?>
        <style type="text/css">
            /* Hide Add to Cart button */
            .single_add_to_cart_button,
            .woocommerce-variation-add-to-cart .button,
            form.cart .button[type="submit"],
            /* Hide Buy it now button - common selectors */
            .buy-now-button,
            .buy_now_button,
            .buy-it-now,
            .buyitnow,
            button[name="buy-now"],
            .single-product .button.checkout,
            .woocommerce-buy-now-button,
            /* Flatsome theme */
            .add-to-cart-button .single_add_to_cart_button,
            /* Astra theme */
            .ast-single-product-form .single_add_to_cart_button,
            /* General patterns */
            form.cart button:not(.silverbene-whatsapp-button) {
                display: none !important;
            }

            /* Keep quantity selector visible but hide button next to it */
            .quantity+.single_add_to_cart_button {
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Render WhatsApp button on product page.
     */
    public function render_whatsapp_button()
    {
        global $product;

        if (!$product) {
            return;
        }

        $whatsapp_url = $this->generate_whatsapp_url($product);
        $button_text = esc_html($this->get_button_text());
        $is_variable = $product->is_type('variable');

        ?>
        <div class="silverbene-whatsapp-wrapper">
            <a href="<?php echo esc_attr($whatsapp_url); ?>"
                class="silverbene-whatsapp-button<?php echo $is_variable ? ' silverbene-whatsapp-variable' : ''; ?>"
                target="_blank" rel="noopener noreferrer" data-product-id="<?php echo esc_attr($product->get_id()); ?>"
                data-product-name="<?php echo esc_attr($product->get_name()); ?>"
                data-product-sku="<?php echo esc_attr($product->get_sku()); ?>"
                data-product-url="<?php echo esc_url(get_permalink($product->get_id())); ?>"
                data-whatsapp-number="<?php echo esc_attr($this->get_whatsapp_number()); ?>">
                <svg class="silverbene-whatsapp-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
                    width="20" height="20">
                    <path
                        d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                </svg>
                <span><?php echo $button_text; ?></span>
            </a>
        </div>
        <?php
    }

    /**
     * Generate WhatsApp URL with message.
     *
     * @param WC_Product      $product   Product object.
     * @param WC_Product|null $variation Optional variation object.
     *
     * @return string
     */
    public function generate_whatsapp_url($product, $variation = null)
    {
        $phone = $this->get_whatsapp_number();
        $message = $this->generate_message($product, $variation);

        // Encode message for WhatsApp URL.
        // Use urlencode and then replace + with %20 for proper encoding.
        $encoded_message = urlencode($message);

        return 'https://wa.me/' . $phone . '?text=' . $encoded_message;
    }

    /**
     * Generate WhatsApp message for product.
     *
     * @param WC_Product      $product   Product object.
     * @param WC_Product|null $variation Optional variation object.
     *
     * @return string
     */
    public function generate_message($product, $variation = null)
    {
        $product_name = $product->get_name();
        $sku = $product->get_sku();
        $product_url = get_permalink($product->get_id());

        // Get price.
        if ($variation) {
            $price = wc_price($variation->get_price());
            $variation_attrs = $this->get_variation_attributes_text($variation);
        } else {
            $price = wc_price($product->get_price());
            $variation_attrs = '';
        }

        // Strip HTML from price.
        $price = wp_strip_all_tags(html_entity_decode($price));

        // Build message lines.
        $lines = array();
        $lines[] = 'Hello, I would like to inquire about purchasing the following item:';
        $lines[] = '';
        $lines[] = '*Product:* ' . $product_name;

        if (!empty($sku)) {
            $lines[] = '*SKU:* ' . $sku;
        }

        if (!empty($variation_attrs)) {
            $lines[] = '*Specification:* ' . $variation_attrs;
        }

        $lines[] = '*Price:* ' . $price;
        $lines[] = '*Link:* ' . $product_url;
        $lines[] = '';
        $lines[] = 'I would appreciate your assistance with this order. Thank you!';

        // Join with actual newline character.
        return implode("\n", $lines);
    }

    /**
     * Get formatted variation attributes text.
     *
     * @param WC_Product_Variation $variation Variation object.
     *
     * @return string
     */
    private function get_variation_attributes_text($variation)
    {
        $attributes = $variation->get_variation_attributes();

        if (empty($attributes)) {
            return '';
        }

        $parts = array();

        foreach ($attributes as $key => $value) {
            if (empty($value)) {
                continue;
            }

            // Clean up attribute name.
            $attr_name = str_replace('attribute_', '', $key);
            $attr_name = str_replace(array('pa_', '-', '_'), array('', ' ', ' '), $attr_name);
            $attr_name = ucwords($attr_name);

            // Get term name if it's a taxonomy attribute.
            $taxonomy = str_replace('attribute_', '', $key);
            if (taxonomy_exists($taxonomy)) {
                $term = get_term_by('slug', $value, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $value = $term->name;
                }
            }

            $parts[] = "{$attr_name}: {$value}";
        }

        return implode(', ', $parts);
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_assets()
    {
        if (!is_product()) {
            return;
        }

        wp_enqueue_style(
            'silverbene-whatsapp',
            plugins_url('assets/css/silverbene-whatsapp.css', dirname(__FILE__)),
            array(),
            SILVERBENE_API_VERSION
        );

        wp_enqueue_script(
            'silverbene-whatsapp',
            plugins_url('assets/js/silverbene-whatsapp.js', dirname(__FILE__)),
            array('jquery'),
            SILVERBENE_API_VERSION,
            true
        );

        wp_localize_script(
            'silverbene-whatsapp',
            'silverbeneWhatsApp',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('silverbene_whatsapp_nonce'),
            )
        );
    }

    /**
     * AJAX handler for getting WhatsApp URL with variation.
     */
    public function ajax_get_whatsapp_url()
    {
        check_ajax_referer('silverbene_whatsapp_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product ID'));
        }

        $product = wc_get_product($product_id);
        $variation = $variation_id ? wc_get_product($variation_id) : null;

        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found'));
        }

        $url = $this->generate_whatsapp_url($product, $variation);

        wp_send_json_success(array('url' => $url));
    }
}
