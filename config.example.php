<?php
/**
 * quickinfo – Beispielkonfiguration.
 *
 * install.sh erzeugt automatisch /etc/quickinfo/config.php mit zufälligen Zugangsdaten.
 * Für eine manuelle Installation diese Datei nach /etc/quickinfo/config.php
 * (oder in das Projekt-Root als config.php) kopieren und anpassen.
 */
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'quickinfo',
        'user'     => 'quickinfo',
        'password' => 'CHANGE_ME',
        'socket'   => null, // z.B. '/var/run/mysqld/mysqld.sock'
    ],

    // Datenhaltung
    'retention' => [
        'raw_days'    => 4,   // Rohdaten (1-Minuten-Auflösung)
        'agg_days'    => 30,  // Verdichtete Daten (10-Minuten-Buckets)
        'log_days'    => 30,  // Dienst-Statusverlauf
    ],

    // Collector
    'collector' => [
        'root_fs'         => '/',        // überwachtes Dateisystem
        'cpu_sample_ms'   => 1000,       // Messintervall für CPU-Auslastung
        'nvidia_smi'      => 'nvidia-smi',
        'sensors'         => 'sensors',
    ],

    // Login-Schutz
    'auth' => [
        'max_attempts'     => 5,     // Fehlversuche pro IP …
        'lockout_seconds'  => 900,   // … danach Sperre für 15 Minuten
        'session_lifetime' => 43200, // 12 Stunden
        'session_name'     => 'quickinfo_sid',
    ],
];
