<?php
/** @var \App\Core\View\View $this */
/** @var list<array<string,mixed>> $items */
/** @var array<string,string> $filters */
$this->layout('layouts/admin');
?>
<?= $this->partial('admin/security/_tabs', ['tab' => $tab]) ?>
<div class="toolbar"><form method="get" action="/admin/security">
    <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="Поиск по действию, email, деталям">
    <input type="text" name="action" value="<?= $this->e($filters['action'] ?? '') ?>" placeholder="Префикс действия, напр. auth." style="min-width:200px">
    <button class="btn btn-ghost btn-sm" type="submit">Найти</button><?php if ($filters): ?><a class="btn btn-ghost btn-sm" href="/admin/security">Сбросить</a><?php endif; ?></form>
    <span class="muted small">Записей: <?= (int) $total ?></span></div>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Время</th><th>Пользователь</th><th>Действие</th><th>Объект</th><th>IP</th><th>Детали</th></tr></thead>
    <tbody>
    <?php foreach ($items as $a): ?>
        <tr><td class="nowrap"><?= $this->e($this->short(new \DateTimeImmutable((string) $a['created_at']))) ?></td>
            <td><?= $a['user_id'] ? '<a href="/admin/users/' . (int) $a['user_id'] . '">' . $this->e((string) ($a['user_name'] ?? $a['user_email'])) . '</a>' : '<span class="muted">система</span>' ?></td>
            <td><code><?= $this->e($a['action']) ?></code></td>
            <td class="small"><?= $this->e(trim((string) $a['entity_type'] . ' ' . (string) $a['entity_id'])) ?></td>
            <td class="small"><?= $this->e((string) $a['ip']) ?></td>
            <td class="muted small"><?= $this->e($this->truncate((string) $a['meta'], 110)) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?><tr><td colspan="6" class="muted">Записей нет.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/security', 'query' => $filters]) ?>
