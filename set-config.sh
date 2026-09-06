#!/usr/bin/env bash
# Legt config.php auf dem Webspace an: Passwort für die Seite und Anthropic-Schlüssel.
#
# Die beiden Werte werden hier eingegeben, nicht angezeigt und nirgends gespeichert.
# Sie gehen direkt per SFTP bzw. FTP in die Datei config.php auf dem Server.
#
# Aufruf:  bash set-config.sh
set -euo pipefail

cd "$(dirname "$0")"
[ -f deploy.env ] || { echo "deploy.env fehlt."; exit 1; }
# shellcheck disable=SC1091
source ./deploy.env
SITE_URL=${SITE_URL:-https://<deine-domain>}

echo "Ziel: ${USER}@${HOST}:${REMOTE_DIR}/config.php"
echo

read -r -s -p "Passwort zum Bearbeiten: " APP_PW; echo
read -r -s -p "Passwort wiederholen:     " APP_PW2; echo
[ "$APP_PW" = "$APP_PW2" ] || { echo "Die Passwörter sind nicht gleich."; exit 1; }
[ ${#APP_PW} -ge 8 ] || { echo "Bitte mindestens 8 Zeichen."; exit 1; }

echo
echo "Zweites Passwort nur zum Ansehen (leer lassen = kein Lesezugang)."
read -r -s -p "Lesepasswort: " RO_PW; echo
if [ -n "$RO_PW" ]; then
  read -r -s -p "Lesepasswort wiederholen: " RO_PW2; echo
  [ "$RO_PW" = "$RO_PW2" ] || { echo "Die Passwörter sind nicht gleich."; exit 1; }
  [ ${#RO_PW} -ge 8 ] || { echo "Bitte mindestens 8 Zeichen."; exit 1; }
  [ "$RO_PW" != "$APP_PW" ] || { echo "Das Lesepasswort muss sich vom Bearbeiten-Passwort unterscheiden."; exit 1; }
fi

read -r -s -p "Anthropic API-Schlüssel (leer lassen = keine Foto-Erkennung): " API_KEY; echo
echo
read -r -p "Denkaufwand beim Ablesen [low]: " EFFORT
EFFORT=${EFFORT:-low}

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
esc() { printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g"; }
cat > "$TMP" <<PHP
<?php
// Zählerbuch SB85 – von set-config.sh erzeugt. Nicht ins Git.
return [
    'password'          => '$(esc "$APP_PW")',
    'password_readonly' => '$(esc "$RO_PW")',
    'api_key'           => '$(esc "$API_KEY")',
    'model'    => 'claude-opus-5',
    'effort'   => '$(esc "$EFFORT")',
    'data_dir' => __DIR__ . '/data',
];
PHP

case "${MODE}" in
sftp)
  KEY_OPT=()
  [ -n "${SSH_KEY:-}" ] && KEY_OPT=(-i "${SSH_KEY}")
  printf 'cd %s\nput %s config.php\nchmod 640 config.php\nbye\n' "${REMOTE_DIR}" "$TMP" \
    | sftp "${KEY_OPT[@]}" -b - "${USER}@${HOST}" >/dev/null
  ;;
ftp)
  curl -sS --user "${USER}:${FTP_PASSWORD:-}" -T "$TMP" "ftp://${HOST}${REMOTE_DIR%/}/config.php"
  ;;
*)
  echo "MODE muss sftp oder ftp sein."; exit 1;;
esac

unset APP_PW APP_PW2 RO_PW RO_PW2 API_KEY
echo
echo "config.php ist auf dem Server."
echo "Kontrolle: ${SITE_URL:-https://<deine-domain>}/api.php?p=health"
echo "  password muss true sein, readonly_pw true wenn ein Lesepasswort gesetzt wurde,"
echo "  api_key true wenn ein Schlüssel eingetragen wurde."
