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
<?= __d('passkeys', 'Hi {0},', $userName) ?>


<?= __d('passkeys', 'A passkey was added to your {0} account.', $appName) ?>


<?= __d('passkeys', 'Name: {0}', $passkeyName) ?>

<?php if ($deviceLabel) { ?>
<?= __d('passkeys', 'Device: {0}', $deviceLabel) ?>

<?php } ?>
<?= __d('passkeys', 'Added: {0}', $addedAt->nice() . ' (' . $addedAt->getTimezone()->getName() . ')') ?>


<?= __d('passkeys', 'If this was you, there is nothing to do.') ?>


<?= __d('passkeys', 'If it was not you, someone else may be signed in to your account. Sign in, remove this passkey and secure your account.') ?>

<?php if ($manageUrl) { ?>

<?= __d('passkeys', 'Manage your passkeys: {0}', $manageUrl) ?>

<?php } ?>
