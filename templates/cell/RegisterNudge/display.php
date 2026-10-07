<?php
declare(strict_types=1);

use function Cake\I18n\__d;

/**
 * @var \Cake\View\View $this
 * @var bool $hidden
 * @var int $redisplayAfterDays
 */
if ($hidden) {
    return;
}
?>
<div class="passkeys-nudge alert alert-info"
     data-passkey-nudge
     data-passkey-nudge-days="<?= (int)$redisplayAfterDays ?>"
     hidden>
    <strong><?= __d('passkeys', 'Sign in faster next time') ?></strong>
    <p><?= __d('passkeys', 'Add a passkey to sign in on this device without your password.') ?></p>
    <button type="button" class="btn btn-primary btn-sm"
            data-passkey-register data-passkey-name-prompt>
        <?= __d('passkeys', 'Add a passkey') ?>
    </button>
    <button type="button" class="btn btn-link btn-sm" data-passkey-nudge-dismiss>
        <?= __d('passkeys', 'Not now') ?>
    </button>
</div>
