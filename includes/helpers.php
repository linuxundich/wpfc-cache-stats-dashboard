<?php
defined('ABSPATH') || exit;

const WPFCS_OWN_PURGES = 'wpfcs_own_purges';

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
 * Cache-Datei einer URL – nach derselben Regel wie WPFCs clear_cache_by_url():
 * Pfad ohne Slashes, URL-dekodiert, darunter index.html.
 */
function wpfcs_cache_file_for_url(string $url): ?string {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $path = trim($path, '/');
    $path = '' === $path ? 'index.html' : rawurldecode($path) . '/index.html';
    if (str_contains($path, '..')) {
        return null;
    }
    return wpfcs_cache_dir('all') . $path;
}

/**
 * Änderungszeit der Cache-Datei eines Beitrags, 0 = nicht im Cache.
 */
function wpfcs_post_cache_mtime(int $post_id): int {
    $url  = get_permalink($post_id);
    $file = $url ? wpfcs_cache_file_for_url($url) : null;
    return ($file && is_file($file)) ? (int) filemtime($file) : 0;
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

function wpfcs_format_age(int $ts): string {
    return $ts ? sprintf('vor %s', human_time_diff($ts, time())) : '–';
}

/**
 * Eigene Komplett-Leerungen über den Widget-Button mitprotokollieren – WPFCs
 * eigenes Protokoll gibt es nur mit der Premium-Version. Zeitstempel wie bei
 * WPFC in Ortszeit, damit beide Quellen vergleichbar sind.
 */
function wpfcs_record_own_purge(): void {
    $list   = (array) get_option(WPFCS_OWN_PURGES, []);
    $list[] = (int) current_time('timestamp');
    update_option(WPFCS_OWN_PURGES, array_slice($list, -20), false);
}

/**
 * Cache-Löschungen aus WPFCs Protokoll (nur Premium) und dem eigenen Button.
 * Zeitstempel in Ortszeit (current_time('timestamp')), daher später mit
 * gmdate() formatiert statt wp_date(), sonst würde doppelt verschoben.
 *
 * @return array<int, array{time: int, label: string, full: bool}>
 */
function wpfcs_purges(): array {
    // "full" = kompletter Seiten-Cache weg; alles andere betrifft einzelne Seiten.
    $labels = [
        'optionsPageRequest' => ['Einstellungen gespeichert', true],
        'deleteCacheToolbar' => ['Über die Admin-Leiste', true],
        'deleteCache'        => ['Kompletter Cache', true],
        'singleDeleteCache'  => ['Einzelne Seite (+ Startseite/Archive)', false],
        'detectNewComment'   => ['Neuer Kommentar', false],
        'detectEditComment'  => ['Kommentar bearbeitet', false],
        'Cache Timeout'      => ['Cache-Timeout', false],
        'setSchedule'        => ['Zeitplan', false],
    ];

    $out  = [];
    $raw  = get_option('WpFcDeleteCacheLogs');
    $logs = is_string($raw) ? json_decode($raw, true) : $raw;
    foreach (is_array($logs) ? $logs : [] as $entry) {
        $fn = (string) ($entry['via']['function'] ?? '');
        [$label, $full] = isset($entry['via']['args']) ? ['Beitrag veröffentlicht/geändert', false] : [$fn, false];
        foreach ($labels as $needle => $def) {
            if (!isset($entry['via']['args']) && str_contains($fn, $needle)) {
                [$label, $full] = $def;
                break;
            }
        }
        $out[] = ['time' => (int) ($entry['date'] ?? 0), 'label' => $label, 'full' => $full];
    }
    foreach ((array) get_option(WPFCS_OWN_PURGES, []) as $ts) {
        $out[] = ['time' => (int) $ts, 'label' => 'Button im Dashboard-Widget', 'full' => true];
    }

    usort($out, static fn($a, $b) => $b['time'] <=> $a['time']);

    // WPFC protokolliert Auslöser und Aktion oft als getrennte Einträge
    // (z. B. "setSchedule" + "Cache Timeout" innerhalb weniger Sekunden); ein
    // eigener Button-Klick kann zusätzlich als deleteCache auftauchen.
    // Einträge im Abstand von ≤ 30 s zusammenfassen: "full" gewinnt, sonst
    // die konkretere Bezeichnung statt des bloßen "Zeitplan".
    $merged = [];
    foreach ($out as $e) {
        $last = array_key_last($merged);
        if (null !== $last && abs($merged[$last]['time'] - $e['time']) <= 30) {
            $prev = $merged[$last];
            if (($e['full'] && !$prev['full']) || (!$prev['full'] && 'Zeitplan' === $prev['label'])) {
                $merged[$last] = $e;
            }
            continue;
        }
        $merged[] = $e;
    }
    return $merged;
}

/**
 * Pfad zum Apache-Error-Log für die Slot-Fehler-Anzeige. Hosting-spezifisch,
 * daher nur per Konstante in der wp-config.php oder per Filter aktiv:
 *   define('WPFCS_ERROR_LOG', '/logs/example.org_error_log');
 */
function wpfcs_error_log_path(): string {
    $path = defined('WPFCS_ERROR_LOG') ? (string) WPFCS_ERROR_LOG : '';
    return (string) apply_filters('wpfcs_error_log_path', $path);
}

/**
 * Zählt PHP-Slot-Fehler (mod_fcgid "can't apply process slot") und
 * abgebrochene PHP-Prozesse ("End of script output") der letzten 24 Stunden.
 * Liest nur die letzten 2 MB des Logs, damit ein großes Log das Dashboard
 * nicht ausbremst; reicht das nicht für 24 h, wird das als "mindestens"
 * gekennzeichnet.
 *
 * @return array{readable: bool, slots_24h?: int, slots_1h?: int, aborted_24h?: int, truncated?: bool}|null
 */
function wpfcs_slot_errors(): ?array {
    $path = wpfcs_error_log_path();
    if ('' === $path) {
        return null;
    }
    if (!is_readable($path)) {
        return ['readable' => false];
    }

    $size = (int) filesize($path);
    $fh   = fopen($path, 'rb');
    if (!$fh) {
        return ['readable' => false];
    }
    $chunk = 2 * 1024 * 1024;
    fseek($fh, max(0, $size - $chunk));
    $data = (string) stream_get_contents($fh);
    fclose($fh);

    $tz        = wp_timezone();
    $now       = time();
    $res       = ['readable' => true, 'slots_24h' => 0, 'slots_1h' => 0, 'aborted_24h' => 0, 'truncated' => false];
    $first_ts  = null;
    foreach (explode("\n", $data) as $line) {
        // [Fri Sep 18 11:30:38.655896 2026]
        if (!preg_match('/^\[\w{3} (\w{3}) +(\d{1,2}) (\d{2}:\d{2}:\d{2})[.\d]* (\d{4})\]/', $line, $m)) {
            continue;
        }
        $dt = DateTimeImmutable::createFromFormat('M j H:i:s Y', "$m[1] $m[2] $m[3] $m[4]", $tz);
        if (!$dt) {
            continue;
        }
        $ts       = $dt->getTimestamp();
        $first_ts ??= $ts;
        if ($ts < $now - DAY_IN_SECONDS) {
            continue;
        }
        if (str_contains($line, "can't apply process slot")) {
            $res['slots_24h']++;
            if ($ts >= $now - HOUR_IN_SECONDS) {
                $res['slots_1h']++;
            }
        } elseif (str_contains($line, 'End of script output')) {
            $res['aborted_24h']++;
        }
    }
    $res['truncated'] = $size > $chunk && null !== $first_ts && $first_ts > $now - DAY_IN_SECONDS;
    return $res;
}
