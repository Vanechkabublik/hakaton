<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Billing\Payment> $payments */
$this->layout('layouts/account');
?>
<h1>История платежей</h1>
<?php if ($payments === []): ?>
    <p class="empty-state">Платежей пока не было.</p>
<?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>№</th><th>Дата</th><th>Описание</th><th class="num">Сумма</th><th>Статус</th><th>Квитанция</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $payment): ?>
            <tr>
                <td>#<?= (int) $payment->id ?></td>
                <td><?= $this->e($this->short($payment->paidAt ?? $payment->createdAt)) ?></td>
                <td><?= $this->e($payment->description) ?><br><span class="muted small"><?= $this->e($payment->kind->label()) ?><?= $payment->discountAmount > 0 ? ' · скидка ' . $this->e($this->money($payment->discountAmount)) : '' ?></span></td>
                <td class="num"><?= $this->e($this->money($payment->amount, true)) ?><?= $payment->refundedAmount > 0 ? '<br><span class="muted small">возврат ' . $this->e($this->money($payment->refundedAmount, true)) . '</span>' : '' ?></td>
                <td><span class="badge badge-<?= $this->e($payment->displayTone()) ?>"><?= $this->e($payment->displayStatus()) ?></span></td>
                <td class="nowrap">
                    <?php if ($payment->isSucceeded()): ?>
                        <a href="/account/payments/<?= (int) $payment->id ?>/receipt" target="_blank">Открыть</a> ·
                        <a href="/account/payments/<?= (int) $payment->id ?>/receipt?download=1">Скачать</a>
                    <?php elseif ($payment->status === \App\Billing\PaymentStatus::Pending): ?>
                        <a href="/payment/return/<?= (int) $payment->id ?>">Статус</a>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">Фискальный кассовый чек (54-ФЗ) ЮKassa отправляет на email, указанный при оплате. Здесь вы можете скачать подтверждение оплаты.</p>
<?php endif; ?>
