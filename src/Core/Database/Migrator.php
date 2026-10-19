<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config;

/** Применяет SQL-миграции из каталога migrations/ по порядку имён; каждая — в своей транзакции. */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    /** @return list<string> применённые в этот запуск миграции */
    public function migrate(): array
    {
        $pdo = $this->db->pdo();
        // Не даём двум контейнерам мигрировать одновременно
        $pdo->exec('SELECT pg_advisory_lock(7461001)');
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, executed_at TIMESTAMPTZ NOT NULL DEFAULT now())');
            $done = array_flip($this->db->fetchColumn('SELECT name FROM migrations'));
            $applied = [];
            foreach ($this->files() as $file) {
                $name = basename($file);
                if (isset($done[$name])) {
                    continue;
                }
                $sql = (string) file_get_contents($file);
                $this->db->transaction(function () use ($pdo, $sql, $name): void {
                    $pdo->exec($sql);
                    $this->db->execute('INSERT INTO migrations (name) VALUES (:name)', ['name' => $name]);
                });
                $applied[] = $name;
            }

            return $applied;
        } finally {
            $pdo->exec('SELECT pg_advisory_unlock(7461001)');
        }
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob($this->config->basePath('migrations/*.sql')) ?: [];
        sort($files, SORT_STRING);

        return $files;
    }
}
