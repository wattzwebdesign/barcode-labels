<?php
/**
 * Plugin Name: WooCommerce Barcode Labels
 * Plugin URI: https://codewattz.com
 * Description: Print customizable barcode labels for WooCommerce products with bulk printing support
 * Version: 1.1.0
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

define('WC_BARCODE_LABELS_VERSION', '1.1.0');
define('WC_BARCODE_LABELS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_BARCODE_LABELS_PLUGIN_PATH', plugin_dir_path(__FILE__));

class WC_Barcode_Labels {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
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
    
    public function admin_init() {
        if (class_exists('WooCommerce') && is_admin()) {
            $this->init_product_columns();
            add_action('woocommerce_product_options_general_product_data', array($this, 'add_queue_button_to_product'));
        }
    }
    
    public function add_queue_button_to_product() {
        global $post;
        $product_id = $post->ID;
        $in_queue = $this->is_product_in_queue($product_id);
        ?>
        <div class="options_group">
            <p class="form-field">
                <label><?php _e('Barcode Label Queue', 'woocommerce'); ?></label>
                <button type="button" class="button button-secondary add-to-queue-product-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
                    <span class="dashicons dashicons-tag" style="margin-top: 3px;"></span>
                    <?php echo $in_queue ? 'Remove from Queue' : 'Add to Queue'; ?>
                </button>
                <span class="queue-status-message" style="margin-left: 10px; display: none;"></span>
            </p>
        </div>
        <?php
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
        add_action('wp_ajax_add_to_label_queue', array($this, 'ajax_add_to_label_queue'));
        add_action('wp_ajax_remove_from_label_queue', array($this, 'ajax_remove_from_label_queue'));
        add_action('wp_ajax_clear_label_queue', array($this, 'ajax_clear_label_queue'));
        add_action('wp_ajax_get_label_queue', array($this, 'ajax_get_label_queue'));
        add_action('wp_ajax_generate_consignor_labels_pdf', array($this, 'ajax_generate_consignor_labels_pdf'));
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
        
        // Add submenu for Backlog Tags
        add_submenu_page(
            'woo-barcode-labels',
            'Backlog Tags',
            'Backlog Tags',
            'manage_woocommerce',
            'woo-barcode-labels-consignor',
            array($this, 'consignor_admin_page')
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
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
    }
    
    private function init_product_columns() {
        add_filter('manage_product_posts_columns', array($this, 'add_label_printed_column'), 15);
        add_action('manage_product_posts_custom_column', array($this, 'display_label_printed_column'), 10, 2);
        add_filter('manage_edit-product_sortable_columns', array($this, 'make_label_printed_column_sortable'));
        add_filter('manage_product_posts_columns', array($this, 'add_queue_column'), 5);
        add_action('manage_product_posts_custom_column', array($this, 'display_queue_column'), 10, 2);
        add_action('manage_posts_extra_tablenav', array($this, 'add_queue_button_to_products_page'), 10, 1);
    }
    
    public function add_queue_button_to_products_page($which) {
        global $typenow;
        
        if ($typenow === 'product' && $which === 'top') {
            $queue_count = count($this->get_queue_products());
            ?>
            <div class="alignleft actions" style="margin-left: 10px;">
                <button type="button" class="button" id="load-queue-from-products" style="height: 32px; display: inline-flex; align-items: center; gap: 5px;">
                    <span class="dashicons dashicons-tag" style="font-size: 16px;"></span>
                    Load Label Queue
                    <span class="queue-badge" style="background: #0073aa; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold;"><?php echo $queue_count; ?></span>
                </button>
            </div>
            <?php
        }
    }
    
    public function add_bulk_action($bulk_actions) {
        $bulk_actions['add_to_label_queue'] = 'Add to Label Queue';
        $bulk_actions['print_barcode_labels'] = 'Print Barcode Labels';
        $bulk_actions['mark_labels_printed'] = 'Mark Labels as Printed';
        $bulk_actions['mark_labels_not_printed'] = 'Mark Labels as Not Printed';
        return $bulk_actions;
    }
    
    public function handle_bulk_action($redirect_to, $doaction, $post_ids) {
        if ($doaction === 'add_to_label_queue') {
            global $wpdb;
            $table_name = $wpdb->prefix . 'barcode_label_queue';
            $user_id = get_current_user_id();
            $added_count = 0;
            
            foreach ($post_ids as $post_id) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND product_id = %d",
                    $user_id,
                    $post_id
                ));
                
                if ($existing == 0) {
                    $wpdb->insert(
                        $table_name,
                        array('user_id' => $user_id, 'product_id' => $post_id),
                        array('%d', '%d')
                    );
                    $added_count++;
                }
            }
            
            $redirect_to = add_query_arg('bulk_queue_added', $added_count, $redirect_to);
        } elseif ($doaction === 'print_barcode_labels') {
            $redirect_to = admin_url('admin.php?page=woo-barcode-labels&product_ids=' . implode(',', $post_ids));
        } elseif ($doaction === 'mark_labels_printed') {
            $marked_count = 0;
            foreach ($post_ids as $post_id) {
                update_post_meta($post_id, '_barcode_label_printed', 'yes');
                update_post_meta($post_id, '_barcode_label_printed_date', current_time('mysql'));
                $marked_count++;
            }
            $redirect_to = add_query_arg('bulk_labels_marked', $marked_count, $redirect_to);
        } elseif ($doaction === 'mark_labels_not_printed') {
            $unmarked_count = 0;
            foreach ($post_ids as $post_id) {
                delete_post_meta($post_id, '_barcode_label_printed');
                delete_post_meta($post_id, '_barcode_label_printed_date');
                $unmarked_count++;
            }
            $redirect_to = add_query_arg('bulk_labels_unmarked', $unmarked_count, $redirect_to);
        }
        
        return $redirect_to;
    }
    
    public function add_label_printed_column($columns) {
        $columns['label_printed'] = 'Label Printed';
        return $columns;
    }
    
    public function make_label_printed_column_sortable($columns) {
        $columns['label_printed'] = 'label_printed';
        return $columns;
    }
    
    public function display_label_printed_column($column, $post_id) {
        if ($column === 'label_printed') {
            $printed = get_post_meta($post_id, '_barcode_label_printed', true);
            if ($printed === 'yes') {
                $printed_date = get_post_meta($post_id, '_barcode_label_printed_date', true);
                $formatted_date = $printed_date ? date('M j, Y', strtotime($printed_date)) : '';
                echo '<span style="color: #46b450; font-weight: bold; font-size: 16px;" title="Printed on: ' . esc_attr($formatted_date) . '">✓</span>';
            }
        }
    }
    
    public function add_queue_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ($key === 'cb') {
                $new_columns[$key] = $value;
                $new_columns['add_to_queue'] = '<span class="dashicons dashicons-tag" title="Add to Queue"></span>';
            } else {
                $new_columns[$key] = $value;
            }
        }
        return $new_columns;
    }
    
    public function display_queue_column($column, $post_id) {
        if ($column === 'add_to_queue') {
            $in_queue = $this->is_product_in_queue($post_id);
            $icon_class = $in_queue ? 'dashicons-yes' : 'dashicons-tag';
            $icon_color = $in_queue ? '#46b450' : '#666';
            echo '<span class="add-to-queue-btn" data-product-id="' . esc_attr($post_id) . '" style="cursor: pointer; display: inline-block;">';
            echo '<span class="dashicons ' . $icon_class . '" style="color: ' . $icon_color . '; font-size: 18px;"></span>';
            echo '</span>';
        }
    }
    
    public function bulk_action_admin_notice() {
        if (!empty($_REQUEST['bulk_queue_added'])) {
            $added_count = intval($_REQUEST['bulk_queue_added']);
            printf(
                '<div id="message" class="updated notice is-dismissible"><p>' .
                _n(
                    'Successfully added %d product to label queue.',
                    'Successfully added %d products to label queue.',
                    $added_count
                ) . '</p></div>',
                $added_count
            );
        }
        
        if (!empty($_REQUEST['bulk_labels_marked'])) {
            $marked_count = intval($_REQUEST['bulk_labels_marked']);
            printf(
                '<div id="message" class="updated notice is-dismissible"><p>' .
                _n(
                    'Successfully marked %d product label as printed.',
                    'Successfully marked %d product labels as printed.',
                    $marked_count
                ) . '</p></div>',
                $marked_count
            );
        }
        
        if (!empty($_REQUEST['bulk_labels_unmarked'])) {
            $unmarked_count = intval($_REQUEST['bulk_labels_unmarked']);
            printf(
                '<div id="message" class="updated notice is-dismissible"><p>' .
                _n(
                    'Successfully marked %d product label as not printed.',
                    'Successfully marked %d product labels as not printed.',
                    $unmarked_count
                ) . '</p></div>',
                $unmarked_count
            );
        }
    }
    
    private function enqueue_assets() {
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    public function admin_enqueue_scripts($hook) {
        $is_barcode_page = strpos($hook, 'woo-barcode-labels') !== false;
        $is_product_page = ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'product');
        
        $is_product_edit = false;
        if ($hook === 'post.php' && isset($_GET['post'])) {
            $is_product_edit = get_post_type($_GET['post']) === 'product';
        } elseif ($hook === 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'product') {
            $is_product_edit = true;
        }
        
        if ($is_barcode_page || $is_product_page || $is_product_edit) {
            wp_enqueue_style('wc-barcode-labels-admin', WC_BARCODE_LABELS_PLUGIN_URL . 'assets/admin.css', array(), WC_BARCODE_LABELS_VERSION);
            wp_enqueue_script('wc-barcode-labels-admin', WC_BARCODE_LABELS_PLUGIN_URL . 'assets/admin.js', array('jquery'), WC_BARCODE_LABELS_VERSION, true);
            wp_localize_script('wc-barcode-labels-admin', 'wcBarcodeLabels', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_barcode_labels_nonce'),
                'admin_url' => admin_url('admin.php?page=woo-barcode-labels')
            ));
        }
    }
    
    public function admin_page() {
        $product_ids = isset($_GET['product_ids']) ? sanitize_text_field($_GET['product_ids']) : '';
        $use_queue = isset($_GET['use_queue']) && $_GET['use_queue'] === '1';
        $products = array();
        
        if ($use_queue) {
            $queue_product_ids = $this->get_queue_products();
            foreach ($queue_product_ids as $id) {
                $product = wc_get_product($id);
                if ($product) {
                    $products[] = $product;
                }
            }
            $product_ids = implode(',', $queue_product_ids);
        } elseif (!empty($product_ids)) {
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
            
            <div class="queue-section" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong>Label Queue: </strong>
                        <span id="queue-count"><?php echo count($this->get_queue_products()); ?></span> product(s)
                    </div>
                    <div>
                        <button type="button" id="load-from-queue" class="button button-secondary">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                            Load Queue
                        </button>
                        <button type="button" id="clear-queue" class="button" style="margin-left: 10px;">
                            <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
                            Clear Queue
                        </button>
                    </div>
                </div>
            </div>
            
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
                                    <?php if ($this->is_wooconsign_active()): ?>
                                    <tr>
                                        <th scope="row"><label for="show_consignor">Show Consignor Number</label></th>
                                        <td>
                                            <input type="checkbox" id="show_consignor" name="show_consignor" value="1" <?php checked($settings['show_consignor'], 1); ?>>
                                            <label for="show_consignor">Display consignor number (from WooConsign plugin)</label>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
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
                                    <strong>Label Size:</strong> 2" × 1" (horizontal)
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
                                        if ($this->is_wooconsign_active()):
                                            $consignor_number = $this->get_consignor_number($product->get_id());
                                            if ($consignor_number): ?>
                                            <br><small>Consignor: <?php echo esc_html($consignor_number); ?></small>
                                            <?php endif; 
                                        endif; ?>
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
            $this->mark_products_as_printed($product_ids);
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
        
        $consignor_number = $this->is_wooconsign_active() ? $this->get_consignor_number($product_id) : null;
        
        $html = '<div class="label-preview-sample" style="width: 144px; height: 72px; border: 1px solid #333; padding: 4px; font-size: ' . $settings['font_size'] . 'px; font-family: Arial, sans-serif; position: relative; text-align: center; background: #fff; display: flex; flex-direction: column; justify-content: center;">';
        
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
    
    private function mark_products_as_printed($product_ids) {
        foreach ($product_ids as $product_id) {
            update_post_meta($product_id, '_barcode_label_printed', 'yes');
            update_post_meta($product_id, '_barcode_label_printed_date', current_time('mysql'));
        }
    }
    
    private function is_wooconsign_active() {
        return class_exists('WC_Consignment_Manager');
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
        
        $queue_table_name = $wpdb->prefix . 'barcode_label_queue';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$queue_table_name'") != $queue_table_name) {
            $sql = "CREATE TABLE $queue_table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                product_id bigint(20) NOT NULL,
                added_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY user_product (user_id, product_id),
                KEY user_id (user_id),
                KEY added_at (added_at)
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
                if ($this->is_wooconsign_active()) {
                    $consignor_number = $this->get_consignor_number($product_id);
                    if ($consignor_number) {
                        $consignor_numbers[] = $consignor_number;
                    } else {
                        $consignor_numbers[] = 'N/A';
                    }
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
    
    public function consignor_admin_page() {
        if (!$this->is_wooconsign_active()) {
            ?>
            <div class="wrap">
                <h1>Backlog Tags</h1>
                <div class="notice notice-error">
                    <p>WooConsign plugin is not active. Please install and activate WooConsign to use this feature.</p>
                </div>
            </div>
            <?php
            return;
        }

        global $wpdb;
        $consignors_table = $wpdb->prefix . 'consignors';

        // Try both schema formats (name vs first_name/last_name)
        $columns_check = $wpdb->get_row("SHOW COLUMNS FROM $consignors_table LIKE 'first_name'");

        if ($columns_check) {
            // New schema with first_name and last_name
            $consignors = $wpdb->get_results("SELECT id, consignor_number, first_name, last_name, CONCAT(first_name, ' ', last_name) as display_name FROM $consignors_table ORDER BY consignor_number ASC");
        } else {
            // Old schema with just name
            $consignors = $wpdb->get_results("SELECT id, consignor_number, name as display_name, name as first_name, '' as last_name FROM $consignors_table ORDER BY consignor_number ASC");
        }

        $settings = $this->get_label_settings();

        ?>
        <div class="wrap">
            <h1>Generate Backlog Tags</h1>

            <div id="consignor-label-configurator">
                <div class="label-config-container">
                    <div class="config-panel">
                        <h2>Select Consignor</h2>

                        <form id="consignor-label-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="consignor_id">Consignor</label></th>
                                    <td>
                                        <select id="consignor_id" name="consignor_id" style="width: 300px;" required>
                                            <option value="">Select a consignor...</option>
                                            <?php foreach ($consignors as $consignor): ?>
                                                <option value="<?php echo esc_attr($consignor->id); ?>">
                                                    #<?php echo esc_html($consignor->consignor_number); ?> -
                                                    <?php echo esc_html($consignor->display_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="label_quantity">Number of Labels</label></th>
                                    <td>
                                        <input type="number" id="label_quantity" name="label_quantity" value="1" min="1" max="500" style="width: 100px;" required>
                                        <p class="description">Enter the quantity of labels to generate (1-500)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="show_consignor_number">Show Consignor Number</label></th>
                                    <td>
                                        <input type="checkbox" id="show_consignor_number" name="show_consignor_number" value="1" checked>
                                        <label for="show_consignor_number">Display consignor number on label</label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="show_backlog_item">Show Backlog Item</label></th>
                                    <td>
                                        <input type="checkbox" id="show_backlog_item" name="show_backlog_item" value="1" checked>
                                        <label for="show_backlog_item">Display "BACKLOG ITEM" text</label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="show_size_line">Show Size Line</label></th>
                                    <td>
                                        <input type="checkbox" id="show_size_line" name="show_size_line" value="1" checked>
                                        <label for="show_size_line">Display size line</label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="show_price_line">Show Price Line</label></th>
                                    <td>
                                        <input type="checkbox" id="show_price_line" name="show_price_line" value="1" checked>
                                        <label for="show_price_line">Display price line at bottom</label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="consignor_font_size">Font Size</label></th>
                                    <td>
                                        <select id="consignor_font_size" name="consignor_font_size">
                                            <option value="8">8pt</option>
                                            <option value="9">9pt</option>
                                            <option value="10" selected>10pt</option>
                                            <option value="11">11pt</option>
                                            <option value="12">12pt</option>
                                            <option value="14">14pt</option>
                                            <option value="16">16pt</option>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </form>
                    </div>

                    <div class="preview-panel">
                        <h2>Label Preview</h2>
                        <div id="label-preview">
                            <div class="label-dimensions">
                                <strong>Label Size:</strong> 2" × 1" (horizontal)
                            </div>
                            <div id="consignor-preview-content" style="margin-top: 20px;">
                                <div class="label-preview-sample" style="width: 144px; height: 72px; border: 1px solid #333; padding: 4px; font-size: 10px; font-family: Arial, sans-serif; position: relative; text-align: center; background: #fff; display: flex; flex-direction: column; justify-content: space-between;">
                                    <div>
                                        <div style="font-size: 10px; color: #666;">Consignor: #1234</div>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 6px;">BACKLOG ITEM</div>
                                    </div>
                                    <div style="font-size: 8px; text-align: left;">Price:____________________</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="print-actions">
                    <button type="button" id="generate-consignor-pdf" class="button button-primary button-large">Generate Backlog Tags</button>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            function updateConsignorPreview() {
                var consignorSelect = $('#consignor_id');
                var selectedText = consignorSelect.find('option:selected').text();
                var showNumber = $('#show_consignor_number').is(':checked');
                var showBacklogItem = $('#show_backlog_item').is(':checked');
                var showSizeLine = $('#show_size_line').is(':checked');
                var showPriceLine = $('#show_price_line').is(':checked');
                var fontSize = parseInt($('#consignor_font_size').val());

                if (!consignorSelect.val()) {
                    $('#consignor-preview-content').html(
                        '<div class="label-preview-sample" style="width: 144px; height: 72px; border: 1px solid #333; padding: 4px; font-size: ' + fontSize + 'px; font-family: Arial, sans-serif; position: relative; text-align: center; background: #fff; display: flex; flex-direction: column; justify-content: center;">' +
                        '<div style="color: #999;">Select a consignor to preview</div>' +
                        '</div>'
                    );
                    return;
                }

                var parts = selectedText.split(' - ');
                var number = parts[0].replace('#', '').trim();

                var html = '<div class="label-preview-sample" style="width: 144px; height: 72px; border: 1px solid #333; padding: 4px; font-size: ' + fontSize + 'px; font-family: Arial, sans-serif; position: relative; text-align: center; background: #fff; display: flex; flex-direction: column; justify-content: space-between;">';

                html += '<div>';
                if (showNumber) {
                    html += '<div style="font-size: ' + fontSize + 'px; color: #666;">Consignor: #' + number + '</div>';
                } else {
                    html += '<div style="color: #999;">Enable consignor number</div>';
                }

                if (showBacklogItem) {
                    html += '<div style="font-size: 9px; font-weight: bold; margin-top: 6px;">BACKLOG ITEM</div>';
                }
                html += '</div>';

                html += '<div style="font-size: 8px; text-align: left;">';
                if (showSizeLine && showPriceLine) {
                    html += 'Size:___________Price:______________';
                } else if (showSizeLine) {
                    html += 'Size:____________________';
                } else if (showPriceLine) {
                    html += 'Price:____________________';
                }
                html += '</div>';

                html += '</div>';

                $('#consignor-preview-content').html(html);
            }

            $('#consignor_id, #show_consignor_number, #show_backlog_item, #show_size_line, #show_price_line, #consignor_font_size').on('change', updateConsignorPreview);

            $('#generate-consignor-pdf').on('click', function() {
                var consignorId = $('#consignor_id').val();
                var quantity = parseInt($('#label_quantity').val());

                if (!consignorId) {
                    alert('Please select a consignor');
                    return;
                }

                if (!quantity || quantity < 1 || quantity > 500) {
                    alert('Please enter a valid quantity (1-500)');
                    return;
                }

                var button = $(this);
                button.prop('disabled', true).text('Generating PDF...');

                $.ajax({
                    url: wcBarcodeLabels.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'generate_consignor_labels_pdf',
                        nonce: wcBarcodeLabels.nonce,
                        consignor_id: consignorId,
                        quantity: quantity,
                        show_consignor_number: $('#show_consignor_number').is(':checked') ? 1 : 0,
                        show_backlog_item: $('#show_backlog_item').is(':checked') ? 1 : 0,
                        show_size_line: $('#show_size_line').is(':checked') ? 1 : 0,
                        show_price_line: $('#show_price_line').is(':checked') ? 1 : 0,
                        font_size: $('#consignor_font_size').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            window.open(response.data.pdf_url, '_blank');
                            button.prop('disabled', false).text('Generate Backlog Tags');
                        } else {
                            alert('Error: ' + (response.data.message || 'Failed to generate PDF'));
                            button.prop('disabled', false).text('Generate Backlog Tags');
                        }
                    },
                    error: function() {
                        alert('An error occurred while generating the PDF');
                        button.prop('disabled', false).text('Generate Backlog Tags');
                    }
                });
            });
        });
        </script>
        <?php
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
    
    private function is_product_in_queue($product_id, $user_id = null) {
        global $wpdb;
        
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        $table_name = $wpdb->prefix . 'barcode_label_queue';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND product_id = %d",
            $user_id,
            $product_id
        ));
        
        return $count > 0;
    }
    
    private function get_queue_products($user_id = null) {
        global $wpdb;
        
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        $table_name = $wpdb->prefix . 'barcode_label_queue';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id FROM $table_name WHERE user_id = %d ORDER BY added_at ASC",
            $user_id
        ));
        
        return array_map(function($row) {
            return $row->product_id;
        }, $results);
    }
    
    public function ajax_add_to_label_queue() {
        check_ajax_referer('wc_barcode_labels_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'barcode_label_queue';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND product_id = %d",
            $user_id,
            $product_id
        ));
        
        if ($existing > 0) {
            wp_send_json_success(array('message' => 'Product already in queue', 'already_exists' => true));
            return;
        }
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'product_id' => $product_id
            ),
            array('%d', '%d')
        );
        
        if ($result) {
            $queue_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
                $user_id
            ));
            wp_send_json_success(array('message' => 'Product added to queue', 'queue_count' => $queue_count));
        } else {
            wp_send_json_error(array('message' => 'Failed to add product to queue'));
        }
    }
    
    public function ajax_remove_from_label_queue() {
        check_ajax_referer('wc_barcode_labels_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'barcode_label_queue';
        
        $result = $wpdb->delete(
            $table_name,
            array(
                'user_id' => $user_id,
                'product_id' => $product_id
            ),
            array('%d', '%d')
        );
        
        if ($result) {
            $queue_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
                $user_id
            ));
            wp_send_json_success(array('message' => 'Product removed from queue', 'queue_count' => $queue_count));
        } else {
            wp_send_json_error(array('message' => 'Failed to remove product from queue'));
        }
    }
    
    public function ajax_clear_label_queue() {
        check_ajax_referer('wc_barcode_labels_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'barcode_label_queue';
        
        $result = $wpdb->delete(
            $table_name,
            array('user_id' => $user_id),
            array('%d')
        );
        
        wp_send_json_success(array('message' => 'Queue cleared successfully', 'deleted_count' => $result));
    }
    
    public function ajax_get_label_queue() {
        check_ajax_referer('wc_barcode_labels_nonce', 'nonce');

        $product_ids = $this->get_queue_products();

        wp_send_json_success(array('product_ids' => $product_ids, 'count' => count($product_ids)));
    }

    public function ajax_generate_consignor_labels_pdf() {
        check_ajax_referer('wc_barcode_labels_nonce', 'nonce');

        if (!$this->is_wooconsign_active()) {
            wp_send_json_error(array('message' => 'WooConsign plugin is not active'));
            return;
        }

        $consignor_id = intval($_POST['consignor_id']);
        $quantity = intval($_POST['quantity']);
        $settings = array(
            'show_consignor_number' => isset($_POST['show_consignor_number']) ? 1 : 0,
            'show_backlog_item' => isset($_POST['show_backlog_item']) ? 1 : 0,
            'show_size_line' => isset($_POST['show_size_line']) ? 1 : 0,
            'show_price_line' => isset($_POST['show_price_line']) ? 1 : 0,
            'font_size' => intval($_POST['font_size'])
        );

        if ($quantity < 1 || $quantity > 500) {
            wp_send_json_error(array('message' => 'Invalid quantity'));
            return;
        }

        global $wpdb;
        $consignors_table = $wpdb->prefix . 'consignors';

        // Check for first_name/last_name columns vs just name
        $columns_check = $wpdb->get_row("SHOW COLUMNS FROM $consignors_table LIKE 'first_name'");

        if ($columns_check) {
            // New schema with first_name and last_name
            $consignor = $wpdb->get_row($wpdb->prepare(
                "SELECT id, consignor_number, first_name, last_name, CONCAT(first_name, ' ', last_name) as display_name FROM $consignors_table WHERE id = %d",
                $consignor_id
            ));
        } else {
            // Old schema with just name
            $consignor = $wpdb->get_row($wpdb->prepare(
                "SELECT id, consignor_number, name as first_name, '' as last_name, name as display_name FROM $consignors_table WHERE id = %d",
                $consignor_id
            ));
        }

        if (!$consignor) {
            wp_send_json_error(array('message' => 'Consignor not found'));
            return;
        }

        $pdf_result = $this->generate_consignor_pdf_labels($consignor, $quantity, $settings);

        if ($pdf_result) {
            wp_send_json_success(array('pdf_url' => $pdf_result));
        } else {
            wp_send_json_error(array('message' => 'Failed to generate PDF'));
        }
    }

    private function generate_consignor_pdf_labels($consignor, $quantity, $settings) {
        require_once(WC_BARCODE_LABELS_PLUGIN_PATH . 'includes/class-pdf-generator.php');

        $pdf_generator = new WC_Barcode_Labels_PDF_Generator();
        return $pdf_generator->generate_consignor_labels($consignor, $quantity, $settings);
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