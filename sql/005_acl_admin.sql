-- ─────────────────────────────────────────────
-- 005: ACL - admin stránky
-- ─────────────────────────────────────────────

INSERT INTO acl_pages (page_key, title, url, icon, is_public, sort_order) VALUES
    ('admin_plants',         'Elektrárny',           '/admin/index.php',              '⚙',  0, 100),
    ('admin_plant_edit',     'Nová / úprava FVE',    '/admin/plant_edit.php',         '➕', 0, 110),
    ('admin_import_isolar',  'Import z iSolarCloud', '/admin/import_isolarcloud.php', '⬇',  0, 120),
    ('admin_import_csv',     'Import CSV',           '/admin/import_csv.php',         '📥', 0, 130),
    ('admin_plants_ote',     'OTE / ERÚ metadata',   '/admin/plants_ote.php',         '🏛️', 0, 140),
    ('admin_ote_report',     'OTE měsíční výkaz',    '/admin/ote_report.php',         '📊', 0, 150),
    ('admin_alert_settings', 'Nastavení alertů',     '/admin/alert_settings.php',     '🔧', 0, 160),
    ('admin_alerts_history', 'Historie alertů',      '/admin/alerts_history.php',     '📋', 0, 170),
    ('admin_users',          'Uživatelé',            '/admin/users.php',              '👥', 0, 180),
    ('admin_login_log',      'Activity log',         '/admin/login_log.php',          '📒', 0, 190)
ON DUPLICATE KEY UPDATE title=VALUES(title), url=VALUES(url), icon=VALUES(icon), sort_order=VALUES(sort_order);

-- Propagace: všichni stávající admini dostanou plná admin práva
INSERT IGNORE INTO user_page_permissions (user_id, page_key)
SELECT u.id, p.page_key
FROM users u
CROSS JOIN acl_pages p
WHERE u.role = 'admin' AND p.page_key LIKE 'admin_%';
