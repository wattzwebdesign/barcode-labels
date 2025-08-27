<?php
/**
 * Plugin Name: WooCommerce Barcode Labels
 * Plugin URI: https://codewattz.com
 * Description: Print customizable barcode labels for WooCommerce products with bulk printing support
 * Version: 1.0.0
 * Author: Code Wattz
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.5
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_BARCODE_LABELS_VERSION', '1.0.0');
define('WC_BARCODE_LABELS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_BARCODE_LABELS_PLUGIN_PATH', plugin_dir_path(__FILE__));

class WC_Barcode_Labels {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        $this->init_hooks();
        $this->init_admin_menu();
        $this->init_bulk_actions();
        $this->enqueue_assets();
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>WooCommerce Barcode Labels</strong> requires WooCommerce to be installed and active.</p></div>';
    }
    
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    private function init_hooks() {
        add_action('wp_ajax_get_label_preview', array($this, 'ajax_get_label_preview'));
        add_action('wp_ajax_generate_labels_pdf', array($this, 'ajax_generate_labels_pdf'));
        add_action('wp_ajax_save_label_settings', array($this, 'ajax_save_label_settings'));
    }
    
    private function init_admin_menu() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Barcode Labels',
            'Barcode Labels',
            'manage_woocommerce',
            'woo-barcode-labels',
            array($this, 'admin_page')
        );
    }
    
    private function init_bulk_actions() {
        add_filter('bulk_actions-edit-product', array($this, 'add_bulk_action'));
        add_filter('handle_bulk_actions-edit-product', array($this, 'handle_bulk_action'), 10, 3);
    }
    
    public function add_bulk_action($bulk_actions) {
        $bulk_actions['print_barcode_labels'] = 'Print Barcode Labels';
        return $bulk_actions;
    }
    
    public function handle_bulk_action($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'print_barcode_labels') {
            return $redirect_to;
        }
        
        $redirect_to = admin_url('admin.php?page=woo-barcode-labels&product_ids=' . implode(',', $post_ids));
        
        return $redirect_to;
    }
    
    
    private function enqueue_assets() {
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'woo-barcode-labels') !== false) {
            wp_enqueue_style('wc-barcode-labels-admin', WC_BARCODE_LABELS_PLUGIN_URL . 'assets/admin.css', array(), WC_BARCODE_LABELS_VERSION);
            wp_enqueue_script('wc-barcode-labels-admin', WC_BARCODE_LABELS_PLUGIN_URL . 'assets/admin.js', array('jquery'), WC_BARCODE_LABELS_VERSION, true);
            wp_localize_script('wc-barcode-labels-admin', 'wcBarcodeLabels', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_barcode_labels_nonce')
            ));
        }
    }
    
    public function admin_page() {
        $product_ids = isset($_GET['product_ids']) ? sanitize_text_field($_GET['product_ids']) : '';
        $products = array();
        
        if (!empty($product_ids)) {
            $ids = array_map('intval', explode(',', $product_ids));
            foreach ($ids as $id) {
                $product = wc_get_product($id);
                if ($product) {
                    $products[] = $product;
                }
            }
        }
        
        $settings = $this->get_label_settings();
        
        ?>
        <div class="wrap">
            <h1>WooCommerce Barcode Labels</h1>
            
            <?php if (empty($products)): ?>
                <div class="notice notice-info">
                    <p>No products selected. Please select products from the <a href="<?php echo admin_url('edit.php?post_type=product'); ?>">Products page</a> using bulk actions.</p>
                </div>
            <?php else: ?>
                <div id="label-configurator">
                    <div class="label-config-container">
                        <div class="config-panel">
                            <h2>Label Configuration</h2>
                            
                            <form id="label-settings-form">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="show_title">Show Product Title</label></th>
                                        <td>
                                            <input type="checkbox" id="show_title" name="show_title" value="1" <?php checked($settings['show_title'], 1); ?>>
                                            <label for="show_title">Display product title on label</label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="show_price">Show Price</label></th>
                                        <td>
                                            <input type="checkbox" id="show_price" name="show_price" value="1" <?php checked($settings['show_price'], 1); ?>>
                                            <label for="show_price">Display product price on label</label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="show_sku">Show SKU Text</label></th>
                                        <td>
                                            <input type="checkbox" id="show_sku" name="show_sku" value="1" <?php checked($settings['show_sku'], 1); ?>>
                                            <label for="show_sku">Display SKU as text on label</label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="show_barcode">Show Barcode</label></th>
                                        <td>
                                            <input type="checkbox" id="show_barcode" name="show_barcode" value="1" <?php checked($settings['show_barcode'], 1); ?>>
                                            <label for="show_barcode">Display barcode (using SKU) on label</label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="show_consignor">Show Consignor Number</label></th>
                                        <td>
                                            <input type="checkbox" id="show_consignor" name="show_consignor" value="1" <?php checked($settings['show_consignor'], 1); ?>>
                                            <label for="show_consignor">Display consignor number (from WooConsign plugin)</label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="font_size">Font Size</label></th>
                                        <td>
                                            <select id="font_size" name="font_size">
                                                <option value="8" <?php selected($settings['font_size'], '8'); ?>>8pt</option>
                                                <option value="9" <?php selected($settings['font_size'], '9'); ?>>9pt</option>
                                                <option value="10" <?php selected($settings['font_size'], '10'); ?>>10pt</option>
                                                <option value="11" <?php selected($settings['font_size'], '11'); ?>>11pt</option>
                                                <option value="12" <?php selected($settings['font_size'], '12'); ?>>12pt</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                                
                                <button type="button" id="preview-labels" class="button">Preview Labels</button>
                                <button type="button" id="save-settings" class="button button-secondary">Save Settings</button>
                            </form>
                        </div>
                        
                        <div class="preview-panel">
                            <h2>Label Preview</h2>
                            <div id="label-preview">
                                <div class="label-dimensions">
                                    <strong>Label Size:</strong> 2.125" × 1.125" (horizontal)
                                </div>
                                <div id="preview-content">
                                    Click "Preview Labels" to see how your labels will look.
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="product-list">
                        <h2>Selected Products (<?php echo count($products); ?>)</h2>
                        <div class="products-grid">
                            <?php foreach ($products as $product): ?>
                                <div class="product-item">
                                    <?php if ($product->get_image_id()): ?>
                                        <?php echo wp_get_attachment_image($product->get_image_id(), 'thumbnail'); ?>
                                    <?php endif; ?>
                                    <div class="product-details">
                                        <strong><?php echo esc_html($product->get_name()); ?></strong><br>
                                        <small><?php echo esc_html($product->get_sku() ?: 'N/A'); ?></small><br>
                                        <small>Price: <?php echo wc_price($product->get_price()); ?></small>
                                        <?php 
                                        $consignor_number = $this->get_consignor_number($product->get_id());
                                        if ($consignor_number): ?>
                                        <br><small>Consignor: <?php echo esc_html($consignor_number); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="print-actions">
                        <button type="button" id="generate-pdf" class="button button-primary button-large">Generate PDF Labels</button>
                        <input type="hidden" id="product-ids" value="<?php echo esc_attr($product_ids); ?>">
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function ajax_get_label_preview() {
        check_ajax_referer('wc_barcode_labels_nonce', 'nonce');
        
        $settings = array(
            'show_title' => isset($_POST['show_title']) ? 1 : 0,
            'show_price' => isset($_POST['show_price']) ? 1 : 0,
            'show_sku' => isset($_POST['show_sku']) ? 1 : 0,
            'show_barcode' => isset($_POST['show_barcode']) ? 1 : 0,
            'show_consignor' => isset($_POST['show_consignor']) ? 1 : 0,
            'font_size' => sanitize_text_field($_POST['font_size'])
        );
        
        $product_ids = array_map('intval', explode(',', sanitize_text_field($_POST['product_ids'])));
        $preview_html = $this->generate_label_preview($product_ids[0], $settings);
        
        wp_send_json_success(array('preview' => $preview_html));
    }
    
    public function ajax_save_label_settings() {
        check_ajax_referer('wc_barcode_labels_nonce', 'nonce');
        
        $settings = array(
            'show_title' => isset($_POST['show_title']) ? 1 : 0,
            'show_price' => isset($_POST['show_price']) ? 1 : 0,
            'show_sku' => isset($_POST['show_sku']) ? 1 : 0,
            'show_barcode' => isset($_POST['show_barcode']) ? 1 : 0,
            'show_consignor' => isset($_POST['show_consignor']) ? 1 : 0,
            'font_size' => sanitize_text_field($_POST['font_size'])
        );
        
        update_option('wc_barcode_labels_settings', $settings);
        
        wp_send_json_success(array('message' => 'Settings saved successfully'));
    }
    
    public function ajax_generate_labels_pdf() {
        check_ajax_referer('wc_barcode_labels_nonce', 'nonce');
        
        $product_ids = array_map('intval', explode(',', sanitize_text_field($_POST['product_ids'])));
        $settings = array(
            'show_title' => isset($_POST['show_title']) ? 1 : 0,
            'show_price' => isset($_POST['show_price']) ? 1 : 0,
            'show_sku' => isset($_POST['show_sku']) ? 1 : 0,
            'show_barcode' => isset($_POST['show_barcode']) ? 1 : 0,
            'show_consignor' => isset($_POST['show_consignor']) ? 1 : 0,
            'font_size' => intval($_POST['font_size'])
        );
        
        $pdf_url = $this->generate_pdf_labels($product_ids, $settings);
        
        if ($pdf_url) {
            wp_send_json_success(array('pdf_url' => $pdf_url));
        } else {
            wp_send_json_error(array('message' => 'Failed to generate PDF'));
        }
    }
    
    private function generate_label_preview($product_id, $settings) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return '<p>Product not found</p>';
        }
        
        $consignor_number = $this->get_consignor_number($product_id);
        
        $html = '<div class="label-preview-sample" style="width: 153px; height: 81px; border: 1px solid #333; padding: 4px; font-size: ' . $settings['font_size'] . 'px; font-family: Arial, sans-serif; position: relative; text-align: center; background: #fff; display: flex; flex-direction: column; justify-content: center;">';
        
        $content_parts = array();
        
        if ($settings['show_title']) {
            $title = $product->get_name();
            if (strlen($title) > 28) {
                $title = substr($title, 0, 25) . '...';
            }
            $content_parts[] = '<div style="font-weight: bold; font-size: ' . ($settings['font_size'] + 2) . 'px; margin-bottom: 2px; line-height: 1.1;">' . esc_html($title) . '</div>';
        }
        
        if ($settings['show_price']) {
            $price = html_entity_decode(strip_tags(wc_price($product->get_price())));
            $content_parts[] = '<div style="font-weight: bold; font-size: ' . ($settings['font_size'] + 1) . 'px; margin-bottom: 2px;">' . esc_html($price) . '</div>';
        }
        
        if ($settings['show_consignor'] && $consignor_number) {
            $content_parts[] = '<div style="font-size: ' . ($settings['font_size'] - 1) . 'px; margin-bottom: 2px;">Consignor: ' . esc_html($consignor_number) . '</div>';
        }
        
        if ($settings['show_barcode'] && $product->get_sku()) {
            $content_parts[] = '<div style="margin: 2px 0; flex-grow: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                <div style="font-family: monospace; font-size: 6px; line-height: 4px; background: #000; color: #fff; padding: 1px 4px; margin-bottom: 1px;">||||| BARCODE |||||</div>';
            
            if ($settings['show_sku']) {
                $sku = $product->get_sku();
                $content_parts[] = '<div style="font-size: ' . ($settings['font_size'] - 2) . 'px; color: #666;">' . esc_html($sku) . '</div>';
            }
            
            $content_parts[] = '</div>';
        } else if ($settings['show_sku']) {
            $sku = $product->get_sku() ?: 'N/A';
            $content_parts[] = '<div style="font-size: ' . ($settings['font_size'] - 2) . 'px; color: #666;">' . esc_html($sku) . '</div>';
        }
        
        $html .= implode('', $content_parts);
        $html .= '</div>';
        
        return $html;
    }
    
    private function generate_pdf_labels($product_ids, $settings) {
        require_once(WC_BARCODE_LABELS_PLUGIN_PATH . 'includes/class-pdf-generator.php');
        
        $pdf_generator = new WC_Barcode_Labels_PDF_Generator();
        return $pdf_generator->generate($product_ids, $settings);
    }
    
    private function get_consignor_number($product_id) {
        $consignor_id = get_post_meta($product_id, '_consignor_id', true);
        
        if ($consignor_id) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'consignors';
            
            $consignor = $wpdb->get_row($wpdb->prepare(
                "SELECT consignor_number FROM $table_name WHERE id = %d",
                $consignor_id
            ));
            
            return $consignor ? $consignor->consignor_number : null;
        }
        
        return null;
    }
    
    private function get_label_settings() {
        return get_option('wc_barcode_labels_settings', array(
            'show_title' => 1,
            'show_price' => 1,
            'show_sku' => 1,
            'show_barcode' => 1,
            'show_consignor' => 1,
            'font_size' => '10'
        ));
    }
    
    public static function activate() {
        if (!wp_next_scheduled('wc_barcode_labels_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wc_barcode_labels_cleanup');
        }
    }
    
    public static function deactivate() {
        wp_clear_scheduled_hook('wc_barcode_labels_cleanup');
    }
}

new WC_Barcode_Labels();

register_activation_hook(__FILE__, array('WC_Barcode_Labels', 'activate'));
register_deactivation_hook(__FILE__, array('WC_Barcode_Labels', 'deactivate'));