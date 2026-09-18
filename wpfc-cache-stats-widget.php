<?php
/*
 * Plugin Name: WPFC Cache-Statistiken im Dashboard
 * Plugin URI:  https://github.com/linuxundich/wpfc-cache-stats-dashboard
 * Description: Zeigt WP-Fastest-Cache-Statistiken im Admin-Dashboard – inklusive Cache-Abdeckung der Beiträge – und bietet einen Button zum Leeren des Caches.
 * Version:     3.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author:      Christoph Langner
 * Author URI:  https://linuxundich.de
 * Text Domain: wpfc-cache-stats-widget
 */

defined('ABSPATH') || exit;

const WPFCS_VERSION   = '3.0';
const WPFCS_TRANSIENT = 'wpfcs_stats';
const WPFCS_TTL       = 15 * MINUTE_IN_SECONDS;

add_action('wp_dashboard_setup', 'wpfcs_register_widget');
add_action('admin_enqueue_scripts', 'wpfcs_enqueue_assets');
add_action('wp_ajax_wpfcs_stats', 'wpfcs_ajax_stats');
add_action('wp_ajax_wpfcs_clear', 'wpfcs_ajax_clear');
add_filter('plugin_row_meta', 'wpfcs_plugin_row_meta', 10, 2);

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
    if (is_array($stats)) {
        wpfcs_render_stats($stats);
    } else {
        echo '<p class="wpfcs-loading" data-wpfcs-autoload="1">⏳ Statistiken werden ermittelt …</p>';
    }
    echo '</div>';
}

/**
 * AJAX: Statistiken liefern. force=1 berechnet neu, sonst wird der Transient
 * genutzt, solange er gültig ist.
 */
function wpfcs_ajax_stats(): void {
    check_ajax_referer('wpfcs', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nicht autorisiert.'], 403);
    }
    $stats = !empty($_POST['force']) ? false : get_transient(WPFCS_TRANSIENT);
    if (!is_array($stats)) {
        $stats = wpfcs_collect_stats();
        set_transient(WPFCS_TRANSIENT, $stats, WPFCS_TTL);
    }
    ob_start();
    wpfcs_render_stats($stats);
    wp_send_json_success(['html' => ob_get_clean()]);
}

/**
 * AJAX: Cache leeren über die offizielle WPFC-Schnittstelle statt über
 * shell_exec + WP-CLI (brauchte eine Shell, WP-CLI im PATH des Webservers und
 * lief in einem eigenen Prozess). deleteCache() verschiebt den Cache nur nach
 * cache/tmpWpfc – das eigentliche Löschen erledigt WPFC später selbst.
 */
function wpfcs_ajax_clear(): void {
    check_ajax_referer('wpfcs', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nicht autorisiert.'], 403);
    }
    if (!has_action('wpfc_clear_all_cache')) {
        wp_send_json_error(['message' => 'WP Fastest Cache ist nicht aktiv.']);
    }
    $minified = !empty($_POST['minified']);
    do_action('wpfc_clear_all_cache', $minified);
    delete_transient(WPFCS_TRANSIENT);

    wp_send_json_success([
        'message' => $minified ? 'Seiten-Cache und minifizierte CSS/JS geleert.' : 'Seiten-Cache geleert.',
    ]);
}

/**
 * Cache-Pfad so ermitteln, wie WPFC selbst es tut (berücksichtigt z. B.
 * Installationen mit Cache-Unterordner pro Hostname).
 */
function wpfcs_cache_dir(string $sub): string {
    global $wpfc;
    if (is_object($wpfc) && method_exists($wpfc, 'getWpContentDir')) {
        return rtrim($wpfc->getWpContentDir('/cache/' . $sub), '/') . '/';
    }
    return WP_CONTENT_DIR . '/cache/' . $sub . '/';
}

/**
 * Ein einziger Durchlauf pro Cache-Verzeichnis (vorher: fünf Durchläufe, zwei
 * davon doppelt über dasselbe Verzeichnis) sammelt Anzahl, Größe und Alter.
 *
 * Cache-Abdeckung der Beiträge: Bei der Permalink-Struktur
 * /%category%/%postname%/ liegt jeder gecachte Beitrag unter
 * cache/all/<kategorie>/<slug>/index.html. Abgeglichen wird der letzte
 * Pfadteil gegen die Slugs aller veröffentlichten Beiträge – eine schlanke
 * Abfrage nur auf post_name, statt 1.600 Permalinks zu berechnen.
 *
 * @return array<string, mixed>
 */
function wpfcs_collect_stats(): array {
    global $wpdb;
    $t0 = microtime(true);

    $slugs = array_flip($wpdb->get_col(
        "SELECT post_name FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_name <> ''"
    ));

    $areas = [];
    $posts_cached = 0;
    foreach (['all' => 'Seiten (Desktop)', 'wpfc-mobile-cache' => 'Seiten (Mobil)'] as $sub => $label) {
        $dir = wpfcs_cache_dir($sub);
        if (!is_dir($dir)) {
            continue;
        }
        $a = ['label' => $label, 'html' => 0, 'xml' => 0, 'bytes' => 0, 'oldest' => 0, 'newest' => 0];
        foreach (wpfcs_iterate($dir) as $file) {
            $name = $file->getFilename();
            $a['bytes'] += $file->getSize();
            if ('index.html' === $name) {
                $a['html']++;
                $mtime = $file->getMTime();
                $a['oldest'] = $a['oldest'] ? min($a['oldest'], $mtime) : $mtime;
                $a['newest'] = max($a['newest'], $mtime);
                if ('all' === $sub) {
                    $rel = trim(substr($file->getPath(), strlen($dir)), '/');
                    if ('' !== $rel && !preg_match('#^(category|tag|page|author|feed|comments)(/|$)#', $rel)
                        && isset($slugs[basename($rel)])) {
                        $posts_cached++;
                    }
                }
            } elseif ('index.xml' === $name) {
                $a['xml']++;
            }
        }
        $areas[$sub] = $a;
    }

    $minified = ['css' => 0, 'js' => 0, 'bytes' => 0];
    $min_dir  = wpfcs_cache_dir('wpfc-minified');
    if (is_dir($min_dir)) {
        foreach (wpfcs_iterate($min_dir) as $file) {
            $ext = strtolower($file->getExtension());
            if (isset($minified[$ext])) {
                $minified[$ext]++;
            }
            $minified['bytes'] += $file->getSize();
        }
    }

    $widget_dir = wpfcs_cache_dir('wpfc-widget-cache');
    $widget     = null;
    if (is_dir($widget_dir)) {
        $widget = 0;
        foreach (wpfcs_iterate($widget_dir) as $file) {
            $widget++;
        }
    }

    return [
        'time'         => time(),
        'duration'     => microtime(true) - $t0,
        'areas'        => $areas,
        'minified'     => $minified,
        'widget'       => $widget,
        'posts_total'  => count($slugs),
        'posts_cached' => $posts_cached,
    ];
}

/**
 * @return iterable<SplFileInfo>
 */
function wpfcs_iterate(string $dir): iterable {
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                yield $file;
            }
        }
    } catch (UnexpectedValueException $e) {
        // Verzeichnis verschwindet während des Durchlaufs (WPFC leert gerade) – Teilergebnis reicht.
        return;
    }
}

/**
 * Letzte Einträge aus WPFCs eigenem Lösch-Protokoll (nur WPFC Premium). Die
 * Zeitstempel speichert WPFC bereits in Ortszeit (current_time('timestamp')),
 * daher gmdate() statt wp_date(), sonst würde doppelt verschoben.
 *
 * @return array<int, array{time: int, label: string}>
 */
function wpfcs_recent_purges(int $limit = 3): array {
    $raw  = get_option('WpFcDeleteCacheLogs');
    $logs = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($logs)) {
        return [];
    }
    $labels = [
        'optionsPageRequest' => 'Einstellungen gespeichert (kompletter Cache)',
        'deleteCacheToolbar' => 'Über die Admin-Leiste',
        'singleDeleteCache'  => 'Einzelne Seite',
        'detectNewComment'   => 'Neuer Kommentar',
        'detectEditComment'  => 'Kommentar bearbeitet',
        'setSchedule'        => 'Zeitplan',
        'Cache Timeout'      => 'Cache-Timeout',
        'deleteCache'        => 'Kompletter Cache',
    ];
    $out = [];
    foreach ($logs as $entry) {
        $fn = (string) ($entry['via']['function'] ?? '');
        if (isset($entry['via']['args'])) {
            $label = 'Beitrag veröffentlicht/geändert';
        } else {
            $label = $fn;
            foreach ($labels as $needle => $text) {
                if (str_contains($fn, $needle)) {
                    $label = $text;
                    break;
                }
            }
        }
        // Mehrere Einträge derselben Sekunde (WPFC protokolliert Auslöser und Aktion getrennt) zusammenfassen.
        $ts = (int) ($entry['date'] ?? 0);
        if ($out && end($out)['time'] === $ts) {
            continue;
        }
        $out[] = ['time' => $ts, 'label' => $label];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function wpfcs_format_age(int $ts): string {
    return $ts ? sprintf('vor %s', human_time_diff($ts, time())) : '–';
}

/**
 * @param array<string, mixed> $s
 */
function wpfcs_render_stats(array $s): void {
    $wpfc_active = has_action('wpfc_clear_all_cache');
    $options     = json_decode((string) get_option('WpFastestCache'), true) ?: [];

    if (!$wpfc_active) {
        echo '<p class="wpfcs-warn">WP Fastest Cache ist nicht aktiv – Statistiken und „Cache leeren“ funktionieren nur mit aktivem Plugin.</p>';
    }

    // Kennzahl zuerst: Wie viele Beiträge kann der Server ohne PHP ausliefern?
    if ($s['posts_total'] > 0) {
        $pct = (int) round(100 * $s['posts_cached'] / $s['posts_total']);
        printf(
            '<div class="wpfcs-coverage"><div class="wpfcs-coverage-head"><strong>Beiträge im Cache</strong><span>%s von %s (%d %%)</span></div>'
            . '<div class="wpfcs-meter"><span style="width:%d%%"></span></div>'
            . '<p class="description">Nicht gecachte Beiträge muss WordPress bei jedem Aufruf neu erzeugen.</p></div>',
            esc_html(number_format_i18n($s['posts_cached'])),
            esc_html(number_format_i18n($s['posts_total'])),
            $pct,
            $pct
        );
    }

    echo '<table class="widefat striped wpfcs-table"><thead><tr><th>Cache-Bereich</th><th class="num">Dateien</th><th class="num">Größe</th></tr></thead><tbody>';
    foreach ($s['areas'] as $a) {
        printf('<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td></tr>', esc_html($a['label']), esc_html(number_format_i18n($a['html'])), esc_html(size_format($a['bytes'], 1)));
        if ($a['xml']) {
            printf('<tr><td>%s – Feeds</td><td class="num">%s</td><td></td></tr>', esc_html($a['label']), esc_html(number_format_i18n($a['xml'])));
        }
    }
    printf(
        '<tr><td>Minifiziertes CSS / JS</td><td class="num">%s / %s</td><td class="num">%s</td></tr>',
        esc_html(number_format_i18n($s['minified']['css'])),
        esc_html(number_format_i18n($s['minified']['js'])),
        esc_html(size_format($s['minified']['bytes'], 1))
    );
    if (null !== $s['widget']) {
        printf('<tr><td>Widget-Cache</td><td class="num">%s</td><td></td></tr>', esc_html(number_format_i18n($s['widget'])));
    }
    echo '</tbody></table>';

    $desktop = $s['areas']['all'] ?? null;
    echo '<ul class="wpfcs-facts">';
    if ($desktop && $desktop['html']) {
        printf('<li>Älteste Cache-Seite: %s, neueste: %s</li>', esc_html(wpfcs_format_age($desktop['oldest'])), esc_html(wpfcs_format_age($desktop['newest'])));
    }
    printf(
        '<li>Preload: %s</li>',
        !empty($options['wpFastestCachePreload'])
            ? esc_html(sprintf('an, %d Seiten je Durchlauf', (int) ($options['wpFastestCachePreload_number'] ?? 0)))
            : 'aus'
    );
    echo '</ul>';

    $purges = wpfcs_recent_purges();
    if ($purges) {
        echo '<p class="wpfcs-purges-head">Letzte Cache-Löschungen:</p><ul class="wpfcs-facts">';
        foreach ($purges as $p) {
            printf('<li>%s – %s</li>', esc_html(gmdate('d.m. H:i', $p['time'])), esc_html($p['label']));
        }
        echo '</ul>';
    }

    printf(
        '<p class="wpfcs-stand">Stand: %s (Berechnung %.1f s) · <a href="#" class="wpfcs-refresh">Aktualisieren</a></p>',
        esc_html(wpfcs_format_age((int) $s['time'])),
        (float) $s['duration']
    );

    if ($wpfc_active) {
        echo '<div class="wpfcs-actions">'
            . '<button type="button" class="button wpfcs-clear">Cache leeren</button> '
            . '<label><input type="checkbox" class="wpfcs-minified"> auch minifiziertes CSS/JS</label>'
            . '<span class="wpfcs-status" role="status"></span></div>';
    }
}

function wpfcs_plugin_row_meta(array $links, string $file): array {
    if (plugin_basename(__FILE__) === $file) {
        $links[] = '<a href="https://github.com/linuxundich/wpfc-cache-stats-dashboard" target="_blank" rel="noopener">Plugin-Website aufrufen</a>';
    }
    return $links;
}
