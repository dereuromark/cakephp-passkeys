<?php
declare(strict_types=1);

use function Cake\I18n\__d;

/**
 * @var \Cake\View\View $this
 * @var string $appName
 * @var string $userName
 * @var string $passkeyName
 * @var string|null $deviceLabel
 * @var \Cake\I18n\DateTime $addedAt
 * @var string|null $manageUrl
 */
?>
<p><?= h(__d('passkeys', 'Hi {0},', $userName)) ?></p>
<p><?= h(__d('passkeys', 'A passkey was added to your {0} account.', $appName)) ?></p>
<ul>
    <li><?= h(__d('passkeys', 'Name: {0}', $passkeyName)) ?></li>
    <?php if ($deviceLabel) { ?>
    <li><?= h(__d('passkeys', 'Device: {0}', $deviceLabel)) ?></li>
    <?php } ?>
    <li><?= h(__d('passkeys', 'Added: {0}', $addedAt->nice() . ' (' . $addedAt->getTimezone()->getName() . ')')) ?></li>
</ul>
<p><?= h(__d('passkeys', 'If this was you, there is nothing to do.')) ?></p>
<p><strong><?= h(__d('passkeys', 'If it was not you, someone else may be signed in to your account. Sign in, remove this passkey and secure your account.')) ?></strong></p>
<?php if ($manageUrl) { ?>
<p><?= $this->Html->link(__d('passkeys', 'Manage your passkeys'), $manageUrl) ?></p>
<?php } ?>
