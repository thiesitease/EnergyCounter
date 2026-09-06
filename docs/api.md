# Schnittstelle

Alles läuft über `api.php?p=<aktion>`. Der Einstiegspunkt mit Abfrageparameter statt
schöner Pfade ist Absicht: So braucht es keine URL-Umschreibung und die App läuft auch auf
Paketen ohne `mod_rewrite`. Verwendet werden nur GET und POST, weil manche Hoster PUT und
DELETE blockieren.

Angemeldet wird über ein `HttpOnly`-Cookie, das `?p=login` setzt. Für Aufrufe von außen
geht auch der Kopfzeilen-Weg `X-ZB-Auth: <passwort>` oder `Authorization: Bearer <passwort>`.

| Methode | Aktion | Rolle | Zweck |
|---|---|---|---|
| GET | `health` | – | Zustand des Servers, ohne Anmeldung erreichbar |
| POST | `login` | – | `{password}` → setzt das Cookie, meldet die Rolle zurück |
| GET | `state` | lesen | Ablesungen, Konfiguration, Wetter, Rolle |
| GET | `weather` | lesen | Wetter-Zwischenstand |
| GET | `geocode&q=Ort` | lesen | Ortssuche über Open-Meteo |
| GET | `photo&id=…&f=…` | lesen | ein archiviertes Foto ausliefern |
| GET | `export.xlsx` | lesen | Excel-Datei |
| POST | `save-reading` | bearbeiten | `{id, t, v, n, chg, ph, src}` |
| POST | `delete-reading` | bearbeiten | `{id}`, löscht auch die Fotos |
| POST | `save-config` | bearbeiten | Zähler, Preise, Standort |
| POST | `save-photo` | bearbeiten | `{id, meter, image}` → Dateiname |
| POST | `delete-photo` | bearbeiten | `{id, file}` |
| POST | `recognize` | bearbeiten | `{image, media_type, prompt}` → erkannter Stand |
| POST | `weather-step` | bearbeiten | holt einen Block Wetterdaten, meldet `done` |

Die Spalte Rolle meint das nötige Passwort, siehe [rollen.md](rollen.md). Aktionen für
„bearbeiten“ stehen in `api.php` in der Liste `$schreibend` und werden sonst mit HTTP 403
abgewiesen.

## Antwort von `health`

```json
{
  "ok": true, "php": "8.2.31", "writable": true, "data_dir": "data",
  "api_key": true, "password": true, "readonly_pw": true,
  "curl": true, "zlib": true, "model": "claude-opus-5",
  "readings": 108, "https": true,
  "photos": { "count": 0, "bytes": 0 },
  "limits": { "max_execution_time": 60, "raisable": false,
              "post_max_size": "500M", "memory_limit": "512M" }
}
```

Das ist der erste Anlaufpunkt nach einer Installation oder einem Update. `writable` false
bedeutet fehlende Schreibrechte auf `data/`, `password` false bedeutet, dass `config.php`
fehlt oder leer ist. In beiden Fällen bleibt die App gesperrt.

## Fehler

Antworten mit einem Feld `error` und einem deutschen `message`. Wichtige Werte:

| `error` | Bedeutung |
|---|---|
| `unauthorized` | nicht angemeldet, HTTP 401 |
| `readonly_mode` | Lesezugang wollte etwas ändern, HTTP 403 |
| `readonly` | Datenverzeichnis nicht beschreibbar |
| `no_api_key` | kein Anthropic-Schlüssel hinterlegt |
| `timeout` | Erkennung dauerte länger als das PHP-Zeitlimit erlaubt |
| `invalid_id` | Kennung oder Dateiname entspricht nicht dem erlaubten Muster |
