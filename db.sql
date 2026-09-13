-- quickinfo – Datenbankschema
-- Zeitreihen werden als (metric, ts, value) gespeichert. ts ist ein Unix-Timestamp (Sekunden),
-- auf volle Minuten gerundet. Der Collector schreibt jede Minute, die Wartung verdichtet
-- alte Rohdaten in 10-Minuten-Buckets (metrics_agg) und löscht Rohdaten nach der Retention.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS metrics (
    metric  VARCHAR(48)  NOT NULL,
    ts      INT UNSIGNED NOT NULL,
    value   DOUBLE       NOT NULL,
    PRIMARY KEY (metric, ts),
    KEY idx_metrics_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS metrics_agg (
    metric      VARCHAR(48)  NOT NULL,
    ts          INT UNSIGNED NOT NULL,        -- Bucket-Start (10 Minuten)
    avg_value   DOUBLE       NOT NULL,
    min_value   DOUBLE       NOT NULL,
    max_value   DOUBLE       NOT NULL,
    samples     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (metric, ts),
    KEY idx_metrics_agg_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS snapshot (
    k   VARCHAR(64)  NOT NULL,
    v   MEDIUMTEXT   NOT NULL,                -- JSON
    ts  INT UNSIGNED NOT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS services (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(128) NOT NULL,      -- systemd Unit-Name ohne ".service"
    display_name  VARCHAR(128) NOT NULL,
    sort_order    INT          NOT NULL DEFAULT 0,
    last_state    VARCHAR(64)  NULL,
    last_active   TINYINT(1)   NULL,
    last_check    INT UNSIGNED NULL,
    created_at    INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_log (
    service_id  INT UNSIGNED NOT NULL,
    ts          INT UNSIGNED NOT NULL,
    active      TINYINT(1)   NOT NULL,
    PRIMARY KEY (service_id, ts),
    KEY idx_service_log_ts (ts),
    CONSTRAINT fk_service_log_service FOREIGN KEY (service_id)
        REFERENCES services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username       VARCHAR(64)  NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    created_at     INT UNSIGNED NOT NULL,
    last_login     INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip            VARCHAR(45)  NOT NULL,
    attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt  INT UNSIGNED NOT NULL,
    PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meta (
    k  VARCHAR(64)  NOT NULL,
    v  VARCHAR(255) NOT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- API-Schlüssel für das Management-Board (/api/v1/*).
-- Es wird ausschließlich der SHA-256-Hash gespeichert; der Klartext ist nur einmalig
-- direkt nach der Erzeugung sichtbar. key_prefix dient der Wiedererkennung in der Oberfläche.
CREATE TABLE IF NOT EXISTS api_keys (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label         VARCHAR(64)  NOT NULL DEFAULT 'management-board',
    key_prefix    VARCHAR(16)  NOT NULL,
    key_hash      CHAR(64)     NOT NULL,      -- SHA-256 (hex) des Klartext-Schlüssels
    created_at    INT UNSIGNED NOT NULL,
    created_by    VARCHAR(64)  NULL,          -- Benutzername oder 'install.sh'
    last_used_at  INT UNSIGNED NULL,
    last_used_ip  VARCHAR(45)  NULL,
    use_count     INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Standard-Dienste (weitere werden von install.sh bzw. über das Web-Frontend ergänzt)
INSERT IGNORE INTO services (name, display_name, sort_order, created_at) VALUES
    ('ssh',   'SSH',   10, UNIX_TIMESTAMP()),
    ('nginx', 'Nginx', 20, UNIX_TIMESTAMP()),
    ('cron',  'Cron',  40, UNIX_TIMESTAMP());
