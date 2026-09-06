# Zwei Passwörter: bearbeiten und nur ansehen

In `config.php` stehen zwei Einträge:

```php
'password'          => 'zum-bearbeiten',   // Pflicht
'password_readonly' => 'nur-zum-ansehen',  // optional
```

Das eingegebene Passwort entscheidet über die Rolle. Der Server merkt sich die Rolle im
Anmelde-Cookie, das je Rolle einen eigenen Wert hat.

| | Bearbeiten | Nur Lesen |
|---|---|---|
| Auswertung, Vergleich, Verlauf | ja | ja |
| Archivierte Fotos ansehen | ja | ja |
| Excel-Export | ja | ja |
| Zähler und Preise ansehen | ja | ja |
| Ablesungen erfassen oder ändern | ja | **nein** |
| Fotos hochladen oder löschen | ja | **nein** |
| Foto-Erkennung starten | ja | **nein** |
| Einstellungen ändern | ja | **nein** |
| Wetterdaten nachladen | ja | **nein** |

## Wie es durchgesetzt wird

`zb_role()` in `lib.php` gibt `edit`, `view` oder `null` zurück. In `api.php` steht eine
Liste `$schreibend`; jede darin genannte Aktion wird mit **HTTP 403** abgewiesen, wenn die
Rolle nicht `edit` ist. Der Schutz sitzt also im Server.

**Wer eine neue schreibende Aktion ergänzt, muss sie in diese Liste eintragen.** Die
Oberfläche allein ist kein Schutz.

Die Oberfläche richtet sich nach `features.canEdit` aus `?p=state`: Sie blendet den Bereich
„Erfassen“ aus, startet in der Auswertung, sperrt alle Eingabefelder unter „Zähler“ und
versteckt dort den Speichern-Knopf, macht die Zeilen im Verlauf nicht anklickbar und zeigt
oben rechts das Kennzeichen „nur Lesen“.

Das Nachladen der Wetterdaten ist dem Bearbeiten-Zugang vorbehalten, weil es auf den Server
schreibt. Ein Lesezugang sieht die zuletzt geholten Temperaturen.

## Cookie

Der Wert für die Bearbeiten-Rolle wird unverändert aus `hash_hmac('sha256', 'zb-auth-v1',
$passwort)` gebildet, damit bestehende Anmeldungen beim Nachrüsten des Lesemodus gültig
blieben. Die Leserolle nutzt `zb-auth-v1-view` mit dem zweiten Passwort. Das Cookie ist
`HttpOnly` und `SameSite=Strict`, bei HTTPS zusätzlich `Secure`.

## Passwort setzen oder ändern

Unter Windows in PowerShell:

```powershell
.\set-readonly-password.ps1
```

Sonst:

```bash
bash set-readonly-password.sh
```

Beide fragen nur nach dem Lesepasswort, ergänzen es in der vorhandenen `config.php` und
laden sie hoch. Alle anderen Einträge bleiben stehen, eine leere Eingabe entfernt den
Lesezugang. Wer die Datei komplett neu schreiben will, nimmt `set-config.sh`.
