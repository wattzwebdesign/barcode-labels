<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Barcode_Labels_PDF_Generator {
    
    private $tcpdf_path;
    
    public function __construct() {
        $this->tcpdf_path = $this->get_tcpdf_path();
    }
    
    private function get_tcpdf_path() {
        $possible_paths = array(
            ABSPATH . 'wp-content/plugins/woocommerce/packages/woocommerce-admin/lib/tcpdf/',
            ABSPATH . 'wp-content/plugins/woocommerce/lib/tcpdf/',
            WC_BARCODE_LABELS_PLUGIN_PATH . 'lib/tcpdf/',
        );
        
        foreach ($possible_paths as $path) {
            if (file_exists($path . 'tcpdf.php')) {
                return $path;
            }
        }
        
        return false;
    }
    
    private function ensure_tcpdf() {
        if (!$this->tcpdf_path) {
            $this->download_tcpdf();
        }
        
        if ($this->tcpdf_path && file_exists($this->tcpdf_path . 'tcpdf.php')) {
            require_once($this->tcpdf_path . 'tcpdf.php');
            return true;
        }
        
        return false;
    }
    
    private function download_tcpdf() {
        $lib_dir = WC_BARCODE_LABELS_PLUGIN_PATH . 'lib/';
        $tcpdf_dir = $lib_dir . 'tcpdf/';
        
        if (!file_exists($lib_dir)) {
            wp_mkdir_p($lib_dir);
        }
        
        if (!file_exists($tcpdf_dir)) {
            $tcpdf_url = 'https://github.com/tecnickcom/TCPDF/archive/refs/heads/main.zip';
            $zip_file = $lib_dir . 'tcpdf.zip';
            
            $response = wp_remote_get($tcpdf_url, array('timeout' => 300));
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
                file_put_contents($zip_file, wp_remote_retrieve_body($response));
                
                $zip = new ZipArchive();
                if ($zip->open($zip_file) === TRUE) {
                    $zip->extractTo($lib_dir);
                    $zip->close();
                    
                    if (file_exists($lib_dir . 'TCPDF-main/')) {
                        rename($lib_dir . 'TCPDF-main/', $tcpdf_dir);
                    }
                    
                    unlink($zip_file);
                    $this->tcpdf_path = $tcpdf_dir;
                }
            }
        } else {
            $this->tcpdf_path = $tcpdf_dir;
        }
    }
    
    public function generate($product_ids, $settings) {
        if (!$this->ensure_tcpdf()) {
            return false;
        }
        
        $pdf = new TCPDF('L', 'in', array(2.0, 1.0), true, 'UTF-8', false);
        
        $pdf->SetCreator('WooCommerce Barcode Labels');
        $pdf->SetAuthor('WooCommerce Store');
        $pdf->SetTitle('Product Barcode Labels');
        $pdf->SetSubject('Barcode Labels');
        
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->SetAutoPageBreak(false, 0);
        
        foreach ($product_ids as $product_id) {
            $this->add_label_page($pdf, $product_id, $settings);
        }
        
        $uploads = wp_upload_dir();
        $pdf_dir = $uploads['basedir'] . '/barcode-labels/';
        
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }
        
        $filename = 'labels_' . time() . '_' . wp_generate_password(8, false) . '.pdf';
        $file_path = $pdf_dir . $filename;
        
        $pdf->Output($file_path, 'F');
        
        if (file_exists($file_path)) {
            return $uploads['baseurl'] . '/barcode-labels/' . $filename;
        }
        
        return false;
    }
    
    private function add_label_page($pdf, $product_id, $settings) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        $pdf->AddPage();
        
        $font_size = intval($settings['font_size']);
        $line_height = $font_size / 72 * 1.2;
        
        $y_position = 0.02;
        $label_width = 2.0;
        
        if ($settings['show_title']) {
            $title = $this->truncate_text($product->get_name(), 32);
            $pdf->SetFont('helvetica', 'B', $font_size);
            $pdf->SetXY(0, $y_position);
            $pdf->Cell($label_width, $line_height, $title, 0, 1, 'C');
            $y_position += $line_height + 0.01;
        }
        
        if ($settings['show_price']) {
            $price = html_entity_decode(strip_tags(wc_price($product->get_price())));
            $pdf->SetFont('helvetica', 'B', $font_size + 1);
            $pdf->SetXY(0, $y_position);
            $pdf->Cell($label_width, $line_height, $price, 0, 1, 'C');
            $y_position += $line_height + 0.01;
        }
        
        $has_consignor = false;
        if ($settings['show_consignor'] && $this->is_wooconsign_active()) {
            $consignor_number = $this->get_consignor_number($product_id);
            if ($consignor_number) {
                $has_consignor = true;
                $consignor_text = 'Consignor: ' . $consignor_number;
                $pdf->SetFont('helvetica', '', $font_size - 2);
                $pdf->SetXY(0, $y_position);
                $pdf->Cell($label_width, $line_height * 0.8, $consignor_text, 0, 1, 'C');
                $y_position += $line_height * 0.8 + 0.01;
            }
        }
        
        // Add minimal spacing above barcode when no consignor is shown
        if (!$has_consignor && ($settings['show_title'] || $settings['show_price'])) {
            $y_position += 0.02;
        }
        
        if ($settings['show_barcode'] && $product->get_sku()) {
            $barcode_result = $this->add_barcode($pdf, $product->get_sku(), $y_position, $settings);
            if ($barcode_result && $settings['show_sku']) {
                $y_position = $barcode_result['sku_y_position'];
            }
        } else if ($settings['show_sku'] && $product->get_sku()) {
            $sku = $product->get_sku();
            $pdf->SetFont('helvetica', '', $font_size - 2);
            $pdf->SetXY(0, $y_position);
            $pdf->Cell($label_width, $line_height * 0.8, $sku, 0, 1, 'C');
        }
    }
    
    private function add_barcode($pdf, $sku, $y_position, $settings) {
        // Use TCPDF's built-in barcode generation for better compatibility
        $available_height = 0.95 - $y_position;
        $barcode_height = min(0.35, $available_height - 0.1);
        $barcode_width = 1.5;
        
        $x_center = (2.0 - $barcode_width) / 2;
        
        // TCPDF's write1DBarcode method for Code 128
        $style = array(
            'border' => false,
            'vpadding' => 'auto',
            'hpadding' => 'auto',
            'fgcolor' => array(0, 0, 0),
            'bgcolor' => array(255, 255, 255),
            'text' => false,
            'font' => 'helvetica',
            'fontsize' => 8
        );
        
        // Generate the barcode using TCPDF's method with proper parameters
        $pdf->write1DBarcode($sku, 'C128', $x_center, $y_position, $barcode_width, $barcode_height, 0.4, $style, 'N');
        
        $sku_y_position = $y_position + $barcode_height - 0.01;
        
        if ($settings['show_sku']) {
            $pdf->SetFont('helvetica', '', intval($settings['font_size']) - 2);
            $pdf->SetXY(0, $sku_y_position);
            $pdf->Cell(2.0, 0.1, $sku, 0, 1, 'C');
            $sku_y_position += 0.1;
        }
        
        return array('sku_y_position' => $sku_y_position);
    }
    
    private function is_wooconsign_active() {
        return class_exists('WC_Consignment_Manager');
    }
    
    private function get_consignor_number($product_id) {
        if (!$this->is_wooconsign_active()) {
            return null;
        }
        
        $consignor_id = get_post_meta($product_id, '_consignor_id', true);
        
        if ($consignor_id) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'consignors';
            
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                $consignor = $wpdb->get_row($wpdb->prepare(
                    "SELECT consignor_number FROM $table_name WHERE id = %d",
                    $consignor_id
                ));
                
                return $consignor ? $consignor->consignor_number : null;
            }
        }
        
        return null;
    }
    
    private function truncate_text($text, $max_length) {
        if (strlen($text) > $max_length) {
            return substr($text, 0, $max_length - 3) . '...';
        }
        return $text;
    }
}