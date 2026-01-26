/**
 * Silverbene WhatsApp Button JavaScript
 *
 * Handles dynamic WhatsApp URL updates for variable products.
 *
 * @package Silverbene_API_Integration
 * @since 1.2.0
 */

(function ($) {
    'use strict';

    /**
     * Initialize WhatsApp button functionality.
     */
    function initWhatsAppButton() {
        const $button = $('.silverbene-whatsapp-button');
        
        if (!$button.length) {
            return;
        }

        // Check if this is a variable product.
        const isVariable = $button.hasClass('silverbene-whatsapp-variable');
        
        if (isVariable) {
            initVariableProductHandler($button);
        }
    }

    /**
     * Initialize handler for variable products.
     *
     * @param {jQuery} $button WhatsApp button element.
     */
    function initVariableProductHandler($button) {
        const $form = $('form.variations_form');
        
        if (!$form.length) {
            return;
        }

        // Store original URL.
        const originalUrl = $button.attr('href');

        // Initially disable button until variation is selected.
        $button.addClass('disabled');

        // Listen for variation change events.
        $form.on('found_variation', function (event, variation) {
            updateWhatsAppUrl($button, variation.variation_id);
        });

        // Reset button when variation is cleared.
        $form.on('reset_data', function () {
            $button.addClass('disabled');
            $button.attr('href', originalUrl);
        });

        // Handle variation selection via select change.
        $form.on('change', '.variations select', function () {
            const variationId = $form.find('input[name="variation_id"]').val();
            
            if (variationId && variationId !== '0') {
                updateWhatsAppUrl($button, variationId);
            } else {
                $button.addClass('disabled');
            }
        });
    }

    /**
     * Update WhatsApp URL with variation data.
     *
     * @param {jQuery} $button     WhatsApp button element.
     * @param {number} variationId Variation ID.
     */
    function updateWhatsAppUrl($button, variationId) {
        const productId = $button.data('product-id');
        
        if (!productId || !variationId) {
            return;
        }

        // Show loading state.
        $button.addClass('loading');

        $.ajax({
            url: silverbeneWhatsApp.ajaxUrl,
            type: 'POST',
            data: {
                action: 'silverbene_get_whatsapp_url',
                nonce: silverbeneWhatsApp.nonce,
                product_id: productId,
                variation_id: variationId
            },
            success: function (response) {
                if (response.success && response.data.url) {
                    $button.attr('href', response.data.url);
                    $button.removeClass('disabled');
                }
            },
            error: function () {
                console.error('Failed to update WhatsApp URL');
            },
            complete: function () {
                $button.removeClass('loading');
            }
        });
    }

    /**
     * Generate WhatsApp message from product data (client-side fallback).
     *
     * @param {jQuery} $button     WhatsApp button element.
     * @param {Object} variation   Variation data.
     * @returns {string} Encoded WhatsApp URL.
     */
    function generateClientSideUrl($button, variation) {
        const phone = $button.data('whatsapp-number');
        const productName = $button.data('product-name');
        const productSku = $button.data('product-sku');
        const productUrl = $button.data('product-url');
        
        // Build variation details.
        let variationDetails = '';
        if (variation.attributes) {
            const parts = [];
            for (const key in variation.attributes) {
                if (variation.attributes.hasOwnProperty(key)) {
                    const attrName = key.replace('attribute_', '').replace('pa_', '').replace(/-/g, ' ');
                    const attrValue = variation.attributes[key];
                    if (attrValue) {
                        parts.push(capitalizeWords(attrName) + ': ' + attrValue);
                    }
                }
            }
            variationDetails = parts.join(', ');
        }

        // Format price.
        const price = variation.display_price ? formatPrice(variation.display_price) : '';

        // Build message with proper line breaks.
        const lines = [];
        lines.push('Hello, I would like to inquire about purchasing the following item:');
        lines.push('');
        lines.push('*Product:* ' + productName);
        
        if (productSku) {
            lines.push('*SKU:* ' + productSku);
        }
        
        if (variationDetails) {
            lines.push('*Specification:* ' + variationDetails);
        }
        
        if (price) {
            lines.push('*Price:* ' + price);
        }
        
        lines.push('*Link:* ' + productUrl);
        lines.push('');
        lines.push('I would appreciate your assistance with this order. Thank you!');

        const message = lines.join('\n');

        return 'https://wa.me/' + phone + '?text=' + encodeURIComponent(message);
    }

    /**
     * Capitalize first letter of each word.
     *
     * @param {string} str String to capitalize.
     * @returns {string} Capitalized string.
     */
    function capitalizeWords(str) {
        return str.replace(/\b\w/g, function (char) {
            return char.toUpperCase();
        });
    }

    /**
     * Format price with currency symbol.
     *
     * @param {number} price Price value.
     * @returns {string} Formatted price.
     */
    function formatPrice(price) {
        // Basic formatting - server-side is more accurate.
        return '$' + parseFloat(price).toFixed(2);
    }

    // Initialize on document ready.
    $(document).ready(function () {
        initWhatsAppButton();
    });

})(jQuery);
