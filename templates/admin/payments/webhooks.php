<?php
/** @var \App\Core\View\View $this */
/** @var list<array<string,mixed>> $events */
$this->layout('layouts/admin');
?>
<?php $this->start('actions'); ?><a class="btn btn-ghost btn-sm" href="/admin/payments">← Платежи</a><?php $this->end(); ?>
<p class="muted">URL для настроек ЮKassa («Интеграция → HTTP-уведомления»): <code><?= $this->e($this->url('/webhooks/yookassa')) ?></code>. События: payment.succeeded, payment.canceled, refund.succeeded.</p>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Получено</th><th>Событие</th><th>Объект</th><th>Статус</th><th>IP</th><th>Ошибка</th></tr></thead>
    <tbody>
    <?php foreach ($events as $e): ?>
        <tr><td class="nowrap"><?= $this->e($this->short(new \DateTimeImmutable((string) $e['received_at']))) ?></td><td><code><?= $this->e($e['event']) ?></code></td><td class="small"><?= $this->e((string) $e['object_id']) ?></td>
            <td><span class="badge badge-<?= ['processed' => 'success', 'failed' => 'danger', 'ignored' => 'muted'][$e['status']] ?? 'warning' ?>"><?= $this->e($e['status']) ?></span></td><td><?= $this->e((string) $e['ip']) ?></td><td class="muted small"><?= $this->e($this->truncate((string) $e['error'], 100)) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($events === []): ?><tr><td colspan="6" class="muted">Уведомлений ещё не было.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
