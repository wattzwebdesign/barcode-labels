# WooCommerce Barcode Labels Plugin

A comprehensive WordPress plugin that allows you to print customizable barcode labels for WooCommerce products. Features bulk printing capability, integration with the WooConsign plugin, and support for 2.125" × 1.125" horizontal labels.

## Features

- **Bulk Label Printing**: Select multiple products from WooCommerce admin and print labels in batch
- **Customizable Labels**: Configure what information appears on each label:
  - Product title
  - Product price
  - SKU (both as text and barcode)
  - Consignor number (from WooConsign plugin)
- **Professional Barcodes**: Generates Code 128 barcodes using product SKU
- **Perfect Label Size**: Designed for 2.125" × 1.125" labels in horizontal orientation
- **PDF Output**: Generates print-ready PDF files that open in system print dialog
- **Easy Configuration**: User-friendly interface with live preview
- **Settings Persistence**: Saves your label configuration preferences

## Requirements

- WordPress 5.0 or higher
- WooCommerce 5.0 or higher
- PHP 7.0 or higher
- WooConsign plugin (optional, for consignor numbers)

## Installation

1. Upload the `woo-barcode-labels` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and activated

## Usage

### Bulk Printing from Products Page

1. Go to **WooCommerce > Products** in your WordPress admin
2. Select the products you want to print labels for using the checkboxes
3. From the "Bulk actions" dropdown, select **"Print Barcode Labels"**
4. Click **"Apply"**
5. You'll see a success notice with a **"Configure & Print Labels"** button
6. Click the button to open the label configurator

### Using the Label Configurator

1. **Configure Label Content**:
   - Check/uncheck which information to include on labels
   - Adjust font size (8pt - 12pt)
   - Click **"Preview Labels"** to see how they'll look
   - Click **"Save Settings"** to remember your preferences

2. **Generate PDF**:
   - Review the selected products list
   - Click **"Generate PDF Labels"**
   - The PDF will automatically open in a new window
   - Use your browser's print function or save the PDF

### Direct Access

You can also access the label configurator directly:
- Go to **WooCommerce > Barcode Labels** in your WordPress admin
- You'll need to select products first from the Products page

## Label Specifications

- **Dimensions**: 2.125" × 1.125" (54mm × 29mm)
- **Orientation**: Horizontal
- **Format**: PDF
- **Barcode Type**: Code 128
- **Fonts**: System fonts (Helvetica/Arial)

## WooConsign Integration

If you have the WooConsign plugin installed, this plugin will automatically:
- Detect consignor information for each product
- Display consignor numbers on labels when enabled
- Pull consignor data from the `_consignor_id` product meta field

## Customization Options

### Label Elements
- **Product Title**: Full product name (truncated if too long)
- **Price**: Formatted product price
- **SKU Text**: Product SKU as readable text
- **Barcode**: Code 128 barcode of the product SKU
- **Consignor Number**: From WooConsign plugin

### Styling Options
- **Font Size**: 8pt, 9pt, 10pt, 11pt, or 12pt
- **Layout**: Automatically optimized for label size
- **Barcode Size**: Automatically scaled to fit

## File Structure

```
woo-barcode-labels/
├── woo-barcode-labels.php          # Main plugin file
├── assets/
│   ├── admin.css                   # Admin interface styles
│   └── admin.js                    # Admin interface JavaScript
├── includes/
│   ├── class-pdf-generator.php     # PDF generation class
│   └── class-barcode-generator.php # Barcode generation class
└── README.md                       # This file
```

## Technical Details

### PDF Generation
- Uses TCPDF library for PDF creation
- Automatically downloads TCPDF if not found in WooCommerce
- Optimized for label printing with precise dimensions

### Barcode Generation
- Custom Code 128 barcode implementation
- Supports alphanumeric SKUs
- Generates PNG images for PDF embedding
- Automatic cleanup of temporary files

### AJAX Endpoints
- `get_label_preview`: Generate live preview
- `save_label_settings`: Save configuration
- `generate_labels_pdf`: Create PDF file

## Troubleshooting

### Common Issues

1. **"No products selected" message**:
   - Make sure to select products using bulk actions from the Products page
   - The plugin requires at least one product to be selected

2. **PDF generation fails**:
   - Check that your server has write permissions to the uploads directory
   - Ensure PHP memory limit is sufficient (recommended: 256MB+)
   - Verify GD extension is installed for barcode generation

3. **Consignor numbers not showing**:
   - Ensure WooConsign plugin is installed and activated
   - Check that products have consignor assignments
   - Enable "Show Consignor Number" in label configuration

4. **Barcodes not generating**:
   - Verify products have SKUs assigned
   - Check that GD extension is installed and enabled
   - SKUs should contain only alphanumeric characters for best results

### Server Requirements
- **Memory**: 256MB+ PHP memory limit recommended
- **Extensions**: GD library for image generation
- **Permissions**: Write access to WordPress uploads directory
- **Execution Time**: 300+ seconds for large batches

## Support

For issues, feature requests, or contributions, please contact the development team.

## Changelog

### Version 1.0.0
- Initial release
- Bulk label printing functionality
- WooConsign integration
- Customizable label configuration
- Code 128 barcode generation
- PDF output for 2.125" × 1.125" labels

## License

GPL v2 or later