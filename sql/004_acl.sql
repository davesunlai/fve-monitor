-- ─────────────────────────────────────────────
-- 004: ACL - per-stránka oprávnění + public flag
-- ─────────────────────────────────────────────

-- Definice stránek (seed se vyplní níže)
CREATE TABLE IF NOT EXISTS acl_pages (
    page_key    VARCHAR(64)  NOT NULL,
    title       VARCHAR(128) NOT NULL,
    url         VARCHAR(255) NOT NULL,
    icon        VARCHAR(16)  DEFAULT NULL,
    is_public   TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user permissions
CREATE TABLE IF NOT EXISTS user_page_permissions (
    user_id   INT UNSIGNED NOT NULL,
    page_key  VARCHAR(64)  NOT NULL,
    PRIMARY KEY (user_id, page_key),
    KEY idx_page (page_key),
    CONSTRAINT fk_upp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_upp_page FOREIGN KEY (page_key) REFERENCES acl_pages(page_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: dashboard veřejný, ostatní jen pro přihlášené
INSERT INTO acl_pages (page_key, title, url, icon, is_public, sort_order) VALUES
    ('dashboard',   'Dashboard',             '/index.php',         '🏠', 1, 10),
    ('comparison',  'Denní srovnání FVE',    '/comparison.php',    '📊', 0, 20),
    ('performance', 'Plnění FVE (vs PVGIS)', '/performance.php',   '📈', 0, 30),
    ('yearly',      'Roční přehled',         '/yearly.php',        '📆', 0, 40),
    ('spot',        'Spotové ceny',          '/spot.php',          '⚡', 0, 50),
    ('spot_calc',   'SPOT kalkulačka',       '/spot_calc.php',     '🧮', 0, 60)
ON DUPLICATE KEY UPDATE title=VALUES(title), url=VALUES(url), icon=VALUES(icon);

-- Defaultně dej všem existujícím adminům přístup ke všem stránkám
INSERT IGNORE INTO user_page_permissions (user_id, page_key)
SELECT u.id, p.page_key
FROM users u
CROSS JOIN acl_pages p
WHERE u.role = 'admin';
