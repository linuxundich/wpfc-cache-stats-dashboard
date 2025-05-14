jQuery(document).ready(function($) {
    const $btn = $('#wpfc-clear-cache-btn');
    const $status = $('#wpfc-clear-cache-status');
    const $widget = $('#wpfc_cache_stats_widget');

    $btn.on('click', function(e) {
        e.preventDefault();

        $btn.prop('disabled', true);
        $status.html('⏳ Bitte warten...');

        $.post(wpfc_ajax_obj.ajax_url, {
            action: 'wpfc_clear_cache',
            nonce: wpfc_ajax_obj.nonce
        }, function(response) {
            if (response.success) {
                $status.html('✅ ' + response.data.message);

                // Danach Widget-Inhalt neu laden
                $.post(wpfc_ajax_obj.ajax_url, {
                    action: 'wpfc_cache_stats_refresh'
                }, function(refreshResponse) {
                    if (refreshResponse.success) {
                        $widget.html(refreshResponse.data.html);
                    } else {
                        $widget.append('<p>⚠️ Fehler beim Neuladen der Statistiken.</p>');
                    }
                });

            } else {
                $status.html('❌ ' + response.data.message);
            }

            $btn.prop('disabled', false);
        });
    });
});
