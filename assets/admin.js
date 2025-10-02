jQuery(document).ready(function($) {
    
    // Handle bulk action form submission for print labels
    $('form#posts-filter').on('submit', function(e) {
        var action = $('#bulk-action-selector-top').val() || $('#bulk-action-selector-bottom').val();
        if (action === 'print_barcode_labels') {
            e.preventDefault();
            
            var selectedIds = [];
            $('input[name="post[]"]:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (selectedIds.length === 0) {
                alert('Please select at least one product to print labels.');
                return;
            }
            
            var url = wcBarcodeLabels.admin_url + '&product_ids=' + selectedIds.join(',');
            window.open(url, '_blank');
        }
    });
    
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
    
    $('.add-to-queue-btn').on('click', function() {
        var $btn = $(this);
        var productId = $btn.data('product-id');
        var $icon = $btn.find('.dashicons');
        var isInQueue = $icon.hasClass('dashicons-yes');
        
        var action = isInQueue ? 'remove_from_label_queue' : 'add_to_label_queue';
        
        $.ajax({
            url: wcBarcodeLabels.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: wcBarcodeLabels.nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    if (isInQueue) {
                        $icon.removeClass('dashicons-yes').addClass('dashicons-tag').css('color', '#666');
                    } else {
                        $icon.removeClass('dashicons-tag').addClass('dashicons-yes').css('color', '#46b450');
                        $btn.css('animation', 'checkmark-pulse 0.5s');
                        setTimeout(function() {
                            $btn.css('animation', '');
                        }, 500);
                    }
                    
                    if ($('#queue-count').length) {
                        $('#queue-count').text(response.data.queue_count);
                    }
                }
            },
            error: function() {
                alert('Error updating queue. Please try again.');
            }
        });
    });
    
    $('.add-to-queue-product-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('product-id');
        var isInQueue = $btn.text().trim().indexOf('Remove') === 0;
        
        var action = isInQueue ? 'remove_from_label_queue' : 'add_to_label_queue';
        
        $.ajax({
            url: wcBarcodeLabels.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: wcBarcodeLabels.nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    var $message = $('.queue-status-message');
                    
                    if (isInQueue) {
                        $btn.html('<span class="dashicons dashicons-tag" style="margin-top: 3px;"></span> Add to Queue');
                        $message.text('Removed from queue').css('color', '#dc3232').fadeIn();
                    } else {
                        $btn.html('<span class="dashicons dashicons-tag" style="margin-top: 3px;"></span> Remove from Queue');
                        $message.html('<span class="dashicons dashicons-yes" style="color: #46b450; margin-top: 3px;"></span> Added to queue!').css('color', '#46b450').fadeIn();
                    }
                    
                    setTimeout(function() {
                        $message.fadeOut();
                    }, 3000);
                }
            },
            error: function() {
                alert('Error updating queue. Please try again.');
            }
        });
    });
    
    function loadLabelQueue() {
        $.ajax({
            url: wcBarcodeLabels.ajax_url,
            type: 'POST',
            data: {
                action: 'get_label_queue',
                nonce: wcBarcodeLabels.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.product_ids.length > 0) {
                        window.location.href = wcBarcodeLabels.admin_url + '&use_queue=1';
                    } else {
                        alert('Queue is empty. Add products to queue first.');
                    }
                }
            },
            error: function() {
                alert('Error loading queue. Please try again.');
            }
        });
    }
    
    $('#load-from-queue').on('click', loadLabelQueue);
    $('#load-queue-from-products').on('click', loadLabelQueue);
    
    $('#clear-queue').on('click', function() {
        if (!confirm('Are you sure you want to clear the entire queue? This cannot be undone.')) {
            return;
        }
        
        $.ajax({
            url: wcBarcodeLabels.ajax_url,
            type: 'POST',
            data: {
                action: 'clear_label_queue',
                nonce: wcBarcodeLabels.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#queue-count').text('0');
                    showNotice('Queue cleared successfully!', 'success');
                    
                    if (window.location.href.indexOf('use_queue=1') > -1) {
                        window.location.href = wcBarcodeLabels.admin_url;
                    }
                }
            },
            error: function() {
                alert('Error clearing queue. Please try again.');
            }
        });
    });
});