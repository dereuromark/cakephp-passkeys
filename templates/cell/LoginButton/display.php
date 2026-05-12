<?php
declare(strict_types=1);

/**
 * @var \Cake\View\View $this
 * @var bool $hidden
 * @var array<string, string> $endpoints
 * @var string $class
 * @var string $label
 */
if ($hidden) {
    return;
}
?>
<button type="button"
        class="<?= h($class) ?>"
        data-passkey-authenticate
        data-passkey-endpoints='<?= h(json_encode($endpoints, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) ?>'
        hidden>
    <?= h($label) ?>
</button>
