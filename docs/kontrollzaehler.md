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

* **Gerechnet wird nur mit dem Hauptzähler.** Kacheln, Vergleich und Kostensumme nehmen
  ausschließlich den Zähler des Versorgers. Kontrollzähler erscheinen dort nicht.
  In der Oberfläche liefert `mainMeters()` die Liste ohne sie.
* **Beim Erfassen wird sofort verglichen.** Stehen beide Werte im Formular, prüft
  `crossCheck()` den Verbrauch seit der letzten Ablesung, in der **beide** Zähler stehen.
  Nur dann sind die Differenzen vergleichbar.
* **In der Auswertung** gibt es die Karte „Zählerabgleich“ mit den letzten acht Abschnitten.
* **Im Excel-Export** gibt es das gleichnamige Blatt mit allen Abschnitten.

Verglichen wird immer der Verbrauch zwischen zwei Ablesungen, nie der absolute Stand: Die
Zähler haben unterschiedliche Anfangswerte. Um einen Zählerwechsel herum wird der
betroffene Abschnitt übersprungen.

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
