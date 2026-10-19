-- Ядро: пользователи, сессии, токены, настройки, аудит, очереди

CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE users (
    id                BIGSERIAL PRIMARY KEY,
    email             CITEXT       NOT NULL UNIQUE,
    password_hash     TEXT,
    name              VARCHAR(120) NOT NULL,
    slug              VARCHAR(140) UNIQUE,
    bio               TEXT         NOT NULL DEFAULT '',
    avatar_path       VARCHAR(255),
    role              VARCHAR(16)  NOT NULL DEFAULT 'reader' CHECK (role IN ('reader', 'author', 'editor', 'admin')),
    status            VARCHAR(16)  NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'blocked')),
    blocked_reason    TEXT,
    email_verified_at TIMESTAMPTZ,
    pending_email     CITEXT,
    totp_secret       TEXT,
    totp_enabled_at   TIMESTAMPTZ,
    totp_last_step    BIGINT       NOT NULL DEFAULT 0,
    totp_recovery     JSONB        NOT NULL DEFAULT '[]',
    digest_frequency  VARCHAR(10)  NOT NULL DEFAULT 'weekly' CHECK (digest_frequency IN ('none', 'daily', 'weekly')),
    notify_replies    BOOLEAN      NOT NULL DEFAULT TRUE,
    notify_authors    BOOLEAN      NOT NULL DEFAULT TRUE,
    notify_push       BOOLEAN      NOT NULL DEFAULT FALSE,
    marketing_opt_in  BOOLEAN      NOT NULL DEFAULT FALSE,
    unsubscribe_token VARCHAR(64)  NOT NULL,
    trial_used_at     TIMESTAMPTZ,
    onboarding_step   SMALLINT     NOT NULL DEFAULT 0,
    last_login_at     TIMESTAMPTZ,
    last_login_ip     VARCHAR(45),
    last_digest_at    TIMESTAMPTZ,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX users_role_idx ON users (role);
CREATE INDEX users_created_idx ON users (created_at);
CREATE INDEX users_name_trgm ON users USING gin (name gin_trgm_ops);

CREATE TABLE oauth_identities (
    id               BIGSERIAL PRIMARY KEY,
    user_id          BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    provider         VARCHAR(20)  NOT NULL,
    provider_user_id VARCHAR(100) NOT NULL,
    login            VARCHAR(190),
    email            VARCHAR(190),
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (provider, provider_user_id),
    UNIQUE (user_id, provider)
);

-- Токены одноразовых действий: подтверждение email, сброс пароля, смена email
CREATE TABLE email_tokens (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    type       VARCHAR(20) NOT NULL CHECK (type IN ('verify', 'reset', 'email_change')),
    token_hash CHAR(64)    NOT NULL UNIQUE,
    payload    JSONB       NOT NULL DEFAULT '{}',
    expires_at TIMESTAMPTZ NOT NULL,
    used_at    TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX email_tokens_user_idx ON email_tokens (user_id, type);

CREATE TABLE remember_tokens (
    id             BIGSERIAL PRIMARY KEY,
    user_id        BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    selector       CHAR(24)     NOT NULL UNIQUE,
    validator_hash CHAR(64)     NOT NULL,
    user_agent     VARCHAR(500),
    ip             VARCHAR(45),
    expires_at     TIMESTAMPTZ  NOT NULL,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX remember_tokens_user_idx ON remember_tokens (user_id);

CREATE TABLE sessions (
    id            CHAR(64) PRIMARY KEY,
    user_id       BIGINT REFERENCES users (id) ON DELETE CASCADE,
    payload       TEXT         NOT NULL,
    ip            VARCHAR(45),
    user_agent    VARCHAR(500),
    last_activity BIGINT       NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX sessions_user_idx ON sessions (user_id);
CREATE INDEX sessions_activity_idx ON sessions (last_activity);

CREATE UNLOGGED TABLE rate_limits (
    key        VARCHAR(160) PRIMARY KEY,
    hits       INTEGER     NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX rate_limits_expires_idx ON rate_limits (expires_at);

CREATE TABLE settings (
    key        VARCHAR(100) PRIMARY KEY,
    value      TEXT        NOT NULL DEFAULT '',
    is_secret  BOOLEAN     NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by BIGINT REFERENCES users (id) ON DELETE SET NULL
);

CREATE TABLE audit_log (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT REFERENCES users (id) ON DELETE SET NULL,
    action      VARCHAR(80) NOT NULL,
    entity_type VARCHAR(40),
    entity_id   VARCHAR(40),
    meta        JSONB       NOT NULL DEFAULT '{}',
    ip          VARCHAR(45),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX audit_log_created_idx ON audit_log (created_at DESC);
CREATE INDEX audit_log_user_idx ON audit_log (user_id, created_at DESC);
CREATE INDEX audit_log_entity_idx ON audit_log (entity_type, entity_id);

-- Очередь фоновых задач
CREATE TABLE jobs (
    id           BIGSERIAL PRIMARY KEY,
    queue        VARCHAR(40)  NOT NULL DEFAULT 'default',
    job          VARCHAR(190) NOT NULL,
    payload      JSONB        NOT NULL DEFAULT '{}',
    attempts     SMALLINT     NOT NULL DEFAULT 0,
    max_attempts SMALLINT     NOT NULL DEFAULT 5,
    available_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    reserved_at  TIMESTAMPTZ,
    failed_at    TIMESTAMPTZ,
    last_error   TEXT,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX jobs_pending_idx ON jobs (queue, available_at) WHERE failed_at IS NULL;

CREATE TABLE scheduled_runs (
    task        VARCHAR(80) PRIMARY KEY,
    last_run_at TIMESTAMPTZ NOT NULL
);
