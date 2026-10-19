<?php
/** @var \App\Core\View\View $this */
/** @var string $code */
$this->layout('layouts/web');
?>
<div class="container auth-wrap">
    <div class="auth-card">
        <h1>Активировать подарок</h1>
        <form method="post" action="/gift/redeem" novalidate>
            <?= $this->csrfField() ?>
            <div class="field">
                <label for="code">Код подарка</label>
                <input id="code" type="text" name="code" value="<?= $this->e($code) ?>" required autocomplete="off" autocapitalize="characters" placeholder="XXXX-XXXX-XXXX" class="code-input">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Активировать</button>
        </form>
    </div>
</div>
