<?php
/*
 * Plugin Name: WPFC Cache-Statistiken im Dashboard
 * Plugin URI:  https://github.com/linuxundich/wpfc-cache-stats-dashboard
 * Description: Zeigt WP-Fastest-Cache-Statistiken im Admin-Dashboard – Cache-Abdeckung, meistgelesene ungecachte Beiträge mit gedrosseltem Vorwärmen, Verlauf und Warnungen – plus Cache-Spalte in der Beitragsliste.
 * Version:     4.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author:      Christoph Langner
 * Author URI:  https://linuxundich.de
 * Text Domain: wpfc-cache-stats-widget
 */

defined('ABSPATH') || exit;

const WPFCS_VERSION   = '4.0';
const WPFCS_TRANSIENT = 'wpfcs_stats';
const WPFCS_TTL       = 15 * MINUTE_IN_SECONDS;

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/stats.php';
require_once __DIR__ . '/includes/warmup.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/posts-column.php';

add_action('wp_dashboard_setup', 'wpfcs_register_widget');
add_action('admin_enqueue_scripts', 'wpfcs_enqueue_assets');
add_action('wp_ajax_wpfcs_stats', 'wpfcs_ajax_stats');
add_action('wp_ajax_wpfcs_clear', 'wpfcs_ajax_clear');
add_filter('plugin_row_meta', 'wpfcs_plugin_row_meta', 10, 2);

/*
 * Täglicher Messpunkt für den Verlauf der Cache-Abdeckung. Geplant über
 * "init" statt nur per Aktivierungs-Hook, damit auch ein per git pull
 * aktualisiertes (also nicht neu aktiviertes) Plugin den Job bekommt;
 * wp_next_scheduled() liest nur die ohnehin geladene cron-Option.
 */
add_action('init', 'wpfcs_schedule_daily');
add_action('wpfcs_daily', 'wpfcs_refresh_stats');
register_deactivation_hook(__FILE__, 'wpfcs_deactivate');

function wpfcs_schedule_daily(): void {
    if (!wp_next_scheduled('wpfcs_daily')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpfcs_daily');
    }
}

function wpfcs_deactivate(): void {
    wp_clear_scheduled_hook('wpfcs_daily');
    wp_clear_scheduled_hook('wpfcs_warm_tick');
}

/**
 * Nur für Admins registrieren – vorher bekam jede Rolle ein leeres Widget.
 */
function wpfcs_register_widget(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    wp_add_dashboard_widget('wpfc_cache_stats_widget', 'WP Fastest Cache: Statistiken', 'wpfcs_render_widget');
}

function wpfcs_enqueue_assets(string $hook): void {
    if ('index.php' !== $hook || !current_user_can('manage_options')) {
        return;
    }
    $url = plugin_dir_url(__FILE__);
    wp_enqueue_script('wpfcs-dashboard', $url . 'wpfc-dashboard.js', [], WPFCS_VERSION, true);
    wp_localize_script('wpfcs-dashboard', 'wpfcsConfig', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('wpfcs'),
    ]);
    wp_enqueue_style('wpfcs-dashboard', $url . 'wpfc-dashboard.css', [], WPFCS_VERSION);
}

/**
 * Das Widget selbst rechnet nie: Es zeigt nur die im Transient gespeicherten
 * Statistiken. Fehlen die, lädt das JavaScript sie nach dem Seitenaufbau per
 * AJAX nach – das Dashboard wartet so nie auf einen Verzeichnisdurchlauf.
 */
function wpfcs_render_widget(): void {
    echo '<div id="wpfcs-root">';
    $stats = get_transient(WPFCS_TRANSIENT);
    if (is_array($stats) && ($stats['version'] ?? '') === WPFCS_VERSION) {
        wpfcs_render_stats($stats);
    } else {
        echo '<p class="wpfcs-loading" data-wpfcs-autoload="1">⏳ Statistiken werden ermittelt …</p>';
    }
    echo '</div>';
}

/**
 * Berechnet die Statistik neu, legt sie im Transient ab und schreibt den
 * Tageswert für den Verlauf fort.
 *
 * @return array<string, mixed>
 */
function wpfcs_refresh_stats(): array {
    $stats = wpfcs_collect_stats();
    set_transient(WPFCS_TRANSIENT, $stats, WPFCS_TTL);
    wpfcs_record_history($stats);
    return $stats;
}

function wpfcs_check_ajax(): void {
    check_ajax_referer('wpfcs', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nicht autorisiert.'], 403);
    }
}

/**
 * AJAX: Statistiken liefern. force=1 berechnet neu, sonst wird der Transient
 * genutzt, solange er gültig ist.
 */
function wpfcs_ajax_stats(): void {
    wpfcs_check_ajax();
    $stats = !empty($_POST['force']) ? false : get_transient(WPFCS_TRANSIENT);
    if (!is_array($stats) || ($stats['version'] ?? '') !== WPFCS_VERSION) {
        $stats = wpfcs_refresh_stats();
    }
    ob_start();
    wpfcs_render_stats($stats);
    wp_send_json_success(['html' => ob_get_clean()]);
}

/**
 * AJAX: Cache leeren über die offizielle WPFC-Schnittstelle statt über
 * shell_exec + WP-CLI. deleteCache() verschiebt den Cache nur nach
 * cache/tmpWpfc – das eigentliche Löschen erledigt WPFC später selbst.
 */
function wpfcs_ajax_clear(): void {
    wpfcs_check_ajax();
    if (!has_action('wpfc_clear_all_cache')) {
        wp_send_json_error(['message' => 'WP Fastest Cache ist nicht aktiv.']);
    }
    $minified = !empty($_POST['minified']);
    do_action('wpfc_clear_all_cache', $minified);
    wpfcs_record_own_purge();
    delete_transient(WPFCS_TRANSIENT);

    wp_send_json_success([
        'message' => $minified ? 'Seiten-Cache und minifizierte CSS/JS geleert.' : 'Seiten-Cache geleert.',
    ]);
}

function wpfcs_plugin_row_meta(array $links, string $file): array {
    if (plugin_basename(__FILE__) === $file) {
        $links[] = '<a href="https://github.com/linuxundich/wpfc-cache-stats-dashboard" target="_blank" rel="noopener">Plugin-Website aufrufen</a>';
    }
    return $links;
}
