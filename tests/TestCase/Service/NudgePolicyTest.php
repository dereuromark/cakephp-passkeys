<?php

declare(strict_types=1);

namespace Passkeys\Test\TestCase\Service;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use Passkeys\Service\NudgePolicy;

class NudgePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Passkeys.nudge', [
            'enabled' => true,
            'afterLogins' => 3,
            'redisplayAfterDays' => 14,
        ]);
    }

    public function testShownWhenAllConditionsMet(): void
    {
        $this->assertTrue((new NudgePolicy())->shouldShow(0, 5, null));
    }

    public function testHiddenWhenUserHasPasskey(): void
    {
        $this->assertFalse((new NudgePolicy())->shouldShow(1, 5, null));
    }

    public function testHiddenBeforeMinLogins(): void
    {
        $this->assertFalse((new NudgePolicy())->shouldShow(0, 2, null));
    }

    public function testHiddenWhenRecentlyDismissed(): void
    {
        $recent = new DateTimeImmutable('-3 days');
        $this->assertFalse((new NudgePolicy())->shouldShow(0, 5, $recent));
    }

    public function testShownAgainAfterRedisplayWindow(): void
    {
        $old = new DateTimeImmutable('-30 days');
        $this->assertTrue((new NudgePolicy())->shouldShow(0, 5, $old));
    }

    public function testHiddenWhenDisabled(): void
    {
        Configure::write('Passkeys.nudge.enabled', false);
        $this->assertFalse((new NudgePolicy())->shouldShow(0, 5, null));
    }
}
