-- Рассылки, аналитика и счётчик пейволла

CREATE TABLE campaigns (
    id            BIGSERIAL PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    subject       VARCHAR(300) NOT NULL,
    preheader     VARCHAR(300) NOT NULL DEFAULT '',
    body_html     TEXT         NOT NULL,
    segment       JSONB        NOT NULL DEFAULT '{}',
    status        VARCHAR(12)  NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'canceled')),
    scheduled_at  TIMESTAMPTZ,
    started_at    TIMESTAMPTZ,
    finished_at   TIMESTAMPTZ,
    total_count   INTEGER      NOT NULL DEFAULT 0,
    sent_count    INTEGER      NOT NULL DEFAULT 0,
    failed_count  INTEGER      NOT NULL DEFAULT 0,
    created_by    BIGINT REFERENCES users (id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE campaign_deliveries (
    campaign_id BIGINT      NOT NULL REFERENCES campaigns (id) ON DELETE CASCADE,
    user_id     BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    status      VARCHAR(10) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'failed')),
    error       VARCHAR(300),
    sent_at     TIMESTAMPTZ,
    PRIMARY KEY (campaign_id, user_id)
);
CREATE INDEX campaign_deliveries_status_idx ON campaign_deliveries (campaign_id, status);

CREATE TABLE article_views (
    id            BIGSERIAL PRIMARY KEY,
    article_id    BIGINT      NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    user_id       BIGINT REFERENCES users (id) ON DELETE SET NULL,
    visitor_id    VARCHAR(48),
    source        VARCHAR(16) NOT NULL DEFAULT 'direct' CHECK (source IN ('direct', 'search', 'social', 'internal', 'email', 'other')),
    referrer_host VARCHAR(120),
    utm_source    VARCHAR(80),
    completed     BOOLEAN     NOT NULL DEFAULT FALSE,
    read_seconds  INTEGER     NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX article_views_article_idx ON article_views (article_id, created_at DESC);
CREATE INDEX article_views_created_idx ON article_views (created_at);

-- Какие премиум-статьи уже прочитал посетитель в этом месяце
CREATE TABLE paywall_reads (
    identity   VARCHAR(64) NOT NULL,
    article_id BIGINT      NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    month      DATE        NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (identity, article_id, month)
);
CREATE INDEX paywall_reads_month_idx ON paywall_reads (identity, month);

-- Показы пейволла и клики по CTA (для конверсии)
CREATE TABLE paywall_events (
    id         BIGSERIAL PRIMARY KEY,
    identity   VARCHAR(64) NOT NULL,
    user_id    BIGINT REFERENCES users (id) ON DELETE SET NULL,
    article_id BIGINT REFERENCES articles (id) ON DELETE SET NULL,
    event      VARCHAR(12) NOT NULL CHECK (event IN ('shown', 'cta_click')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX paywall_events_idx ON paywall_events (created_at, event);
