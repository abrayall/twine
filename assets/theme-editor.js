jQuery(document).ready(function($) {
    // Initialize color pickers
    $('.color-picker').wpColorPicker();

    // Generate session key (current time in seconds since epoch)
    var sessionKey = Math.floor(Date.now() / 1000);
    console.log('Theme editor session key:', sessionKey);

    // Get the preview iframe
    var $previewIframe = $('.twine-admin-preview-iframe');
    if ($previewIframe.length === 0) {
        return; // No preview iframe on this page
    }

    // Function to update preview with current form values
    function updatePreview() {
        console.log('Updating preview with session key:', sessionKey);

        // Get current iframe URL
        var currentSrc = $previewIframe.attr('src');
        if (!currentSrc) {
            return;
        }

        // Parse URL and add/update key parameter
        var url = new URL(currentSrc, window.location.href);
        url.searchParams.set('key', sessionKey);

        // Update iframe src
        $previewIframe.attr('src', url.toString());
    }

    // Function to auto-save theme changes
    function autoSaveTheme() {
        console.log('Auto-saving theme...');

        var formData = $('#twine-theme-editor-form').serialize();
        formData += '&key=' + sessionKey;

        $.ajax({
            url: $('#twine-theme-editor-form').attr('action'),
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('Theme auto-saved successfully');
                updatePreview();
            },
            error: function(xhr, status, error) {
                console.error('Failed to auto-save theme:', error);
            }
        });
    }

    // Debounce function to limit auto-save frequency
    var autoSaveTimeout;
    function debounceAutoSave() {
        clearTimeout(autoSaveTimeout);
        autoSaveTimeout = setTimeout(autoSaveTheme, 500); // Wait 500ms after last change
    }

    // Listen for changes on form fields
    $('#twine-theme-editor-form input, #twine-theme-editor-form select, #twine-theme-editor-form textarea').on('input change', function() {
        console.log('Form field changed');
        debounceAutoSave();
    });

    // Listen for color picker changes (WordPress color picker specific)
    $('.color-picker').wpColorPicker({
        change: function() {
            console.log('Color changed');
            debounceAutoSave();
        }
    });
});
