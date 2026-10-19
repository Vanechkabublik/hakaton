-- Вовлечённость: комментарии, реакции, закладки, история чтения, подписки на авторов, push

CREATE TABLE comments (
    id            BIGSERIAL PRIMARY KEY,
    article_id    BIGINT      NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    user_id       BIGINT REFERENCES users (id) ON DELETE SET NULL,
    parent_id     BIGINT REFERENCES comments (id) ON DELETE CASCADE,
    body          TEXT        NOT NULL,
    status        VARCHAR(12) NOT NULL DEFAULT 'approved' CHECK (status IN ('pending', 'approved', 'rejected', 'spam')),
    likes_count   INTEGER     NOT NULL DEFAULT 0,
    reports_count INTEGER     NOT NULL DEFAULT 0,
    ip            VARCHAR(45),
    edited_at     TIMESTAMPTZ,
    moderated_by  BIGINT REFERENCES users (id) ON DELETE SET NULL,
    moderated_at  TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX comments_article_idx ON comments (article_id, created_at);
CREATE INDEX comments_parent_idx ON comments (parent_id);
CREATE INDEX comments_user_idx ON comments (user_id, created_at DESC);
CREATE INDEX comments_status_idx ON comments (status, created_at DESC);

CREATE TABLE comment_likes (
    comment_id BIGINT      NOT NULL REFERENCES comments (id) ON DELETE CASCADE,
    user_id    BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (comment_id, user_id)
);

CREATE TABLE comment_reports (
    id          BIGSERIAL PRIMARY KEY,
    comment_id  BIGINT      NOT NULL REFERENCES comments (id) ON DELETE CASCADE,
    user_id     BIGINT REFERENCES users (id) ON DELETE SET NULL,
    reason      VARCHAR(30) NOT NULL DEFAULT 'other',
    details     VARCHAR(500) NOT NULL DEFAULT '',
    resolved_at TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (comment_id, user_id)
);

CREATE TABLE reactions (
    article_id BIGINT      NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    user_id    BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind       VARCHAR(16) NOT NULL CHECK (kind IN ('like', 'useful', 'controversial')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (article_id, user_id)
);
CREATE INDEX reactions_kind_idx ON reactions (article_id, kind);

CREATE TABLE bookmarks (
    user_id    BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    article_id BIGINT      NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, article_id)
);
CREATE INDEX bookmarks_user_idx ON bookmarks (user_id, created_at DESC);

CREATE TABLE reading_history (
    user_id       BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    article_id    BIGINT      NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    first_read_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_read_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    progress      SMALLINT    NOT NULL DEFAULT 0,
    completed     BOOLEAN     NOT NULL DEFAULT FALSE,
    PRIMARY KEY (user_id, article_id)
);
CREATE INDEX reading_history_user_idx ON reading_history (user_id, last_read_at DESC);

CREATE TABLE author_follows (
    user_id    BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    author_id  BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, author_id)
);
CREATE INDEX author_follows_author_idx ON author_follows (author_id);

CREATE TABLE push_subscriptions (
    id           BIGSERIAL PRIMARY KEY,
    user_id      BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    endpoint     TEXT         NOT NULL UNIQUE,
    p256dh       VARCHAR(200) NOT NULL,
    auth         VARCHAR(100) NOT NULL,
    user_agent   VARCHAR(500),
    failed_count SMALLINT     NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX push_subscriptions_user_idx ON push_subscriptions (user_id);
