# EnergyCounter

Zählerstände per Foto erfassen, Verbrauch und Kosten auswerten, Zeiträume vergleichen,
Außentemperaturen einbeziehen, alles als Excel exportieren.

Gedacht für den eigenen Haushalt: Man geht durchs Haus, fotografiert die Zähler, und die
Anwendung liest Zähler und Zählerstand aus dem Bild. Die Daten bleiben auf dem eigenen
Server, als schlichte JSON-Dateien.

## Was es kann

* **Erfassen** – ein Foto je Zähler, beliebig viele auf einmal. Ein Sprachmodell erkennt
  Zähler und Stand, anhand von Seriennummer, OBIS-Register und Aussehen. Jeder Wert läuft
  durch eine Plausibilitätsprüfung, die verrutschte Kommas und unmögliche Sprünge abfängt.
  Der Aufnahmezeitpunkt kommt aus dem EXIF-Block des Bildes.
* **Auswertung** – Verbrauch und Kosten je Tag, Vorjahresvergleich, Monate mehrerer Jahre
  nebeneinander, Verlauf zwischen den Ablesungen. Für Heizzähler zusätzlich Außentemperatur,
  Verbrauch je Gradtag und ein Diagramm Verbrauch gegen Temperatur.
* **Vergleich** – zwei frei wählbare Zeiträume, mit Vorlagen für 30 und 90 Tage, Jahr bis
  heute, zwölf Monate, Vorjahr und Heizperiode. Für Heizzähler auch witterungsbereinigt.
* **Verlauf** – alle Ablesungen mit den archivierten Fotos, bearbeitbar, mit Excel-Export.
* **Zähler** – Einheiten, Umrechnungsfaktoren, Seriennummern, Preise mit Gültigkeitsdatum
  (auch negativ für Einspeisevergütung), Kontrollzähler, Standort für die Wetterdaten.

Zwei Passwörter: eines zum Bearbeiten, eines nur zum Ansehen.

## Zwei Varianten

| Variante | Dateien | Wofür |
|---|---|---|
| **PHP** | `php/` | Normales Webhosting. Läuft nur während eines Seitenaufrufs, kein Dienst, kein Cronjob, kein Docker. Das ist die gepflegte Fassung. |
| Python | `server.py`, `static/` | Eigener Rechner, Raspberry Pi, NAS oder Docker. Startet einen kleinen Dauerprozess auf Port 8080. Älterer Funktionsstand. |

Kein Framework, kein Build-Schritt, keine Abhängigkeit von fremden Servern. Der
Excel-Export, der EXIF-Leser und der ZIP-Schreiber sind selbst geschrieben, damit die
Anwendung auch auf Paketen läuft, auf denen sich nichts installieren lässt.

## Schnellstart, PHP-Variante

```bash
cp php/config.example.php php/config.php
cp php/data/config.example.json php/data/config.json
```

In `config.php` die Passwörter und den API-Schlüssel eintragen, dann den Inhalt von `php/`
ins Webverzeichnis laden, `data/` auf `0775` setzen und `https://<domain>/api.php?p=health`
aufrufen. Ausführlich in [docs/betrieb.md](docs/betrieb.md).

`deploy.sh` lädt die Dateien per SFTP oder FTP hoch, die Zugangsdaten kommen aus einer
`deploy.env` nach dem Muster von `deploy.env.example`.

## Schnellstart, Python-Variante

```bash
pip install -r requirements.txt
ANTHROPIC_API_KEY=sk-ant-… ZB_PASSWORD=geheim python server.py
```

Oder `docker compose up -d --build`.

## Foto-Erkennung

Läuft über die Anthropic-API und braucht einen eigenen Schlüssel von
[console.anthropic.com](https://console.anthropic.com). Ohne Schlüssel funktioniert alles
außer der Erkennung, Zählerstände tippt man dann von Hand. Ein Foto kostet etwa
anderthalb Cent. Die Bilder werden zur Erkennung übertragen und liegen danach nur im
eigenen Archiv.

## Wetterdaten

Tagesmitteltemperaturen von [Open-Meteo](https://open-meteo.com), kostenlos und ohne Konto.
Nötig ist nur der Ort, der in der Anwendung gesucht und gespeichert wird.

## Dokumentation

* [docs/betrieb.md](docs/betrieb.md) – Installation, Hosting-Grenzen, Sicherung
* [docs/api.md](docs/api.md) – die Schnittstelle
* [docs/datenmodell.md](docs/datenmodell.md) – Datenformat und Auswertungslogik
* [docs/kontrollzaehler.md](docs/kontrollzaehler.md) – zwei Zähler gegeneinander prüfen
* [docs/fotos.md](docs/fotos.md) – Erkennung, Plausibilität, Archiv
* [docs/rollen.md](docs/rollen.md) – Bearbeiten und Nur-Lesen
* [docs/entwicklung.md](docs/entwicklung.md) – lokal testen, Stolpersteine

## Hinweis zu eigenen Daten

Dieses Repository enthält nur Programm und Anleitung. Eigene Ablesungen, Zählerfotos,
Seriennummern, Standort und Zugangsdaten gehören nicht hinein und sind über `.gitignore`
ausgeschlossen. Sie verraten unter anderem, wann ein Haus leer stand.

## Lizenz

GPL-3.0, siehe [LICENSE](LICENSE).
