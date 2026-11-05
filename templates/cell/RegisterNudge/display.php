<?php
declare(strict_types=1);

use CakePasskeys\Service\NudgePolicy;
use function Cake\I18n\__d;

/**
 * @var \Cake\View\View $this
 * @var bool $hidden
 * @var string|null $reason
 * @var int $redisplayAfterDays
 */
if ($hidden) {
    return;
}
?>
<div class="passkeys-nudge alert alert-info"
     data-passkey-nudge
     data-passkey-nudge-reason="<?= h($reason) ?>"
     data-passkey-nudge-days="<?= (int)$redisplayAfterDays ?>"
     hidden>
    <?php if ($reason === NudgePolicy::REASON_DEVICE_BOUND_ONLY) { ?>
        <strong><?= __d('passkeys', 'Keep access if you lose this device') ?></strong>
        <p><?= __d('passkeys', 'Your passkeys only work on the device they were created on. Add a second passkey, for example on your phone or in a password manager, so you can still sign in without it.') ?></p>
        <button type="button" class="btn btn-primary btn-sm"
                data-passkey-register data-passkey-name-prompt>
            <?= __d('passkeys', 'Add another passkey') ?>
        </button>
    <?php } else { ?>
        <strong><?= __d('passkeys', 'Sign in faster next time') ?></strong>
        <p><?= __d('passkeys', 'Add a passkey to sign in on this device without your password.') ?></p>
        <button type="button" class="btn btn-primary btn-sm"
                data-passkey-register data-passkey-name-prompt>
            <?= __d('passkeys', 'Add a passkey') ?>
        </button>
    <?php } ?>
    <button type="button" class="btn btn-link btn-sm" data-passkey-nudge-dismiss>
        <?= __d('passkeys', 'Not now') ?>
    </button>
</div>
