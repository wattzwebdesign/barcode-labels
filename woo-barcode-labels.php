<?php
/**
 * Plugin Name: WooCommerce Barcode Labels
 * Plugin URI: https://codewattz.com
 * Description: Print customizable barcode labels for WooCommerce products with bulk printing support
 * Version: 1.0.1
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

define('WC_BARCODE_LABELS_VERSION', '1.0.1');
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
        
        $this->create_database_tables();
        $this->init_hooks();
        $this->init_admin_menu();
        $this->init_bulk_actions();
        $this->enqueue_assets();
        
        add_action('wc_barcode_labels_cleanup', array($this, 'cleanup_old_labels'));
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
        // Add main menu for Barcode Labels
        add_menu_page(
            'Barcode Labels',
            'Barcode Labels',
            'manage_woocommerce',
            'woo-barcode-labels',
            array($this, 'admin_page'),
            'dashicons-tag',
            56 // Position after WooCommerce
        );
        
        // Add submenu for Generate Labels (same as main menu)
        add_submenu_page(
            'woo-barcode-labels',
            'Generate Labels',
            'Generate Labels',
            'manage_woocommerce',
            'woo-barcode-labels',
            array($this, 'admin_page')
        );
        
        // Add submenu for Label History
        add_submenu_page(
            'woo-barcode-labels',
            'Label History',
            'Label History',
            'manage_woocommerce',
            'woo-barcode-labels-history',
            array($this, 'history_admin_page')
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
        
        $pdf_result = $this->generate_pdf_labels($product_ids, $settings);
        
        if ($pdf_result) {
            $this->save_label_history($product_ids, $settings, $pdf_result);
            wp_send_json_success(array('pdf_url' => $pdf_result));
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
    
    private function create_database_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'barcode_label_history';
        $charset_collate = $wpdb->get_charset_collate();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                pdf_filename varchar(255) NOT NULL,
                pdf_url varchar(500) NOT NULL,
                product_count int NOT NULL,
                product_ids text NOT NULL,
                product_names text NOT NULL,
                settings text NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY created_at (created_at)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    private function save_label_history($product_ids, $settings, $pdf_url) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'barcode_label_history';
        
        $product_names = array();
        $consignor_numbers = array();
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product_names[] = $product->get_name();
                $consignor_number = $this->get_consignor_number($product_id);
                if ($consignor_number) {
                    $consignor_numbers[] = $consignor_number;
                } else {
                    $consignor_numbers[] = 'N/A';
                }
            }
        }
        
        $filename = basename($pdf_url);
        
        $wpdb->insert(
            $table_name,
            array(
                'pdf_filename' => $filename,
                'pdf_url' => $pdf_url,
                'product_count' => count($product_ids),
                'product_ids' => implode(',', $product_ids),
                'product_names' => wp_json_encode($consignor_numbers),
                'settings' => wp_json_encode($settings)
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s')
        );
    }
    
    public function history_admin_page() {
        global $wpdb;
        
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $history_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($action === 'delete' && $history_id) {
            $this->delete_label_history($history_id);
            echo '<div class="notice notice-success is-dismissible"><p>Label history deleted successfully.</p></div>';
        }
        
        if ($action === 'cleanup') {
            $deleted_count = $this->cleanup_old_labels();
            echo '<div class="notice notice-success is-dismissible"><p>' . $deleted_count . ' old label(s) cleaned up successfully.</p></div>';
        }
        
        $table_name = $wpdb->prefix . 'barcode_label_history';
        
        $per_page = 20;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;
        
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_items / $per_page);
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        ?>
        <div class="wrap">
            <h1>Label History 
                <a href="<?php echo admin_url('admin.php?page=woo-barcode-labels-history&action=cleanup'); ?>" class="page-title-action">Cleanup Old Labels</a>
            </h1>
            
            <?php if (empty($results)): ?>
                <div class="notice notice-info">
                    <p>No label history found. <a href="<?php echo admin_url('admin.php?page=woo-barcode-labels'); ?>">Generate your first labels</a>.</p>
                </div>
            <?php else: ?>
                <div class="tablenav top">
                    <div class="tablenav-pages">
                        <?php if ($total_pages > 1): ?>
                            <span class="displaying-num"><?php echo $total_items; ?> items</span>
                            <span class="pagination-links">
                                <?php if ($paged > 1): ?>
                                    <a class="first-page button" href="<?php echo admin_url('admin.php?page=woo-barcode-labels-history'); ?>">&laquo;</a>
                                    <a class="prev-page button" href="<?php echo admin_url('admin.php?page=woo-barcode-labels-history&paged=' . ($paged - 1)); ?>">&lsaquo;</a>
                                <?php endif; ?>
                                <span class="paging-input">
                                    <span class="tablenav-paging-text"><?php echo $paged; ?> of <span class="total-pages"><?php echo $total_pages; ?></span></span>
                                </span>
                                <?php if ($paged < $total_pages): ?>
                                    <a class="next-page button" href="<?php echo admin_url('admin.php?page=woo-barcode-labels-history&paged=' . ($paged + 1)); ?>">&rsaquo;</a>
                                    <a class="last-page button" href="<?php echo admin_url('admin.php?page=woo-barcode-labels-history&paged=' . $total_pages); ?>">&raquo;</a>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <style>
                    .consignor-tooltip {
                        position: relative;
                        cursor: help;
                        display: inline-block;
                    }
                    .consignor-tooltip .tooltip-content {
                        display: none;
                        position: absolute;
                        background: #333;
                        color: #fff;
                        padding: 10px;
                        border-radius: 4px;
                        z-index: 1000;
                        white-space: nowrap;
                        bottom: 125%;
                        left: 50%;
                        transform: translateX(-50%);
                        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                    }
                    .consignor-tooltip:hover .tooltip-content {
                        display: block;
                    }
                    .consignor-tooltip .tooltip-content::after {
                        content: "";
                        position: absolute;
                        top: 100%;
                        left: 50%;
                        margin-left: -5px;
                        border-width: 5px;
                        border-style: solid;
                        border-color: #333 transparent transparent transparent;
                    }
                    .consignor-tooltip .tooltip-content .consignor-item {
                        display: block;
                        margin: 2px 0;
                    }
                    .warning-badge {
                        display: inline-block;
                        background: #ffa500;
                        color: #fff;
                        padding: 2px 6px;
                        border-radius: 3px;
                        font-size: 11px;
                        margin-left: 5px;
                    }
                    .warning-badge.critical {
                        background: #dc3232;
                    }
                </style>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column">Generated</th>
                            <th scope="col" class="manage-column">Consignors</th>
                            <th scope="col" class="manage-column">Overview</th>
                            <th scope="col" class="manage-column">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): 
                            $consignor_numbers = json_decode($row->product_names, true);
                            $settings = json_decode($row->settings, true);
                            $file_exists = $this->pdf_file_exists($row->pdf_url);
                            $age_days = floor((time() - strtotime($row->created_at)) / (24 * 60 * 60));
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('M j, Y g:i A', strtotime($row->created_at)); ?></strong><br>
                                    <small><?php echo $age_days; ?> day<?php echo $age_days != 1 ? 's' : ''; ?> ago</small>
                                    <?php if ($age_days >= 7): ?>
                                        <span class="warning-badge critical">Expires today!</span>
                                    <?php elseif ($age_days == 6): ?>
                                        <span class="warning-badge">Expires tomorrow</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo $row->product_count; ?> label<?php echo $row->product_count != 1 ? 's' : ''; ?></strong><br>
                                    <?php if (count($consignor_numbers) > 2): ?>
                                        <small class="consignor-tooltip">
                                            <?php echo esc_html(implode(', ', array_slice($consignor_numbers, 0, 2))); ?>...
                                            <div class="tooltip-content">
                                                <strong>All Consignors:</strong>
                                                <?php foreach ($consignor_numbers as $consignor): ?>
                                                    <span class="consignor-item"><?php echo esc_html($consignor); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </small>
                                    <?php else: ?>
                                        <small><?php echo esc_html(implode(', ', $consignor_numbers)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $enabled_features = array();
                                    if ($settings['show_title']) $enabled_features[] = 'Title';
                                    if ($settings['show_price']) $enabled_features[] = 'Price';
                                    if ($settings['show_sku']) $enabled_features[] = 'SKU';
                                    if ($settings['show_barcode']) $enabled_features[] = 'Barcode';
                                    if ($settings['show_consignor']) $enabled_features[] = 'Consignor';
                                    echo esc_html(implode(', ', $enabled_features));
                                    ?>
                                </td>
                                <td>
                                    <?php if ($file_exists): ?>
                                        <a href="<?php echo esc_url($row->pdf_url); ?>" class="button button-primary button-small" target="_blank">View PDF</a>
                                        <a href="<?php echo admin_url('admin.php?page=woo-barcode-labels&product_ids=' . $row->product_ids); ?>" class="button button-small">Reprint</a>
                                    <?php else: ?>
                                        <span class="description">File expired</span><br>
                                        <a href="<?php echo admin_url('admin.php?page=woo-barcode-labels&product_ids=' . $row->product_ids); ?>" class="button button-small">Regenerate</a>
                                    <?php endif; ?>
                                    <br><br>
                                    <a href="<?php echo admin_url('admin.php?page=woo-barcode-labels-history&action=delete&id=' . $row->id); ?>" class="button button-small" onclick="return confirm('Are you sure you want to delete this record?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function pdf_file_exists($url) {
        $file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $url);
        return file_exists($file_path);
    }
    
    private function delete_label_history($history_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'barcode_label_history';
        
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $history_id
        ));
        
        if ($record) {
            $file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $record->pdf_url);
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            $wpdb->delete($table_name, array('id' => $history_id), array('%d'));
        }
    }
    
    private function cleanup_old_labels() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'barcode_label_history';
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        
        $old_records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE created_at < %s",
            $seven_days_ago
        ));
        
        $deleted_count = 0;
        foreach ($old_records as $record) {
            $file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $record->pdf_url);
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $deleted_count++;
        }
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE created_at < %s",
            $seven_days_ago
        ));
        
        return $deleted_count;
    }
    
    public static function activate() {
        $instance = new self();
        $instance->create_database_tables();
        
        if (!wp_next_scheduled('wc_barcode_labels_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wc_barcode_labels_cleanup');
        }
        
        add_action('wc_barcode_labels_cleanup', array($instance, 'cleanup_old_labels'));
    }
    
    public static function deactivate() {
        wp_clear_scheduled_hook('wc_barcode_labels_cleanup');
    }
}

new WC_Barcode_Labels();

register_activation_hook(__FILE__, array('WC_Barcode_Labels', 'activate'));
register_deactivation_hook(__FILE__, array('WC_Barcode_Labels', 'deactivate'));