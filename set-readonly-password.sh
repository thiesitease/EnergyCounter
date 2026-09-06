#!/usr/bin/env bash
# Ergänzt in der bestehenden config.php nur das Lesepasswort und lädt sie hoch.
# Alle anderen Einträge (Bearbeiten-Passwort, API-Schlüssel, Modell) bleiben unverändert.
#
# Das Passwort wird hier eingegeben, nicht angezeigt und nirgends protokolliert.
#
# Aufruf:  bash set-readonly-password.sh
set -euo pipefail

cd "$(dirname "$0")"
[ -f deploy.env ] || { echo "deploy.env fehlt."; exit 1; }
[ -f php/config.php ] || { echo "php/config.php fehlt. Dann bitte set-config.sh benutzen."; exit 1; }
# shellcheck disable=SC1091
source ./deploy.env
SITE_URL=${SITE_URL:-https://<deine-domain>}

echo "Lesepasswort für ${REMOTE_DIR}/config.php auf ${HOST}"
echo "Es erlaubt alle Auswertungen, Fotos und den Excel-Export, aber keine Änderungen."
echo "Leer lassen und Enter drücken entfernt einen bestehenden Lesezugang."
echo

read -r -s -p "Lesepasswort: " RO_PW; echo
if [ -n "$RO_PW" ]; then
  read -r -s -p "Wiederholen:  " RO_PW2; echo
  [ "$RO_PW" = "$RO_PW2" ] || { echo "Die Passwörter sind nicht gleich."; exit 1; }
  [ ${#RO_PW} -ge 8 ] || { echo "Bitte mindestens 8 Zeichen."; exit 1; }
fi

RO_PW="$RO_PW" python - <<'PY'
import io, os, re, sys
pw = os.environ.get("RO_PW", "")
esc = pw.replace("\\", "\\\\").replace("'", "\\'")
p = "php/config.php"
s = io.open(p, encoding="utf-8").read()

if pw and pw == re.search(r"'password'\s*=>\s*'((?:[^'\\]|\\.)*)'", s).group(1):
    sys.exit("Das Lesepasswort muss sich vom Bearbeiten-Passwort unterscheiden.")

zeile = "    'password_readonly' => '%s'," % esc
if re.search(r"'password_readonly'\s*=>", s):
    s = re.sub(r"[ \t]*'password_readonly'\s*=>\s*'(?:[^'\\]|\\.)*'\s*,", zeile, s, count=1)
else:
    s = re.sub(r"([ \t]*'password'\s*=>\s*'(?:[^'\\]|\\.)*'\s*,)", r"\1\n" + zeile, s, count=1)

if "'password_readonly'" not in s:
    sys.exit("In config.php wurde kein Eintrag 'password' gefunden – bitte set-config.sh benutzen.")
io.open(p, "w", encoding="utf-8", newline="\n").write(s)
print("config.php ergänzt" if pw else "Lesezugang entfernt")
PY

case "${MODE}" in
sftp)
  KEY_OPT=()
  [ -n "${SSH_KEY:-}" ] && KEY_OPT=(-i "${SSH_KEY}")
  printf 'cd %s\nput php/config.php config.php\nchmod 640 config.php\nbye\n' "${REMOTE_DIR}" \
    | sftp "${KEY_OPT[@]}" -b - "${USER}@${HOST}" >/dev/null
  ;;
ftp)
  curl -sS --user "${USER}:${FTP_PASSWORD:-}" -T php/config.php "ftp://${HOST}${REMOTE_DIR%/}/config.php"
  ;;
*)
  echo "MODE muss sftp oder ftp sein."; exit 1;;
esac

unset RO_PW RO_PW2
echo
echo "Hochgeladen. Kontrolle:"
echo "  ${SITE_URL:-https://<deine-domain>}/api.php?p=health"
echo "  readonly_pw muss true sein."
