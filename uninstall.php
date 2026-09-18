<?php
// Beim Löschen des Plugins eigene Optionen, Transients und Cron-Jobs entfernen.
defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['wpfcs_history', 'wpfcs_warm_queue', 'wpfcs_warm_state', 'wpfcs_own_purges'] as $option) {
    delete_option($option);
}
delete_transient('wpfcs_stats');
delete_transient('wpfcs_warm_lock');
wp_clear_scheduled_hook('wpfcs_daily');
wp_clear_scheduled_hook('wpfcs_warm_tick');
