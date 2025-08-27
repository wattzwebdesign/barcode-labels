jQuery(document).ready(function($) {
    
    $('#preview-labels').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.text('Loading...').addClass('loading').prop('disabled', true);
        
        var formData = {
            action: 'get_label_preview',
            nonce: wcBarcodeLabels.nonce,
            show_title: $('#show_title').is(':checked') ? 1 : 0,
            show_price: $('#show_price').is(':checked') ? 1 : 0,
            show_sku: $('#show_sku').is(':checked') ? 1 : 0,
            show_barcode: $('#show_barcode').is(':checked') ? 1 : 0,
            show_consignor: $('#show_consignor').is(':checked') ? 1 : 0,
            font_size: $('#font_size').val(),
            product_ids: $('#product-ids').val()
        };
        
        $.ajax({
            url: wcBarcodeLabels.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    $('#preview-content').html(response.data.preview).addClass('has-preview');
                } else {
                    $('#preview-content').html('Error generating preview: ' + (response.data.message || 'Unknown error'));
                }
            },
            error: function() {
                $('#preview-content').html('Error communicating with server. Please try again.');
            },
            complete: function() {
                button.text(originalText).removeClass('loading').prop('disabled', false);
            }
        });
    });
    
    $('#save-settings').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        button.text('Saving...').addClass('loading').prop('disabled', true);
        
        var formData = {
            action: 'save_label_settings',
            nonce: wcBarcodeLabels.nonce,
            show_title: $('#show_title').is(':checked') ? 1 : 0,
            show_price: $('#show_price').is(':checked') ? 1 : 0,
            show_sku: $('#show_sku').is(':checked') ? 1 : 0,
            show_barcode: $('#show_barcode').is(':checked') ? 1 : 0,
            show_consignor: $('#show_consignor').is(':checked') ? 1 : 0,
            font_size: $('#font_size').val()
        };
        
        $.ajax({
            url: wcBarcodeLabels.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    showNotice('Settings saved successfully!', 'success');
                } else {
                    showNotice('Error saving settings: ' + (response.data.message || 'Unknown error'), 'error');
                }
            },
            error: function() {
                showNotice('Error communicating with server. Please try again.', 'error');
            },
            complete: function() {
                button.text(originalText).removeClass('loading').prop('disabled', false);
            }
        });
    });
    
    $('#generate-pdf').on('click', function() {
        var button = $(this);
        var originalText = button.text();
        
        var productIds = $('#product-ids').val();
        if (!productIds) {
            showNotice('No products selected for label generation.', 'error');
            return;
        }
        
        button.text('Generating PDF...').addClass('loading').prop('disabled', true);
        
        var formData = {
            action: 'generate_labels_pdf',
            nonce: wcBarcodeLabels.nonce,
            show_title: $('#show_title').is(':checked') ? 1 : 0,
            show_price: $('#show_price').is(':checked') ? 1 : 0,
            show_sku: $('#show_sku').is(':checked') ? 1 : 0,
            show_barcode: $('#show_barcode').is(':checked') ? 1 : 0,
            show_consignor: $('#show_consignor').is(':checked') ? 1 : 0,
            font_size: $('#font_size').val(),
            product_ids: productIds
        };
        
        $.ajax({
            url: wcBarcodeLabels.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    showNotice('PDF generated successfully! Opening in new window...', 'success');
                    
                    setTimeout(function() {
                        window.open(response.data.pdf_url, '_blank');
                    }, 1000);
                } else {
                    showNotice('Error generating PDF: ' + (response.data.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error communicating with server';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage += ': ' + xhr.responseJSON.data.message;
                } else if (error) {
                    errorMessage += ': ' + error;
                }
                showNotice(errorMessage, 'error');
            },
            complete: function() {
                button.text(originalText).removeClass('loading').prop('disabled', false);
            }
        });
    });
    
    $('.form-table input, .form-table select').on('change', function() {
        $('#preview-content').removeClass('has-preview').html('Click "Preview Labels" to see updated preview.');
    });
    
    function showNotice(message, type) {
        var noticeClass = 'notice notice-' + type + ' is-dismissible barcode-labels-notice';
        var notice = $('<div class="' + noticeClass + '"><p>' + message + '</p></div>');
        
        $('.wrap h1').after(notice);
        
        notice.find('.notice-dismiss').on('click', function() {
            notice.remove();
        });
        
        setTimeout(function() {
            notice.fadeOut(function() {
                notice.remove();
            });
        }, 5000);
    }
    
    $(document).on('click', '.barcode-labels-notice .notice-dismiss', function() {
        $(this).closest('.notice').remove();
    });
    
    if ($('#product-ids').val()) {
        $('#preview-labels').trigger('click');
    }
});