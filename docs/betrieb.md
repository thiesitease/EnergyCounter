# Betrieb auf einem Webhosting-Paket

## Warum PHP und nicht der Python-Server

Der Anbieter des betriebenen Pakets untersagt in seinen Bedingungen ausdrücklich:

* dauerhafte Prozesse und Hintergrundprozesse
* Prozesse, die auf einem Port lauschen
* eigene Cronjobs (dafür gibt es eine Funktion im Kundenmenü)
* Container-Virtualisierung, also Docker und Vergleichbares
* Rechte-Eskalation über `su` oder `sudo`

Zusätzlich verstößt die SSH-Erweiterung von Visual Studio Code dagegen, weil sie
Serversoftware installiert und ausführt.

Der Python-Server aus diesem Repository verletzt gleich zwei dieser Punkte. Deshalb läuft
dort die PHP-Fassung. Sie kommt ohne Dauerprozess aus:

| Regel | Umsetzung |
|---|---|
| Keine Hintergrundprozesse | PHP läuft nur während eines Seitenaufrufs. Auch das Nachladen der Wetterdaten passiert in kurzen Einzelschritten, die der Browser nacheinander anstößt. |
| Nichts lauscht auf einem Port | Die Seite liefert der Webserver des Anbieters aus. |
| Kein eigener Cronjob | Wird nicht gebraucht. Fehlende Wettertage holt die App beim Öffnen nach. |
| Keine Container | Die Docker-Dateien gehören zur Python-Variante für den eigenen Server. |
| Keine Rechte-Eskalation | Auf dem Server wird nichts installiert. |

SSH wird ausschließlich zum Kopieren der Dateien und für lesende Befehle wie `ls` benutzt.
FTP genügt genauso.

## Gemessene Grenzwerte des Pakets

| Wert | |
|---|---|
| PHP | 8.2, mit curl, zlib, mbstring, json, openssl |
| `max_execution_time` | 60 s, lässt sich per `set_time_limit()` nicht erhöhen |
| `post_max_size` | 500 M |
| `memory_limit` | 512 M |

Wegen der 60 Sekunden wartet die Foto-Erkennung höchstens 50 Sekunden auf die Antwort und
meldet sich danach mit einer verständlichen Meldung zurück. `effort` steht deshalb auf
`low`. Höhere Werte denken länger und laufen eher in das Limit.

Der Anbieter drosselt außerdem viele schnelle Zugriffe von derselben Adresse. Bei
automatisierten Tests kann Port 443 zeitweise für die eigene IP gesperrt werden, während
SSH weiter funktioniert. Prüfungen also nicht in Schleifen gegen die Seite fahren.

## Was auf den Server gehört

```
index.html            die App
api.php               Schnittstelle (Speichern, Foto, Wetter, Export)
lib.php               Funktionen
config.php            Passwörter und API-Schlüssel  ← selbst anlegen
.htaccess             HTTPS-Zwang, schützt config.php und die JSON-Dateien
robots.txt            hält Suchmaschinen fern
manifest.webmanifest  damit die Seite auf dem Handy wie eine App startet
data/.htaccess        sperrt das Datenverzeichnis für direkte Zugriffe
data/config.json      Zähler, Preise, Standort  (aus config.example.json erzeugen)
data/readings.json    entsteht bei der ersten Ablesung
```

## Einrichten

1. `config.example.php` als `config.php` kopieren und ausfüllen:

   ```php
   return [
       'password'          => 'zum-bearbeiten',
       'password_readonly' => 'nur-zum-ansehen',   // optional
       'api_key'           => 'sk-ant-...',        // optional, für die Foto-Erkennung
       'model'             => 'claude-opus-5',
       'effort'            => 'low',
       'data_dir'          => __DIR__ . '/data',
   ];
   ```

   Ohne Passwort bleibt die App gesperrt. Das ist Absicht, damit sie nicht versehentlich
   offen im Netz steht.

2. `php/data/config.example.json` als `data/config.json` kopieren und die eigenen Zähler,
   Seriennummern, Preise und den Standort eintragen. Das geht auch später in der Oberfläche
   unter „Zähler“.

3. Alles per FTP oder SFTP ins Webverzeichnis laden, `deploy.sh` nimmt einem das ab.

4. Für `data/` Schreibrechte setzen: `0775`, falls das nicht reicht `0777`.

5. `https://<domain>/api.php?p=health` aufrufen. Dort steht, ob PHP, Schreibrechte, cURL,
   zlib, Passwörter und Schlüssel in Ordnung sind.

6. Zur Kontrolle `https://<domain>/data/readings.json` aufrufen. Es muss **403** kommen.
   Falls doch der Inhalt erscheint, greift `.htaccess` nicht. Dann `data_dir` in
   `config.php` auf einen Ordner außerhalb des Webverzeichnisses zeigen lassen.

7. Seite öffnen, anmelden, unter „Zähler“ den Ort für die Wetterdaten wählen und speichern.

8. Auf dem Handy „Zum Startbildschirm hinzufügen“, dann startet es wie eine App.

## Wetterdaten

Tagesmitteltemperaturen kommen von Open-Meteo, kostenlos und ohne Konto. Nötig ist nur der
Standort. Der Abruf läuft in Blöcken von fünf Jahren, ein Block pro Anfrage, bis alles da
ist; ab dem Jahr der ersten Ablesung sind das etwa fünf Anfragen von je einer halben
Sekunde. Gespeichert wird in `data/weather.json`, das jederzeit neu geholt werden kann.

Der Abruf ist dem Bearbeiten-Zugang vorbehalten, weil er auf den Server schreibt.

## Foto-Erkennung

Das Handy macht das Foto, die Seite verkleinert es auf 1600 px und schickt es an
`api.php?p=recognize`. PHP hängt die Zählerliste an (Seriennummern, letzte Stände,
Hinweise) und fragt die Anthropic-API. Die Antwort ist per JSON-Schema erzwungen. Kosten
liegen bei etwa anderthalb Cent je Foto. Die Fotos gehen nur zur Erkennung dorthin und
werden nicht bei Anthropic gespeichert.

## Sicherung

Die eigenen Daten sind zwei Dateien, `data/readings.json` und `data/config.json`, dazu der
Ordner `data/photos`. Ab und zu per FTP herunterladen genügt, oder den Excel-Export
aufheben. `data/weather.json` ist nur ein Zwischenspeicher.

## Aktualisieren

`index.html`, `api.php` und `lib.php` überschreiben, `deploy.sh` macht genau das.
`config.php` und `data/` dabei nicht anfassen.

## SSH-Schlüssel für das Hochladen

Für `deploy.sh` empfiehlt sich ein eigener Schlüssel, der nur diesem Zweck dient. Dann
lässt sich der Zugang später allein durch Löschen im Kundenmenü zurückziehen, ohne dass
andere Dinge betroffen sind:

```bash
ssh-keygen -t ed25519 -a 100 -f ~/.ssh/zaehlerbuch -N "" -C "zaehlerbuch-deploy"
```

Ohne Passphrase läuft das Hochladen ohne Rückfrage, dafür kann jeder mit Zugriff auf das
Benutzerkonto den Schlüssel benutzen. Eine Passphrase lässt sich mit
`ssh-keygen -p -f ~/.ssh/zaehlerbuch` nachrüsten. Nimmt das Kundenmenü ed25519 nicht an,
tut es ein RSA-Schlüssel mit 4096 Bit.

Unter Windows sollte die Datei nur dem eigenen Benutzer gehören:

```powershell
icacls "$env:USERPROFILE\.ssh\zaehlerbuch" /inheritance:r /grant:r "$($env:USERNAME):(R,W)"
```
