<?php
/**
 * Zählerbuch SB85 – Konfiguration.
 *
 * Diese Datei nach  config.php  kopieren und ausfüllen. config.php wird per
 * .htaccess vor direktem Aufruf geschützt und gehört NICHT in ein öffentliches
 * Repository.
 */
return [

    // Passwort zum Bearbeiten. Pflicht – ohne Passwort bleibt die API gesperrt.
    'password' => '',

    // Zweites Passwort, das nur zum Ansehen berechtigt: alle Auswertungen, Fotos und
    // der Excel-Export sind damit erreichbar, Erfassen und Einstellungen sind gesperrt.
    // Leer lassen, wenn es keinen Lesezugang geben soll.
    'password_readonly' => '',

    // API-Schlüssel von https://console.anthropic.com für die Foto-Erkennung.
    // Leer lassen, wenn die Zählerstände von Hand eingetippt werden sollen.
    'api_key' => '',

    // Modell für das Ablesen der Fotos.
    'model' => 'claude-opus-5',

    // Wie gründlich das Modell nachdenkt: low | medium | high | xhigh | max.
    // "low" ist für das reine Ablesen von Ziffern ausreichend und am schnellsten –
    // wichtig, weil PHP auf Webhosting-Paketen ein Zeitlimit pro Aufruf hat.
    'effort' => 'low',

    // Datenverzeichnis. Standard ist der Unterordner data/ neben dieser Datei.
    // Sicherer ist ein Ordner ausserhalb des Webverzeichnisses, z. B.:
    // 'data_dir' => dirname(__DIR__) . '/zb-daten',
    'data_dir' => __DIR__ . '/data',

];
