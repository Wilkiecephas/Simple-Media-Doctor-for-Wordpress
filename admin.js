jQuery(document).ready(function($) {
    // Initialize Chart.js for statistics
    if (typeof Chart !== 'undefined') {
        var ctx = document.getElementById('statsChart').getContext('2d');
        var statsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Media Processed',
                    data: [120, 190, 180, 250, 200, 300],
                    borderColor: '#0073aa',
                    backgroundColor: 'rgba(0, 115, 170, 0.1)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    function updateMedia(action, postId) {
        $.ajax({
            url: smd_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'smd_update_media',
                nonce: smd_ajax.nonce,
                post_id: postId,
                action_type: action
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.message, 'success');
                } else {
                    showNotification(response.message, 'error');
                }
            }
        });
    }

    function showNotification(message, type) {
        var notification = $('<div class="notice notice-' + type + '"><p>' + message + '</p></div>');
        $('.wrap').prepend(notification);
        
        setTimeout(function() {
            notification.fadeOut();
        }, 3000);
    }

    function addNewAd() {
        // Implementation for adding new ad
        showNotification('Add new ad functionality', 'info');
    }

    function editAd(adId) {
        // Implementation for editing ad
        showNotification('Edit ad ID: ' + adId, 'info');
    }

    function deleteAd(adId) {
        if (confirm('Are you sure you want to delete this ad?')) {
            // Implementation for deleting ad
            showNotification('Delete ad ID: ' + adId, 'info');
        }
    }

    // Add visual feedback for form interactions
    $('.smd-input, .smd-select').on('focus', function() {
        $(this).css('border-color', '#0073aa');
    }).on('blur', function() {
        $(this).css('border-color', '#ddd');
    });

    // Visualizer animation
    $('.smd-waveform-bar').each(function(index) {
        var $bar = $(this);
        var originalHeight = $bar.height();
        
        setInterval(function() {
            var newHeight = originalHeight + Math.random() * 30;
            $bar.animate({
                height: newHeight + 'px'
            }, 300);
        }, 500 + index * 100);
    });

    window.addNewAd = addNewAd;
    window.editAd = editAd;
    window.deleteAd = deleteAd;
});