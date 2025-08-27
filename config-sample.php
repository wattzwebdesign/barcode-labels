<?php
/**
 * Sample Configuration for WooCommerce Barcode Labels
 * 
 * Copy this file to config.php and modify as needed.
 * This file contains default settings that can override plugin defaults.
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    // Default label settings
    'default_settings' => array(
        'show_title' => true,
        'show_price' => true,
        'show_sku' => true,
        'show_barcode' => true,
        'show_consignor' => true,
        'font_size' => 10
    ),
    
    // Label dimensions in inches
    'label_dimensions' => array(
        'width' => 2.125,
        'height' => 1.125,
        'orientation' => 'landscape'
    ),
    
    // PDF settings
    'pdf_settings' => array(
        'margin_top' => 0.05,
        'margin_left' => 0.05,
        'margin_right' => 0.05,
        'margin_bottom' => 0.05
    ),
    
    // Barcode settings
    'barcode_settings' => array(
        'type' => 'code128',
        'width' => 300,
        'height' => 50,
        'max_width_inches' => 1.5,
        'max_height_inches' => 0.3
    ),
    
    // Text formatting
    'text_settings' => array(
        'title_max_length' => 28,
        'font_family' => 'helvetica',
        'line_height_multiplier' => 1.2
    ),
    
    // File cleanup settings
    'cleanup' => array(
        'temp_file_lifetime' => 3600, // 1 hour in seconds
        'cleanup_on_shutdown' => true
    ),
    
    // Advanced settings
    'advanced' => array(
        'memory_limit' => '256M',
        'max_execution_time' => 300,
        'enable_debug' => false,
        'cache_barcodes' => true
    )
);