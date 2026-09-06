#!/usr/bin/env bash
# Zählerbuch SB85 – Dateien auf das Webhosting laden.
#
# Es wird nur kopiert. Auf dem Server wird nichts installiert, nichts gestartet
# und kein Dienst eingerichtet.
#
# Zugangsdaten kommen aus deploy.env NEBEN dieser Datei (nicht ins Git, nicht in
# den Chat). Vorlage:
#
#   MODE=sftp                      # sftp | ftp
#   HOST=ssh.example.de
#   USER=benutzername
#   REMOTE_DIR=/htdocs             # Webverzeichnis der Domain
#   # bei MODE=sftp mit Schlüssel:
#   SSH_KEY=~/.ssh/zaehlerbuch
#   # bei MODE=ftp:
#   FTP_PASSWORD=...               # oder leer lassen, dann fragt curl nach
#
# Aufruf:  bash deploy.sh            (lädt alles ausser config.php und data/)
#          bash deploy.sh --with-data  (lädt beim ersten Mal auch data/)
set -euo pipefail

cd "$(dirname "$0")"
[ -f deploy.env ] || { echo "deploy.env fehlt – siehe Kopf dieser Datei."; exit 1; }
# shellcheck disable=SC1091
source ./deploy.env
SITE_URL=${SITE_URL:-https://<deine-domain>}

WITH_DATA=0
[ "${1:-}" = "--with-data" ] && WITH_DATA=1

FILES=(php/index.html php/api.php php/lib.php php/.htaccess php/robots.txt php/manifest.webmanifest php/config.example.php)
DATA_FILES=(php/data/readings.json php/data/config.json php/data/.htaccess)

echo "Ziel: ${MODE} ${USER}@${HOST}:${REMOTE_DIR}"
echo "Dateien: ${FILES[*]}"
[ "$WITH_DATA" = 1 ] && echo "Daten:   ${DATA_FILES[*]}"

case "${MODE}" in
sftp)
  KEY_OPT=()
  [ -n "${SSH_KEY:-}" ] && KEY_OPT=(-i "${SSH_KEY}")
  {
    echo "cd ${REMOTE_DIR}"
    for f in "${FILES[@]}"; do echo "put $f"; done
    if [ "$WITH_DATA" = 1 ]; then
      echo "-mkdir data"
      echo "cd ${REMOTE_DIR}/data"
      for f in "${DATA_FILES[@]}"; do echo "put $f"; done
      echo "chmod 775 ."
    fi
    echo "bye"
  } | sftp "${KEY_OPT[@]}" -b - "${USER}@${HOST}"
  ;;
ftp)
  BASE="ftp://${HOST}${REMOTE_DIR%/}/"
  AUTH=(--user "${USER}:${FTP_PASSWORD:-}")
  for f in "${FILES[@]}"; do
    echo "  -> $(basename "$f")"
    curl -sS --ftp-create-dirs "${AUTH[@]}" -T "$f" "${BASE}"
  done
  if [ "$WITH_DATA" = 1 ]; then
    for f in "${DATA_FILES[@]}"; do
      echo "  -> data/$(basename "$f")"
      curl -sS --ftp-create-dirs "${AUTH[@]}" -T "$f" "${BASE}data/"
    done
  fi
  ;;
*)
  echo "MODE muss sftp oder ftp sein."; exit 1;;
esac

echo
echo "Fertig. Jetzt prüfen:"
echo "  ${SITE_URL:-https://<deine-domain>}/api.php?p=health   -> writable/curl/zlib müssen true sein"
echo "  ${SITE_URL:-https://<deine-domain>}/data/readings.json -> muss 403 liefern"
echo
echo "Nicht vergessen: config.php von Hand anlegen (Passwort + API-Schlüssel)"
echo "und für data/ die Schreibrechte auf 0775 setzen."
