-- Биллинг: тарифы, подписки, платежи ЮKassa, возвраты, промокоды, подарки
-- Все суммы хранятся в копейках (INTEGER).

CREATE TABLE plans (
    id          SERIAL PRIMARY KEY,
    code        VARCHAR(40)  NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    tier        VARCHAR(10)  NOT NULL CHECK (tier IN ('digital', 'premium')),
    period      VARCHAR(10)  NOT NULL CHECK (period IN ('month', 'year')),
    price       INTEGER      NOT NULL CHECK (price >= 0),
    trial_days  SMALLINT     NOT NULL DEFAULT 0,
    description TEXT         NOT NULL DEFAULT '',
    features    JSONB        NOT NULL DEFAULT '[]',
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order  INTEGER      NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE subscriptions (
    id                   BIGSERIAL PRIMARY KEY,
    user_id              BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    plan_id              INTEGER     NOT NULL REFERENCES plans (id),
    pending_plan_id      INTEGER REFERENCES plans (id) ON DELETE SET NULL,
    status               VARCHAR(12) NOT NULL CHECK (status IN ('trialing', 'active', 'past_due', 'expired')),
    source               VARCHAR(12) NOT NULL DEFAULT 'payment' CHECK (source IN ('payment', 'manual', 'trial', 'gift', 'promo')),
    started_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    current_period_start TIMESTAMPTZ NOT NULL DEFAULT now(),
    current_period_end   TIMESTAMPTZ NOT NULL,
    grace_until          TIMESTAMPTZ,
    auto_renew           BOOLEAN     NOT NULL DEFAULT FALSE,
    payment_method_id    VARCHAR(100),
    payment_method_title VARCHAR(100),
    renew_attempts       SMALLINT    NOT NULL DEFAULT 0,
    next_attempt_at      TIMESTAMPTZ,
    canceled_at          TIMESTAMPTZ,
    cancel_reason        VARCHAR(300),
    paywall_article_id   BIGINT REFERENCES articles (id) ON DELETE SET NULL,
    granted_by           BIGINT REFERENCES users (id) ON DELETE SET NULL,
    ended_at             TIMESTAMPTZ,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- у пользователя может быть только одна «живая» подписка
CREATE UNIQUE INDEX subscriptions_one_live_idx ON subscriptions (user_id) WHERE status IN ('trialing', 'active', 'past_due');
CREATE INDEX subscriptions_user_idx ON subscriptions (user_id, created_at DESC);
CREATE INDEX subscriptions_period_end_idx ON subscriptions (current_period_end) WHERE status IN ('trialing', 'active', 'past_due');
CREATE INDEX subscriptions_renew_idx ON subscriptions (next_attempt_at) WHERE auto_renew AND status IN ('active', 'past_due');

CREATE TABLE promo_codes (
    id              SERIAL PRIMARY KEY,
    code            CITEXT       NOT NULL UNIQUE,
    description     VARCHAR(200) NOT NULL DEFAULT '',
    discount_type   VARCHAR(10)  NOT NULL CHECK (discount_type IN ('percent', 'fixed')),
    discount_value  INTEGER      NOT NULL CHECK (discount_value > 0),
    max_uses        INTEGER,
    used_count      INTEGER      NOT NULL DEFAULT 0,
    per_user_limit  SMALLINT     NOT NULL DEFAULT 1,
    valid_from      TIMESTAMPTZ,
    valid_until     TIMESTAMPTZ,
    plan_ids        JSONB        NOT NULL DEFAULT '[]',
    first_payment_only BOOLEAN   NOT NULL DEFAULT TRUE,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE payments (
    id                   BIGSERIAL PRIMARY KEY,
    user_id              BIGINT REFERENCES users (id) ON DELETE SET NULL,
    customer_email       VARCHAR(190),
    subscription_id      BIGINT REFERENCES subscriptions (id) ON DELETE SET NULL,
    plan_id              INTEGER REFERENCES plans (id) ON DELETE SET NULL,
    promo_code_id        INTEGER REFERENCES promo_codes (id) ON DELETE SET NULL,
    kind                 VARCHAR(12) NOT NULL DEFAULT 'initial' CHECK (kind IN ('initial', 'renewal', 'extension', 'upgrade', 'gift')),
    status               VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'waiting_for_capture', 'succeeded', 'canceled')),
    amount               INTEGER     NOT NULL CHECK (amount >= 0),
    original_amount      INTEGER     NOT NULL DEFAULT 0,
    discount_amount      INTEGER     NOT NULL DEFAULT 0,
    refunded_amount      INTEGER     NOT NULL DEFAULT 0,
    currency             CHAR(3)     NOT NULL DEFAULT 'RUB',
    description          VARCHAR(255) NOT NULL DEFAULT '',
    yookassa_id          VARCHAR(64) UNIQUE,
    idempotence_key      VARCHAR(64) NOT NULL UNIQUE,
    confirmation_type    VARCHAR(12),
    confirmation_url     TEXT,
    confirmation_token   TEXT,
    save_method          BOOLEAN     NOT NULL DEFAULT FALSE,
    is_recurring         BOOLEAN     NOT NULL DEFAULT FALSE,
    payment_method_id    VARCHAR(100),
    payment_method_title VARCHAR(100),
    cancel_reason        VARCHAR(100),
    paywall_article_id   BIGINT REFERENCES articles (id) ON DELETE SET NULL,
    metadata             JSONB       NOT NULL DEFAULT '{}',
    paid_at              TIMESTAMPTZ,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX payments_user_idx ON payments (user_id, created_at DESC);
CREATE INDEX payments_status_idx ON payments (status, created_at DESC);
CREATE INDEX payments_paid_idx ON payments (paid_at) WHERE status = 'succeeded';

CREATE TABLE refunds (
    id          BIGSERIAL PRIMARY KEY,
    payment_id  BIGINT      NOT NULL REFERENCES payments (id) ON DELETE CASCADE,
    yookassa_id VARCHAR(64) UNIQUE,
    amount      INTEGER     NOT NULL CHECK (amount > 0),
    status      VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'succeeded', 'canceled')),
    reason      VARCHAR(300) NOT NULL DEFAULT '',
    created_by  BIGINT REFERENCES users (id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX refunds_payment_idx ON refunds (payment_id);

CREATE TABLE promo_redemptions (
    id         BIGSERIAL PRIMARY KEY,
    promo_id   INTEGER     NOT NULL REFERENCES promo_codes (id) ON DELETE CASCADE,
    user_id    BIGINT REFERENCES users (id) ON DELETE SET NULL,
    payment_id BIGINT REFERENCES payments (id) ON DELETE SET NULL,
    discount   INTEGER     NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX promo_redemptions_idx ON promo_redemptions (promo_id, user_id);

CREATE TABLE gifts (
    id              BIGSERIAL PRIMARY KEY,
    code            VARCHAR(24)  NOT NULL UNIQUE,
    plan_id         INTEGER      NOT NULL REFERENCES plans (id),
    buyer_id        BIGINT REFERENCES users (id) ON DELETE SET NULL,
    buyer_name      VARCHAR(120) NOT NULL DEFAULT '',
    recipient_email VARCHAR(190) NOT NULL,
    recipient_name  VARCHAR(120) NOT NULL DEFAULT '',
    message         VARCHAR(1000) NOT NULL DEFAULT '',
    status          VARCHAR(12)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'redeemed', 'expired')),
    payment_id      BIGINT REFERENCES payments (id) ON DELETE SET NULL,
    redeemed_by     BIGINT REFERENCES users (id) ON DELETE SET NULL,
    redeemed_at     TIMESTAMPTZ,
    delivered_at    TIMESTAMPTZ,
    expires_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX gifts_buyer_idx ON gifts (buyer_id);

-- Журнал вебхуков ЮKassa
CREATE TABLE webhook_events (
    id           BIGSERIAL PRIMARY KEY,
    event        VARCHAR(60) NOT NULL,
    object_id    VARCHAR(64),
    payload      JSONB       NOT NULL,
    status       VARCHAR(12) NOT NULL DEFAULT 'received' CHECK (status IN ('received', 'processed', 'failed', 'ignored')),
    error        TEXT,
    ip           VARCHAR(45),
    received_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_at TIMESTAMPTZ
);
CREATE INDEX webhook_events_idx ON webhook_events (received_at DESC);

-- Уже отправленные напоминания об окончании подписки
CREATE TABLE subscription_reminders (
    subscription_id BIGINT      NOT NULL REFERENCES subscriptions (id) ON DELETE CASCADE,
    kind            VARCHAR(20) NOT NULL,
    period_end      TIMESTAMPTZ NOT NULL,
    sent_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (subscription_id, kind, period_end)
);
