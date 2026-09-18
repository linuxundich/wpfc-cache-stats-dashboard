<?php
defined('ABSPATH') || exit;

function wpfcs_num(int|float $n): string {
    return esc_html(number_format_i18n($n));
}

function wpfcs_pct(int $cached, int $total): int {
    return $total > 0 ? (int) round(100 * $cached / $total) : 0;
}

/**
 * @param array<string, mixed> $s
 */
function wpfcs_render_stats(array $s): void {
    $wpfc_active = has_action('wpfc_clear_all_cache');
    $options     = json_decode((string) get_option('WpFastestCache'), true) ?: [];
    $purges      = wpfcs_purges();

    // --- Warnungen zuerst -------------------------------------------------
    if (!$wpfc_active) {
        echo '<p class="wpfcs-warn">WP Fastest Cache ist nicht aktiv – Statistiken und „Cache leeren“ funktionieren nur mit aktivem Plugin.</p>';
    }
    $since      = (int) current_time('timestamp') - DAY_IN_SECONDS;
    $full_today = count(array_filter($purges, static fn($p) => $p['full'] && $p['time'] >= $since));
    if ($full_today) {
        printf(
            '<p class="wpfcs-notice">⚠️ Der Seiten-Cache wurde in den letzten 24 Stunden <strong>%d× komplett geleert</strong>. Danach muss WordPress jede Seite neu erzeugen – bei Crawler-Verkehr eine häufige Ursache für Lastspitzen.</p>',
            $full_today
        );
    }
    wpfcs_render_slots($s['slots'] ?? null);

    // --- Abdeckung ----------------------------------------------------------
    $posts = $s['coverage']['posts'];
    if ($posts['total'] > 0) {
        $pct = wpfcs_pct($posts['cached'], $posts['total']);
        printf(
            '<div class="wpfcs-coverage"><div class="wpfcs-coverage-head"><strong>Beiträge im Cache</strong><span>%s von %s (%d %%)</span></div>'
            . '<div class="wpfcs-meter"><span style="width:%d%%"></span></div></div>',
            wpfcs_num($posts['cached']),
            wpfcs_num($posts['total']),
            $pct,
            $pct
        );
    }
    wpfcs_render_history();

    echo '<table class="widefat striped wpfcs-table wpfcs-cov-table"><tbody>';
    foreach (['pages', 'categories', 'tags'] as $key) {
        $c = $s['coverage'][$key];
        if ($c['total'] < 1) {
            continue;
        }
        printf('<tr><td>%s</td><td class="num">%s / %s</td><td class="num">%d %%</td></tr>', esc_html($c['label']), wpfcs_num($c['cached']), wpfcs_num($c['total']), wpfcs_pct($c['cached'], $c['total']));
    }
    printf('<tr><td>Startseite</td><td class="num" colspan="2">%s</td></tr>', $s['extra']['home'] ? '✓ im Cache' : '<span class="wpfcs-warn">nicht im Cache</span>');
    printf('<tr><td>Blätterseiten (…/page/N)</td><td class="num" colspan="2">%s</td></tr>', wpfcs_num($s['extra']['pagination']));
    echo '</tbody></table>';

    // --- Meistgelesen, aber nicht im Cache --------------------------------
    wpfcs_render_hot($s['hot'] ?? ['available' => false]);
    wpfcs_render_warm_status();

    // --- Details (eingeklappt) ---------------------------------------------
    echo '<details class="wpfcs-details"><summary>Cache-Dateien und Verlauf der Löschungen</summary>';
    echo '<table class="widefat striped wpfcs-table"><thead><tr><th>Cache-Bereich</th><th class="num">Dateien</th><th class="num">Größe</th></tr></thead><tbody>';
    foreach ($s['areas'] as $a) {
        printf('<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td></tr>', esc_html($a['label']), wpfcs_num($a['html']), esc_html(size_format($a['bytes'], 1)));
        if ($a['xml']) {
            printf('<tr><td>%s – Feeds</td><td class="num">%s</td><td></td></tr>', esc_html($a['label']), wpfcs_num($a['xml']));
        }
    }
    printf(
        '<tr><td>Minifiziertes CSS / JS</td><td class="num">%s / %s</td><td class="num">%s</td></tr>',
        wpfcs_num($s['minified']['css']),
        wpfcs_num($s['minified']['js']),
        esc_html(size_format($s['minified']['bytes'], 1))
    );
    if (null !== $s['widget']) {
        printf('<tr><td>Widget-Cache</td><td class="num">%s</td><td></td></tr>', wpfcs_num($s['widget']));
    }
    echo '</tbody></table><ul class="wpfcs-facts">';
    $desktop = $s['areas']['all'] ?? null;
    if ($desktop && $desktop['html']) {
        printf('<li>Älteste Cache-Seite: %s, neueste: %s</li>', esc_html(wpfcs_format_age($desktop['oldest'])), esc_html(wpfcs_format_age($desktop['newest'])));
    }
    printf(
        '<li>WPFC-Preload: %s</li>',
        !empty($options['wpFastestCachePreload'])
            ? esc_html(sprintf('an, %d Seiten je Durchlauf', (int) ($options['wpFastestCachePreload_number'] ?? 0)))
            : 'aus'
    );
    echo '</ul>';
    if ($purges) {
        echo '<p class="wpfcs-subhead">Letzte Cache-Löschungen:</p><ul class="wpfcs-facts">';
        foreach (array_slice($purges, 0, 6) as $p) {
            printf(
                '<li>%s – %s%s</li>',
                esc_html(gmdate('d.m. H:i', $p['time'])),
                esc_html($p['label']),
                $p['full'] ? ' <strong>(komplett)</strong>' : ''
            );
        }
        echo '</ul>';
    }
    echo '</details>';

    printf(
        '<p class="wpfcs-stand">Stand: %s (Verzeichnisdurchlauf %.1f s) · <a href="#" class="wpfcs-refresh">Aktualisieren</a></p>',
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

/**
 * @param array<string, mixed>|null $slots
 */
function wpfcs_render_slots(?array $slots): void {
    if (null === $slots) {
        return;
    }
    if (empty($slots['readable'])) {
        echo '<p class="wpfcs-warn">Das Error-Log (WPFCS_ERROR_LOG) ist für PHP nicht lesbar.</p>';
        return;
    }
    $prefix = !empty($slots['truncated']) ? 'mind. ' : '';
    $class  = $slots['slots_1h'] > 0 ? 'wpfcs-notice' : 'wpfcs-ok';
    printf(
        '<p class="%s"><strong>PHP-Slot-Fehler:</strong> %s%s in 24 Std., %s in der letzten Stunde · abgebrochene PHP-Prozesse: %s%s<br><span class="description">Ein Slot-Fehler bedeutet: Ein Besucher hat 64 s gewartet und eine Fehlerseite bekommen.</span></p>',
        esc_attr($class),
        esc_html($prefix),
        wpfcs_num($slots['slots_24h']),
        wpfcs_num($slots['slots_1h']),
        esc_html($prefix),
        wpfcs_num($slots['aborted_24h'])
    );
}

function wpfcs_render_history(): void {
    $history = (array) get_option(WPFCS_HISTORY, []);
    if (count($history) < 2) {
        return;
    }
    $vals = array_map(static fn($h) => (float) $h['posts'], array_values($history));
    $n    = count($vals);
    $w    = 300;
    $h    = 40;
    $pts  = [];
    foreach ($vals as $i => $v) {
        $pts[] = sprintf('%.1f,%.1f', $i * $w / max(1, $n - 1), $h - 2 - ($v / 100) * ($h - 4));
    }
    $days = array_keys($history);
    printf(
        '<figure class="wpfcs-history"><svg viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s">'
        . '<line x1="0" y1="%4$.1f" x2="%1$d" y2="%4$.1f" class="wpfcs-grid"/>'
        . '<polyline points="%5$s" class="wpfcs-line"/></svg>'
        . '<figcaption>Beitrags-Abdeckung %6$s – %7$s: %8$s → %9$s %%</figcaption></figure>',
        $w,
        $h,
        esc_attr(sprintf('Verlauf der Cache-Abdeckung über %d Tage', $n)),
        $h - 2 - 0.5 * ($h - 4),
        esc_attr(implode(' ', $pts)),
        esc_html(wp_date('d.m.', strtotime($days[0]))),
        esc_html(wp_date('d.m.', strtotime(end($days)))),
        esc_html(number_format_i18n(reset($vals))),
        esc_html(number_format_i18n(end($vals)))
    );
}

/**
 * @param array<string, mixed> $hot
 */
function wpfcs_render_hot(array $hot): void {
    if (empty($hot['available'])) {
        return;
    }
    echo '<div class="wpfcs-hot">';
    printf('<p class="wpfcs-subhead">Meistgelesen, aber nicht im Cache <span class="description">(letzte %d Tage)</span></p>', (int) $hot['days']);
    if (empty($hot['items'])) {
        printf('<p class="wpfcs-ok">✓ Die %d meistgelesenen Beiträge liegen alle im Cache.</p></div>', (int) $hot['checked']);
        return;
    }
    echo '<ol class="wpfcs-hot-list">';
    $ids = [];
    foreach ($hot['items'] as $item) {
        $ids[] = (int) $item['id'];
        printf(
            '<li><a href="%s">%s</a><span class="num">%s Aufrufe</span></li>',
            esc_url($item['url']),
            esc_html($item['title']),
            wpfcs_num($item['views'])
        );
    }
    echo '</ol>';
    printf(
        '<p><button type="button" class="button wpfcs-warm" data-ids="%s">Diese %d vorwärmen</button> <span class="wpfcs-warm-status" role="status"></span></p>',
        esc_attr(implode(',', $ids)),
        count($ids)
    );
    echo '</div>';
}

function wpfcs_render_warm_status(): void {
    $w = wpfcs_warm_status();
    $s = $w['state'];
    if (!$w['queued'] && empty($s['last_run'])) {
        return;
    }
    $parts = [];
    if ($w['queued']) {
        $parts[] = sprintf('%d in der Warteschlange', $w['queued']);
        if (!empty($s['paused_until']) && $s['paused_until'] > time()) {
            $parts[] = sprintf('pausiert bis %s (Server langsam oder Fehler)', wp_date('H:i', (int) $s['paused_until']));
        } elseif ($w['next']) {
            $parts[] = sprintf('nächster Lauf %s', $w['next'] > time() ? 'in ' . human_time_diff(time(), $w['next']) : 'fällig');
        }
    }
    if (!empty($s['last_run'])) {
        $parts[] = sprintf('bisher %d erzeugt, %d übersprungen, %d fehlgeschlagen', (int) $s['done'], (int) $s['skipped'], (int) $s['failed']);
        if (!empty($s['last_avg'])) {
            $parts[] = sprintf('zuletzt Ø %s s je Seite', number_format_i18n((float) $s['last_avg'], 1));
        }
    }
    printf('<p class="wpfcs-facts wpfcs-warmstate"><strong>Vorwärmen:</strong> %s</p>', esc_html(implode(' · ', $parts)));
    if (!empty($s['last_error'])) {
        printf('<p class="description">Letzter Fehler: %s</p>', esc_html($s['last_error']));
    }
}
