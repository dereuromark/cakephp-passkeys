<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use CakePasskeys\Command\PasskeysPruneCommand;
use CakePasskeys\Event\PasskeyEvent;
use CakePasskeys\Test\PasskeyCommandFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;

class PasskeysPruneCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use PasskeyCommandFixtureTrait;

    /**
     * @var array<\Cake\Event\EventInterface<\CakePasskeys\Command\PasskeysPruneCommand>>
     */
    protected array $events = [];

    /**
     * @var \Closure
     */
    protected $listener;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchema();
        $this->events = [];
        $this->listener = function (EventInterface $event): void {
            $this->events[] = $event;
        };
        EventManager::instance()->on('CakePasskeys.afterDelete', $this->listener);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        EventManager::instance()->off('CakePasskeys.afterDelete', $this->listener);
        parent::tearDown();
    }

    /**
     * @param int $userId
     * @param string $name
     * @param int $createdDaysAgo
     * @param int|null $usedDaysAgo
     *
     * @return int
     */
    protected function seedAged(int $userId, string $name, int $createdDaysAgo, ?int $usedDaysAgo = null): int
    {
        return $this->seedPasskey($userId, $name, [
            'created' => DateTime::now()->subDays($createdDaysAgo),
            'last_used_at' => $usedDaysAgo === null ? null : DateTime::now()->subDays($usedDaysAgo),
        ]);
    }

    /**
     * @return array<int>
     */
    protected function remainingIds(): array
    {
        return $this->getTableLocator()->get('CakePasskeys.Passkeys')->find()
            ->orderBy(['id' => 'ASC'])
            ->all()
            ->extract('id')
            ->toList();
    }

    /**
     * @return void
     */
    public function testPrunesPasskeysLastUsedBeforeCutoff(): void
    {
        $old = $this->seedAged(1, 'Old phone', 400, 100);
        $recent = $this->seedAged(1, 'Laptop', 400, 5);

        $this->exec('passkeys prune --unused-for 30 --yes');

        $this->assertExitSuccess();
        $this->assertOutputContains('Deleted passkey ' . $old . ' (Old phone) of user 1.');
        $this->assertOutputContains('Passkeys deleted: 1.');
        $this->assertSame([$recent], $this->remainingIds());

        $this->assertCount(1, $this->events);
        $this->assertInstanceOf(PasskeysPruneCommand::class, $this->events[0]->getSubject());
        $payload = $this->events[0]->getData('event');
        $this->assertInstanceOf(PasskeyEvent::class, $payload);
        $this->assertSame($old, $payload->getPasskey()->id);
        $this->assertSame(64, strlen($payload->getUserHandle()));
    }

    /**
     * @return void
     */
    public function testNeverUsedPasskeysCountFromCreation(): void
    {
        $stale = $this->seedAged(1, 'Test key', 100);
        $fresh = $this->seedAged(1, 'New key', 5);
        $used = $this->seedAged(1, 'Laptop', 400, 1);

        $this->exec('passkeys prune --unused-for 30 --yes');

        $this->assertExitSuccess();
        $this->assertSame([$fresh, $used], $this->remainingIds());
        $this->assertCount(1, $this->events);
        $this->assertSame($stale, $this->events[0]->getData('event')->getPasskey()->id);
    }

    /**
     * @return void
     */
    public function testKeepsLastPasskeyOfEachUser(): void
    {
        $this->seedAged(1, 'Ancient', 200);
        $newest = $this->seedAged(1, 'Old laptop', 300, 50);
        $bob = $this->seedAged(2, 'Bob key', 300, 90);

        $this->exec('passkeys prune --unused-for 30 --yes');

        $this->assertExitSuccess();
        $this->assertOutputContains('Passkeys deleted: 1.');
        $this->assertOutputContains('Kept as the last passkey of their user: 2.');
        $this->assertSame([$newest, $bob], $this->remainingIds());
        $this->assertCount(1, $this->events);
    }

    /**
     * @return void
     */
    public function testNothingToPruneWhenOnlyLastPasskeysQualify(): void
    {
        $bob = $this->seedAged(2, 'Bob key', 300, 90);

        $this->exec('passkeys prune --unused-for 30');

        $this->assertExitSuccess();
        $this->assertOutputContains('No passkeys unused for 30 days.');
        $this->assertOutputContains('Kept as the last passkey of their user: 1.');
        $this->assertOutputNotContains('Delete all');
        $this->assertSame([$bob], $this->remainingIds());
        $this->assertCount(0, $this->events);
    }

    /**
     * @return void
     */
    public function testIncludeLastDeletesEveryUnusedPasskey(): void
    {
        $this->seedAged(1, 'Ancient', 200);
        $this->seedAged(1, 'Old laptop', 300, 50);
        $this->seedAged(2, 'Bob key', 300, 90);
        $kept = $this->seedAged(2, 'Bob phone', 300, 2);

        $this->exec('passkeys prune --unused-for 30 --include-last --yes');

        $this->assertExitSuccess();
        $this->assertOutputContains('Passkeys deleted: 3.');
        $this->assertOutputNotContains('Kept as the last passkey');
        $this->assertSame([$kept], $this->remainingIds());
        $this->assertCount(3, $this->events);
    }

    /**
     * @return void
     */
    public function testSkipsPasskeyUsedSinceTheScan(): void
    {
        $first = $this->seedAged(1, 'Old phone', 400, 100);
        $second = $this->seedAged(1, 'Old tablet', 400, 90);
        $this->seedAged(1, 'Laptop', 400, 1);
        $touch = function () use ($second): void {
            $this->getTableLocator()->get('CakePasskeys.Passkeys')
                ->updateAll(['last_used_at' => DateTime::now()], ['id' => $second]);
        };
        EventManager::instance()->on('CakePasskeys.afterDelete', $touch);

        try {
            $this->exec('passkeys prune --unused-for 30 --yes');
        } finally {
            EventManager::instance()->off('CakePasskeys.afterDelete', $touch);
        }

        $this->assertExitSuccess();
        $this->assertOutputContains('Skipped passkey ' . $second . ' (Old tablet) of user 1: used since the scan.');
        $this->assertOutputContains('Passkeys deleted: 1.');
        $this->assertNotContains($first, $this->remainingIds());
        $this->assertContains($second, $this->remainingIds());
        $this->assertCount(1, $this->events);
    }

    /**
     * @return void
     */
    public function testRechecksLastPasskeyBeforeEachDeletion(): void
    {
        $first = $this->seedAged(1, 'Old phone', 400, 100);
        $second = $this->seedAged(1, 'Old tablet', 400, 90);
        $active = $this->seedAged(1, 'Laptop', 400, 1);
        $deleteActive = function () use ($active): void {
            $this->getTableLocator()->get('CakePasskeys.Passkeys')->deleteAll(['id' => $active]);
        };
        EventManager::instance()->on('CakePasskeys.afterDelete', $deleteActive);

        try {
            $this->exec('passkeys prune --unused-for 30 --yes');
        } finally {
            EventManager::instance()->off('CakePasskeys.afterDelete', $deleteActive);
        }

        $this->assertExitSuccess();
        $this->assertOutputContains('Passkeys deleted: 1.');
        $this->assertOutputContains('Kept as the last passkey of their user: 1.');
        $this->assertSame([$second], $this->remainingIds());
        $this->assertCount(1, $this->events);
        $this->assertSame($first, $this->events[0]->getData('event')->getPasskey()->id);
    }

    /**
     * @return void
     */
    public function testDryRunListsWithoutDeleting(): void
    {
        $old = $this->seedAged(1, 'Old phone', 400, 100);
        $never = $this->seedAged(1, 'Test key', 100);
        $recent = $this->seedAged(1, 'Laptop', 400, 5);

        $this->exec('passkeys prune --unused-for 30 --dry-run');

        $this->assertExitSuccess();
        $this->assertOutputContains('Old phone');
        $this->assertOutputContains('Test key');
        $this->assertOutputNotContains('Laptop');
        $this->assertOutputContains('Passkeys that would be deleted: 2.');
        $this->assertOutputNotContains('Delete all');
        $this->assertSame([$old, $never, $recent], $this->remainingIds());
        $this->assertCount(0, $this->events);
    }

    /**
     * @return void
     */
    public function testAsksBeforeDeleting(): void
    {
        $this->seedAged(1, 'Old phone', 400, 100);
        $this->seedAged(1, 'Laptop', 400, 5);

        $this->exec('passkeys prune --unused-for 30', ['n']);

        $this->assertExitSuccess();
        $this->assertOutputContains('Delete all passkeys unused for 30 days (1 in total)?');
        $this->assertOutputContains('Nothing deleted.');
        $this->assertSame(2, $this->countPasskeys(1));
        $this->assertCount(0, $this->events);

        $this->exec('passkeys prune --unused-for 30', ['y']);

        $this->assertExitSuccess();
        $this->assertSame(1, $this->countPasskeys(1));
        $this->assertCount(1, $this->events);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDaysProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'text' => ['abc'],
            'fraction' => ['1.5'],
        ];
    }

    /**
     * @param string $days
     *
     * @return void
     */
    #[DataProvider('invalidDaysProvider')]
    public function testRejectsInvalidDays(string $days): void
    {
        $this->seedAged(1, 'Old phone', 400, 100);

        $this->exec('passkeys prune --yes --unused-for=' . $days);

        $this->assertExitError();
        $this->assertErrorContains('--unused-for must be a positive number of days');
        $this->assertSame(1, $this->countPasskeys(1));
        $this->assertCount(0, $this->events);
    }

    /**
     * @return void
     */
    public function testRequiresUnusedFor(): void
    {
        $this->seedAged(1, 'Old phone', 400, 100);

        $this->exec('passkeys prune --yes');

        $this->assertExitError();
        $this->assertErrorContains('unused-for');
        $this->assertSame(1, $this->countPasskeys(1));
    }
}
