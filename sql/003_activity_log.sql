-- ───────────────────────────────────────────────────────────
-- 003: Activity tracking & login log
-- ───────────────────────────────────────────────────────────

-- Login eventy (audit log autentifikace)
CREATE TABLE IF NOT EXISTS admin_login_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NULL COMMENT 'NULL při login_fail (uživatel neexistuje)',
    username        VARCHAR(64)  NOT NULL COMMENT 'Zadaný username (i pro fail)',
    event           ENUM('login_success','login_fail','logout') NOT NULL,
    method          ENUM('password','passkey') NOT NULL DEFAULT 'password',
    fail_reason     VARCHAR(64) NULL COMMENT 'unknown_user|bad_password|inactive',
    ip              VARCHAR(45)  NOT NULL,
    user_agent      VARCHAR(500) NULL,
    session_key     CHAR(64)     NULL COMMENT 'SHA-256 hash session ID (pro párování s logout)',
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    logged_out_at   TIMESTAMP    NULL COMMENT 'NULL = stále aktivní nebo session vypršela bez logout',
    PRIMARY KEY (id),
    KEY idx_user_time (user_id, created_at),
    KEY idx_event_time (event, created_at),
    KEY idx_session (session_key),
    KEY idx_ip (ip),
    CONSTRAINT fk_loglog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity tracking (i anonymní, heartbeat)
CREATE TABLE IF NOT EXISTS activity_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visitor_key     CHAR(64)     NOT NULL COMMENT 'SHA-256(IP + UA) pro anon; SHA-256(session_id) pro přihlášené',
    user_id         INT UNSIGNED NULL COMMENT 'NULL = anonymní',
    ip              VARCHAR(45)  NOT NULL,
    user_agent      VARCHAR(500) NULL,
    first_seen      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_page       VARCHAR(255) NULL COMMENT 'REQUEST_URI poslední návštěvy',
    page_views      INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_visitor (visitor_key),
    KEY idx_last_seen (last_seen),
    KEY idx_user (user_id),
    CONSTRAINT fk_actses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity log (historie page-views, append-only)
CREATE TABLE IF NOT EXISTS activity_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visitor_key     CHAR(64)     NOT NULL,
    user_id         INT UNSIGNED NULL,
    ip              VARCHAR(45)  NOT NULL,
    user_agent      VARCHAR(500) NULL,
    request_uri     VARCHAR(500) NOT NULL,
    method          VARCHAR(8)   NOT NULL DEFAULT 'GET',
    referer         VARCHAR(500) NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_visitor_time (visitor_key, created_at),
    KEY idx_user_time (user_id, created_at),
    KEY idx_created (created_at),
    KEY idx_uri (request_uri(64)),
    CONSTRAINT fk_actlog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
