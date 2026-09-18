<?php
defined('ABSPATH') || exit;

/*
 * Cache-Spalte in der Beitragsliste samt Zeilenaktionen.
 *
 * Kosten: pro angezeigter Zeile ein file_exists() auf die Cache-Datei; den
 * Permalink berechnet die Listenansicht ohnehin schon für den "Ansehen"-Link.
 * Die Aktionen laufen über admin-post.php (normale Links mit Nonce, kein AJAX).
 */

add_filter('manage_post_posts_columns', 'wpfcs_posts_column');
add_action('manage_post_posts_custom_column', 'wpfcs_posts_column_value', 10, 2);
add_filter('post_row_actions', 'wpfcs_post_row_actions', 10, 2);
add_action('admin_post_wpfcs_post_clear', 'wpfcs_handle_post_clear');
add_action('admin_post_wpfcs_post_warm', 'wpfcs_handle_post_warm');
add_action('admin_notices', 'wpfcs_post_notices');
add_action('admin_head-edit.php', 'wpfcs_posts_column_css');

function wpfcs_posts_column(array $columns): array {
    if (current_user_can('manage_options') && has_action('wpfc_clear_all_cache')) {
        $columns['wpfcs_cache'] = 'Cache';
    }
    return $columns;
}

function wpfcs_posts_column_value(string $column, int $post_id): void {
    if ('wpfcs_cache' !== $column || 'publish' !== get_post_status($post_id)) {
        return;
    }
    $mtime = wpfcs_post_cache_mtime($post_id);
    echo $mtime
        ? '<span class="wpfcs-yes" title="' . esc_attr(wp_date('d.m.Y H:i', $mtime)) . '">✓ ' . esc_html(human_time_diff($mtime)) . '</span>'
        : '<span class="wpfcs-no">–</span>';
}

function wpfcs_post_row_actions(array $actions, WP_Post $post): array {
    if ('post' !== $post->post_type || 'publish' !== $post->post_status || !current_user_can('manage_options') || !has_action('wpfc_clear_all_cache')) {
        return $actions;
    }
    $action = wpfcs_post_cache_mtime($post->ID) ? 'clear' : 'warm';
    $url    = wp_nonce_url(
        add_query_arg(['action' => 'wpfcs_post_' . $action, 'post' => $post->ID], admin_url('admin-post.php')),
        'wpfcs_post_' . $action . '_' . $post->ID
    );
    $actions['wpfcs_' . $action] = sprintf('<a href="%s">%s</a>', esc_url($url), 'clear' === $action ? 'Cache leeren' : 'Vorwärmen');
    return $actions;
}

function wpfcs_post_action_guard(string $action): int {
    $post_id = absint($_GET['post'] ?? 0);
    check_admin_referer('wpfcs_post_' . $action . '_' . $post_id);
    if (!current_user_can('manage_options') || 'publish' !== get_post_status($post_id)) {
        wp_die('Nicht erlaubt.', 403);
    }
    return $post_id;
}

function wpfcs_post_redirect(string $msg): void {
    $back = wp_get_referer() ?: admin_url('edit.php');
    wp_safe_redirect(add_query_arg('wpfcs_msg', $msg, remove_query_arg('wpfcs_msg', $back)));
    exit;
}

/**
 * Nur diese eine URL leeren. Bewusst nicht wpfc_clear_post_cache_by_id:
 * WPFCs singleDeleteCache() leert kaskadierend auch Startseite, Kategorien
 * und Schlagwörter des Beitrags.
 */
function wpfcs_handle_post_clear(): void {
    $post_id = wpfcs_post_action_guard('clear');
    $url     = (string) get_permalink($post_id);
    if (has_action('wpfc_clear_cache_by_url')) {
        do_action('wpfc_clear_cache_by_url', $url);
    } elseif (($file = wpfcs_cache_file_for_url($url)) && is_file($file)) {
        wp_delete_file($file);
    }
    delete_transient(WPFCS_TRANSIENT);
    wpfcs_post_redirect('cleared');
}

function wpfcs_handle_post_warm(): void {
    $post_id = wpfcs_post_action_guard('warm');
    wpfcs_warm_enqueue([(string) get_permalink($post_id)]);
    wpfcs_post_redirect('queued');
}

function wpfcs_post_notices(): void {
    $msg = sanitize_key($_GET['wpfcs_msg'] ?? '');
    $map = [
        'cleared' => 'Cache dieses Beitrags geleert (Startseite und Archive bleiben unberührt).',
        'queued'  => 'Beitrag zum Vorwärmen vorgemerkt – das läuft gedrosselt im Hintergrund per Cron.',
    ];
    if (isset($map[$msg]) && 'edit' === (get_current_screen()->base ?? '')) {
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($map[$msg]));
    }
}

function wpfcs_posts_column_css(): void {
    echo '<style>.column-wpfcs_cache{width:7.5em}.wpfcs-yes{color:#00a32a}.wpfcs-no{color:#8c8f94}</style>';
}
