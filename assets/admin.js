jQuery(document).ready(function($) {
    'use strict';

    console.log('Twine admin.js loaded successfully');

    // Copy public URL to clipboard
    $('#twine-copy-url-btn').on('click', function(e) {
        e.preventDefault();
        var url = $(this).data('url');
        var $btn = $(this);
        var originalText = $btn.text();

        // Use modern clipboard API
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function() {
                $btn.text('Copied!');
                setTimeout(function() {
                    $btn.text(originalText);
                }, 2000);
            }).catch(function(err) {
                console.error('Failed to copy:', err);
                alert('Failed to copy URL to clipboard');
            });
        } else {
            // Fallback for older browsers
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(url).select();
            try {
                document.execCommand('copy');
                $btn.text('Copied!');
                setTimeout(function() {
                    $btn.text(originalText);
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
                alert('Failed to copy URL to clipboard');
            }
            $temp.remove();
        }
    });

    // Reload preview iframe on Settings page when form is saved
    if ($('.twine-admin-preview-iframe').length > 0) {
        // Reload preview when theme is changed
        $('#twine-theme').on('change', function() {
            var $iframe = $('.twine-admin-preview-iframe');
            var currentSrc = $iframe.attr('src');
            if (currentSrc) {
                var baseUrl = currentSrc.split('?')[0];
                var queryString = currentSrc.split('?')[1] || '';
                var params = new URLSearchParams(queryString);
                params.set('twine_preview', $(this).val());
                params.set('t', new Date().getTime());
                $iframe.attr('src', baseUrl + '?' + params.toString());
            }
        });
    }

    // WordPress Media Uploader for Icon
    var mediaUploader;

    $('#twine-upload-icon-btn').on('click', function(e) {
        e.preventDefault();

        // If the uploader object has already been created, reopen the dialog
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        // Create the media uploader
        mediaUploader = wp.media({
            title: 'Choose Icon',
            button: {
                text: 'Select Icon'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });

        // When an image is selected, run a callback
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();

            // Set the icon URL in the hidden input
            $('#twine-icon-url').val(attachment.url);

            // Update the preview
            if ($('#twine-icon-preview-img').length) {
                $('#twine-icon-preview-img').attr('src', attachment.url);
            } else {
                $('#twine-icon-placeholder').replaceWith(
                    '<img src="' + attachment.url + '" alt="Icon" id="twine-icon-preview-img">'
                );
            }

            // Update button text and show remove button
            $('#twine-upload-icon-btn').text('Change Icon');
            if (!$('#twine-remove-icon-btn').length) {
                $('#twine-upload-icon-btn').after('<button type="button" class="button" id="twine-remove-icon-btn">Remove Icon</button>');
            }
        });

        // Open the uploader dialog
        mediaUploader.open();
    });

    // Remove icon
    $(document).on('click', '#twine-remove-icon-btn', function(e) {
        e.preventDefault();

        if (confirm('Are you sure you want to remove the icon?')) {
            // Clear the icon URL
            $('#twine-icon-url').val('');

            // Replace preview with placeholder
            $('#twine-icon-preview-img').replaceWith(
                '<div class="twine-icon-placeholder" id="twine-icon-placeholder">' +
                '<span class="dashicons dashicons-format-image"></span>' +
                '</div>'
            );

            // Update button text and remove remove button
            $('#twine-upload-icon-btn').text('Change Icon');
            $('#twine-remove-icon-btn').remove();
        }
    });

    // Make links sortable
    $('#twine-links-container').sortable({
        handle: '.twine-drag-handle',
        placeholder: 'twine-link-placeholder',
        cursor: 'move',
        opacity: 0.8,
        tolerance: 'pointer'
    });

    // Add new link
    $('#twine-add-link').on('click', function() {
        var linkHtml = `
            <div class="twine-link-item">
                <span class="twine-drag-handle dashicons dashicons-menu"></span>
                <div class="twine-link-fields">
                    <div class="twine-link-field">
                        <label>Label</label>
                        <input type="text"
                               name="link_text[]"
                               value=""
                               placeholder="Link Text"
                               class="twine-link-text"
                               required>
                    </div>
                    <div class="twine-link-field">
                        <label>URL</label>
                        <input type="url"
                               name="link_url[]"
                               value=""
                               placeholder="https://example.com"
                               class="twine-link-url"
                               required>
                    </div>
                </div>
                <button type="button" class="button twine-remove-link">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
        `;

        $('#twine-links-container').append(linkHtml);

        // Focus on the new text input
        $('#twine-links-container .twine-link-item:last-child .twine-link-text').focus();
    });

    // Remove link
    $(document).on('click', '.twine-remove-link', function() {
        if (confirm('Are you sure you want to remove this link?')) {
            $(this).closest('.twine-link-item').fadeOut(300, function() {
                $(this).remove();
            });
        }
    });

    // Show empty state if no links
    function checkEmptyState() {
        if ($('#twine-links-container .twine-link-item').length === 0) {
            $('#twine-links-container').html('<p class="twine-empty-state">No links yet. Click "Add Link" to get started!</p>');
        }
    }

    // Check on load
    checkEmptyState();

    // Tab switching function
    function switchTab(tabName) {
        // Update active tab
        $('.nav-tab').removeClass('nav-tab-active');
        $('.nav-tab[data-tab="' + tabName + '"]').addClass('nav-tab-active');

        // Show/hide tab content
        $('.twine-tab-content').hide();
        $('#tab-' + tabName).show();
    }

    // Tab switching on click
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();

        var targetTab = $(this).data('tab');

        // Update URL hash
        window.location.hash = targetTab;

        // Switch to the tab
        switchTab(targetTab);
    });

    // Load tab from URL hash on page load
    var hash = window.location.hash.substring(1); // Remove the # character
    if (hash && $('#tab-' + hash).length > 0) {
        switchTab(hash);
    }

    // Theme selection
    $(document).on('click', '.twine-select-theme-btn', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var themeSlug = $btn.data('theme');

        // Don't do anything if this theme is already active
        if ($btn.closest('.twine-theme-card').hasClass('active')) {
            return;
        }

        // Update hidden input
        $('#twine-theme-input').val(themeSlug);

        // Update UI
        $('.twine-theme-card').removeClass('active');
        $btn.closest('.twine-theme-card').addClass('active');

        // Update all buttons
        $('.twine-select-theme-btn').each(function() {
            var $this = $(this);
            if ($this.closest('.twine-theme-card').hasClass('active')) {
                $this.text('Active');
            } else {
                $this.text('Select');
            }
        });

        // Auto-save the form
        $btn.closest('form').submit();
    });

    // Upload theme button (Settings tab)
    $('#twine-upload-theme-btn').on('click', function(e) {
        e.preventDefault();
        $('#twine-theme-upload').click();
    });

    // Auto-submit when file is selected (Settings tab)
    $('#twine-theme-upload').on('change', function() {
        if (this.files && this.files.length > 0) {
            $(this).closest('form').submit();
        }
    });

    // Upload theme button (Themes gallery page)
    $('#twine-upload-theme-btn-main').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#twine-theme-upload-main').click();
    });

    // Auto-submit when file is selected (Themes gallery page)
    $('#twine-theme-upload-main').on('change', function() {
        if (this.files && this.files.length > 0) {
            $('#twine-theme-upload-form').submit();
        }
    });

    // Theme selection from gallery page
    $(document).on('click', '.twine-select-theme-btn-gallery', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var themeSlug = $btn.data('theme');

        console.log('Use button clicked, theme:', themeSlug);

        // Don't do anything if this theme is already active
        if ($btn.closest('.twine-theme-card').hasClass('active')) {
            console.log('Theme already active, skipping');
            return;
        }

        // Show loading
        $btn.prop('disabled', true).text('Activating...');

        var nonceVal = $('#twine_nonce').val();
        console.log('Nonce value:', nonceVal ? 'found' : 'NOT FOUND');

        var ajaxUrl = (typeof twineAdmin !== 'undefined' && twineAdmin.ajaxurl) ? twineAdmin.ajaxurl : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
        console.log('ajaxurl:', ajaxUrl ? ajaxUrl : 'NOT DEFINED');

        if (!nonceVal) {
            console.error('Nonce not found');
            alert('Error: Security token not found. Please refresh the page.');
            $btn.prop('disabled', false).text('Use');
            return;
        }

        if (!ajaxUrl) {
            console.error('ajaxurl not defined');
            alert('Error: AJAX URL not defined. Please refresh the page.');
            $btn.prop('disabled', false).text('Use');
            return;
        }

        console.log('Sending AJAX request...');

        // Send AJAX request to update theme
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'twine_set_active_theme',
                theme_slug: themeSlug,
                nonce: nonceVal
            },
            success: function(response) {
                console.log('AJAX response:', response);
                if (response.success) {
                    console.log('Success! Reloading page...');
                    // Reload page to show new active theme in correct position
                    window.location.reload();
                } else {
                    console.error('Error response:', response.data);
                    alert('Error: ' + (response.data || 'Failed to activate theme'));
                    $btn.prop('disabled', false).text('Use');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error, xhr);
                alert('Error: Failed to activate theme');
                $btn.prop('disabled', false).text('Use');
            }
        });
    });

    // Make entire card clickable to preview
    $(document).on('click', '.twine-theme-card', function(e) {
        // Don't trigger if clicking on buttons or links
        if ($(e.target).closest('button, a').length > 0) {
            return;
        }

        var $card = $(this);
        var themeSlug = $card.data('theme');
        var previewUrl = $card.find('a[target="_blank"]').first().attr('href');

        // For default theme, construct preview URL
        if (!previewUrl && themeSlug === '') {
            previewUrl = window.location.origin + '/?twine_preview=';
        }

        if (previewUrl) {
            window.open(previewUrl, '_blank');
        }
    });

    // Delete theme
    $(document).on('click', '.twine-delete-theme-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var themeSlug = $btn.data('theme');
        var themeName = $btn.data('name');

        if (!confirm('Are you sure you want to delete the theme "' + themeName + '"? This action cannot be undone.')) {
            return;
        }

        // Show loading
        $btn.prop('disabled', true).text('Deleting...');

        // Send AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'twine_delete_theme',
                theme_slug: themeSlug,
                nonce: $('#twine_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    // Remove the card
                    $btn.closest('.twine-theme-card').fadeOut(300, function() {
                        $(this).remove();
                    });

                    // If this was the active theme, select default
                    if ($('#twine-theme-input').val() === themeSlug) {
                        $('#twine-theme-input').val('');
                        $('.twine-theme-card[data-theme=""]').addClass('active');
                    }
                } else {
                    alert('Error: ' + (response.data || 'Failed to delete theme'));
                    $btn.prop('disabled', false).text('Delete');
                }
            },
            error: function() {
                alert('Error: Failed to delete theme');
                $btn.prop('disabled', false).text('Delete');
            }
        });
    });
});
