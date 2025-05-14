<?php
/**
 * Plugin Name: WPFC Cache-Statistiken im Dashboard
 * Description: Zeigt WP Fastest Cache-Statistiken im Admin-Dashboard und bietet einen Button zum Leeren des Caches.
 * Version:     2.0
 * Author:      Christoph Langner
 */

add_action('wp_dashboard_setup', 'wpfc_cache_stats_widget');
add_action('admin_enqueue_scripts', 'wpfc_enqueue_ajax_assets');
add_action('wp_ajax_wpfc_clear_cache', 'wpfc_clear_cache_ajax');
add_action('wp_ajax_wpfc_cache_stats_refresh', 'wpfc_cache_stats_refresh');

/**
 * Widget im Dashboard registrieren
 */
function wpfc_cache_stats_widget() {
    wp_add_dashboard_widget(
        'wpfc_cache_stats_widget',
        'WP Fastest Cache: Statistiken',
        'wpfc_cache_stats_display'
    );
}

/**
 * Styles und Skripte für AJAX laden
 */
function wpfc_enqueue_ajax_assets($hook) {
    if ('index.php' !== $hook) {
        return;
    }

    wp_enqueue_script('wpfc-dashboard-script', plugin_dir_url(__FILE__) . 'wpfc-dashboard.js', ['jquery'], null, true);
    wp_localize_script('wpfc-dashboard-script', 'wpfc_ajax_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wpfc_clear_cache_nonce')
    ]);

    wp_enqueue_style('wpfc-dashboard-style', plugin_dir_url(__FILE__) . 'wpfc-dashboard.css');
}

/**
 * Dashboard-Inhalt anzeigen
 */
function wpfc_cache_stats_display() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $base    = WP_CONTENT_DIR . '/cache/';
    $desktop = $base . 'all/';
    $mobile  = $base . 'wpfc-mobile-cache/';
    $minify  = $base . 'wpfc-minified/';
    $widget  = $base . 'wpfc-widget-cache/';

    echo '<table class="widefat striped" style="width:100%; max-width:700px;">';
    echo '<thead><tr><th>🗂️ Cache-Bereich</th><th>📊 Anzahl Dateien</th></tr></thead><tbody>';
    echo '<tr><td>📄 Seiten (Desktop)</td><td>' . count_named_files($desktop, 'index.html') . '</td></tr>';
    echo '<tr><td>📱 Seiten (Mobil)</td><td>'   . count_named_files($mobile,  'index.html') . '</td></tr>';
    echo '<tr><td>📰 Feeds (Desktop)</td><td>'  . count_named_files($desktop, 'index.xml')  . '</td></tr>';
    echo '<tr><td>📰 Feeds (Mobil)</td><td>'    . count_named_files($mobile,  'index.xml')  . '</td></tr>';
    echo '<tr><td>🎨 CSS-Dateien</td><td>'      . count_files_by_extension($minify, 'css')  . '</td></tr>';
    echo '<tr><td>⚙️ JS-Dateien</td><td>'       . count_files_by_extension($minify, 'js')   . '</td></tr>';
    echo '<tr><td>🗄️ Widget-Cache</td><td>'     . count_all_files($widget)                 . '</td></tr>';
    echo '</tbody></table>';

    echo '<p style="font-size:smaller;color:#666;">Gezählt werden rekursiv alle <code>index.html</code>- und <code>index.xml</code>-Dateien sowie <code>.css</code> und <code>.js</code> im Minify-Cache.</p>';

    echo '<p><button id="wpfc-clear-cache-btn" class="button button-primary">🧹 Cache leeren</button> <span id="wpfc-clear-cache-status" style="margin-left:10px;"></span></p>';
}

/**
 * AJAX-Aktion: Cache leeren
 */
function wpfc_clear_cache_ajax() {
    check_ajax_referer('wpfc_clear_cache_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nicht autorisiert.']);
    }

    if (!function_exists('shell_exec')) {
        wp_send_json_error(['message' => 'Fehler: shell_exec() ist deaktiviert.']);
    }

    $wp_cli = defined('WPFC_WP_CLI_PATH') ? WPFC_WP_CLI_PATH : 'wp';
    $command = escapeshellcmd("$wp_cli fastest-cache clear all");

    $output = shell_exec($command . ' 2>&1');
    if ($output === null) {
        wp_send_json_error(['message' => 'Befehl konnte nicht ausgeführt werden.']);
    }

    wp_send_json_success(['message' => 'Cache erfolgreich geleert.', 'output' => trim($output)]);
}

/**
 * AJAX-Aktion: HTML des Widgets neu laden
 */
function wpfc_cache_stats_refresh() {
    ob_start();
    wpfc_cache_stats_display();
    $html = ob_get_clean();
    wp_send_json_success(['html' => $html]);
}

/**
 * Zählt bestimmte Dateien rekursiv
 */
function count_named_files($dir, $filename) {
    if (!is_dir($dir)) {
        return '<span style="color:gray;">nicht gefunden</span>';
    }

    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getFilename()) === strtolower($filename)) {
            $count++;
        }
    }

    return $count;
}

function count_files_by_extension($dir, $extension) {
    if (!is_dir($dir)) {
        return '<span style="color:gray;">nicht gefunden</span>';
    }

    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === strtolower($extension)) {
            $count++;
        }
    }

    return $count;
}

function count_all_files($dir) {
    if (!is_dir($dir)) {
        return '<span style="color:gray;">nicht gefunden</span>';
    }

    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->isFile()) {
            $count++;
        }
    }

    return $count;
}
