<?php
declare(strict_types=1);

use function Cake\I18n\__d;

/**
 * @var \Cake\View\View $this
 * @var bool $hidden
 * @var array<\Passkeys\Model\Entity\Passkey> $passkeys
 * @var bool $atCap
 * @var int $maxPerUser
 * @var string|null $docsUrl
 * @var string $title
 */
if ($hidden) {
    return;
}
?>
<div class="passkeys-manager">
    <?php if (empty($passkeys)) { ?>
        <p class="passkeys-empty"><?= __d('passkeys', "You haven't added any passkeys yet.") ?></p>
        <p class="passkeys-explainer">
            <?= __d('passkeys', 'Passkeys are a faster, phishing-resistant way to sign in. They live on your device and unlock with your fingerprint, face, or PIN.') ?>
            <?php if ($docsUrl) { ?>
                <a href="<?= h($docsUrl) ?>"><?= __d('passkeys', "What's a passkey?") ?></a>
            <?php } ?>
        </p>
        <button type="button" class="btn btn-primary"
                data-passkey-register data-passkey-name-prompt>
            <?= __d('passkeys', 'Add a passkey') ?>
        </button>
    <?php } else { ?>
        <table class="passkeys-list table">
            <thead>
                <tr>
                    <th></th>
                    <th><?= __d('passkeys', 'Name') ?></th>
                    <th><?= __d('passkeys', 'Type') ?></th>
                    <th><?= __d('passkeys', 'Added') ?></th>
                    <th><?= __d('passkeys', 'Last used') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($passkeys as $p) { ?>
                <tr data-passkey-row data-passkey-id="<?= (int)$p->id ?>">
                    <td><?= h($p->emoji ?? '🔒') ?></td>
                    <td>
                        <span data-passkey-name><?= h($p->name) ?></span>
                        <button type="button" data-passkey-rename aria-label="<?= h(__d('passkeys', 'Rename')) ?>">✎</button>
                    </td>
                    <td><?= h($p->aaguid_label ?? __d('passkeys', 'Passkey')) ?></td>
                    <td><?= $p->created?->nice() ?></td>
                    <td><?= $p->last_used_at ? $p->last_used_at->timeAgoInWords() : __d('passkeys', 'never') ?></td>
                    <td>
                        <?= $this->Form->postLink(
                            __d('passkeys', 'Delete'),
                            ['plugin' => 'Passkeys', 'controller' => 'Passkeys', 'action' => 'delete', $p->id],
                            [
                                'confirm' => __d('passkeys', 'Delete this passkey?'),
                                'block' => true,
                                'data-passkey-delete' => true,
                                'escape' => false,
                            ],
                        ) ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php if ($atCap) { ?>
            <p class="passkeys-cap"><?= __d('passkeys', "You've reached your limit of {0} passkeys.", $maxPerUser) ?></p>
        <?php } else { ?>
            <button type="button" class="btn btn-secondary"
                    data-passkey-register data-passkey-name-prompt>
                <?= __d('passkeys', 'Add another passkey') ?>
            </button>
        <?php } ?>
    <?php } ?>
</div>
