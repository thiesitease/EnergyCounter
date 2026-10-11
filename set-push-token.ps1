# Zählerbuch SB85 – Token für das NAS erzeugen (PowerShell).
#
# Das NAS schickt stündlich die Zählerstände des Fronius an api.php?p=fronius-push und
# meldet sich dabei mit diesem Token an. Er erlaubt nur genau diese eine Aktion.
#
# Das Skript
#   1. erzeugt einen neuen Zufallstoken (ein alter wird damit ungültig),
#   2. trägt ihn als 'push_token' in die config.php AUF DEM SERVER ein. Die Datei wird
#      dazu nicht heruntergeladen: Ein kurzer PHP-Aufruf über SSH ändert sie dort und
#      legt vorher config.php.vor-push.bak an. Der Token geht über die Standardeingabe,
#      damit er nicht in der Prozessliste des Servers auftaucht.
#   3. schreibt nas/fronius-push.conf. Die Datei kommt neben fronius-push.sh auf das NAS.
#
# Aufruf in PowerShell im Projektordner:
#     .\set-push-token.ps1 -Fronius http://192.168.0.10

param([string]$Fronius = '')

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

if (-not (Test-Path 'deploy.env')) { throw 'deploy.env fehlt.' }
$env_ = @{}
foreach ($zeile in Get-Content 'deploy.env') {
    $t = $zeile.Trim()
    if ($t -eq '' -or $t.StartsWith('#')) { continue }
    if ($t -match '^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
        $env_[$Matches[1]] = ($Matches[2] -replace '\s+#.*$', '').Trim().Trim('"').Trim("'")
    }
}
foreach ($k in 'HOST', 'USER', 'REMOTE_DIR', 'SITE_URL') {
    if (-not $env_.ContainsKey($k) -or $env_[$k] -eq '') { throw "In deploy.env fehlt $k." }
}
if ($env_.MODE -ne 'sftp') { throw 'Dieses Skript braucht SSH (MODE=sftp in deploy.env).' }
if ($Fronius -eq '') { $Fronius = Read-Host 'Adresse des Fronius im Heimnetz, z. B. http://192.168.0.10' }
if ($Fronius -notmatch '^https?://') { throw 'Die Adresse muss mit http:// beginnen.' }

# --- Token: 32 Zufallsbytes als Hex, damit er ohne Maskieren in PHP und sh passt
$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$token = -join ($bytes | ForEach-Object { $_.ToString('x2') })

# --- config.php auf dem Server ändern. Nur ASCII, PowerShell 5.1 schickt sonst Fragezeichen.
$php = @'
<?php
$f = '__ZIEL__/config.php';
$s = @file_get_contents($f);
if ($s === false) { fwrite(STDERR, "config.php nicht lesbar\n"); exit(1); }
$line = "    'push_token' => '__TOKEN__',";
// Zeilenweise statt mit regulaeren Ausdruecken: Auf der Kommandozeile des Hosters
// bricht PCRE bei verschachtelten Mustern mit "Internal error" ab.
$lines = explode("\n", $s);
$done = false;
foreach ($lines as $i => $l) {
    if (strpos(ltrim($l), "'push_token'") === 0) { $lines[$i] = $line; $done = true; break; }
}
if (!$done) {
    foreach ($lines as $i => $l) {
        if (strpos(ltrim($l), 'return [') === 0) { array_splice($lines, $i + 1, 0, [$line]); $done = true; break; }
    }
}
if (!$done) { fwrite(STDERR, "'return [' nicht gefunden\n"); exit(1); }
$s = implode("\n", $lines);
$tmp = $f . '.neu';
if (file_put_contents($tmp, $s) === false) { fwrite(STDERR, "kann nicht schreiben\n"); exit(1); }
chmod($tmp, 0640);
$check = include $tmp;
if (!is_array($check) || ($check['push_token'] ?? '') !== '__TOKEN__' || ($check['password'] ?? '') === '') {
    unlink($tmp); fwrite(STDERR, "Ergebnis ungueltig, nichts geaendert\n"); exit(1);
}
copy($f, $f . '.vor-push.bak'); chmod($f . '.vor-push.bak', 0640);
if (!rename($tmp, $f)) { unlink($tmp); fwrite(STDERR, "Ersetzen fehlgeschlagen\n"); exit(1); }
echo "ok\n";
'@
$php = $php.Replace('__ZIEL__', $env_.REMOTE_DIR.TrimEnd('/')).Replace('__TOKEN__', $token)

$key = $env_.SSH_KEY
if ($key) { $key = $key -replace '^~', $HOME }
$sshArgs = @('-o', 'BatchMode=yes')
if ($key) { $sshArgs += @('-i', $key) }
$antwort = $php | & ssh @sshArgs "$($env_.USER)@$($env_.HOST)" php
if ($LASTEXITCODE -ne 0 -or $antwort -notmatch 'ok') { throw "Eintragen auf dem Server fehlgeschlagen ($LASTEXITCODE)." }
Write-Host 'push_token in config.php auf dem Server eingetragen.'

# --- Einstellungen für das NAS, ohne BOM und mit LF, sonst stolpert sh
$conf = "# Einstellungen fuer fronius-push.sh, erzeugt von set-push-token.ps1. Nicht ins Git.`n" +
        "FRONIUS=$Fronius`nSITE_URL=$($env_.SITE_URL.TrimEnd('/'))`nPUSH_TOKEN=$token`n"
[System.IO.File]::WriteAllText((Join-Path $PSScriptRoot 'nas\fronius-push.conf'), $conf, (New-Object System.Text.UTF8Encoding($false)))
$token = $null
Write-Host 'nas\fronius-push.conf geschrieben. Zusammen mit nas\fronius-push.sh auf das NAS kopieren.'
Write-Host "Kontrolle: $($env_.SITE_URL.TrimEnd('/'))/api.php?p=health  -> push_token muss true sein."
