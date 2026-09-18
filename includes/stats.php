<?php
defined('ABSPATH') || exit;

const WPFCS_HISTORY = 'wpfcs_history';

/**
 * Slugs als Lookup-Set. WPFC legt Cache-Verzeichnisse URL-dekodiert an
 * (siehe clear_cache_by_url), in der Datenbank stehen Slugs mit Umlauten
 * aber kodiert (%c3%bc) – daher vor dem Abgleich dekodieren.
 *
 * @param string[] $slugs
 * @return array<string, true>
 */
function wpfcs_slug_set(array $slugs): array {
    $set = [];
    foreach ($slugs as $slug) {
        $set[rawurldecode((string) $slug)] = true;
    }
    return $set;
}

/**
 * Ein einziger Durchlauf pro Cache-Verzeichnis sammelt Anzahl, Größe, Alter
 * und ordnet jede gecachte Seite einem Typ zu. Die Zuordnung nutzt nur die
 * Verzeichnisstruktur (category/<slug>, tag/<slug>, <…>/<beitrags-slug>) und
 * vier schlanke Slug-Abfragen – keine Permalink-Berechnung pro Beitrag.
 *
 * @return array<string, mixed>
 */
function wpfcs_collect_stats(): array {
    global $wpdb;
    $t0 = microtime(true);

    $post_slugs = wpfcs_slug_set($wpdb->get_col("SELECT post_name FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_name <> ''"));
    $page_slugs = wpfcs_slug_set($wpdb->get_col("SELECT post_name FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_name <> ''"));
    $term_slugs = static fn(string $tax): array => wpfcs_slug_set($wpdb->get_col($wpdb->prepare(
        "SELECT t.slug FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND tt.count > 0",
        $tax
    )));
    $cat_slugs = $term_slugs('category');
    $tag_slugs = $term_slugs('post_tag');

    $coverage = [
        'posts'      => ['label' => 'Beiträge', 'cached' => 0, 'total' => count($post_slugs)],
        'pages'      => ['label' => 'Seiten', 'cached' => 0, 'total' => count($page_slugs)],
        'categories' => ['label' => 'Kategorien', 'cached' => 0, 'total' => count($cat_slugs)],
        'tags'       => ['label' => 'Schlagwörter', 'cached' => 0, 'total' => count($tag_slugs)],
    ];
    $extra = ['home' => false, 'pagination' => 0, 'other' => 0];

    $areas = [];
    foreach (['all' => 'Seiten (Desktop)', 'wpfc-mobile-cache' => 'Seiten (Mobil)'] as $sub => $label) {
        $dir = wpfcs_cache_dir($sub);
        if (!is_dir($dir)) {
            continue;
        }
        $a = ['label' => $label, 'html' => 0, 'xml' => 0, 'bytes' => 0, 'oldest' => 0, 'newest' => 0];
        foreach (wpfcs_iterate($dir) as $file) {
            $name = $file->getFilename();
            $a['bytes'] += $file->getSize();
            if ('index.xml' === $name) {
                $a['xml']++;
                continue;
            }
            if ('index.html' !== $name) {
                continue;
            }
            $a['html']++;
            $mtime       = $file->getMTime();
            $a['oldest'] = $a['oldest'] ? min($a['oldest'], $mtime) : $mtime;
            $a['newest'] = max($a['newest'], $mtime);

            if ('all' !== $sub) {
                continue;
            }
            $rel = trim(substr($file->getPath(), strlen($dir)), '/');
            if ('' === $rel) {
                $extra['home'] = true;
            } elseif (preg_match('#(^|/)page/\d+$#', $rel)) {
                $extra['pagination']++;
            } elseif (preg_match('#^category/(.+)$#', $rel, $m)) {
                // Unterkategorien liegen als category/eltern/kind – maßgeblich ist der letzte Teil.
                isset($cat_slugs[basename($m[1])]) ? $coverage['categories']['cached']++ : $extra['other']++;
            } elseif (preg_match('#^tag/([^/]+)$#', $rel, $m)) {
                isset($tag_slugs[$m[1]]) ? $coverage['tags']['cached']++ : $extra['other']++;
            } elseif (preg_match('#^(author|feed|comments|type|tag|category)(/|$)#', $rel)) {
                $extra['other']++;
            } elseif (isset($post_slugs[basename($rel)]) && str_contains($rel, '/')) {
                // Permalink /%category%/%postname%/ – Beiträge liegen mindestens eine Ebene tief.
                $coverage['posts']['cached']++;
            } elseif (isset($page_slugs[basename($rel)])) {
                $coverage['pages']['cached']++;
            } elseif (isset($post_slugs[basename($rel)])) {
                $coverage['posts']['cached']++;
            } else {
                $extra['other']++;
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

    $walk = microtime(true) - $t0;

    return [
        'version'  => WPFCS_VERSION,
        'time'     => time(),
        'duration' => $walk,
        'areas'    => $areas,
        'minified' => $minified,
        'widget'   => $widget,
        'coverage' => $coverage,
        'extra'    => $extra,
        'hot'      => wpfcs_hot_uncached(),
        'slots'    => wpfcs_slot_errors(),
    ];
}

/**
 * Meistgelesene Beiträge der letzten 14 Tage, die gerade NICHT im Cache
 * liegen – genau die erzeugen die meiste PHP-Last. Nutzt die Aufrufzählung
 * des Themes "Linux und Ich" (lui_views_YYYYMMDD, Rangliste per Transient
 * gecacht); ohne dieses Theme entfällt der Abschnitt.
 *
 * @return array{available: bool, days?: int, items?: array<int, array{id: int, title: string, url: string, views: int}>, checked?: int}
 */
function wpfcs_hot_uncached(int $days = 14, int $limit = 10): array {
    if (!function_exists('lui_get_trending_post_ids') || !function_exists('lui_get_recent_view_counts')) {
        return ['available' => false];
    }
    $ids = lui_get_trending_post_ids(60, $days);
    if (empty($ids)) {
        return ['available' => true, 'days' => $days, 'items' => [], 'checked' => 0];
    }
    $views = lui_get_recent_view_counts($days, $ids);
    _prime_post_caches($ids, true, false);

    $items = [];
    foreach ($ids as $id) {
        $post = get_post($id);
        if (!$post || 'publish' !== $post->post_status || 'post' !== $post->post_type) {
            continue;
        }
        if (wpfcs_post_cache_mtime($post->ID)) {
            continue;
        }
        $items[] = [
            'id'    => $post->ID,
            'title' => get_the_title($post),
            'url'   => (string) get_permalink($post),
            'views' => (int) ($views[$post->ID] ?? 0),
        ];
        if (count($items) >= $limit) {
            break;
        }
    }
    return ['available' => true, 'days' => $days, 'items' => $items, 'checked' => count($ids)];
}

/**
 * Tageswert der Abdeckung für den Verlauf fortschreiben (letzter Wert des
 * Tages gewinnt), maximal 30 Tage. Eigene Option mit autoload=off.
 *
 * @param array<string, mixed> $stats
 */
function wpfcs_record_history(array $stats): void {
    $posts = $stats['coverage']['posts'];
    if ($posts['total'] < 1) {
        return;
    }
    $history = (array) get_option(WPFCS_HISTORY, []);
    $history[wp_date('Y-m-d')] = [
        'posts' => round(100 * $posts['cached'] / $posts['total'], 1),
        'pages' => (int) ($stats['areas']['all']['html'] ?? 0),
    ];
    ksort($history);
    update_option(WPFCS_HISTORY, array_slice($history, -30, null, true), false);
}
