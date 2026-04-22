USE fve_monitor;

CREATE TABLE IF NOT EXISTS plant_sections (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plant_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(64)  NOT NULL DEFAULT 'Hlavní',
    tilt_deg        TINYINT      NOT NULL DEFAULT 35,
    azimuth_deg     SMALLINT     NOT NULL DEFAULT 0,
    power_share_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_plant (plant_id),
    CONSTRAINT fk_section_plant FOREIGN KEY (plant_id) REFERENCES plants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE plants
    ADD COLUMN install_year SMALLINT UNSIGNED NULL AFTER system_loss_pct,
    ADD COLUMN degradation_pct_per_year DECIMAL(4,2) NOT NULL DEFAULT 0.50 AFTER install_year;

ALTER TABLE pvgis_monthly
    DROP PRIMARY KEY,
    ADD COLUMN section_id INT UNSIGNED NULL AFTER plant_id,
    ADD PRIMARY KEY (plant_id, section_id, month),
    ADD KEY idx_section (section_id);

INSERT INTO plant_sections (plant_id, name, tilt_deg, azimuth_deg, power_share_pct, sort_order)
SELECT id, 'Hlavní', tilt_deg, azimuth_deg, 100.00, 0
FROM plants
WHERE id NOT IN (SELECT DISTINCT plant_id FROM plant_sections);
