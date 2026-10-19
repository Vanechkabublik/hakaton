-- Контент: категории, теги, медиатека, статьи, ревизии, страницы

CREATE TABLE categories (
    id          SERIAL PRIMARY KEY,
    slug        VARCHAR(100) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    description TEXT         NOT NULL DEFAULT '',
    color       VARCHAR(7)   NOT NULL DEFAULT '#2563eb',
    sort_order  INTEGER      NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE tags (
    id         SERIAL PRIMARY KEY,
    slug       VARCHAR(100) NOT NULL UNIQUE,
    name       VARCHAR(100) NOT NULL,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX tags_name_trgm ON tags USING gin (name gin_trgm_ops);

CREATE TABLE media (
    id            BIGSERIAL PRIMARY KEY,
    user_id       BIGINT REFERENCES users (id) ON DELETE SET NULL,
    original_name VARCHAR(255) NOT NULL,
    mime          VARCHAR(60)  NOT NULL,
    width         INTEGER      NOT NULL DEFAULT 0,
    height        INTEGER      NOT NULL DEFAULT 0,
    size_bytes    BIGINT       NOT NULL DEFAULT 0,
    alt           VARCHAR(300) NOT NULL DEFAULT '',
    -- ширина => относительный путь файла в storage/uploads
    variants      JSONB        NOT NULL DEFAULT '{}',
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX media_created_idx ON media (created_at DESC);

CREATE TABLE articles (
    id              BIGSERIAL PRIMARY KEY,
    type            VARCHAR(16)  NOT NULL DEFAULT 'news'
        CHECK (type IN ('news', 'analytics', 'longread', 'opinion', 'photo', 'liveblog')),
    status          VARCHAR(16)  NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'pending', 'scheduled', 'published', 'archived')),
    access          VARCHAR(16)  NOT NULL DEFAULT 'free' CHECK (access IN ('free', 'digital', 'premium')),
    slug            VARCHAR(200) NOT NULL UNIQUE,
    title           VARCHAR(300) NOT NULL,
    subtitle        VARCHAR(500) NOT NULL DEFAULT '',
    lead            TEXT         NOT NULL DEFAULT '',
    body            TEXT         NOT NULL DEFAULT '',
    cover_media_id  BIGINT REFERENCES media (id) ON DELETE SET NULL,
    cover_caption   VARCHAR(300) NOT NULL DEFAULT '',
    author_id       BIGINT REFERENCES users (id) ON DELETE SET NULL,
    category_id     INTEGER REFERENCES categories (id) ON DELETE SET NULL,
    reading_minutes SMALLINT     NOT NULL DEFAULT 1,
    seo_title       VARCHAR(200) NOT NULL DEFAULT '',
    seo_description VARCHAR(400) NOT NULL DEFAULT '',
    is_featured     BOOLEAN      NOT NULL DEFAULT FALSE,
    allow_comments  BOOLEAN      NOT NULL DEFAULT TRUE,
    is_live         BOOLEAN      NOT NULL DEFAULT FALSE,
    publish_at      TIMESTAMPTZ,
    published_at    TIMESTAMPTZ,
    archived_at     TIMESTAMPTZ,
    submitted_at    TIMESTAMPTZ,
    moderated_by    BIGINT REFERENCES users (id) ON DELETE SET NULL,
    moderation_note TEXT         NOT NULL DEFAULT '',
    views_count     INTEGER      NOT NULL DEFAULT 0,
    comments_count  INTEGER      NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    search_vector   TSVECTOR GENERATED ALWAYS AS (
        setweight(to_tsvector('russian', coalesce(title, '')), 'A') ||
        setweight(to_tsvector('russian', coalesce(subtitle, '')), 'B') ||
        setweight(to_tsvector('russian', coalesce(lead, '')), 'B') ||
        setweight(to_tsvector('russian', regexp_replace(coalesce(body, ''), '<[^>]+>', ' ', 'g')), 'C')
    ) STORED
);
CREATE INDEX articles_published_idx ON articles (published_at DESC) WHERE status = 'published';
CREATE INDEX articles_category_idx ON articles (category_id, published_at DESC) WHERE status = 'published';
CREATE INDEX articles_author_idx ON articles (author_id);
CREATE INDEX articles_status_idx ON articles (status, updated_at DESC);
CREATE INDEX articles_scheduled_idx ON articles (publish_at) WHERE status = 'scheduled';
CREATE INDEX articles_search_idx ON articles USING gin (search_vector);
CREATE INDEX articles_title_trgm ON articles USING gin (title gin_trgm_ops);

CREATE TABLE article_tag (
    article_id BIGINT  NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    tag_id     INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
    PRIMARY KEY (article_id, tag_id)
);
CREATE INDEX article_tag_tag_idx ON article_tag (tag_id);

CREATE TABLE article_related (
    article_id BIGINT  NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    related_id BIGINT  NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (article_id, related_id),
    CHECK (article_id <> related_id)
);

CREATE TABLE article_revisions (
    id         BIGSERIAL PRIMARY KEY,
    article_id BIGINT      NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    user_id    BIGINT REFERENCES users (id) ON DELETE SET NULL,
    data       JSONB       NOT NULL,
    note       VARCHAR(200) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX article_revisions_idx ON article_revisions (article_id, created_at DESC);

-- Фотографии фоторепортажа
CREATE TABLE article_gallery (
    id         BIGSERIAL PRIMARY KEY,
    article_id BIGINT       NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    media_id   BIGINT       NOT NULL REFERENCES media (id) ON DELETE CASCADE,
    caption    VARCHAR(500) NOT NULL DEFAULT '',
    sort_order INTEGER      NOT NULL DEFAULT 0
);
CREATE INDEX article_gallery_idx ON article_gallery (article_id, sort_order);

-- Записи live-блога
CREATE TABLE live_entries (
    id         BIGSERIAL PRIMARY KEY,
    article_id BIGINT       NOT NULL REFERENCES articles (id) ON DELETE CASCADE,
    user_id    BIGINT REFERENCES users (id) ON DELETE SET NULL,
    title      VARCHAR(300) NOT NULL DEFAULT '',
    body       TEXT         NOT NULL,
    is_pinned  BOOLEAN      NOT NULL DEFAULT FALSE,
    posted_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX live_entries_idx ON live_entries (article_id, posted_at DESC);

CREATE TABLE pages (
    id              SERIAL PRIMARY KEY,
    slug            VARCHAR(100) NOT NULL UNIQUE,
    title           VARCHAR(200) NOT NULL,
    body            TEXT         NOT NULL DEFAULT '',
    seo_description VARCHAR(400) NOT NULL DEFAULT '',
    is_published    BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
