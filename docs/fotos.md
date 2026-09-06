# Fotos: Erkennung, Plausibilität, Archiv

## Viele Bilder auf einmal

Über „Aus Galerie“ lassen sich beliebig viele Bilder gleichzeitig auswählen, über
„Foto aufnehmen“ sammeln sich nacheinander aufgenommene Bilder im selben Formular. Die
Erkennung arbeitet eine Warteschlange mit **drei gleichzeitigen Anfragen** ab, siehe
`PHOTO_PARALLEL` in `index.html`.

Die Grenze ist bewusst niedrig: Der Browser erlaubt ohnehin nur etwa sechs Verbindungen
zum selben Server, das Webhosting hat begrenzt viele PHP-Prozesse, und zu viele Anfragen
am Stück lösen die Drosselung des Anbieters aus. Zwölf Fotos brauchen etwa eine Minute.
Der Fortschritt steht über den Bildern, der Speichern-Knopf bleibt bis zum Ende gesperrt.

## Plausibilitätsprüfung

`plausibility()` prüft jeden Wert, egal ob getippt oder erkannt:

| Prüfung | Stufe |
|---|---|
| Zeitpunkt liegt vor der letzten Ablesung | kritisch |
| Anzahl der Stellen vor dem Komma weicht ab | kritisch |
| Wert ist kleiner als der letzte Stand | kritisch |
| Verbrauch pro Tag über dem Zehnfachen des Üblichen | kritisch |
| Verbrauch pro Tag über dem 2,5-fachen des Üblichen | Warnung |
| Verbrauch pro Tag unter einem Fünftel des Üblichen | Warnung |
| sonst | in Ordnung, mit Ø pro Tag |

Die Stelligkeitsprüfung fängt den häufigsten Lesefehler ab, ein verrutschtes Komma: Aus
7024,042 wird 702,4042 oder 70240,42, beides fällt sofort auf. Als üblicher Wert gilt der
Durchschnitt der letzten zwölf Monate. Bei angehaktem Zählerwechsel wird nicht verglichen.
Meldet das Modell selbst weniger als 70 Prozent Sicherheit, steht das zusätzlich am Foto.
Kritische Meldungen führen beim Speichern zu einer Rückfrage.

## Aufnahmezeitpunkt aus dem Bild

`exifDateTime()` liest den APP1-Block der JPEG-Datei von Hand, ohne Bibliothek, und nur
die ersten 256 KB. Gesucht wird `DateTimeOriginal`, ersatzweise `DateTimeDigitized` oder
`DateTime`. Der früheste gefundene Zeitpunkt wird zur Erfassungszeit.

* Fehlt der EXIF-Block, gilt das Änderungsdatum der Datei. Bei Kamerafotos ist das in aller
  Regel ebenfalls der Aufnahmezeitpunkt.
* Werte vor dem Jahr 2000 oder mehr als zwei Tage in der Zukunft werden verworfen.
* Ein von Hand geänderter Zeitpunkt bleibt stehen und wird von später hinzugefügten Fotos
  nicht überschrieben.
* Liegen die Fotos mehr als eine halbe Stunde auseinander, erscheint ein Hinweis. Weicht
  ein einzelnes Foto um mehr als eine Viertelstunde ab, steht seine Aufnahmezeit auf der Karte.
* Die Zeit im Foto ist Ortszeit der Kamera und wird unverändert übernommen.

## Archiv

Beim Speichern wandert jedes Foto zusammen mit der Ablesung auf den Server, verkleinert auf
1200 Pixel bei Qualität 0,72, also etwa 100 bis 200 KB. Zur Erkennung geht die größere
Fassung mit 1600 Pixeln, gespeichert wird nur die kleinere.

* Ablage: `data/photos/<Ablesungs-ID>/<Zähler>-<Nummer>.jpg`
* Verweis in der Ablesung: `"ph": [{"f": "gas-1.jpg", "m": "gas"}]`
* Fotos ohne Zuordnung landen unter `sonstige-N.jpg`
* Ausgeliefert wird nur über `api.php?p=photo&id=…&f=…`, also nur nach Anmeldung. Das
  Verzeichnis selbst ist per `.htaccess` gesperrt.
* Im **Verlauf** steht unter jeder Ablesung eine Reihe Vorschaubilder. Ein Klick öffnet das
  Bild groß, zusammen mit Zähler, Zeitpunkt und eingetragenem Stand.
* Im **Bearbeiten-Dialog** lassen sich einzelne Fotos löschen. Wird eine Ablesung gelöscht,
  verschwinden ihre Fotos mit.
* Der belegte Platz steht in `api.php?p=health` unter `photos`.

Grober Platzbedarf: bei zehn Fotos je Ablesung und etwa 40 Ablesungen im Jahr rund 50 bis
80 MB pro Jahr.

## Sicherheit

Jeder Pfad, der aus Benutzereingaben entsteht, läuft durch `zb_valid_id()` und
`zb_valid_photo()`. Beide lassen nur harmlose Zeichen und die Endung `.jpg` zu, sodass
weder `../` noch andere Ausbrüche möglich sind. Das ist bei Änderungen unbedingt beizubehalten.

## Stolperstein bei der Anthropic-Schnittstelle

Im Antwortschema unter `output_config.format.schema` sind bei Feldern vom Typ `number`
die Angaben `minimum` und `maximum` **nicht erlaubt**. Der Aufruf scheitert sonst mit
„For 'number' type, properties maximum, minimum are not supported“. Grenzen gehören in die
Beschreibung, die Seite begrenzt den Wert zusätzlich selbst.
