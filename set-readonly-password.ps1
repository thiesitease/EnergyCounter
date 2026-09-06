# Zählerbuch SB85 – Lesepasswort setzen, ändern oder entfernen (PowerShell-Fassung).
#
# Ergänzt in der bestehenden config.php nur den Eintrag 'password_readonly' und lädt sie
# hoch. Bearbeiten-Passwort, API-Schlüssel und alle anderen Einträge bleiben unverändert.
# Das Passwort wird verdeckt eingegeben und nirgends protokolliert.
#
# Aufruf in PowerShell, im Ordner Energie:
#     .\zaehlerbuch\set-readonly-password.ps1

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

if (-not (Test-Path 'deploy.env'))     { throw 'deploy.env fehlt.' }
if (-not (Test-Path 'php/config.php')) { throw 'php/config.php fehlt. Dann bitte set-config.ps1 benutzen.' }

# --- deploy.env einlesen (KEY=WERT, Kommentare und Anführungszeichen werden entfernt)
$env_ = @{}
foreach ($zeile in Get-Content 'deploy.env') {
    $t = $zeile.Trim()
    if ($t -eq '' -or $t.StartsWith('#')) { continue }
    if ($t -match '^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
        $wert = ($Matches[2] -replace '\s+#.*$', '').Trim().Trim('"').Trim("'")
        $env_[$Matches[1]] = $wert
    }
}
foreach ($k in 'MODE', 'HOST', 'USER', 'REMOTE_DIR') {
    if (-not $env_.ContainsKey($k) -or $env_[$k] -eq '') { throw "In deploy.env fehlt $k." }
}

Write-Host "Lesepasswort für $($env_.REMOTE_DIR)/config.php auf $($env_.HOST)"
Write-Host 'Es erlaubt alle Auswertungen, Fotos und den Excel-Export, aber keine Änderungen.'
Write-Host 'Leer lassen und Enter druecken entfernt einen bestehenden Lesezugang.'
Write-Host ''

function Read-Geheim([string]$Text) {
    $sicher = Read-Host -Prompt $Text -AsSecureString
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sicher)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}

$pw = Read-Geheim 'Lesepasswort'
if ($pw -ne '') {
    $pw2 = Read-Geheim 'Wiederholen '
    if ($pw -ne $pw2) { throw 'Die Passwoerter sind nicht gleich.' }
    if ($pw.Length -lt 8) { throw 'Bitte mindestens 8 Zeichen.' }
}

# --- config.php anpassen
$inhalt = Get-Content 'php/config.php' -Raw -Encoding UTF8

$editTreffer = [regex]::Match($inhalt, "'password'\s*=>\s*'((?:[^'\\]|\\.)*)'")
if (-not $editTreffer.Success) { throw "In config.php wurde kein Eintrag 'password' gefunden." }
if ($pw -ne '' -and $pw -eq $editTreffer.Groups[1].Value) {
    throw 'Das Lesepasswort muss sich vom Bearbeiten-Passwort unterscheiden.'
}

$escaped = $pw.Replace('\', '\\').Replace("'", "\'")
$neueZeile = "    'password_readonly' => '$escaped',"

# Nur das erste Vorkommen ersetzen. Den Zaehler gibt es ausschliesslich an der
# Instanz-Methode, nicht an der statischen – sonst landet die 1 als RegexOptions.
if ($inhalt -match "'password_readonly'\s*=>") {
    $rx = [regex]"[ \t]*'password_readonly'\s*=>\s*'(?:[^'\\]|\\.)*'\s*,"
    $inhalt = $rx.Replace($inhalt,
        [System.Text.RegularExpressions.MatchEvaluator] { param($m) $neueZeile }, 1)
} else {
    $rx = [regex]"([ \t]*'password'\s*=>\s*'(?:[^'\\]|\\.)*'\s*,)"
    $inhalt = $rx.Replace($inhalt,
        [System.Text.RegularExpressions.MatchEvaluator] { param($m) $m.Groups[1].Value + "`n" + $neueZeile }, 1)
}

# ohne BOM schreiben, sonst schickt PHP Zeichen vor den Kopfzeilen los
[System.IO.File]::WriteAllText((Resolve-Path 'php/config.php'), $inhalt, (New-Object System.Text.UTF8Encoding($false)))
Write-Host ($(if ($pw -ne '') { 'config.php ergaenzt' } else { 'Lesezugang entfernt' }))

# --- hochladen
if ($env_.MODE -eq 'sftp') {
    $key = $env_.SSH_KEY
    if ($key) { $key = $key -replace '^~', $HOME }
    $batch = [System.IO.Path]::GetTempFileName()
    @(
        "cd $($env_.REMOTE_DIR)"
        'put php/config.php config.php'
        'chmod 640 config.php'
        'bye'
    ) | Set-Content -Path $batch -Encoding Ascii
    try {
        if ($key) { & sftp -i $key -b $batch "$($env_.USER)@$($env_.HOST)" | Out-Null }
        else      { & sftp -b $batch "$($env_.USER)@$($env_.HOST)" | Out-Null }
        if ($LASTEXITCODE -ne 0) { throw "sftp hat mit Fehlercode $LASTEXITCODE abgebrochen." }
    } finally { Remove-Item $batch -ErrorAction SilentlyContinue }
}
elseif ($env_.MODE -eq 'ftp') {
    $ziel = "ftp://$($env_.HOST)$($env_.REMOTE_DIR.TrimEnd('/'))/config.php"
    & curl.exe -sS --user "$($env_.USER):$($env_.FTP_PASSWORD)" -T 'php/config.php' $ziel
    if ($LASTEXITCODE -ne 0) { throw "curl hat mit Fehlercode $LASTEXITCODE abgebrochen." }
}
else { throw 'MODE muss sftp oder ftp sein.' }

$seite = if ($env_.SITE_URL) { $env_.SITE_URL.TrimEnd('/') } else { 'https://<deine-domain>' }
$pw = $null; $pw2 = $null
Write-Host ''
Write-Host 'Hochgeladen. Kontrolle:'
Write-Host "  $seite/api.php?p=health"
Write-Host '  readonly_pw muss true sein.'
