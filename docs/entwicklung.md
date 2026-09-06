# Entwicklung und Test

## Grundsätze

* Kein Build-Schritt. `php/index.html` ist eine einzige Datei mit CSS und JavaScript darin.
* Keine Abhängigkeiten von außen. Kein Composer, kein npm, kein Skript von einem CDN.
  Selbst geschrieben sind unter anderem der XLSX-Export samt ZIP-Schreiber, der EXIF-Leser
  und der Wetterabruf.
* PHP 7.4-verträglich schreiben, damit die App auch auf älteren Paketen läuft.
* Die PHP-Fassung ist führend. Änderungen an der Oberfläche gehören nach `php/index.html`;
  `static/index.html` der Python-Variante ist ein älterer Stand.

## Lokal testen ohne Installation

Auf dem Entwicklungsrechner gibt es kein System-PHP. Für Tests genügt eine tragbare
Fassung von der offiziellen Seite, die in einen temporären Ordner entpackt wird:

1. `https://windows.php.net/downloads/releases/releases.json` lesen, den Eintrag
   `nts-vs16-x64` einer aktuellen Reihe nehmen und die Prüfsumme vergleichen.
2. Entpacken, daneben eine `php.ini` anlegen:

   ```ini
   extension_dir = "ext"
   extension=curl
   extension=mbstring
   extension=openssl
   date.timezone = Europe/Berlin
   curl.cainfo = "…/cacert.pem"
   ```

   Ohne `curl.cainfo` scheitern die HTTPS-Aufrufe an Open-Meteo mit einem Zertifikatsfehler.
   Eine brauchbare Sammlung liegt bei Git unter `/usr/ssl/certs/ca-bundle.crt`.

3. Eine Kopie des Projekts in einen Testordner legen, eigene `config.php` mit Testpasswörtern
   schreiben, dann:

   ```
   php.exe -c php.ini -S 127.0.0.1:8099 -t .
   ```

Wichtig: immer gegen eine **Kopie** der Daten testen, nie gegen die echten Ablesungen.
Endpunkt-Tests wie „einmal jede schreibende Aktion aufrufen“ verändern sonst den Bestand.

## Prüfen, ohne den Server zu ärgern

Der Hoster drosselt viele schnelle Zugriffe von derselben Adresse und kann Port 443
zeitweise sperren, während SSH weiter geht. Wiederholte Prüfungen deshalb besser über eine
SSH-Sitzung vom Server aus fahren, dort ist die Seite in Millisekunden erreichbar.

## Stolpersteine, die schon einmal Zeit gekostet haben

| Thema | Merksatz |
|---|---|
| Antwortschema der Anthropic-API | Bei `number` sind `minimum` und `maximum` verboten. |
| Zeitlimit | 60 s, `set_time_limit()` hebt es nicht an. Lange Arbeit stückeln. |
| PowerShell | `[regex]::Replace` hat **keine** statische Überladung mit Anzahl. Die 1 landet sonst als `RegexOptions`. Eine Instanz `[regex]"…"` benutzen. |
| PowerShell | Konfigurationsdateien mit `UTF8Encoding($false)` schreiben, sonst steht eine BOM vor `<?php`. |
| Windows | `bash` ist nicht im PATH. Für den Benutzer PowerShell-Skripte anbieten. |
| Excel-Export | Datumszellen als Seriennummer schreiben, sonst kann Excel nicht rechnen. |
| Zählerwechsel | `series()` in JavaScript und `zb_series()` in PHP müssen gleich rechnen. |

## Was vor einem Commit zu prüfen ist

```bash
git status --ignored --short
```

Es darf nichts aus `php/data/`, `excel/`, keine `config.php` und keine `deploy.env` in der
Liste der vorgemerkten Dateien auftauchen. Das Repository ist öffentlich.
