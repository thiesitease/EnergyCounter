# Kontrollzähler

Manche Zähler messen dasselbe wie ein anderer. Im betriebenen Haushalt zählt der
Fronius-Wechselrichter parallel zum Stromzähler der Stadtwerke, einmal für den Bezug und
einmal für die Einspeisung. Der Sinn ist die Kontrolle, ob beide gleich laufen.

## Einrichtung

```json
{ "key": "frv", "controlOf": "s180", "tolerance": 1, "tolPerDay": 0.05 }
{ "key": "fre", "controlOf": "s280", "tolerance": 1, "tolPerDay": 0.05 }
```

`controlOf` nennt den Hauptzähler. Erlaubt ist die Abweichung `tolerance` plus
`tolPerDay` mal Anzahl der Tage.

## Folgen im Programm

* **Gerechnet wird mit dem Hauptzähler.** Kacheln, Vergleich und Kostensumme nehmen den
  Zähler des Versorgers, Kontrollzähler erscheinen dort nicht. In der Oberfläche liefert
  `mainMeters()` die Liste ohne sie. Der Kontrollzähler liefert aber den **zeitlichen
  Verlauf**, siehe unten unter „Verlauf aus dem Kontrollzähler“.
* **Beim Erfassen wird sofort verglichen.** Stehen beide Werte im Formular, prüft
  `crossCheck()` den Verbrauch seit der letzten Ablesung des Hauptzählers, zu der es auch
  einen Stand des Kontrollzählers gibt.
* **In der Auswertung** gibt es die Karte „Zählerabgleich“ mit den letzten acht Abschnitten.
* **Im Excel-Export** gibt es das gleichnamige Blatt mit allen Abschnitten.

Verglichen wird immer der Verbrauch zwischen zwei Ablesungen, nie der absolute Stand: Die
Zähler haben unterschiedliche Anfangswerte. Um einen Zählerwechsel herum wird der
betroffene Abschnitt übersprungen.

## Abschnitte und Stundenwerte

Ein Abschnitt reicht von einer Ablesung des **Hauptzählers** bis zur nächsten. Den Stand
des Kontrollzählers zu diesen Zeitpunkten liefert

* dieselbe Ablesung, wenn dort beide Werte stehen, so wie früher bei jedem Foto, oder
* die Stundenwerte vom NAS ([fronius-nas.md](fronius-nas.md)): linear zwischen dem Wert
  davor und danach, aber nur, wenn **beide höchstens drei Stunden** entfernt sind und
  dazwischen kein Zählerwechsel liegt.

Bei einer Stunde Abstand liegt der Fehler der Interpolation deutlich unter der Toleranz.
Zwischen alten, seltenen Handablesungen wird dagegen nie interpoliert, die Grenze von drei
Stunden verhindert das. `frv` und `fre` müssen deshalb nicht mehr von Hand abgelesen werden.

Umgesetzt in `nearAt()` und `controlSeries()` in `index.html` und gleichlautend in
`zb_near_at()` und `zb_control_rows()` in `lib.php`. Beide müssen gleich rechnen.

## Verlauf aus dem Kontrollzähler

Der Zähler der Stadtwerke wird nur ab und zu fotografiert. Der Fronius liefert dagegen
stündliche Werte. Für jeden frei gewählten Zeitraum rechnet `cumFor()` in `index.html`
deshalb so:

* **Zwischen zwei Ablesungen des Hauptzählers** wird dessen Verbrauch nach dem Verlauf des
  Kontrollzählers verteilt statt gleichmäßig. Die Summe zwischen den beiden Ablesungen
  bleibt genau die des Hauptzählers, also die der Rechnung. Ein Abend mit Sauna bekommt so
  mehr ab als die Nacht.
* **Nach der letzten Ablesung** geht es mit dem Zuwachs des Kontrollzählers weiter. Die
  Kacheln sind damit aktuell, auch wenn das letzte Foto Wochen alt ist.
* Fehlen Werte des Kontrollzählers in der Nähe (mehr als drei Stunden Abstand, also vor
  den Stundenwerten vom NAS) oder liegt ein Zählerwechsel dazwischen, bleibt es bei der
  linearen Interpolation. Die Geschichte seit 2008 bleibt damit unverändert.

Den Zähler der Stadtwerke muss man daher nur noch gelegentlich fotografieren, zum Beispiel
monatlich oder zum Ablesetermin. Fotos der Fronius-Anzeige braucht es nicht mehr.

Das betrifft nur die Oberfläche. Der Excel-Export rechnet Abschnitte zwischen echten
Ablesungen und interpoliert nicht.

## Warum es eine Toleranz je Tag gibt

In den vorhandenen Daten stimmen die Zähler bei kurzen Abständen sehr genau überein. Bei
langen Abständen läuft der Fronius aber gleichmäßig etwa 0,05 kWh pro Tag hinter dem
Zähler der Stadtwerke her:

| Abschnitt | Tage | Abweichung |
|---|---|---|
| kurz | 8 | -0,09 kWh |
| mittel | 88 | -4,60 kWh |
| lang | 130 | -6,27 kWh |

Mit einer festen Grenze von 1 kWh würden lange Abschnitte immer warnen, obwohl nichts
kaputt ist. `tolPerDay` von 0,05 hebt diesen Dauerversatz auf, sodass nur noch echte
Abweichungen auffallen. Wer die Zähler strenger prüfen will, setzt den Wert auf null.

## Erweiterung

Das Muster ist allgemein: Jeder Zähler kann Kontrollzähler eines anderen werden, die
Einstellung steht in der Oberfläche unter „Zähler“. Wichtig ist nur, dass beide dieselbe
Ergebnis-Einheit haben, sonst vergleicht die Prüfung Äpfel mit Birnen.
