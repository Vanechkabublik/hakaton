<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Audit\AuditLog;
use App\Auth\TwoFactorService;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\StreamedResponse;
use App\Core\Http\ValidationException;
use App\Core\Queue\Queue;
use App\Core\Schedule\Scheduler;
use App\Core\Security\PasswordHasher;
use App\Http\AdminController;
use App\Security\DatabaseBackup;
use App\User\UserRepository;

/** Безопасность: аудит действий, управление 2FA сотрудников, экспорт и бэкапы БД, состояние очереди. */
final class SecurityController extends AdminController
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly UserRepository $users,
        private readonly TwoFactorService $twoFactor,
        private readonly DatabaseBackup $backup,
        private readonly PasswordHasher $hasher,
        private readonly Queue $queue,
        private readonly Scheduler $scheduler,
        private readonly Database $db,
    ) {
    }

    public function audit(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'action', 'user_id']);
        [$page, $offset] = $this->paging($request, 50);
        $result = $this->audit->search($filters, 50, $offset);

        return $this->admin('security/audit', [
            'title' => 'Безопасность: журнал действий',
            'section' => 'security',
            'tab' => 'audit',
            'items' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / 50)),
        ]);
    }

    public function staff(): Response
    {
        return $this->admin('security/staff', [
            'title' => 'Безопасность: сотрудники и 2FA',
            'section' => 'security',
            'tab' => 'staff',
            'staff' => $this->users->listStaff(),
        ]);
    }

    public function resetTwoFactor(Request $request, int $id): Response
    {
        $subject = $this->users->find($id) ?? $this->notFound();
        $this->twoFactor->disable($subject, $this->requireUser()->id);

        return $this->done($request, '2FA сброшена для ' . $subject->email, '/admin/security/staff');
    }

    public function database(): Response
    {
        return $this->admin('security/database', [
            'title' => 'Безопасность: база данных',
            'section' => 'security',
            'tab' => 'database',
            'available' => $this->backup->isAvailable(),
            'files' => $this->backup->listFiles(),
        ]);
    }

    /** Скачать gzip-дамп БД. Требует повторного ввода пароля администратора. */
    public function exportDatabase(Request $request): Response
    {
        $admin = $this->requireUser();
        if (!$admin->hasPassword() || !$this->hasher->verify($request->string('password'), (string) $admin->passwordHash)) {
            throw new ValidationException(['password' => 'Пароль указан неверно'], '/admin/security/database');
        }
        if (!$this->backup->isAvailable()) {
            throw new \DomainException('pg_dump недоступен в этом окружении');
        }
        $this->audit->record('database.export', $admin->id);
        $name = 'db-' . date('Ymd-His') . '.sql.gz';

        return new StreamedResponse(function (): void {
            $this->backup->stream();
        }, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function createBackup(Request $request): Response
    {
        $file = $this->backup->createFile(14);
        if ($file === null) {
            throw new \DomainException('Не удалось создать бэкап. Подробности — в логах.');
        }
        $this->audit->record('database.backup', $this->requireUser()->id, null, null, ['file' => basename($file)]);

        return $this->done($request, 'Бэкап создан: ' . basename($file), '/admin/security/database');
    }

    public function downloadBackup(Request $request, string $name): Response
    {
        $admin = $this->requireUser();
        if (!preg_match('/^db-\d{8}-\d{6}\.sql\.gz$/', $name)) {
            $this->notFound();
        }
        foreach ($this->backup->listFiles() as $file) {
            if ($file['name'] === $name) {
                $path = dirname(__DIR__, 4) . '/storage/backups/' . $name;
                $this->audit->record('database.backup_download', $admin->id, null, null, ['file' => $name]);

                return new StreamedResponse(static function () use ($path): void {
                    readfile($path);
                }, 200, [
                    'Content-Type' => 'application/gzip',
                    'Content-Disposition' => 'attachment; filename="' . $name . '"',
                    'Cache-Control' => 'no-store',
                ]);
            }
        }
        $this->notFound();
    }

    public function system(): Response
    {
        $failed = $this->db->fetchAll('SELECT id, job, attempts, last_error, failed_at FROM jobs WHERE failed_at IS NOT NULL ORDER BY failed_at DESC LIMIT 20');

        return $this->admin('security/system', [
            'title' => 'Безопасность: очередь и планировщик',
            'section' => 'security',
            'tab' => 'system',
            'pending' => $this->queue->pendingCount(),
            'failedCount' => $this->queue->failedCount(),
            'failed' => $failed,
            'tasks' => $this->scheduler->status(),
            'php' => PHP_VERSION,
            'disk' => ['free' => (int) @disk_free_space('/'), 'total' => (int) @disk_total_space('/')],
        ]);
    }

    public function retryFailed(Request $request): Response
    {
        $count = $this->queue->retryFailed();
        $this->audit->record('queue.retry_failed', $this->requireUser()->id, null, null, ['count' => $count]);

        return $this->done($request, "Возвращено в очередь: {$count}", '/admin/security/system');
    }
}
