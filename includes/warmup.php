<?php
defined('ABSPATH') || exit;

/*
 * Gedrosseltes Vorwärmen einzelner Seiten.
 *
 * Anders als der WPFC-Preload (arbeitet stur nach Datum: Startseite,
 * Beiträge, Kategorien …) wärmt dies gezielt die Seiten vor, die tatsächlich
 * aufgerufen werden, aber nicht im Cache liegen. Leitplanken, damit das
 * Vorwärmen nicht selbst zur Last wird:
 * - läuft nur per Cron, nie im Request eines Besuchers oder Admins
 * - Seiten werden streng nacheinander abgerufen, nie parallel – belegt also
 *   neben dem Cron-Prozess höchstens einen weiteren PHP-Slot
 * - höchstens WPFCS_WARM_BATCH Seiten bzw. WPFCS_WARM_BUDGET Sekunden je Lauf
 * - braucht eine Seite länger als WPFCS_WARM_SLOW Sekunden oder schlägt
 *   fehl, pausiert das Vorwärmen WPFCS_WARM_BACKOFF Sekunden
 */

const WPFCS_WARM_QUEUE   = 'wpfcs_warm_queue';
const WPFCS_WARM_STATE   = 'wpfcs_warm_state';
const WPFCS_WARM_BATCH   = 5;
const WPFCS_WARM_BUDGET  = 20;
const WPFCS_WARM_SLOW    = 15;
const WPFCS_WARM_PAUSE   = 60;
const WPFCS_WARM_BACKOFF = 300;
const WPFCS_WARM_MAX     = 200;
// Enthält die Kennung des WPFC-Preload-Bots: Die WPFC-.htaccess liefert
// diesem User-Agent nie die statische Cache-Datei, sondern lässt WordPress
// die Seite erzeugen – genau das soll hier passieren.
const WPFCS_WARM_UA = 'WP Fastest Cache Preload Bot (wpfc-cache-stats-widget)';

add_action('wpfcs_warm_tick', 'wpfcs_warm_tick');
add_action('wp_ajax_wpfcs_warm', 'wpfcs_ajax_warm');

/**
 * URLs in die Warteschlange (ohne Duplikate, gedeckelt) und den nächsten
 * Lauf planen.
 *
 * @param string[] $urls
 */
function wpfcs_warm_enqueue(array $urls): int {
    $queue = (array) get_option(WPFCS_WARM_QUEUE, []);
    foreach ($urls as $url) {
        $url = esc_url_raw($url);
        if ($url && str_starts_with($url, home_url('/')) && !in_array($url, $queue, true)) {
            $queue[] = $url;
        }
    }
    $queue = array_slice($queue, 0, WPFCS_WARM_MAX);
    update_option(WPFCS_WARM_QUEUE, $queue, false);

    if ($queue && !wp_next_scheduled('wpfcs_warm_tick')) {
        wp_schedule_single_event(time() + 5, 'wpfcs_warm_tick');
    }
    return count($queue);
}

/**
 * AJAX: Beiträge (IDs) vorwärmen – aus der Liste "Meistgelesen, nicht im Cache".
 */
function wpfcs_ajax_warm(): void {
    wpfcs_check_ajax();
    $ids  = array_map('absint', (array) ($_POST['ids'] ?? []));
    $urls = [];
    foreach (array_slice($ids, 0, 50) as $id) {
        if ($id && 'publish' === get_post_status($id)) {
            $urls[] = (string) get_permalink($id);
        }
    }
    if (!$urls) {
        wp_send_json_error(['message' => 'Keine gültigen Beiträge ausgewählt.']);
    }
    $n = wpfcs_warm_enqueue($urls);
    wp_send_json_success(['message' => sprintf('%d Seite(n) in der Warteschlange – Vorwärmen läuft im Hintergrund per Cron.', $n)]);
}

function wpfcs_warm_tick(): void {
    // Kein zweiter Lauf parallel (z. B. externer Cron + Loopback gleichzeitig).
    if (get_transient('wpfcs_warm_lock')) {
        return;
    }
    set_transient('wpfcs_warm_lock', 1, 2 * WPFCS_WARM_BUDGET + 60);

    $queue = (array) get_option(WPFCS_WARM_QUEUE, []);
    $state = wp_parse_args((array) get_option(WPFCS_WARM_STATE, []), ['done' => 0, 'skipped' => 0, 'failed' => 0, 'last_run' => 0, 'last_avg' => 0.0, 'last_error' => '', 'paused_until' => 0]);
    $start = microtime(true);
    $pause = WPFCS_WARM_PAUSE;
    $times = [];

    while ($queue && count($times) < WPFCS_WARM_BATCH && microtime(true) - $start < WPFCS_WARM_BUDGET) {
        $url  = array_shift($queue);
        $file = wpfcs_cache_file_for_url($url);
        if ($file && is_file($file)) {
            $state['skipped']++;
            continue;
        }
        $t0   = microtime(true);
        $resp = wp_remote_get($url, [
            'timeout'    => 30,
            'user-agent' => WPFCS_WARM_UA,
            'headers'    => ['Cache-Control' => 'no-cache'],
        ]);
        $dt      = microtime(true) - $t0;
        $times[] = $dt;
        $code    = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);

        if (200 !== $code) {
            $state['failed']++;
            $state['last_error'] = sprintf('%s – %s', is_wp_error($resp) ? $resp->get_error_message() : 'HTTP ' . $code, $url);
            $pause               = WPFCS_WARM_BACKOFF;
            break;
        }
        $state['done']++;
        if ($dt > WPFCS_WARM_SLOW) {
            // Server offensichtlich unter Last: nicht weiter nachlegen.
            $pause = WPFCS_WARM_BACKOFF;
            break;
        }
        sleep(1);
    }

    $state['last_run']     = time();
    $state['last_avg']     = $times ? array_sum($times) / count($times) : 0.0;
    $state['paused_until'] = WPFCS_WARM_BACKOFF === $pause ? time() + $pause : 0;
    update_option(WPFCS_WARM_QUEUE, $queue, false);
    update_option(WPFCS_WARM_STATE, $state, false);
    delete_transient('wpfcs_warm_lock');

    if ($queue) {
        wp_schedule_single_event(time() + $pause, 'wpfcs_warm_tick');
    }
}

/**
 * @return array{queued: int, next: int, state: array<string, mixed>}
 */
function wpfcs_warm_status(): array {
    return [
        'queued' => count((array) get_option(WPFCS_WARM_QUEUE, [])),
        'next'   => (int) wp_next_scheduled('wpfcs_warm_tick'),
        'state'  => (array) get_option(WPFCS_WARM_STATE, []),
    ];
}
