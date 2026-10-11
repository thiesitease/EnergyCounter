# Fronius stündlich über das NAS

Der Fronius-Wechselrichter hat einen eigenen Zähler am Netzanschluss, ein Fronius Smart
Meter. Seine Stände lassen sich über die SolarAPI im Heimnetz auslesen. Ein Skript auf
dem NAS holt sie jede Stunde und schickt sie an die Website. Dort landen sie als ganz
normale Ablesungen der Kontrollzähler `frv` und `fre`, siehe
[kontrollzaehler.md](kontrollzaehler.md).

```
Fronius (Heimnetz) ──HTTP──▶ NAS: fronius-push.sh ──HTTPS + Token──▶ api.php?p=fronius-push
```

Warum über das NAS: Der Webserver erreicht das Heimnetz nicht, und auf dem Hosting sind
eigene Cronjobs und Hintergrundprozesse verboten (siehe [betrieb.md](betrieb.md)). Das
NAS läuft ohnehin durch.

## Was der Fronius liefert

`GET http://<fronius>/solar_api/v1/GetMeterRealtimeData.cgi?Scope=System`, ohne Anmeldung,
sobald die SolarAPI in der Weboberfläche des Wechselrichters eingeschaltet ist.

| Feld | Bedeutung | Zähler |
|---|---|---|
| `EnergyReal_WAC_Sum_Consumed` | Bezug aus dem Netz in **Wh** | `frv` |
| `EnergyReal_WAC_Sum_Produced` | Einspeisung ins Netz in **Wh** | `fre` |
| `TimeStamp` | Zeitpunkt der Messung, Sekunden seit 1970 (UTC) | `t` |
| `Meter_Location_Current` | 0 = Zähler am Netzanschluss | Auswahl des Zählers |

Die Website teilt durch 1000 und speichert kWh mit drei Nachkommastellen. Welches Feld in
welchen Zähler geht, lässt sich in `config.php` mit `'fronius_map'` ändern, zum Beispiel
`['frv' => 'EnergyReal_WAC_Sum_Consumed']`.

Die PV-Erzeugung (`E_Total` aus `GetPowerFlowRealtimeData.fcgi`) wird bewusst nicht
erfasst. `E_Day` und `E_Year` liefert die Firmware des betriebenen Geräts nicht.

## Die Ablesung auf der Website

```json
{ "id": "fronius-2026101113", "t": 1791725378000, "v": { "frv": 12345.678, "fre": 2345.678 }, "src": "fronius" }
```

* Die `id` ist die Stunde in UTC. Schickt das NAS dieselbe Stunde noch einmal, ersetzt sie
  den Eintrag, statt ihn zu verdoppeln.
* `t` ist der Messzeitpunkt des Fronius, nicht der Zeitpunkt der Übertragung. Nachgeholte
  Stunden stehen deshalb an der richtigen Stelle.
* Kaputte Antworten, Zählerstände von null und unplausible Zeitstempel werden abgewiesen.

Rund 8 760 Einträge im Jahr. Alle Auswertungen, der Zählerabgleich und der Excel-Export
rechnen mit allen Werten. Nur der **Verlauf** fasst sie zusammen, standardmäßig je Woche.

## Endpunkt und Token

`POST api.php?p=fronius-push` mit Kopfzeile `X-Push-Token: <token>`. Im Rumpf steht eine
Fronius-Antwort oder eine Liste davon, höchstens 1 000 auf einmal. Die Antwort sieht so aus:

```json
{ "ok": true, "saved": 3, "rejected": [], "last": { "t": 1791725378000, "v": { "frv": 12345.678, "fre": 2345.678 } } }
```

Der Token steht als `'push_token'` in `config.php` und erlaubt **nur** diese eine Aktion.
Das Bearbeiten-Passwort liegt also nicht auf dem NAS. Ohne Token ist der Endpunkt aus.
`api.php?p=health` zeigt `push_token` und `fronius_last`, den Zeitpunkt des letzten Werts.

Token erzeugen und eintragen:

```powershell
.\set-push-token.ps1 -Fronius http://<ip-des-fronius>
```

Das Skript erzeugt einen neuen Token und trägt ihn per SSH direkt in die `config.php` auf
dem Server ein. Dort legt es vorher `config.php.vor-push.bak` an. Außerdem schreibt es
`nas/fronius-push.conf` für das NAS. Ein erneuter Aufruf ersetzt den Token, der alte wird
damit ungültig.

## Das Skript auf dem NAS

`nas/fronius-push.sh` braucht nur `sh` und `curl`, beides gehört zum DSM der Synology.

1. Die Abfrage beim Fronius wird als Datei in `spool/` abgelegt, aber nur, wenn sie
   fehlerfrei ist.
2. Alles, was in `spool/` liegt, geht in **einer** Anfrage an die Website, älteste zuerst,
   höchstens 200 auf einmal. Viele schnelle Einzelanfragen würde der Hoster drosseln.
3. Bei HTTP 200 werden die Dateien gelöscht. Bei einem anderen Fehler bleiben sie liegen
   und gehen beim nächsten Lauf mit. Ein Ausfall der Website verliert also keine Stunde.
4. Bei HTTP 400 ist der Inhalt selbst kaputt. Die Dateien wandern dann nach
   `spool/abgelehnt/`, damit sie die Warteschlange nicht blockieren.

Probelauf ohne Senden: `DRY_RUN=1 sh fronius-push.sh`

### Einrichten auf der Synology

1. Einen Ordner anlegen, **ohne Leerzeichen im Pfad**, etwa `/volume1/scripts/zaehlerbuch`.
   Dorthin `fronius-push.sh` und die von `set-push-token.ps1` erzeugte
   `fronius-push.conf` kopieren.
2. **Systemsteuerung → Aufgabenplaner → Erstellen → Geplante Aufgabe → Benutzerdefiniertes
   Script**.
   * *Allgemein:* Name „Zählerbuch Fronius“, Benutzer: ein normaler Benutzer mit
     Schreibrecht auf den Ordner, nicht `root`.
   * *Zeitplan:* täglich, erste Ausführung 00:00, Häufigkeit **jede Stunde**, letzte
     Ausführung 23:00.
   * *Aufgabeneinstellungen:* Benutzerdefiniertes Script
     `sh /volume1/scripts/zaehlerbuch/fronius-push.sh`. Wer eine Nachricht bei Fehlern
     möchte, setzt den Haken bei „Ausführungsdetails per E-Mail senden“ und dort „nur wenn
     das Script abnormal beendet wird“.
3. Die Aufgabe einmal von Hand ausführen. Danach steht der Wert im Verlauf, und
   `health` zeigt `fronius_last`.

Der Verlauf warnt, wenn seit über drei Stunden kein Wert vom Fronius gekommen ist.
