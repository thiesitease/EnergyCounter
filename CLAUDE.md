# EnergyCounter – Projektgedächtnis

Zählerstände per Foto erfassen, Verbrauch und Kosten auswerten, Zeiträume vergleichen,
Außentemperaturen einbeziehen, alles als Excel exportieren. Deutschsprachige Oberfläche,
eine Haushaltsinstallation.

**Dieses Repository ist öffentlich.** Deshalb gehören hier ausschließlich Programm und
Anleitung hinein. Zugangsdaten (`config.php`, `deploy.env`), die eigenen Ablesungen
(`php/data/*.json`), die Zählerfotos und die Excel-Dateien sind über `.gitignore`
ausgeschlossen und müssen es bleiben. Sie enthalten unter anderem Notizen zu
Abwesenheitszeiten, den Standort des Hauses und die Seriennummern der Zähler.

## Zwei Varianten, gleiche Oberfläche

| Variante | Dateien | Wofür |
|---|---|---|
| **PHP** | `php/` | Normales Webhosting. Läuft nur während eines Seitenaufrufs. Das ist die betriebene Variante. |
| Python | `server.py`, `static/` | Eigener Rechner, Raspberry Pi, NAS, Docker. Startet einen Dauerprozess auf Port 8080. |

Die Oberfläche ist in beiden Fällen eine einzelne HTML-Datei mit eingebettetem CSS und
JavaScript, ohne Framework und ohne Build-Schritt. Die PHP-Fassung ist die gepflegte;
neue Funktionen kommen zuerst dorthin. `static/index.html` hinkt hinterher und kennt
weder Kontrollzähler noch Fotoarchiv, EXIF-Zeit oder Lesemodus.

## Wichtigste Regeln

1. **Nichts Persönliches committen.** Vor jedem Commit `git status --ignored` ansehen.
2. **Keine Bibliotheken von außen.** Kein Composer, kein npm, kein CDN-Skript. Alles
   Nötige ist selbst geschrieben: der XLSX-Export, der EXIF-Leser, der ZIP-Schreiber.
   Grund: Das Hosting erlaubt keine Installation, und die Seite soll ohne fremde Server laufen.
3. **Neue schreibende API-Aktion?** In `api.php` in die Liste `$schreibend` eintragen.
   Der Lesemodus wird im Server durchgesetzt, nicht in der Oberfläche.
4. **PHP 7.4-verträglich schreiben.** Kein `match`, kein `?->`, keine benannten Argumente.
5. **Zeitlimit beachten.** Das Hosting bricht nach 60 Sekunden ab und lässt sich nicht
   hochsetzen. Lange Arbeit in kurze Einzelschritte zerlegen, so wie beim Wetterabruf.

## Einstiegspunkte im Code

| Datei | Inhalt |
|---|---|
| `php/index.html` | Die gesamte Oberfläche: Erfassen, Auswertung, Vergleich, Verlauf, Zähler |
| `php/api.php` | Ein Einstiegspunkt `?p=<aktion>`, Rollenprüfung, alle Endpunkte |
| `php/lib.php` | Speicher, Anmeldung, Wetter, Anthropic-Aufruf, Auswertung, XLSX-Export |
| `deploy.sh` | Dateien per SFTP oder FTP hochladen |
| `set-config.sh` | `config.php` neu schreiben (fragt alle Werte ab) |
| `set-readonly-password.ps1` / `.sh` | Nur das Lesepasswort ändern |

## Weiterführende Notizen

* [docs/betrieb.md](docs/betrieb.md) – Hosting-Regeln, Grenzwerte, Installation, Sicherung
* [docs/datenmodell.md](docs/datenmodell.md) – Format der Ablesungen, Zählerwechsel, Auswertungslogik
* [docs/kontrollzaehler.md](docs/kontrollzaehler.md) – Fronius parallel zum Zähler der Stadtwerke
* [docs/fotos.md](docs/fotos.md) – Warteschlange, Plausibilität, Archiv, Aufnahmezeit
* [docs/rollen.md](docs/rollen.md) – Zwei Passwörter, Lesemodus
* [docs/entwicklung.md](docs/entwicklung.md) – Lokal testen, Stolpersteine

## Umgebung des Entwicklungsrechners

Windows 11 mit PowerShell. Kein Node, kein System-PHP, kein Docker. Vorhanden sind
`git`, `ssh`, `sftp`, `curl`, `python`. Git Bash liegt unter
`C:\Program Files\Git\bin\bash.exe`, steht aber nicht im PATH. Skripte für den Benutzer
deshalb als PowerShell anbieten oder mit dem vollen Pfad zu `bash.exe` aufrufen.
