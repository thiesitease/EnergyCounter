# Datenmodell und Auswertungslogik

Alles liegt als JSON im Datenverzeichnis. Keine Datenbank, keine Migrationen.

## `readings.json` – die Ablesungen

Eine Liste, aufsteigend nach Zeit sortiert. Ein Eintrag:

```json
{
  "id": "r1a2b3c4",
  "t": 1788472380000,
  "v": { "gas": 7024.042, "s180": 10483 },
  "n": { "gas": "Heizung eingeschaltet" },
  "chg": { "gas": true },
  "ph": [ { "f": "gas-1.jpg", "m": "gas" } ],
  "src": "photo",
  "created": 1788472400000
}
```

| Feld | Bedeutung |
|---|---|
| `id` | frei gewählt, nur `A-Z a-z 0-9 _ -`, höchstens 64 Zeichen |
| `t` | Zeitpunkt in Millisekunden seit 1970, Ortszeit des Ablesens |
| `v` | abgelesene Stände je Zählerschlüssel, so wie sie am Zähler stehen |
| `n` | Notizen je Zähler |
| `chg` | Zählerwechsel: Der neue Zähler beginnt mit diesem Stand |
| `ph` | archivierte Fotos, `f` Dateiname, `m` zugehöriger Zähler |
| `src` | `photo`, `manual` oder `import` |

Nicht abgelesene Zähler fehlen einfach. Es ist ausdrücklich erlaubt, bei einer Ablesung
nur einen einzigen Zähler einzutragen.

## `config.json` – Zähler, Preise, Standort

```json
{
  "meters": [
    { "key": "gas", "name": "Gas", "unit": "m³", "outUnit": "kWh",
      "ids": ["7AMX…"], "decimals": 3, "active": true, "inTotal": true, "weather": true,
      "factors": { "zz": 0.9683, "bw": 11.435 },
      "prices": [ { "from": "2024-06-01", "price": 0.1152 } ],
      "hint": "Hinweis für die Foto-Erkennung" }
  ],
  "location": { "name": "…", "lat": 53.55, "lon": 9.99 }
}
```

| Feld | Bedeutung |
|---|---|
| `key` | interner Schlüssel, taucht in `v`, `n`, `chg` und in Dateinamen auf |
| `unit` / `outUnit` | Einheit am Zähler und Einheit der Auswertung |
| `factors` | Gas: Zustandszahl mal Brennwert rechnet m³ in kWh um |
| `prices` | ab Datum gültiger Preis je `outUnit`, **negativ = Gutschrift** |
| `inTotal` | zählt in die Kostensumme |
| `weather` | blendet Außentemperatur und Gradtage für diesen Zähler ein |
| `controlOf` | macht den Zähler zum Kontrollzähler, siehe [kontrollzaehler.md](kontrollzaehler.md) |
| `decimals` | Nachkommastellen für Anzeige und Plausibilitätsprüfung |

## Zählerwechsel

Zähler fangen nach einem Austausch wieder bei null an. Damit die Reihe trotzdem
durchläuft, merkt sich die Auswertung einen Aufschlag: Ist bei einer Ablesung
`chg[key]` gesetzt, wird der bis dahin erreichte kumulierte Rohstand zum Aufschlag für
alle folgenden Werte. Der neue Zählerstand wird also nicht als Rückgang gelesen, sondern
als Fortsetzung.

Umgesetzt in `series()` in `index.html` und in `zb_series()` in `lib.php`. Beide müssen
gleich rechnen, sonst weichen Oberfläche und Excel-Export voneinander ab.

## Verbrauch, Preise, Zeiträume

* Der Verbrauch eines Abschnitts ist die Differenz der kumulierten Stände, multipliziert
  mit dem Faktor des Zählers.
* Für einen frei gewählten Zeitraum wird zwischen den benachbarten Ablesungen **linear
  interpoliert** (`cumAt`). Liegt der Zeitraum teilweise außerhalb der vorhandenen Daten,
  wird er gekürzt und die Oberfläche schreibt „gekürzt“ dazu.
* Kosten werden zeitanteilig über die Preisstufen verteilt (`costBetween`), ein Preiswechsel
  mitten im Zeitraum wird also anteilig berücksichtigt.
* Vergleiche laufen über den **Durchschnitt pro Tag**, damit unterschiedlich lange
  Zeiträume vergleichbar sind.

## Wetter und Gradtage

`weather.json` hält Tagesmittel, Minimum und Maximum je Tag. Daraus entstehen:

* die mittlere Außentemperatur eines Abschnitts
* die **Gradtagzahl nach der 20/15-Methode**: Ein Tag zählt als Heiztag, wenn das
  Tagesmittel unter 15 °C liegt; gezählt wird dann die Differenz zu 20 °C.
* der witterungsbereinigte Vergleich, also Verbrauch je Gradtag

Fehlen für einen Zeitraum mehr als 30 Prozent der Tage, wird nichts ausgewiesen.

## Excel-Export

`api.php?p=export.xlsx` baut die Datei direkt in PHP, ohne Bibliothek: ein eigener
ZIP-Schreiber über `gzdeflate` und Arbeitsblätter mit `inlineStr`-Zellen. Blätter:

1. **Zählerstände** – wie die Übersichtstabelle, plus Ø Außentemperatur und Gradtage seit
   der letzten Ablesung, plus Anzahl der Fotos
2. **Verbrauch** – je Zähler und Abschnitt: Verbrauch, pro Tag, Kosten, Temperatur,
   Gradtage, Verbrauch je Gradtag
3. **Zählerabgleich** – nur wenn Kontrollzähler eingerichtet sind
4. **Temperaturen** – alle Tageswerte
5. **Zähler** – Konfiguration und Preishistorie

Datumszellen werden als echte Excel-Seriennummern geschrieben, damit Excel damit rechnen
kann. Die Umrechnung steckt in `zb_serial()` und kommt ohne die `calendar`-Erweiterung aus.

## Negative Preise

Preise dürfen negativ sein. Ein negativer Preis bedeutet, dass Geld zurückfließt: Für die
Einspeisung trägt man die Vergütung mit Minuszeichen ein, also zum Beispiel `-0,082`.
Dann gilt:

* Die Kachel zeigt den Betrag als **Gutschrift** in Grün statt als Kosten.
* Mit gesetztem Haken „in Kostensumme“ senkt die Vergütung die Gesamtkosten, die Summe ist
  also der Nettobetrag.
* Im Vergleich und im Excel-Export erscheinen die Beträge mit Vorzeichen.

Ein positiver Preis bei der Einspeisung würde die Vergütung fälschlich als Kosten
aufaddieren. In der Anzeige entscheidet `priceAt(m,t) !== 0`, ob überhaupt Kosten
ausgewiesen werden; eine Prüfung auf „größer null“ würde negative Preise verstecken.

Weil die Zifferntastatur auf dem Handy kein Minuszeichen hat, sitzt neben jedem Preisfeld
eine Taste, die zwischen plus und minus umschaltet. Sie zeigt das aktuelle Vorzeichen und
wird bei minus grün hinterlegt. Auf schmalen Bildschirmen rutscht das Datum in eine eigene
Zeile, damit für Vorzeichen und Betrag genug Platz bleibt.
