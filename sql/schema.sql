-- FVE Monitor — schéma databáze
-- MariaDB 10.6+ / utf8mb4

CREATE DATABASE IF NOT EXISTS fve_monitor
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fve_monitor;

-- ───────────────────────────────────────────────────────────
-- Elektrárny
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plants (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(32)  NOT NULL COMMENT 'Krátký kód, např. ALBERT_BRNO',
    name            VARCHAR(128) NOT NULL,
    provider        ENUM('isolarcloud','solaredge','mock') NOT NULL DEFAULT 'mock',
    provider_ps_id  VARCHAR(64)  NULL COMMENT 'ps_id v iSolarCloud / siteId v SolarEdge',
    latitude        DECIMAL(9,6) NOT NULL,
    longitude       DECIMAL(9,6) NOT NULL,
    peak_power_kwp  DECIMAL(8,2) NOT NULL COMMENT 'Instalovaný výkon v kWp',
    tilt_deg        TINYINT      NOT NULL DEFAULT 35 COMMENT 'Sklon panelů',
    azimuth_deg     SMALLINT     NOT NULL DEFAULT 0  COMMENT '0=jih, -90=východ, 90=západ (PVGIS aspect)',
    system_loss_pct DECIMAL(4,1) NOT NULL DEFAULT 14.0,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────
-- Aktuální výkon (15min vzorky, ~7 denní ring buffer)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS production_realtime (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plant_id    INT UNSIGNED NOT NULL,
    ts          DATETIME     NOT NULL,
    power_kw    DECIMAL(8,3) NOT NULL DEFAULT 0,
    energy_kwh  DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'Kumulativní za den',
    PRIMARY KEY (id),
    UNIQUE KEY uk_plant_ts (plant_id, ts),
    KEY idx_ts (ts),
    CONSTRAINT fk_rt_plant FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────
-- Denní souhrny
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS production_daily (
    plant_id    INT UNSIGNED NOT NULL,
    day         DATE         NOT NULL,
    energy_kwh  DECIMAL(10,3) NOT NULL,
    peak_kw     DECIMAL(8,3)  NOT NULL DEFAULT 0,
    sunshine_h  DECIMAL(4,2)  NULL,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (plant_id, day),
    KEY idx_day (day),
    CONSTRAINT fk_d_plant FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────
-- PVGIS predikce (12 řádků na elektrárnu, refreshované 1× měsíčně)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pvgis_monthly (
    plant_id        INT UNSIGNED NOT NULL,
    month           TINYINT      NOT NULL COMMENT '1-12',
    e_d_kwh         DECIMAL(8,3) NOT NULL COMMENT 'Průměrná denní výroba',
    e_m_kwh         DECIMAL(10,3) NOT NULL COMMENT 'Průměrná měsíční výroba',
    h_i_d           DECIMAL(6,3) NOT NULL COMMENT 'In-plane irradiance kWh/m²/den',
    h_i_m           DECIMAL(8,3) NOT NULL COMMENT 'In-plane irradiance kWh/m²/měs',
    sd_m_kwh        DECIMAL(8,3) NULL COMMENT 'Std. odchylka year-to-year',
    fetched_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (plant_id, month),
    CONSTRAINT fk_pv_plant FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────
-- Alerty
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alerts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plant_id    INT UNSIGNED NOT NULL,
    type        ENUM('underperform','offline','communication','manual') NOT NULL,
    severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    message     VARCHAR(500) NOT NULL,
    metric      JSON         NULL COMMENT 'Pomocná data (ratio, expected, actual, ...)',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_plant_created (plant_id, created_at),
    KEY idx_unack (acknowledged_at),
    CONSTRAINT fk_a_plant FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────
-- Cache pro API tokeny (iSolarCloud)
-- ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_tokens (
    provider    VARCHAR(32) NOT NULL,
    token       VARCHAR(512) NOT NULL,
    expires_at  DATETIME    NOT NULL,
    PRIMARY KEY (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
