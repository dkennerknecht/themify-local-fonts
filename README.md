# Themify Local Fonts

Automatically download Google Fonts selected in Themify and serve their CSS and font files from your WordPress uploads directory.

Das Plugin ergänzt den lokalen Font-Download von Themify um variable Schriften, automatische Vorbereitung neuer Schriftfamilien und die direkten Font-Anfragen von Builder und Customizer. Themify-Core-Dateien werden nicht verändert.

## Installation

1. Unter [Releases](https://github.com/dkennerknecht/themify-local-fonts/releases/latest) die Datei **themify-local-fonts.zip** herunterladen.
2. In WordPress **Plugins → Installieren → Plugin hochladen** öffnen und die ZIP-Datei installieren.
3. Plugin aktivieren. Bereits gespeicherte Themify-Schriften und Schriftfamilien aus der zentralen Theme-CSS werden lokal vorbereitet.
4. Unter **Einstellungen → Themify Local Fonts** die erkannten Schriften prüfen. Mit **Vorhandene Schriften lokal vorbereiten** lassen sich gespeicherte Builder-/Customizer-Schriften erneut erfassen.
5. Seiten-, Optimierungs- und gegebenenfalls CDN-Caches leeren und die Website prüfen.

Die ZIP-Datei aus dem Release enthält den passenden Plugin-Ordner und lässt sich direkt installieren. Ein manueller Git-Checkout gehört nach `wp-content/plugins/themify-local-fonts`.

## Voraussetzungen

- WordPress 5.8 oder neuer, PHP 7.4 oder neuer.
- Ein aktives Themify-Theme oder das aktive eigenständige Themify-Builder-Plugin mit Google-Font-Katalog.
- Ein für WordPress beschreibbares Upload-Verzeichnis.
- Für neue Schriften muss der Server `fonts.googleapis.com` und `fonts.gstatic.com` über HTTPS erreichen können. Die Besucherbrowser benötigen diese Verbindungen nicht.

Ohne Themify zeigt das Plugin einen Backend-Hinweis und greift nicht in die Website ein. Bei Multisite das Plugin pro Website aktivieren; eine Netzwerkaktivierung wird nicht unterstützt.

## Neue Schriften verwenden

In Themify wie gewohnt eine Google-Schrift auswählen. Beim ersten Auswählen lädt der Server deren verfügbare Schnitte und Unicode-Teilmengen herunter. Die Vorschau erhält danach lokales CSS. Je nach Schrift und Verbindung dauert das einige Sekunden.

Die Vorbereitung erfolgt zusätzlich beim Speichern von Builder-Daten, Customizer-Einstellungen und Themify-Stilvariablen. Klassische Gewichte, Kursivvarianten und die vom Themify-Katalog ausgewiesenen variablen Gewichts-/Breitenbereiche werden berücksichtigt. Eigene hochgeladene Fonts und Systemschriften bleiben unverändert.

Neue Downloads dürfen nur angemeldete Benutzer mit passenden Bearbeitungsrechten auslösen. Der Vorschau-Endpunkt benötigt zusätzlich einen WordPress-Nonce. Nicht angemeldete Besucher können ausschließlich bereits vorbereitete Fonts abrufen. Bei Downloadfehlern erscheint ein Hinweis; das Plugin schaltet keine unvollständige Schrift frei und aktiviert keinen externen Google-Fallback.

## Speicher und Portabilität

Die Daten liegen unter `<Upload-Verzeichnis>/themify-local-fonts/`:

- `manifest.json`: vorbereitete Familien, Font-URL-Zuordnungen und Prüfsummen.
- `*.woff2`: lokale Font-Dateien.
- `family-*.css`: Deklarationen einzelner Schriftfamilien.
- `fonts-*.css`: versionierte CSS-Bundles.

Der Upload-Pfad wird über WordPress ermittelt. Relative Font-URLs in den CSS-Dateien erlauben Domain- und Upload-URL-Wechsel, ohne die Font-Dateien neu herunterzuladen. Unterschiedliche Websites in einer Multisite erhalten getrennte Speicherorte.

Parallele Downloads werden serialisiert; Besucher müssen nicht auf diese Sperre warten. Erst nach vollständiger Vorbereitung wird das Manifest atomar aktualisiert. Ältere Bundles bleiben für zwischengespeicherte Seiten erreichbar. Der Ordner gehört in das normale Website-Backup; alte Bundles werden derzeit nicht automatisch bereinigt.

Das Repository enthält keine Website-Daten, vorab heruntergeladenen Schriften oder Themify-Dateien. Schriftdateien unterliegen ihren jeweiligen Lizenzen; die GPL-Lizenz dieses Repositories gilt für den Plugin-Code.

## WP-CLI

```sh
wp themify-local-fonts sync --user=<Administrator-ID-oder-Login>
```

Der Befehl bereitet vorhandene Themify-Schriftauswahlen vor und liefert bei Downloadfehlern einen Fehlerstatus. Für eine erfolgreiche Vorbereitung sind dieselben Bearbeitungsrechte wie im Backend erforderlich.

## Aktualisieren, deaktivieren, migrieren

Neue Release-ZIP-Dateien lassen sich über den WordPress-Plugin-Upload als Ersatz installieren. Es gibt derzeit keinen automatischen GitHub-Updater.

Beim Deaktivieren übernimmt Themify wieder die Font-Auslieferung. Die heruntergeladenen Dateien und die Schriftauswahl bleiben erhalten. Den Seitencache anschließend leeren. Auch beim Löschen des Plugins bleiben die Font-Dateien für bestehende Cacheeinträge und spätere Wiederverwendung erhalten; bei Bedarf den Font-Ordner nach Ablauf der Caches manuell entfernen.

Falls bereits eine ältere individuelle MU-Font-Lösung installiert ist: diese zuerst deaktivieren, dann dieses Plugin aktivieren, bestehende Schriften vorbereiten und Caches leeren. Beide Lösungen sollen nicht parallel laufen. Vor einer Migration ein Backup erstellen.

## Umfang und Grenzen

Die Integration behandelt Themifys Font-Ausgabe, regulär eingereihte Google-Stylesheets und die dynamischen Stylesheet-Links der Themify-Editoren. Andere Plugins, CSS-Dateien mit eigenen externen `@import`-Regeln und eingebettete Drittanbieter-Iframes benötigen gegebenenfalls eine gesonderte Prüfung. Themify selbst kann weiterhin Google-CSS oder Font-Metadaten serverseitig abfragen.

Nach Theme-/Builder-Updates die Besucheransicht und die Editor-Vorschau erneut prüfen. Schriftfamilien außerhalb des installierten Themify-Katalogs werden nicht automatisch heruntergeladen.

## Entwicklung und Paketbau

```sh
php tests/run.php
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
python3 tools/build_zip.py
```

Das Installationspaket liegt anschließend unter `dist/themify-local-fonts.zip`. Die 21 Regressionstests benötigen keine WordPress-Installation und prüfen unter anderem frischen Speicher, abweichende Upload-Pfade, Domainwechsel, Download-Berechtigungen, Fehlerfälle und den Erhalt umgebender CSS-Regeln.

### Integrationstests

Geprüft auf einer separaten WordPress-7.1.3-Installation mit PHP 8.3:

- Themify Corporate 7.7.8 / Framework 8.6.3: frische Aktivierung und ZIP-Installation; Desktop-/Mobil-Darstellung vor und nach Aktivierung pixelgleich.
- Themify Builder 7.8.3 mit Twenty Twenty-Five: Speicher-Hooks, neue Schrift in angemeldeter Browser-Vorschau und anschließendes anonymes Laden.
- Keine Google-Font-Anfragen aus den geprüften Besucher-/Vorschau-Browsern; keine JavaScript-Fehler.

Diese Tests decken die genannten Versionen ab, nicht jede Themify-Version oder jedes Drittanbieter-Plugin.

## Lizenz

[GPL-2.0-or-later](LICENSE). Eigenständiges Community-Plugin, kein offizielles Themify-Produkt.
