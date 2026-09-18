# WPFC Cache-Statistiken im Dashboard

Ein kleines WordPress-Plugin, das Cache-Statistiken von [WP Fastest Cache](https://wordpress.org/plugins/wp-fastest-cache/) direkt im Admin-Dashboard anzeigt – mit Fokus auf die Frage: **Wie viel kann der Server ausliefern, ohne dass WordPress eine Seite neu erzeugen muss?**

## Funktionen

### Im Dashboard-Widget

✅ **Cache-Abdeckung:** Anteil der veröffentlichten Beiträge im Seiten-Cache (mit Balken), dazu Seiten, Kategorien, Schlagwörter, Startseite und Blätterseiten.

📈 **Verlauf:** Die Beitrags-Abdeckung der letzten 30 Tage als Mini-Kurve – zeigt, wie schnell sich der Cache nach einem Leeren wieder füllt.

🔥 **Meistgelesen, aber nicht im Cache:** Die meistgelesenen Beiträge der letzten 14 Tage, die gerade *nicht* gecacht sind – genau die erzeugen die meiste Last. Benötigt die Aufrufzählung des Themes „Linux und Ich“; ohne dieses Theme entfällt der Abschnitt.

🌡️ **Gedrosseltes Vorwärmen:** Ein Klick legt diese Beiträge in eine Warteschlange, die per WP-Cron abgearbeitet wird – streng nacheinander, höchstens 5 Seiten bzw. 20 Sekunden je Lauf, mit 5 Minuten Pause, sobald eine Seite länger als 15 Sekunden braucht oder fehlschlägt. Das Widget zeigt Warteschlange und Ergebnis.

⚠️ **Warnung bei Komplett-Leerungen:** Wurde der Cache in den letzten 24 Stunden komplett geleert (Einstellungen gespeichert, Admin-Leiste, Button), weist das Widget darauf hin.

🧯 **PHP-Slot-Fehler (optional):** Zählt `mod_fcgid: can't apply process slot` und abgebrochene PHP-Prozesse der letzten 24 Stunden aus dem Apache-Error-Log. Hosting-spezifisch, daher nur aktiv, wenn in der `wp-config.php` der Pfad gesetzt ist:

```php
define('WPFCS_ERROR_LOG', '/pfad/zum/error_log');
```

📂 **Details (eingeklappt):** Anzahl und Größe der Cache-Dateien je Bereich, Alter der ältesten/neuesten Cache-Seite, Preload-Status, letzte Cache-Löschungen samt Auslöser (mit WPFC Premium).

🧹 **Cache leeren:** mit Sicherheitsabfrage, optional inklusive minifizierter CSS/JS-Dateien, über die WPFC-eigene Schnittstelle.

### In der Beitragsliste

- Spalte **„Cache“**: ✓ mit Alter der Cache-Datei oder –
- Zeilenaktion **„Cache leeren“** – leert nur diese eine URL (nicht wie WPFCs eigene Funktion zusätzlich Startseite und Archive)
- Zeilenaktion **„Vorwärmen“** – legt den Beitrag in die Vorwärm-Warteschlange

## Performance

- Das Dashboard wartet nie auf die Statistik: Sie wird per AJAX nachgeladen und 15 Minuten zwischengespeichert. Ein täglicher Cron-Lauf schreibt den Verlauf fort.
- Ein Verzeichnisdurchlauf pro Cache-Bereich; die Zuordnung zu Beiträgen, Seiten und Archiven nutzt nur die Verzeichnisstruktur und vier schlanke Slug-Abfragen.
- Vom Error-Log werden höchstens die letzten 2 MB gelesen.
- Beitragsliste: ein `file_exists()` pro Zeile.
- Kein jQuery. Eigene Optionen mit `autoload = off`; `uninstall.php` räumt sie beim Löschen ab.

![WPFC Cache-Statistiken im Dashboard von WordPress](assets/wpfc-stats-dashboard.webp)

## Voraussetzungen

- WordPress ab 6.2, PHP ab 8.0
- [WP Fastest Cache](https://wordpress.org/plugins/wp-fastest-cache/) installiert und aktiviert
- Benutzerrolle mit `manage_options`-Rechten
- Für das Vorwärmen: funktionierender WP-Cron (Loopback oder externer Cron)

Die Zuordnung der Cache-Dateien zu Beiträgen setzt eine Permalink-Struktur voraus, die mit `%postname%` endet.

## Installation

1. Dieses Repository herunterladen oder klonen

   ```bash
   git clone https://github.com/linuxundich/wpfc-cache-stats-dashboard.git
   ```

2. Ordner `wpfc-cache-stats-widget` nach `/wp-content/plugins` kopieren

## Änderungen

**4.0**
- Neu: „Meistgelesen, aber nicht im Cache“ mit gedrosseltem Vorwärmen per Cron
- Neu: Cache-Spalte und Zeilenaktionen (Cache leeren / Vorwärmen) in der Beitragsliste
- Neu: Abdeckung für Seiten, Kategorien, Schlagwörter, Startseite und Blätterseiten
- Neu: Verlauf der Beitrags-Abdeckung (30 Tage)
- Neu: Warnung bei Komplett-Leerungen in den letzten 24 Stunden
- Neu: optionale Anzeige der PHP-Slot-Fehler aus dem Error-Log
- Details eingeklappt, Code auf mehrere Dateien aufgeteilt, `uninstall.php`

**3.0**
- Statistik asynchron und gecacht statt bei jedem Dashboard-Aufruf, ein Verzeichnisdurchlauf statt fünf
- Neu: Cache-Abdeckung der Beiträge, Größen, Alter, Preload-Status, letzte Cache-Löschungen
- Cache leeren über WPFC-Schnittstelle statt `shell_exec` + WP-CLI, mit Sicherheitsabfrage
- Behoben: Nach dem Leeren wurde der komplette Widget-Rahmen ersetzt und der Button funktionierte nicht mehr
- Behoben: AJAX-Refresh ohne Nonce- und Rechteprüfung; Widget erschien für alle Rollen (leer)
- Funktionsnamen mit Präfix, jQuery entfernt
