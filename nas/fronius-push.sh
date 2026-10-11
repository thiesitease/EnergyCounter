#!/bin/sh
# Zählerbuch – Zählerstände des Fronius-Wechselrichters an die Website schicken.
#
# Läuft auf dem NAS im Haus, einmal pro Stunde über den Aufgabenplaner der Synology.
# Das Skript fragt den Fronius im Heimnetz ab und schickt die Antwort unverändert an
# api.php?p=fronius-push. Ausgewertet wird erst auf der Website.
#
# Ist die Website nicht erreichbar, bleibt die Antwort im Ordner spool/ liegen und
# geht beim nächsten Lauf mit. Alles Liegengebliebene wird in EINER Anfrage
# geschickt, damit der Hoster nicht wegen vieler schneller Zugriffe drosselt.
#
# Benötigt nur sh und curl, beides gehört zum DSM.
#
# Aufruf:  sh fronius-push.sh [konfigurationsdatei]
#          DRY_RUN=1 sh fronius-push.sh   (nur abfragen und zeigen, nichts senden)
#
# Konfiguration: fronius-push.conf neben diesem Skript, Vorlage fronius-push.conf.example.
set -u

DIR=$(cd "$(dirname "$0")" && pwd)
CONF=${1:-$DIR/fronius-push.conf}
[ -f "$CONF" ] || { echo "Konfiguration fehlt: $CONF" >&2; exit 1; }
# shellcheck disable=SC1090
. "$CONF"
: "${FRONIUS:?FRONIUS fehlt in $CONF}" "${SITE_URL:?SITE_URL fehlt in $CONF}" "${PUSH_TOKEN:?PUSH_TOKEN fehlt in $CONF}"
SPOOL=${SPOOL:-$DIR/spool}
MAX_BATCH=${MAX_BATCH:-200}
DRY_RUN=${DRY_RUN:-0}
mkdir -p "$SPOOL" || exit 1

# 1. Fronius abfragen. Nur eine fehlerfreie Antwort mit Zählerständen wird aufgehoben.
neu="$SPOOL/.neu.$$"
if curl -fsS -m 20 -o "$neu" "${FRONIUS%/}/solar_api/v1/GetMeterRealtimeData.cgi?Scope=System" \
    && grep -q '"Code" *: *0' "$neu" && grep -q 'EnergyReal_WAC_Sum_Consumed' "$neu"; then
  mv "$neu" "$SPOOL/$(date +%s).json"
else
  echo "Fronius nicht erreichbar oder Fehlerantwort: ${FRONIUS}" >&2
  rm -f "$neu"
fi

# 2. Alles Liegengebliebene senden, älteste zuerst, höchstens MAX_BATCH auf einmal.
#    Die Dateinamen sind Sekunden seit 1970 und sortieren sich deshalb richtig.
dateien=$(ls "$SPOOL" 2>/dev/null | grep '^[0-9]*\.json$' | sort | head -n "$MAX_BATCH")
[ -n "$dateien" ] || exit 0
anzahl=$(echo "$dateien" | wc -l | tr -d ' ')

paket="$SPOOL/.paket.$$"
{
  printf '['
  trenner=''
  for f in $dateien; do
    printf '%s' "$trenner"
    cat "$SPOOL/$f"
    trenner=','
  done
  printf ']'
} > "$paket"

if [ "$DRY_RUN" = 1 ]; then
  echo "DRY_RUN: $anzahl Antwort(en), $(wc -c < "$paket" | tr -d ' ') Bytes, würde an ${SITE_URL%/}/api.php?p=fronius-push gehen."
  grep -o '"EnergyReal_WAC_Sum_[A-Za-z]*" *: *[0-9.]*' "$paket"
  rm -f "$paket"
  exit 0
fi

antwort="$SPOOL/.antwort"
code=$(curl -sS -m 50 -o "$antwort" -w '%{http_code}' -X POST \
  -H 'Content-Type: application/json' -H "X-Push-Token: $PUSH_TOKEN" \
  --data-binary "@$paket" "${SITE_URL%/}/api.php?p=fronius-push")
rm -f "$paket"

if [ "$code" = 200 ]; then
  for f in $dateien; do rm -f "$SPOOL/$f"; done
  echo "$(date '+%Y-%m-%d %H:%M') gesendet: $anzahl, Antwort: $(head -c 300 "$antwort")"
  exit 0
fi

echo "$(date '+%Y-%m-%d %H:%M') Website antwortet mit ${code:-keiner Verbindung}, $anzahl Antwort(en) bleiben liegen: $(head -c 300 "$antwort" 2>/dev/null)" >&2
# 400 heißt: Der Inhalt ist kaputt und wird auch später nicht besser. Beiseitelegen,
# damit er die Warteschlange nicht dauerhaft blockiert.
if [ "$code" = 400 ]; then
  mkdir -p "$SPOOL/abgelehnt"
  for f in $dateien; do mv "$SPOOL/$f" "$SPOOL/abgelehnt/"; done
fi
exit 1
