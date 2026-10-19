<?php
/** @var \App\Core\View\View $this */
/** @var int $pending */
/** @var int $failedCount */
/** @var list<array<string,mixed>> $failed */
/** @var list<array{name:string,last_run:?\DateTimeImmutable}> $tasks */
$this->layout('layouts/admin');
?>
<?= $this->partial('admin/security/_tabs', ['tab' => $tab]) ?>
<div class="kpi-grid">
    <div class="kpi"><div class="kpi-label">Задач в очереди</div><div class="kpi-value"><?= (int) $pending ?></div></div>
    <div class="kpi"><div class="kpi-label">Завершились ошибкой</div><div class="kpi-value" style="color:<?= $failedCount > 0 ? 'var(--danger)' : 'inherit' ?>"><?= (int) $failedCount ?></div>
        <?php if ($failedCount > 0): ?><form method="post" action="/admin/security/system/retry"><?= $this->csrfField() ?><button class="btn btn-ghost btn-xs" type="submit" style="margin-top:.5rem">Повторить все</button></form><?php endif; ?></div>
    <div class="kpi"><div class="kpi-label">PHP</div><div class="kpi-value" style="font-size:1.3rem"><?= $this->e($php) ?></div></div>
    <div class="kpi"><div class="kpi-label">Свободно на диске</div><div class="kpi-value" style="font-size:1.3rem"><?= $disk['total'] > 0 ? $this->e(\App\Support\Format::bytes($disk['free'])) : '—' ?></div></div>
</div>
<div class="two-col">
    <section class="card-box"><h2 class="chart-title" style="padding:1rem 1.1rem 0">Планировщик</h2>
        <div class="table-wrap"><table class="table"><thead><tr><th>Задача</th><th>Последний запуск</th></tr></thead><tbody>
        <?php foreach ($tasks as $t): ?><tr><td><code><?= $this->e($t['name']) ?></code></td><td><?= $t['last_run'] ? $this->e($this->ago($t['last_run'])) : '<span class="muted">ещё не запускалась</span>' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="muted small" style="padding:0 1.1rem 1rem">Задачи выполняет контейнер <code>worker</code>. Если все запуски «давно» — проверьте <code>docker compose logs worker</code>.</p>
    </section>
    <section class="card-box"><h2 class="chart-title" style="padding:1rem 1.1rem 0">Последние ошибки очереди</h2>
        <div class="table-wrap"><table class="table"><tbody>
        <?php foreach ($failed as $f): ?><tr><td><code><?= $this->e(substr(strrchr('\\' . $f['job'], '\\'), 1)) ?></code><span class="sub"><?= $this->e($this->short(new \DateTimeImmutable((string) $f['failed_at']))) ?></span></td><td class="muted small"><?= $this->e($this->truncate((string) $f['last_error'], 160)) ?></td></tr><?php endforeach; ?>
        <?php if ($failed === []): ?><tr><td class="muted">Ошибок нет.</td></tr><?php endif; ?>
        </tbody></table></div></section>
</div>
