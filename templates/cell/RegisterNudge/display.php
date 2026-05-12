<?php
declare(strict_types=1);

use function Cake\I18n\__d;

/**
 * @var \Cake\View\View $this
 * @var bool $hidden
 */
if ($hidden) {
    return;
}
?>
<div class="passkeys-nudge alert alert-info" data-passkey-nudge>
    <strong><?= __d('passkeys', 'Sign in faster next time') ?></strong>
    <p><?= __d('passkeys', 'Add a passkey to skip the password and 2FA prompt on this device.') ?></p>
    <button type="button" class="btn btn-primary btn-sm"
            data-passkey-register data-passkey-name-prompt>
        <?= __d('passkeys', 'Add a passkey') ?>
    </button>
    <button type="button" class="btn btn-link btn-sm" data-passkey-nudge-dismiss>
        <?= __d('passkeys', 'Not now') ?>
    </button>
</div>
