<?php
/** @var \App\Core\View\View $this */
/** @var \App\Billing\Payment $payment */
/** @var \App\User\User $payer */
/** @var array<string,string> $seller */
/** @var list<array<string,mixed>> $refunds */
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Квитанция об оплате № <?= (int) $payment->id ?></title>
<meta name="robots" content="noindex">
<style>
    body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #14171f; max-width: 720px; margin: 32px auto; padding: 0 20px; line-height: 1.5; }
    h1 { font-size: 24px; margin: 0 0 4px; }
    .muted { color: #667085; font-size: 14px; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    td, th { padding: 10px 8px; border-bottom: 1px solid #e1e5ec; text-align: left; vertical-align: top; }
    td.r, th.r { text-align: right; }
    .total td { font-weight: 700; font-size: 18px; border-bottom: 0; }
    .head { display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap; border-bottom: 2px solid #14171f; padding-bottom: 14px; }
    button { padding: 8px 16px; border-radius: 8px; border: 1px solid #c3c9d4; background: #fff; cursor: pointer; font: inherit; }
    @media print { button { display: none; } body { margin: 0; } }
</style>
</head>
<body>
<div class="head">
    <div><h1>Квитанция об оплате № <?= (int) $payment->id ?></h1><div class="muted">от <?= $this->e($this->dateTime($payment->paidAt ?? $payment->createdAt)) ?></div></div>
    <div style="text-align:right"><strong><?= $this->e($seller['name']) ?></strong><br><?php if ($seller['inn'] !== ''): ?><span class="muted">ИНН <?= $this->e($seller['inn']) ?></span><br><?php endif; ?><span class="muted"><?= $this->e($seller['site']) ?></span></div>
</div>
<p><strong>Плательщик:</strong> <?= $this->e($payer->name) ?>, <?= $this->e($payer->email) ?><br>
<strong>Способ оплаты:</strong> <?= $this->e($payment->paymentMethodTitle ?? 'Банковская карта / ЮKassa') ?><br>
<strong>Платёж в ЮKassa:</strong> <?= $this->e((string) $payment->yookassaId) ?></p>
<table>
    <thead><tr><th>Наименование</th><th class="r">Сумма</th></tr></thead>
    <tbody>
        <tr><td><?= $this->e($payment->description) ?></td><td class="r"><?= $this->e($this->money($payment->originalAmount > 0 ? $payment->originalAmount : $payment->amount, true)) ?></td></tr>
        <?php if ($payment->discountAmount > 0): ?><tr><td>Скидка / зачёт остатка</td><td class="r">−<?= $this->e($this->money($payment->discountAmount, true)) ?></td></tr><?php endif; ?>
        <tr class="total"><td>Оплачено</td><td class="r"><?= $this->e($this->money($payment->amount, true)) ?></td></tr>
        <?php foreach ($refunds as $refund): if (($refund['status'] ?? '') !== 'succeeded') { continue; } ?>
            <tr><td>Возврат от <?= $this->e($this->short(new \DateTimeImmutable((string) $refund['created_at']))) ?></td><td class="r">−<?= $this->e($this->money((int) $refund['amount'], true)) ?></td></tr>
        <?php endforeach; ?>
    </tbody>
</table>
<p class="muted">НДС не облагается или включён в стоимость согласно системе налогообложения продавца. Документ подтверждает факт оплаты и не является фискальным чеком; кассовый чек направляется отдельно на email плательщика.</p>
<button type="button" id="print">Печать / сохранить в PDF</button>
<script nonce="<?= $this->e($nonce) ?>">document.getElementById('print').addEventListener('click', function () { window.print(); });</script>
</body>
</html>
