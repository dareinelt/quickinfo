# quickinfo

Schlanke, vollständig offline-fähige Web-Überwachung für Ubuntu Bare-Metal-Server.
Natives PHP + MySQL/MariaDB im Backend, Vanilla JS / HTML5 / CSS3 im Frontend –
**keine Frameworks, keine CDNs, keine externen Schriftarten oder Bibliotheken.**

## Funktionen

- **CPU**: Auslastung gesamt & pro Kern, Temperatur pro Kern (hwmon / lm-sensors / thermal_zone)
- **NVIDIA GPU**: Auslastung, Temperatur, VRAM, Leistungsaufnahme (`nvidia-smi`)
- **Speicherplatz** des Root-Dateisystems, Arbeitsspeicher, Load & Uptime
- **systemd-Dienste**: Status-Check konfigurierbarer Units, Verfügbarkeit der letzten 24 h,
  Verwaltung über das Web-Frontend (nach Login)
- **Verlaufsgraphen** für 1h · 3h · 24h · 3d · 14d – nativ auf HTML5 Canvas, Retina-scharf
  (`devicePixelRatio`), mit Tooltip, Fadenkreuz und ein-/ausblendbaren Serien
- **Modernes Dark-Theme** mit System-Schriftarten
- **Sicherer Login**: Session-basiert, `password_hash()`, CSRF-Token, Login-Throttling,
  HttpOnly/Secure/SameSite-Cookies, strikte Content-Security-Policy
- **Time-Series-Speicherung** mit automatischem Downsampling (10-Minuten-Buckets) und Cleanup
- **VM-Erkennung**: Läuft quickinfo in einer virtuellen Umgebung (VM/Container), werden die
  Temperatur-Sensoren sowie die zugehörige Anzeige und der Verlaufsgraph automatisch deaktiviert
- **REST-API für ein Management-Board** (`/api/v1/…`): schreibgeschützte JSON-Endpunkte mit
  Bearer-Token-Authentifizierung, konfigurierbarem CORS und Schlüsselverwaltung im Admin-Panel

## Installation (One-Liner)

Auf einem frischen Ubuntu-Server (20.04 mit PHP ≥ 8.1 Backport, empfohlen 22.04 / 24.04):

```bash
git clone https://github.com/dareinelt/quickinfo quickinfo && cd quickinfo && sudo bash install.sh
```

`install.sh` erledigt:

1. `apt`-Installation von `nginx`, `php-fpm`, `php-mysql`, `mysql-server` (Fallback MariaDB),
   `lm-sensors` (inkl. `sensors-detect --auto`) und `sysstat`
2. Self-Signed-Zertifikat (10 Jahre, SAN mit Hostname & IP), Nginx mit HTTP→HTTPS-Redirect
   und PHP-FPM-Anbindung
3. Datenbank, DB-Benutzer, Schema (`db.sql`) und Admin-Benutzer mit zufälligem Passwort
   (wird am Ende ausgegeben)
4. Collector als systemd-Timer (minütlich), Dateirechte für `/var/www/html/quickinfo`
5. Erster API-Schlüssel für das Management-Board (wird am Ende einmalig ausgegeben; bei
   erneutem Lauf bleibt der vorhandene Schlüssel erhalten, `QI_ROTATE_API_KEY=1` erzwingt Rotation)

Danach: `https://<server-ip>` aufrufen, Zertifikatswarnung bestätigen, mit `admin` anmelden.

Umgebungsvariablen zur Anpassung: `QI_DB_NAME`, `QI_DB_USER`, `QI_ADMIN_USER`,
`QI_HTTP_PORT`, `QI_HTTPS_PORT`, `QI_CORS_ORIGINS` (Komma-getrennt, Standard `*`),
`QI_ROTATE_API_KEY`.

## Projektstruktur

```
install.sh              Setup-Skript (idempotent, erneuter Lauf = Update + neues Admin-Passwort)
db.sql                  Datenbankschema
config.example.php      Beispielkonfiguration (→ /etc/quickinfo/config.php)
collector/collector.php Datenerfassung (PHP-CLI, systemd-Timer)
bin/apikey.php          API-Schlüssel per CLI verwalten (status | ensure | rotate | revoke)
src/bootstrap.php       Konfiguration, PDO, Hilfsfunktionen
src/auth.php            Session-Login, CSRF, Throttling
src/apikey.php          API-Schlüssel: Erzeugung, Hashing, Prüfung, Throttling, CORS
src/api.php             REST-Endpunkte (Web-Frontend, Session-basiert)
src/api_v1.php          Öffentliche Read-Only-API /api/v1/* (Bearer-Token)
public/index.html       Single-Page-Frontend
public/assets/          style.css · chart.js (Canvas-Graphen) · app.js
public/api/index.php    API-Einstiegspunkt (einzige PHP-Datei im Webroot)
```

## REST-API

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/session` | Login-Status + CSRF-Token + Systeminfo (Hostname, Kurzbeschreibung, Inventarnummer) |
| POST | `/api/login` | `{username, password}` |
| POST | `/api/logout` | |
| GET | `/api/overview` | Aktueller Snapshot + Dienststatus |
| GET | `/api/history?range=1h\|3h\|24h\|3d\|14d` | Zeitreihen `{metric: [[ts, value], …]}` |
| GET/POST | `/api/services` | Dienste auflisten / hinzufügen |
| PUT/DELETE | `/api/services/{id}` | Anzeigename ändern / entfernen |
| GET | `/api/services/available` | systemd-Units auf dem System |
| POST | `/api/password` | `{current, new}` |
| POST | `/api/system` | `{description, inventory}` – Kurzbeschreibung / Inventarnummer speichern |
| GET | `/api/apikey` | Metadaten des API-Schlüssels, Pairing-Infos |
| POST | `/api/apikey/rotate` | Neuen API-Schlüssel erzeugen (Klartext einmalig in der Antwort) |
| DELETE | `/api/apikey` | API-Schlüssel widerrufen |

Alle Endpunkte außer `session` und `login` erfordern eine Sitzung; schreibende Anfragen
zusätzlich den Header `X-CSRF-Token`.

## Management-Board-API (`/api/v1`)

Schreibgeschützte JSON-Schnittstelle, über die ein übergeordnetes Management-Board mehrere
quickinfo-Instanzen einsammeln kann. Der API-Schlüssel wird im Admin-Panel unter
*Einstellungen → API & Management-Board* erzeugt, eingesehen (Kennung, letzte Nutzung)
und rotiert bzw. widerrufen. Alternativ per CLI: `php /var/www/html/quickinfo/bin/apikey.php rotate`.

**Authentifizierung:** `Authorization: Bearer <API-KEY>` (alternativ `X-API-Key: <API-KEY>`).
Fehlende oder ungültige Schlüssel → `401 Unauthorized`; nach 10 Fehlversuchen pro IP → `429`
für 5 Minuten (konfigurierbar). Der Schlüssel wird ausschließlich als SHA-256-Hash in der
Tabelle `api_keys` gespeichert.

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/v1/` | Endpunktübersicht |
| GET | `/api/v1/status` | Aktuelle Messwerte: CPU (Auslastung, Kerne, Temperatur), GPU(s), RAM, Speicherplatz `/`, Load, Status aller Dienste |
| GET | `/api/v1/history?range=1h\|3h\|24h\|3d\|14d[&metrics=cpu.total,temp.max]` | Aggregierte Zeitreihen `{metric: [[ts, value], …]}` |
| GET | `/api/v1/info` | Hostname, Uptime, Systemzeit, OS/Kernel, CPU-Kerne & -Modell, GPU-Modell |

```bash
curl -k -H "Authorization: Bearer qi_…" https://<server-ip>/api/v1/status
```

**CORS:** Standardmäßig sind alle Origins erlaubt (`'cors_origins' => ['*']`), da der Zugriff
ohnehin nur mit gültigem Schlüssel möglich ist. Für eine Einschränkung auf das Management-Board
in `/etc/quickinfo/config.php` konkrete Origins hinterlegen, z. B.
`['https://board.example.com']`; `[]` deaktiviert CORS vollständig. Preflight-Anfragen
(`OPTIONS`) werden ohne Authentifizierung mit `204` beantwortet.

**Pairing:** Im Management-Board die Server-URL (`https://<ip>`) und den API-Schlüssel
hinterlegen. Nach einer Rotation wird der alte Schlüssel sofort ungültig und das Board muss
mit dem neuen Schlüssel neu gekoppelt werden.

## Datenhaltung

- `metrics`: Rohdaten in Minutenauflösung, Retention 4 Tage
- `metrics_agg`: 10-Minuten-Buckets (avg/min/max), Retention 30 Tage
- `service_log`: Dienststatus pro Minute, Retention 30 Tage

Die Wartung läuft automatisch einmal pro Stunde im Collector. Manuell:

```bash
sudo php /var/www/html/quickinfo/collector/collector.php --verbose --maintenance
```

## Betrieb

```bash
systemctl status quickinfo-collector.timer
journalctl -u quickinfo-collector.service -n 50
tail -f /var/log/nginx/quickinfo.error.log
php /var/www/html/quickinfo/bin/apikey.php status   # API-Schlüssel prüfen
```
