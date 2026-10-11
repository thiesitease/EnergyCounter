#!/usr/bin/env python3
"""Zählerbuch – Detaildaten des Fronius alle 30 Sekunden auf dem NAS ablegen.

Läuft jede Minute über den Aufgabenplaner der Synology und nimmt dabei zwei Messungen:
sofort und 30 Sekunden später. Jede Messung fragt drei Schnittstellen der SolarAPI ab
und schreibt eine Zeile in eine CSV-Datei pro Tag:

    detail/2026/10/2026-10-11.csv

Nichts davon geht an die Website. Die stündlichen Zählerstände für das Zählerbuch
schickt weiterhin fronius-push.sh.

Nur Standardbibliothek, lauffähig ab Python 3.7 (DSM 7).

Aufruf:  python3 fronius-detail.py [konfigurationsdatei]
         python3 fronius-detail.py --einmal   (eine Messung, auf dem Bildschirm, nichts schreiben)

Die Konfiguration ist dieselbe wie für fronius-push.sh (fronius-push.conf). Gebraucht wird
FRONIUS, optional DETAIL_DIR (Standard: Ordner detail neben dem Skript).
"""

import csv
import fcntl
import json
import os
import sys
import time
import urllib.request
from datetime import datetime

PROBEN = 2       # Messungen je Lauf
ABSTAND = 30     # Sekunden zwischen den Messungen
TIMEOUT = 8      # Sekunden je Abfrage

SPALTEN = [
    'zeit', 'unix',
    'netz_w', 'pv_w', 'haus_w',
    'bezug_wh', 'einspeisung_wh', 'pv_gesamt_wh',
    'l1_w', 'l2_w', 'l3_w', 'l1_v', 'l2_v', 'l3_v', 'l1_a', 'l2_a', 'l3_a',
    'blind_var', 'frequenz_hz',
    'wr_ac_w', 'pv1_v', 'pv1_a', 'pv2_v', 'pv2_a', 'wr_status',
]


def lies_konfiguration(pfad):
    """KEY=WERT wie in der sh-Datei, Kommentare und Anführungszeichen werden entfernt."""
    werte = {}
    with open(pfad, encoding='utf-8') as f:
        for zeile in f:
            zeile = zeile.strip()
            if not zeile or zeile.startswith('#') or '=' not in zeile:
                continue
            k, v = zeile.split('=', 1)
            werte[k.strip()] = v.split(' #')[0].strip().strip('"').strip("'")
    return werte


def hole(basis, pfad):
    with urllib.request.urlopen(basis + '/solar_api/v1/' + pfad, timeout=TIMEOUT) as r:
        daten = json.loads(r.read().decode('utf-8'))
    if daten.get('Head', {}).get('Status', {}).get('Code', 0) != 0:
        raise RuntimeError('Fronius meldet Fehler bei ' + pfad)
    return daten.get('Body', {}).get('Data', {})


def runde(wert, stellen):
    if wert is None:
        return ''
    try:
        return int(round(float(wert))) if stellen == 0 else round(float(wert), stellen)
    except (TypeError, ValueError):
        return ''


def netzzaehler(daten):
    """Bei Scope=System eine Liste nach Index; gesucht ist der Zähler am Netzanschluss."""
    gefunden = None
    for z in daten.values():
        if isinstance(z, dict) and (gefunden is None or z.get('Meter_Location_Current') == 0):
            gefunden = z
    return gefunden or {}


def messe(basis):
    """Eine Messung. Fällt eine Schnittstelle aus, bleiben ihre Spalten leer."""
    jetzt = time.time()
    zeile = {'zeit': datetime.fromtimestamp(jetzt).strftime('%Y-%m-%dT%H:%M:%S'),
             'unix': int(jetzt)}
    fehler = []
    try:
        site = hole(basis, 'GetPowerFlowRealtimeData.fcgi').get('Site', {})
        last = site.get('P_Load')
        zeile.update({
            'netz_w': runde(site.get('P_Grid'), 1),
            'pv_w': runde(site.get('P_PV') or 0, 1),
            'haus_w': runde(-last if last is not None else None, 1),
            'pv_gesamt_wh': runde(site.get('E_Total'), 1),
        })
    except Exception as e:  # noqa: BLE001 – jede Störung nur melden, nicht abbrechen
        fehler.append('Leistungsfluss: %s' % e)
    try:
        z = netzzaehler(hole(basis, 'GetMeterRealtimeData.cgi?Scope=System'))
        zeile.update({
            'bezug_wh': runde(z.get('EnergyReal_WAC_Sum_Consumed'), 0),
            'einspeisung_wh': runde(z.get('EnergyReal_WAC_Sum_Produced'), 0),
            'l1_w': runde(z.get('PowerReal_P_Phase_1'), 1),
            'l2_w': runde(z.get('PowerReal_P_Phase_2'), 1),
            'l3_w': runde(z.get('PowerReal_P_Phase_3'), 1),
            'l1_v': runde(z.get('Voltage_AC_Phase_1'), 1),
            'l2_v': runde(z.get('Voltage_AC_Phase_2'), 1),
            'l3_v': runde(z.get('Voltage_AC_Phase_3'), 1),
            'l1_a': runde(z.get('Current_AC_Phase_1'), 3),
            'l2_a': runde(z.get('Current_AC_Phase_2'), 3),
            'l3_a': runde(z.get('Current_AC_Phase_3'), 3),
            'blind_var': runde(z.get('PowerReactive_Q_Sum'), 1),
            'frequenz_hz': runde(z.get('Frequency_Phase_Average'), 2),
        })
    except Exception as e:  # noqa: BLE001
        fehler.append('Zähler: %s' % e)
    try:
        w = hole(basis, 'GetInverterRealtimeData.cgi?Scope=Device&DeviceId=1&DataCollection=CommonInverterData')

        def wert(k):
            return (w.get(k) or {}).get('Value')

        zeile.update({
            'wr_ac_w': runde(wert('PAC') or 0, 1),
            'pv1_v': runde(wert('UDC'), 1), 'pv1_a': runde(wert('IDC'), 3),
            'pv2_v': runde(wert('UDC_2'), 1), 'pv2_a': runde(wert('IDC_2'), 3),
            'wr_status': (w.get('DeviceStatus') or {}).get('InverterState', ''),
        })
    except Exception:  # noqa: BLE001
        # Nachts geht der Wechselrichter schlafen und antwortet hier womöglich nicht.
        # Das ist normal, die Spalten bleiben dann leer.
        pass
    return zeile, fehler


def schreibe(verzeichnis, zeile):
    tag = zeile['zeit'][:10]
    ordner = os.path.join(verzeichnis, tag[:4], tag[5:7])
    os.makedirs(ordner, exist_ok=True)
    datei = os.path.join(ordner, tag + '.csv')
    neu = not os.path.exists(datei)
    with open(datei, 'a', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=SPALTEN, extrasaction='ignore')
        if neu:
            w.writeheader()
        w.writerow(zeile)


def main():
    argumente = [a for a in sys.argv[1:] if not a.startswith('--')]
    einmal = '--einmal' in sys.argv
    hier = os.path.dirname(os.path.abspath(__file__))
    conf = lies_konfiguration(argumente[0] if argumente else os.path.join(hier, 'fronius-push.conf'))
    basis = conf.get('FRONIUS', '').rstrip('/')
    if not basis:
        sys.exit('FRONIUS fehlt in der Konfiguration')
    verzeichnis = conf.get('DETAIL_DIR') or os.path.join(hier, 'detail')

    if einmal:
        zeile, fehler = messe(basis)
        for k in SPALTEN:
            print('%-15s %s' % (k, zeile.get(k, '')))
        for f in fehler:
            print('Fehler:', f, file=sys.stderr)
        return

    # Hängt ein Lauf, startet der nächste nicht darüber hinweg und verdoppelt Zeilen.
    os.makedirs(verzeichnis, exist_ok=True)
    sperre = open(os.path.join(verzeichnis, '.sperre'), 'w')
    try:
        fcntl.flock(sperre, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        print('Vorheriger Lauf ist noch nicht fertig, dieser fällt aus.', file=sys.stderr)
        return

    start = time.time()
    for i in range(PROBEN):
        ziel = start + i * ABSTAND
        warten = ziel - time.time()
        if warten > 0:
            time.sleep(warten)
        zeile, fehler = messe(basis)
        if len(zeile) > 2:
            schreibe(verzeichnis, zeile)
        for f in fehler:
            print(zeile['zeit'], f, file=sys.stderr)


if __name__ == '__main__':
    main()
