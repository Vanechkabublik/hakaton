<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Migrator;
use Tests\Support\IntegrationTestCase;

final class MigrationsTest extends IntegrationTestCase
{
    public function testAllMigrationFilesAreApplied(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files);
        $applied = $this->db->fetchColumn('SELECT name FROM migrations ORDER BY name');

        $this->assertNotEmpty($files);
        $this->assertSame($files, $applied);
    }

    public function testMigratorIsIdempotent(): void
    {
        $this->assertSame([], $this->c->get(Migrator::class)->migrate());
    }

    public function testKeyTablesExist(): void
    {
        $tables = $this->db->fetchColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        foreach ([
            'users', 'sessions', 'oauth_identities', 'email_tokens', 'remember_tokens', 'rate_limits', 'settings', 'audit_log',
            'jobs', 'scheduled_runs', 'articles', 'article_revisions', 'categories', 'tags', 'media', 'comments',
            'plans', 'subscriptions', 'payments', 'refunds', 'promo_codes', 'gifts', 'webhook_events',
            'paywall_reads', 'paywall_events', 'article_views', 'campaigns', 'push_subscriptions',
        ] as $table) {
            $this->assertContains($table, $tables, "Нет таблицы {$table}");
        }
    }

    public function testRequiredExtensionsAreInstalled(): void
    {
        $extensions = $this->db->fetchColumn('SELECT extname FROM pg_extension');
        $this->assertContains('pg_trgm', $extensions);
        $this->assertContains('citext', $extensions);
    }

    public function testEmailIsCaseInsensitiveUnique(): void
    {
        $this->fx->user(['email' => 'Reader@Example.test']);
        $this->expectException(\PDOException::class);
        $this->fx->user(['email' => 'reader@example.TEST']);
    }

    public function testRussianFullTextSearchUsesStemming(): void
    {
        $this->fx->article(['title' => 'Рынок облачных технологий растёт', 'slug' => 'cloud', 'body' => '<p>Компании переходят в облака.</p>']);

        $found = $this->db->fetchColumn(
            "SELECT slug FROM articles WHERE search_vector @@ websearch_to_tsquery('russian', :q)",
            ['q' => 'облачная технология']
        );

        $this->assertSame(['cloud'], $found);
    }

    public function testCheckConstraintsRejectBadEnumValues(): void
    {
        $this->expectException(\PDOException::class);
        $this->fx->article(['status' => 'published-ish']);
    }
}
