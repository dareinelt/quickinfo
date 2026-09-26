# agentsindex.md – Übersicht für Coding-Agenten

Dieses Dokument ist der Einstiegspunkt für Coding-Agenten und neue Entwickler. Es fasst
Architektur, Routing, Datenbankschema, Konfiguration, Sicherheitsmodell und die wichtigsten
Workflows des Projekts **quickinfo** zusammen. Für Nutzer- und Betriebsdokumentation siehe
[`README.md`](README.md).

---

## 1. Was ist das Projekt?

**quickinfo** ist ein schlanker Server-Monitoring-Agent („Server-Quickinfo“) für einzelne
Linux-Hosts. Er sammelt minütlich CPU-Auslastung, Temperaturen, GPU-Auslastung, Speicherplatz
und den Status systemd-Dienste und stellt sie über ein webbasiertes Dashboard sowie eine
token-gesicherte JSON-API bereit. Zusätzlich kann ein Host als **Docker-Host** konfiguriert
werden; dann lassen sich Container auflisten, steuern und inspizieren (Auslastung, Volumes,
Netzwerke, Logs, Notizen) – per SSH auf dem Ziel-Host ausgeführt.

Kein PHP-Framework, keine SPA-Frameworks: bewusst einfach gehalten (PHP + MySQL, Vanilla JS).

---

## 2. Technologie-Stack

- **Backend**: PHP 8.x (CLI + FPM), `declare(strict_types=1)`, PDO/MySQL
- **Datenbank**: MySQL/MariaDB (`db.sql`)
- **Frontend**: Vanilla JS (ES2020+), ein HTML-Dokument (`public/index.html`), CSS in `public/assets/style.css`
- **Server**: Nginx (Reverse-Proxy + statische Auslieferung), PHP-FPM
- **Scheduler**: systemd-Timer (`collector.service` / `collector.timer`), minütlich
- **Docker-Anbindung**: OpenSSH-Client + `sshpass` (Passwort-Auth), `docker` CLI remote
- **Verschlüsselung**: OpenSSL AES-256-GCM für SSH-Zugangsdaten

---

## 3. Schnellstart (Build, Start, Test)

```bash
# Installation (interaktiv): legt DB, Benutzer, systemd-Dienste und config.php an
sudo bash install.sh

# Systemdienste
sudo systemctl status quickinfo-collector.timer quickinfo-collector.service
```

```bash
# Syntaxprüfung aller PHP-Dateien
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

# Syntaxprüfung des Frontends
node --check public/assets/app.js
```

Es gibt keine Unit-Tests und keinen Build-Schritt. Verifikation erfolgt über `php -l`,
`node --check` und manuelle Prüfung gegen eine laufende Instanz.

---

## 4. Architektur im Überblick

```
┌──────────────┐  GET /api/*   ┌──────────────────────┐
│  Nginx        │ ────────────▶ │ public/api/index.php │
│  (PHP-FPM)    │               │  qi_api_dispatch()    │
└──────────────┘               └──────────┬───────────┘
      │ statisch                           │
      ▼                                    ▼
 public/index.html          ┌────────────────────────────┐
 public/assets/app.js       │ src/api.php (Web-API)       │
 public/assets/style.css    │ src/api_v1.php (ext. API)   │
                            │ src/docker.php (Docker)     │
                            │ src/auth.php / src/apikey.php│
                            └──────────┬─────────────────┘
                                       │ PDO
                                       ▼
                                  MySQL (quickinfo)

┌─────────────────────┐  schreibt  ┌─────────────────────┐
│ collector/collector.php│ ───────▶ │ metrics, metrics_agg │
│ (systemd-Timer)       │           │ snapshot, service_log│
└─────────────────────┘           └─────────────────────┘
```

**Kernprinzipien:**

- Ein einziger API-Einstiegspunkt: `public/api/index.php` → `qi_api_dispatch()` in `src/api.php`.
- Alle Ausgaben sind JSON (`qi_json_response()` / `qi_json_error()`, beide `never`).
- Datenbankzugriff ausschließlich über `qi_db()` (Singleton-PDO).
- Der Collector ist ein separates CLI-Skript, das jede Minute läuft.

---

## 5. Verzeichnisstruktur

```
├── collector/
│   └── collector.php      # CLI: minütliche Datenerfassung + Retention
├── public/
│   ├── index.html         # SPA-Einstieg (Dashboard, Docker-UI, Einstellungen)
│   ├── api/
│   │   └── index.php      # API-Einstiegspunkt
│   └── assets/
│       ├── app.js         # gesamte Frontend-Logik
│       └── style.css      # Styles
├── src/
│   ├── bootstrap.php      # Konfiguration, DB, JSON-/HTTP-Helfer, qi_exec
│   ├── auth.php           # Session-Login, CSRF, Throttling
│   ├── apikey.php         # API-Schlüssel (v1): Erzeugung, Hashing, Prüfung, CORS
│   ├── api.php            # Web-API (Session) inkl. Docker-Handler
│   ├── api_v1.php         # externe API (Bearer-Token)
│   └── docker.php         # Docker-Host-Modul (Verschlüsselung, SSH, Container/…)
├── db.sql                 # Schema (alle Tabellen)
├── config.example.php     # Vorlage für /etc/quickinfo/config.php
├── install.sh             # Installation (DB, Benutzer, systemd, config.php)
├── README.md              # Nutzer-/Betriebsdokumentation
└── agentsindex.md         # dieses Dokument
```

---

## 6. Kernschichten

### `src/bootstrap.php` – Basis

| Funktion | Zweck |
|---|---|
| `qi_config()` | Lädt `/etc/quickinfo/config.php` + `config.example.php`, merged mit Defaults |
| `qi_merge_defaults()` | Default-Werte inkl. `docker.encryption_key` |
| `qi_db()` | Singleton-PDO (UTF-8, ERRMODE_EXCEPTION) |
| `qi_meta_get()` / `qi_meta_set()` | Schlüssel-Wert-Metadaten aus Tabelle `meta` |
| `qi_json_response()` / `qi_json_error()` | JSON-Ausgabe, terminiert (`never`) |
| `qi_request_json()` | JSON-Body einer Anfrage lesen |
| `qi_client_ip()` | Client-IP (Proxy-fähig) |
| `qi_exec()` | Prozess ausführen, liefert nur stdout (String/`null`) |
| `qi_command_exists()` | Prüft, ob ein Binary verfügbar ist |
| `qi_valid_service_name()` / `qi_normalize_service_name()` | systemd-Dienstnamen validieren/normalisieren |
| `qi_is_virtual_machine()` | VM-Erkennung |

### `src/auth.php` – Session & CSRF

`qi_session_start()`, `qi_current_user()`, `qi_require_auth()`, `qi_csrf_token()`,
`qi_require_csrf()`, `qi_login()`, `qi_logout()`, `qi_change_password()` sowie
Login-Throttling (`qi_login_locked`, `qi_login_record_failure`, `qi_login_clear`).

### `src/apikey.php` – externe API (v1)

`qi_api_key_generate/hash/display_prefix`, `qi_api_key_info/rotate/revoke/verify`,
`qi_api_key_from_request`, Throttling (`qi_api_throttle_key`, `qi_api_locked`,
`qi_api_record_failure`), `qi_api_require_key()`, `qi_api_cors_headers()`.

### `src/docker.php` – Docker-Host-Modul

| Funktion | Zweck |
|---|---|
| `qi_docker_*_encrypt/decrypt` | AES-256-GCM Ver-/Entschlüsselung |
| `qi_docker_row()` | Konfigurationszeile (Singleton id=1) lesen |
| `qi_docker_config_public()` | Öffentliche Konfiguration (ohne Secrets) |
| `qi_docker_credentials()` | Entschlüsselte Zugangsdaten |
| `qi_docker_run()` | SSH-Kommando bauen (`sshpass -e` optional), via `escapeshellarg` |
| `qi_docker_proc()` | `proc_open` mit Timeout; liefert `['out','err','exit']` |
| `qi_docker_ping()` | Erreichbarkeit (SSH + `docker version`) |
| `qi_docker_save()` | Einstellungen validieren/speichern + Verbindungstest |
| `qi_docker_containers()` | `docker ps -a` (JSON-Format) |
| `qi_docker_inspect()` | Detail (Mounts, Netzwerke, Ports, Labels) |
| `qi_docker_stats()` | `docker stats --no-stream` (CPU, RAM, Netz, Block-I/O, PIDs) |
| `qi_docker_action()` | start/stop/restart |
| `qi_docker_logs()` | `docker logs --tail N --timestamps` |
| `qi_docker_volumes()` / `qi_docker_networks()` | Volumes/Netzwerke auflisten |
| `qi_docker_note_get()` / `qi_docker_note_row()` / `qi_docker_note_save()` | Container-Notizen |

---

## 7. Routing-Tabelle

Alle Web-API-Routen (`/api/*`) werden in `src/api.php` → `qi_api_dispatch()` aufgelöst.
Pfad wird zerlegt in `$resource` + `$id` (+ weitere Sub-Pfade). CSRF gilt für alle
nicht-GET/HEAD-Anfragen außer `login`. Session-geschützte Routen rufen `qi_require_auth()`.

### Basis & Session

| Methode | Pfad | Auth | Zweck |
|---|---|---|---|
| GET | `/api/` | – | Name + Version |
| GET | `/api/session` | – | Aktueller Benutzer |
| POST | `/api/login` | – (Throttling) | Anmelden |
| POST | `/api/logout` | Session | Abmelden |
| POST | `/api/password` | Session | Passwort ändern |

### Kennzahlen & Dienste

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/overview` | Aktuelle Kennzahlen + letzter Snapshot |
| PUT | `/api/system` | Serverdaten (z. B. Hostname) aktualisieren |
| GET | `/api/history?range=1h\|6h\|1d\|7d\|30d` | Verlaufsdaten |
| GET | `/api/services` | Dienstliste |
| GET | `/api/services/available` | Verfügbare systemd-Dienste |
| POST | `/api/services` | Dienst anlegen |
| PUT | `/api/services/{id}` | Dienst ändern |
| DELETE | `/api/services/{id}` | Dienst löschen |

### Management-Board-API-Schlüssel

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/apikey` | API-Schlüssel-Info |
| POST | `/api/apikey/rotate` | Neuen Schlüssel erzeugen (Klartext einmalig) |
| DELETE | `/api/apikey` | Schlüssel widerrufen |

### Docker (Session, nur bei aktiviertem Host sinnvoll)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/docker/config` | Öffentliche Konfiguration (mit `has_password`/`has_private_key`) |
| PUT | `/api/docker/config` | Speichern; bei aktivem Host Verbindungstest (`status: ok\|unreachable`) |
| GET | `/api/docker/status` | Erreichbarkeit |
| GET | `/api/docker/containers` | Containerliste |
| GET | `/api/docker/containers/{name}` | Detail inkl. Notiz |
| GET | `/api/docker/containers/{name}/stats` | Live-Auslastung |
| GET | `/api/docker/containers/{name}/logs?lines=N` | Letzte Logs |
| PUT | `/api/docker/containers/{name}/note` | Notiz speichern |
| POST | `/api/docker/containers/{name}/{start\|stop\|restart}` | Steuern |
| GET | `/api/docker/volumes` | Volumes |
| GET | `/api/docker/networks` | Netzwerke |

### Externe API v1 (`/api/v1/*`, Bearer-Token)

Dispatch in `src/api_v1.php` (`qi_api_v1_dispatch()`), Endpunkte in `QI_API_V1_ENDPOINTS`:
`status`, `snapshot`, `history`, `info`. Authentifizierung via `qi_api_require_key()`
(Bearer-Token, eigenes Throttling, CORS-Support).

---

## 8. Datenbankschema (`db.sql`)

| Tabelle | Zweck |
|---|---|
| `metrics` | Rohdaten, Minutenauflösung, Retention 4 Tage |
| `metrics_agg` | 10-Minuten-Buckets (avg/min/max), Retention 30 Tage |
| `snapshot` | Letzter Gesamtzustand des Servers |
| `services` | Überwachte Dienste |
| `service_log` | Dienststatus pro Minute, Retention 30 Tage |
| `users` | Benutzer (Argon2id-Passwort-Hash) |
| `login_attempts` | Login-Throttling |
| `meta` | Schlüssel-Wert-Metadaten |
| `api_keys` | Externe API-Schlüssel (Hash) |
| `docker_host` | Singleton (id=1): Aktivierung + verschlüsselte SSH-Zugangsdaten |
| `docker_container_notes` | Container-Notizen (PK: `container_name`) |

---

## 9. Konfiguration

Laufzeit-Konfiguration in `/etc/quickinfo/config.php` (aus `config.example.php` erzeugt durch
`install.sh`). Zugriff ausschließlich über `qi_config()`; Defaults via `qi_merge_defaults()`.

Relevante Docker-Schlüssel:

```php
'docker' => [
    'encryption_key' => '<von install.sh generiert, mind. 16 Zeichen>',
],
```

- Der Schlüssel wird mit SHA-256 auf 32 Byte abgeleitet und für AES-256-GCM genutzt.
- Leerer Schlüssel → deterministische Fallback-Ableitung aus dem Installationspfad
  (hält vorhandene Daten lesbar, sollte in Produktion ersetzt werden).
- Änderungen an Defaults müssen an **vier Stellen** konsistent bleiben:
  `src/bootstrap.php` (Defaults), `config.example.php`, `install.sh` (generierte config.php),
  und ggf. `src/docker.php` (Key-Ableitung).

---

## 10. Sicherheitsmodell

- **Login**: Session-Cookie (httponly), Argon2id-Hashes, IP-basiertes Throttling
  (`login_attempts`).
- **CSRF**: Token aus `qi_csrf_token()`; `qi_require_csrf()` für alle schreibenden
  Web-API-Routen (Ausnahme `login`).
- **Externe API v1**: Bearer-Token, nur Hash in DB (`api_keys`), eigenes Throttling, CORS.
- **Docker-Secrets**: AES-256-GCM; `docker/config` GET liefert nie Klartext, nur
  `has_password` / `has_private_key`.
- **Prozessausführung**: Shell-Argumente via `escapeshellarg()`; `sshpass`-Passwort nur als
  env `SSHPASS` (`putenv`), wird im `finally` wieder entfernt.
- **HTTP-Header**: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`; zentrale Exception-Handling ohne interne Fehlerdetails.

---

## 11. Wichtige Workflows

### Datenerfassung (Collector)

`collector/collector.php` läuft minütlich (systemd-Timer). Es liest `/proc/stat`,
Temperaturen (`hwmon`/`sensors`/`thermal_zone`), NVIDIA-GPU (`nvidia-smi`), Speicherplatz
(`df`) und systemd-Dienststatus; schreibt in `metrics`/`service_log`/`snapshot`. Einmal pro
Stunde wird verdichtet (`--maintenance`, Rohdaten → `metrics_agg`) und aufgeräumt.
Lock über Temp-Datei verhindert parallele Läufe.

### Docker-Abfrage

`src/docker.php` baut SSH-Kommandos (`ssh … docker …`). `qi_docker_run()` setzt bei
Passwort-Auth `SSHPASS` via `putenv` und ruft `sshpass -e`; `qi_docker_proc()` liest
stdout/stderr non-blockierend mit Timeout und liefert Exit-Code. Frontend ruft
`/api/docker/containers`, bei Auswahl `/api/docker/containers/{name}` (Detail + Notiz),
`/stats` und `/logs` ab; Aktionen via POST `start|stop|restart`.

### Installation

`install.sh` installiert Pakete (inkl. `sshpass`), legt DB/Benutzer an, generiert
`DOCKER_KEY` (wiederverwendbar), schreibt `/etc/quickinfo/config.php` und richtet
systemd-Dienste ein.

---

## 12. Konventionen & Coding-Standards

- PHP: `declare(strict_types=1)`, Funktionen als `qi_`-Prefix, keine Klassen/Composer.
- JSON-Antworten immer über `qi_json_response()`/`qi_json_error()`.
- Frontend: `$`/`$$` sind dokumentweite Helfer (`document.querySelector[All]`).
- Tab-/Panel-Selektoren sind gescoped: Haupt-Tabs `data-maintab`/`data-panel-main`,
  Einstellungs-Tabs `data-tab`/`data-panel` (innerhalb `#modal-settings`).
- Deutsche Bezeichner/Kommentare in UI und Doku.
- Keine „magischen“ Zahlen: Ranges in `QI_RANGES` (`src/api.php`).

---

## 13. Häufige Änderungsaufgaben

- **Neue Metrik aufnehmen**: Collector erweitern → `snapshot`/`metrics` schreiben →
  `/api/overview` ausgeben → Frontend-Renderer ergänzen.
- **Neuen Web-API-Endpunkt**: Route in `qi_api_dispatch()` (Switch) + Handler-Funktion in
  `src/api.php`; CSRF/Auth nicht vergessen.
- **Neuen externen Endpunkt**: `QI_API_V1_ENDPOINTS` + Handler in `src/api_v1.php`.
- **Docker-Feature**: Logik in `src/docker.php`, Routing/Handler in `src/api.php`,
  UI in `public/index.html` + `public/assets/app.js` + `style.css`.
- **Schemaänderung**: `db.sql` anpassen; bei Bestandsinstallationen Migrationsschritt in
  `install.sh` oder manuell dokumentieren.

---

## 14. Referenzdokumente

- [`README.md`](README.md) – Nutzer-, Betriebs- und API-Dokumentation
- `config.example.php` – Konfigurationsvorlage
- `db.sql` – vollständiges Datenbankschema
